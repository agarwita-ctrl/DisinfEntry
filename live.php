<?php
/**
 * Live Monitoring — real-time booth view driven by AJAX polling.
 */

declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
require_login();

$PAGE_TITLE   = 'Live Monitoring';
$ACTIVE       = 'live';
$PAGE_SCRIPTS = ['assets/js/live.js'];
$interval     = (int) setting('refresh_interval', '3');

require __DIR__ . '/includes/header.php';
?>

<div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-3">
  <div class="d-flex align-items-center gap-2">
    <span class="badge bg-danger" id="pollBadge">
      <i class="bi bi-broadcast me-1"></i>LIVE — polling every <?= e((string) $interval) ?>s
    </span>
    <span class="text-muted small" id="lastSync">Waiting for data…</span>
  </div>

  <div class="d-flex align-items-center gap-2">
    <div class="form-check form-switch mb-0">
      <input class="form-check-input" type="checkbox" id="soundToggle" checked>
      <label class="form-check-label small" for="soundToggle"><i class="bi bi-volume-up me-1"></i>Sound</label>
    </div>
    <div class="form-check form-switch mb-0">
      <input class="form-check-input" type="checkbox" id="pauseToggle">
      <label class="form-check-label small" for="pauseToggle"><i class="bi bi-pause-circle me-1"></i>Pause</label>
    </div>
    <button class="btn btn-sm btn-outline-primary" id="testSound" title="Test the notification sound">
      <i class="bi bi-music-note-beamed"></i>
    </button>
  </div>
</div>

<!-- ===================== Current entrant ===================== -->
<div class="row g-3 mb-3">
  <div class="col-lg-7">
    <div class="live-hero is-idle h-100" id="liveHero">
      <div class="d-flex justify-content-between align-items-start mb-3">
        <div>
          <div class="live-meta text-uppercase" style="letter-spacing:.08em;font-size:.72rem">Current Entrant</div>
          <div class="live-name mt-1" id="liveName">Waiting for entry…</div>
          <div class="live-meta" id="liveEmployee">The booth has not reported anyone yet.</div>
        </div>
        <div class="text-end">
          <span class="badge bg-light text-dark" id="liveStatusBadge">Idle</span>
        </div>
      </div>

      <div class="d-flex align-items-end gap-3 mb-3">
        <div class="live-temp" id="liveTemp">--.-</div>
        <div class="pb-2">
          <div class="fs-4 fw-semibold">&deg;C</div>
          <div class="live-meta" id="liveTempNote">Threshold <?= e(setting('temperature_threshold', '37.8')) ?>&deg;C</div>
        </div>
      </div>

      <div class="row g-2">
        <div class="col-6 col-md-3">
          <div class="live-tile">
            <div class="label">Misting</div>
            <div class="value mist-indicator" id="liveMisting"><span class="dot"></span><span>Off</span></div>
          </div>
        </div>
        <div class="col-6 col-md-3">
          <div class="live-tile">
            <div class="label">Disinfection</div>
            <div class="value" id="liveDisinfection">—</div>
          </div>
        </div>
        <div class="col-6 col-md-3">
          <div class="live-tile">
            <div class="label">Entry Status</div>
            <div class="value" id="liveAccess">—</div>
          </div>
        </div>
        <div class="col-6 col-md-3">
          <div class="live-tile">
            <div class="label">Timestamp</div>
            <div class="value" id="liveTimestamp">—</div>
          </div>
        </div>
      </div>

      <div class="live-meta mt-3" id="liveRemarks"></div>
    </div>
  </div>

  <div class="col-lg-5">
    <div class="row g-3 h-100">
      <div class="col-6">
        <div class="stat-card acc-blue h-100">
          <div class="stat-icon"><i class="bi bi-door-open"></i></div>
          <div><div class="stat-value" id="cntTotal">0</div><div class="stat-label">Today</div></div>
        </div>
      </div>
      <div class="col-6">
        <div class="stat-card acc-green h-100">
          <div class="stat-icon"><i class="bi bi-check2-circle"></i></div>
          <div><div class="stat-value" id="cntGranted">0</div><div class="stat-label">Granted</div></div>
        </div>
      </div>
      <div class="col-6">
        <div class="stat-card acc-red h-100">
          <div class="stat-icon"><i class="bi bi-x-octagon"></i></div>
          <div><div class="stat-value" id="cntDenied">0</div><div class="stat-label">Denied</div></div>
        </div>
      </div>
      <div class="col-6">
        <div class="stat-card acc-orange h-100">
          <div class="stat-icon"><i class="bi bi-thermometer-high"></i></div>
          <div><div class="stat-value" id="cntHigh">0</div><div class="stat-label">High Temp</div></div>
        </div>
      </div>

      <div class="col-12">
        <div class="card">
          <div class="card-body py-3">
            <div class="d-flex align-items-center justify-content-between mb-2 gap-2">
              <span class="fw-semibold small"><i class="bi bi-cpu me-2"></i>Booth Status</span>
              <div class="d-flex align-items-center gap-2">
                <!-- Only rendered when more than one booth is registered. -->
                <select class="form-select form-select-sm d-none" id="boothPicker"
                        style="width:auto" aria-label="Select booth"></select>
                <span class="badge bg-secondary" id="boothBadge">Checking…</span>
              </div>
            </div>

            <!-- The booth's own sensor readings. It syncs only while idle, so
                 these arrive in batches and are minutes old at worst - the age
                 is shown rather than passing them off as a live feed. -->
            <div id="boothSensors" hidden>
              <div class="d-flex justify-content-between small text-muted mb-1">
                <span>Last sensor reading</span><span id="boothReadingAge">—</span>
              </div>
              <div class="row g-2 mb-2">
                <div class="col-4">
                  <div class="live-tile py-2">
                    <div class="label">Distance</div>
                    <div class="value small" id="boothDistance">—</div>
                  </div>
                </div>
                <div class="col-4">
                  <div class="live-tile py-2">
                    <div class="label">Object</div>
                    <div class="value small" id="boothObjTemp">—</div>
                  </div>
                </div>
                <div class="col-4">
                  <div class="live-tile py-2">
                    <div class="label">Ambient</div>
                    <div class="value small" id="boothAmbTemp">—</div>
                  </div>
                </div>
              </div>
            </div>

            <div class="d-flex justify-content-between small text-muted mb-2" id="boothCycleRow" hidden>
              <span>Last cycle</span><span id="boothLastCycle">—</span>
            </div>

            <div class="d-flex justify-content-between small text-muted mb-1">
              <span>Disinfectant level</span><span id="boothLevelText">—</span>
            </div>
            <div class="progress" style="height:8px">
              <div class="progress-bar bg-info" id="boothLevelBar" style="width:0%"></div>
            </div>
            <div class="small text-muted mt-2" id="boothMeta">—</div>
            <div class="small text-danger mt-1" id="boothFault" hidden>
              <i class="bi bi-exclamation-triangle-fill me-1"></i>Thermometer not detected — the booth is denying all entry.
            </div>
          </div>
        </div>
      </div>
    </div>
  </div>
</div>

<!-- ===================== Live feed ===================== -->
<div class="card">
  <div class="card-header d-flex align-items-center justify-content-between">
    <span><i class="bi bi-list-ul me-2"></i>Live Entry Feed</span>
    <span class="text-muted small">Last 15 records</span>
  </div>
  <div class="table-responsive">
    <table class="table table-hover align-middle mb-0">
      <thead>
        <tr>
          <th>Entry ID</th><th class="text-center">Temperature</th>
          <th>Time</th><th class="text-center">Misting</th>
          <th class="text-center">Disinfection</th><th class="text-center">Status</th><th>Remarks</th>
        </tr>
      </thead>
      <tbody id="feedBody">
        <tr><td colspan="7" class="text-center text-muted py-4">Loading…</td></tr>
      </tbody>
    </table>
  </div>
</div>

<?php require __DIR__ . '/includes/footer.php'; ?>
