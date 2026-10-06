<?php
/**
 * Live monitoring feed — polled every few seconds by live.php and the dashboard.
 *
 *   GET ?since=<last_seen_entry_id>
 *
 * Returns the current entrant, any entries newer than `since`, today's counters
 * and the booth state, so the client can refresh without a second round trip.
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
api_require_login();

$pdo       = db();
$since     = max(0, (int) ($_GET['since'] ?? 0));
$threshold = temp_threshold();

/* ---- Latest entry = "current entrant" ---- */
$latest = $pdo->query(
    'SELECT * FROM entries ORDER BY id DESC LIMIT 1'
)->fetch() ?: null;

$shape = static function (?array $r) use ($threshold): ?array {
    if (!$r) {
        return null;
    }
    return [
        'id'           => (int) $r['id'],
        'name'         => $r['person_name'],
        'employee_id'  => $r['employee_id'],
        'temperature'  => (float) $r['temperature'],
        'high_temp'    => (float) $r['temperature'] > $threshold,
        'date'         => fmt_date($r['entry_date']),
        'time'         => fmt_time($r['entry_time']),
        'raw_datetime' => $r['entry_date'] . ' ' . $r['entry_time'],
        'disinfection' => $r['disinfection_status'],
        'misting'      => $r['misting_status'],
        'status'       => $r['access_status'],
        'remarks'      => $r['remarks'],
        'device_id'    => $r['device_id'],
        'distance_cm'  => $r['distance_cm'] !== null ? (float) $r['distance_cm'] : null,
        'created_at'   => $r['created_at'],
        'age_seconds'  => max(0, time() - strtotime((string) $r['created_at'])),
    ];
};

/* ---- Entries newer than the client's cursor ---- */
$fresh = [];
if ($since > 0) {
    $stmt = $pdo->prepare('SELECT * FROM entries WHERE id > ? ORDER BY id ASC LIMIT 25');
    $stmt->execute([$since]);
    $fresh = array_map($shape, $stmt->fetchAll());
}

/* ---- Rolling feed (last 15) ---- */
$feed = array_map($shape, $pdo->query('SELECT * FROM entries ORDER BY id DESC LIMIT 15')->fetchAll());

/* ---- Today's counters ---- */
$stmt = $pdo->prepare(
    'SELECT COUNT(*) AS total,
            SUM(access_status = "granted") AS granted,
            SUM(access_status = "denied")  AS denied,
            SUM(temperature > ?)           AS high_temp,
            AVG(temperature)               AS avg_temp,
            MAX(temperature)               AS max_temp
       FROM entries WHERE entry_date = CURDATE()'
);
$stmt->execute([$threshold]);
$counts = $stmt->fetch() ?: [];

/* ---- Booth state ----
 *
 * Every registered booth is returned, not just the first. The page used to take
 * $devices[0] unconditionally, so a second booth synced, wrote entries and
 * raised alerts while never appearing on the live view - with nothing on screen
 * to say anything was missing.
 *
 * `device` selects one; an unknown or absent id falls back to the first, so the
 * single-booth case behaves exactly as before.
 */
$devices = device_states();

$wanted = trim((string) ($_GET['device'] ?? ''));
$booth  = null;

foreach ($devices as $d) {
    if ($wanted !== '' && $d['device_id'] === $wanted) {
        $booth = $d;
        break;
    }
}
$booth ??= ($devices[0] ?? null);

$boothLive = $booth ? booth_live_state($booth) : null;

/**
 * The booth is considered actively misting when the newest entry says so and it
 * landed within the configured misting window.
 *
 * This stays a heuristic on purpose. The booth cannot report a spray as it
 * happens - it syncs only while idle, precisely so a blocking POST can never
 * stretch a burst - so a cycle reaches the server already finished.
 */
$mistWindow = (int) setting('disinfection_duration', '8');
$mistingNow = $latest
    && $latest['misting_status'] === 'on'
    && (time() - strtotime((string) $latest['created_at'])) <= $mistWindow;

json_response([
    'success'   => true,
    'threshold' => $threshold,
    'current'   => $shape($latest),
    'new'       => $fresh,
    'has_new'   => count($fresh) > 0,
    'feed'      => $feed,
    'last_id'   => $latest ? (int) $latest['id'] : 0,
    'counters'  => [
        'total'     => (int) ($counts['total'] ?? 0),
        'granted'   => (int) ($counts['granted'] ?? 0),
        'denied'    => (int) ($counts['denied'] ?? 0),
        'high_temp' => (int) ($counts['high_temp'] ?? 0),
        'avg_temp'  => $counts['avg_temp'] !== null ? round((float) $counts['avg_temp'], 1) : null,
        'max_temp'  => $counts['max_temp'] !== null ? round((float) $counts['max_temp'], 1) : null,
    ],
    // Every booth on record, so the page can offer a selector and show at a
    // glance that more than one exists.
    'booths' => array_map(static fn(array $d): array => [
        'device_id'   => $d['device_id'],
        'device_name' => $d['device_name'],
        'online'      => (bool) $d['online'],
    ], $devices),
    'booth' => $booth ? [
        'device_id'          => $booth['device_id'],
        'device_name'        => $booth['device_name'],
        'firmware'           => $booth['firmware'],
        'online'             => (bool) $booth['online'],
        'last_seen'          => $booth['last_seen'],
        'disinfectant_level' => $booth['disinfectant_level'],   // null = never reported
        'low_level'          => (bool) $booth['low_level'],
        'misting'            => $mistingNow,
    ] + $boothLive : null,
    'server_time' => date('Y-m-d H:i:s'),
]);
