<?php
/**
 * REST endpoint for the ESP32 disinfection booth.
 *
 *   POST /api/entry.php
 *   Header: X-API-Key: <settings.api_key>
 *   Body (JSON):
 *   {
 *     "device_id":    "ESP32-BOOTH-01",
 *     "employee_id":  "EMP-0003",          // optional
 *     "person_name":  "Juan Dela Cruz",    // optional, defaults to employee lookup
 *     "temperature":  36.7,                // required, °C from MLX90614
 *     "distance_cm":  42.5,                // optional, HC-SR04
 *     "motion":       true,                // optional, PIR
 *     "disinfection": "completed",         // completed | incomplete | skipped
 *     "misting":      "on",                // on | off
 *     "remarks":      "..."                // optional
 *   }
 *
 * The server is authoritative on access control: temperature above the
 * configured threshold is always denied, whatever the device reports.
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

/* ---- Validation ---- */
if (!isset($in['temperature']) || !is_numeric($in['temperature'])) {
    json_response(['success' => false, 'message' => 'Field "temperature" is required and must be numeric.'], 422);
}

$temperature = round((float) $in['temperature'], 1);
if ($temperature < 20.0 || $temperature > 50.0) {
    json_response([
        'success' => false,
        'message' => 'Temperature out of sensor range (20-50 °C). Reading rejected.',
    ], 422);
}

// Cut to the column widths: an over-long value throws under strict SQL mode.
$deviceId     = mb_substr(trim((string) ($in['device_id'] ?? 'ESP32-BOOTH-01')), 0, 60);
$employeeId   = mb_substr(trim((string) ($in['employee_id'] ?? '')), 0, 30);
$personName   = mb_substr(trim((string) ($in['person_name'] ?? '')), 0, 120);
$distance     = isset($in['distance_cm']) && is_numeric($in['distance_cm']) ? round((float) $in['distance_cm'], 1) : null;
$motion       = !empty($in['motion']) ? 1 : 0;
$remarksIn    = trim((string) ($in['remarks'] ?? ''));

$disinfection = strtolower((string) ($in['disinfection'] ?? 'completed'));
if (!in_array($disinfection, ['completed', 'incomplete', 'skipped'], true)) {
    $disinfection = 'completed';
}

$misting = strtolower((string) ($in['misting'] ?? 'off'));
$misting = $misting === 'on' ? 'on' : 'off';

$pdo = db();

/* ---- Resolve the person against the user directory ---- */
$userId = null;
if ($employeeId !== '') {
    $stmt = $pdo->prepare('SELECT id, full_name FROM users WHERE employee_id = ? LIMIT 1');
    $stmt->execute([$employeeId]);
    if ($match = $stmt->fetch()) {
        $userId = (int) $match['id'];
        if ($personName === '') {
            $personName = $match['full_name'];
        }
    }
}
if ($personName === '') {
    $personName = 'Unidentified';
}

/* ---- Access decision (server-side, authoritative) ---- */
$threshold  = temp_threshold();
$isHighTemp = $temperature > $threshold;

$reasons = [];
if ($isHighTemp) {
    $reasons[] = sprintf('High temperature %.1f°C exceeds %.1f°C threshold', $temperature, $threshold);
}
if ($disinfection !== 'completed') {
    $reasons[] = 'Disinfection ' . $disinfection;
}

$accessStatus = ($isHighTemp || $disinfection === 'skipped') ? 'denied' : 'granted';
$remarks = $remarksIn !== ''
    ? $remarksIn
    : ($reasons ? implode('; ', $reasons) : 'Cleared for entry');

/* ---- Persist ---- */
$now = new DateTimeImmutable('now');

$stmt = $pdo->prepare(
    'INSERT INTO entries
       (user_id, employee_id, person_name, temperature, entry_date, entry_time,
        disinfection_status, misting_status, access_status, remarks, device_id,
        distance_cm, motion_detected)
     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
);
$stmt->execute([
    $userId,
    $employeeId ?: null,
    $personName,
    $temperature,
    $now->format('Y-m-d'),
    $now->format('H:i:s'),
    $disinfection,
    $misting,
    $accessStatus,
    mb_substr($remarks, 0, 255),
    $deviceId ?: null,
    $distance,
    $motion,
]);
$entryId = (int) $pdo->lastInsertId();

/* ---- Device heartbeat + optional disinfectant telemetry ---- */
$level = isset($in['disinfectant_level']) && is_numeric($in['disinfectant_level'])
    ? max(0, min(100, (int) $in['disinfectant_level']))
    : null;

$stmt = $pdo->prepare(
    // No COALESCE to a default on insert: a booth that reports no level must
    // register as "not reported", not as a full tank.
    'INSERT INTO devices (device_id, device_name, ip_address, firmware, disinfectant_level, last_seen)
     VALUES (?, ?, ?, ?, ?, NOW())
     ON DUPLICATE KEY UPDATE
        last_seen = NOW(),
        ip_address = VALUES(ip_address),
        firmware = COALESCE(VALUES(firmware), firmware),
        disinfectant_level = COALESCE(?, disinfectant_level)'
);
$stmt->execute([
    $deviceId ?: 'ESP32-BOOTH-01',
    'Disinfection Booth',
    client_ip(),
    isset($in['firmware']) ? substr((string) $in['firmware'], 0, 30) : null,
    $level,
    $level,
]);

/* ---- Notifications ---- */
if ($isHighTemp) {
    notify(
        'high_temperature',
        'High Temperature Detected',
        sprintf('%s recorded %.1f°C (threshold %.1f°C). Access denied.', $personName, $temperature, $threshold),
        'danger',
        $entryId
    );
} elseif ($accessStatus === 'denied') {
    notify(
        'denied_entry',
        'Entry Denied',
        sprintf('%s was denied entry. %s', $personName, $remarks),
        'danger',
        $entryId
    );
}

if ($level !== null && $level <= (int) setting('low_disinfectant_at', '20')) {
    // Only raise this once per hour so the booth does not spam the operator.
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

audit(
    sprintf('Booth entry recorded for %s (%.1f°C, %s)', $personName, $temperature, $accessStatus),
    'Booth',
    null,
    $deviceId ?: 'device'
);

/* ---- Response drives the booth's gate + indicators ---- */
json_response([
    'success'      => true,
    'entry_id'     => $entryId,
    'person_name'  => $personName,
    'temperature'  => $temperature,
    'threshold'    => $threshold,
    'high_temp'    => $isHighTemp,
    'access'       => $accessStatus,
    'allow_entry'  => $accessStatus === 'granted',
    'disinfection' => $disinfection,
    'mist_seconds' => (int) setting('disinfection_duration', '8'),
    'remarks'      => $remarks,
    'timestamp'    => $now->format('Y-m-d H:i:s'),
], 201);
