<?php
/**
 * User management (administrator only).
 *
 *   GET                       - every account
 *   POST {action:"create",         full_name, username, role, contact_number?, employee_id?}
 *   POST {action:"update",         id, full_name, role, contact_number?}
 *   POST {action:"reset_password", id}
 *   POST {action:"set_status",     id, status: active|inactive}
 *
 * create and reset_password answer with a one-time temporary password. It is shown to the
 * administrator once, never stored in the clear, and the account is held on My Profile
 * until its owner replaces it (users.must_change_password, migration 006).
 *
 * Two rules protect the system from locking itself out: an administrator cannot deactivate
 * or demote their own account, and the last active administrator can never be deactivated
 * or demoted by anyone.
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';

api_require_admin();

$pdo  = db();
$me   = (int) $_SESSION['user']['id'];
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

function column_exists(PDO $pdo): bool
{
    try {
        $pdo->query('SELECT must_change_password FROM users LIMIT 0');
        return true;
    } catch (PDOException $e) {
        return false;
    }
}

function fail(string $message, int $code = 422): never
{
    json_response(['success' => false, 'message' => $message], $code);
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
    $hasFlag = column_exists($pdo);
    $flagSql = $hasFlag ? 'must_change_password' : '0 AS must_change_password';

    $rows = $pdo->query(
        "SELECT id, employee_id, full_name, contact_number, username, role, status,
                last_login, created_at, $flagSql
           FROM users
          ORDER BY (status = 'active') DESC, role = 'administrator' DESC, full_name"
    )->fetchAll();

    json_response([
        'success'      => true,
        'can_provision' => $hasFlag,
        'users'        => array_map(static fn(array $r): array => [
            'id'             => (int) $r['id'],
            'employee_id'    => $r['employee_id'],
            'full_name'      => $r['full_name'],
            'contact_number' => $r['contact_number'],
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

/* ============================================================
 * Create
 * ========================================================== */
if ($action === 'create') {
    if (!column_exists($pdo)) {
        fail('Run database/migrations/006_must_change_password.sql first: it adds the column that '
           . 'forces a new user to choose their own password.', 500);
    }

    $fullName = $str('full_name', 120);
    $username = $str('username', 60);
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
    $pdo->prepare(
        'INSERT INTO users (employee_id, full_name, contact_number, username, password_hash, role, status, must_change_password)
         VALUES (?, ?, ?, ?, ?, ?, "active", 1)'
    )->execute([$empId, $fullName, $contact !== '' ? $contact : null, $username, hash_password($temp), $role]);

    audit(sprintf('Created user "%s" (%s, %s)', $username, $empId, role_label($role)), $MODULE);

    json_response([
        'success'       => true,
        'message'       => 'User created.',
        'username'      => $username,
        'temp_password' => $temp,
    ]);
}

/* ============================================================
 * Update (name, contact, role)
 * ========================================================== */
if ($action === 'update') {
    $u        = $target();
    $fullName = $str('full_name', 120);
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

    if ($role !== $u['role'] && $u['role'] === 'administrator') {
        if ((int) $u['id'] === $me) {
            fail('You cannot remove your own administrator role.');
        }
        if ($u['status'] === 'active' && other_active_admins($pdo, (int) $u['id']) === 0) {
            fail('This is the last active administrator and cannot be demoted.');
        }
    }

    $pdo->prepare('UPDATE users SET full_name = ?, contact_number = ?, role = ? WHERE id = ?')
        ->execute([$fullName, $contact !== '' ? $contact : null, $role, (int) $u['id']]);

    audit(sprintf('Updated user "%s"%s', $u['username'],
        $role !== $u['role'] ? sprintf(' (role %s -> %s)', $u['role'], $role) : ''), $MODULE);

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
    if (!column_exists($pdo)) {
        fail('Run database/migrations/006_must_change_password.sql first: it adds the column that '
           . 'forces the user to choose their own password.', 500);
    }

    $temp = temp_password();
    $pdo->prepare('UPDATE users SET password_hash = ?, must_change_password = 1 WHERE id = ?')
        ->execute([hash_password($temp), (int) $u['id']]);
    // Ends any session the old password still had open (a no-op until migration 005).
    mark_password_changed((int) $u['id']);

    audit(sprintf('Reset the password of "%s"', $u['username']), $MODULE);

    json_response([
        'success'       => true,
        'message'       => 'Password reset.',
        'username'      => $u['username'],
        'temp_password' => $temp,
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
