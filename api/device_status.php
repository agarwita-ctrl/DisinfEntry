<?php
/**
 * Device heartbeat + status.
 *
 *   POST ?action=heartbeat   (ESP32, X-API-Key)  — keeps the booth marked online
 *   GET  ?action=status      (dashboard session) — returns booth online state
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';

$action = (string) ($_GET['action'] ?? 'status');

/* ------------------------------------------------------------
 * Booth heartbeat (device authenticated)
 * ---------------------------------------------------------- */
if ($action === 'heartbeat') {
    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Headers: Content-Type, X-API-Key');

    // Header only: a key in the query string ends up in access logs and browser history.
    $providedKey = $_SERVER['HTTP_X_API_KEY'] ?? '';
    $expectedKey = setting('api_key', '');

    if ($expectedKey === '' || !hash_equals($expectedKey, (string) $providedKey)) {
        json_response(['success' => false, 'message' => 'Invalid API key.'], 401);
    }

    $in       = json_input();
    $deviceId = mb_substr(trim((string) ($in['device_id'] ?? 'ESP32-BOOTH-01')), 0, 60);
    $level    = isset($in['disinfectant_level']) && is_numeric($in['disinfectant_level'])
        ? max(0, min(100, (int) $in['disinfectant_level']))
        : null;

    $stmt = db()->prepare(
        // No COALESCE to a default on insert: a booth that reports no level
        // must register as "not reported", not as a full tank.
        'INSERT INTO devices (device_id, device_name, location, firmware, ip_address, disinfectant_level, last_seen)
         VALUES (?, ?, ?, ?, ?, ?, NOW())
         ON DUPLICATE KEY UPDATE
            last_seen = NOW(),
            ip_address = VALUES(ip_address),
            firmware = COALESCE(VALUES(firmware), firmware),
            disinfectant_level = COALESCE(?, disinfectant_level)'
    );
    $stmt->execute([
        $deviceId,
        mb_substr(trim((string) ($in['device_name'] ?? 'Disinfection Booth')), 0, 120),
        isset($in['location']) ? substr((string) $in['location'], 0, 120) : null,
        isset($in['firmware']) ? substr((string) $in['firmware'], 0, 30) : null,
        client_ip(),
        $level,
        $level,
    ]);

    json_response([
        'success'          => true,
        'server_time'      => date('Y-m-d H:i:s'),
        'threshold'        => temp_threshold(),
        'mist_seconds'     => (int) setting('disinfection_duration', '8'),
        'poll_interval'    => (int) setting('refresh_interval', '3'),
    ]);
}

/* ------------------------------------------------------------
 * Status read (dashboard)
 * ---------------------------------------------------------- */
api_require_login();

$devices = device_states();

/**
 * Raise an offline alert at most once per hour, per booth.
 *
 * A booth that has dropped out stays dropped out, and this runs on the
 * dashboard's status poll - every 15 seconds, from every open console. Without
 * the window one outage would bury every other notification in the list.
 *
 * An hour also matches the other repeating alerts: booth_sync.php rate-limits
 * the low-disinfectant and sensor-fault notifications the same way.
 */
foreach ($devices as $d) {
    if ($d['online'] || $d['last_seen'] === null) {
        continue;
    }
    // Matched on "(device_id)" as the message renders it, not on the bare id.
    // A plain LIKE '%id%' would let one booth suppress another's alert whenever
    // one id is a substring of the next - ESP32-BOOTH-1 inside ESP32-BOOTH-10.
    $stmt = db()->prepare(
        'SELECT COUNT(*) FROM notifications
          WHERE type = "device_offline" AND message LIKE ?
            AND created_at > DATE_SUB(NOW(), INTERVAL 1 HOUR)'
    );
    $stmt->execute(['%(' . $d['device_id'] . ')%']);
    if ((int) $stmt->fetchColumn() === 0) {
        notify(
            'device_offline',
            'Booth Offline',
            sprintf('%s (%s) has not reported since %s.', $d['device_name'], $d['device_id'], fmt_datetime($d['last_seen'])),
            'warning'
        );
    }
}

json_response([
    'success' => true,
    'devices' => array_map(static fn(array $d): array => [
        'device_id'          => $d['device_id'],
        'device_name'        => $d['device_name'],
        'location'           => $d['location'],
        'firmware'           => $d['firmware'],
        'ip_address'         => $d['ip_address'],
        'disinfectant_level' => $d['disinfectant_level'],   // null = never reported
        'low_level'          => (bool) $d['low_level'],
        'online'             => (bool) $d['online'],
        'last_seen'          => $d['last_seen'],
    ], $devices),
    'server_time' => date('Y-m-d H:i:s'),
]);
