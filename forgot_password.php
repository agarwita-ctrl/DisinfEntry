<?php
/**
 * Forgot password.
 *
 * This page NEVER shows a reset link, and never has one to show unless mail is
 * configured. It used to print the token straight into its own response
 * whenever MAIL_ENABLED was false - which is the default - so anyone who could
 * load the page could mint a working reset link for any account by typing its
 * username, and take over the administrator without credentials.
 *
 * The two delivery routes are now:
 *
 *   MAIL_ENABLED = true   a single-use token is issued and e-mailed. Nothing is
 *                         rendered; the token exists only in the message.
 *   MAIL_ENABLED = false  no token is issued at all. The request is recorded and
 *                         raised to the administrators, who reset the password
 *                         directly in the database (the User Management page
 *                         has been removed).
 *
 * The response is identical whether or not the account exists, so the page
 * cannot be used to enumerate usernames - which the old code intended but
 * defeated by showing the link only for accounts that did exist.
 */

declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';

const MAIL_ENABLED   = false;
const RESET_TTL_MINS = 30;

/** Requests allowed from one IP before the page stops acting on them. */
const RESET_MAX_PER_WINDOW = 5;
const RESET_WINDOW_MINUTES = 15;

$error = '';
$done  = false;
$identifier = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $identifier = trim((string) ($_POST['identifier'] ?? ''));

    if (!csrf_check($_POST['csrf_token'] ?? null)) {
        $error = 'Security token expired. Please try again.';
    } elseif ($identifier === '') {
        $error = 'Enter your username or employee ID.';
    } elseif (reset_requests_exceeded()) {
        // Throttled per IP: unbounded requests let anyone flood the operators
        // with alerts and keep invalidating a real user's outstanding token.
        $error = 'Too many reset requests from this connection. Please try again later.';
    } else {
        $stmt = db()->prepare(
            'SELECT * FROM users WHERE (username = ? OR employee_id = ?) AND status = "active" LIMIT 1'
        );
        $stmt->execute([$identifier, $identifier]);
        $user = $stmt->fetch();

        if ($user) {
            if (MAIL_ENABLED) {
                $token   = bin2hex(random_bytes(32));
                $expires = date('Y-m-d H:i:s', time() + RESET_TTL_MINS * 60);

                // Invalidate any outstanding tokens for this account.
                db()->prepare('UPDATE password_resets SET used_at = NOW() WHERE user_id = ? AND used_at IS NULL')
                    ->execute([(int) $user['id']]);

                db()->prepare('INSERT INTO password_resets (user_id, token_hash, expires_at) VALUES (?, ?, ?)')
                    ->execute([(int) $user['id'], hash('sha256', $token), $expires]);

                $scheme = (($_SERVER['HTTPS'] ?? '') === 'on') ? 'https' : 'http';
                $link   = $scheme . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost')
                        . url('reset_password.php?token=' . $token);

                @mail(
                    $user['username'] . '@localhost',
                    setting('system_name', 'DisinfEntry') . ' — Password Reset',
                    "Use this link within " . RESET_TTL_MINS . " minutes to reset your password:\n\n$link\n",
                    'From: no-reply@disinfentry.local'
                );
                unset($link, $token);
            } else {
                // No transport, so no token: one that cannot be delivered can only
                // be leaked. The administrators get the request instead.
                notify(
                    'system',
                    'Password Reset Requested',
                    sprintf(
                        'User "%s" (%s) asked for a password reset. Reset it directly in the database.',
                        $user['username'],
                        $user['full_name']
                    ),
                    'info'
                );
            }

            audit('Password reset requested', 'Authentication', (int) $user['id'], $user['username']);
        } else {
            // Recorded without a user id so the throttle above still counts it,
            // and so repeated probing of unknown names is visible in the trail.
            audit('Password reset requested for an unknown account', 'Authentication', null, $identifier);
        }

        // Identical response either way.
        $done = true;
    }
}

/** Reset requests from this IP inside the window. */
function reset_requests_exceeded(): bool
{
    $stmt = db()->prepare(
        'SELECT COUNT(*) FROM audit_logs
          WHERE module = ?
            AND activity LIKE ?
            AND ip_address = ?
            AND created_at > ?'
    );
    $stmt->execute([
        'Authentication',
        'Password reset requested%',
        client_ip(),
        date('Y-m-d H:i:s', time() - RESET_WINDOW_MINUTES * 60),
    ]);

    return (int) $stmt->fetchColumn() >= RESET_MAX_PER_WINDOW;
}

$sysName = setting('system_name', 'DisinfEntry');
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Forgot Password · <?= e($sysName) ?></title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
<link href="<?= url('assets/css/style.css') ?>" rel="stylesheet">
</head>
<body>
<div class="auth-panel" style="min-height:100vh">
  <div class="auth-card">
    <div class="text-center mb-3">
      <span class="auth-logo mx-auto"><i class="bi bi-key"></i></span>
    </div>
    <h3 class="fw-bold mb-1">Forgot your password?</h3>
    <p class="text-muted mb-4">Enter your username or employee ID and the administrator will be notified.</p>

    <?php if ($error): ?>
      <div class="alert alert-danger py-2"><i class="bi bi-exclamation-octagon-fill me-2"></i><?= e($error) ?></div>
    <?php endif; ?>

    <?php if ($done): ?>
      <div class="alert alert-success">
        <i class="bi bi-check-circle-fill me-2"></i>
        <?php if (MAIL_ENABLED): ?>
          If that account exists, a reset link has been sent to it. It expires in
          <?= RESET_TTL_MINS ?> minutes.
        <?php else: ?>
          Your request has been passed to the system administrator. They will issue you a
          new password from the console.
        <?php endif; ?>
      </div>
      <a href="<?= url('login.php') ?>" class="btn btn-primary w-100">Back to sign in</a>
    <?php else: ?>
      <form method="post" novalidate>
        <?= csrf_field() ?>
        <div class="mb-3">
          <label class="form-label" for="identifier">Username or Employee ID</label>
          <div class="input-group">
            <span class="input-group-text bg-white"><i class="bi bi-person-badge"></i></span>
            <input type="text" class="form-control border-start-0" id="identifier" name="identifier"
                   value="<?= e($identifier) ?>" placeholder="e.g. admin or EMP-0001" required autofocus>
          </div>
        </div>
        <button class="btn btn-primary w-100 py-2 fw-semibold" type="submit">
          <i class="bi bi-send me-1"></i>Notify the Administrator
        </button>
      </form>
      <div class="text-center mt-3">
        <a href="<?= url('login.php') ?>" class="small"><i class="bi bi-arrow-left me-1"></i>Back to sign in</a>
      </div>
    <?php endif; ?>
  </div>
</div>
</body>
</html>
