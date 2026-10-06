<?php
/**
 * Dashboard data feed.
 *
 *   GET ?action=stats   — KPI cards
 *   GET ?action=charts  — daily / weekly / monthly / pass-vs-denied / temp distribution
 *   GET ?action=recent  — recent activity table
 *   GET ?action=all     — everything in one round trip (default)
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
api_require_login();

$pdo       = db();
$action    = (string) ($_GET['action'] ?? 'all');
$threshold = temp_threshold();
$today     = date('Y-m-d');

/* ============================================================
 * KPI cards
 * ========================================================== */
function build_stats(PDO $pdo, float $threshold, string $today): array
{
    $stmt = $pdo->prepare(
        'SELECT
            COUNT(*)                                                   AS total,
            SUM(access_status = "granted")                             AS granted,
            SUM(access_status = "denied")                              AS denied,
            SUM(temperature > :t1)                                     AS high_temp,
            AVG(temperature)                                           AS avg_temp
         FROM entries
         WHERE entry_date = :d'
    );
    $stmt->execute([':t1' => $threshold, ':d' => $today]);
    $today_row = $stmt->fetch() ?: [];

    // Same query for yesterday, to render the trend deltas.
    $stmt->execute([':t1' => $threshold, ':d' => date('Y-m-d', strtotime('-1 day'))]);
    $yday = $stmt->fetch() ?: [];

    $activeUsers = (int) $pdo->query('SELECT COUNT(*) FROM users WHERE status = "active"')->fetchColumn();
    $totalUsers  = (int) $pdo->query('SELECT COUNT(*) FROM users')->fetchColumn();
    $allTime     = (int) $pdo->query('SELECT COUNT(*) FROM entries')->fetchColumn();

    $delta = static function (int $now, int $before): array {
        if ($before === 0) {
            return ['pct' => $now > 0 ? 100 : 0, 'dir' => $now > 0 ? 'up' : 'flat'];
        }
        $pct = (int) round((($now - $before) / $before) * 100);
        return ['pct' => abs($pct), 'dir' => $pct > 0 ? 'up' : ($pct < 0 ? 'down' : 'flat')];
    };

    $total   = (int) ($today_row['total'] ?? 0);
    $granted = (int) ($today_row['granted'] ?? 0);
    $denied  = (int) ($today_row['denied'] ?? 0);
    $high    = (int) ($today_row['high_temp'] ?? 0);

    return [
        'total_today'  => $total,
        'granted'      => $granted,
        'denied'       => $denied,
        'high_temp'    => $high,
        'active_users' => $activeUsers,
        'total_users'  => $totalUsers,
        'all_time'     => $allTime,
        'avg_temp'     => $today_row['avg_temp'] !== null ? round((float) $today_row['avg_temp'], 1) : null,
        'pass_rate'    => $total > 0 ? round($granted / $total * 100, 1) : 0.0,
        'trend'        => [
            'total'   => $delta($total,   (int) ($yday['total'] ?? 0)),
            'granted' => $delta($granted, (int) ($yday['granted'] ?? 0)),
            'denied'  => $delta($denied,  (int) ($yday['denied'] ?? 0)),
            'high'    => $delta($high,    (int) ($yday['high_temp'] ?? 0)),
        ],
    ];
}

/* ============================================================
 * Charts
 * ========================================================== */
function build_charts(PDO $pdo, float $threshold): array
{
    /* --- Daily: entries per hour today --- */
    $daily = ['labels' => [], 'granted' => [], 'denied' => []];
    $rows  = $pdo->query(
        'SELECT HOUR(entry_time) AS h,
                SUM(access_status = "granted") AS g,
                SUM(access_status = "denied")  AS d
           FROM entries WHERE entry_date = CURDATE()
          GROUP BY h'
    )->fetchAll();
    $byHour = [];
    foreach ($rows as $r) {
        $byHour[(int) $r['h']] = ['g' => (int) $r['g'], 'd' => (int) $r['d']];
    }
    for ($h = 0; $h < 24; $h++) {
        $daily['labels'][]  = sprintf('%02d:00', $h);
        $daily['granted'][] = $byHour[$h]['g'] ?? 0;
        $daily['denied'][]  = $byHour[$h]['d'] ?? 0;
    }

    /* --- Weekly: last 7 days --- */
    $weekly = ['labels' => [], 'granted' => [], 'denied' => []];
    $rows = $pdo->query(
        'SELECT entry_date,
                SUM(access_status = "granted") AS g,
                SUM(access_status = "denied")  AS d
           FROM entries
          WHERE entry_date >= DATE_SUB(CURDATE(), INTERVAL 6 DAY)
          GROUP BY entry_date'
    )->fetchAll();
    $byDate = [];
    foreach ($rows as $r) {
        $byDate[$r['entry_date']] = ['g' => (int) $r['g'], 'd' => (int) $r['d']];
    }
    for ($i = 6; $i >= 0; $i--) {
        $date = date('Y-m-d', strtotime("-$i day"));
        $weekly['labels'][]  = date('D j', strtotime($date));
        $weekly['granted'][] = $byDate[$date]['g'] ?? 0;
        $weekly['denied'][]  = $byDate[$date]['d'] ?? 0;
    }

    /* --- Monthly: last 12 months --- */
    $monthly = ['labels' => [], 'total' => [], 'denied' => []];
    $rows = $pdo->query(
        'SELECT DATE_FORMAT(entry_date, "%Y-%m") AS ym,
                COUNT(*) AS c,
                SUM(access_status = "denied") AS d
           FROM entries
          WHERE entry_date >= DATE_SUB(DATE_FORMAT(CURDATE(), "%Y-%m-01"), INTERVAL 11 MONTH)
          GROUP BY ym'
    )->fetchAll();
    $byMonth = [];
    foreach ($rows as $r) {
        $byMonth[$r['ym']] = ['c' => (int) $r['c'], 'd' => (int) $r['d']];
    }
    for ($i = 11; $i >= 0; $i--) {
        $ts = strtotime("first day of -$i month");
        $ym = date('Y-m', $ts);
        $monthly['labels'][] = date('M Y', $ts);
        $monthly['total'][]  = $byMonth[$ym]['c'] ?? 0;
        $monthly['denied'][] = $byMonth[$ym]['d'] ?? 0;
    }

    /* --- Pass vs Denied (last 30 days) --- */
    $stmt = $pdo->query(
        'SELECT SUM(access_status = "granted") AS g, SUM(access_status = "denied") AS d
           FROM entries WHERE entry_date >= DATE_SUB(CURDATE(), INTERVAL 29 DAY)'
    );
    $pd = $stmt->fetch() ?: [];
    $passDenied = ['granted' => (int) ($pd['g'] ?? 0), 'denied' => (int) ($pd['d'] ?? 0)];

    /* --- Temperature distribution (last 30 days) --- */
    $buckets = [
        ['label' => '< 35.0',      'min' => 0.0,   'max' => 35.0],
        ['label' => '35.0 – 36.0', 'min' => 35.0,  'max' => 36.0],
        ['label' => '36.0 – 36.5', 'min' => 36.0,  'max' => 36.5],
        ['label' => '36.5 – 37.0', 'min' => 36.5,  'max' => 37.0],
        ['label' => '37.0 – 37.5', 'min' => 37.0,  'max' => 37.5],
        ['label' => '37.5 – 38.0', 'min' => 37.5,  'max' => 38.0],
        ['label' => '38.0 – 39.0', 'min' => 38.0,  'max' => 39.0],
        ['label' => '≥ 39.0',      'min' => 39.0,  'max' => 99.0],
    ];
    $tempDist = ['labels' => [], 'counts' => [], 'high' => []];
    $stmt = $pdo->prepare(
        'SELECT COUNT(*) FROM entries
          WHERE entry_date >= DATE_SUB(CURDATE(), INTERVAL 29 DAY)
            AND temperature >= ? AND temperature < ?'
    );
    foreach ($buckets as $b) {
        $stmt->execute([$b['min'], $b['max']]);
        $tempDist['labels'][] = $b['label'];
        $tempDist['counts'][] = (int) $stmt->fetchColumn();
        $tempDist['high'][]   = $b['min'] >= $threshold;
    }

    return [
        'daily'       => $daily,
        'weekly'      => $weekly,
        'monthly'     => $monthly,
        'pass_denied' => $passDenied,
        'temp_dist'   => $tempDist,
    ];
}

/* ============================================================
 * Recent activity
 * ========================================================== */
function build_recent(PDO $pdo, int $limit = 10): array
{
    $stmt = $pdo->prepare(
        'SELECT id, person_name, employee_id, temperature, entry_date, entry_time,
                disinfection_status, misting_status, access_status, remarks, created_at
           FROM entries
          ORDER BY id DESC
          LIMIT ' . max(1, min(50, $limit))
    );
    $stmt->execute();

    return array_map(static fn(array $r): array => [
        'id'          => (int) $r['id'],
        'name'        => $r['person_name'],
        'employee_id' => $r['employee_id'],
        'temperature' => (float) $r['temperature'],
        'date'        => fmt_date($r['entry_date']),
        'time'        => fmt_time($r['entry_time']),
        'disinfection'=> $r['disinfection_status'],
        'misting'     => $r['misting_status'],
        'status'      => $r['access_status'],
        'remarks'     => $r['remarks'],
        'created_at'  => $r['created_at'],
    ], $stmt->fetchAll());
}

/* ============================================================
 * Dispatch
 * ========================================================== */
$payload = ['success' => true, 'threshold' => $threshold, 'generated_at' => date('Y-m-d H:i:s')];

if ($action === 'stats' || $action === 'all') {
    $payload['stats'] = build_stats($pdo, $threshold, $today);
}
if ($action === 'charts' || $action === 'all') {
    $payload['charts'] = build_charts($pdo, $threshold);
}
if ($action === 'recent' || $action === 'all') {
    $payload['recent'] = build_recent($pdo, (int) ($_GET['limit'] ?? 10));
}

json_response($payload);
