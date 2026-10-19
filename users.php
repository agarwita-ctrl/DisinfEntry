<?php
/**
 * User Management - create accounts, change roles, reset passwords, (de)activate.
 */

declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
require_admin();

$PAGE_TITLE   = 'User Management';
$ACTIVE       = 'users';
$PAGE_SCRIPTS = ['assets/js/users.js'];

require __DIR__ . '/includes/header.php';
?>

<div class="alert alert-warning small d-none" id="userNotice"></div>

<div class="card">
  <div class="card-header d-flex flex-wrap align-items-center justify-content-between gap-2">
    <span><i class="bi bi-people me-2"></i>User Accounts</span>
    <button type="button" class="btn btn-sm btn-primary" id="addUserBtn">
      <i class="bi bi-person-plus me-1"></i>Add User
    </button>
  </div>
  <div class="table-responsive">
    <table class="table table-hover align-middle mb-0">
      <thead>
        <tr>
          <th>Employee ID</th>
          <th>Name</th>
          <th>Username</th>
          <th>Role</th>
          <th>Status</th>
          <th>Last Login</th>
          <th class="text-end">Actions</th>
        </tr>
      </thead>
      <tbody id="usersBody">
        <tr><td colspan="7" class="text-center text-muted py-4">Loading…</td></tr>
      </tbody>
    </table>
  </div>
  <div class="card-footer small text-muted">
    A forgotten password is reset here: the user gets a one-time temporary password from you and
    is asked to choose their own at their next sign-in. Accounts are deactivated, never deleted,
    so the audit trail keeps its history.
  </div>
</div>

<!-- Add / edit -->
<div class="modal fade" id="userModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <form class="modal-content" id="userForm" novalidate>
      <div class="modal-header">
        <h5 class="modal-title" id="userModalTitle">Add User</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <input type="hidden" id="uId">
        <div class="mb-3">
          <label class="form-label" for="uName">Full name <span class="text-danger">*</span></label>
          <input type="text" class="form-control" id="uName" maxlength="120" required>
        </div>
        <div class="mb-3" id="uUsernameWrap">
          <label class="form-label" for="uUsername">Username <span class="text-danger">*</span></label>
          <input type="text" class="form-control" id="uUsername" maxlength="60" autocomplete="off">
          <div class="form-text">3-60 characters: letters, digits, dot, dash, underscore. Cannot be changed later.</div>
        </div>
        <div class="row g-3">
          <div class="col-sm-6">
            <label class="form-label" for="uRole">Role</label>
            <select class="form-select" id="uRole">
              <option value="farm_manager">Farm Manager</option>
              <option value="administrator">Administrator</option>
            </select>
          </div>
          <div class="col-sm-6">
            <label class="form-label" for="uContact">Contact number</label>
            <input type="text" class="form-control" id="uContact" maxlength="30">
          </div>
        </div>
        <div class="mt-3" id="uEmpWrap">
          <label class="form-label" for="uEmp">Employee ID <span class="text-muted small">(optional)</span></label>
          <input type="text" class="form-control" id="uEmp" maxlength="30" placeholder="Generated if left empty">
        </div>
        <div class="alert alert-danger py-2 small mt-3 mb-0 d-none" id="userError"></div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
        <button type="submit" class="btn btn-primary" id="userSave">Save</button>
      </div>
    </form>
  </div>
</div>

<!-- One-time temporary password -->
<div class="modal fade" id="tempModal" tabindex="-1" aria-hidden="true" data-bs-backdrop="static">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title"><i class="bi bi-key me-2"></i>Temporary password</h5>
      </div>
      <div class="modal-body">
        <p class="mb-2">Give this to <strong id="tempUser"></strong>. It is shown <strong>only once</strong>
          and cannot be looked up again; they must choose their own password when they sign in.</p>
        <div class="input-group">
          <input type="text" class="form-control font-monospace fs-5" id="tempValue" readonly>
          <button type="button" class="btn btn-outline-secondary" id="tempCopy">
            <i class="bi bi-clipboard me-1"></i>Copy
          </button>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-primary" data-bs-dismiss="modal">I have noted it</button>
      </div>
    </div>
  </div>
</div>

<?php require __DIR__ . '/includes/footer.php'; ?>
