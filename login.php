<?php
/**
 * Sign-in page.
 */

declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';

if (is_logged_in()) {
    header('Location: ' . url('index.php'));
    exit;
}

$error  = '';
$notice = isset($_GET['msg']) ? (string) $_GET['msg'] : '';
$flash  = $_SESSION['flash'] ?? '';
unset($_SESSION['flash']);
$username = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim((string) ($_POST['username'] ?? ''));
    $password = (string) ($_POST['password'] ?? '');

    if (!csrf_check($_POST['csrf_token'] ?? null)) {
        $error = 'Security token expired. Please try again.';
    } elseif (login_attempts_exceeded($username)) {
        $error = 'Too many failed attempts. Try again in '
               . max(1, (int) ceil(lockout_remaining($username) / 60)) . ' minute(s).';
    } elseif ($username === '' || $password === '') {
        $error = 'Please enter both your username and password.';
    } else {
        $stmt = db()->prepare('SELECT * FROM users WHERE username = ? LIMIT 1');
        $stmt->execute([$username]);
        $user = $stmt->fetch();

        if (!$user || !password_verify($password, $user['password_hash'])) {
            $error = 'Invalid username or password.';
            // This row is also what the throttle counts - see login_buckets().
            audit('Failed login attempt for "' . $username . '"', 'Authentication', null, $username);
        } elseif ($user['status'] !== 'active') {
            $error = 'This account is deactivated. Please contact the administrator.';
            audit('Login blocked (inactive account)', 'Authentication', (int) $user['id'], $user['username']);
        } else {
            // Transparently upgrade legacy hashes.
            if (password_needs_rehash($user['password_hash'], PASSWORD_BCRYPT, ['cost' => 12])) {
                db()->prepare('UPDATE users SET password_hash = ? WHERE id = ?')
                    ->execute([hash_password($password), (int) $user['id']]);
            }
            login_user($user);
            $_SESSION['must_change_password'] = in_array($password, DEFAULT_PASSWORDS, true);
            audit('User logged in', 'Authentication');
            header('Location: ' . url('index.php'));
            exit;
        }
    }
}

$sysName  = setting('system_name', 'DisinfEntry');
$farmName = setting('farm_name', 'Poultry Farm');
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Sign In · <?= e($sysName) ?></title>
<link rel="icon" href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 100 100'><text y='.9em' font-size='90'>&#128737;</text></svg>">
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
<link href="<?= url('assets/css/style.css') ?>" rel="stylesheet">
</head>
<body>
<div class="auth-wrap">

  <aside class="auth-aside">
    <div style="max-width:520px">
      <div class="d-flex align-items-center gap-3 mb-4">
        <span class="auth-logo mb-0"><i class="bi bi-shield-check"></i></span>
        <div>
          <h2 class="mb-0"><?= e($sysName) ?></h2>
          <span class="text-white-50">Smart Disinfection &amp; Entry Monitoring</span>
        </div>
      </div>
      <p class="lead text-white-50">
        IoT biosecurity monitoring for <?= e($farmName) ?>. Every person entering the farm is
        screened, disinfected and logged automatically.
      </p>
      <div class="auth-feature">
        <i class="bi bi-thermometer-half"></i>
        <div><strong>Contactless screening</strong><br>
          <span class="text-white-50 small">MLX90614 infrared temperature readings with automatic access control.</span></div>
      </div>
      <div class="auth-feature">
        <i class="bi bi-droplet"></i>
        <div><strong>Automatic misting</strong><br>
          <span class="text-white-50 small">PIR and ultrasonic triggered disinfection on every entry.</span></div>
      </div>
      <div class="auth-feature">
        <i class="bi bi-graph-up-arrow"></i>
        <div><strong>Real-time analytics</strong><br>
          <span class="text-white-50 small">Live dashboards, alerts and exportable compliance reports.</span></div>
      </div>
    </div>
  </aside>

  <section class="auth-panel">
    <div class="auth-card">
      <div class="d-lg-none text-center mb-3">
        <span class="auth-logo mx-auto"><i class="bi bi-shield-check"></i></span>
      </div>

      <h3 class="fw-bold mb-1">Welcome back</h3>
      <p class="text-muted mb-4">Sign in to the <?= e($sysName) ?> monitoring console.</p>

      <?php if ($error): ?>
        <div class="alert alert-danger d-flex align-items-center py-2">
          <i class="bi bi-exclamation-octagon-fill me-2"></i><div><?= e($error) ?></div>
        </div>
      <?php endif; ?>
      <?php if ($flash): ?>
        <div class="alert alert-success d-flex align-items-center py-2">
          <i class="bi bi-check-circle-fill me-2"></i><div><?= e($flash) ?></div>
        </div>
      <?php endif; ?>
      <?php if ($notice && !$error): ?>
        <div class="alert alert-warning d-flex align-items-center py-2">
          <i class="bi bi-info-circle-fill me-2"></i><div><?= e($notice) ?></div>
        </div>
      <?php endif; ?>

      <form method="post" autocomplete="on" novalidate>
        <?= csrf_field() ?>
        <div class="mb-3">
          <label class="form-label" for="username">Username</label>
          <div class="input-group">
            <span class="input-group-text bg-white"><i class="bi bi-person"></i></span>
            <input type="text" class="form-control border-start-0" id="username" name="username"
                   value="<?= e($username) ?>" placeholder="Enter your username" required autofocus>
          </div>
        </div>

        <div class="mb-2">
          <label class="form-label" for="password">Password</label>
          <div class="input-group">
            <span class="input-group-text bg-white"><i class="bi bi-lock"></i></span>
            <input type="password" class="form-control border-start-0 border-end-0" id="password"
                   name="password" placeholder="Enter your password" required>
            <span class="input-group-text bg-white pw-toggle" data-target="password">
              <i class="bi bi-eye"></i>
            </span>
          </div>
        </div>

        <div class="d-flex justify-content-between align-items-center mb-4">
          <div class="form-check">
            <input class="form-check-input" type="checkbox" id="showPw">
            <label class="form-check-label small text-muted" for="showPw">Show password</label>
          </div>
          <a href="<?= url('forgot_password.php') ?>" class="small">Forgot password?</a>
        </div>

        <button class="btn btn-primary w-100 py-2 fw-semibold" type="submit">
          <i class="bi bi-box-arrow-in-right me-1"></i>Sign In
        </button>
      </form>

      <div class="text-center mt-4">
        <span class="text-muted small">&copy; <?= date('Y') ?> <?= e($sysName) ?> · <?= e($farmName) ?></span>
      </div>
    </div>
  </section>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
document.querySelectorAll('.pw-toggle').forEach((el) => {
  el.addEventListener('click', () => {
    const input = document.getElementById(el.dataset.target);
    const show = input.type === 'password';
    input.type = show ? 'text' : 'password';
    el.querySelector('i').className = show ? 'bi bi-eye-slash' : 'bi bi-eye';
  });
});
document.getElementById('showPw')?.addEventListener('change', (ev) => {
  document.getElementById('password').type = ev.target.checked ? 'text' : 'password';
});
</script>
</body>
</html>
