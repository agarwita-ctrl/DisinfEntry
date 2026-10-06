<?php
/**
 * My Profile — view own details, update contact info, change password.
 */

declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
require_login();

$me      = current_user();
$success = '';
$error   = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string) ($_POST['action'] ?? '');

    if (!csrf_check($_POST['csrf_token'] ?? null)) {
        $error = 'Security token expired. Please reload the page.';
    } elseif ($action === 'update_profile') {
        $fullName = trim((string) ($_POST['full_name'] ?? ''));
        $contact  = trim((string) ($_POST['contact_number'] ?? ''));

        if ($fullName === '') {
            $error = 'Full name is required.';
        } else {
            db()->prepare('UPDATE users SET full_name = ?, contact_number = ? WHERE id = ?')
                ->execute([$fullName, $contact ?: null, (int) $me['id']]);
            $_SESSION['user']['full_name'] = $fullName;
            $me = current_user();
            audit('Updated own profile', 'Profile');
            $success = 'Your profile has been updated.';
        }
    } elseif ($action === 'change_password') {
        $current = (string) ($_POST['current_password'] ?? '');
        $new     = (string) ($_POST['new_password'] ?? '');
        $confirm = (string) ($_POST['confirm_password'] ?? '');

        $stmt = db()->prepare('SELECT password_hash FROM users WHERE id = ?');
        $stmt->execute([(int) $me['id']]);
        $hash = (string) $stmt->fetchColumn();

        if (!password_verify($current, $hash)) {
            $error = 'Your current password is incorrect.';
        } elseif ($new !== $confirm) {
            $error = 'The new passwords do not match.';
        } elseif ($msg = password_policy_error($new)) {
            $error = $msg;
        } elseif (password_verify($new, $hash)) {
            $error = 'The new password must be different from the current one.';
        } else {
            db()->prepare('UPDATE users SET password_hash = ? WHERE id = ?')
                ->execute([hash_password($new), (int) $me['id']]);
            audit('Changed own password', 'Profile');
            unset($_SESSION['must_change_password']);
            $success = 'Your password has been changed.';
        }
    }
}

$stmt = db()->prepare('SELECT * FROM users WHERE id = ?');
$stmt->execute([(int) $me['id']]);
$user = $stmt->fetch();

$entryCount = 0;
if ($user['employee_id']) {
    $c = db()->prepare('SELECT COUNT(*) FROM entries WHERE employee_id = ?');
    $c->execute([$user['employee_id']]);
    $entryCount = (int) $c->fetchColumn();
}

$PAGE_TITLE = 'My Profile';
$ACTIVE     = '';
require __DIR__ . '/includes/header.php';
?>

<?php if ($success): ?>
  <div class="alert alert-success alert-dismissible fade show">
    <i class="bi bi-check-circle-fill me-2"></i><?= e($success) ?>
    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
  </div>
<?php endif; ?>
<?php if ($error): ?>
  <div class="alert alert-danger alert-dismissible fade show">
    <i class="bi bi-exclamation-octagon-fill me-2"></i><?= e($error) ?>
    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
  </div>
<?php endif; ?>

<div class="row g-3">

  <div class="col-lg-4">
    <div class="card h-100">
      <div class="card-body text-center">
        <div class="avatar mx-auto mb-3" style="width:82px;height:82px;flex:0 0 82px;font-size:2rem">
          <?= e(strtoupper(substr((string) $user['full_name'], 0, 1))) ?>
        </div>
        <h5 class="mb-0"><?= e($user['full_name']) ?></h5>
        <div class="text-muted small mb-2">@<?= e($user['username']) ?></div>
        <span class="badge-soft <?= $user['role'] === 'administrator' ? 'admin' : 'manager' ?>">
          <?= e(role_label($user['role'])) ?>
        </span>
        <span class="badge-soft <?= e($user['status']) ?>"><?= ucfirst(e($user['status'])) ?></span>

        <hr>
        <dl class="row text-start small mb-0">
          <dt class="col-6 text-muted fw-normal">Employee ID</dt>
          <dd class="col-6 text-end fw-semibold"><?= e($user['employee_id']) ?></dd>
          <dt class="col-6 text-muted fw-normal">Contact</dt>
          <dd class="col-6 text-end fw-semibold"><?= e($user['contact_number'] ?: '—') ?></dd>
          <dt class="col-6 text-muted fw-normal">Member since</dt>
          <dd class="col-6 text-end fw-semibold"><?= fmt_date($user['created_at']) ?></dd>
          <dt class="col-6 text-muted fw-normal">Last login</dt>
          <dd class="col-6 text-end fw-semibold"><?= fmt_datetime($user['last_login']) ?></dd>
          <dt class="col-6 text-muted fw-normal">Booth entries</dt>
          <dd class="col-6 text-end fw-semibold"><?= number_format($entryCount) ?></dd>
        </dl>
      </div>
    </div>
  </div>

  <div class="col-lg-8">
    <div class="card mb-3">
      <div class="card-header"><i class="bi bi-person-lines-fill me-2"></i>Account Details</div>
      <div class="card-body">
        <form method="post" novalidate>
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="update_profile">
          <div class="row g-3">
            <div class="col-md-6">
              <label class="form-label">Employee ID</label>
              <input type="text" class="form-control" value="<?= e($user['employee_id']) ?>" disabled>
            </div>
            <div class="col-md-6">
              <label class="form-label">Username</label>
              <input type="text" class="form-control" value="<?= e($user['username']) ?>" disabled>
            </div>
            <div class="col-md-6">
              <label class="form-label" for="full_name">Full Name</label>
              <input type="text" class="form-control" id="full_name" name="full_name"
                     value="<?= e($user['full_name']) ?>" required maxlength="120">
            </div>
            <div class="col-md-6">
              <label class="form-label" for="contact_number">Contact Number</label>
              <input type="text" class="form-control" id="contact_number" name="contact_number"
                     value="<?= e($user['contact_number']) ?>" maxlength="30" placeholder="09xxxxxxxxx">
            </div>
          </div>
          <div class="text-end mt-3">
            <button class="btn btn-primary"><i class="bi bi-save me-1"></i>Save Changes</button>
          </div>
        </form>
      </div>
    </div>

    <div class="card" id="password">
      <div class="card-header"><i class="bi bi-key-fill me-2"></i>Change Password</div>
      <div class="card-body">
        <form method="post" novalidate id="pwForm">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="change_password">
          <div class="row g-3">
            <div class="col-12">
              <label class="form-label" for="current_password">Current Password</label>
              <input type="password" class="form-control" id="current_password" name="current_password" required>
            </div>
            <div class="col-md-6">
              <label class="form-label" for="new_password">New Password</label>
              <input type="password" class="form-control" id="new_password" name="new_password" required>
              <div class="pw-meter"><span id="pwBar"></span></div>
              <div class="form-text">Minimum 8 characters, with letters and numbers.</div>
            </div>
            <div class="col-md-6">
              <label class="form-label" for="confirm_password">Confirm New Password</label>
              <input type="password" class="form-control" id="confirm_password" name="confirm_password" required>
              <div class="form-text" id="matchHint">&nbsp;</div>
            </div>
          </div>
          <div class="text-end mt-3">
            <button class="btn btn-primary"><i class="bi bi-shield-check me-1"></i>Update Password</button>
          </div>
        </form>
      </div>
    </div>
  </div>
</div>

<script>
const np = document.getElementById('new_password');
const cp = document.getElementById('confirm_password');

np?.addEventListener('input', () => {
  const v = np.value;
  let score = 0;
  if (v.length >= 8) score++;
  if (/[A-Z]/.test(v)) score++;
  if (/\d/.test(v)) score++;
  if (/[^A-Za-z0-9]/.test(v)) score++;
  const bar = document.getElementById('pwBar');
  bar.style.width = (score * 25) + '%';
  bar.style.background = ['#dc3545', '#f59e0b', '#eab308', '#16a34a'][Math.max(0, score - 1)];
});

cp?.addEventListener('input', () => {
  const hint = document.getElementById('matchHint');
  if (!cp.value) { hint.textContent = ' '; hint.className = 'form-text'; return; }
  const ok = cp.value === np.value;
  hint.textContent = ok ? 'Passwords match.' : 'Passwords do not match.';
  hint.className = 'form-text ' + (ok ? 'text-success' : 'text-danger');
});
</script>

<?php require __DIR__ . '/includes/footer.php'; ?>
