/* ============================================================
   Notification centre page.
   ========================================================== */

(() => {
  const ICONS = {
    high_temperature: ['bi-thermometer-high', 'bg-danger-subtle text-danger', 'High Temperature'],
    denied_entry: ['bi-shield-x', 'bg-danger-subtle text-danger', 'Denied Entry'],
    device_offline: ['bi-wifi-off', 'bg-warning-subtle text-warning', 'Booth Offline'],
    low_disinfectant: ['bi-droplet-half', 'bg-warning-subtle text-warning', 'Low Disinfectant'],
    system: ['bi-info-circle', 'bg-primary-subtle text-primary', 'System'],
  };

  function renderCounts(byType) {
    const get = (t) => byType[t]?.total ?? 0;
    $('#nHighTemp').text(get('high_temperature'));
    $('#nDenied').text(get('denied_entry'));
    $('#nOffline').text(get('device_offline'));
    $('#nDisinfectant').text(get('low_disinfectant'));
  }

  let canDelete = false;

  function render(items) {
    const list = $('#notifPageList');

    if (!items.length) {
      list.html('<div class="empty-state py-5"><i class="bi bi-bell-slash"></i>No notifications match this filter.</div>');
      return;
    }

    list.html(items.map((n) => {
      const [icon, cls, label] = ICONS[n.type] || ICONS.system;
      return `
        <div class="list-group-item d-flex gap-3 align-items-start ${n.is_read ? '' : 'bg-light-subtle'}"
             data-id="${n.id}">
          <div class="notif-icon ${cls}" style="width:38px;height:38px;flex:0 0 38px">
            <i class="bi ${icon}"></i>
          </div>
          <div class="flex-grow-1 min-w-0">
            <div class="d-flex flex-wrap align-items-center gap-2">
              <span class="fw-semibold">${App.esc(n.title)}</span>
              <span class="badge bg-secondary-subtle text-secondary" style="font-size:.68rem">${App.esc(label)}</span>
              ${n.is_read ? '' : '<span class="badge bg-danger" style="font-size:.62rem">NEW</span>'}
            </div>
            <div class="text-muted small mt-1">${App.esc(n.message)}</div>
            <div class="text-muted" style="font-size:.72rem">
              <i class="bi bi-clock me-1"></i>${App.esc(n.created_h)} · ${App.timeAgo(n.created_at)}
            </div>
          </div>
          <div class="d-flex gap-1">
            ${n.is_read ? '' : `<button class="btn btn-sm btn-outline-secondary act-read" data-id="${n.id}" title="Mark as read">
                                  <i class="bi bi-check2"></i></button>`}
            ${canDelete ? `<button class="btn btn-sm btn-outline-danger act-del" data-id="${n.id}" title="Delete">
              <i class="bi bi-x-lg"></i></button>` : ''}
          </div>
        </div>`;
    }).join(''));
  }

  async function load() {
    try {
      const d = await App.api('api/notifications.php', {
        params: { action: 'list', type: $('#fType').val(), read: $('#fRead').val(), limit: 200 },
      });
      canDelete = !!d.can_delete;
      render(d.items);
      renderCounts(d.by_type);
      $('#pageUnread').text(d.unread).toggleClass('d-none', d.unread === 0);
    } catch (err) {
      App.toast(err.message, 'danger');
    }
  }

  $(document).ready(() => {
    load();

    $('#fType, #fRead').on('change', load);

    $('#btnMarkAll').on('click', async () => {
      try {
        const r = await App.api('api/notifications.php', { method: 'POST', body: { action: 'mark_all_read' } });
        App.toast(r.message, 'success');
        load();
        App.refreshNotifications();
      } catch (err) { App.toast(err.message, 'danger'); }
    });

    $('#btnClearRead').on('click', async () => {
      if (!confirm('Delete every notification that has already been read?')) return;
      try {
        const r = await App.api('api/notifications.php', { method: 'POST', body: { action: 'clear_read' } });
        App.toast(r.message, 'success');
        load();
      } catch (err) { App.toast(err.message, 'danger'); }
    });

    $(document).on('click', '.act-read', async function () {
      try {
        await App.api('api/notifications.php', { method: 'POST', body: { action: 'mark_read', id: Number(this.dataset.id) } });
        load();
        App.refreshNotifications();
      } catch (err) { App.toast(err.message, 'danger'); }
    });

    $(document).on('click', '.act-del', async function () {
      try {
        await App.api('api/notifications.php', { method: 'POST', body: { action: 'delete', id: Number(this.dataset.id) } });
        load();
        App.refreshNotifications();
      } catch (err) { App.toast(err.message, 'danger'); }
    });

    setInterval(load, 20000);
  });
})();
