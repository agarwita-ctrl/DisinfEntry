<?php
/**
 * Audit log API (administrator only) — DataTables server-side processing.
 *
 *   GET  draw/start/length/order/search + filters: user, module, date_from, date_to
 *   GET  ?export=csv
 *   POST {action: clear, before: 'YYYY-MM-DD'}
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
api_require_admin();

$pdo = db();

/* ============================================================
 * Purge old entries
 * ========================================================== */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $in = json_input();

    if (($in['action'] ?? '') !== 'clear') {
        json_response(['success' => false, 'message' => 'Unknown action.'], 400);
    }

    $before = (string) ($in['before'] ?? '');
    if ($before === '' || !strtotime($before)) {
        json_response(['success' => false, 'message' => 'Provide a valid cut-off date.'], 422);
    }

    $stmt = $pdo->prepare('DELETE FROM audit_logs WHERE created_at < ?');
    $stmt->execute([date('Y-m-d 00:00:00', strtotime($before))]);
    $deleted = $stmt->rowCount();

    audit(sprintf('Purged %d audit log entries older than %s', $deleted, fmt_date($before)), 'Audit Logs');
    json_response(['success' => true, 'message' => "Removed $deleted log entr" . ($deleted === 1 ? 'y' : 'ies') . '.']);
}

/* ============================================================
 * Filters
 * ========================================================== */
$where  = 'WHERE 1=1';
$params = [];

if (($u = trim((string) ($_GET['user'] ?? ''))) !== '') {
    $where .= ' AND (username LIKE ? OR user_id = ?)';
    $params[] = '%' . $u . '%';
    $params[] = (int) $u;
}
if (($m = trim((string) ($_GET['module'] ?? ''))) !== '') {
    $where .= ' AND module = ?';
    $params[] = $m;
}
if (($f = trim((string) ($_GET['date_from'] ?? ''))) !== '' && strtotime($f)) {
    $where .= ' AND created_at >= ?';
    $params[] = date('Y-m-d 00:00:00', strtotime($f));
}
if (($t = trim((string) ($_GET['date_to'] ?? ''))) !== '' && strtotime($t)) {
    $where .= ' AND created_at <= ?';
    $params[] = date('Y-m-d 23:59:59', strtotime($t));
}

$search = trim((string) ($_GET['search']['value'] ?? ''));
if ($search !== '') {
    $where .= ' AND (username LIKE ? OR activity LIKE ? OR module LIKE ? OR ip_address LIKE ?)';
    $like = '%' . $search . '%';
    array_push($params, $like, $like, $like, $like);
}

/* ============================================================
 * CSV export
 * ========================================================== */
if (($_GET['export'] ?? '') === 'csv') {
    $stmt = $pdo->prepare("SELECT * FROM audit_logs $where ORDER BY id DESC LIMIT 50000");
    $stmt->execute($params);

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="audit_logs_' . date('Ymd_His') . '.csv"');

    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, [setting('system_name', 'DisinfEntry') . ' — Audit Log Export']);
    fputcsv($out, ['Generated', date('Y-m-d H:i:s'), 'By', current_user()['full_name']]);
    fputcsv($out, []);
    fputcsv($out, ['ID', 'Date & Time', 'User', 'Module', 'Activity', 'IP Address']);

    foreach ($stmt->fetchAll() as $r) {
        fputcsv($out, [
            $r['id'],
            date('Y-m-d H:i:s', strtotime((string) $r['created_at'])),
            csv_safe($r['username'] ?: 'system'),
            csv_safe($r['module']),
            csv_safe($r['activity']),
            csv_safe($r['ip_address']),
        ]);
    }
    fclose($out);

    audit('Exported audit logs as CSV', 'Audit Logs');
    exit;
}

/* ============================================================
 * Listing
 * ========================================================== */
$totalAll = (int) $pdo->query('SELECT COUNT(*) FROM audit_logs')->fetchColumn();

$countStmt = $pdo->prepare("SELECT COUNT(*) FROM audit_logs $where");
$countStmt->execute($params);
$filtered = (int) $countStmt->fetchColumn();

$columns  = ['id', 'created_at', 'username', 'module', 'activity', 'ip_address'];
$orderCol = (int) ($_GET['order'][0]['column'] ?? 0);
$orderDir = strtolower((string) ($_GET['order'][0]['dir'] ?? 'desc')) === 'asc' ? 'ASC' : 'DESC';
$orderBy  = $columns[$orderCol] ?? 'id';

$start  = max(0, (int) ($_GET['start'] ?? 0));
$length = (int) ($_GET['length'] ?? 25);
$length = ($length === -1) ? 10000 : max(1, min(500, $length));

$stmt = $pdo->prepare("SELECT * FROM audit_logs $where ORDER BY $orderBy $orderDir LIMIT $length OFFSET $start");
$stmt->execute($params);

$data = array_map(static fn(array $r): array => [
    'id'         => (int) $r['id'],
    'datetime'   => fmt_datetime($r['created_at']),
    'raw_date'   => $r['created_at'],
    'user'       => $r['username'] ?: 'system',
    'module'     => $r['module'],
    'activity'   => $r['activity'],
    'ip'         => $r['ip_address'] ?: '—',
], $stmt->fetchAll());

$modules = $pdo->query('SELECT DISTINCT module FROM audit_logs ORDER BY module')->fetchAll(PDO::FETCH_COLUMN);

json_response([
    'draw'            => (int) ($_GET['draw'] ?? 1),
    'recordsTotal'    => $totalAll,
    'recordsFiltered' => $filtered,
    'data'            => $data,
    'modules'         => $modules,
]);
