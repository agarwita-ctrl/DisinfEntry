<?php
/**
 * Reports — daily / weekly / monthly / yearly / custom range.
 */

declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
require_login();

$PAGE_TITLE   = 'Reports';
$ACTIVE       = 'reports';
$PAGE_SCRIPTS = ['assets/js/reports.js'];

require __DIR__ . '/includes/header.php';
?>

<!-- ===================== Controls ===================== -->
<div class="filter-bar mb-3 no-print">
  <form id="reportForm" class="row g-2 align-items-end">
    <div class="col-md-4 col-xl-3">
      <label class="form-label" for="preset">Report Type</label>
      <select class="form-select form-select-sm" id="preset" name="preset">
        <option value="daily">Daily Report (today)</option>
        <option value="weekly">Weekly Report (this week)</option>
        <option value="monthly">Monthly Report (this month)</option>
        <option value="yearly">Yearly Report (this year)</option>
        <option value="custom">Custom Date Range</option>
      </select>
    </div>
    <div class="col-6 col-md-3 col-xl-2 d-none" id="fromWrap">
      <label class="form-label" for="from">From</label>
      <input type="date" class="form-control form-control-sm" id="from" name="from">
    </div>
    <div class="col-6 col-md-3 col-xl-2 d-none" id="toWrap">
      <label class="form-label" for="to">To</label>
      <input type="date" class="form-control form-control-sm" id="to" name="to">
    </div>
    <div class="col-12 col-xl d-flex gap-2">
      <button type="submit" class="btn btn-sm btn-primary">
        <i class="bi bi-bar-chart-line me-1"></i>Generate Report
      </button>
      <button type="button" class="btn btn-sm btn-outline-secondary" id="btnPrint">
        <i class="bi bi-printer me-1"></i>Print
      </button>
      <button type="button" class="btn btn-sm btn-outline-danger" id="btnPdf">
        <i class="bi bi-file-earmark-pdf me-1"></i>Export PDF
      </button>
      <button type="button" class="btn btn-sm btn-outline-success" id="btnExcel">
        <i class="bi bi-file-earmark-excel me-1"></i>Export Excel (.xlsx)
      </button>
      <button type="button" class="btn btn-sm btn-outline-secondary" id="btnCsv">
        <i class="bi bi-filetype-csv me-1"></i>CSV
      </button>
    </div>
  </form>
</div>

<!-- ===================== Report sheet ===================== -->
<div class="report-sheet" id="reportSheet">

  <div class="report-head d-flex flex-wrap justify-content-between align-items-start gap-3">
    <div>
      <h4 class="mb-1 fw-bold" id="rTitle">Daily Report</h4>
      <div class="text-muted small" id="rSubtitle">Generating…</div>
    </div>
    <div class="text-md-end small text-muted">
      <div><strong id="rSystem"><?= e(setting('system_name', 'DisinfEntry')) ?></strong></div>
      <div id="rFarm"><?= e(setting('farm_name', 'Poultry Farm')) ?></div>
      <div id="rGenerated"></div>
    </div>
  </div>

  <!-- Summary -->
  <div class="summary-grid mb-4">
    <div class="summary-box"><div class="val" id="rTotal">—</div><div class="lbl">Total Entries</div></div>
    <div class="summary-box"><div class="val text-success" id="rPassed">—</div><div class="lbl">Passed</div></div>
    <div class="summary-box"><div class="val text-danger" id="rDenied">—</div><div class="lbl">Denied</div></div>
    <div class="summary-box"><div class="val text-warning" id="rHigh">—</div><div class="lbl">High Temperature</div></div>
    <div class="summary-box"><div class="val" id="rAvg">—</div><div class="lbl">Average Temp</div></div>
    <div class="summary-box"><div class="val" id="rRate">—</div><div class="lbl">Pass Rate</div></div>
    <div class="summary-box"><div class="val" id="rDailyAvg">—</div><div class="lbl">Avg / Active Day</div></div>
  </div>

  <!-- Charts -->
  <div class="row g-3 mb-4">
    <div class="col-lg-8">
      <div class="card h-100">
        <div class="card-header"><i class="bi bi-graph-up me-2"></i>Entry Trend</div>
        <div class="card-body"><div class="chart-box"><canvas id="trendChart"></canvas></div></div>
      </div>
    </div>
    <div class="col-lg-4">
      <div class="card h-100">
        <div class="card-header"><i class="bi bi-thermometer-half me-2"></i>Temperature Spread</div>
        <div class="card-body"><div class="chart-box"><canvas id="distChart"></canvas></div></div>
      </div>
    </div>
  </div>

  <div class="row g-3 mb-4">
    <div class="col-12">
      <div class="card h-100">
        <div class="card-header"><i class="bi bi-shield-x me-2"></i>Denial Reasons</div>
        <div class="card-body"><div class="chart-box-sm"><canvas id="denialChart"></canvas></div></div>
      </div>
    </div>
  </div>

  <!-- Detail table -->
  <div class="card">
    <div class="card-header d-flex flex-wrap justify-content-between align-items-center gap-2">
      <span><i class="bi bi-table me-2"></i>Detailed Entry Log</span>
      <span class="text-muted small" id="rRowCount"></span>
    </div>
    <div class="card-body">
      <div class="table-responsive">
        <table class="table table-hover table-sm align-middle w-100" id="reportTable">
          <thead>
            <tr>
              <th>Entry ID</th><th class="text-center">Temp</th>
              <th>Date</th><th>Time</th><th class="text-center">Disinfection</th>
              <th class="text-center">Access</th><th>Remarks</th>
            </tr>
          </thead>
          <tbody></tbody>
        </table>
      </div>
    </div>
  </div>

  <div class="print-only mt-4">
    <div class="d-flex justify-content-between" style="gap:60px;margin-top:40px">
      <div style="flex:1;border-top:1px solid #333;padding-top:6px">Prepared by</div>
      <div style="flex:1;border-top:1px solid #333;padding-top:6px">Reviewed by</div>
      <div style="flex:1;border-top:1px solid #333;padding-top:6px">Noted by</div>
    </div>
  </div>
</div>

<?php require __DIR__ . '/includes/footer.php'; ?>
