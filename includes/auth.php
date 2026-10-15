<?php
/**
 * Authentication, session management and role-based access control.
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/config.php';

const SESSION_IDLE_TIMEOUT = 1800; // 30 minutes

/**
 * Passwords shipped in the README's default accounts. Signing in with one of
 * them is allowed once, but the user is held on My Profile until it is changed.
 */
const DEFAULT_PASSWORDS = ['Admin@123', 'Manager@123'];

/** Longest password accepted. bcrypt only reads the first 72 bytes, so more is silently ignored. */
const PASSWORD_MAX_BYTES = 72;

/* ============================================================
 * Session state
 * ========================================================== */

function current_user(): ?array
{
    return $_SESSION['user'] ?? null;
}

function is_logged_in(): bool
{
    return isset($_SESSION['user']['id']);
}

function is_admin(): bool
{
    return (current_user()['role'] ?? '') === 'administrator';
}

/**
 * Logs the user in by seeding the session (regenerating the id first).
 */
function login_user(array $user): void
{
    session_regenerate_id(true);

    // Issue a fresh CSRF token at the privilege boundary. The old one was seeded
    // before sign-in and carried straight into the authenticated session.
    unset($_SESSION['csrf_token']);

    $_SESSION['user'] = [
        'id'          => (int) $user['id'],
        'employee_id' => $user['employee_id'],
        'full_name'   => $user['full_name'],
        'username'    => $user['username'],
        'role'        => $user['role'],
    ];
    $_SESSION['last_activity'] = time();
    $_SESSION['login_at']      = time();

    db()->prepare('UPDATE users SET last_login = NOW() WHERE id = ?')->execute([(int) $user['id']]);
}

/**
 * The signed-in account as the database has it right now.
 *
 * Falls back to a query without password_changed_at when migration 005 has not been
 * applied yet, so an older database keeps working (it just cannot end other sessions
 * after a password change).
 */
function load_account(int $id): ?array
{
    try {
        $stmt = db()->prepare('SELECT status, role, full_name, password_changed_at FROM users WHERE id = ?');
        $stmt->execute([$id]);
    } catch (PDOException $e) {
        $stmt = db()->prepare('SELECT status, role, full_name, NULL AS password_changed_at FROM users WHERE id = ?');
        $stmt->execute([$id]);
    }
    return $stmt->fetch() ?: null;
}

/**
 * Re-checks the session against the database on every request. Returns the reason the
 * session must end, or null - in which case the role and name are refreshed, so a demoted
 * administrator loses admin rights on their next request, not at their next login.
 */
function session_problem(): ?string
{
    $acct = load_account((int) $_SESSION['user']['id']);

    if (!$acct || $acct['status'] !== 'active') {
        return 'Your account is no longer active. Contact the administrator.';
    }
    // A password change ends every session that started before it.
    if ($acct['password_changed_at'] !== null
        && strtotime((string) $acct['password_changed_at']) > (int) ($_SESSION['login_at'] ?? 0)) {
        return 'Your password was changed. Please sign in again.';
    }

    $_SESSION['user']['role']      = $acct['role'];
    $_SESSION['user']['full_name'] = $acct['full_name'];
    return null;
}

/**
 * Records a password change and keeps only the current session alive.
 * Call after the new hash is written.
 */
function mark_password_changed(int $userId): void
{
    try {
        db()->prepare('UPDATE users SET password_changed_at = ? WHERE id = ?')
            ->execute([date('Y-m-d H:i:s'), $userId]);
    } catch (PDOException $e) {
        return;   // migration 005 not applied
    }
    if (is_logged_in() && (int) $_SESSION['user']['id'] === $userId) {
        session_regenerate_id(true);
        $_SESSION['login_at'] = time();
    }
}

/** True while the signed-in user still has to replace a shipped default password. */
function must_change_password(): bool
{
    return !empty($_SESSION['must_change_password']);
}

function logout_user(): void
{
    if (is_logged_in()) {
        audit('User logged out', 'Authentication');
    }
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
    }
    session_destroy();
}

/* ============================================================
 * Guards
 * ========================================================== */

/**
 * Blocks page access unless a valid, non-expired session exists.
 */
function require_login(): void
{
    if (!is_logged_in()) {
        redirect_to_login('Please sign in to continue.');
    }

    if (isset($_SESSION['last_activity']) && (time() - $_SESSION['last_activity']) > SESSION_IDLE_TIMEOUT) {
        logout_user();
        redirect_to_login('Your session expired due to inactivity.');
    }
    $_SESSION['last_activity'] = time();

    // A deactivated, deleted or password-changed account loses access immediately.
    if ($problem = session_problem()) {
        logout_user();
        redirect_to_login($problem);
    }

    // Hold anyone still on a shipped default password at My Profile until they
    // change it. Pages only: this is a prompt, not an API boundary.
    if (must_change_password() && !in_array(basename($_SERVER['SCRIPT_NAME'] ?? ''), ['profile.php', 'logout.php'], true)) {
        header('Location: ' . url('profile.php'));
        exit;
    }
}

/**
 * Blocks page access unless the signed-in user is an administrator.
 */
function require_admin(): void
{
    require_login();
    if (!is_admin()) {
        http_response_code(403);
        include APP_ROOT . '/includes/403.php';
        exit;
    }
}

/**
 * API variant of require_login(): responds with JSON instead of redirecting.
 */
function api_require_login(): void
{
    if (!is_logged_in()) {
        json_response(['success' => false, 'message' => 'Not authenticated.', 'logged_out' => true], 401);
    }
    if (isset($_SESSION['last_activity']) && (time() - $_SESSION['last_activity']) > SESSION_IDLE_TIMEOUT) {
        logout_user();
        json_response(['success' => false, 'message' => 'Session expired.', 'logged_out' => true], 401);
    }
    $_SESSION['last_activity'] = time();

    // Same rule as require_login(): a deactivated or deleted account loses API
    // access immediately, not when it next loads a page.
    if ($problem = session_problem()) {
        logout_user();
        json_response(['success' => false, 'message' => $problem, 'logged_out' => true], 401);
    }

    // Anyone still on a shipped default password is held to the profile page - and that
    // has to cover the API too, or the default admin account can regenerate the booth key
    // or purge the audit log without ever changing it. Only the read-only status polls the
    // page header makes are let through, so My Profile itself stays quiet.
    if (must_change_password()
        && !(($_SERVER['REQUEST_METHOD'] ?? '') === 'GET'
             && in_array(basename($_SERVER['SCRIPT_NAME'] ?? ''), ['notifications.php', 'device_status.php'], true))) {
        json_response([
            'success' => false,
            'message' => 'Change your default password first (My Profile).',
        ], 403);
    }
}

function api_require_admin(): void
{
    api_require_login();
    if (!is_admin()) {
        json_response(['success' => false, 'message' => 'Administrator access required.'], 403);
    }
}

function redirect_to_login(string $message = ''): never
{
    $q = $message !== '' ? '?msg=' . urlencode($message) : '';
    header('Location: ' . url('login.php') . $q);
    exit;
}

/* ============================================================
 * Login throttling
 *
 * Counted from audit_logs, which already records every failed attempt with the
 * submitted username and the client IP - so the throttle costs no new table.
 *
 * It deliberately does NOT live in the session. The previous version kept the
 * counter in $_SESSION, which meant an attacker who simply discarded the cookie
 * got a fresh allowance on every request and the five-attempt limit imposed no
 * cost whatsoever.
 *
 * Three limits, so that locking an account out costs the attacker real effort
 * and does not fall out of eight requests from anywhere:
 *
 *   - this username FROM this address   (guessing at one account)
 *   - this address, any username        (one password against many accounts)
 *   - this username, any address        (distributed guessing; set high, since
 *                                        it is the only one a third party can
 *                                        trip for somebody else's account)
 *
 * The earlier "username OR ip" rule let anyone lock the administrator out by
 * submitting eight wrong passwords for that username from their own machine.
 * ========================================================== */

const LOGIN_MAX_PER_USER_IP = 8;
const LOGIN_MAX_PER_IP      = 20;
const LOGIN_MAX_PER_USER    = 50;
const LOGIN_WINDOW_MINUTES  = 15;

/**
 * The three buckets as [SQL condition, bound values, limit].
 *
 * @return array<int, array{0:string, 1:array<int,string>, 2:int}>
 */
function login_buckets(string $username): array
{
    $ip = client_ip();

    // audit() stores the username cut to 60 characters. Compare the same cut value, or a
    // longer submitted name never matches its own rows and escapes the per-user buckets.
    $username = mb_substr($username, 0, 60);

    return [
        ['username = ? AND ip_address = ?', [$username, $ip], LOGIN_MAX_PER_USER_IP],
        ['ip_address = ?',                  [$ip],            LOGIN_MAX_PER_IP],
        ['username = ?',                    [$username],      LOGIN_MAX_PER_USER],
    ];
}

/** [count, oldest attempt in the window] for one bucket. */
function login_bucket_stats(string $condition, array $values): array
{
    $stmt = db()->prepare(
        "SELECT COUNT(*), MIN(created_at) FROM audit_logs
          WHERE module = ?
            AND activity LIKE ?
            AND $condition
            AND created_at > ?"
    );
    $stmt->execute([
        'Authentication',
        'Failed login attempt%',
        ...$values,
        date('Y-m-d H:i:s', time() - LOGIN_WINDOW_MINUTES * 60),
    ]);
    $row = $stmt->fetch(PDO::FETCH_NUM);

    return [(int) $row[0], $row[1] ?: null];
}

function login_attempts_exceeded(string $username): bool
{
    return lockout_remaining($username) > 0;
}

/**
 * Seconds until the caller gets an attempt back: the longest wait among the
 * buckets that are currently over their limit, or 0 if none is.
 */
function lockout_remaining(string $username): int
{
    $wait = 0;

    foreach (login_buckets($username) as [$condition, $values, $limit]) {
        [$count, $oldest] = login_bucket_stats($condition, $values);
        if ($count >= $limit && $oldest !== null) {
            $wait = max($wait, (strtotime((string) $oldest) + LOGIN_WINDOW_MINUTES * 60) - time());
        }
    }

    return max(0, $wait);
}

/* ============================================================
 * Password policy
 * ========================================================== */

/**
 * Returns an error string, or null when the password is acceptable.
 */
function password_policy_error(string $password): ?string
{
    if (strlen($password) < 8) {
        return 'Password must be at least 8 characters long.';
    }
    if (strlen($password) > PASSWORD_MAX_BYTES) {
        return 'Password must be at most ' . PASSWORD_MAX_BYTES . ' characters long.';
    }
    if (!preg_match('/[A-Za-z]/', $password) || !preg_match('/\d/', $password)) {
        return 'Password must contain at least one letter and one number.';
    }
    if (in_array($password, DEFAULT_PASSWORDS, true)) {
        return 'Choose a password other than the shipped default.';
    }
    return null;
}

function hash_password(string $password): string
{
    return password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);
}
