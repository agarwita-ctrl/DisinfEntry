<?php
/**
 * Sync endpoint for the ESP32 disinfection booth.
 *
 *   POST /api/booth_sync.php
 *   Header: X-API-Key: <settings.api_key>
 *
 * Speaks exactly the payload esp32/DisinfEntry_Booth/DisinfEntry_Booth.ino
 * builds in buildPayload(), and answers with the six directives its
 * adoptDirectives() knows how to adopt:
 *
 *   {
 *     "device_id":"ESP32-BOOTH-01", "device_name":"Main Gate Booth",
 *     "boot_id":"<uuid>", "firmware":"1.1.0",
 *     "uptime_ms":123456, "state":"IDLE",
 *     "boot":        {"mlx_ok":true,"message":"..."},   // until the first sync lands
 *     "config":      { ...the sketch's compile-time constants... },
 *     "cycles":      [ ...whole detection-to-reset passes... ],
 *     "distance":    [ {"distance_cm":41.2,"in_range":true,"uptime_ms":...} ],
 *     "temperature": [ {"object_temp_c":36.6,"ambient_temp_c":29.1,"is_screening":false,...} ]
 *   }
 *
 * Everything the booth sends is keyed, so the batch it resends after a failed
 * POST updates rows instead of duplicating them:
 *   - cycles  by cycle_ref ("<boot_id>:<detected_uptime_ms>")
 *   - samples by (boot_id, uptime_ms)
 *
 * The booth times everything in uptime_ms, which means nothing to a reader.
 * Each one is converted to wall clock against the uptime_ms of the sync that
 * carried it, so a cycle that happened 12 seconds before the POST is stored 12
 * seconds before the POST landed.
 *
 * A cycle that screened someone is published to `entries`, which is what the
 * dashboard, live monitor and reports read. A cycle that never got a
 * temperature - aborted, or denied because the MLX90614 failed - is kept in
 * booth_cycles and raises a notification, but is never invented as an entry.
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/config.php';

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: Content-Type, X-API-Key');
header('Access-Control-Allow-Methods: POST, OPTIONS');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(['success' => false, 'message' => 'Method not allowed. Use POST.'], 405);
}

/* ---- Device authentication ---- */
// Header only: a key in the query string ends up in access logs and browser history.
$providedKey = $_SERVER['HTTP_X_API_KEY'] ?? '';
$expectedKey = setting('api_key', '');

if ($expectedKey === '' || !hash_equals($expectedKey, (string) $providedKey)) {
    json_response(['success' => false, 'message' => 'Invalid API key.'], 401);
}

$in = json_input();

/* ============================================================
 * Envelope
 * ========================================================== */

$deviceId = trim((string) ($in['device_id'] ?? ''));
$bootId   = trim((string) ($in['boot_id'] ?? ''));

if ($deviceId === '' || mb_strlen($deviceId) > 60) {
    json_response(['success' => false, 'message' => 'Field "device_id" is required.'], 422);
}
if ($bootId === '' || mb_strlen($bootId) > 36) {
    json_response(['success' => false, 'message' => 'Field "boot_id" is required.'], 422);
}
if (!isset($in['uptime_ms']) || !is_numeric($in['uptime_ms'])) {
    json_response(['success' => false, 'message' => 'Field "uptime_ms" is required and must be numeric.'], 422);
}

$deviceName  = mb_substr(trim((string) ($in['device_name'] ?? 'Disinfection Booth')), 0, 120);
$firmware    = isset($in['firmware']) ? substr((string) $in['firmware'], 0, 30) : null;
$boothState  = substr((string) ($in['state'] ?? 'IDLE'), 0, 24);
$syncUptime  = max(0, (int) $in['uptime_ms']);
$receivedAt  = microtime(true);

/**
 * Turns one of the booth's uptime stamps into server wall clock.
 *
 * millis() is only meaningful relative to the sync that carried it, so the
 * offset is measured back from the moment this request arrived. A stamp from
 * after the POST was assembled, or from further back than the queues can
 * actually hold, is clamped to now rather than trusted.
 */
$wallClock = static function (?int $uptimeMs) use ($syncUptime, $receivedAt): string {
    if ($uptimeMs === null) {
        return date('Y-m-d H:i:s', (int) round($receivedAt));
    }
    $ageMs = $syncUptime - $uptimeMs;
    if ($ageMs < 0 || $ageMs > 7 * 24 * 3600 * 1000) {
        $ageMs = 0;
    }
    return date('Y-m-d H:i:s', (int) round($receivedAt - ($ageMs / 1000.0)));
};

/** Reads a value that the firmware sends as a number or as JSON null. */
$num = static function (array $src, string $key): ?float {
    return isset($src[$key]) && is_numeric($src[$key]) ? (float) $src[$key] : null;
};

$int = static function (array $src, string $key): ?int {
    return isset($src[$key]) && is_numeric($src[$key]) ? (int) $src[$key] : null;
};

$pdo = db();

/**
 * Everything from here to the commit is one transaction, and an exception
 * anywhere inside it must not reach the booth as a stack trace: XAMPP ships
 * with display_errors on, so an uncaught PDOException would answer the sync
 * with the failing SQL. The booth keys every row it sends, so rolling back and
 * returning a plain 500 simply means the next sync resends the same batch.
 */
set_exception_handler(static function (Throwable $e) use (&$pdo): void {
    if ($pdo instanceof PDO && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('booth_sync failed: ' . $e->getMessage());
    json_response([
        'success' => false,
        'message' => 'Server error while storing the sync. The batch was not recorded; resend it.',
    ], 500);
});

$pdo->beginTransaction();

/* ============================================================
 * Device heartbeat
 * ========================================================== */

$bootInfo = is_array($in['boot'] ?? null) ? $in['boot'] : null;
$mlxOk    = $bootInfo !== null ? (!empty($bootInfo['mlx_ok']) ? 1 : 0) : null;

// The booth keeps sending its boot section until a sync succeeds, so a retried
// batch carries one the server has already seen. Only a boot_id that differs
// from the stored one is genuinely a new boot worth logging.
$stmt = $pdo->prepare('SELECT boot_id FROM devices WHERE device_id = ? LIMIT 1');
$stmt->execute([$deviceId]);
$isNewBoot = ($stmt->fetchColumn() ?: null) !== $bootId;

/**
 * Nothing on the booth measures disinfectant level today - the sketch has a
 * ranger and a thermometer and reports neither. The field is accepted anyway so
 * that adding a tank sensor is a firmware change and not another migration.
 * Absent, the stored value is left alone: NULL stays "not reported".
 */
$level = isset($in['disinfectant_level']) && is_numeric($in['disinfectant_level'])
    ? max(0, min(100, (int) $in['disinfectant_level']))
    : null;

$stmt = $pdo->prepare(
    'INSERT INTO devices
       (device_id, device_name, firmware, boot_id, state, uptime_ms, mlx_ok,
        disinfectant_level, ip_address, last_seen)
     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
     ON DUPLICATE KEY UPDATE
        device_name = VALUES(device_name),
        firmware    = COALESCE(VALUES(firmware), firmware),
        boot_id     = VALUES(boot_id),
        state       = VALUES(state),
        uptime_ms   = VALUES(uptime_ms),
        mlx_ok      = COALESCE(?, mlx_ok),
        disinfectant_level = COALESCE(?, disinfectant_level),
        ip_address  = VALUES(ip_address),
        last_seen   = NOW()'
);
$stmt->execute([
    $deviceId,
    $deviceName !== '' ? $deviceName : 'Disinfection Booth',
    $firmware,
    $bootId,
    $boothState,
    $syncUptime,
    $mlxOk,
    $level,
    client_ip(),
    $mlxOk,
    $level,
]);

/* ---- First sync after a reset ---- */
if ($bootInfo !== null && $isNewBoot) {
    audit(
        sprintf('Booth %s booted (firmware %s, boot %s)', $deviceId, $firmware ?? 'unknown', $bootId),
        'Booth',
        null,
        $deviceId
    );

    if ($mlxOk === 0) {
        notify(
            'system',
            'Temperature Sensor Fault',
            sprintf('Booth %s reported the MLX90614 as missing at boot. Screening cannot run.', $deviceId),
            'danger'
        );
    }
}

/* ============================================================
 * Reported configuration (the sketch's compile-time constants)
 * ========================================================== */

$configColumns = [
    'trig_pin', 'echo_pin', 'relay_pin', 'servo_pin', 'i2c_sda_pin', 'i2c_scl_pin',
    'relay_active_low', 'door_closed_angle', 'door_open_angle', 'servo_min_us', 'servo_max_us',
    'detection_distance_cm', 'ultrasonic_timeout_us', 'fever_threshold_c',
    'presence_confirm_time_ms', 'presence_glitch_grace_ms', 'sample_interval_ms',
    'print_interval_ms', 'pump_on_time_ms', 'pump_off_time_ms', 'door_open_time_ms',
    'temp_print_interval_ms',
];

$configReported = false;

if (is_array($in['config'] ?? null)) {
    $configReported = true;
    $cfg = $in['config'];

    $values = [$deviceId];
    foreach ($configColumns as $col) {
        $values[] = $num($cfg, $col);
    }

    $assignments = implode(', ', array_map(
        static fn(string $c): string => "`$c` = VALUES(`$c`)",
        $configColumns
    ));

    $stmt = $pdo->prepare(
        'INSERT INTO booth_config (device_id, `' . implode('`, `', $configColumns) . '`, reported_at)
         VALUES (?' . str_repeat(', ?', count($configColumns)) . ', NOW())
         ON DUPLICATE KEY UPDATE ' . $assignments . ', reported_at = NOW()'
    );
    $stmt->execute($values);
}

/* ============================================================
 * Cycles
 * ========================================================== */

$threshold      = temp_threshold();
$cyclesStored   = 0;
$entriesCreated = 0;
$lastCycleAt    = null;   // when the newest cycle ran, not when it was reported
$pendingNotices = [];   // raised after the commit, so a rollback cannot leave orphan alerts

$findCycle = $pdo->prepare('SELECT id, entry_id FROM booth_cycles WHERE cycle_ref = ? LIMIT 1');

$insertCycle = $pdo->prepare(
    'INSERT INTO booth_cycles
       (device_id, boot_id, cycle_ref, detected_uptime_ms, duration_ms, trigger_distance_cm,
        screening_temp_c, ambient_temp_c, threshold_c, outcome, pump_bursts, pump_total_ms,
        door_opened, remarks, detected_at)
     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
);

$updateCycle = $pdo->prepare(
    'UPDATE booth_cycles
        SET duration_ms = ?, trigger_distance_cm = ?, screening_temp_c = ?, ambient_temp_c = ?,
            threshold_c = ?, outcome = ?, pump_bursts = ?, pump_total_ms = ?, door_opened = ?,
            remarks = ?, detected_at = ?
      WHERE id = ?'
);

$insertState = $pdo->prepare(
    'INSERT INTO booth_cycle_states (cycle_id, seq, from_state, to_state, uptime_ms)
     VALUES (?, ?, ?, ?, ?)'
);
$insertBurst = $pdo->prepare(
    'INSERT INTO booth_pump_bursts (cycle_id, burst_no, duration_ms) VALUES (?, ?, ?)'
);
$insertDoor = $pdo->prepare(
    'INSERT INTO booth_door_actions (cycle_id, action, angle, uptime_ms) VALUES (?, ?, ?, ?)'
);

$insertEntry = $pdo->prepare(
    'INSERT INTO entries
       (person_name, temperature, entry_date, entry_time, disinfection_status, misting_status,
        access_status, remarks, device_id, distance_cm, motion_detected, created_at)
     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, ?)'
);

$updateEntry = $pdo->prepare(
    'UPDATE entries
        SET temperature = ?, entry_date = ?, entry_time = ?, disinfection_status = ?,
            misting_status = ?, access_status = ?, remarks = ?, distance_cm = ?, created_at = ?
      WHERE id = ?'
);

$linkEntry = $pdo->prepare('UPDATE booth_cycles SET entry_id = ? WHERE id = ?');

$clearStates = $pdo->prepare('DELETE FROM booth_cycle_states WHERE cycle_id = ?');
$clearBursts = $pdo->prepare('DELETE FROM booth_pump_bursts WHERE cycle_id = ?');
$clearDoor   = $pdo->prepare('DELETE FROM booth_door_actions WHERE cycle_id = ?');

$cycles = is_array($in['cycles'] ?? null) ? $in['cycles'] : [];

foreach ($cycles as $c) {
    if (!is_array($c)) {
        continue;
    }

    $cycleRef = trim((string) ($c['cycle_ref'] ?? ''));
    if ($cycleRef === '' || mb_strlen($cycleRef) > 80) {
        continue;
    }

    $detectedUptime = $int($c, 'detected_uptime_ms') ?? 0;
    $outcome        = strtolower((string) ($c['outcome'] ?? 'in_progress'));
    if (!in_array($outcome, ['granted', 'denied', 'aborted', 'in_progress'], true)) {
        $outcome = 'in_progress';
    }

    $screeningTemp = $num($c, 'screening_temp_c');
    $ambientTemp   = $num($c, 'ambient_temp_c');
    $triggerDist   = $num($c, 'trigger_distance_cm');
    $cycleThresh   = $num($c, 'threshold_c');
    $pumpBursts    = max(0, min(255, $int($c, 'pump_bursts') ?? 0));
    $pumpTotalMs   = max(0, $int($c, 'pump_total_ms') ?? 0);
    $doorOpened    = !empty($c['door_opened']) ? 1 : 0;
    $remarks       = mb_substr(trim((string) ($c['remarks'] ?? '')), 0, 255);
    $detectedAt    = $wallClock($detectedUptime);

    // A reading outside what an MLX90614 can plausibly report is treated as no
    // reading at all rather than persisted as a measurement.
    if ($screeningTemp !== null && ($screeningTemp < 20.0 || $screeningTemp > 50.0)) {
        $screeningTemp = null;
    }

    $findCycle->execute([$cycleRef]);
    $existing = $findCycle->fetch() ?: null;

    $cycleRow = [
        $int($c, 'duration_ms'),
        $triggerDist,
        $screeningTemp,
        $ambientTemp,
        $cycleThresh,
        $outcome,
        $pumpBursts,
        $pumpTotalMs,
        $doorOpened,
        $remarks !== '' ? $remarks : null,
        $detectedAt,
    ];

    if ($existing) {
        $cycleId = (int) $existing['id'];
        $entryId = $existing['entry_id'] !== null ? (int) $existing['entry_id'] : null;
        $isNew   = false;

        $updateCycle->execute([...$cycleRow, $cycleId]);

        // The booth only ever resends a cycle unchanged, but rebuilding the
        // children keeps the row honest if a retry ever carries more detail.
        $clearStates->execute([$cycleId]);
        $clearBursts->execute([$cycleId]);
        $clearDoor->execute([$cycleId]);
    } else {
        $insertCycle->execute([
            $deviceId,
            $bootId,
            $cycleRef,
            $detectedUptime,
            ...$cycleRow,
        ]);
        $cycleId = (int) $pdo->lastInsertId();
        $entryId = null;
        $isNew   = true;
        $cyclesStored++;

        if ($lastCycleAt === null || $detectedAt > $lastCycleAt) {
            $lastCycleAt = $detectedAt;
        }
    }

    /* ---- State machine ---- */
    $seq = 0;
    foreach ((is_array($c['states'] ?? null) ? $c['states'] : []) as $s) {
        if (!is_array($s) || $seq >= 255) {
            continue;
        }
        $insertState->execute([
            $cycleId,
            $seq++,
            substr((string) ($s['from_state'] ?? ''), 0, 24),
            substr((string) ($s['to_state'] ?? ''), 0, 24),
            max(0, $int($s, 'uptime_ms') ?? 0),
        ]);
    }

    /* ---- Spray bursts ---- */
    $seenBursts = [];
    foreach ((is_array($c['pump'] ?? null) ? $c['pump'] : []) as $b) {
        $no = is_array($b) ? $int($b, 'burst_no') : null;
        if ($no === null || $no < 1 || isset($seenBursts[$no])) {
            continue;
        }
        $seenBursts[$no] = true;
        $insertBurst->execute([$cycleId, $no, max(0, $int($b, 'duration_ms') ?? 0)]);
    }

    /* ---- Door movement ---- */
    $seenActions = [];
    foreach ((is_array($c['door'] ?? null) ? $c['door'] : []) as $d) {
        $action = is_array($d) ? strtolower((string) ($d['action'] ?? '')) : '';
        if (!in_array($action, ['open', 'close'], true) || isset($seenActions[$action])) {
            continue;
        }
        $seenActions[$action] = true;
        $insertDoor->execute([$cycleId, $action, $int($d, 'angle'), max(0, $int($d, 'uptime_ms') ?? 0)]);
    }

    /* ------------------------------------------------------------
     * Publish to the entry log
     *
     * Only a cycle that actually screened someone becomes an entry: an
     * aborted pass and a sensor-fault denial have no temperature, and
     * entries.temperature is the column every report averages.
     * ---------------------------------------------------------- */
    if (!in_array($outcome, ['granted', 'denied'], true) || $screeningTemp === null) {
        if ($isNew && $outcome === 'denied' && $screeningTemp === null) {
            $pendingNotices[] = [
                'system',
                'Screening Failed',
                sprintf('Booth %s denied entry because the temperature could not be read. Check the MLX90614.', $deviceId),
                'danger',
                null,
            ];
        }
        continue;
    }

    // What the booth physically did. The pump is blocked on a denial, so the
    // burst count is the honest record of how much disinfection happened.
    $disinfection = match (true) {
        $pumpBursts >= 2 => 'completed',
        $pumpBursts === 1 => 'incomplete',
        default => 'skipped',
    };

    $entryDate = date('Y-m-d', strtotime($detectedAt));
    $entryTime = date('H:i:s', strtotime($detectedAt));
    $temp      = round($screeningTemp, 1);
    $access    = $outcome === 'granted' ? 'granted' : 'denied';

    $misting    = $pumpBursts > 0 ? 'on' : 'off';
    $entryNote  = $remarks !== ''
        ? $remarks
        : ($access === 'granted' ? 'Cleared for entry' : 'Access denied');

    if ($entryId !== null) {
        $updateEntry->execute([
            $temp, $entryDate, $entryTime, $disinfection, $misting,
            $access, $entryNote, $triggerDist, $detectedAt, $entryId,
        ]);
        continue;
    }

    // The booth cannot identify anyone: it has a distance sensor and a
    // thermometer, no reader. Naming a person here would be a guess.
    $insertEntry->execute([
        'Unidentified', $temp, $entryDate, $entryTime, $disinfection, $misting,
        $access, $entryNote, $deviceId, $triggerDist, $detectedAt,
    ]);
    $entryId = (int) $pdo->lastInsertId();
    $linkEntry->execute([$entryId, $cycleId]);
    $entriesCreated++;

    /* ---- Alerts ---- */
    if ($temp > $threshold) {
        $pendingNotices[] = [
            'high_temperature',
            'High Temperature Detected',
            sprintf('Booth %s recorded %.1f°C (threshold %.1f°C). %s.', $deviceId, $temp, $threshold,
                $access === 'denied' ? 'Access denied' : 'Access was granted by the booth'),
            'danger',
            $entryId,
        ];
    } elseif ($access === 'denied') {
        $pendingNotices[] = [
            'denied_entry',
            'Entry Denied',
            sprintf('Booth %s denied entry at %.1f°C. %s', $deviceId, $temp, $remarks ?: 'No reason given.'),
            'danger',
            $entryId,
        ];
    }
}

// GREATEST(...) because a batch can arrive out of order after a long outage,
// and a late-delivered old cycle must not rewind the newest one on record.
if ($lastCycleAt !== null) {
    $pdo->prepare(
        'UPDATE devices SET last_cycle_at = GREATEST(COALESCE(last_cycle_at, ?), ?) WHERE device_id = ?'
    )->execute([$lastCycleAt, $lastCycleAt, $deviceId]);
}

/* ============================================================
 * Raw sensor streams
 *
 * INSERT IGNORE against the (boot_id, uptime_ms) key: a resent batch is a
 * no-op rather than a duplicate. Written in one statement per stream because
 * the booth pushes up to 40 distance and 20 temperature rows at a time.
 * ========================================================== */

$distanceStored = 0;
$tempStored     = 0;

$distance = is_array($in['distance'] ?? null) ? array_slice($in['distance'], 0, 500) : [];
if ($distance) {
    $rows = [];
    $args = [];
    foreach ($distance as $s) {
        if (!is_array($s)) {
            continue;
        }
        $uptime = $int($s, 'uptime_ms');
        if ($uptime === null) {
            continue;
        }
        $cm = $num($s, 'distance_cm');
        $rows[] = '(?, ?, ?, ?, ?, ?)';
        array_push($args, $deviceId, $bootId, $cm, !empty($s['in_range']) ? 1 : 0, max(0, $uptime), $wallClock($uptime));
    }
    if ($rows) {
        $stmt = $pdo->prepare(
            'INSERT IGNORE INTO booth_distance_samples
               (device_id, boot_id, distance_cm, in_range, uptime_ms, recorded_at)
             VALUES ' . implode(', ', $rows)
        );
        $stmt->execute($args);
        // Rows actually written, not rows offered: a replayed batch is ignored
        // by the (boot_id, uptime_ms) key and must not be reported as stored.
        $distanceStored = $stmt->rowCount();
    }
}

$temps = is_array($in['temperature'] ?? null) ? array_slice($in['temperature'], 0, 500) : [];
if ($temps) {
    $rows = [];
    $args = [];
    foreach ($temps as $s) {
        if (!is_array($s)) {
            continue;
        }
        $uptime = $int($s, 'uptime_ms');
        if ($uptime === null) {
            continue;
        }
        $rows[] = '(?, ?, ?, ?, ?, ?, ?)';
        array_push(
            $args,
            $deviceId,
            $bootId,
            $num($s, 'object_temp_c'),
            $num($s, 'ambient_temp_c'),
            !empty($s['is_screening']) ? 1 : 0,
            max(0, $uptime),
            $wallClock($uptime)
        );
    }
    if ($rows) {
        $stmt = $pdo->prepare(
            'INSERT IGNORE INTO booth_temperature_samples
               (device_id, boot_id, object_temp_c, ambient_temp_c, is_screening, uptime_ms, recorded_at)
             VALUES ' . implode(', ', $rows)
        );
        $stmt->execute($args);
        $tempStored = $stmt->rowCount();
    }
}

$pdo->commit();

/* ============================================================
 * Housekeeping and alerts (outside the transaction)
 * ========================================================== */

foreach ($pendingNotices as $n) {
    // The sensor-fault alert repeats on every failed screening, so it is
    // rate-limited the same way the low-disinfectant one is.
    if ($n[0] === 'system') {
        $recent = $pdo->prepare(
            'SELECT COUNT(*) FROM notifications
              WHERE type = "system" AND title = ? AND created_at > DATE_SUB(NOW(), INTERVAL 1 HOUR)'
        );
        $recent->execute([$n[1]]);
        if ((int) $recent->fetchColumn() > 0) {
            continue;
        }
    }
    notify($n[0], $n[1], $n[2], $n[3], $n[4]);
}

/* ---- Low disinfectant, if this booth reports a level at all ---- */
if ($level !== null && $level <= (int) setting('low_disinfectant_at', '20')) {
    // Once per hour, so a booth syncing every 30 s does not bury the operator.
    $recent = $pdo->prepare(
        'SELECT COUNT(*) FROM notifications
          WHERE type = "low_disinfectant" AND created_at > DATE_SUB(NOW(), INTERVAL 1 HOUR)'
    );
    $recent->execute();
    if ((int) $recent->fetchColumn() === 0) {
        notify(
            'low_disinfectant',
            'Low Disinfectant Level',
            sprintf('Booth %s is down to %d%% disinfectant. Refill required.', $deviceId, $level),
            'warning'
        );
    }
}

/**
 * The raw streams are a debugging aid, not a record. Trimming them here keeps
 * the booth itself paying for its own retention, with no cron to install.
 */
// Interpolated, not bound: MySQL will not take a placeholder inside INTERVAL,
// and the value is an int clamped to a fixed range one line above.
$retentionHours = max(1, min(720, (int) setting('telemetry_retention_hours', '24')));
$pdo->exec("DELETE FROM booth_distance_samples WHERE recorded_at < DATE_SUB(NOW(), INTERVAL $retentionHours HOUR)");
$pdo->exec("DELETE FROM booth_temperature_samples WHERE recorded_at < DATE_SUB(NOW(), INTERVAL $retentionHours HOUR)");

/* ============================================================
 * Directives
 *
 * adoptDirectives() scans the body for these keys by name, so each one
 * appears exactly once and nothing else in the response reuses a key it
 * looks for. Values are already inside the ranges the sketch accepts -
 * anything outside them it ignores and logs, which would leave the booth
 * running settings the dashboard claims it changed.
 * ========================================================== */

json_response([
    'success'     => true,
    'server_time' => date('Y-m-d H:i:s'),
    'stored'      => [
        'cycles'      => $cyclesStored,
        'entries'     => $entriesCreated,
        'distance'    => $distanceStored,
        'temperature' => $tempStored,
        'config'      => $configReported,
    ],
] + booth_directives(), 200);
