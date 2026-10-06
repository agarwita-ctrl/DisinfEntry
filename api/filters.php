<?php
/**
 * Shared WHERE-clause builder for the entry listing, exports and reports.
 * Always returns a clause beginning with "WHERE 1=1" so callers may safely
 * append further "AND ..." fragments.
 */

declare(strict_types=1);

/**
 * @param  array<string,mixed> $req  Request array ($_GET or $_POST)
 * @return array{0:string,1:array<int,mixed>}  [whereSql, boundParams]
 */
function entry_filters(array $req, float $threshold): array
{
    $where  = 'WHERE 1=1';
    $params = [];

    $val = static fn(string $k): string => trim((string) ($req[$k] ?? ''));

    /* ---- Exact date ---- */
    if (($date = $val('date')) !== '' && strtotime($date)) {
        $where .= ' AND entry_date = ?';
        $params[] = date('Y-m-d', strtotime($date));
    }

    /* ---- Date range ---- */
    if (($from = $val('date_from')) !== '' && strtotime($from)) {
        $where .= ' AND entry_date >= ?';
        $params[] = date('Y-m-d', strtotime($from));
    }
    if (($to = $val('date_to')) !== '' && strtotime($to)) {
        $where .= ' AND entry_date <= ?';
        $params[] = date('Y-m-d', strtotime($to));
    }

    /* ---- Month (YYYY-MM) ---- */
    if (($month = $val('month')) !== '' && preg_match('/^\d{4}-\d{2}$/', $month)) {
        $where .= ' AND DATE_FORMAT(entry_date, "%Y-%m") = ?';
        $params[] = $month;
    }

    /* ---- Access status ---- */
    if (in_array($status = $val('status'), ['granted', 'denied'], true)) {
        $where .= ' AND access_status = ?';
        $params[] = $status;
    }

    /* ---- Disinfection status ---- */
    if (in_array($dis = $val('disinfection'), ['completed', 'incomplete', 'skipped'], true)) {
        $where .= ' AND disinfection_status = ?';
        $params[] = $dis;
    }

    /* ---- Temperature band ---- */
    $temp = $val('temp');
    if ($temp === 'high') {
        $where .= ' AND temperature > ?';
        $params[] = $threshold;
    } elseif ($temp === 'normal') {
        $where .= ' AND temperature <= ?';
        $params[] = $threshold;
    }

    /* ---- Explicit temperature bounds ---- */
    if (($min = $val('temp_min')) !== '' && is_numeric($min)) {
        $where .= ' AND temperature >= ?';
        $params[] = (float) $min;
    }
    if (($max = $val('temp_max')) !== '' && is_numeric($max)) {
        $where .= ' AND temperature <= ?';
        $params[] = (float) $max;
    }

    /* ---- Name / employee ID ---- */
    if (($name = $val('name')) !== '') {
        $where .= ' AND employee_id LIKE ?';
        $params[] = '%' . $name . '%';
    }

    /* ---- Device ---- */
    if (($device = $val('device')) !== '') {
        $where .= ' AND device_id = ?';
        $params[] = $device;
    }

    /* ---- DataTables global search box ---- */
    $search = trim((string) ($req['search']['value'] ?? $req['search'] ?? ''));
    if ($search !== '') {
        $where .= ' AND (remarks LIKE ?
                         OR CAST(temperature AS CHAR) LIKE ? OR CAST(id AS CHAR) LIKE ?)';
        $like = '%' . $search . '%';
        array_push($params, $like, $like, $like);
    }

    return [$where, $params];
}

/**
 * Human-readable description of the active filters, used in export headers.
 */
function describe_filters(array $req): string
{
    $bits = [];
    $val  = static fn(string $k): string => trim((string) ($req[$k] ?? ''));

    if ($v = $val('date'))         { $bits[] = 'Date: ' . fmt_date($v); }
    if ($v = $val('date_from'))    { $bits[] = 'From: ' . fmt_date($v); }
    if ($v = $val('date_to'))      { $bits[] = 'To: ' . fmt_date($v); }
    if ($v = $val('month'))        { $bits[] = 'Month: ' . date('F Y', strtotime($v . '-01')); }
    if ($v = $val('status'))       { $bits[] = 'Access: ' . ucfirst($v); }
    if ($v = $val('disinfection')) { $bits[] = 'Disinfection: ' . ucfirst($v); }
    if ($v = $val('temp'))         { $bits[] = 'Temperature: ' . ucfirst($v); }
    if ($v = $val('name'))         { $bits[] = 'Employee ID contains: ' . $v; }
    if ($v = $val('device'))       { $bits[] = 'Device: ' . $v; }

    return $bits ? implode('  |  ', $bits) : 'All records';
}
