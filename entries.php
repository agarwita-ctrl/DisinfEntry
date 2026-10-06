<?php
/**
 * Entry Monitoring — all booth logs with filters, sorting, paging and exports.
 */

declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
require_login();

$devices = db()->query('SELECT device_id, device_name FROM devices ORDER BY device_name')->fetchAll();

$PAGE_TITLE   = 'Entry Monitoring';
$ACTIVE       = 'entries';
$PAGE_SCRIPTS = ['assets/js/entries.js'];

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
      <label class="form-label" for="fTemp">Temperature</label>
      <select class="form-select form-select-sm" id="fTemp" name="temp">
        <option value="">All temperatures</option>
        <option value="normal">Normal (&le; <?= e(setting('temperature_threshold', '37.8')) ?>&deg;C)</option>
        <option value="high">High (&gt; <?= e(setting('temperature_threshold', '37.8')) ?>&deg;C)</option>
      </select>
    </div>
    <div class="col-6 col-md-3 col-xl-2">
      <label class="form-label" for="fStatus">Access Status</label>
      <select class="form-select form-select-sm" id="fStatus" name="status">
        <option value="">All statuses</option>
        <option value="granted">Granted</option>
        <option value="denied">Denied</option>
      </select>
    </div>
    <div class="col-6 col-md-3 col-xl-2">
      <label class="form-label" for="fDisinfection">Disinfection</label>
      <select class="form-select form-select-sm" id="fDisinfection" name="disinfection">
        <option value="">All</option>
        <option value="completed">Completed</option>
        <option value="incomplete">Incomplete</option>
        <option value="skipped">Skipped</option>
      </select>
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
    <div class="col-12 col-xl-5 d-flex gap-2">
      <button type="submit" class="btn btn-sm btn-primary flex-fill">
        <i class="bi bi-funnel me-1"></i>Apply Filters
      </button>
      <button type="button" class="btn btn-sm btn-outline-secondary flex-fill" id="resetFilters">
        <i class="bi bi-arrow-counterclockwise me-1"></i>Reset
      </button>
      <div class="btn-group flex-fill">
        <button type="button" class="btn btn-sm btn-outline-success dropdown-toggle" data-bs-toggle="dropdown">
          <i class="bi bi-download me-1"></i>Export
        </button>
        <ul class="dropdown-menu dropdown-menu-end">
          <li><a class="dropdown-item" href="#" id="exportCsv"><i class="bi bi-filetype-csv me-2"></i>Export CSV</a></li>
          <li><a class="dropdown-item" href="#" id="exportExcel"><i class="bi bi-file-earmark-excel me-2"></i>Export Excel (.xlsx)</a></li>
          <li><hr class="dropdown-divider"></li>
          <li><a class="dropdown-item" href="#" id="printTable"><i class="bi bi-printer me-2"></i>Print</a></li>
        </ul>
      </div>
    </div>
  </form>
</div>

<!-- ===================== Summary ===================== -->
<div class="row g-3 mb-3">
  <div class="col-6 col-lg-3">
    <div class="stat-card acc-blue">
      <div class="stat-icon"><i class="bi bi-collection"></i></div>
      <div><div class="stat-value" id="sumTotal">0</div><div class="stat-label">Matching Records</div></div>
    </div>
  </div>
  <div class="col-6 col-lg-3">
    <div class="stat-card acc-green">
      <div class="stat-icon"><i class="bi bi-check2-circle"></i></div>
      <div><div class="stat-value" id="sumGranted">0</div><div class="stat-label">Granted</div></div>
    </div>
  </div>
  <div class="col-6 col-lg-3">
    <div class="stat-card acc-red">
      <div class="stat-icon"><i class="bi bi-x-octagon"></i></div>
      <div><div class="stat-value" id="sumDenied">0</div><div class="stat-label">Denied</div></div>
    </div>
  </div>
  <div class="col-6 col-lg-3">
    <div class="stat-card acc-orange">
      <div class="stat-icon"><i class="bi bi-thermometer-half"></i></div>
      <div><div class="stat-value" id="sumAvg">—</div><div class="stat-label">Average Temp</div></div>
    </div>
  </div>
</div>

<!-- ===================== Table ===================== -->
<div class="card">
  <div class="card-header d-flex flex-wrap align-items-center justify-content-between gap-2">
    <span><i class="bi bi-table me-2"></i>Entry Logs</span>
    <span class="text-muted small" id="activeFilterNote">Showing all records</span>
  </div>
  <div class="card-body">
    <div class="print-only mb-3">
      <h4><?= e(setting('system_name', 'DisinfEntry')) ?> — Entry Logs</h4>
      <div class="small"><?= e(setting('farm_name', 'Poultry Farm')) ?> · Printed <?= date('F d, Y h:i A') ?></div>
    </div>
    <div class="table-responsive">
      <table class="table table-hover align-middle w-100" id="entriesTable">
        <thead>
          <tr>
            <th>Entry ID</th>
            <th class="text-center">Temperature</th>
            <th>Entry Date</th>
            <th>Entry Time</th>
            <th class="text-center">Disinfection</th>
            <th class="text-center">Access</th>
            <th>Remarks</th>
            <th class="text-end no-print">Action</th>
          </tr>
        </thead>
        <tbody></tbody>
      </table>
    </div>
  </div>
</div>

<!-- Entry detail modal -->
<div class="modal fade" id="entryModal" tabindex="-1" aria-hidden="true">
  <!-- Wide and scrollable: the booth cycle adds a sequence timeline below the summary. -->
  <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title"><i class="bi bi-card-list me-2"></i>Entry Details</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body" id="entryModalBody"></div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
      </div>
    </div>
  </div>
</div>

<?php require __DIR__ . '/includes/footer.php'; ?>
