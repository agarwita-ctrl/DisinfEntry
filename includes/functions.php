<?php
/**
 * Shared helper functions: settings, escaping, CSRF, audit logging,
 * notifications, JSON responses and formatting.
 */

declare(strict_types=1);

/* ============================================================
 * Settings
 * ========================================================== */

/**
 * The settings table, read once per request.
 *
 * The cache lives here rather than as a static inside setting() so that
 * set_setting() can actually drop it. While it was private to setting(), a
 * write left the stale value in place and every later read in the same request
 * returned the old one - which is not what set_setting() documented, and is the
 * kind of bug that only shows up once someone reads back what they just wrote.
 *
 * Passing true forgets the cache; the next read reloads it.
 */
function settings_cache(bool $forget = false): array
{
    static $cache = null;

    if ($forget) {
        $cache = null;
        return [];
    }

    if ($cache === null) {
        $cache = [];
        try {
            foreach (db()->query('SELECT setting_key, setting_value FROM settings') as $row) {
                $cache[$row['setting_key']] = (string) $row['setting_value'];
            }
        } catch (Throwable $e) {
            // No settings table yet (fresh checkout, migration not run). Callers
            // fall back to their defaults rather than failing the request.
            $cache = [];
        }
    }

    return $cache;
}

/**
 * Reads a setting value (cached per request).
 */
function setting(string $key, string $default = ''): string
{
    $cache = settings_cache();

    return array_key_exists($key, $cache) && $cache[$key] !== '' ? $cache[$key] : $default;
}

/**
 * Writes a setting value and invalidates the request cache.
 */
function set_setting(string $key, string $value): void
{
    $stmt = db()->prepare(
        'INSERT INTO settings (setting_key, setting_value) VALUES (?, ?)
         ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)'
    );
    $stmt->execute([$key, $value]);

    settings_cache(true);
}

function all_settings(): array
{
    $out = [];
    foreach (db()->query('SELECT setting_key, setting_value FROM settings') as $row) {
        $out[$row['setting_key']] = (string) $row['setting_value'];
    }
    return $out;
}

function temp_threshold(): float
{
    return (float) setting('temperature_threshold', '37.8');
}

/**
 * The bounds the ESP32 sketch enforces in adoptDirectives(), keyed by the name
 * it looks for in the sync response: [default, min, max].
 *
 * The sketch ignores anything outside its own range and carries on with the
 * value it had - so a setting saved outside these bounds would leave the booth
 * running one sequence while System Settings displayed another. Validation and
 * the sync response share this list so the two can never drift apart.
 */
const BOOTH_DIRECTIVE_RANGES = [
    'fever_threshold_c'        => ['temperature_threshold', 37.8, 30.0, 45.0],
    'detection_distance_cm'    => ['detection_distance_cm', 50.0, 2.0, 400.0],
    'presence_confirm_time_ms' => ['presence_confirm_ms',   5000, 1000, 60000],
    'pump_on_time_ms'          => ['pump_on_ms',            2000, 200,  60000],
    'pump_off_time_ms'         => ['pump_off_ms',           2000, 0,    60000],
    'door_open_time_ms'        => ['door_open_ms',          5000, 500,  120000],
];

/**
 * The settings the booth adopts over the air, named as the firmware expects.
 *
 * Every value is clamped into the sketch's accepted range: sending one outside
 * it is the same as sending nothing, which would silently strand the booth on
 * its previous setting.
 */
function booth_directives(): array
{
    $out = [];
    foreach (BOOTH_DIRECTIVE_RANGES as $field => [$key, $default, $min, $max]) {
        $value = (float) setting($key, (string) $default);
        $value = max($min, min($max, $value));
        // The four *_ms directives are whole milliseconds; the other two carry
        // one decimal, which is all the sensors resolve.
        $out[$field] = str_ends_with($field, '_ms') ? (int) round($value) : round($value, 1);
    }
    return $out;
}

/* ============================================================
 * Output / formatting
 * ========================================================== */

function e(?string $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function url(string $path = ''): string
{
    return BASE_URL . '/' . ltrim($path, '/');
}

/**
 * URL for a bundled CSS/JS file, stamped with its modification time.
 *
 * Browsers cache these without asking the server again, so after an edit a page
 * could pair new markup with the old script - e.g. a table header with one
 * column fewer than the cached script's column list - and the table would hang
 * on "Loading…". The stamp changes whenever the file does.
 */
function asset_url(string $path): string
{
    $file = APP_ROOT . '/' . ltrim($path, '/');

    return url($path) . (is_file($file) ? '?v=' . filemtime($file) : '');
}

/**
 * Neutralizes CSV/XLSX formula injection.
 *
 * A cell value that opens with =, +, -, @, tab or CR is a live formula to
 * Excel/Sheets the moment the file is opened - and several of the strings that
 * land in exports (person_name, remarks) arrive from the ESP32 booth over a
 * device API key, not a logged-in user, which is a much easier bar to clear.
 * Prefixing with a single quote is the standard mitigation: spreadsheet
 * software then renders the text as-is instead of evaluating it.
 */
function csv_safe(?string $value): string
{
    $value = (string) $value;
    if ($value !== '' && strpbrk($value[0], "=+-@\t\r") !== false) {
        return "'" . $value;
    }
    return $value;
}

function fmt_date(?string $date): string
{
    return $date ? date('M d, Y', strtotime($date)) : '—';
}

function fmt_time(?string $time): string
{
    return $time ? date('h:i:s A', strtotime($time)) : '—';
}

function fmt_datetime(?string $dt): string
{
    return $dt ? date('M d, Y h:i A', strtotime($dt)) : '—';
}

function role_label(string $role): string
{
    return $role === 'administrator' ? 'Administrator' : 'Farm Manager';
}

/* ============================================================
 * CSRF
 * ========================================================== */

function csrf_token(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="csrf_token" value="' . e(csrf_token()) . '">';
}

function csrf_check(?string $token): bool
{
    return !empty($_SESSION['csrf_token'])
        && is_string($token)
        && hash_equals($_SESSION['csrf_token'], $token);
}

/**
 * Aborts the request with a JSON error unless a valid CSRF token was supplied.
 *
 * Responds 403 rather than the fashionable 419: Apache does not recognise 419
 * and rewrites it to 500, which makes a correctly-blocked request look like a
 * server crash in the logs.
 */
function require_csrf(): void
{
    $token = $_POST['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? null;
    if (!csrf_check($token)) {
        json_response([
            'success'    => false,
            'csrf_error' => true,
            'message'    => 'Invalid or expired security token. Please reload the page.',
        ], 403);
    }
}

/* ============================================================
 * JSON helpers
 * ========================================================== */

function json_response(array $payload, int $status = 200): never
{
    if (!headers_sent()) {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        header('X-Content-Type-Options: nosniff');
    }
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

/**
 * Reads the request body as JSON, falling back to form-encoded input.
 */
function json_input(): array
{
    $raw = file_get_contents('php://input') ?: '';
    $data = json_decode($raw, true);
    if (is_array($data)) {
        return $data;
    }
    return $_POST ?: [];
}

/* ============================================================
 * Audit log
 * ========================================================== */

/**
 * Records one activity line.
 *
 * Every field is cut to its column width here rather than at the call sites.
 * Callers build these with sprintf() out of names, remarks and submitted
 * usernames, none of which are bounded by the caller - and an over-long value
 * is silently truncated by MySQL in the default XAMPP configuration, but throws
 * outright under STRICT_TRANS_TABLES (the MySQL 8 default). Neither belongs in
 * an audit trail.
 */
function audit(string $activity, string $module = 'System', ?int $userId = null, ?string $username = null): void
{
    $userId   ??= ($_SESSION['user']['id'] ?? null);
    $username ??= ($_SESSION['user']['username'] ?? 'system');

    $stmt = db()->prepare(
        'INSERT INTO audit_logs (user_id, username, module, activity, ip_address)
         VALUES (?, ?, ?, ?, ?)'
    );
    $stmt->execute([
        $userId,
        mb_substr($username, 0, 60),
        mb_substr($module, 0, 50),
        mb_substr($activity, 0, 255),
        client_ip(),
    ]);
}

function client_ip(): string
{
    return substr((string) ($_SERVER['REMOTE_ADDR'] ?? 'cli'), 0, 45);
}

/* ============================================================
 * Notifications
 * ========================================================== */

/**
 * Raises one notification.
 *
 * Cut to the column widths for the same reason audit() is: the booth builds
 * these from a 60-character device id plus a 255-character remark, which
 * comfortably overruns message's 255 and cost the tail of the alert.
 */
function notify(string $type, string $title, string $message, string $severity = 'info', ?int $entryId = null): void
{
    $stmt = db()->prepare(
        'INSERT INTO notifications (type, severity, title, message, entry_id)
         VALUES (?, ?, ?, ?, ?)'
    );
    $stmt->execute([
        $type,
        $severity,
        mb_substr($title, 0, 120),
        mb_substr($message, 0, 255),
        $entryId,
    ]);
}

function unread_notification_count(): int
{
    return (int) db()->query('SELECT COUNT(*) FROM notifications WHERE is_read = 0')->fetchColumn();
}

/* ============================================================
 * Device status
 * ========================================================== */

/**
 * Returns each booth with a derived online/offline state.
 */
function device_states(): array
{
    $offlineAfter = max(10, (int) setting('device_offline_after', '60'));
    $lowLevel     = max(1, (int) setting('low_disinfectant_at', '20'));

    $rows = db()->query('SELECT * FROM devices ORDER BY device_name')->fetchAll();
    foreach ($rows as &$row) {
        $seen          = $row['last_seen'] ? strtotime($row['last_seen']) : 0;
        $row['age']    = $seen ? time() - $seen : null;
        $row['online'] = $seen && (time() - $seen) <= $offlineAfter;

        // NULL means no sensor has reported a level. Casting that to int would
        // read as 0% and raise a false empty-tank alarm, so it stays null and
        // the level is simply not low.
        $level = $row['disinfectant_level'] !== null ? (int) $row['disinfectant_level'] : null;
        $row['disinfectant_level'] = $level;
        $row['low_level'] = $level !== null && $level <= $lowLevel;
    }
    return $rows;
}

/**
 * The booth's own view of itself: its last reported sensor readings, its
 * thermometer health and when it last ran a cycle.
 *
 * Deliberately NOT a real-time view of the state machine. The sketch syncs only
 * while it is IDLE with nobody in range - a blocking POST must never land inside
 * a spray burst or a door hold - so the state it reports is always IDLE, and the
 * readings arrive in batches up to a heartbeat apart. `reading_age` carries how
 * stale they are so the UI can say so rather than imply a live feed.
 *
 * Everything here is best-effort. A booth running the older single-reading
 * firmware reports no state and stores no samples, and a database that has not
 * had migration 001 applied has no tables to read - both come back as nulls
 * rather than as an error, so the live monitor keeps working either way.
 */
function booth_live_state(array $device): array
{
    $online = !empty($device['online']);

    $out = [
        'state'       => $online ? ($device['state'] ?? null) : null,
        'boot_id'     => $device['boot_id'] ?? null,
        'mlx_ok'      => isset($device['mlx_ok']) && $device['mlx_ok'] !== null ? (bool) $device['mlx_ok'] : null,
        'last_cycle'  => $device['last_cycle_at'] ?? null,
        'distance_cm' => null,
        'object_temp' => null,
        'ambient_temp' => null,
        'reading_age' => null,
    ];

    if (!$online) {
        return $out;
    }

    try {
        $pdo = db();

        $stmt = $pdo->prepare(
            'SELECT distance_cm, recorded_at FROM booth_distance_samples
              WHERE device_id = ? ORDER BY id DESC LIMIT 1'
        );
        $stmt->execute([$device['device_id']]);
        if ($row = $stmt->fetch()) {
            $out['distance_cm'] = $row['distance_cm'] !== null ? (float) $row['distance_cm'] : null;
            $out['reading_age'] = max(0, time() - strtotime((string) $row['recorded_at']));
        }

        $stmt = $pdo->prepare(
            'SELECT object_temp_c, ambient_temp_c FROM booth_temperature_samples
              WHERE device_id = ? ORDER BY id DESC LIMIT 1'
        );
        $stmt->execute([$device['device_id']]);
        if ($row = $stmt->fetch()) {
            $out['object_temp']  = $row['object_temp_c'] !== null ? (float) $row['object_temp_c'] : null;
            $out['ambient_temp'] = $row['ambient_temp_c'] !== null ? (float) $row['ambient_temp_c'] : null;
        }
    } catch (Throwable $e) {
        // Migration 001 not applied yet - the live view simply has no telemetry.
    }

    return $out;
}

/* ============================================================
 * Misc
 * ========================================================== */

/**
 * Resolves a report preset to a [start, end] date pair (Y-m-d).
 */
function report_range(string $preset, ?string $from = null, ?string $to = null): array
{
    return match ($preset) {
        'daily'   => [date('Y-m-d'), date('Y-m-d')],
        'weekly'  => [date('Y-m-d', strtotime('monday this week')), date('Y-m-d', strtotime('sunday this week'))],
        'monthly' => [date('Y-m-01'), date('Y-m-t')],
        'yearly'  => [date('Y-01-01'), date('Y-12-31')],
        default   => [
            $from && strtotime($from) ? date('Y-m-d', strtotime($from)) : date('Y-m-d'),
            $to   && strtotime($to)   ? date('Y-m-d', strtotime($to))   : date('Y-m-d'),
        ],
    };
}

function range_label(string $preset, string $start, string $end): string
{
    return match ($preset) {
        'daily'   => 'Daily Report — ' . fmt_date($start),
        'weekly'  => 'Weekly Report — ' . fmt_date($start) . ' to ' . fmt_date($end),
        'monthly' => 'Monthly Report — ' . date('F Y', strtotime($start)),
        'yearly'  => 'Yearly Report — ' . date('Y', strtotime($start)),
        default   => 'Custom Report — ' . fmt_date($start) . ' to ' . fmt_date($end),
    };
}
