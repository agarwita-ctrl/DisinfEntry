/* ============================================================
   Audit Logs — server-side DataTable, filters, export and purge.
   ========================================================== */

(() => {
  let table = null;

  const MODULE_STYLE = {
    'Authentication': 'bg-primary-subtle text-primary',
    'User Management': 'bg-purple-subtle text-purple',
    'System Settings': 'bg-warning-subtle text-warning',
    'Entry Monitoring': 'bg-info-subtle text-info',
    'Reports': 'bg-success-subtle text-success',
    'Notifications': 'bg-secondary-subtle text-secondary',
    'Booth': 'bg-dark-subtle text-dark',
    'Profile': 'bg-primary-subtle text-primary',
    'Audit Logs': 'bg-danger-subtle text-danger',
  };

  const filters = () => ({
    user: $('#fUser').val(),
    module: $('#fModule').val(),
    date_from: $('#fFrom').val(),
    date_to: $('#fTo').val(),
  });

  $(document).ready(() => {
    table = $('#auditTable').DataTable({
      processing: true,
      serverSide: true,
      searchDelay: 400,
      order: [[0, 'desc']],
      pageLength: 25,
      lengthMenu: [[25, 50, 100, 200], [25, 50, 100, 200]],
      language: {
        processing: '<div class="spinner-border spinner-border-sm text-primary"></div> Loading…',
        search: '',
        searchPlaceholder: 'Search activity…',
        emptyTable: 'No audit entries match these filters.',
      },
      ajax: {
        url: App.urlFor('api/audit.php'),
        data: (d) => Object.assign(d, filters()),
        error: () => App.toast('Could not load the audit log.', 'danger'),
      },
      columns: [
        { data: 'id', render: (v) => `<span class="text-muted small">${v}</span>` },
        {
          data: 'datetime',
          render: (v, t, r) =>
            `<div class="small">${App.esc(v)}</div>` +
            `<div class="text-muted" style="font-size:.72rem">${App.timeAgo(r.raw_date)}</div>`,
        },
        {
          data: 'user',
          render: (v) =>
            `<div class="d-flex align-items-center gap-2">
               <span class="avatar" style="width:26px;height:26px;flex:0 0 26px;font-size:.7rem">
                 ${App.esc(v.charAt(0).toUpperCase())}
               </span>
               <span class="small fw-semibold">${App.esc(v)}</span>
             </div>`,
        },
        {
          data: 'module',
          render: (v) => {
            const cls = MODULE_STYLE[v] || 'bg-secondary-subtle text-secondary';
            return `<span class="badge ${cls}" style="font-size:.7rem">${App.esc(v)}</span>`;
          },
        },
        { data: 'activity', render: (v) => `<span class="small">${App.esc(v)}</span>` },
        { data: 'ip', render: (v) => `<code class="small text-muted">${App.esc(v)}</code>` },
      ],
    });

    $('#auditFilter').on('submit', (ev) => { ev.preventDefault(); table.ajax.reload(); });

    $('#btnReset').on('click', () => {
      $('#auditFilter')[0].reset();
      table.search('').ajax.reload();
    });

    $('#btnExport').on('click', () => {
      const params = new URLSearchParams({ export: 'csv', ...filters() });
      const search = table.search();
      if (search) params.set('search[value]', search);
      window.location.href = App.urlFor('api/audit.php') + '?' + params;
      App.toast('Preparing your CSV download…', 'info');
    });

    $('#btnPurge').on('click', () => {
      const d = new Date();
      d.setMonth(d.getMonth() - 3);
      $('#purgeBefore').val(d.toISOString().slice(0, 10));
      bootstrap.Modal.getOrCreateInstance(document.getElementById('purgeModal')).show();
    });

    $('#purgeForm').on('submit', async (ev) => {
      ev.preventDefault();
      const before = $('#purgeBefore').val();
      if (!confirm(`Permanently delete all audit entries before ${before}?`)) return;

      try {
        const res = await App.api('api/audit.php', { method: 'POST', body: { action: 'clear', before } });
        bootstrap.Modal.getInstance(document.getElementById('purgeModal')).hide();
        App.toast(res.message, 'success');
        table.ajax.reload();
      } catch (err) {
        App.toast(err.message, 'danger');
      }
    });
  });
})();
