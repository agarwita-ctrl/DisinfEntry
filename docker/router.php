<?php
/**
 * Request router for PHP's built-in web server (used by the Docker image).
 *
 * The built-in server does not read .htaccess, so the protections those files
 * provide on Apache are re-stated here:
 *   - config/, includes/, database/, esp32/ and docker/ are never served;
 *   - source/config file types (.sql .md .ino .ini .log ...) are never served;
 *   - dotfiles (.htaccess, .gitignore, .env ...) are never served;
 *   - nothing under assets/uploads is ever executed;
 *   - the same security headers as the Apache config are sent.
 *
 * Everything else is left to the server: returning false serves the file as is, or
 * runs it if it is a .php script.
 */

declare(strict_types=1);

$path = rawurldecode((string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH));

// A backslash is a path separator on Windows, so "/includes%5cauth.php" would reach
// includes/auth.php there while sailing past the folder check below. Treat it as "/".
// A NUL byte can truncate a path in lower-level code; no legitimate request has one.
$path = str_replace('\\', '/', $path);
if (str_contains($path, "\0")) {
    http_response_code(400);
    header('Content-Type: text/plain; charset=utf-8');
    echo "400 Bad Request\n";
    return true;
}

// Normalise so "/config/../config/x" and "//config/x" cannot slip past the checks.
$parts = [];
foreach (explode('/', $path) as $seg) {
    if ($seg === '' || $seg === '.') {
        continue;
    }
    if ($seg === '..') {
        array_pop($parts);
        continue;
    }
    $parts[] = $seg;
}
$clean = '/' . implode('/', $parts);

$forbidden =
    // private directories (first path segment)
    preg_match('#^/(config|includes|database|esp32|docker)(/|$)#i', $clean)
    // source / config / data file types anywhere
    || preg_match('#\.(sql|md|ino|h|hpp|c|cpp|log|bak|ya?ml|ini|sh|conf|dockerfile)$#i', $clean)
    || preg_match('#(^|/)(Dockerfile|\.dockerignore|\.gitignore|\.gitattributes)$#i', $clean)
    // any dotfile or dot-directory
    || preg_match('#(^|/)\.#', $clean)
    // uploads are images only - never run anything from there
    || preg_match('#^/assets/uploads/.*\.(php\d?|phtml|phar|cgi|pl|py)$#i', $clean);

if ($forbidden) {
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    echo "403 Forbidden\n";
    return true;
}

// Same headers the Apache .htaccess sets.
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: SAMEORIGIN');
header('Referrer-Policy: strict-origin-when-cross-origin');
header('Permissions-Policy: camera=(), microphone=(), geolocation=(), payment=()');

// HSTS only when the request really arrived over HTTPS. Behind Railway's proxy that is
// signalled by X-Forwarded-Proto, which is trusted only when the deployment opted in.
if (getenv('DISINFENTRY_BEHIND_PROXY') === '1'
    && strtolower((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https') {
    header('Strict-Transport-Security: max-age=31536000');
}

$target = __DIR__ . '/..' . $clean;

// Static files are served here rather than left to the server: the built-in server
// serves them natively and ignores header() calls made in this router, so CSS and JS
// went out without the security headers above.
if (is_file($target) && !preg_match('/\.php\d?$/i', $target)) {
    $root = realpath(__DIR__ . '/..');
    $real = realpath($target);
    if ($real === false || $root === false || strncmp($real, $root . DIRECTORY_SEPARATOR, strlen($root) + 1) !== 0) {
        http_response_code(403);
        header('Content-Type: text/plain; charset=utf-8');
        echo "403 Forbidden\n";
        return true;
    }

    static $types = [
        'css' => 'text/css; charset=utf-8',       'js'   => 'application/javascript; charset=utf-8',
        'json' => 'application/json; charset=utf-8', 'map' => 'application/json; charset=utf-8',
        'png' => 'image/png',  'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'gif' => 'image/gif',
        'webp' => 'image/webp', 'ico' => 'image/x-icon', 'svg' => 'image/svg+xml',
        'woff' => 'font/woff', 'woff2' => 'font/woff2', 'ttf' => 'font/ttf', 'txt' => 'text/plain; charset=utf-8',
    ];
    $ext = strtolower(pathinfo($real, PATHINFO_EXTENSION));
    header('Content-Type: ' . ($types[$ext] ?? 'application/octet-stream'));
    header('Content-Length: ' . (string) filesize($real));
    // Page links carry ?v=<mtime>, so a changed file gets a new URL; a day is safe.
    header('Cache-Control: public, max-age=86400');
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'HEAD') {
        readfile($real);
    }
    return true;
}

// A PHP script or a directory (its index.php): let the server run it.
if ($clean === '/' || is_file($target) || is_dir($target)) {
    return false;
}

http_response_code(404);
header('Content-Type: text/plain; charset=utf-8');
echo "404 Not Found\n";
return true;
