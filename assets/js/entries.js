/* ============================================================
   Entry Monitoring — DataTables (server-side) + filters + exports.
   ========================================================== */

(() => {
  let table = null;
  const rowCache = new Map();

  const filters = () => ({
    date: $('#fDate').val(),
    month: $('#fMonth').val(),
    date_from: $('#fFrom').val(),
    date_to: $('#fTo').val(),
    temp: $('#fTemp').val(),
    status: $('#fStatus').val(),
    disinfection: $('#fDisinfection').val(),
    device: $('#fDevice').val(),
  });

  function describeFilters() {
    const f = filters();
    const parts = [];
    if (f.date) parts.push(`Date ${f.date}`);
    if (f.month) parts.push(`Month ${f.month}`);
    if (f.date_from) parts.push(`From ${f.date_from}`);
    if (f.date_to) parts.push(`To ${f.date_to}`);
    if (f.temp) parts.push(`${f.temp === 'high' ? 'High' : 'Normal'} temperature`);
    if (f.status) parts.push(`Access ${f.status}`);
    if (f.disinfection) parts.push(`Disinfection ${f.disinfection}`);
    if (f.device) parts.push(`Booth ${f.device}`);
    return parts.length ? parts.join(' · ') : 'Showing all records';
  }

  function renderSummary(s) {
    $('#sumTotal').text(App.num(s.total));
    $('#sumGranted').text(App.num(s.granted));
    $('#sumDenied').text(App.num(s.denied));
    $('#sumAvg').html(s.avg_temp !== null ? `${s.avg_temp}&deg;C` : '—');
  }

  function initTable() {
    table = $('#entriesTable').DataTable({
      processing: true,
      serverSide: true,
      searchDelay: 400,
      order: [[0, 'desc']],
      lengthMenu: [[10, 25, 50, 100], [10, 25, 50, 100]],
      pageLength: 25,
      language: {
        processing: '<div class="spinner-border spinner-border-sm text-primary"></div> Loading…',
        emptyTable: 'No entries match the selected filters.',
        zeroRecords: 'No entries match the selected filters.',
        search: '',
        searchPlaceholder: 'Search entries…',
      },
      ajax: {
        url: App.urlFor('api/entries.php'),
        data: (d) => Object.assign(d, filters()),
        dataSrc: (json) => {
          renderSummary(json.summary);
          json.data.forEach((r) => rowCache.set(r.id, r));
          $('#activeFilterNote').text(describeFilters());
          return json.data;
        },
        error: (xhr) => {
          App.toast('Could not load entries. ' + (xhr.responseJSON?.message || ''), 'danger');
        },
      },
      columns: [
        { data: 'entry_code', render: (v) => `<span class="text-muted small">${App.esc(v)}</span>` },
        {
          data: 'temperature',
          className: 'text-center',
          render: (v) => App.tempBadge(v),
        },
        { data: 'date' },
        { data: 'time' },
        { data: 'disinfection', className: 'text-center', render: (v) => App.disinfectionBadge(v) },
        { data: 'status', className: 'text-center', render: (v) => App.accessBadge(v) },
        {
          data: 'remarks',
          render: (v) => `<span class="small text-muted">${App.esc(v || '—')}</span>`,
        },
        {
          data: 'id',
          orderable: false,
          className: 'text-end no-print',
          render: (v) => `
            <button class="btn btn-sm btn-outline-secondary view-entry" data-id="${v}" title="View">
              <i class="bi bi-eye"></i>
            </button>`,
        },
      ],
      drawCallback: () => {
        // Administrators additionally get a delete control.
        const canDelete = table?.ajax.json()?.can_delete;
        if (!canDelete) return;
        $('#entriesTable tbody .view-entry').each(function () {
          const id = this.dataset.id;
          if (this.parentElement.querySelector('.del-entry')) return;
          $(this).after(
            `<button class="btn btn-sm btn-outline-danger ms-1 del-entry" data-id="${id}" title="Delete">
               <i class="bi bi-trash"></i></button>`
          );
        });
      },
    });
  }

  /* ---------------- Row detail ---------------- */

  // Seconds read better than five-digit millisecond counts on a timeline.
  const secs = (ms) => `${(ms / 1000).toFixed(1)}s`;

  // The sketch's SystemState enum, in plain language.
  const STATE_LABEL = {
    IDLE: 'Idle',
    CONFIRMING: 'Confirming presence',
    PUMP_ON_1: 'Spraying (1st burst)',
    PUMP_OFF_INTERVAL: 'Between bursts',
    PUMP_ON_2: 'Spraying (2nd burst)',
    DOOR_OPENING: 'Opening door',
    DOOR_HOLD_OPEN: 'Door open',
    DOOR_CLOSING: 'Closing door',
    ACCESS_DENIED: 'Access denied',
  };
  const stateLabel = (s) => STATE_LABEL[s] || s;

  function summaryHtml(e) {
    return `
      <dl class="row mb-0 small">
        <dt class="col-5 text-muted fw-normal">Entry ID</dt><dd class="col-7 fw-semibold">${App.esc(e.entry_code)}</dd>
        <dt class="col-5 text-muted fw-normal">Temperature</dt><dd class="col-7">${App.tempBadge(e.temperature)}</dd>
        <dt class="col-5 text-muted fw-normal">Entry Date</dt><dd class="col-7">${App.esc(e.date)}</dd>
        <dt class="col-5 text-muted fw-normal">Entry Time</dt><dd class="col-7">${App.esc(e.time)}</dd>
        <dt class="col-5 text-muted fw-normal">Disinfection</dt><dd class="col-7">${App.disinfectionBadge(e.disinfection)}</dd>
        <dt class="col-5 text-muted fw-normal">Misting</dt><dd class="col-7">${App.esc(String(e.misting).toUpperCase())}</dd>
        <dt class="col-5 text-muted fw-normal">Access Status</dt><dd class="col-7">${App.accessBadge(e.status)}</dd>
        <dt class="col-5 text-muted fw-normal">Booth</dt><dd class="col-7">${App.esc(e.device_id || '—')}</dd>
        <dt class="col-5 text-muted fw-normal">Remarks</dt><dd class="col-7">${App.esc(e.remarks || '—')}</dd>
      </dl>`;
  }

  function cycleHtml(c) {
    if (!c) {
      return `
        <div class="alert alert-light border small mb-0 mt-3">
          <i class="bi bi-info-circle me-1"></i>
          No booth cycle is linked to this entry. Records created by the legacy
          <code>api/entry.php</code> carry only the reading that was posted.
        </div>`;
    }

    const readings = `
      <div class="row g-2 mb-3">
        <div class="col-4"><div class="live-tile py-2">
          <div class="label">Screened at</div>
          <div class="value small">${c.screening_temp_c != null ? `${c.screening_temp_c.toFixed(2)} °C` : '—'}</div>
        </div></div>
        <div class="col-4"><div class="live-tile py-2">
          <div class="label">Ambient</div>
          <div class="value small">${c.ambient_temp_c != null ? `${c.ambient_temp_c.toFixed(2)} °C` : '—'}</div>
        </div></div>
        <div class="col-4"><div class="live-tile py-2">
          <!-- The threshold the booth screened against at the time, which is the
               one it had adopted then — not necessarily today's setting. -->
          <div class="label">Threshold then</div>
          <div class="value small">${c.threshold_c != null ? `${c.threshold_c.toFixed(1)} °C` : '—'}</div>
        </div></div>
      </div>`;

    const spray = c.pump.length
      ? c.pump.map((b) => `burst ${b.burst_no}: ${secs(b.duration_ms)}`).join(' · ')
      : 'none — the pump was blocked';

    const door = c.door.length
      ? c.door.map((d) => `${d.action} at +${secs(d.offset)}`).join(' · ')
      : 'never opened';

    const timeline = c.states.length
      ? `<ul class="list-unstyled mb-0 small">${c.states.map((s) => `
          <li class="d-flex justify-content-between border-bottom py-1">
            <span>${App.esc(stateLabel(s.to))}</span>
            <span class="text-muted font-monospace">+${secs(s.offset)}</span>
          </li>`).join('')}</ul>`
      : '<div class="text-muted small">No transitions were recorded.</div>';

    return `
      <hr class="my-3">
      <div class="d-flex align-items-center justify-content-between mb-2">
        <span class="fw-semibold small"><i class="bi bi-cpu me-2"></i>Booth Cycle</span>
        <span class="text-muted" style="font-size:.72rem">
          ${c.duration_ms != null ? `${secs(c.duration_ms)} total` : ''}
        </span>
      </div>

      ${readings}

      <dl class="row mb-2 small">
        <dt class="col-5 text-muted fw-normal">Triggered at</dt>
        <dd class="col-7">${c.trigger_distance_cm != null ? `${c.trigger_distance_cm.toFixed(1)} cm` : '—'}</dd>
        <dt class="col-5 text-muted fw-normal">Spray</dt><dd class="col-7">${App.esc(spray)}</dd>
        <dt class="col-5 text-muted fw-normal">Door</dt><dd class="col-7">${App.esc(door)}</dd>
      </dl>

      <div class="text-muted text-uppercase mb-1" style="letter-spacing:.08em;font-size:.68rem">
        Sequence — from first detection
      </div>
      ${timeline}

      <div class="text-muted font-monospace mt-2" style="font-size:.68rem;word-break:break-all">
        ${App.esc(c.cycle_ref)}
      </div>`;
  }

  $(document).on('click', '.view-entry', async function () {
    const id = Number(this.dataset.id);
    const cached = rowCache.get(id);

    // Show what the table already knows immediately, then fill in the cycle —
    // the modal should never open onto a spinner for data already on screen.
    $('#entryModalBody').html(
      (cached ? summaryHtml(cached) : '') +
      '<div class="text-muted small mt-3"><span class="spinner-border spinner-border-sm me-2"></span>Loading booth cycle…</div>'
    );
    bootstrap.Modal.getOrCreateInstance(document.getElementById('entryModal')).show();

    try {
      const d = await App.api('api/entries.php', { params: { action: 'detail', id } });
      $('#entryModalBody').html(summaryHtml(d.entry) + cycleHtml(d.cycle));
    } catch (err) {
      $('#entryModalBody').html(
        (cached ? summaryHtml(cached) : '') +
        `<div class="alert alert-warning small mt-3 mb-0">Could not load the booth cycle. ${App.esc(err.message)}</div>`
      );
    }
  });

  /* ---------------- Delete ---------------- */

  $(document).on('click', '.del-entry', async function () {
    const id = Number(this.dataset.id);
    const r = rowCache.get(id);
    if (!confirm(`Delete entry ${r?.entry_code || id}? This cannot be undone.`)) return;

    try {
      const res = await App.api('api/entries.php', { method: 'POST', body: { action: 'delete', id } });
      App.toast(res.message, 'success');
      table.ajax.reload(null, false);
    } catch (err) {
      App.toast(err.message, 'danger');
    }
  });

  /* ---------------- Filters & exports ---------------- */

  function exportUrl(format) {
    const params = new URLSearchParams({ format, ...filters() });
    // Carry the DataTables search box into the export.
    const search = table?.search();
    if (search) params.set('search', search);
    return App.urlFor('api/export.php') + '?' + params;
  }

  $(document).ready(() => {
    initTable();

    $('#filterForm').on('submit', (ev) => {
      ev.preventDefault();
      table.ajax.reload();
    });

    $('#resetFilters').on('click', () => {
      $('#filterForm')[0].reset();
      table.search('').ajax.reload();
    });

    // A month filter and an exact-date filter contradict each other.
    $('#fDate').on('change', function () { if (this.value) $('#fMonth').val(''); });
    $('#fMonth').on('change', function () { if (this.value) $('#fDate').val(''); });

    $('#exportCsv').on('click', (ev) => {
      ev.preventDefault();
      window.location.href = exportUrl('csv');
      App.toast('Preparing your CSV download…', 'info');
    });

    $('#exportExcel').on('click', (ev) => {
      ev.preventDefault();
      window.location.href = exportUrl('excel');
      App.toast('Preparing your Excel download…', 'info');
    });

    $('#printTable').on('click', (ev) => {
      ev.preventDefault();
      window.print();
    });
  });
})();
