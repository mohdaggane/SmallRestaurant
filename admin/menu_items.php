<?php
/** Menu items: prices, cost, kitchen routing and availability. */

require_once __DIR__ . '/../core/config.php';
require_role('admin');

$editing = null;

if (is_post()) {
    csrf_check();
    $action = post('action');

    if ($action === 'save') {
        $id       = (int)post('id');
        $name     = post('name');
        $catId    = (int)post('category_id');
        $price    = post_amount('price');
        $cost     = post_amount('cost_price');
        $sort     = (int)post('sort_order');
        $prep     = isset($_POST['needs_prep'])   ? 1 : 0;
        $avail    = isset($_POST['is_available']) ? 1 : 0;

        // The category must be one of this restaurant's own.
        $catOk = $catId > 0
            && db_value('SELECT id FROM categories WHERE id = ? AND company_id = ?', [$catId, company_id()]) !== null;

        if ($name === '' || !$catOk) {
            flash(__('mi.err_name_cat'), 'danger');
        } elseif ($id === 0 && company_limit_reached('menu_items')) {
            flash(__('mi.err_limit', '', ['n' => (int)current_company()['max_menu_items']]), 'danger');
        } elseif ($price <= 0) {
            flash(__('mi.err_price'), 'danger');
        } else {
            if ($id > 0) {
                db_exec(
                    'UPDATE menu_items
                        SET category_id = ?, name = ?, price = ?, cost_price = ?,
                            needs_prep = ?, is_available = ?, sort_order = ?
                      WHERE id = ? AND company_id = ?',
                    [$catId, $name, $price, $cost, $prep, $avail, $sort, $id, company_id()]
                );
                flash(__('mi.updated'));
            } else {
                db_exec(
                    'INSERT INTO menu_items
                        (company_id, category_id, name, price, cost_price, needs_prep, is_available, sort_order)
                     VALUES (?,?,?,?,?,?,?,?)',
                    [company_id(), $catId, $name, $price, $cost, $prep, $avail, $sort]
                );
                flash(__('mi.added'));
            }
            redirect('admin/menu_items.php');
        }
    }

    if ($action === 'toggle') {
        db_exec('UPDATE menu_items SET is_available = 1 - is_available WHERE id = ? AND company_id = ?', [(int)post('id'), company_id()]);
        redirect('admin/menu_items.php');
    }

    if ($action === 'delete') {
        $id   = (int)post('id');
        $sold = (int)db_value('SELECT COUNT(*) FROM order_items WHERE menu_item_id = ? AND company_id = ?', [$id, company_id()]);
        if ($sold > 0) {
            db_exec('UPDATE menu_items SET is_available = 0 WHERE id = ? AND company_id = ?', [$id, company_id()]);
            flash(__('mi.hidden_instead'), 'warning');
        } else {
            db_exec('DELETE FROM menu_items WHERE id = ? AND company_id = ?', [$id, company_id()]);
            flash(__('mi.deleted'));
        }
        redirect('admin/menu_items.php');
    }
}

if (get('edit') !== '') {
    $editing = db_one('SELECT * FROM menu_items WHERE id = ? AND company_id = ?', [(int)get('edit'), company_id()]);
}

$categories = db_all('SELECT * FROM categories WHERE company_id = ? ORDER BY sort_order, name', [company_id()]);
$filterCat  = (int)get('cat');

$sql    = 'SELECT i.*, c.name AS category FROM menu_items i JOIN categories c ON c.id = i.category_id
            WHERE i.company_id = ?';
$params = [company_id()];
if ($filterCat > 0) {
    $sql .= ' AND i.category_id = ?';
    $params[] = $filterCat;
}
$sql .= ' ORDER BY c.sort_order, i.sort_order, i.name';
$items = db_all($sql, $params);

$pageTitle = __('nav.menu_items');
require __DIR__ . '/../core/header.php';
?>

<div class="grid grid-cols-1 lg:grid-cols-[350px_1fr] gap-4">
    <div class="card">
        <div class="card-header"><?= e($editing ? __('mi.edit') : __('mi.new')) ?></div>
        <div class="card-body">
            <form method="post" class="space-y-3">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="save">
                <input type="hidden" name="id" value="<?= (int)($editing['id'] ?? 0) ?>">

                <div>
                    <label class="label"><?= e(__('mi.item_name')) ?></label>
                    <input type="text" name="name" class="input" required maxlength="100"
                           value="<?= e($editing['name'] ?? '') ?>">
                </div>
                <div>
                    <label class="label"><?= e(__('lbl.category')) ?></label>
                    <select name="category_id" class="select" required>
                        <option value=""><?= e(__('lbl.choose')) ?></option>
                        <?php foreach ($categories as $c): ?>
                            <option value="<?= (int)$c['id'] ?>"
                                <?= (int)($editing['category_id'] ?? 0) === (int)$c['id'] ? 'selected' : '' ?>>
                                <?= e($c['name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="grid grid-cols-2 gap-2">
                    <div>
                        <label class="label"><?= e(__('mi.selling_price')) ?></label>
                        <input type="number" name="price" step="0.01" min="0" class="input" required
                               value="<?= e($editing['price'] ?? '') ?>">
                    </div>
                    <div>
                        <label class="label"><?= e(__('mi.cost_price')) ?></label>
                        <input type="number" name="cost_price" step="0.01" min="0" class="input"
                               value="<?= e($editing['cost_price'] ?? '0.00') ?>">
                        <p class="form-text"><?= e(__('mi.cost_help')) ?></p>
                    </div>
                </div>
                <div>
                    <label class="label"><?= e(__('lbl.sort_order')) ?></label>
                    <input type="number" name="sort_order" class="input"
                           value="<?= (int)($editing['sort_order'] ?? 0) ?>">
                </div>
                <label class="flex items-center gap-2 cursor-pointer">
                    <input type="checkbox" name="needs_prep" class="checkbox"
                           <?= (!$editing || (int)$editing['needs_prep'] === 1) ? 'checked' : '' ?>>
                    <span class="text-sm"><?= e(__('mi.send_kitchen')) ?></span>
                </label>
                <p class="form-text -mt-2"><?= e(__('mi.kitchen_help')) ?></p>
                <label class="flex items-center gap-2 cursor-pointer">
                    <input type="checkbox" name="is_available" class="checkbox"
                           <?= (!$editing || (int)$editing['is_available'] === 1) ? 'checked' : '' ?>>
                    <span class="text-sm"><?= e(__('mi.available')) ?></span>
                </label>

                <div class="flex gap-2 pt-1">
                    <button class="btn btn-brand"><?= e($editing ? __('btn.save_changes') : __('mi.add')) ?></button>
                    <?php if ($editing): ?>
                        <a class="btn btn-outline" href="<?= url('admin/menu_items.php') ?>"><?= e(__('btn.cancel')) ?></a>
                    <?php endif; ?>
                </div>
            </form>
        </div>
    </div>

    <div>
        <form class="mb-3" method="get">
            <div class="flex gap-2 items-center">
                <select name="cat" class="select max-w-[260px]" onchange="this.form.submit()">
                    <option value="0"><?= e(__('mi.all_cats')) ?></option>
                    <?php foreach ($categories as $c): ?>
                        <option value="<?= (int)$c['id'] ?>" <?= $filterCat === (int)$c['id'] ? 'selected' : '' ?>>
                            <?= e($c['name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <span class="text-muted text-sm"><?= e(__('mi.count', '', ['n' => count($items)])) ?></span>
            </div>
        </form>

        <div class="card overflow-x-auto">
        <table class="tbl">
            <thead><tr>
                <th><?= e(__('mi.item')) ?></th><th><?= e(__('lbl.category')) ?></th>
                <th class="text-right"><?= e(__('mi.price')) ?></th><th class="text-right"><?= e(__('mi.cost')) ?></th>
                <th class="text-center"><?= e(__('nav.kitchen')) ?></th><th class="text-center"><?= e(__('lbl.status')) ?></th>
                <th class="text-right"><?= e(__('lbl.actions')) ?></th>
            </tr></thead>
            <tbody>
            <?php foreach ($items as $i): ?>
                <tr>
                    <td><?= e($i['name']) ?></td>
                    <td class="text-muted"><?= e($i['category']) ?></td>
                    <td class="text-right"><?= money($i['price']) ?></td>
                    <td class="text-right text-muted"><?= money($i['cost_price']) ?></td>
                    <td class="text-center"><?= (int)$i['needs_prep'] ? '✔' : '—' ?></td>
                    <td class="text-center">
                        <span class="badge <?= (int)$i['is_available'] ? 'badge-success' : 'badge-secondary' ?>">
                            <?= e((int)$i['is_available'] ? __('mi.on_sale') : __('lbl.hidden')) ?>
                        </span>
                    </td>
                    <td class="text-right text-nowrap">
                        <a class="btn btn-outline btn-sm"
                           href="<?= url('admin/menu_items.php?edit=' . (int)$i['id']) ?>"><?= e(__('btn.edit')) ?></a>
                        <form method="post" class="inline">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="toggle">
                            <input type="hidden" name="id" value="<?= (int)$i['id'] ?>">
                            <button class="btn btn-outline-warning btn-sm">
                                <?= e((int)$i['is_available'] ? __('btn.hide') : __('btn.show')) ?>
                            </button>
                        </form>
                        <form method="post" class="inline" onsubmit="return confirm(<?= e(json_encode(__('mi.delete_confirm'))) ?>);">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="delete">
                            <input type="hidden" name="id" value="<?= (int)$i['id'] ?>">
                            <button class="btn btn-outline-danger btn-sm"><?= e(__('btn.delete')) ?></button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
    </div>
</div>

<?php require __DIR__ . '/../core/footer.php'; ?>
