<?php
/**
 * Consumes a password reset token and sets a new password.
 */

declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';

$token = (string) ($_GET['token'] ?? $_POST['token'] ?? '');
$error = '';
$valid = false;
$row   = null;

if ($token !== '') {
    $stmt = db()->prepare(
        'SELECT pr.*, u.username, u.full_name
           FROM password_resets pr
           JOIN users u ON u.id = pr.user_id
          WHERE pr.token_hash = ? AND pr.used_at IS NULL AND pr.expires_at > NOW()
          LIMIT 1'
    );
    $stmt->execute([hash('sha256', $token)]);
    $row   = $stmt->fetch();
    $valid = (bool) $row;
}

if (!$valid) {
    $error = 'This reset link is invalid, already used, or has expired.';
}

if ($valid && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $pw  = (string) ($_POST['password'] ?? '');
    $pw2 = (string) ($_POST['password_confirm'] ?? '');

    if (!csrf_check($_POST['csrf_token'] ?? null)) {
        $error = 'Security token expired. Please try again.';
    } elseif ($pw !== $pw2) {
        $error = 'The two passwords do not match.';
    } elseif ($msg = password_policy_error($pw)) {
        $error = $msg;
    } else {
        $pdo = db();
        $pdo->beginTransaction();
        $pdo->prepare('UPDATE users SET password_hash = ? WHERE id = ?')
            ->execute([hash_password($pw), (int) $row['user_id']]);
        $pdo->prepare('UPDATE password_resets SET used_at = NOW() WHERE id = ?')
            ->execute([(int) $row['id']]);
        $pdo->commit();

        audit('Password reset completed', 'Authentication', (int) $row['user_id'], $row['username']);
        $_SESSION['flash'] = 'Your password has been reset. You can now sign in.';
        header('Location: ' . url('login.php'));
        exit;
    }
}

$sysName = setting('system_name', 'DisinfEntry');
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Reset Password · <?= e($sysName) ?></title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
<link href="<?= url('assets/css/style.css') ?>" rel="stylesheet">
</head>
<body>
<div class="auth-panel" style="min-height:100vh">
  <div class="auth-card">
    <div class="text-center mb-3"><span class="auth-logo mx-auto"><i class="bi bi-shield-lock"></i></span></div>
    <h3 class="fw-bold mb-1">Set a new password</h3>
    <?php if ($valid): ?>
      <p class="text-muted mb-4">Resetting the password for <strong><?= e($row['full_name']) ?></strong>.</p>
    <?php endif; ?>

    <?php if ($error): ?>
      <div class="alert alert-danger py-2"><i class="bi bi-exclamation-octagon-fill me-2"></i><?= e($error) ?></div>
    <?php endif; ?>

    <?php if ($valid): ?>
      <form method="post" novalidate id="resetForm">
        <?= csrf_field() ?>
        <input type="hidden" name="token" value="<?= e($token) ?>">
        <div class="mb-3">
          <label class="form-label" for="password">New Password</label>
          <input type="password" class="form-control" id="password" name="password" required
                 placeholder="At least 8 characters, with a number">
          <div class="pw-meter"><span id="pwBar"></span></div>
          <div class="form-text" id="pwHint">Use letters and numbers, minimum 8 characters.</div>
        </div>
        <div class="mb-4">
          <label class="form-label" for="password_confirm">Confirm New Password</label>
          <input type="password" class="form-control" id="password_confirm" name="password_confirm" required>
        </div>
        <button class="btn btn-primary w-100 py-2 fw-semibold" type="submit">
          <i class="bi bi-check2-circle me-1"></i>Reset Password
        </button>
      </form>
    <?php else: ?>
      <a href="<?= url('forgot_password.php') ?>" class="btn btn-primary w-100">Request a new link</a>
    <?php endif; ?>

    <div class="text-center mt-3">
      <a href="<?= url('login.php') ?>" class="small"><i class="bi bi-arrow-left me-1"></i>Back to sign in</a>
    </div>
  </div>
</div>
<script>
const pw = document.getElementById('password');
pw?.addEventListener('input', () => {
  const v = pw.value;
  let score = 0;
  if (v.length >= 8) score++;
  if (/[A-Z]/.test(v)) score++;
  if (/\d/.test(v)) score++;
  if (/[^A-Za-z0-9]/.test(v)) score++;
  const bar = document.getElementById('pwBar');
  const colors = ['#dc3545', '#f59e0b', '#eab308', '#16a34a'];
  bar.style.width = (score * 25) + '%';
  bar.style.background = colors[Math.max(0, score - 1)];
});
</script>
</body>
</html>
