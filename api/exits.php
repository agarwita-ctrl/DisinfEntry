<?php
/**
 * Exit monitoring feed — DataTables server-side processing with filters.
 *
 *   GET  draw, start, length, order[0][column], order[0][dir]
 *        + filters: date, month, date_from, date_to, device
 *
 * An exit is someone leaving through the booth's exit-side sensor: the door opens for them
 * with no screening and no spray, so a row carries only when, where and how close.
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';

api_require_login();

$pdo = db();

/* ---- Filters (all bound; dates validated before they reach SQL) ---- */
$val   = static fn(string $k): string => trim((string) ($_GET[$k] ?? ''));
$where = 'WHERE 1=1';
$params = [];

if (($date = $val('date')) !== '' && strtotime($date)) {
    $d = date('Y-m-d', strtotime($date));
    $where .= ' AND exited_at >= ? AND exited_at < DATE_ADD(?, INTERVAL 1 DAY)';
    array_push($params, $d, $d);
}
if (($from = $val('date_from')) !== '' && strtotime($from)) {
    $where .= ' AND exited_at >= ?';
    $params[] = date('Y-m-d', strtotime($from));
}
if (($to = $val('date_to')) !== '' && strtotime($to)) {
    $where .= ' AND exited_at < DATE_ADD(?, INTERVAL 1 DAY)';
    $params[] = date('Y-m-d', strtotime($to));
}
if (($month = $val('month')) !== '' && preg_match('/^\d{4}-\d{2}$/', $month)) {
    $where .= ' AND exited_at >= ? AND exited_at < DATE_ADD(?, INTERVAL 1 MONTH)';
    array_push($params, $month . '-01', $month . '-01');
}
if (($device = $val('device')) !== '') {
    $where .= ' AND device_id = ?';
    $params[] = $device;
}

/* ---- Sorting (whitelisted) and paging (clamped) ---- */
$columns  = ['id', 'exited_at', 'exited_at', 'device_id', 'distance_cm'];
$orderCol = (int) ($_GET['order'][0]['column'] ?? 0);
$orderDir = strtolower((string) ($_GET['order'][0]['dir'] ?? 'desc')) === 'asc' ? 'ASC' : 'DESC';
$orderBy  = $columns[$orderCol] ?? 'id';

$start  = max(0, (int) ($_GET['start'] ?? 0));
$length = (int) ($_GET['length'] ?? 25);
$length = ($length === -1) ? 10000 : max(1, min(500, $length));

try {
    $totalAll = (int) $pdo->query('SELECT COUNT(*) FROM booth_exits')->fetchColumn();

    $count = $pdo->prepare("SELECT COUNT(*) FROM booth_exits $where");
    $count->execute($params);
    $totalFiltered = (int) $count->fetchColumn();

    $stmt = $pdo->prepare(
        "SELECT id, device_id, distance_cm, exited_at
           FROM booth_exits $where
          ORDER BY $orderBy $orderDir, id $orderDir
          LIMIT $length OFFSET $start"
    );
    $stmt->execute($params);
    $rows = $stmt->fetchAll();

    $today = date('Y-m-d');
    $sum   = $pdo->prepare(
        'SELECT COUNT(*) FROM booth_exits WHERE exited_at >= ? AND exited_at < DATE_ADD(?, INTERVAL 1 DAY)'
    );
    $sum->execute([$today, $today]);
    $todayCount = (int) $sum->fetchColumn();

    $lastExit = $pdo->query('SELECT MAX(exited_at) FROM booth_exits')->fetchColumn() ?: null;
} catch (PDOException $e) {
    // Migration 004 not applied: an empty list with a hint, not a broken page.
    json_response([
        'draw' => (int) ($_GET['draw'] ?? 1), 'recordsTotal' => 0, 'recordsFiltered' => 0,
        'data' => [], 'summary' => ['total' => 0, 'today' => 0, 'last' => null],
        'notice' => 'The exit log table is missing. Run database/migrations/004_booth_exits.sql.',
    ]);
}

json_response([
    'draw'            => (int) ($_GET['draw'] ?? 1),
    'recordsTotal'    => $totalAll,
    'recordsFiltered' => $totalFiltered,
    'data'            => array_map(static fn(array $r): array => [
        'id'          => (int) $r['id'],
        'exit_id'     => sprintf('EXT-%06d', (int) $r['id']),
        'date'        => fmt_date(substr((string) $r['exited_at'], 0, 10)),
        'time'        => fmt_time(substr((string) $r['exited_at'], 11, 8)),
        'device_id'   => $r['device_id'],
        'distance_cm' => $r['distance_cm'] !== null ? (float) $r['distance_cm'] : null,
    ], $rows),
    'summary'         => [
        'total' => $totalFiltered,
        'today' => $todayCount,
        'last'  => $lastExit ? fmt_datetime($lastExit) : null,
    ],
]);
