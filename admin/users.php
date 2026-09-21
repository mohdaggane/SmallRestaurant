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

        // tenant-global: usernames are unique across ALL restaurants; login finds the company from the username.
        $clash = db_one('SELECT id FROM users WHERE username = ? AND id <> ?', [$username, $id]);
        // Editing: the account must be one of this restaurant's own.
        $existing = $id > 0 ? db_one('SELECT * FROM users WHERE id = ? AND company_id = ?', [$id, company_id()]) : null;
        // A new active account, or re-activating one, counts against the plan.
        $addsSeat = $active === 1 && ($id === 0 || ($existing && !(int)$existing['is_active']));

        if ($id > 0 && !$existing) {
            flash('That account no longer exists.', 'danger');
        } elseif ($fullName === '' || $username === '') {
            flash('Name and username are both required.', 'danger');
        } elseif ($clash) {
            $suffix = current_company()['slug'] ?? '';
            flash('That username is already taken (usernames are shared by every restaurant on the system). Try e.g. "'
                . $username . '.' . $suffix . '".', 'danger');
        } elseif ($addsSeat && company_limit_reached('users')) {
            flash('Your plan allows ' . (int)current_company()['max_users'] . ' active accounts. Disable one or upgrade the plan (Billing).', 'danger');
        } elseif ($id === 0 && strlen($password) < 6) {
            flash('Set a password of at least 6 characters.', 'danger');
        } elseif ($id > 0 && $password !== '' && strlen($password) < 6) {
            flash('The new password must be at least 6 characters.', 'danger');
        } elseif ($id === user_id() && ($role !== 'admin' || $active === 0)) {
            flash('You cannot remove your own admin access or disable your own account.', 'danger');
        } else {
            if ($id > 0) {
                db_exec('UPDATE users SET full_name = ?, username = ?, role = ?, is_active = ? WHERE id = ? AND company_id = ?',
                    [$fullName, $username, $role, $active, $id, company_id()]);
                if ($password !== '') {
                    db_exec('UPDATE users SET password_hash = ? WHERE id = ? AND company_id = ?',
                        [password_hash($password, PASSWORD_DEFAULT), $id, company_id()]);
                }
                flash('Account updated.');
            } else {
                db_exec('INSERT INTO users (company_id, full_name, username, password_hash, role, is_active) VALUES (?,?,?,?,?,?)',
                    [company_id(), $fullName, $username, password_hash($password, PASSWORD_DEFAULT), $role, $active]);
                flash('Account created.');
            }
            redirect('admin/users.php');
        }
    }

    if ($action === 'toggle') {
        $id = (int)post('id');
        $target = db_one('SELECT is_active FROM users WHERE id = ? AND company_id = ?', [$id, company_id()]);
        if ($id === user_id()) {
            flash('You cannot disable your own account.', 'danger');
        } elseif (!$target) {
            flash('That account no longer exists.', 'danger');
        } elseif (!(int)$target['is_active'] && company_limit_reached('users')) {
            flash('Your plan allows ' . (int)current_company()['max_users'] . ' active accounts. Disable one or upgrade the plan (Billing).', 'danger');
        } else {
            db_exec('UPDATE users SET is_active = 1 - is_active WHERE id = ? AND company_id = ?', [$id, company_id()]);
            flash('Account status changed.');
        }
        redirect('admin/users.php');
    }
}

if (get('edit') !== '') {
    $editing = db_one('SELECT * FROM users WHERE id = ? AND company_id = ?', [(int)get('edit'), company_id()]);
}

$users = db_all(
    'SELECT u.*, (SELECT COUNT(*) FROM orders o WHERE o.created_by = u.id) AS order_count
       FROM users u WHERE u.company_id = ? ORDER BY u.role, u.full_name',
    [company_id()]
);

$pageTitle = 'Users';
require __DIR__ . '/../core/header.php';
?>

<div class="grid grid-cols-1 lg:grid-cols-[350px_1fr] gap-4">
    <div class="card">
        <div class="card-header"><?= $editing ? 'Edit account' : 'New account' ?></div>
        <div class="card-body">
            <form method="post" autocomplete="off" class="space-y-3">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="save">
                <input type="hidden" name="id" value="<?= (int)($editing['id'] ?? 0) ?>">

                <div>
                    <label class="label">Full name</label>
                    <input type="text" name="full_name" class="input" required maxlength="100"
                           value="<?= e($editing['full_name'] ?? '') ?>">
                </div>
                <div>
                    <label class="label">Username</label>
                    <input type="text" name="username" class="input" required maxlength="50"
                           value="<?= e($editing['username'] ?? '') ?>">
                </div>
                <div>
                    <label class="label">Role</label>
                    <select name="role" class="select">
                        <?php foreach ($ROLES as $key => $label): ?>
                            <option value="<?= $key ?>" <?= ($editing['role'] ?? '') === $key ? 'selected' : '' ?>>
                                <?= e($label) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label class="label">Password</label>
                    <input type="password" name="password" class="input"
                           <?= $editing ? '' : 'required' ?> minlength="6">
                    <?php if ($editing): ?>
                        <p class="form-text">Leave blank to keep the current password.</p>
                    <?php endif; ?>
                </div>
                <label class="flex items-center gap-2 cursor-pointer">
                    <input type="checkbox" name="is_active" class="checkbox"
                           <?= (!$editing || (int)$editing['is_active'] === 1) ? 'checked' : '' ?>>
                    <span class="text-sm">Account can sign in</span>
                </label>

                <div class="flex gap-2 pt-1">
                    <button class="btn btn-brand"><?= $editing ? 'Save changes' : 'Create account' ?></button>
                    <?php if ($editing): ?>
                        <a class="btn btn-outline" href="<?= url('admin/users.php') ?>">Cancel</a>
                    <?php endif; ?>
                </div>
            </form>
        </div>
    </div>

    <div>
        <div class="card overflow-x-auto">
        <table class="tbl">
            <thead><tr>
                <th>Name</th><th>Username</th><th>Role</th>
                <th class="text-center">Orders</th><th class="text-center">Status</th><th class="text-right">Actions</th>
            </tr></thead>
            <tbody>
            <?php foreach ($users as $u): ?>
                <tr>
                    <td><?= e($u['full_name']) ?><?= (int)$u['id'] === user_id() ? ' <span class="badge badge-secondary">you</span>' : '' ?></td>
                    <td class="text-muted"><?= e($u['username']) ?></td>
                    <td><span class="badge badge-dark"><?= e(ucfirst($u['role'])) ?></span></td>
                    <td class="text-center"><?= (int)$u['order_count'] ?></td>
                    <td class="text-center">
                        <span class="badge <?= (int)$u['is_active'] ? 'badge-success' : 'badge-secondary' ?>">
                            <?= (int)$u['is_active'] ? 'Active' : 'Disabled' ?>
                        </span>
                    </td>
                    <td class="text-right text-nowrap">
                        <a class="btn btn-outline btn-sm"
                           href="<?= url('admin/users.php?edit=' . (int)$u['id']) ?>">Edit</a>
                        <?php if ((int)$u['id'] !== user_id()): ?>
                            <form method="post" class="inline">
                                <?= csrf_field() ?>
                                <input type="hidden" name="action" value="toggle">
                                <input type="hidden" name="id" value="<?= (int)$u['id'] ?>">
                                <button class="btn btn-outline-warning btn-sm">
                                    <?= (int)$u['is_active'] ? 'Disable' : 'Enable' ?>
                                </button>
                            </form>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
        <p class="text-muted text-xs mt-2">
            Accounts are never deleted — disabling one keeps its sales history intact.
        </p>
    </div>
</div>

<?php require __DIR__ . '/../core/footer.php'; ?>
