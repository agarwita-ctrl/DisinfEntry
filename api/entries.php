<?php
/**
 * Entry monitoring feed — DataTables server-side processing with filters.
 *
 *   GET  draw, start, length, order[0][column], order[0][dir], search[value]
 *        + filters: date, date_from, date_to, month, status, disinfection,
 *                   temp (normal|high), name, device
 *   GET  action=detail&id=<id>   — one entry plus the booth cycle behind it
 *   POST action=delete&id=<id>   (administrator only)
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/filters.php';

api_require_login();

$pdo       = db();
$threshold = temp_threshold();

/* ============================================================
 * Delete (admin only)
 * ========================================================== */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    api_require_admin();
    require_csrf();

    $in = json_input();
    if (($in['action'] ?? '') !== 'delete') {
        json_response(['success' => false, 'message' => 'Unknown action.'], 400);
    }

    $id = (int) ($in['id'] ?? 0);
    $stmt = $pdo->prepare('SELECT person_name, entry_date FROM entries WHERE id = ?');
    $stmt->execute([$id]);
    $row = $stmt->fetch();

    if (!$row) {
        json_response(['success' => false, 'message' => 'Entry not found.'], 404);
    }

    $pdo->prepare('DELETE FROM entries WHERE id = ?')->execute([$id]);
    audit(sprintf('Deleted entry #%d (%s, %s)', $id, $row['person_name'], $row['entry_date']), 'Entry Monitoring');

    json_response(['success' => true, 'message' => 'Entry deleted.']);
}

/* ============================================================
 * Detail — the entry, plus what the booth recorded while producing it
 *
 * The cycle is a left join in spirit: entries created by the legacy
 * api/entry.php have none, and neither does an installation that has not run
 * migration 001. Both return `cycle: null` and the modal simply shows less.
 * ========================================================== */
if (($_GET['action'] ?? '') === 'detail') {
    $id = (int) ($_GET['id'] ?? 0);

    $stmt = $pdo->prepare('SELECT * FROM entries WHERE id = ? LIMIT 1');
    $stmt->execute([$id]);
    $entry = $stmt->fetch();

    if (!$entry) {
        json_response(['success' => false, 'message' => 'Entry not found.'], 404);
    }

    $cycle = null;

    try {
        $stmt = $pdo->prepare('SELECT * FROM booth_cycles WHERE entry_id = ? LIMIT 1');
        $stmt->execute([$id]);

        if ($c = $stmt->fetch()) {
            $cycleId = (int) $c['id'];

            $states = $pdo->prepare(
                'SELECT from_state, to_state, uptime_ms FROM booth_cycle_states
                  WHERE cycle_id = ? ORDER BY seq'
            );
            $states->execute([$cycleId]);

            $bursts = $pdo->prepare(
                'SELECT burst_no, duration_ms FROM booth_pump_bursts WHERE cycle_id = ? ORDER BY burst_no'
            );
            $bursts->execute([$cycleId]);

            $door = $pdo->prepare(
                'SELECT action, angle, uptime_ms FROM booth_door_actions WHERE cycle_id = ? ORDER BY uptime_ms'
            );
            $door->execute([$cycleId]);

            // Uptime is meaningless on its own, so every step is expressed as
            // milliseconds since the person was first detected.
            $detected = (int) $c['detected_uptime_ms'];

            $cycle = [
                'cycle_ref'   => $c['cycle_ref'],
                'outcome'     => $c['outcome'],
                'duration_ms' => $c['duration_ms'] !== null ? (int) $c['duration_ms'] : null,
                'trigger_distance_cm' => $c['trigger_distance_cm'] !== null ? (float) $c['trigger_distance_cm'] : null,
                'screening_temp_c'    => $c['screening_temp_c'] !== null ? (float) $c['screening_temp_c'] : null,
                'ambient_temp_c'      => $c['ambient_temp_c'] !== null ? (float) $c['ambient_temp_c'] : null,
                'threshold_c'         => $c['threshold_c'] !== null ? (float) $c['threshold_c'] : null,
                'pump_bursts'   => (int) $c['pump_bursts'],
                'pump_total_ms' => (int) $c['pump_total_ms'],
                'door_opened'   => (bool) $c['door_opened'],
                'detected_at'   => $c['detected_at'],
                'states' => array_map(static fn(array $s): array => [
                    'from'   => $s['from_state'],
                    'to'     => $s['to_state'],
                    'offset' => max(0, (int) $s['uptime_ms'] - $detected),
                ], $states->fetchAll()),
                'pump' => array_map(static fn(array $b): array => [
                    'burst_no'    => (int) $b['burst_no'],
                    'duration_ms' => (int) $b['duration_ms'],
                ], $bursts->fetchAll()),
                'door' => array_map(static fn(array $d): array => [
                    'action' => $d['action'],
                    'angle'  => $d['angle'] !== null ? (int) $d['angle'] : null,
                    'offset' => max(0, (int) $d['uptime_ms'] - $detected),
                ], $door->fetchAll()),
            ];
        }
    } catch (Throwable $e) {
        $cycle = null;   // migration 001 not applied
    }

    json_response([
        'success' => true,
        'entry'   => [
            'id'           => (int) $entry['id'],
            'entry_code'   => 'ENT-' . str_pad((string) $entry['id'], 6, '0', STR_PAD_LEFT),
            'name'         => $entry['person_name'],
            'employee_id'  => $entry['employee_id'],
            'temperature'  => (float) $entry['temperature'],
            'high_temp'    => (float) $entry['temperature'] > $threshold,
            'date'         => fmt_date($entry['entry_date']),
            'time'         => fmt_time($entry['entry_time']),
            'disinfection' => $entry['disinfection_status'],
            'misting'      => $entry['misting_status'],
            'status'       => $entry['access_status'],
            'remarks'      => $entry['remarks'],
            'device_id'    => $entry['device_id'],
            'distance_cm'  => $entry['distance_cm'] !== null ? (float) $entry['distance_cm'] : null,
        ],
        'cycle'     => $cycle,
        'threshold' => $threshold,
    ]);
}

/* ============================================================
 * Listing
 * ========================================================== */
[$where, $params] = entry_filters($_GET, $threshold);

$totalAll = (int) $pdo->query('SELECT COUNT(*) FROM entries')->fetchColumn();

$countStmt = $pdo->prepare("SELECT COUNT(*) FROM entries $where");
$countStmt->execute($params);
$totalFiltered = (int) $countStmt->fetchColumn();

/* ---- Sorting (whitelisted) ---- */
$columns = ['id', 'temperature', 'entry_date', 'entry_time',
            'disinfection_status', 'access_status', 'remarks'];
$orderCol = (int) ($_GET['order'][0]['column'] ?? 0);
$orderDir = strtolower((string) ($_GET['order'][0]['dir'] ?? 'desc')) === 'asc' ? 'ASC' : 'DESC';
$orderBy  = $columns[$orderCol] ?? 'id';

// Sorting by date should keep the time ordering coherent.
$orderSql = $orderBy === 'entry_date'
    ? "entry_date $orderDir, entry_time $orderDir"
    : "$orderBy $orderDir";

/* ---- Paging ---- */
$start  = max(0, (int) ($_GET['start'] ?? 0));
$length = (int) ($_GET['length'] ?? 25);
$length = ($length === -1) ? 10000 : max(1, min(500, $length));

$sql = "SELECT id, person_name, employee_id, temperature, entry_date, entry_time,
               disinfection_status, misting_status, access_status, remarks, device_id, created_at
          FROM entries
          $where
         ORDER BY $orderSql
         LIMIT $length OFFSET $start";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);

$data = array_map(static function (array $r) use ($threshold): array {
    return [
        'id'           => (int) $r['id'],
        'entry_code'   => 'ENT-' . str_pad((string) $r['id'], 6, '0', STR_PAD_LEFT),
        'name'         => $r['person_name'],
        'employee_id'  => $r['employee_id'],
        'temperature'  => (float) $r['temperature'],
        'high_temp'    => (float) $r['temperature'] > $threshold,
        'date'         => fmt_date($r['entry_date']),
        'raw_date'     => $r['entry_date'],
        'time'         => fmt_time($r['entry_time']),
        'raw_time'     => $r['entry_time'],
        'disinfection' => $r['disinfection_status'],
        'misting'      => $r['misting_status'],
        'status'       => $r['access_status'],
        'remarks'      => $r['remarks'],
        'device_id'    => $r['device_id'],
    ];
}, $stmt->fetchAll());

/* ---- Filtered summary strip ---- */
$sumStmt = $pdo->prepare(
    "SELECT COUNT(*) AS total,
            SUM(access_status = 'granted') AS granted,
            SUM(access_status = 'denied')  AS denied,
            AVG(temperature)               AS avg_temp
       FROM entries $where"
);
$sumStmt->execute($params);
$summary = $sumStmt->fetch() ?: [];

$highStmt = $pdo->prepare("SELECT COUNT(*) FROM entries $where AND temperature > ?");
$highParams = $params;
$highParams[] = $threshold;
$highStmt->execute($highParams);

json_response([
    'draw'            => (int) ($_GET['draw'] ?? 1),
    'recordsTotal'    => $totalAll,
    'recordsFiltered' => $totalFiltered,
    'data'            => $data,
    'threshold'       => $threshold,
    'can_delete'      => is_admin(),
    'summary'         => [
        'total'     => (int) ($summary['total'] ?? 0),
        'granted'   => (int) ($summary['granted'] ?? 0),
        'denied'    => (int) ($summary['denied'] ?? 0),
        'high_temp' => (int) $highStmt->fetchColumn(),
        'avg_temp'  => $summary['avg_temp'] !== null ? round((float) $summary['avg_temp'], 1) : null,
    ],
]);
