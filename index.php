<?php
/**
 * Dashboard — KPI cards, charts and recent activity.
 */

declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
require_login();

$PAGE_TITLE   = 'Dashboard';
$ACTIVE       = 'dashboard';
$PAGE_SCRIPTS = ['assets/js/dashboard.js'];

require __DIR__ . '/includes/header.php';
?>

<!-- ===================== KPI cards ===================== -->
<div class="row g-3 mb-3">
  <div class="col-6 col-xl">
    <div class="stat-card acc-blue">
      <div class="stat-icon"><i class="bi bi-door-open"></i></div>
      <div class="min-w-0">
        <div class="stat-value" id="kpiTotal">—</div>
        <div class="stat-label">Today's Entries</div>
        <div class="stat-sub" id="kpiTotalSub">&nbsp;</div>
      </div>
    </div>
  </div>
  <div class="col-6 col-xl">
    <div class="stat-card acc-green">
      <div class="stat-icon"><i class="bi bi-check2-circle"></i></div>
      <div class="min-w-0">
        <div class="stat-value" id="kpiGranted">—</div>
        <div class="stat-label">Successful Entries</div>
        <div class="stat-sub" id="kpiGrantedSub">&nbsp;</div>
      </div>
    </div>
  </div>
  <div class="col-6 col-xl">
    <div class="stat-card acc-red">
      <div class="stat-icon"><i class="bi bi-x-octagon"></i></div>
      <div class="min-w-0">
        <div class="stat-value" id="kpiDenied">—</div>
        <div class="stat-label">Denied Entries</div>
        <div class="stat-sub" id="kpiDeniedSub">&nbsp;</div>
      </div>
    </div>
  </div>
  <div class="col-6 col-xl">
    <div class="stat-card acc-orange">
      <div class="stat-icon"><i class="bi bi-thermometer-high"></i></div>
      <div class="min-w-0">
        <div class="stat-value" id="kpiHighTemp">—</div>
        <div class="stat-label">High Temp Alerts</div>
        <div class="stat-sub" id="kpiHighTempSub">&nbsp;</div>
      </div>
    </div>
  </div>
  <div class="col-6 col-xl">
    <div class="stat-card acc-purple">
      <div class="stat-icon"><i class="bi bi-people"></i></div>
      <div class="min-w-0">
        <div class="stat-value" id="kpiUsers">—</div>
        <div class="stat-label">Active Users</div>
        <div class="stat-sub" id="kpiUsersSub">&nbsp;</div>
      </div>
    </div>
  </div>
</div>

<!-- ===================== Charts row 1 ===================== -->
<div class="row g-3 mb-3">
  <div class="col-lg-8">
    <div class="card h-100">
      <div class="card-header d-flex flex-wrap align-items-center justify-content-between gap-2">
        <span><i class="bi bi-graph-up me-2"></i>Entry Volume</span>
        <div class="btn-group btn-group-sm" role="group" id="volumeRange">
          <button type="button" class="btn btn-outline-primary active" data-range="daily">Daily</button>
          <button type="button" class="btn btn-outline-primary" data-range="weekly">Weekly</button>
          <button type="button" class="btn btn-outline-primary" data-range="monthly">Monthly</button>
        </div>
      </div>
      <div class="card-body">
        <div class="chart-box"><canvas id="volumeChart"></canvas></div>
      </div>
    </div>
  </div>

  <div class="col-lg-4">
    <div class="card h-100">
      <div class="card-header"><i class="bi bi-pie-chart me-2"></i>Pass vs Denied <span class="text-muted small fw-normal">(30 days)</span></div>
      <div class="card-body d-flex flex-column">
        <div class="chart-box-sm flex-grow-1"><canvas id="passDeniedChart"></canvas></div>
        <div class="d-flex justify-content-around border-top pt-3 mt-2 text-center">
          <div>
            <div class="fw-bold fs-5 text-success" id="pdGranted">—</div>
            <div class="text-muted small">Granted</div>
          </div>
          <div>
            <div class="fw-bold fs-5 text-danger" id="pdDenied">—</div>
            <div class="text-muted small">Denied</div>
          </div>
          <div>
            <div class="fw-bold fs-5" id="pdRate">—</div>
            <div class="text-muted small">Pass rate</div>
          </div>
        </div>
      </div>
    </div>
  </div>
</div>

<!-- ===================== Charts row 2 ===================== -->
<div class="row g-3 mb-3">
  <div class="col-lg-7">
    <div class="card h-100">
      <div class="card-header">
        <i class="bi bi-thermometer-half me-2"></i>Temperature Distribution
        <span class="text-muted small fw-normal">(last 30 days · threshold <span id="thresholdLabel">—</span>°C)</span>
      </div>
      <div class="card-body">
        <div class="chart-box-sm"><canvas id="tempChart"></canvas></div>
      </div>
    </div>
  </div>

  <div class="col-lg-5">
    <div class="card h-100">
      <div class="card-header"><i class="bi bi-calendar3 me-2"></i>Monthly Trend <span class="text-muted small fw-normal">(12 months)</span></div>
      <div class="card-body">
        <div class="chart-box-sm"><canvas id="monthlyChart"></canvas></div>
      </div>
    </div>
  </div>
</div>

<!-- ===================== Recent activity ===================== -->
<div class="card">
  <div class="card-header d-flex flex-wrap align-items-center justify-content-between gap-2">
    <span><i class="bi bi-activity me-2"></i>Recent Activity</span>
    <div class="d-flex align-items-center gap-2">
      <span class="badge bg-success-subtle text-success border border-success-subtle" id="liveIndicator">
        <i class="bi bi-broadcast me-1"></i>Live
      </span>
      <a href="<?= url('entries.php') ?>" class="btn btn-sm btn-outline-primary">
        View all <i class="bi bi-arrow-right ms-1"></i>
      </a>
    </div>
  </div>
  <div class="table-responsive">
    <table class="table table-hover align-middle mb-0">
      <thead>
        <tr>
          <th>Entry ID</th>
          <th class="text-center">Temperature</th>
          <th>Time</th>
          <th class="text-center">Disinfection</th>
          <th class="text-center">Status</th>
          <th class="text-end">Action</th>
        </tr>
      </thead>
      <tbody id="recentBody">
        <tr><td colspan="6" class="text-center text-muted py-4">Loading…</td></tr>
      </tbody>
    </table>
  </div>
</div>

<!-- Entry detail modal -->
<div class="modal fade" id="entryModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
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
