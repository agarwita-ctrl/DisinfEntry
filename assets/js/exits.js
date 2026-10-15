/* ============================================================
   Exit Monitoring — DataTables (server-side) + filters.
   ========================================================== */

(() => {
  let table = null;

  const filters = () => ({
    date: $('#fDate').val(),
    month: $('#fMonth').val(),
    date_from: $('#fFrom').val(),
    date_to: $('#fTo').val(),
    device: $('#fDevice').val(),
  });

  function describeFilters() {
    const f = filters();
    const parts = [];
    if (f.date) parts.push(`Date ${f.date}`);
    if (f.month) parts.push(`Month ${f.month}`);
    if (f.date_from) parts.push(`From ${f.date_from}`);
    if (f.date_to) parts.push(`To ${f.date_to}`);
    if (f.device) parts.push(`Booth ${f.device}`);
    return parts.length ? parts.join(' · ') : 'Showing all records';
  }

  function renderSummary(s) {
    $('#sumTotal').text(App.num(s.total));
    $('#sumToday').text(App.num(s.today));
    $('#sumLast').text(s.last || '—');
  }

  function initTable() {
    table = $('#exitsTable').DataTable({
      processing: true,
      serverSide: true,
      searching: false,
      order: [[0, 'desc']],
      lengthMenu: [[10, 25, 50, 100], [10, 25, 50, 100]],
      pageLength: 25,
      language: {
        processing: '<div class="spinner-border spinner-border-sm text-primary"></div> Loading…',
        emptyTable: 'No exits recorded yet.',
        zeroRecords: 'No exits match the selected filters.',
      },
      ajax: {
        url: App.urlFor('api/exits.php'),
        data: (d) => Object.assign(d, filters()),
        dataSrc: (json) => {
          renderSummary(json.summary);
          $('#activeFilterNote').text(describeFilters());
          const note = $('#exitNotice');
          if (json.notice) note.text(json.notice).removeClass('d-none');
          else note.addClass('d-none');
          return json.data;
        },
        error: (xhr) => {
          App.toast('Could not load exits. ' + (xhr.responseJSON?.message || ''), 'danger');
        },
      },
      columns: [
        { data: 'exit_id', render: (v) => `<span class="fw-semibold">${App.esc(v)}</span>` },
        { data: 'date' },
        { data: 'time' },
        { data: 'device_id', render: (v) => App.esc(v || '—') },
        {
          data: 'distance_cm',
          className: 'text-end',
          render: (v) => (v === null ? '<span class="text-muted">—</span>' : `${Number(v).toFixed(1)} cm`),
        },
      ],
    });
  }

  $(document).ready(() => {
    initTable();

    $('#filterForm').on('submit', (ev) => {
      ev.preventDefault();
      table.ajax.reload();
    });

    $('#resetFilters').on('click', () => {
      $('#filterForm')[0].reset();
      table.ajax.reload();
    });

    // A month filter and an exact-date filter contradict each other.
    $('#fDate').on('change', function () { if (this.value) $('#fMonth').val(''); });
    $('#fMonth').on('change', function () { if (this.value) $('#fDate').val(''); });

    // New exits show up without a reload, the way the dashboard feed does.
    setInterval(() => {
      if (!document.hidden && table) table.ajax.reload(null, false);
    }, 15000);
  });
})();
