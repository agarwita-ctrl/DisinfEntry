<?php
/**
 * Database connection (PDO / MySQL).
 * Adjust the credentials below to match your XAMPP setup.
 */

declare(strict_types=1);

/**
 * The application runs as a dedicated account with SELECT/INSERT/UPDATE/DELETE
 * on this schema and nothing else - not as root.
 *
 * Running as root@localhost with an empty password turned any future injection
 * or file-read defect into full compromise of every database on the server, and
 * let the web application drop schemas it has no business touching.
 *
 * The grant is schema-wide (`disinfentry`.*), so a table added by a later
 * migration is covered without a new GRANT. An earlier revision of this file
 * described per-table grants, which were a workaround for mysql.db being
 * corrupt on this server: the account and its privileges could not be written
 * durably and vanished on the next restart, taking the application offline with
 * "Database connection failed". Those tables have since been repaired
 * (REPAIR TABLE mysql.db, mysql.columns_priv) and the grant persists normally.
 *
 * Migrations are still run manually as root: they need DDL this account lacks,
 * by design.
 */
/**
 * Credentials are NOT stored in this file, so the project can be copied or
 * shared without carrying the database password with it.
 *
 * They come from, in order:
 *   1. config/database.local.php  (returns ['user' => ..., 'pass' => ...];
 *      see database.local.example.php - this file is kept out of version control)
 *   2. the DISINFENTRY_DB_USER / DISINFENTRY_DB_PASS environment variables
 *
 * There is no default account and no fallback to root: if neither source
 * supplies a user, the connection fails with a message saying what to create.
 */
$local = is_file(__DIR__ . '/database.local.php') ? (array) require __DIR__ . '/database.local.php' : [];

define('DB_HOST', (string) ($local['host'] ?? getenv('DISINFENTRY_DB_HOST') ?: '127.0.0.1'));
define('DB_PORT', (int) ($local['port'] ?? getenv('DISINFENTRY_DB_PORT') ?: 3306));
define('DB_NAME', (string) ($local['name'] ?? getenv('DISINFENTRY_DB_NAME') ?: 'disinfentry'));
define('DB_USER', (string) ($local['user'] ?? getenv('DISINFENTRY_DB_USER') ?: ''));
define('DB_PASS', (string) ($local['pass'] ?? getenv('DISINFENTRY_DB_PASS') ?: ''));
unset($local);

/**
 * Returns a shared PDO instance.
 */
function db(): PDO
{
    static $pdo = null;

    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', DB_HOST, DB_PORT, DB_NAME);

    $options = [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ];

    try {
        if (DB_USER === '') {
            throw new PDOException('No database user configured (config/database.local.php or DISINFENTRY_DB_USER).');
        }
        $pdo = new PDO($dsn, DB_USER, DB_PASS, $options);
    } catch (PDOException $e) {
        error_log('DisinfEntry DB connection failed: ' . $e->getMessage());
        http_response_code(500);
        if (PHP_SAPI === 'cli') {
            fwrite(STDERR, 'Database connection failed: ' . $e->getMessage() . PHP_EOL);
            exit(1);
        }
        exit('<h3 style="font-family:sans-serif">Database connection failed.</h3>'
            . '<p style="font-family:sans-serif">Start MySQL in XAMPP, import <code>database/disinfentry.sql</code>, '
            . 'and create <code>config/database.local.php</code> from <code>database.local.example.php</code>.</p>');
    }

    return $pdo;
}
