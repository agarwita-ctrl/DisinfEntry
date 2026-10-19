<?php
/**
 * User management (administrator only).
 *
 *   GET                       - every account
 *   POST {action:"create",         full_name, username, email, role, contact_number?, employee_id?}
 *   POST {action:"update",         id, full_name, email, role, contact_number?}
 *   POST {action:"reset_password", id}
 *   POST {action:"set_status",     id, status: active|inactive}
 *
 * create and reset_password generate a temporary password and E-MAIL it to the account's
 * registered address (includes/mail.php). It is never returned to the administrator, never
 * logged and stored only as a hash; the account is held on My Profile until its owner
 * replaces it (users.must_change_password, migration 006). If the e-mail cannot be sent the
 * whole change is rolled back, so nobody ends up with a password that was never delivered.
 *
 * Two rules protect the system from locking itself out: an administrator cannot deactivate
 * or demote their own account, and the last active administrator can never be deactivated
 * or demoted by anyone.
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/mail.php';

api_require_admin();

$pdo    = db();
$me     = (int) $_SESSION['user']['id'];
$MODULE = 'User Management';

/** A temporary password that satisfies the policy: letters and digits, no look-alikes. */
function temp_password(): string
{
    $letters = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnpqrstuvwxyz';
    $digits  = '23456789';
    $all     = $letters . $digits;

    $chars = [$letters[random_int(0, strlen($letters) - 1)], $digits[random_int(0, strlen($digits) - 1)]];
    while (count($chars) < 12) {
        $chars[] = $all[random_int(0, strlen($all) - 1)];
    }
    // Fisher-Yates with a CSPRNG, so the guaranteed letter and digit are not always first.
    for ($i = count($chars) - 1; $i > 0; $i--) {
        $j = random_int(0, $i);
        [$chars[$i], $chars[$j]] = [$chars[$j], $chars[$i]];
    }
    return implode('', $chars);
}

/** Which of the optional columns (migrations 006 and 007) this database has. */
function user_columns(PDO $pdo): array
{
    static $cols = null;
    if ($cols === null) {
        $found = $pdo->query(
            "SELECT COLUMN_NAME FROM information_schema.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users'
                AND COLUMN_NAME IN ('must_change_password', 'email')"
        )->fetchAll(PDO::FETCH_COLUMN);
        $cols = [
            'must_change_password' => in_array('must_change_password', $found, true),
            'email'                => in_array('email', $found, true),
        ];
    }
    return $cols;
}

function fail(string $message, int $code = 422): never
{
    json_response(['success' => false, 'message' => $message], $code);
}

/** Stops with a pointer to the missing migrations, or because e-mail is not set up. */
function require_provisioning(PDO $pdo): void
{
    $cols = user_columns($pdo);
    if (!$cols['must_change_password'] || !$cols['email']) {
        fail('Run database/migrations/006_must_change_password.sql and 007_user_email.sql first: '
           . 'they add the columns this page needs.', 500);
    }
    if (!mail_configured()) {
        fail('E-mail is not configured on this server, so a temporary password cannot be sent. '
           . 'Set the DISINFENTRY_MAIL_* settings (see config/mail.local.example.php).', 503);
    }
}

/** Active administrators other than $exceptId. */
function other_active_admins(PDO $pdo, int $exceptId): int
{
    $stmt = $pdo->prepare(
        'SELECT COUNT(*) FROM users WHERE role = "administrator" AND status = "active" AND id <> ?'
    );
    $stmt->execute([$exceptId]);
    return (int) $stmt->fetchColumn();
}

/* ============================================================
 * List
 * ========================================================== */
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $cols = user_columns($pdo);
    $flagSql  = $cols['must_change_password'] ? 'must_change_password' : '0 AS must_change_password';
    $emailSql = $cols['email'] ? 'email' : 'NULL AS email';

    $rows = $pdo->query(
        "SELECT id, employee_id, full_name, contact_number, $emailSql, username, role, status,
                last_login, created_at, $flagSql
           FROM users
          ORDER BY (status = 'active') DESC, role = 'administrator' DESC, full_name"
    )->fetchAll();

    json_response([
        'success'         => true,
        'can_provision'   => $cols['must_change_password'] && $cols['email'],
        'mail_configured' => mail_configured(),
        'users'           => array_map(static fn(array $r): array => [
            'id'             => (int) $r['id'],
            'employee_id'    => $r['employee_id'],
            'full_name'      => $r['full_name'],
            'contact_number' => $r['contact_number'],
            'email'          => $r['email'],
            'username'       => $r['username'],
            'role'           => $r['role'],
            'role_label'     => role_label($r['role']),
            'status'         => $r['status'],
            'last_login'     => $r['last_login'] ? fmt_datetime($r['last_login']) : null,
            'must_change'    => (bool) $r['must_change_password'],
            'is_self'        => (int) $r['id'] === $me,
        ], $rows),
    ]);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    fail('Method not allowed.', 405);
}

require_csrf();
$in     = json_input();
$action = (string) ($in['action'] ?? '');

$str = static fn(string $k, int $max): string => mb_substr(trim((string) ($in[$k] ?? '')), 0, $max);

/** Loads the target account, or stops with a 404. */
$target = static function () use ($pdo, $in): array {
    $stmt = $pdo->prepare('SELECT * FROM users WHERE id = ? LIMIT 1');
    $stmt->execute([(int) ($in['id'] ?? 0)]);
    return $stmt->fetch() ?: fail('User not found.', 404);
};

$validContact = static fn(string $c): bool => $c === '' || (bool) preg_match('/^[0-9+()\\-\\s]{3,30}$/', $c);

/** A usable address, normalised; stops with a message when it is not. */
$validEmail = static function (string $e): string {
    $e = strtolower(trim($e));
    if ($e === '' || mb_strlen($e) > 190 || !filter_var($e, FILTER_VALIDATE_EMAIL)) {
        fail('Enter a valid e-mail address.');
    }
    return $e;
};

/** Stops when another account already uses this address. */
$emailFree = static function (string $email, int $exceptId) use ($pdo): void {
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM users WHERE email = ? AND id <> ?');
    $stmt->execute([$email, $exceptId]);
    if ((int) $stmt->fetchColumn() > 0) {
        fail('That e-mail address is already registered to another account.');
    }
};

/* ============================================================
 * Create
 * ========================================================== */
if ($action === 'create') {
    require_provisioning($pdo);

    $fullName = $str('full_name', 120);
    $username = $str('username', 60);
    $email    = $validEmail((string) ($in['email'] ?? ''));
    $role     = (string) ($in['role'] ?? 'farm_manager');
    $contact  = $str('contact_number', 30);
    $empId    = $str('employee_id', 30);

    if ($fullName === '') {
        fail('Full name is required.');
    }
    if (!preg_match('/^[A-Za-z0-9._-]{3,60}$/', $username)) {
        fail('Username must be 3-60 characters: letters, digits, dot, dash or underscore.');
    }
    if (!in_array($role, ['administrator', 'farm_manager'], true)) {
        fail('Choose a valid role.');
    }
    if (!$validContact($contact)) {
        fail('Contact number may only contain digits, spaces, + ( ) and -.');
    }

    $exists = $pdo->prepare('SELECT COUNT(*) FROM users WHERE username = ?');
    $exists->execute([$username]);
    if ((int) $exists->fetchColumn() > 0) {
        fail('That username is already taken.');
    }
    $emailFree($email, 0);

    if ($empId === '') {
        $next = (int) $pdo->query(
            "SELECT COALESCE(MAX(CAST(SUBSTRING(employee_id, 5) AS UNSIGNED)), 0) FROM users
              WHERE employee_id REGEXP '^EMP-[0-9]+$'"
        )->fetchColumn();
        $empId = sprintf('EMP-%04d', $next + 1);
    } else {
        $dup = $pdo->prepare('SELECT COUNT(*) FROM users WHERE employee_id = ?');
        $dup->execute([$empId]);
        if ((int) $dup->fetchColumn() > 0) {
            fail('That employee ID is already in use.');
        }
    }

    $temp = temp_password();

    // Create and e-mail as one step: if the message cannot be sent the account is not kept.
    $pdo->beginTransaction();
    $pdo->prepare(
        'INSERT INTO users (employee_id, full_name, contact_number, email, username, password_hash, role, status, must_change_password)
         VALUES (?, ?, ?, ?, ?, ?, ?, "active", 1)'
    )->execute([$empId, $fullName, $contact !== '' ? $contact : null, $email, $username, hash_password($temp), $role]);

    $sendError = send_temp_password(
        ['full_name' => $fullName, 'username' => $username, 'email' => $email], $temp, 'created'
    );
    if ($sendError !== null) {
        $pdo->rollBack();
        fail('The account was not created: ' . $sendError);
    }
    $pdo->commit();

    audit(sprintf('Created user "%s" (%s, %s) and e-mailed a temporary password to %s',
        $username, $empId, role_label($role), mail_mask($email)), $MODULE);

    json_response([
        'success' => true,
        'message' => 'User created. A temporary password was e-mailed to ' . mail_mask($email) . '.',
    ]);
}

/* ============================================================
 * Update (name, e-mail, contact, role)
 * ========================================================== */
if ($action === 'update') {
    if (!user_columns($pdo)['email']) {
        fail('Run database/migrations/007_user_email.sql first: it adds the e-mail column.', 500);
    }

    $u        = $target();
    $fullName = $str('full_name', 120);
    $email    = $validEmail((string) ($in['email'] ?? ''));
    $role     = (string) ($in['role'] ?? $u['role']);
    $contact  = $str('contact_number', 30);

    if ($fullName === '') {
        fail('Full name is required.');
    }
    if (!in_array($role, ['administrator', 'farm_manager'], true)) {
        fail('Choose a valid role.');
    }
    if (!$validContact($contact)) {
        fail('Contact number may only contain digits, spaces, + ( ) and -.');
    }
    $emailFree($email, (int) $u['id']);

    if ($role !== $u['role'] && $u['role'] === 'administrator') {
        if ((int) $u['id'] === $me) {
            fail('You cannot remove your own administrator role.');
        }
        if ($u['status'] === 'active' && other_active_admins($pdo, (int) $u['id']) === 0) {
            fail('This is the last active administrator and cannot be demoted.');
        }
    }

    $pdo->prepare('UPDATE users SET full_name = ?, contact_number = ?, email = ?, role = ? WHERE id = ?')
        ->execute([$fullName, $contact !== '' ? $contact : null, $email, $role, (int) $u['id']]);

    $changes = [];
    if ($role !== $u['role']) {
        $changes[] = sprintf('role %s -> %s', $u['role'], $role);
    }
    if (strtolower((string) ($u['email'] ?? '')) !== $email) {
        $changes[] = 'e-mail changed';
    }
    audit(sprintf('Updated user "%s"%s', $u['username'], $changes ? ' (' . implode(', ', $changes) . ')' : ''), $MODULE);

    json_response(['success' => true, 'message' => 'User updated.']);
}

/* ============================================================
 * Reset password
 * ========================================================== */
if ($action === 'reset_password') {
    $u = $target();

    if ((int) $u['id'] === $me) {
        fail('Change your own password under My Profile.');
    }
    require_provisioning($pdo);

    $email = trim((string) ($u['email'] ?? ''));
    if ($email === '') {
        fail('This user has no registered e-mail address. Edit the user and add one first.');
    }

    $temp = temp_password();

    // Change and e-mail as one step: if the message cannot be sent, the old password stays.
    $pdo->beginTransaction();
    $pdo->prepare('UPDATE users SET password_hash = ?, must_change_password = 1 WHERE id = ?')
        ->execute([hash_password($temp), (int) $u['id']]);

    $sendError = send_temp_password($u, $temp, 'reset');
    if ($sendError !== null) {
        $pdo->rollBack();
        fail('The password was not changed: ' . $sendError);
    }
    $pdo->commit();

    // Ends any session the old password still had open (a no-op until migration 005).
    mark_password_changed((int) $u['id']);

    audit(sprintf('Reset the password of "%s" and e-mailed a temporary password to %s',
        $u['username'], mail_mask($email)), $MODULE);

    json_response([
        'success' => true,
        'message' => 'Password reset. A temporary password was e-mailed to ' . mail_mask($email) . '.',
    ]);
}

/* ============================================================
 * Activate / deactivate
 * ========================================================== */
if ($action === 'set_status') {
    $u      = $target();
    $status = (string) ($in['status'] ?? '');

    if (!in_array($status, ['active', 'inactive'], true)) {
        fail('Invalid status.');
    }
    if ($status === 'inactive') {
        if ((int) $u['id'] === $me) {
            fail('You cannot deactivate your own account.');
        }
        if ($u['role'] === 'administrator' && $u['status'] === 'active'
            && other_active_admins($pdo, (int) $u['id']) === 0) {
            fail('This is the last active administrator and cannot be deactivated.');
        }
    }

    $pdo->prepare('UPDATE users SET status = ? WHERE id = ?')->execute([$status, (int) $u['id']]);

    audit(sprintf('%s user "%s"', $status === 'active' ? 'Activated' : 'Deactivated', $u['username']), $MODULE);

    json_response(['success' => true, 'message' => $status === 'active' ? 'User activated.' : 'User deactivated.']);
}

fail('Unknown action.', 400);
