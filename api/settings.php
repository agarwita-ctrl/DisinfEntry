<?php
/**
 * System settings API (administrator only).
 *
 *   GET                          — current settings
 *   POST action=save             — persist settings (multipart for the logo)
 *   POST action=regenerate_key   — issue a new booth API key
 *   POST action=remove_logo
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
api_require_admin();

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $s = all_settings();
    json_response([
        'success'   => true,
        'settings'  => $s,
        'timezones' => DateTimeZone::listIdentifiers(),
    ]);
}

require_csrf();

$action = (string) ($_POST['action'] ?? '');

/* ------------------------------------------------ regenerate key */
if ($action === 'regenerate_key') {
    $key = 'DISINF-' . strtoupper(bin2hex(random_bytes(12)));
    set_setting('api_key', $key);
    audit('Regenerated the booth API key', 'System Settings');
    json_response(['success' => true, 'api_key' => $key, 'message' => 'API key regenerated. Update the ESP32 firmware.']);
}

/* ---------------------------------------------------- remove logo */
if ($action === 'remove_logo') {
    $current = setting('logo', '');
    if ($current !== '' && is_file(APP_ROOT . '/' . $current)) {
        @unlink(APP_ROOT . '/' . $current);
    }
    set_setting('logo', '');
    audit('Removed the system logo', 'System Settings');
    json_response(['success' => true, 'message' => 'Logo removed.']);
}

if ($action !== 'save') {
    json_response(['success' => false, 'message' => 'Unknown action.'], 400);
}

/* ------------------------------------------------------- validate */
$farmName = trim((string) ($_POST['farm_name'] ?? ''));
$sysName  = trim((string) ($_POST['system_name'] ?? ''));
$timezone = (string) ($_POST['timezone'] ?? 'Asia/Manila');
$threshold = (string) ($_POST['temperature_threshold'] ?? '37.8');
$duration  = (int) ($_POST['disinfection_duration'] ?? 8);
$refresh   = (int) ($_POST['refresh_interval'] ?? 3);
$offline   = (int) ($_POST['device_offline_after'] ?? 60);
$lowLevel  = (int) ($_POST['low_disinfectant_at'] ?? 20);

if ($farmName === '' || $sysName === '') {
    json_response(['success' => false, 'message' => 'Farm name and system name are required.'], 422);
}
if (!in_array($timezone, DateTimeZone::listIdentifiers(), true)) {
    json_response(['success' => false, 'message' => 'Please select a valid timezone.'], 422);
}
if (!is_numeric($threshold) || (float) $threshold < 30.0 || (float) $threshold > 45.0) {
    json_response(['success' => false, 'message' => 'Temperature threshold must be between 30.0 and 45.0 °C.'], 422);
}
if ($duration < 1 || $duration > 120) {
    json_response(['success' => false, 'message' => 'Disinfection duration must be between 1 and 120 seconds.'], 422);
}
if ($refresh < 1 || $refresh > 60) {
    json_response(['success' => false, 'message' => 'Dashboard refresh interval must be between 1 and 60 seconds.'], 422);
}
if ($offline < 15 || $offline > 3600) {
    json_response(['success' => false, 'message' => 'Offline threshold must be between 15 and 3600 seconds.'], 422);
}
if ($lowLevel < 1 || $lowLevel > 90) {
    json_response(['success' => false, 'message' => 'Low disinfectant warning must be between 1% and 90%.'], 422);
}

/* ---------------------------------------- booth sequence (adopted by the ESP32)
 * Bounded by BOOTH_DIRECTIVE_RANGES rather than by numbers repeated here: the
 * sketch silently ignores a directive outside its own range, so a value this
 * form accepted but the booth refused would leave the two disagreeing about
 * how the booth is running.
 */
$boothLabels = [
    'detection_distance_cm' => ['Detection distance', 'cm'],
    'presence_confirm_ms'   => ['Presence confirmation time', 'ms'],
    'pump_on_ms'            => ['Spray burst duration', 'ms'],
    'pump_off_ms'           => ['Interval between bursts', 'ms'],
    'door_open_ms'          => ['Door hold-open time', 'ms'],
];

$booth = [];
foreach (BOOTH_DIRECTIVE_RANGES as [$key, $default, $min, $max]) {
    if (!isset($boothLabels[$key])) {
        continue;   // the fever threshold is validated above, as temperature_threshold
    }
    [$label, $unit] = $boothLabels[$key];
    $raw = $_POST[$key] ?? (string) $default;

    if (!is_numeric($raw) || (float) $raw < $min || (float) $raw > $max) {
        json_response([
            'success' => false,
            'message' => sprintf('%s must be between %s and %s %s.', $label, $min, $max, $unit),
        ], 422);
    }

    $booth[$key] = $unit === 'ms'
        ? (string) (int) round((float) $raw)
        : (string) round((float) $raw, 1);
}

$retention = (int) ($_POST['telemetry_retention_hours'] ?? 24);
if ($retention < 1 || $retention > 720) {
    json_response(['success' => false, 'message' => 'Telemetry retention must be between 1 and 720 hours.'], 422);
}
$booth['telemetry_retention_hours'] = (string) $retention;

/* ----------------------------------------------------- logo upload */
$logoPath = null;

if (!empty($_FILES['logo']['name']) && ($_FILES['logo']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
    $file = $_FILES['logo'];

    if ($file['error'] !== UPLOAD_ERR_OK) {
        json_response(['success' => false, 'message' => 'Logo upload failed (error ' . $file['error'] . ').'], 422);
    }
    if ($file['size'] > 2 * 1024 * 1024) {
        json_response(['success' => false, 'message' => 'Logo must be 2 MB or smaller.'], 422);
    }

    $info = @getimagesize($file['tmp_name']);
    $allowed = [
        IMAGETYPE_PNG  => 'png',
        IMAGETYPE_JPEG => 'jpg',
        IMAGETYPE_GIF  => 'gif',
        IMAGETYPE_WEBP => 'webp',
    ];

    if (!$info || !isset($allowed[$info[2]])) {
        json_response(['success' => false, 'message' => 'Logo must be a PNG, JPG, GIF or WEBP image.'], 422);
    }

    $dir = APP_ROOT . '/assets/uploads';
    if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
        json_response(['success' => false, 'message' => 'Could not create the uploads directory.'], 500);
    }

    $name = 'logo_' . date('YmdHis') . '.' . $allowed[$info[2]];
    if (!move_uploaded_file($file['tmp_name'], $dir . '/' . $name)) {
        json_response(['success' => false, 'message' => 'Could not save the uploaded logo.'], 500);
    }

    // Drop the previous file so uploads do not accumulate.
    $previous = setting('logo', '');
    if ($previous !== '' && is_file(APP_ROOT . '/' . $previous)) {
        @unlink(APP_ROOT . '/' . $previous);
    }

    $logoPath = 'assets/uploads/' . $name;
}

/* -------------------------------------------------------- persist */
$values = [
    'farm_name'             => $farmName,
    'system_name'           => $sysName,
    'timezone'              => $timezone,
    'temperature_threshold' => (string) round((float) $threshold, 1),
    'disinfection_duration' => (string) $duration,
    'refresh_interval'      => (string) $refresh,
    'device_offline_after'  => (string) $offline,
    'low_disinfectant_at'   => (string) $lowLevel,
] + $booth;
if ($logoPath !== null) {
    $values['logo'] = $logoPath;
}

foreach ($values as $key => $value) {
    set_setting($key, $value);
}

audit('Updated system settings', 'System Settings');

json_response([
    'success'  => true,
    // The booth is never pushed to: it picks these up on its next sync, which
    // it only performs while idle with nobody in range.
    'message'  => 'Settings saved. The booth adopts the new sequence on its next sync.',
    'logo'     => $logoPath,
    'settings' => $values,
]);
