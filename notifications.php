<?php
/**
 * Notification centre.
 */

declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
require_login();

$PAGE_TITLE   = 'Notifications';
$ACTIVE       = 'notifs';
$PAGE_SCRIPTS = ['assets/js/notifications.js'];

require __DIR__ . '/includes/header.php';
?>

<div class="row g-3 mb-3">
  <div class="col-6 col-lg-3">
    <div class="stat-card acc-red">
      <div class="stat-icon"><i class="bi bi-thermometer-high"></i></div>
      <div><div class="stat-value" id="nHighTemp">0</div><div class="stat-label">High Temperature</div></div>
    </div>
  </div>
  <div class="col-6 col-lg-3">
    <div class="stat-card acc-orange">
      <div class="stat-icon"><i class="bi bi-shield-x"></i></div>
      <div><div class="stat-value" id="nDenied">0</div><div class="stat-label">Denied Entries</div></div>
    </div>
  </div>
  <div class="col-6 col-lg-3">
    <div class="stat-card acc-purple">
      <div class="stat-icon"><i class="bi bi-wifi-off"></i></div>
      <div><div class="stat-value" id="nOffline">0</div><div class="stat-label">Booth Offline</div></div>
    </div>
  </div>
  <div class="col-6 col-lg-3">
    <div class="stat-card acc-blue">
      <div class="stat-icon"><i class="bi bi-droplet-half"></i></div>
      <div><div class="stat-value" id="nDisinfectant">0</div><div class="stat-label">Low Disinfectant</div></div>
    </div>
  </div>
</div>

<div class="card">
  <div class="card-header d-flex flex-wrap align-items-center justify-content-between gap-2">
    <span><i class="bi bi-bell me-2"></i>All Notifications <span class="badge bg-danger ms-1 d-none" id="pageUnread">0</span></span>
    <div class="d-flex flex-wrap gap-2">
      <select class="form-select form-select-sm" id="fType" style="width:auto">
        <option value="">All types</option>
        <option value="high_temperature">High Temperature</option>
        <option value="denied_entry">Denied Entry</option>
        <option value="device_offline">Booth Offline</option>
        <option value="low_disinfectant">Low Disinfectant</option>
        <option value="system">System</option>
      </select>
      <select class="form-select form-select-sm" id="fRead" style="width:auto">
        <option value="">All</option>
        <option value="0">Unread only</option>
        <option value="1">Read only</option>
      </select>
      <button class="btn btn-sm btn-outline-primary" id="btnMarkAll">
        <i class="bi bi-check2-all me-1"></i>Mark all read
      </button>
      <?php if (is_admin()): ?>
        <button class="btn btn-sm btn-outline-danger" id="btnClearRead">
          <i class="bi bi-trash me-1"></i>Clear read
        </button>
      <?php endif; ?>
    </div>
  </div>
  <div class="list-group list-group-flush" id="notifPageList">
    <div class="text-center text-muted py-5">Loading…</div>
  </div>
</div>

<?php require __DIR__ . '/includes/footer.php'; ?>
