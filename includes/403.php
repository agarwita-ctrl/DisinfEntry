<?php
declare(strict_types=1);
$PAGE_TITLE = 'Access Denied';
require __DIR__ . '/header.php';
?>
<div class="text-center py-5">
  <div class="display-1 text-danger"><i class="bi bi-shield-lock"></i></div>
  <h2 class="mt-3">403 — Access Denied</h2>
  <p class="text-muted">This module is restricted to administrators.</p>
  <a href="<?= url('index.php') ?>" class="btn btn-primary mt-2">
    <i class="bi bi-arrow-left me-1"></i>Back to Dashboard
  </a>
</div>
<?php require __DIR__ . '/footer.php'; ?>
