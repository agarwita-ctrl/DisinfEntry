<?php
/**
 * Notification centre API.
 *
 *   GET  ?action=recent[&limit=10]
 *   GET  ?action=list[&type=&read=&limit=]
 *   POST {action: mark_read|mark_all_read|delete|clear_read}
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
api_require_login();

$pdo = db();

/* ============================================================
 * Reads
 * ========================================================== */
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $action = (string) ($_GET['action'] ?? 'recent');
    $limit  = max(1, min(200, (int) ($_GET['limit'] ?? ($action === 'recent' ? 10 : 100))));

    $where  = 'WHERE 1=1';
    $params = [];

    $type = (string) ($_GET['type'] ?? '');
    if (in_array($type, ['high_temperature', 'denied_entry', 'device_offline', 'low_disinfectant', 'system'], true)) {
        $where .= ' AND type = ?';
        $params[] = $type;
    }

    $read = (string) ($_GET['read'] ?? '');
    if ($read === '0' || $read === '1') {
        $where .= ' AND is_read = ?';
        $params[] = (int) $read;
    }

    $stmt = $pdo->prepare(
        "SELECT n.*, e.person_name, e.temperature, e.access_status
           FROM notifications n
           LEFT JOIN entries e ON e.id = n.entry_id
           $where
          ORDER BY n.id DESC
          LIMIT $limit"
    );
    $stmt->execute($params);

    $items = array_map(static fn(array $r): array => [
        'id'          => (int) $r['id'],
        'type'        => $r['type'],
        'severity'    => $r['severity'],
        'title'       => $r['title'],
        'message'     => $r['message'],
        'entry_id'    => $r['entry_id'] !== null ? (int) $r['entry_id'] : null,
        'person_name' => $r['person_name'],
        'temperature' => $r['temperature'] !== null ? (float) $r['temperature'] : null,
        'is_read'     => (int) $r['is_read'],
        'created_at'  => $r['created_at'],
        'created_h'   => fmt_datetime($r['created_at']),
    ], $stmt->fetchAll());

    $counts = $pdo->query(
        'SELECT type, COUNT(*) AS c, SUM(is_read = 0) AS unread
           FROM notifications GROUP BY type'
    )->fetchAll();

    $byType = [];
    foreach ($counts as $c) {
        $byType[$c['type']] = ['total' => (int) $c['c'], 'unread' => (int) $c['unread']];
    }

    json_response([
        'success'    => true,
        'items'      => $items,
        'unread'     => unread_notification_count(),
        'total'      => (int) $pdo->query('SELECT COUNT(*) FROM notifications')->fetchColumn(),
        'by_type'    => $byType,
        // The delete control is administrator-only server-side; this lets the
        // page avoid offering a button that would only ever return 403.
        'can_delete' => is_admin(),
    ]);
}

/* ============================================================
 * Writes
 * ========================================================== */
require_csrf();

$in     = json_input();
$action = (string) ($in['action'] ?? '');

switch ($action) {

    case 'mark_read': {
        $id = (int) ($in['id'] ?? 0);
        $pdo->prepare('UPDATE notifications SET is_read = 1 WHERE id = ?')->execute([$id]);
        json_response(['success' => true, 'unread' => unread_notification_count()]);
    }

    case 'mark_all_read': {
        $affected = $pdo->exec('UPDATE notifications SET is_read = 1 WHERE is_read = 0');
        if ($affected) {
            audit("Marked $affected notification(s) as read", 'Notifications');
        }
        json_response(['success' => true, 'unread' => 0, 'message' => 'All notifications marked as read.']);
    }

    case 'delete': {
        // Administrator only, and audited. These are the fever and denied-entry
        // alerts the system exists to raise: any signed-in user could previously
        // erase one by id, leaving no record that it had ever been raised.
        api_require_admin();

        $id = (int) ($in['id'] ?? 0);

        $stmt = $pdo->prepare('SELECT type, title FROM notifications WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch();

        if (!$row) {
            json_response(['success' => false, 'message' => 'Notification not found.'], 404);
        }

        $pdo->prepare('DELETE FROM notifications WHERE id = ?')->execute([$id]);
        audit(sprintf('Deleted notification #%d (%s: %s)', $id, $row['type'], $row['title']), 'Notifications');

        json_response(['success' => true, 'unread' => unread_notification_count(), 'message' => 'Notification deleted.']);
    }

    case 'clear_read': {
        api_require_admin();
        $affected = $pdo->exec('DELETE FROM notifications WHERE is_read = 1');
        audit("Cleared $affected read notification(s)", 'Notifications');
        json_response(['success' => true, 'message' => "Cleared $affected read notification(s)."]);
    }

    default:
        json_response(['success' => false, 'message' => 'Unknown action.'], 400);
}
