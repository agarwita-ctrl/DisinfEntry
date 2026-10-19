/* ============================================================
   User Management - list, add/edit, reset password, (de)activate.
   ========================================================== */

(() => {
  let users = [];
  let canProvision = true;
  let mailConfigured = true;

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
          ${u.email
            ? `<div class="small text-muted">${App.esc(u.email)}</div>`
            : '<div class="small text-warning"><i class="bi bi-exclamation-triangle me-1"></i>No e-mail registered</div>'}
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
          <button class="btn btn-sm btn-outline-warning" data-act="reset" data-id="${u.id}"
                  title="${u.email ? 'Reset password (e-mailed to the user)' : 'Add an e-mail address first'}"
                  ${u.email ? '' : 'disabled'}>
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
      mailConfigured = d.mail_configured;

      const note = document.getElementById('userNotice');
      const problems = [];
      if (!canProvision) {
        problems.push('Run database/migrations/006_must_change_password.sql and 007_user_email.sql: ' +
          'adding users, editing e-mail addresses and resetting passwords need them.');
      }
      if (!mailConfigured) {
        problems.push('E-mail is not configured on this server, so temporary passwords cannot be sent ' +
          'and users cannot be created or reset. Set the DISINFENTRY_MAIL_* settings ' +
          '(see config/mail.local.example.php).');
      }
      note.innerHTML = problems.map((t) => `<div>${App.esc(t)}</div>`).join('');
      note.classList.toggle('d-none', problems.length === 0);
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
    document.getElementById('uEmail').value = editing ? (u.email || '') : '';
    document.getElementById('uEmp').value = '';
    document.getElementById('uEmpWrap').classList.toggle('d-none', editing);
    document.getElementById('uUsernameWrap').classList.toggle('d-none', false);
    document.getElementById('userError').classList.add('d-none');
    modal('userModal').show();
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
        email: document.getElementById('uEmail').value,
      };
      if (id) {
        payload.id = Number(id);
      } else {
        payload.username = document.getElementById('uUsername').value;
        payload.employee_id = document.getElementById('uEmp').value;
      }

      // Creating a user sends an e-mail, which can take a few seconds.
      save.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Saving';
      const res = await post(payload);
      modal('userModal').hide();
      await load();
      App.toast(res.message, 'success');
    } catch (e) {
      err.textContent = e.message;
      err.classList.remove('d-none');
    } finally {
      save.disabled = false;
      save.textContent = 'Save';
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
        if (!window.confirm(`Reset the password for ${u.username}? A temporary password will be e-mailed to ${u.email}, ` +
                            'and their current password stops working immediately.')) return;
        btn.disabled = true;
        const res = await post({ action: 'reset_password', id: u.id });
        App.toast(res.message, 'success');
        await load();
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

  load();
})();
