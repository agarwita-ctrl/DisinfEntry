<?php
/**
 * Audit Logs (administrator only).
 */

declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
require_admin();

$modules = db()->query('SELECT DISTINCT module FROM audit_logs ORDER BY module')->fetchAll(PDO::FETCH_COLUMN);

$PAGE_TITLE   = 'Audit Logs';
$ACTIVE       = 'audit';
$PAGE_SCRIPTS = ['assets/js/audit.js'];

require __DIR__ . '/includes/header.php';
?>

<div class="filter-bar mb-3 no-print">
  <form id="auditFilter" class="row g-2 align-items-end">
    <div class="col-6 col-md-3">
      <label class="form-label" for="fUser">User</label>
      <input type="text" class="form-control form-control-sm" id="fUser" name="user" placeholder="Username">
    </div>
    <div class="col-6 col-md-3">
      <label class="form-label" for="fModule">Module</label>
      <select class="form-select form-select-sm" id="fModule" name="module">
        <option value="">All modules</option>
        <?php foreach ($modules as $m): ?>
          <option value="<?= e($m) ?>"><?= e($m) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="col-6 col-md-2">
      <label class="form-label" for="fFrom">From</label>
      <input type="date" class="form-control form-control-sm" id="fFrom" name="date_from">
    </div>
    <div class="col-6 col-md-2">
      <label class="form-label" for="fTo">To</label>
      <input type="date" class="form-control form-control-sm" id="fTo" name="date_to">
    </div>
    <div class="col-12 col-md-2 d-flex gap-2">
      <button type="submit" class="btn btn-sm btn-primary flex-fill"><i class="bi bi-funnel"></i></button>
      <button type="button" class="btn btn-sm btn-outline-secondary flex-fill" id="btnReset">
        <i class="bi bi-arrow-counterclockwise"></i>
      </button>
      <button type="button" class="btn btn-sm btn-outline-success flex-fill" id="btnExport" title="Export CSV">
        <i class="bi bi-download"></i>
      </button>
    </div>
  </form>
</div>

<div class="card">
  <div class="card-header d-flex flex-wrap align-items-center justify-content-between gap-2">
    <span><i class="bi bi-clock-history me-2"></i>System Activity Trail</span>
    <div class="d-flex gap-2 align-items-center">
      <span class="text-muted small">Every login, change and export is recorded.</span>
      <button class="btn btn-sm btn-outline-danger" id="btnPurge">
        <i class="bi bi-trash me-1"></i>Purge Old Logs
      </button>
    </div>
  </div>
  <div class="card-body">
    <div class="table-responsive">
      <table class="table table-hover align-middle w-100" id="auditTable">
        <thead>
          <tr>
            <th>#</th>
            <th>Date &amp; Time</th>
            <th>User</th>
            <th>Module</th>
            <th>Activity</th>
            <th>IP Address</th>
          </tr>
        </thead>
        <tbody></tbody>
      </table>
    </div>
  </div>
</div>

<!-- Purge modal -->
<div class="modal fade" id="purgeModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">
      <form id="purgeForm">
        <div class="modal-header">
          <h5 class="modal-title text-danger"><i class="bi bi-exclamation-triangle me-2"></i>Purge Audit Logs</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <p class="small text-muted">
            Permanently delete audit entries recorded <strong>before</strong> the date you choose.
            This cannot be undone, and the purge itself is recorded.
          </p>
          <label class="form-label" for="purgeBefore">Delete entries before</label>
          <input type="date" class="form-control" id="purgeBefore" required>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-danger"><i class="bi bi-trash me-1"></i>Purge</button>
        </div>
      </form>
    </div>
  </div>
</div>

<?php require __DIR__ . '/includes/footer.php'; ?>
