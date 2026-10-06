<?php
/**
 * Report data feed.
 *
 *   GET ?preset=daily|weekly|monthly|yearly|custom [&from=&to=]
 *
 * Returns the summary block, a per-period trend series, a per-person
 * breakdown, the denial reasons and the underlying rows.
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
api_require_login();

$pdo       = db();
$threshold = temp_threshold();

$preset = (string) ($_GET['preset'] ?? 'daily');
if (!in_array($preset, ['daily', 'weekly', 'monthly', 'yearly', 'custom'], true)) {
    $preset = 'daily';
}

[$start, $end] = report_range($preset, $_GET['from'] ?? null, $_GET['to'] ?? null);

if (strtotime($start) > strtotime($end)) {
    [$start, $end] = [$end, $start];
}

$range = [$start, $end];

/* ============================================================
 * Summary
 * ========================================================== */
$stmt = $pdo->prepare(
    'SELECT COUNT(*) AS total,
            SUM(access_status = "granted")            AS passed,
            SUM(access_status = "denied")             AS denied,
            SUM(temperature > ?)                      AS high_temp,
            SUM(disinfection_status = "completed")    AS disinfected,
            AVG(temperature)                          AS avg_temp,
            MIN(temperature)                          AS min_temp,
            MAX(temperature)                          AS max_temp,
            COUNT(DISTINCT entry_date)                AS active_days
       FROM entries
      WHERE entry_date BETWEEN ? AND ?'
);
$stmt->execute([$threshold, $start, $end]);
$s = $stmt->fetch() ?: [];

$total = (int) ($s['total'] ?? 0);

$summary = [
    'total'        => $total,
    'passed'       => (int) ($s['passed'] ?? 0),
    'denied'       => (int) ($s['denied'] ?? 0),
    'high_temp'    => (int) ($s['high_temp'] ?? 0),
    'disinfected'  => (int) ($s['disinfected'] ?? 0),
    'avg_temp'     => $s['avg_temp'] !== null ? round((float) $s['avg_temp'], 2) : null,
    'min_temp'     => $s['min_temp'] !== null ? round((float) $s['min_temp'], 1) : null,
    'max_temp'     => $s['max_temp'] !== null ? round((float) $s['max_temp'], 1) : null,
    'active_days'  => (int) ($s['active_days'] ?? 0),
    'pass_rate'    => $total > 0 ? round((int) $s['passed'] / $total * 100, 1) : 0.0,
    'denial_rate'  => $total > 0 ? round((int) $s['denied'] / $total * 100, 1) : 0.0,
    'daily_avg'    => ((int) ($s['active_days'] ?? 0)) > 0
        ? round($total / (int) $s['active_days'], 1)
        : 0.0,
];

/* ============================================================
 * Trend series — bucket granularity follows the range length
 * ========================================================== */
$days = (int) ((strtotime($end) - strtotime($start)) / 86400) + 1;

if ($preset === 'daily' || $days <= 1) {
    $groupSql = 'DATE_FORMAT(entry_time, "%H:00")';
    $labelFmt = 'hour';
} elseif ($days <= 92) {
    $groupSql = 'entry_date';
    $labelFmt = 'day';
} else {
    $groupSql = 'DATE_FORMAT(entry_date, "%Y-%m")';
    $labelFmt = 'month';
}

$stmt = $pdo->prepare(
    "SELECT $groupSql AS bucket,
            COUNT(*)                       AS total,
            SUM(access_status = 'granted') AS passed,
            SUM(access_status = 'denied')  AS denied,
            SUM(temperature > ?)           AS high_temp,
            AVG(temperature)               AS avg_temp
       FROM entries
      WHERE entry_date BETWEEN ? AND ?
      GROUP BY bucket
      ORDER BY bucket"
);
$stmt->execute([$threshold, $start, $end]);

$trend = ['labels' => [], 'total' => [], 'passed' => [], 'denied' => [], 'high_temp' => [], 'avg_temp' => []];
foreach ($stmt->fetchAll() as $r) {
    $trend['labels'][] = match ($labelFmt) {
        'hour'  => $r['bucket'],
        'day'   => date('M j', strtotime((string) $r['bucket'])),
        default => date('M Y', strtotime($r['bucket'] . '-01')),
    };
    $trend['total'][]     = (int) $r['total'];
    $trend['passed'][]    = (int) $r['passed'];
    $trend['denied'][]    = (int) $r['denied'];
    $trend['high_temp'][] = (int) $r['high_temp'];
    $trend['avg_temp'][]  = round((float) $r['avg_temp'], 1);
}

/* ============================================================
 * Temperature distribution over the range
 * ========================================================== */
$buckets = [
    ['label' => '< 36.0',      'min' => 0.0,  'max' => 36.0],
    ['label' => '36.0 – 36.5', 'min' => 36.0, 'max' => 36.5],
    ['label' => '36.5 – 37.0', 'min' => 36.5, 'max' => 37.0],
    ['label' => '37.0 – 37.5', 'min' => 37.0, 'max' => 37.5],
    ['label' => '37.5 – 38.0', 'min' => 37.5, 'max' => 38.0],
    ['label' => '≥ 38.0',      'min' => 38.0, 'max' => 99.0],
];
$dist = ['labels' => [], 'counts' => []];
$stmt = $pdo->prepare(
    'SELECT COUNT(*) FROM entries
      WHERE entry_date BETWEEN ? AND ? AND temperature >= ? AND temperature < ?'
);
foreach ($buckets as $b) {
    $stmt->execute([$start, $end, $b['min'], $b['max']]);
    $dist['labels'][] = $b['label'];
    $dist['counts'][] = (int) $stmt->fetchColumn();
}

/* ============================================================
 * Denial breakdown
 * ========================================================== */
$stmt = $pdo->prepare(
    'SELECT
        SUM(temperature > ?)                                          AS by_temp,
        SUM(temperature <= ? AND disinfection_status = "skipped")      AS by_disinfection,
        SUM(temperature <= ? AND disinfection_status <> "skipped")     AS by_other
       FROM entries
      WHERE entry_date BETWEEN ? AND ? AND access_status = "denied"'
);
$stmt->execute([$threshold, $threshold, $threshold, $start, $end]);
$d = $stmt->fetch() ?: [];

$denials = [
    'high_temperature'    => (int) ($d['by_temp'] ?? 0),
    'skipped_disinfection'=> (int) ($d['by_disinfection'] ?? 0),
    'other'               => (int) ($d['by_other'] ?? 0),
];

/* ============================================================
 * Rows
 * ========================================================== */
$stmt = $pdo->prepare(
    'SELECT id, person_name, employee_id, temperature, entry_date, entry_time,
            disinfection_status, access_status, remarks
       FROM entries
      WHERE entry_date BETWEEN ? AND ?
      ORDER BY entry_date DESC, entry_time DESC
      LIMIT 2000'
);
$stmt->execute([$start, $end]);

$rows = array_map(static fn(array $r): array => [
    'id'           => (int) $r['id'],
    'entry_code'   => 'ENT-' . str_pad((string) $r['id'], 6, '0', STR_PAD_LEFT),
    'name'         => $r['person_name'],
    'employee_id'  => $r['employee_id'] ?: '—',
    'temperature'  => (float) $r['temperature'],
    'high_temp'    => (float) $r['temperature'] > $threshold,
    'date'         => fmt_date($r['entry_date']),
    'time'         => fmt_time($r['entry_time']),
    'disinfection' => $r['disinfection_status'],
    'status'       => $r['access_status'],
    'remarks'      => $r['remarks'],
], $stmt->fetchAll());

// Deliberately not audited. This endpoint is the Reports page's data feed, and
// the page calls it on load and again on every preset change - so browsing the
// four presets once wrote five "Generated report" rows and buried the entries
// the trail exists to surface. Exports are the auditable event, and
// api/report_export.php records those.

json_response([
    'success'   => true,
    'preset'    => $preset,
    'range'     => ['start' => $start, 'end' => $end, 'days' => $days],
    'title'     => range_label($preset, $start, $end),
    'threshold' => $threshold,
    'summary'   => $summary,
    'trend'     => $trend,
    'dist'      => $dist,
    'denials'   => $denials,
    'rows'      => $rows,
    'farm'      => setting('farm_name', 'Poultry Farm'),
    'system'    => setting('system_name', 'DisinfEntry'),
    'generated' => date('F d, Y h:i A'),
    'by'        => current_user()['full_name'],
]);
