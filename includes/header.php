<?php
/**
 * Page shell: <head>, sidebar, topbar. Set $PAGE_TITLE and $ACTIVE before including.
 * Pair with includes/footer.php.
 */

declare(strict_types=1);

require_once __DIR__ . '/auth.php';

$PAGE_TITLE = $PAGE_TITLE ?? 'Dashboard';
$ACTIVE     = $ACTIVE     ?? '';
$me         = current_user();
$sysName    = setting('system_name', 'DisinfEntry');
$farmName   = setting('farm_name', 'Poultry Farm');
$logo       = setting('logo', '');
$navItems   = [
    ['key' => 'dashboard',  'label' => 'Dashboard',        'icon' => 'grid',        'href' => 'index.php',      'admin' => false],
    ['key' => 'live',       'label' => 'Live Monitoring',  'icon' => 'broadcast',   'href' => 'live.php',       'admin' => false],
    ['key' => 'entries',    'label' => 'Entry Monitoring', 'icon' => 'list-check',  'href' => 'entries.php',    'admin' => false],
    ['key' => 'reports',    'label' => 'Reports',          'icon' => 'file-earmark-bar-graph', 'href' => 'reports.php', 'admin' => false],
    ['key' => 'notifs',     'label' => 'Notifications',    'icon' => 'bell',        'href' => 'notifications.php', 'admin' => false],
    ['key' => 'settings',   'label' => 'System Settings',  'icon' => 'gear',        'href' => 'settings.php',   'admin' => true],
    ['key' => 'audit',      'label' => 'Audit Logs',       'icon' => 'clock-history', 'href' => 'audit_logs.php', 'admin' => true],
];
?>
<!DOCTYPE html>
<html lang="en" data-bs-theme="light">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($PAGE_TITLE) ?> · <?= e($sysName) ?></title>
<meta name="csrf-token" content="<?= e(csrf_token()) ?>">
<meta name="base-url" content="<?= e(BASE_URL) ?>">
<meta name="temp-threshold" content="<?= e(setting('temperature_threshold', '37.8')) ?>">
<meta name="refresh-interval" content="<?= e(setting('refresh_interval', '3')) ?>">
<link rel="icon" href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 100 100'><text y='.9em' font-size='90'>&#128737;</text></svg>">
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
<link href="https://cdn.datatables.net/1.13.8/css/dataTables.bootstrap5.min.css" rel="stylesheet">
<link href="<?= asset_url('assets/css/style.css') ?>" rel="stylesheet">
</head>
<body>

<div class="app-shell">

  <!-- ============ Sidebar ============ -->
  <aside class="sidebar" id="sidebar">
    <div class="sidebar-brand">
      <?php if ($logo !== '' && file_exists(APP_ROOT . '/' . $logo)): ?>
        <img src="<?= url($logo) ?>" alt="Logo" class="brand-logo">
      <?php else: ?>
        <span class="brand-mark"><i class="bi bi-shield-check"></i></span>
      <?php endif; ?>
      <div class="brand-text">
        <span class="brand-name"><?= e($sysName) ?></span>
        <span class="brand-sub"><?= e($farmName) ?></span>
      </div>
      <button class="btn btn-sm sidebar-close d-lg-none" type="button" aria-label="Close menu">
        <i class="bi bi-x-lg"></i>
      </button>
    </div>

    <nav class="sidebar-nav">
      <div class="nav-section">Main</div>
      <?php foreach ($navItems as $i => $item):
          if ($item['admin'] && !is_admin()) { continue; }
          if ($i === 5) { echo '<div class="nav-section">Administration</div>'; }
      ?>
        <a class="nav-link<?= $ACTIVE === $item['key'] ? ' active' : '' ?>" href="<?= url($item['href']) ?>">
          <i class="bi bi-<?= e($item['icon']) ?>"></i>
          <span><?= e($item['label']) ?></span>
          <?php if ($item['key'] === 'notifs'): ?>
            <span class="badge bg-danger rounded-pill ms-auto nav-badge d-none" id="navNotifBadge">0</span>
          <?php endif; ?>
        </a>
      <?php endforeach; ?>
    </nav>

    <div class="sidebar-footer">
      <div class="device-pill" id="deviceStatusPill">
        <span class="status-dot bg-secondary"></span>
        <span class="small">Checking booth…</span>
      </div>
      <div class="text-white-50 small mt-2">v<?= e(APP_VERSION) ?></div>
    </div>
  </aside>
  <div class="sidebar-backdrop" id="sidebarBackdrop"></div>

  <!-- ============ Main ============ -->
  <div class="main-area">

    <header class="topbar">
      <button class="btn btn-light btn-icon d-lg-none" id="sidebarToggle" type="button" aria-label="Open menu">
        <i class="bi bi-list"></i>
      </button>

      <div class="topbar-title">
        <h1><?= e($PAGE_TITLE) ?></h1>
        <span class="text-muted small d-none d-md-inline" id="topbarClock"></span>
      </div>

      <div class="topbar-actions">
        <!-- Notification bell -->
        <div class="dropdown">
          <button class="btn btn-light btn-icon position-relative" data-bs-toggle="dropdown"
                  data-bs-auto-close="outside" aria-expanded="false" title="Notifications">
            <i class="bi bi-bell"></i>
            <span class="position-absolute top-0 start-100 translate-middle badge rounded-pill bg-danger d-none"
                  id="notifBadge">0</span>
          </button>
          <div class="dropdown-menu dropdown-menu-end notif-dropdown p-0">
            <div class="d-flex align-items-center justify-content-between px-3 py-2 border-bottom">
              <strong class="small">Notifications</strong>
              <button class="btn btn-sm btn-link p-0 text-decoration-none" id="markAllRead">Mark all read</button>
            </div>
            <div id="notifList" class="notif-list">
              <div class="text-center text-muted py-4 small">Loading…</div>
            </div>
            <a class="dropdown-item text-center small border-top py-2" href="<?= url('notifications.php') ?>">
              View all notifications
            </a>
          </div>
        </div>

        <!-- User menu -->
        <div class="dropdown">
          <button class="btn btn-light user-chip" data-bs-toggle="dropdown" aria-expanded="false">
            <span class="avatar"><?= e(strtoupper(substr((string) $me['full_name'], 0, 1))) ?></span>
            <span class="d-none d-sm-inline text-start">
              <span class="d-block fw-semibold lh-1"><?= e($me['full_name']) ?></span>
              <span class="d-block text-muted" style="font-size:.72rem"><?= e(role_label($me['role'])) ?></span>
            </span>
            <i class="bi bi-chevron-down small ms-1"></i>
          </button>
          <ul class="dropdown-menu dropdown-menu-end">
            <li><h6 class="dropdown-header"><?= e($me['employee_id']) ?></h6></li>
            <li><a class="dropdown-item" href="<?= url('profile.php') ?>"><i class="bi bi-person me-2"></i>My Profile</a></li>
            <li><a class="dropdown-item" href="<?= url('profile.php#password') ?>"><i class="bi bi-key me-2"></i>Change Password</a></li>
            <li><hr class="dropdown-divider"></li>
            <li><a class="dropdown-item text-danger" href="<?= url('logout.php') ?>"><i class="bi bi-box-arrow-right me-2"></i>Logout</a></li>
          </ul>
        </div>
      </div>
    </header>

    <main class="content">
      <?php if (must_change_password()): ?>
        <div class="alert alert-warning d-flex align-items-center">
          <i class="bi bi-key-fill me-2"></i>
          <div>You signed in with a default password. Change it below (My Profile &rarr; Change Password)
          before using the rest of the system.</div>
        </div>
      <?php endif; ?>
      <?php if (is_admin() && setting('api_key', '') === 'DISINF-ESP32-2024-CHANGE-ME'): ?>
        <div class="alert alert-warning d-flex align-items-center">
          <i class="bi bi-shield-exclamation me-2"></i>
          <div>The booth API key is still the shipped default, which is public. Regenerate it in
          <a href="<?= url('settings.php') ?>" class="alert-link">System Settings</a> and update the
          booth firmware.</div>
        </div>
      <?php endif; ?>
