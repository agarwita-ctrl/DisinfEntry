<?php
/**
 * System Settings (administrator only).
 */

declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
require_admin();

$s         = all_settings();
$timezones = DateTimeZone::listIdentifiers();
$devices   = device_states();

/* What each booth last reported it is actually running, so an operator can see
   whether a saved change has been adopted yet. Absent until migration 001 has
   been applied and a booth has synced at least once. */
$boothConfigs = [];
try {
    foreach (db()->query('SELECT * FROM booth_config') as $row) {
        $boothConfigs[$row['device_id']] = $row;
    }
} catch (Throwable $e) {
    $boothConfigs = [];
}

/* The values the booth should converge on, keyed as booth_config stores them. */
$wanted = booth_directives();

$PAGE_TITLE   = 'System Settings';
$ACTIVE       = 'settings';
$PAGE_SCRIPTS = ['assets/js/settings.js'];

require __DIR__ . '/includes/header.php';
?>

<form id="settingsForm" enctype="multipart/form-data">
  <div class="row g-3">

    <!-- ============ General ============ -->
    <div class="col-lg-6">
      <div class="card h-100">
        <div class="card-header"><i class="bi bi-building me-2"></i>Farm &amp; Branding</div>
        <div class="card-body">
          <div class="mb-3">
            <label class="form-label" for="farm_name">Farm Name <span class="text-danger">*</span></label>
            <input type="text" class="form-control" id="farm_name" name="farm_name"
                   value="<?= e($s['farm_name'] ?? '') ?>" required maxlength="120">
            <div class="form-text">Shown in the sidebar, reports and exports.</div>
          </div>

          <div class="mb-3">
            <label class="form-label" for="system_name">System Name <span class="text-danger">*</span></label>
            <input type="text" class="form-control" id="system_name" name="system_name"
                   value="<?= e($s['system_name'] ?? '') ?>" required maxlength="60">
          </div>

          <div class="mb-3">
            <label class="form-label" for="timezone">Timezone</label>
            <select class="form-select" id="timezone" name="timezone">
              <?php foreach ($timezones as $tz): ?>
                <option value="<?= e($tz) ?>" <?= ($s['timezone'] ?? '') === $tz ? 'selected' : '' ?>>
                  <?= e($tz) ?>
                </option>
              <?php endforeach; ?>
            </select>
            <div class="form-text">Current server time: <strong><?= date('F d, Y h:i:s A') ?></strong></div>
          </div>

          <label class="form-label">System Logo</label>
          <div class="d-flex align-items-center gap-3">
            <div class="border rounded d-grid place-items-center bg-light"
                 style="width:72px;height:72px;display:grid;place-items:center;overflow:hidden">
              <?php if (!empty($s['logo']) && file_exists(APP_ROOT . '/' . $s['logo'])): ?>
                <img src="<?= url($s['logo']) ?>" alt="Logo" style="max-width:100%;max-height:100%" id="logoPreview">
              <?php else: ?>
                <i class="bi bi-image text-muted fs-3" id="logoPlaceholder"></i>
              <?php endif; ?>
            </div>
            <div class="flex-grow-1">
              <input type="file" class="form-control form-control-sm" id="logo" name="logo"
                     accept="image/png,image/jpeg,image/gif,image/webp">
              <div class="form-text">PNG, JPG, GIF or WEBP · max 2 MB.</div>
              <?php if (!empty($s['logo'])): ?>
                <button type="button" class="btn btn-sm btn-outline-danger mt-1" id="btnRemoveLogo">
                  <i class="bi bi-trash me-1"></i>Remove logo
                </button>
              <?php endif; ?>
            </div>
          </div>
        </div>
      </div>
    </div>

    <!-- ============ Monitoring ============ -->
    <div class="col-lg-6">
      <div class="card h-100">
        <div class="card-header"><i class="bi bi-sliders me-2"></i>Monitoring Parameters</div>
        <div class="card-body">
          <div class="mb-3">
            <label class="form-label" for="temperature_threshold">
              Temperature Threshold (&deg;C) <span class="text-danger">*</span>
            </label>
            <div class="input-group">
              <input type="number" class="form-control" id="temperature_threshold" name="temperature_threshold"
                     value="<?= e($s['temperature_threshold'] ?? '37.8') ?>" step="0.1" min="30" max="45" required>
              <span class="input-group-text">&deg;C</span>
            </div>
            <div class="form-text">
              Readings above this value are flagged as high temperature and <strong>automatically denied entry</strong>.
            </div>
          </div>

          <div class="mb-3">
            <label class="form-label" for="disinfection_duration">Disinfection / Misting Duration</label>
            <div class="input-group">
              <input type="number" class="form-control" id="disinfection_duration" name="disinfection_duration"
                     value="<?= e($s['disinfection_duration'] ?? '8') ?>" min="1" max="120" required>
              <span class="input-group-text">seconds</span>
            </div>
            <div class="form-text">Sent back to the ESP32 so the misting pump runs for this long.</div>
          </div>

          <div class="mb-3">
            <label class="form-label" for="refresh_interval">Dashboard Refresh Interval</label>
            <div class="input-group">
              <input type="number" class="form-control" id="refresh_interval" name="refresh_interval"
                     value="<?= e($s['refresh_interval'] ?? '3') ?>" min="1" max="60" required>
              <span class="input-group-text">seconds</span>
            </div>
            <div class="form-text">How often the live monitor polls for new booth records.</div>
          </div>

          <div class="row g-3">
            <div class="col-sm-6">
              <label class="form-label" for="device_offline_after">Mark Booth Offline After</label>
              <div class="input-group">
                <input type="number" class="form-control" id="device_offline_after" name="device_offline_after"
                       value="<?= e($s['device_offline_after'] ?? '60') ?>" min="15" max="3600" required>
                <span class="input-group-text">s</span>
              </div>
            </div>
            <div class="col-sm-6">
              <label class="form-label" for="low_disinfectant_at">Low Disinfectant Warning</label>
              <div class="input-group">
                <input type="number" class="form-control" id="low_disinfectant_at" name="low_disinfectant_at"
                       value="<?= e($s['low_disinfectant_at'] ?? '20') ?>" min="1" max="90" required>
                <span class="input-group-text">%</span>
              </div>
            </div>
          </div>
        </div>
      </div>
    </div>

    <!-- ============ Booth sequence (adopted over the air) ============ -->
    <div class="col-lg-6">
      <div class="card h-100">
        <div class="card-header">
          <i class="bi bi-broadcast me-2"></i>Booth Sequence
          <span class="badge bg-secondary ms-1">ESP32</span>
        </div>
        <div class="card-body">
          <p class="form-text mt-0">
            The booth reads these on its next sync and retunes itself — no reflashing. It only syncs
            while idle with nobody in range, so a change never lands mid-spray.
          </p>

          <div class="row g-3">
            <div class="col-sm-6">
              <label class="form-label" for="detection_distance_cm">Detection Distance</label>
              <div class="input-group">
                <input type="number" class="form-control" id="detection_distance_cm" name="detection_distance_cm"
                       value="<?= e($s['detection_distance_cm'] ?? '50') ?>" step="0.1" min="2" max="400" required>
                <span class="input-group-text">cm</span>
              </div>
              <div class="form-text">How close someone must be to trigger a cycle.</div>
            </div>

            <div class="col-sm-6">
              <label class="form-label" for="presence_confirm_ms">Presence Confirmation</label>
              <div class="input-group">
                <input type="number" class="form-control" id="presence_confirm_ms" name="presence_confirm_ms"
                       value="<?= e($s['presence_confirm_ms'] ?? '5000') ?>" step="100" min="1000" max="60000" required>
                <span class="input-group-text">ms</span>
              </div>
              <div class="form-text">How long they must stay before screening.</div>
            </div>

            <div class="col-sm-6">
              <label class="form-label" for="pump_on_ms">Spray Burst</label>
              <div class="input-group">
                <input type="number" class="form-control" id="pump_on_ms" name="pump_on_ms"
                       value="<?= e($s['pump_on_ms'] ?? '2000') ?>" step="100" min="200" max="60000" required>
                <span class="input-group-text">ms</span>
              </div>
              <div class="form-text">Each of the two bursts.</div>
            </div>

            <div class="col-sm-6">
              <label class="form-label" for="pump_off_ms">Between Bursts</label>
              <div class="input-group">
                <input type="number" class="form-control" id="pump_off_ms" name="pump_off_ms"
                       value="<?= e($s['pump_off_ms'] ?? '2000') ?>" step="100" min="0" max="60000" required>
                <span class="input-group-text">ms</span>
              </div>
            </div>

            <div class="col-sm-6">
              <label class="form-label" for="door_open_ms">Door Hold Open</label>
              <div class="input-group">
                <input type="number" class="form-control" id="door_open_ms" name="door_open_ms"
                       value="<?= e($s['door_open_ms'] ?? '5000') ?>" step="100" min="500" max="120000" required>
                <span class="input-group-text">ms</span>
              </div>
            </div>

            <div class="col-sm-6">
              <label class="form-label" for="telemetry_retention_hours">Keep Raw Telemetry</label>
              <div class="input-group">
                <input type="number" class="form-control" id="telemetry_retention_hours" name="telemetry_retention_hours"
                       value="<?= e($s['telemetry_retention_hours'] ?? '24') ?>" min="1" max="720" required>
                <span class="input-group-text">h</span>
              </div>
              <div class="form-text">Distance and temperature streams. Entries are never pruned.</div>
            </div>
          </div>

          <div class="alert alert-light border small mt-3 mb-0">
            <i class="bi bi-thermometer-half me-1"></i>
            The fever threshold the booth screens against is
            <strong><?= e($s['temperature_threshold'] ?? '37.8') ?>&deg;C</strong>, set under
            Monitoring Parameters. Pin assignments, servo angles and sampling rates are compiled
            into the sketch and can only change by reflashing.
          </div>
        </div>
      </div>
    </div>

    <!-- ============ Device / API ============ -->
    <div class="col-lg-6">
      <div class="card h-100">
        <div class="card-header"><i class="bi bi-cpu me-2"></i>Booth API Access</div>
        <div class="card-body">
          <label class="form-label">Device API Key</label>
          <div class="input-group mb-2">
            <input type="text" class="form-control font-monospace" id="apiKey"
                   value="<?= e($s['api_key'] ?? '') ?>" readonly>
            <button class="btn btn-outline-secondary" type="button" id="btnCopyKey" title="Copy"><i class="bi bi-clipboard"></i></button>
            <button class="btn btn-outline-warning" type="button" id="btnRegenKey" title="Generate a new key">
              <i class="bi bi-arrow-repeat"></i>
            </button>
          </div>
          <div class="form-text mb-3">
            The ESP32 must send this value in the <code>X-API-Key</code> header. Regenerating it immediately
            blocks any booth still using the old key.
          </div>

          <?php $origin = (($_SERVER['HTTPS'] ?? '') === 'on' ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost'); ?>
          <label class="form-label">Endpoint URLs</label>
          <div class="bg-light border rounded p-2 small font-monospace mb-1" style="word-break:break-all">
            <div class="mb-1">
              <span class="badge bg-success">POST</span> <?= e($origin . url('api/booth_sync.php')) ?>
            </div>
            <div class="mb-1 text-muted">
              <span class="badge bg-secondary">POST</span> <?= e($origin . url('api/entry.php')) ?>
            </div>
            <div class="text-muted">
              <span class="badge bg-secondary">POST</span> <?= e($origin . url('api/device_status.php?action=heartbeat')) ?>
            </div>
          </div>
          <div class="form-text mb-3">
            <code>booth_sync.php</code> is what the current firmware posts to — it carries whole cycles,
            telemetry and the heartbeat in one request. The two greyed endpoints stay for older sketches
            that post a single reading at a time.
          </div>

          <label class="form-label">Registered Booths</label>
          <?php if (!$devices): ?>
            <div class="text-muted small">No booth has reported yet.</div>
          <?php else: foreach ($devices as $d):
            $cfg = $boothConfigs[$d['device_id']] ?? null; ?>
            <div class="border rounded p-2 mb-2">
              <div class="d-flex align-items-center justify-content-between">
                <div>
                  <div class="fw-semibold small"><?= e($d['device_name']) ?></div>
                  <div class="text-muted" style="font-size:.74rem">
                    <?= e($d['device_id']) ?> · <?= e($d['ip_address'] ?: 'no IP') ?> ·
                    fw <?= e($d['firmware'] ?: '—') ?> ·
                    last seen <?= $d['last_seen'] ? fmt_datetime($d['last_seen']) : 'never' ?>
                  </div>
                </div>
                <div class="text-end">
                  <span class="badge <?= $d['online'] ? 'bg-success' : 'bg-danger' ?>">
                    <?= $d['online'] ? 'Online' : 'Offline' ?>
                  </span>
                  <div class="small text-muted mt-1">
                    <?= $d['disinfectant_level'] === null
                        ? 'disinfectant not reported'
                        : (int) $d['disinfectant_level'] . '% disinfectant' ?>
                  </div>
                </div>
              </div>

              <?php if ($cfg): ?>
                <?php
                /* A booth that has not synced since the last save is still running the
                   old sequence. Showing both numbers is the only way to tell. */
                $drift = [];
                foreach ($wanted as $field => $want) {
                    if ($cfg[$field] === null) {
                        continue;
                    }
                    if (abs((float) $cfg[$field] - (float) $want) >= 0.05) {
                        $drift[$field] = (float) $cfg[$field];
                    }
                }
                ?>
                <div class="mt-2 pt-2 border-top small">
                  <?php if (!$drift): ?>
                    <span class="text-success">
                      <i class="bi bi-check-circle me-1"></i>Running the saved sequence.
                    </span>
                  <?php else: ?>
                    <span class="text-warning-emphasis">
                      <i class="bi bi-hourglass-split me-1"></i>Not yet adopted:
                    </span>
                    <?php foreach ($drift as $field => $running): ?>
                      <div class="text-muted font-monospace" style="font-size:.72rem">
                        <?= e($field) ?>: running <?= e(rtrim(rtrim(number_format($running, 2, '.', ''), '0'), '.')) ?>
                        → saved <?= e((string) $wanted[$field]) ?>
                      </div>
                    <?php endforeach; ?>
                  <?php endif; ?>
                  <div class="text-muted mt-1" style="font-size:.72rem">
                    Reported <?= fmt_datetime($cfg['reported_at']) ?>
                  </div>
                </div>
              <?php endif; ?>
            </div>
          <?php endforeach; endif; ?>
        </div>
      </div>
    </div>

    <!-- ============ Environment ============ -->
    <div class="col-lg-6">
      <div class="card h-100">
        <div class="card-header"><i class="bi bi-info-circle me-2"></i>System Information</div>
        <div class="card-body">
          <dl class="row small mb-0">
            <dt class="col-6 text-muted fw-normal">Application version</dt>
            <dd class="col-6 text-end fw-semibold"><?= e(APP_VERSION) ?></dd>
            <dt class="col-6 text-muted fw-normal">PHP version</dt>
            <dd class="col-6 text-end fw-semibold"><?= e(PHP_VERSION) ?></dd>
            <dt class="col-6 text-muted fw-normal">Database</dt>
            <dd class="col-6 text-end fw-semibold">
              <?= e((string) db()->getAttribute(PDO::ATTR_SERVER_VERSION)) ?>
            </dd>
            <dt class="col-6 text-muted fw-normal">Server software</dt>
            <dd class="col-6 text-end fw-semibold"><?= e($_SERVER['SERVER_SOFTWARE'] ?? 'CLI') ?></dd>
            <dt class="col-6 text-muted fw-normal">Total entries</dt>
            <dd class="col-6 text-end fw-semibold">
              <?= number_format((int) db()->query('SELECT COUNT(*) FROM entries')->fetchColumn()) ?>
            </dd>
            <dt class="col-6 text-muted fw-normal">Registered users</dt>
            <dd class="col-6 text-end fw-semibold">
              <?= number_format((int) db()->query('SELECT COUNT(*) FROM users')->fetchColumn()) ?>
            </dd>
            <dt class="col-6 text-muted fw-normal">Audit log entries</dt>
            <dd class="col-6 text-end fw-semibold">
              <?= number_format((int) db()->query('SELECT COUNT(*) FROM audit_logs')->fetchColumn()) ?>
            </dd>
            <dt class="col-6 text-muted fw-normal">Notifications</dt>
            <dd class="col-6 text-end fw-semibold">
              <?= number_format((int) db()->query('SELECT COUNT(*) FROM notifications')->fetchColumn()) ?>
            </dd>
          </dl>
        </div>
      </div>
    </div>
  </div>

  <div class="d-flex justify-content-end gap-2 mt-3">
    <button type="reset" class="btn btn-outline-secondary"><i class="bi bi-arrow-counterclockwise me-1"></i>Reset Form</button>
    <button type="submit" class="btn btn-primary" id="saveSettings"><i class="bi bi-save me-1"></i>Save Settings</button>
  </div>
</form>

<?php require __DIR__ . '/includes/footer.php'; ?>
