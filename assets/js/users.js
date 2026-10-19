/* ============================================================
   User Management - list, add/edit, reset password, (de)activate.
   ========================================================== */

(() => {
  let users = [];
  let canProvision = true;

  const modal = (id) => bootstrap.Modal.getOrCreateInstance(document.getElementById(id));
  const post = (body) => App.api('api/users.php', { method: 'POST', body });

  const roleBadge = (u) =>
    `<span class="badge ${u.role === 'administrator' ? 'bg-primary-subtle text-primary' : 'bg-secondary-subtle text-secondary'}">${App.esc(u.role_label)}</span>`;

  const statusBadge = (u) =>
    u.status === 'active'
      ? '<span class="badge bg-success-subtle text-success">Active</span>'
      : '<span class="badge bg-danger-subtle text-danger">Inactive</span>';

  function render() {
    const body = document.getElementById('usersBody');

    if (!users.length) {
      body.innerHTML = '<tr><td colspan="7" class="text-center text-muted py-4">No users.</td></tr>';
      return;
    }

    body.innerHTML = users.map((u) => `
      <tr class="${u.status === 'active' ? '' : 'text-muted'}">
        <td>${App.esc(u.employee_id)}</td>
        <td>
          <div class="fw-semibold">${App.esc(u.full_name)}${u.is_self ? ' <span class="badge bg-light text-dark border ms-1">You</span>' : ''}</div>
          <div class="small text-muted">${App.esc(u.contact_number || '—')}</div>
        </td>
        <td>${App.esc(u.username)}</td>
        <td>${roleBadge(u)}</td>
        <td>${statusBadge(u)}${u.must_change ? '<div class="small text-warning mt-1">Must change password</div>' : ''}</td>
        <td class="small">${App.esc(u.last_login || 'Never')}</td>
        <td class="text-end text-nowrap">
          <button class="btn btn-sm btn-outline-secondary" data-act="edit" data-id="${u.id}" title="Edit">
            <i class="bi bi-pencil"></i>
          </button>
          ${u.is_self ? '' : `
          <button class="btn btn-sm btn-outline-warning" data-act="reset" data-id="${u.id}" title="Reset password">
            <i class="bi bi-key"></i>
          </button>
          <button class="btn btn-sm ${u.status === 'active' ? 'btn-outline-danger' : 'btn-outline-success'}"
                  data-act="status" data-id="${u.id}" title="${u.status === 'active' ? 'Deactivate' : 'Activate'}">
            <i class="bi bi-${u.status === 'active' ? 'person-slash' : 'person-check'}"></i>
          </button>`}
        </td>
      </tr>`).join('');
  }

  async function load() {
    try {
      const d = await App.api('api/users.php');
      users = d.users;
      canProvision = d.can_provision;

      const note = document.getElementById('userNotice');
      if (!canProvision) {
        note.textContent =
          'Adding users and resetting passwords need database/migrations/006_must_change_password.sql. Run it, then reload this page.';
        note.classList.remove('d-none');
      } else {
        note.classList.add('d-none');
      }
      render();
    } catch (err) {
      App.toast(err.message, 'danger');
    }
  }

  /* ---------------- Add / edit ---------------- */

  function openForm(u) {
    const editing = !!u;
    document.getElementById('userModalTitle').textContent = editing ? 'Edit User' : 'Add User';
    document.getElementById('uId').value = editing ? u.id : '';
    document.getElementById('uName').value = editing ? u.full_name : '';
    document.getElementById('uUsername').value = editing ? u.username : '';
    document.getElementById('uUsername').disabled = editing;
    document.getElementById('uRole').value = editing ? u.role : 'farm_manager';
    document.getElementById('uRole').disabled = editing && u.is_self;   // not your own role
    document.getElementById('uContact').value = editing ? (u.contact_number || '') : '';
    document.getElementById('uEmp').value = '';
    document.getElementById('uEmpWrap').classList.toggle('d-none', editing);
    document.getElementById('uUsernameWrap').classList.toggle('d-none', false);
    document.getElementById('userError').classList.add('d-none');
    modal('userModal').show();
  }

  function showTemp(username, password) {
    document.getElementById('tempUser').textContent = username;
    document.getElementById('tempValue').value = password;
    modal('tempModal').show();
  }

  document.getElementById('addUserBtn').addEventListener('click', () => openForm(null));

  document.getElementById('userForm').addEventListener('submit', async (ev) => {
    ev.preventDefault();
    const id = document.getElementById('uId').value;
    const err = document.getElementById('userError');
    const save = document.getElementById('userSave');
    err.classList.add('d-none');
    save.disabled = true;

    try {
      const payload = {
        action: id ? 'update' : 'create',
        full_name: document.getElementById('uName').value,
        role: document.getElementById('uRole').value,
        contact_number: document.getElementById('uContact').value,
      };
      if (id) {
        payload.id = Number(id);
      } else {
        payload.username = document.getElementById('uUsername').value;
        payload.employee_id = document.getElementById('uEmp').value;
      }

      const res = await post(payload);
      modal('userModal').hide();
      await load();
      if (res.temp_password) showTemp(res.username, res.temp_password);
      else App.toast(res.message, 'success');
    } catch (e) {
      err.textContent = e.message;
      err.classList.remove('d-none');
    } finally {
      save.disabled = false;
    }
  });

  /* ---------------- Row actions ---------------- */

  document.getElementById('usersBody').addEventListener('click', async (ev) => {
    const btn = ev.target.closest('button[data-act]');
    if (!btn) return;
    const u = users.find((x) => x.id === Number(btn.dataset.id));
    if (!u) return;

    try {
      if (btn.dataset.act === 'edit') {
        openForm(u);
      } else if (btn.dataset.act === 'reset') {
        if (!window.confirm(`Reset the password for ${u.username}? Their current password stops working immediately.`)) return;
        const res = await post({ action: 'reset_password', id: u.id });
        await load();
        showTemp(res.username, res.temp_password);
      } else if (btn.dataset.act === 'status') {
        const next = u.status === 'active' ? 'inactive' : 'active';
        if (next === 'inactive' && !window.confirm(`Deactivate ${u.username}? They will be signed out and unable to sign in.`)) return;
        const res = await post({ action: 'set_status', id: u.id, status: next });
        App.toast(res.message, 'success');
        await load();
      }
    } catch (e) {
      App.toast(e.message, 'danger');
    }
  });

  document.getElementById('tempCopy').addEventListener('click', async () => {
    const input = document.getElementById('tempValue');
    try {
      await navigator.clipboard.writeText(input.value);
      App.toast('Copied.', 'success');
    } catch {
      input.select();   // clipboard blocked (plain http): let the admin copy by hand
    }
  });

  // The temporary password must not outlive the dialog in the page.
  document.getElementById('tempModal').addEventListener('hidden.bs.modal', () => {
    document.getElementById('tempValue').value = '';
  });

  load();
})();
