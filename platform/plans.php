<?php
/**
 * Plans the platform sells: monthly price and the limits each one enforces
 * (active users, menu items — blank means unlimited). A plan in use can't be
 * deleted, only retired, so every restaurant always points at a real plan.
 */

require_once __DIR__ . '/../core/config.php';
require_platform();

$editing = null;

/** A limit field: blank = unlimited (NULL), otherwise a whole number >= 1. */
function limit_value(string $key): ?int
{
    $v = post($key);
    return $v === '' ? null : max(1, (int)$v);
}

if (is_post()) {
    csrf_check();
    $action = post('action');

    if ($action === 'save') {
        $id    = (int)post('id');
        $name  = post('name');
        $price = post_amount('price_month');
        $users = limit_value('max_users');
        $items = limit_value('max_menu_items');
        $sort  = (int)post('sort_order');
        $act   = isset($_POST['is_active']) ? 1 : 0;

        if ($name === '') {
            flash('A plan needs a name.', 'danger');
        } elseif ($id > 0) {
            db_exec('UPDATE plans SET name = ?, price_month = ?, max_users = ?, max_menu_items = ?, sort_order = ?, is_active = ? WHERE id = ?',
                [$name, $price, $users, $items, $sort, $act, $id]);
            flash('Plan updated. Restaurants on it get the new limits at once.');
        } else {
            db_exec('INSERT INTO plans (name, price_month, max_users, max_menu_items, sort_order, is_active) VALUES (?,?,?,?,?,?)',
                [$name, $price, $users, $items, $sort, $act]);
            flash('Plan added.');
        }
        redirect('platform/plans.php');
    }

    if ($action === 'delete') {
        $id    = (int)post('id');
        $inUse = (int)db_value('SELECT COUNT(*) FROM companies WHERE plan_id = ?', [$id])
               + (int)db_value('SELECT COUNT(*) FROM company_payments WHERE plan_id = ?', [$id]);
        if ($id === TRIAL_PLAN_ID) {
            flash('That is the plan new sign-ups start on (TRIAL_PLAN_ID in core/config.php); it cannot be deleted.', 'danger');
        } elseif ($inUse > 0) {
            flash('That plan is used by restaurants or payments. Untick "Offered" to retire it instead.', 'danger');
        } else {
            db_exec('DELETE FROM plans WHERE id = ?', [$id]);
            flash('Plan deleted.');
        }
        redirect('platform/plans.php');
    }
}

if (get('edit') !== '') {
    $editing = db_one('SELECT * FROM plans WHERE id = ?', [(int)get('edit')]);
}

$plans = db_all(
    'SELECT p.*, (SELECT COUNT(*) FROM companies c WHERE c.plan_id = p.id) AS company_count
       FROM plans p ORDER BY p.sort_order, p.price_month'
);

$pageTitle = 'Plans';
$platform  = true;
require __DIR__ . '/../core/header.php';
?>

<div class="grid grid-cols-1 lg:grid-cols-[350px_1fr] gap-4">
    <div class="card">
        <div class="card-header"><?= $editing ? 'Edit plan' : 'New plan' ?></div>
        <div class="card-body">
            <form method="post" class="space-y-3">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="save">
                <input type="hidden" name="id" value="<?= (int)($editing['id'] ?? 0) ?>">
                <div>
                    <label class="label" for="name">Name</label>
                    <input type="text" id="name" name="name" class="input" maxlength="50" required value="<?= e($editing['name'] ?? '') ?>">
                </div>
                <div>
                    <label class="label" for="price_month">Price per month</label>
                    <input type="number" id="price_month" name="price_month" class="input" step="0.01" min="0" value="<?= e($editing['price_month'] ?? '0.00') ?>">
                </div>
                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <label class="label" for="max_users">Max active users</label>
                        <input type="number" id="max_users" name="max_users" class="input" min="1" value="<?= e($editing['max_users'] ?? '') ?>" placeholder="Unlimited">
                    </div>
                    <div>
                        <label class="label" for="max_menu_items">Max menu items</label>
                        <input type="number" id="max_menu_items" name="max_menu_items" class="input" min="1" value="<?= e($editing['max_menu_items'] ?? '') ?>" placeholder="Unlimited">
                    </div>
                </div>
                <div>
                    <label class="label" for="sort_order">Sort order</label>
                    <input type="number" id="sort_order" name="sort_order" class="input" value="<?= (int)($editing['sort_order'] ?? 0) ?>">
                </div>
                <label class="flex items-center gap-2 text-sm">
                    <input type="checkbox" name="is_active" value="1" <?= ($editing === null || (int)$editing['is_active']) ? 'checked' : '' ?>>
                    Offered (shown on restaurants' Billing page)
                </label>
                <div class="flex gap-2">
                    <button class="btn btn-brand"><?= $editing ? 'Save plan' : 'Add plan' ?></button>
                    <?php if ($editing): ?>
                        <a class="btn btn-outline" href="<?= url('platform/plans.php') ?>">Cancel</a>
                    <?php endif; ?>
                </div>
            </form>
        </div>
    </div>

    <div class="card">
        <table class="tbl">
            <thead><tr>
                <th>Plan</th><th class="text-right">Price / month</th><th class="text-right">Users</th>
                <th class="text-right">Menu items</th><th class="text-right">Restaurants</th><th class="text-center">Offered</th><th></th>
            </tr></thead>
            <tbody>
            <?php foreach ($plans as $p): ?>
                <tr>
                    <td class="font-semibold"><?= e($p['name']) ?><?= (int)$p['id'] === TRIAL_PLAN_ID ? ' <span class="badge badge-warning">sign-up</span>' : '' ?></td>
                    <td class="text-right"><?= e(number_format((float)$p['price_month'], 2)) ?></td>
                    <td class="text-right"><?= $p['max_users'] === null ? '∞' : (int)$p['max_users'] ?></td>
                    <td class="text-right"><?= $p['max_menu_items'] === null ? '∞' : (int)$p['max_menu_items'] ?></td>
                    <td class="text-right"><?= (int)$p['company_count'] ?></td>
                    <td class="text-center"><?= (int)$p['is_active'] ? '✓' : '—' ?></td>
                    <td class="text-right text-nowrap">
                        <a class="btn btn-outline btn-sm" href="?edit=<?= (int)$p['id'] ?>">Edit</a>
                        <form method="post" class="inline" onsubmit="return confirm('Delete this plan?')">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="delete">
                            <input type="hidden" name="id" value="<?= (int)$p['id'] ?>">
                            <button class="btn btn-outline btn-sm text-bad">Delete</button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<?php require __DIR__ . '/../core/footer.php'; ?>
