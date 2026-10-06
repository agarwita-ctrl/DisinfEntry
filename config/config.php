<?php
/**
 * Global bootstrap: constants, session, settings, timezone.
 * Every page and API endpoint includes this file first.
 */

declare(strict_types=1);

define('APP_ROOT', dirname(__DIR__));
define('APP_VERSION', '1.0.0');

// Don't advertise the PHP version. expose_php is PHP_INI_SYSTEM so it cannot be
// turned off per-application, and mod_headers' unset does not catch a header
// mod_php adds during the handler - but PHP will drop its own banner on request.
header_remove('X-Powered-By');

// Never render PHP errors to the browser. The .htaccess flag only works under
// mod_php; shared hosts that run PHP as FastCGI ignore it, so set it here too.
// Errors still go to the server's error log.
ini_set('display_errors', '0');
ini_set('log_errors', '1');

require_once APP_ROOT . '/config/database.php';
require_once APP_ROOT . '/includes/functions.php';

/* ---- Base URL (works from any sub-folder under htdocs) ---- */
if (!defined('BASE_URL')) {
    $script = str_replace('\\', '/', $_SERVER['SCRIPT_NAME'] ?? '/index.php');
    $root   = str_replace('\\', '/', APP_ROOT);
    $docs   = str_replace('\\', '/', realpath($_SERVER['DOCUMENT_ROOT'] ?? '') ?: '');
    $base   = ($docs && str_starts_with($root, $docs)) ? substr($root, strlen($docs)) : dirname($script);
    define('BASE_URL', rtrim($base, '/') ?: '');
}

/* ---- Session ---- */
if (session_status() === PHP_SESSION_NONE) {
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => BASE_URL ?: '/',
        'httponly' => true,
        'samesite' => 'Lax',
        'secure'   => (($_SERVER['HTTPS'] ?? '') === 'on'),
    ]);
    session_name('DISINFENTRY_SID');
    session_start();
}

/* ---- Timezone from settings ---- */
date_default_timezone_set(setting('timezone', 'Asia/Manila'));

/*
 * Make MySQL's NOW()/CURDATE() agree with PHP's clock.
 *
 * Booth heartbeats are stamped with NOW() and read back with strtotime()/time(),
 * and "today" on the dashboard is CURDATE(). If the database server runs in a
 * different timezone from the one configured here - common on a new machine -
 * booths read as offline and "today" is the wrong day. Pinning the session
 * offset removes the dependence on how the server happens to be set up.
 */
try {
    db()->exec("SET time_zone = '" . date('P') . "'");
} catch (Throwable $e) {
    // No database yet; db() has already reported that.
}
