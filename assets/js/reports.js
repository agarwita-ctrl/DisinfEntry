/* ============================================================
   Reports — generates the summary, charts and detail table.
   ========================================================== */

(() => {
  const charts = {};
  let table = null;

  const C = {
    green: '#0d7a5f',
    greenSoft: 'rgba(13, 122, 95, .15)',
    red: '#dc3545',
    orange: '#ea8a00',
    blue: '#2563eb',
    blueSoft: 'rgba(37, 99, 235, .14)',
    grey: '#94a3b8',
  };

  Chart.defaults.font.family = '"Segoe UI", system-ui, sans-serif';
  Chart.defaults.font.size = 11;
  Chart.defaults.color = '#64748b';
  Chart.defaults.plugins.legend.labels.usePointStyle = true;
  Chart.defaults.plugins.legend.labels.boxWidth = 8;

  const params = () => {
    const p = { preset: $('#preset').val() };
    if (p.preset === 'custom') {
      p.from = $('#from').val();
      p.to = $('#to').val();
    }
    return p;
  };

  /* ---------------- Rendering ---------------- */

  function renderSummary(d) {
    const s = d.summary;
    $('#rTitle').text(d.title);
    $('#rSubtitle').text(
      `Period: ${d.range.start} to ${d.range.end}  ·  ${d.range.days} day(s)  ·  Threshold ${d.threshold}°C`
    );
    $('#rGenerated').text(`Generated ${d.generated} by ${d.by}`);
    $('#rSystem').text(d.system);
    $('#rFarm').text(d.farm);

    $('#rTotal').text(App.num(s.total));
    $('#rPassed').text(App.num(s.passed));
    $('#rDenied').text(App.num(s.denied));
    $('#rHigh').text(App.num(s.high_temp));
    $('#rAvg').html(s.avg_temp !== null ? `${s.avg_temp}&deg;C` : '—');
    $('#rRate').text(`${s.pass_rate}%`);
    $('#rDailyAvg').text(s.daily_avg);
    $('#rRowCount').text(`${App.num(d.rows.length)} record(s) listed`);
  }

  function drawTrend(t) {
    charts.trend?.destroy();
    charts.trend = new Chart(document.getElementById('trendChart'), {
      type: 'bar',
      data: {
        labels: t.labels,
        datasets: [
          {
            label: 'Passed',
            data: t.passed,
            backgroundColor: C.green,
            borderRadius: 5,
            maxBarThickness: 30,
            stack: 'access',
          },
          {
            label: 'Denied',
            data: t.denied,
            backgroundColor: C.red,
            borderRadius: 5,
            maxBarThickness: 30,
            stack: 'access',
          },
          {
            label: 'Average temperature',
            data: t.avg_temp,
            type: 'line',
            borderColor: C.orange,
            backgroundColor: 'transparent',
            borderWidth: 2,
            tension: 0.35,
            pointRadius: 2,
            yAxisID: 'y1',
          },
        ],
      },
      options: {
        responsive: true,
        maintainAspectRatio: false,
        interaction: { mode: 'index', intersect: false },
        plugins: { legend: { position: 'top', align: 'end' } },
        scales: {
          x: { stacked: true, grid: { display: false } },
          y: { stacked: true, beginAtZero: true, grid: { color: '#eef2f7' }, ticks: { precision: 0 } },
          y1: {
            position: 'right',
            beginAtZero: false,
            grid: { display: false },
            title: { display: true, text: '°C' },
          },
        },
      },
    });
  }

  function drawDist(dist) {
    charts.dist?.destroy();
    charts.dist = new Chart(document.getElementById('distChart'), {
      type: 'bar',
      data: {
        labels: dist.labels,
        datasets: [{
          label: 'Readings',
          data: dist.counts,
          backgroundColor: dist.labels.map((l) => (l.includes('38.0') ? C.red : C.green)),
          borderRadius: 5,
        }],
      },
      options: {
        indexAxis: 'y',
        responsive: true,
        maintainAspectRatio: false,
        plugins: { legend: { display: false } },
        scales: {
          x: { beginAtZero: true, grid: { color: '#eef2f7' }, ticks: { precision: 0 } },
          y: { grid: { display: false } },
        },
      },
    });
  }

  function drawDenials(d) {
    const total = d.high_temperature + d.skipped_disinfection + d.other;
    charts.denial?.destroy();
    charts.denial = new Chart(document.getElementById('denialChart'), {
      type: 'doughnut',
      data: {
        labels: ['High temperature', 'Skipped disinfection', 'Other'],
        datasets: [{
          data: total ? [d.high_temperature, d.skipped_disinfection, d.other] : [0, 0, 1],
          backgroundColor: total ? [C.red, C.orange, C.grey] : ['#e2e8f0'],
          borderWidth: 0,
        }],
      },
      options: {
        responsive: true,
        maintainAspectRatio: false,
        cutout: '60%',
        plugins: {
          legend: { position: 'bottom' },
          tooltip: { enabled: total > 0 },
        },
      },
    });
  }

  function renderTable(rows) {
    if (table) {
      table.clear().rows.add(rows).draw();
      return;
    }
    table = $('#reportTable').DataTable({
      data: rows,
      order: [[2, 'desc']],
      pageLength: 25,
      lengthMenu: [[10, 25, 50, 100, -1], [10, 25, 50, 100, 'All']],
      language: { search: '', searchPlaceholder: 'Search…', emptyTable: 'No entries in this period.' },
      columns: [
        { data: 'entry_code', render: (v) => `<span class="text-muted small">${App.esc(v)}</span>` },
        { data: 'temperature', className: 'text-center', render: (v) => App.tempBadge(v) },
        { data: 'date' },
        { data: 'time' },
        { data: 'disinfection', className: 'text-center', render: (v) => App.disinfectionBadge(v) },
        { data: 'status', className: 'text-center', render: (v) => App.accessBadge(v) },
        { data: 'remarks', render: (v) => `<span class="small text-muted">${App.esc(v || '—')}</span>` },
      ],
    });
  }

  /* ---------------- Generate ---------------- */

  async function generate() {
    $('#rSubtitle').text('Generating…');
    try {
      const d = await App.api('api/reports.php', { params: params() });
      renderSummary(d);
      drawTrend(d.trend);
      drawDist(d.dist);
      drawDenials(d.denials);
      renderTable(d.rows);
    } catch (err) {
      App.toast(err.message, 'danger');
      $('#rSubtitle').text('Could not generate the report.');
    }
  }

  const exportUrl = (format) =>
    App.urlFor('api/report_export.php') + '?' + new URLSearchParams({ format, ...params() });

  /* ---------------- Wiring ---------------- */

  $(document).ready(() => {
    const today = new Date().toISOString().slice(0, 10);
    const monthAgo = new Date(Date.now() - 29 * 86400000).toISOString().slice(0, 10);
    $('#from').val(monthAgo);
    $('#to').val(today);

    $('#preset').on('change', function () {
      const custom = this.value === 'custom';
      $('#fromWrap, #toWrap').toggleClass('d-none', !custom);
      if (!custom) generate();
    });

    $('#reportForm').on('submit', (ev) => { ev.preventDefault(); generate(); });
    $('#btnPrint').on('click', () => window.print());
    $('#btnPdf').on('click', () => window.open(exportUrl('pdf'), '_blank'));
    $('#btnExcel').on('click', () => { window.location.href = exportUrl('excel'); });
    $('#btnCsv').on('click', () => { window.location.href = exportUrl('csv'); });

    generate();
  });
})();
