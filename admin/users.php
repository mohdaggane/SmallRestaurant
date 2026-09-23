<?php
/** Staff accounts and roles. Admin only. */

require_once __DIR__ . '/../core/config.php';
require_role('admin');

$ROLES = [
    'admin'   => __('us.role_admin'),
    'cashier' => __('us.role_cashier'),
    'waiter'  => __('us.role_waiter'),
    'kitchen' => __('us.role_kitchen'),
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
            flash(__('us.err_gone'), 'danger');
        } elseif ($fullName === '' || $username === '') {
            flash(__('us.err_required'), 'danger');
        } elseif ($clash) {
            $suffix = current_company()['slug'] ?? '';
            flash(__('us.err_taken', '', ['suggest' => $username . '.' . $suffix]), 'danger');
        } elseif ($addsSeat && company_limit_reached('users')) {
            flash(__('us.err_limit', '', ['n' => (int)current_company()['max_users']]), 'danger');
        } elseif ($id === 0 && strlen($password) < 6) {
            flash(__('us.err_pw_new'), 'danger');
        } elseif ($id > 0 && $password !== '' && strlen($password) < 6) {
            flash(__('pc.err_pw'), 'danger');
        } elseif ($id === user_id() && ($role !== 'admin' || $active === 0)) {
            flash(__('us.err_self'), 'danger');
        } else {
            if ($id > 0) {
                db_exec('UPDATE users SET full_name = ?, username = ?, role = ?, is_active = ? WHERE id = ? AND company_id = ?',
                    [$fullName, $username, $role, $active, $id, company_id()]);
                if ($password !== '') {
                    db_exec('UPDATE users SET password_hash = ? WHERE id = ? AND company_id = ?',
                        [password_hash($password, PASSWORD_DEFAULT), $id, company_id()]);
                }
                flash(__('us.updated'));
            } else {
                db_exec('INSERT INTO users (company_id, full_name, username, password_hash, role, is_active) VALUES (?,?,?,?,?,?)',
                    [company_id(), $fullName, $username, password_hash($password, PASSWORD_DEFAULT), $role, $active]);
                flash(__('us.created'));
            }
            redirect('admin/users.php');
        }
    }

    if ($action === 'toggle') {
        $id = (int)post('id');
        $target = db_one('SELECT is_active FROM users WHERE id = ? AND company_id = ?', [$id, company_id()]);
        if ($id === user_id()) {
            flash(__('us.err_self_off'), 'danger');
        } elseif (!$target) {
            flash(__('us.err_gone'), 'danger');
        } elseif (!(int)$target['is_active'] && company_limit_reached('users')) {
            flash(__('us.err_limit', '', ['n' => (int)current_company()['max_users']]), 'danger');
        } else {
            db_exec('UPDATE users SET is_active = 1 - is_active WHERE id = ? AND company_id = ?', [$id, company_id()]);
            flash(__('us.toggled'));
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

$pageTitle = __('nav.users');
require __DIR__ . '/../core/header.php';
?>

<div class="grid grid-cols-1 lg:grid-cols-[350px_1fr] gap-4">
    <div class="card">
        <div class="card-header"><?= e($editing ? __('us.edit') : __('us.new')) ?></div>
        <div class="card-body">
            <form method="post" autocomplete="off" class="space-y-3">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="save">
                <input type="hidden" name="id" value="<?= (int)($editing['id'] ?? 0) ?>">

                <div>
                    <label class="label"><?= e(__('us.full_name')) ?></label>
                    <input type="text" name="full_name" class="input" required maxlength="100"
                           value="<?= e($editing['full_name'] ?? '') ?>">
                </div>
                <div>
                    <label class="label"><?= e(__('auth.username')) ?></label>
                    <input type="text" name="username" class="input" required maxlength="50"
                           value="<?= e($editing['username'] ?? '') ?>">
                </div>
                <div>
                    <label class="label"><?= e(__('lbl.role')) ?></label>
                    <select name="role" class="select">
                        <?php foreach ($ROLES as $key => $label): ?>
                            <option value="<?= $key ?>" <?= ($editing['role'] ?? '') === $key ? 'selected' : '' ?>>
                                <?= e($label) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label class="label"><?= e(__('auth.password')) ?></label>
                    <input type="password" name="password" class="input"
                           <?= $editing ? '' : 'required' ?> minlength="6">
                    <?php if ($editing): ?>
                        <p class="form-text"><?= e(__('us.keep_pw')) ?></p>
                    <?php endif; ?>
                </div>
                <label class="flex items-center gap-2 cursor-pointer">
                    <input type="checkbox" name="is_active" class="checkbox"
                           <?= (!$editing || (int)$editing['is_active'] === 1) ? 'checked' : '' ?>>
                    <span class="text-sm"><?= e(__('us.can_sign_in')) ?></span>
                </label>

                <div class="flex gap-2 pt-1">
                    <button class="btn btn-brand"><?= e($editing ? __('btn.save_changes') : __('us.create')) ?></button>
                    <?php if ($editing): ?>
                        <a class="btn btn-outline" href="<?= url('admin/users.php') ?>"><?= e(__('btn.cancel')) ?></a>
                    <?php endif; ?>
                </div>
            </form>
        </div>
    </div>

    <div>
        <div class="card overflow-x-auto">
        <table class="tbl">
            <thead><tr>
                <th><?= e(__('lbl.name')) ?></th><th><?= e(__('auth.username')) ?></th><th><?= e(__('lbl.role')) ?></th>
                <th class="text-center"><?= e(__('lbl.orders')) ?></th><th class="text-center"><?= e(__('lbl.status')) ?></th><th class="text-right"><?= e(__('lbl.actions')) ?></th>
            </tr></thead>
            <tbody>
            <?php foreach ($users as $u): ?>
                <tr>
                    <td><?= e($u['full_name']) ?><?= (int)$u['id'] === user_id() ? ' <span class="badge badge-secondary">' . e(__('us.you')) . '</span>' : '' ?></td>
                    <td class="text-muted"><?= e($u['username']) ?></td>
                    <td><span class="badge badge-dark"><?= e(__('role.' . $u['role'], ucfirst($u['role']))) ?></span></td>
                    <td class="text-center"><?= (int)$u['order_count'] ?></td>
                    <td class="text-center">
                        <span class="badge <?= (int)$u['is_active'] ? 'badge-success' : 'badge-secondary' ?>">
                            <?= e((int)$u['is_active'] ? __('st.active') : __('st.disabled')) ?>
                        </span>
                    </td>
                    <td class="text-right text-nowrap">
                        <a class="btn btn-outline btn-sm"
                           href="<?= url('admin/users.php?edit=' . (int)$u['id']) ?>"><?= e(__('btn.edit')) ?></a>
                        <?php if ((int)$u['id'] !== user_id()): ?>
                            <form method="post" class="inline">
                                <?= csrf_field() ?>
                                <input type="hidden" name="action" value="toggle">
                                <input type="hidden" name="id" value="<?= (int)$u['id'] ?>">
                                <button class="btn btn-outline-warning btn-sm">
                                    <?= e((int)$u['is_active'] ? __('btn.disable') : __('btn.enable')) ?>
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
            <?= e(__('us.never_deleted')) ?>
        </p>
    </div>
</div>

<?php require __DIR__ . '/../core/footer.php'; ?>
