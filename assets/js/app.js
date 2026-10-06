/* ============================================================
   DisinfEntry — shared front-end runtime
   Toasts, alert popups, notification polling, notification sound,
   sidebar behaviour and small formatting helpers.
   ========================================================== */

const App = (() => {
  const meta = (name, fallback = '') =>
    document.querySelector(`meta[name="${name}"]`)?.content ?? fallback;

  const BASE = meta('base-url');
  const CSRF = meta('csrf-token');
  const THRESHOLD = parseFloat(meta('temp-threshold', '37.8'));
  const REFRESH = Math.max(1, parseInt(meta('refresh-interval', '3'), 10)) * 1000;

  /* ---------------- HTTP ---------------- */

  const urlFor = (path) => `${BASE}/${String(path).replace(/^\//, '')}`;

  async function api(path, { method = 'GET', body = null, params = null } = {}) {
    let target = urlFor(path);
    if (params) target += (target.includes('?') ? '&' : '?') + new URLSearchParams(params);

    const opts = { method, headers: { 'X-Requested-With': 'XMLHttpRequest' } };

    if (body instanceof FormData) {
      body.append('csrf_token', CSRF);
      opts.body = body;
    } else if (body) {
      opts.headers['Content-Type'] = 'application/json';
      opts.headers['X-CSRF-Token'] = CSRF;
      opts.body = JSON.stringify(body);
    }

    const res = await fetch(target, opts);
    let data;
    try {
      data = await res.json();
    } catch {
      throw new Error('The server returned an unexpected response.');
    }
    if (data && data.logged_out) {
      window.location.href = urlFor('login.php?msg=' + encodeURIComponent('Your session expired. Please sign in again.'));
      throw new Error('Session expired');
    }
    if (!res.ok || data.success === false) {
      throw new Error(data.message || `Request failed (${res.status})`);
    }
    return data;
  }

  /* ---------------- Escaping / formatting ---------------- */

  const esc = (v) =>
    String(v ?? '').replace(/[&<>"']/g, (c) =>
      ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));

  const num = (v) => Number(v ?? 0).toLocaleString();

  function tempBadge(temp) {
    const t = parseFloat(temp);
    if (Number.isNaN(t)) return '<span class="badge-temp normal">—</span>';
    const high = t > THRESHOLD;
    return `<span class="badge-temp ${high ? 'high' : 'normal'}">${t.toFixed(1)}&deg;C</span>`;
  }

  function accessBadge(status) {
    return status === 'denied'
      ? '<span class="badge-soft denied"><i class="bi bi-x-circle me-1"></i>Denied</span>'
      : '<span class="badge-soft granted"><i class="bi bi-check-circle me-1"></i>Granted</span>';
  }

  function disinfectionBadge(status) {
    const map = { completed: 'Completed', incomplete: 'Incomplete', skipped: 'Skipped' };
    return `<span class="badge-soft ${esc(status)}">${esc(map[status] || status)}</span>`;
  }

  function timeAgo(iso) {
    const then = new Date(String(iso).replace(' ', 'T'));
    const s = Math.max(0, Math.floor((Date.now() - then.getTime()) / 1000));
    if (s < 60) return `${s}s ago`;
    if (s < 3600) return `${Math.floor(s / 60)}m ago`;
    if (s < 86400) return `${Math.floor(s / 3600)}h ago`;
    return `${Math.floor(s / 86400)}d ago`;
  }

  /* ---------------- Toast ---------------- */

  function toast(message, type = 'info', title = null) {
    const area = document.getElementById('toastArea');
    if (!area) { console.log(`[${type}] ${message}`); return; }

    const palette = {
      success: ['bg-success', 'bi-check-circle-fill', 'Success'],
      danger: ['bg-danger', 'bi-exclamation-octagon-fill', 'Error'],
      warning: ['bg-warning text-dark', 'bi-exclamation-triangle-fill', 'Warning'],
      info: ['bg-primary', 'bi-info-circle-fill', 'Notice'],
    };
    const [bg, icon, defTitle] = palette[type] || palette.info;

    const el = document.createElement('div');
    el.className = 'toast align-items-center border-0';
    el.setAttribute('role', 'alert');
    el.innerHTML = `
      <div class="toast-header ${bg} ${bg.includes('warning') ? '' : 'text-white'} border-0">
        <i class="bi ${icon} me-2"></i>
        <strong class="me-auto">${esc(title || defTitle)}</strong>
        <small>just now</small>
        <button type="button" class="btn-close ${bg.includes('warning') ? '' : 'btn-close-white'} ms-2"
                data-bs-dismiss="toast" aria-label="Close"></button>
      </div>
      <div class="toast-body">${esc(message)}</div>`;

    area.appendChild(el);
    const t = new bootstrap.Toast(el, { delay: 6000 });
    t.show();
    el.addEventListener('hidden.bs.toast', () => el.remove());
  }

  /* ---------------- Alert popup ---------------- */

  function alertPopup({ title, message, temperature = null, severity = 'danger' }) {
    const modalEl = document.getElementById('alertModal');
    if (!modalEl) return;

    const header = document.getElementById('alertModalHeader');
    header.className = `modal-header border-0 text-white bg-${severity === 'warning' ? 'warning' : 'danger'}`;
    document.getElementById('alertModalTitle').textContent = title;
    document.getElementById('alertModalBody').textContent = message;

    const tempEl = document.getElementById('alertModalTemp');
    if (temperature !== null && temperature !== undefined) {
      tempEl.textContent = `${parseFloat(temperature).toFixed(1)}°C`;
      tempEl.className = 'alert-temp display-4 fw-bold mb-2 text-danger';
      tempEl.style.display = '';
    } else {
      tempEl.style.display = 'none';
    }

    bootstrap.Modal.getOrCreateInstance(modalEl).show();
  }

  /* ---------------- Notification sound (synthesised) ---------------- */

  let audioCtx = null;
  let soundEnabled = localStorage.getItem('disinf_sound') !== 'off';

  function unlockAudio() {
    if (!audioCtx) {
      const Ctx = window.AudioContext || window.webkitAudioContext;
      if (Ctx) audioCtx = new Ctx();
    }
    if (audioCtx?.state === 'suspended') audioCtx.resume();
  }

  /**
   * Two-tone chime for normal events, urgent triple beep for alerts.
   * Synthesised with the Web Audio API so no binary asset is required.
   */
  function playSound(kind = 'info') {
    if (!soundEnabled) return;
    unlockAudio();
    if (!audioCtx) return;

    const seq = kind === 'alert'
      ? [[880, 0], [880, 0.18], [1100, 0.36]]
      : [[660, 0], [990, 0.13]];

    seq.forEach(([freq, at]) => {
      const osc = audioCtx.createOscillator();
      const gain = audioCtx.createGain();
      const t0 = audioCtx.currentTime + at;
      osc.type = kind === 'alert' ? 'square' : 'sine';
      osc.frequency.setValueAtTime(freq, t0);
      gain.gain.setValueAtTime(0.0001, t0);
      gain.gain.exponentialRampToValueAtTime(kind === 'alert' ? 0.22 : 0.14, t0 + 0.02);
      gain.gain.exponentialRampToValueAtTime(0.0001, t0 + 0.16);
      osc.connect(gain).connect(audioCtx.destination);
      osc.start(t0);
      osc.stop(t0 + 0.2);
    });
  }

  function toggleSound(on) {
    soundEnabled = on;
    localStorage.setItem('disinf_sound', on ? 'on' : 'off');
    return soundEnabled;
  }

  const isSoundOn = () => soundEnabled;

  /* ---------------- Notification centre ---------------- */

  const NOTIF_ICONS = {
    high_temperature: ['bi-thermometer-high', 'bg-danger-subtle text-danger'],
    denied_entry: ['bi-shield-x', 'bg-danger-subtle text-danger'],
    device_offline: ['bi-wifi-off', 'bg-warning-subtle text-warning'],
    low_disinfectant: ['bi-droplet-half', 'bg-warning-subtle text-warning'],
    system: ['bi-info-circle', 'bg-primary-subtle text-primary'],
  };

  let lastNotifId = 0;
  let notifPrimed = false;

  function renderNotifications(items) {
    const list = document.getElementById('notifList');
    if (!list) return;

    if (!items.length) {
      list.innerHTML = '<div class="empty-state py-4"><i class="bi bi-bell-slash"></i>No notifications</div>';
      return;
    }

    list.innerHTML = items.map((n) => {
      const [icon, cls] = NOTIF_ICONS[n.type] || NOTIF_ICONS.system;
      return `
        <div class="notif-item ${Number(n.is_read) ? '' : 'unread'}">
          <div class="notif-icon ${cls}"><i class="bi ${icon}"></i></div>
          <div class="flex-grow-1 min-w-0">
            <div class="fw-semibold">${esc(n.title)}</div>
            <div class="text-muted">${esc(n.message)}</div>
            <div class="notif-time">${timeAgo(n.created_at)}</div>
          </div>
        </div>`;
    }).join('');
  }

  function setBadge(count) {
    [document.getElementById('notifBadge'), document.getElementById('navNotifBadge')].forEach((el) => {
      if (!el) return;
      el.textContent = count > 99 ? '99+' : count;
      el.classList.toggle('d-none', count === 0);
    });
  }

  async function pollNotifications() {
    try {
      const data = await api('api/notifications.php', { params: { action: 'recent' } });
      setBadge(data.unread ?? 0);
      renderNotifications(data.items ?? []);

      // Announce anything that arrived since the last poll (skip the first run).
      const fresh = (data.items ?? []).filter((n) => Number(n.id) > lastNotifId);
      if (fresh.length) lastNotifId = Math.max(...fresh.map((n) => Number(n.id)));

      if (notifPrimed && fresh.length) {
        const critical = fresh.find((n) => n.severity === 'danger');
        playSound(critical ? 'alert' : 'info');
        fresh.slice(0, 3).forEach((n) =>
          toast(n.message, n.severity === 'danger' ? 'danger' : n.severity === 'warning' ? 'warning' : 'info', n.title));
      }
      notifPrimed = true;
    } catch {
      /* transient network/server error — next poll retries */
    }
  }

  /* ---------------- Device status pill ---------------- */

  async function pollDeviceStatus() {
    const pill = document.getElementById('deviceStatusPill');
    if (!pill) return;
    try {
      const data = await api('api/device_status.php', { params: { action: 'status' } });
      const d = (data.devices || [])[0];
      if (!d) {
        pill.innerHTML = '<span class="status-dot bg-secondary"></span><span class="small">No booth registered</span>';
        return;
      }
      const dot = d.online ? 'bg-success' : 'bg-danger';
      const label = d.online ? 'Booth online' : 'Booth offline';

      // null means no sensor has reported a level - which is every booth on the
      // current firmware. Interpolating it raw printed a bare "%".
      const level = d.disinfectant_level == null ? 'no level' : `${d.disinfectant_level}%`;

      pill.innerHTML =
        `<span class="status-dot ${dot}"></span>` +
        `<span class="small">${esc(label)}</span>` +
        `<span class="small ms-auto text-white-50">${esc(level)}</span>`;
      pill.title = `${d.device_name} — last seen ${d.last_seen ? timeAgo(d.last_seen) : 'never'}`;
    } catch {
      /* ignore */
    }
  }

  /* ---------------- Sidebar / chrome ---------------- */

  function initChrome() {
    const sidebar = document.getElementById('sidebar');
    const backdrop = document.getElementById('sidebarBackdrop');
    const open = () => { sidebar?.classList.add('show'); backdrop?.classList.add('show'); };
    const close = () => { sidebar?.classList.remove('show'); backdrop?.classList.remove('show'); };

    document.getElementById('sidebarToggle')?.addEventListener('click', open);
    document.querySelector('.sidebar-close')?.addEventListener('click', close);
    backdrop?.addEventListener('click', close);

    const clock = document.getElementById('topbarClock');
    if (clock) {
      const tick = () => {
        clock.textContent = new Date().toLocaleString(undefined, {
          weekday: 'short', month: 'short', day: 'numeric',
          hour: '2-digit', minute: '2-digit', second: '2-digit',
        });
      };
      tick();
      setInterval(tick, 1000);
    }

    document.getElementById('markAllRead')?.addEventListener('click', async (ev) => {
      ev.preventDefault();
      try {
        await api('api/notifications.php', { method: 'POST', body: { action: 'mark_all_read' } });
        setBadge(0);
        pollNotifications();
      } catch (err) { toast(err.message, 'danger'); }
    });

    // Browsers require a gesture before audio may play.
    ['click', 'keydown'].forEach((evt) =>
      document.addEventListener(evt, unlockAudio, { once: true }));

    // Confirm-on-click helper: any element with data-confirm.
    document.addEventListener('click', (ev) => {
      const el = ev.target.closest('[data-confirm]');
      if (el && !window.confirm(el.dataset.confirm)) {
        ev.preventDefault();
        ev.stopPropagation();
      }
    }, true);
  }

  /* ---------------- Boot ---------------- */

  document.addEventListener('DOMContentLoaded', () => {
    initChrome();
    if (document.getElementById('notifList')) {
      pollNotifications();
      setInterval(pollNotifications, 10000);
    }
    if (document.getElementById('deviceStatusPill')) {
      pollDeviceStatus();
      setInterval(pollDeviceStatus, 15000);
    }
  });

  return {
    api, urlFor, esc, num, toast, alertPopup, playSound, toggleSound, isSoundOn,
    tempBadge, accessBadge, disinfectionBadge, timeAgo,
    THRESHOLD, REFRESH, CSRF, BASE,
    refreshNotifications: pollNotifications,
  };
})();

window.App = App;
