<?php
/** Staff accounts and roles. Admin only. */

require_once __DIR__ . '/../core/config.php';
require_role('admin');

$ROLES = [
    'admin'   => 'Administrator — everything',
    'cashier' => 'Cashier — POS, payments, drawer, expenses',
    'waiter'  => 'Waiter — takes orders, cannot take payment',
    'kitchen' => 'Kitchen — preparation screen only',
];

$editing = null;

if (is_post()) {
    csrf_check();
    $action = post('action');

    if ($action === 'save') {
        $id       = (int)post('id');
        $fullName = post('full_name');
        $username = strtolower(post('username'));
        $role     = array_key_exists(post('role'), $ROLES) ? post('role') : 'cashier';
        $password = (string)($_POST['password'] ?? '');
        $active   = isset($_POST['is_active']) ? 1 : 0;

        $clash = db_one('SELECT id FROM users WHERE username = ? AND id <> ?', [$username, $id]);

        if ($fullName === '' || $username === '') {
            flash('Name and username are both required.', 'danger');
        } elseif ($clash) {
            flash('That username is already taken.', 'danger');
        } elseif ($id === 0 && strlen($password) < 6) {
            flash('Set a password of at least 6 characters.', 'danger');
        } elseif ($id > 0 && $password !== '' && strlen($password) < 6) {
            flash('The new password must be at least 6 characters.', 'danger');
        } elseif ($id === user_id() && ($role !== 'admin' || $active === 0)) {
            flash('You cannot remove your own admin access or disable your own account.', 'danger');
        } else {
            if ($id > 0) {
                db_exec('UPDATE users SET full_name = ?, username = ?, role = ?, is_active = ? WHERE id = ?',
                    [$fullName, $username, $role, $active, $id]);
                if ($password !== '') {
                    db_exec('UPDATE users SET password_hash = ? WHERE id = ?',
                        [password_hash($password, PASSWORD_DEFAULT), $id]);
                }
                flash('Account updated.');
            } else {
                db_exec('INSERT INTO users (full_name, username, password_hash, role, is_active) VALUES (?,?,?,?,?)',
                    [$fullName, $username, password_hash($password, PASSWORD_DEFAULT), $role, $active]);
                flash('Account created.');
            }
            redirect('admin/users.php');
        }
    }

    if ($action === 'toggle') {
        $id = (int)post('id');
        if ($id === user_id()) {
            flash('You cannot disable your own account.', 'danger');
        } else {
            db_exec('UPDATE users SET is_active = 1 - is_active WHERE id = ?', [$id]);
            flash('Account status changed.');
        }
        redirect('admin/users.php');
    }
}

if (get('edit') !== '') {
    $editing = db_one('SELECT * FROM users WHERE id = ?', [(int)get('edit')]);
}

$users = db_all(
    'SELECT u.*, (SELECT COUNT(*) FROM orders o WHERE o.created_by = u.id) AS order_count
       FROM users u ORDER BY u.role, u.full_name'
);

$pageTitle = 'Users';
require __DIR__ . '/../core/header.php';
?>

<div class="row g-3">
    <div class="col-lg-4">
        <div class="card">
            <div class="card-header"><?= $editing ? 'Edit account' : 'New account' ?></div>
            <div class="card-body">
                <form method="post" autocomplete="off">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="save">
                    <input type="hidden" name="id" value="<?= (int)($editing['id'] ?? 0) ?>">

                    <div class="mb-3">
                        <label class="form-label">Full name</label>
                        <input type="text" name="full_name" class="form-control" required maxlength="100"
                               value="<?= e($editing['full_name'] ?? '') ?>">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Username</label>
                        <input type="text" name="username" class="form-control" required maxlength="50"
                               value="<?= e($editing['username'] ?? '') ?>">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Role</label>
                        <select name="role" class="form-select">
                            <?php foreach ($ROLES as $key => $label): ?>
                                <option value="<?= $key ?>" <?= ($editing['role'] ?? '') === $key ? 'selected' : '' ?>>
                                    <?= e($label) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Password</label>
                        <input type="password" name="password" class="form-control"
                               <?= $editing ? '' : 'required' ?> minlength="6">
                        <?php if ($editing): ?>
                            <div class="form-text">Leave blank to keep the current password.</div>
                        <?php endif; ?>
                    </div>
                    <div class="form-check mb-3">
                        <input type="checkbox" name="is_active" class="form-check-input" id="ua"
                               <?= (!$editing || (int)$editing['is_active'] === 1) ? 'checked' : '' ?>>
                        <label class="form-check-label" for="ua">Account can sign in</label>
                    </div>

                    <button class="btn text-white" style="background:var(--brand)">
                        <?= $editing ? 'Save changes' : 'Create account' ?>
                    </button>
                    <?php if ($editing): ?>
                        <a class="btn btn-outline-secondary" href="<?= url('admin/users.php') ?>">Cancel</a>
                    <?php endif; ?>
                </form>
            </div>
        </div>
    </div>

    <div class="col-lg-8">
        <table class="table align-middle">
            <thead>
                <tr><th>Name</th><th>Username</th><th>Role</th><th class="text-center">Orders</th>
                    <th class="text-center">Status</th><th class="text-end">Actions</th></tr>
            </thead>
            <tbody>
            <?php foreach ($users as $u): ?>
                <tr>
                    <td><?= e($u['full_name']) ?><?= (int)$u['id'] === user_id() ? ' <span class="badge bg-light text-dark">you</span>' : '' ?></td>
                    <td class="text-muted"><?= e($u['username']) ?></td>
                    <td><span class="badge bg-dark"><?= e(ucfirst($u['role'])) ?></span></td>
                    <td class="text-center"><?= (int)$u['order_count'] ?></td>
                    <td class="text-center">
                        <span class="badge bg-<?= (int)$u['is_active'] ? 'success' : 'secondary' ?>">
                            <?= (int)$u['is_active'] ? 'Active' : 'Disabled' ?>
                        </span>
                    </td>
                    <td class="text-end text-nowrap">
                        <a class="btn btn-sm btn-outline-secondary"
                           href="<?= url('admin/users.php?edit=' . (int)$u['id']) ?>">Edit</a>
                        <?php if ((int)$u['id'] !== user_id()): ?>
                            <form method="post" class="d-inline">
                                <?= csrf_field() ?>
                                <input type="hidden" name="action" value="toggle">
                                <input type="hidden" name="id" value="<?= (int)$u['id'] ?>">
                                <button class="btn btn-sm btn-outline-warning">
                                    <?= (int)$u['is_active'] ? 'Disable' : 'Enable' ?>
                                </button>
                            </form>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <p class="text-muted small">
            Accounts are never deleted — disabling one keeps its sales history intact.
        </p>
    </div>
</div>

<?php require __DIR__ . '/../core/footer.php'; ?>
