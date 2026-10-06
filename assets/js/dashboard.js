/* ============================================================
   Dashboard — KPI cards, Chart.js visuals and the live activity table.
   ========================================================== */

(() => {
  const charts = {};
  let chartData = null;
  let lastEntryId = 0;
  let primed = false;

  const PALETTE = {
    granted: '#0d7a5f',
    grantedSoft: 'rgba(13, 122, 95, .16)',
    denied: '#dc3545',
    deniedSoft: 'rgba(220, 53, 69, .16)',
    neutral: '#94a3b8',
    accent: '#2563eb',
    accentSoft: 'rgba(37, 99, 235, .14)',
    warn: '#ea8a00',
  };

  Chart.defaults.font.family = '"Segoe UI", system-ui, sans-serif';
  Chart.defaults.font.size = 11;
  Chart.defaults.color = '#64748b';
  Chart.defaults.plugins.legend.labels.usePointStyle = true;
  Chart.defaults.plugins.legend.labels.boxWidth = 8;
  Chart.defaults.plugins.legend.labels.padding = 14;

  const gridScale = (extra = {}) => ({
    grid: { color: '#eef2f7', drawBorder: false },
    ticks: { precision: 0 },
    ...extra,
  });

  /* ---------------- KPI cards ---------------- */

  function trendHtml(t, invert = false) {
    if (!t || t.dir === 'flat') return '<span class="text-muted">No change vs yesterday</span>';
    const up = t.dir === 'up';
    const good = invert ? !up : up;
    const cls = good ? 'text-success' : 'text-danger';
    const arrow = up ? 'bi-arrow-up-short' : 'bi-arrow-down-short';
    return `<span class="${cls}"><i class="bi ${arrow}"></i>${t.pct}%</span> <span class="text-muted">vs yesterday</span>`;
  }

  function renderStats(s) {
    document.getElementById('kpiTotal').textContent = App.num(s.total_today);
    document.getElementById('kpiGranted').textContent = App.num(s.granted);
    document.getElementById('kpiDenied').textContent = App.num(s.denied);
    document.getElementById('kpiHighTemp').textContent = App.num(s.high_temp);
    document.getElementById('kpiUsers').textContent = App.num(s.active_users);

    document.getElementById('kpiTotalSub').innerHTML = trendHtml(s.trend.total);
    document.getElementById('kpiGrantedSub').innerHTML =
      `<span class="text-muted">${s.pass_rate}% pass rate today</span>`;
    document.getElementById('kpiDeniedSub').innerHTML = trendHtml(s.trend.denied, true);
    document.getElementById('kpiHighTempSub').innerHTML =
      s.avg_temp !== null
        ? `<span class="text-muted">Avg ${s.avg_temp}&deg;C today</span>`
        : '<span class="text-muted">No readings yet</span>';
    document.getElementById('kpiUsersSub').innerHTML =
      `<span class="text-muted">${s.total_users} registered</span>`;

    document.getElementById('pdRate').textContent = `${s.pass_rate}%`;
  }

  /* ---------------- Charts ---------------- */

  function volumeConfig(range) {
    const src = chartData[range];
    const isDaily = range === 'daily';
    const labels = src.labels;
    const granted = isDaily || range === 'weekly' ? src.granted : src.total;
    const denied = src.denied;

    return {
      type: isDaily ? 'line' : 'bar',
      data: {
        labels,
        datasets: [
          {
            label: range === 'monthly' ? 'Total entries' : 'Granted',
            data: granted,
            borderColor: range === 'monthly' ? PALETTE.accent : PALETTE.granted,
            backgroundColor: range === 'monthly' ? PALETTE.accentSoft : (isDaily ? PALETTE.grantedSoft : PALETTE.granted),
            borderWidth: 2,
            fill: isDaily,
            tension: 0.35,
            pointRadius: isDaily ? 0 : 3,
            pointHoverRadius: 5,
            borderRadius: 5,
            maxBarThickness: 34,
          },
          {
            label: 'Denied',
            data: denied,
            borderColor: PALETTE.denied,
            backgroundColor: isDaily ? PALETTE.deniedSoft : PALETTE.denied,
            borderWidth: 2,
            fill: isDaily,
            tension: 0.35,
            pointRadius: isDaily ? 0 : 3,
            pointHoverRadius: 5,
            borderRadius: 5,
            maxBarThickness: 34,
          },
        ],
      },
      options: {
        responsive: true,
        maintainAspectRatio: false,
        interaction: { mode: 'index', intersect: false },
        plugins: {
          legend: { position: 'top', align: 'end' },
          tooltip: { padding: 10, cornerRadius: 8 },
        },
        scales: { x: gridScale({ grid: { display: false } }), y: gridScale({ beginAtZero: true }) },
      },
    };
  }

  function drawVolume(range) {
    charts.volume?.destroy();
    charts.volume = new Chart(document.getElementById('volumeChart'), volumeConfig(range));
  }

  function drawPassDenied() {
    const pd = chartData.pass_denied;
    document.getElementById('pdGranted').textContent = App.num(pd.granted);
    document.getElementById('pdDenied').textContent = App.num(pd.denied);

    charts.passDenied?.destroy();
    charts.passDenied = new Chart(document.getElementById('passDeniedChart'), {
      type: 'doughnut',
      data: {
        labels: ['Granted', 'Denied'],
        datasets: [{
          data: [pd.granted, pd.denied],
          backgroundColor: [PALETTE.granted, PALETTE.denied],
          borderWidth: 0,
          hoverOffset: 6,
        }],
      },
      options: {
        responsive: true,
        maintainAspectRatio: false,
        cutout: '64%',
        plugins: {
          legend: { position: 'bottom' },
          tooltip: {
            callbacks: {
              label: (ctx) => {
                const total = ctx.dataset.data.reduce((a, b) => a + b, 0) || 1;
                return ` ${ctx.label}: ${ctx.parsed} (${((ctx.parsed / total) * 100).toFixed(1)}%)`;
              },
            },
          },
        },
      },
    });
  }

  function drawTemp() {
    const t = chartData.temp_dist;
    charts.temp?.destroy();
    charts.temp = new Chart(document.getElementById('tempChart'), {
      type: 'bar',
      data: {
        labels: t.labels,
        datasets: [{
          label: 'Readings',
          data: t.counts,
          backgroundColor: t.high.map((h) => (h ? PALETTE.denied : PALETTE.granted)),
          borderRadius: 6,
          maxBarThickness: 44,
        }],
      },
      options: {
        responsive: true,
        maintainAspectRatio: false,
        plugins: {
          legend: { display: false },
          tooltip: {
            callbacks: {
              afterLabel: (ctx) => (t.high[ctx.dataIndex] ? 'Above threshold — access denied' : 'Within safe range'),
            },
          },
        },
        scales: { x: gridScale({ grid: { display: false } }), y: gridScale({ beginAtZero: true }) },
      },
    });
  }

  function drawMonthly() {
    const m = chartData.monthly;
    charts.monthly?.destroy();
    charts.monthly = new Chart(document.getElementById('monthlyChart'), {
      type: 'line',
      data: {
        labels: m.labels,
        datasets: [
          {
            label: 'Total entries',
            data: m.total,
            borderColor: PALETTE.accent,
            backgroundColor: PALETTE.accentSoft,
            fill: true,
            tension: 0.35,
            pointRadius: 2,
            borderWidth: 2,
          },
          {
            label: 'Denied',
            data: m.denied,
            borderColor: PALETTE.denied,
            backgroundColor: 'transparent',
            borderDash: [5, 4],
            tension: 0.35,
            pointRadius: 2,
            borderWidth: 2,
          },
        ],
      },
      options: {
        responsive: true,
        maintainAspectRatio: false,
        interaction: { mode: 'index', intersect: false },
        plugins: { legend: { position: 'top', align: 'end' } },
        scales: { x: gridScale({ grid: { display: false } }), y: gridScale({ beginAtZero: true }) },
      },
    });
  }

  /* ---------------- Recent activity ---------------- */

  const recentCache = new Map();
  const entryCode = (id) => `ENT-${String(id).padStart(6, '0')}`;

  function renderRecent(rows) {
    const body = document.getElementById('recentBody');

    if (!rows.length) {
      body.innerHTML =
        '<tr><td colspan="6"><div class="empty-state"><i class="bi bi-inbox"></i>No entries recorded yet.<br>' +
        '<span class="small">Booth records will appear here as they arrive.</span></div></td></tr>';
      return;
    }

    body.innerHTML = rows.map((r) => {
      recentCache.set(r.id, r);
      const isNew = primed && r.id > lastEntryId;
      return `
        <tr class="${isNew ? 'feed-row' : ''}">
          <td>
            <div class="fw-semibold">${entryCode(r.id)}</div>
          </td>
          <td class="text-center">${App.tempBadge(r.temperature)}</td>
          <td>
            <div>${App.esc(r.time)}</div>
            <div class="text-muted small">${App.esc(r.date)}</div>
          </td>
          <td class="text-center">${App.disinfectionBadge(r.disinfection)}</td>
          <td class="text-center">${App.accessBadge(r.status)}</td>
          <td class="text-end">
            <button class="btn btn-sm btn-outline-secondary view-entry" data-id="${r.id}" title="View details">
              <i class="bi bi-eye"></i>
            </button>
          </td>
        </tr>`;
    }).join('');

    const newest = Math.max(...rows.map((r) => r.id));

    if (primed && newest > lastEntryId) {
      const fresh = rows.filter((r) => r.id > lastEntryId);
      const alert = fresh.find((r) => r.status === 'denied' || r.temperature > App.THRESHOLD);
      App.playSound(alert ? 'alert' : 'info');

      if (alert) {
        App.alertPopup({
          title: alert.temperature > App.THRESHOLD ? 'High Temperature Detected' : 'Entry Denied',
          message: `${entryCode(alert.id)} —${alert.remarks || 'Access denied at the booth.'}`,
          temperature: alert.temperature > App.THRESHOLD ? alert.temperature : null,
        });
      } else {
        fresh.forEach((r) =>
          App.toast(`${entryCode(r.id)} entered at ${r.time} (${r.temperature.toFixed(1)}°C)`, 'success', 'New Entry'));
      }
    }

    lastEntryId = Math.max(lastEntryId, newest);
    primed = true;
  }

  document.addEventListener('click', (ev) => {
    const btn = ev.target.closest('.view-entry');
    if (!btn) return;

    const r = recentCache.get(Number(btn.dataset.id));
    if (!r) return;

    document.getElementById('entryModalBody').innerHTML = `
      <dl class="row mb-0 small">
        <dt class="col-5 text-muted fw-normal">Entry ID</dt>
        <dd class="col-7 fw-semibold">ENT-${String(r.id).padStart(6, '0')}</dd>
        <dt class="col-5 text-muted fw-normal">Temperature</dt><dd class="col-7">${App.tempBadge(r.temperature)}</dd>
        <dt class="col-5 text-muted fw-normal">Entry Date</dt><dd class="col-7">${App.esc(r.date)}</dd>
        <dt class="col-5 text-muted fw-normal">Entry Time</dt><dd class="col-7">${App.esc(r.time)}</dd>
        <dt class="col-5 text-muted fw-normal">Disinfection</dt><dd class="col-7">${App.disinfectionBadge(r.disinfection)}</dd>
        <dt class="col-5 text-muted fw-normal">Misting</dt><dd class="col-7">${App.esc(String(r.misting).toUpperCase())}</dd>
        <dt class="col-5 text-muted fw-normal">Access Status</dt><dd class="col-7">${App.accessBadge(r.status)}</dd>
        <dt class="col-5 text-muted fw-normal">Remarks</dt><dd class="col-7">${App.esc(r.remarks || '—')}</dd>
      </dl>`;
    bootstrap.Modal.getOrCreateInstance(document.getElementById('entryModal')).show();
  });

  /* ---------------- Loaders ---------------- */

  async function loadAll() {
    try {
      const d = await App.api('api/dashboard.php', { params: { action: 'all', limit: 10 } });
      document.getElementById('thresholdLabel').textContent = d.threshold;
      renderStats(d.stats);
      chartData = d.charts;

      const active = document.querySelector('#volumeRange .btn.active')?.dataset.range || 'daily';
      drawVolume(active);
      drawPassDenied();
      drawTemp();
      drawMonthly();
      renderRecent(d.recent);
    } catch (err) {
      App.toast(err.message, 'danger');
    }
  }

  /** Lightweight refresh: stats + recent rows only, so charts do not flicker. */
  async function refreshLive() {
    try {
      const d = await App.api('api/dashboard.php', { params: { action: 'stats' } });
      renderStats(d.stats);
      const r = await App.api('api/dashboard.php', { params: { action: 'recent', limit: 10 } });
      renderRecent(r.recent);
      document.getElementById('liveIndicator').className =
        'badge bg-success-subtle text-success border border-success-subtle';
    } catch {
      document.getElementById('liveIndicator').className =
        'badge bg-secondary-subtle text-secondary border border-secondary-subtle';
    }
  }

  document.addEventListener('DOMContentLoaded', () => {
    loadAll();

    document.getElementById('volumeRange')?.addEventListener('click', (ev) => {
      const btn = ev.target.closest('button[data-range]');
      if (!btn || !chartData) return;
      document.querySelectorAll('#volumeRange .btn').forEach((b) => b.classList.remove('active'));
      btn.classList.add('active');
      drawVolume(btn.dataset.range);
    });

    setInterval(refreshLive, Math.max(3000, App.REFRESH));
    setInterval(loadAll, 120000); // full chart refresh every 2 minutes
  });
})();
