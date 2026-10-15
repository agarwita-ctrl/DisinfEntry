<?php
/**
 * Exit Monitoring — everyone who left through the booth's exit-side sensor.
 */

declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
require_login();

$devices = db()->query('SELECT device_id, device_name FROM devices ORDER BY device_name')->fetchAll();

$PAGE_TITLE   = 'Exit Monitoring';
$ACTIVE       = 'exits';
$PAGE_SCRIPTS = ['assets/js/exits.js'];

require __DIR__ . '/includes/header.php';
?>

<!-- ===================== Filters ===================== -->
<div class="filter-bar mb-3 no-print">
  <form id="filterForm" class="row g-2 align-items-end">
    <div class="col-6 col-md-3 col-xl-2">
      <label class="form-label" for="fDate">Exact Date</label>
      <input type="date" class="form-control form-control-sm" id="fDate" name="date">
    </div>
    <div class="col-6 col-md-3 col-xl-2">
      <label class="form-label" for="fMonth">Month</label>
      <input type="month" class="form-control form-control-sm" id="fMonth" name="month">
    </div>
    <div class="col-6 col-md-3 col-xl-2">
      <label class="form-label" for="fFrom">Date From</label>
      <input type="date" class="form-control form-control-sm" id="fFrom" name="date_from">
    </div>
    <div class="col-6 col-md-3 col-xl-2">
      <label class="form-label" for="fTo">Date To</label>
      <input type="date" class="form-control form-control-sm" id="fTo" name="date_to">
    </div>
    <div class="col-6 col-md-3 col-xl-2">
      <label class="form-label" for="fDevice">Booth</label>
      <select class="form-select form-select-sm" id="fDevice" name="device">
        <option value="">All booths</option>
        <?php foreach ($devices as $d): ?>
          <option value="<?= e($d['device_id']) ?>"><?= e($d['device_name']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="col-12 col-xl-2 d-flex gap-2">
      <button type="submit" class="btn btn-sm btn-primary flex-fill">
        <i class="bi bi-funnel me-1"></i>Apply
      </button>
      <button type="button" class="btn btn-sm btn-outline-secondary flex-fill" id="resetFilters">
        <i class="bi bi-arrow-counterclockwise me-1"></i>Reset
      </button>
    </div>
  </form>
</div>

<!-- ===================== Summary ===================== -->
<div class="row g-3 mb-3">
  <div class="col-12 col-md-4">
    <div class="stat-card acc-blue">
      <div class="stat-icon"><i class="bi bi-collection"></i></div>
      <div><div class="stat-value" id="sumTotal">0</div><div class="stat-label">Matching Exits</div></div>
    </div>
  </div>
  <div class="col-6 col-md-4">
    <div class="stat-card acc-green">
      <div class="stat-icon"><i class="bi bi-box-arrow-right"></i></div>
      <div><div class="stat-value" id="sumToday">0</div><div class="stat-label">Exits Today</div></div>
    </div>
  </div>
  <div class="col-6 col-md-4">
    <div class="stat-card acc-purple">
      <div class="stat-icon"><i class="bi bi-clock-history"></i></div>
      <div class="min-w-0">
        <div class="stat-value fs-6" id="sumLast">—</div>
        <div class="stat-label">Last Exit</div>
      </div>
    </div>
  </div>
</div>

<div class="alert alert-warning small d-none" id="exitNotice"></div>

<!-- ===================== Table ===================== -->
<div class="card">
  <div class="card-header d-flex flex-wrap align-items-center justify-content-between gap-2">
    <span><i class="bi bi-table me-2"></i>Exit Logs</span>
    <span class="text-muted small" id="activeFilterNote">Showing all records</span>
  </div>
  <div class="card-body">
    <div class="table-responsive">
      <table class="table table-hover align-middle w-100" id="exitsTable">
        <thead>
          <tr>
            <th>Exit ID</th>
            <th>Exit Date</th>
            <th>Exit Time</th>
            <th>Booth</th>
            <th class="text-end">Distance</th>
          </tr>
        </thead>
        <tbody></tbody>
      </table>
    </div>
  </div>
</div>

<?php require __DIR__ . '/includes/footer.php'; ?>
