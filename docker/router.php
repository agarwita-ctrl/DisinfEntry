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

// A real file, or a directory (its index.php): let the server handle it.
$target = __DIR__ . '/..' . $clean;
if ($clean === '/' || is_file($target) || is_dir($target)) {
    return false;
}

http_response_code(404);
header('Content-Type: text/plain; charset=utf-8');
echo "404 Not Found\n";
return true;
