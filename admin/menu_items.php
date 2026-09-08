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

        if ($name === '' || $catId <= 0) {
            flash('An item needs a name and a category.', 'danger');
        } elseif ($price <= 0) {
            flash('The selling price must be greater than zero.', 'danger');
        } else {
            if ($id > 0) {
                db_exec(
                    'UPDATE menu_items
                        SET category_id = ?, name = ?, price = ?, cost_price = ?,
                            needs_prep = ?, is_available = ?, sort_order = ?
                      WHERE id = ?',
                    [$catId, $name, $price, $cost, $prep, $avail, $sort, $id]
                );
                flash('Menu item updated. Past receipts keep their old price.');
            } else {
                db_exec(
                    'INSERT INTO menu_items
                        (category_id, name, price, cost_price, needs_prep, is_available, sort_order)
                     VALUES (?,?,?,?,?,?,?)',
                    [$catId, $name, $price, $cost, $prep, $avail, $sort]
                );
                flash('Menu item added.');
            }
            redirect('admin/menu_items.php');
        }
    }

    if ($action === 'toggle') {
        db_exec('UPDATE menu_items SET is_available = 1 - is_available WHERE id = ?', [(int)post('id')]);
        redirect('admin/menu_items.php');
    }

    if ($action === 'delete') {
        $id   = (int)post('id');
        $sold = (int)db_value('SELECT COUNT(*) FROM order_items WHERE menu_item_id = ?', [$id]);
        if ($sold > 0) {
            // Keep the sales history intact — hide it instead of deleting.
            db_exec('UPDATE menu_items SET is_available = 0 WHERE id = ?', [$id]);
            flash('That item has been sold before, so it was hidden instead of deleted (sales history is kept).', 'warning');
        } else {
            db_exec('DELETE FROM menu_items WHERE id = ?', [$id]);
            flash('Menu item deleted.');
        }
        redirect('admin/menu_items.php');
    }
}

if (get('edit') !== '') {
    $editing = db_one('SELECT * FROM menu_items WHERE id = ?', [(int)get('edit')]);
}

$categories = db_all('SELECT * FROM categories ORDER BY sort_order, name');
$filterCat  = (int)get('cat');

$sql    = 'SELECT i.*, c.name AS category FROM menu_items i JOIN categories c ON c.id = i.category_id';
$params = [];
if ($filterCat > 0) {
    $sql .= ' WHERE i.category_id = ?';
    $params[] = $filterCat;
}
$sql .= ' ORDER BY c.sort_order, i.sort_order, i.name';
$items = db_all($sql, $params);

$pageTitle = 'Menu Items';
require __DIR__ . '/../core/header.php';
?>

<div class="row g-3">
    <div class="col-lg-4">
        <div class="card">
            <div class="card-header"><?= $editing ? 'Edit item' : 'New item' ?></div>
            <div class="card-body">
                <form method="post">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="save">
                    <input type="hidden" name="id" value="<?= (int)($editing['id'] ?? 0) ?>">

                    <div class="mb-3">
                        <label class="form-label">Item name</label>
                        <input type="text" name="name" class="form-control" required maxlength="100"
                               value="<?= e($editing['name'] ?? '') ?>">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Category</label>
                        <select name="category_id" class="form-select" required>
                            <option value="">Choose…</option>
                            <?php foreach ($categories as $c): ?>
                                <option value="<?= (int)$c['id'] ?>"
                                    <?= (int)($editing['category_id'] ?? 0) === (int)$c['id'] ? 'selected' : '' ?>>
                                    <?= e($c['name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="row g-2 mb-3">
                        <div class="col-6">
                            <label class="form-label">Selling price</label>
                            <input type="number" name="price" step="0.01" min="0" class="form-control" required
                                   value="<?= e($editing['price'] ?? '') ?>">
                        </div>
                        <div class="col-6">
                            <label class="form-label">Cost price</label>
                            <input type="number" name="cost_price" step="0.01" min="0" class="form-control"
                                   value="<?= e($editing['cost_price'] ?? '0.00') ?>">
                            <div class="form-text">Used for the profit column in reports.</div>
                        </div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Sort order</label>
                        <input type="number" name="sort_order" class="form-control"
                               value="<?= (int)($editing['sort_order'] ?? 0) ?>">
                    </div>
                    <div class="form-check mb-2">
                        <input type="checkbox" name="needs_prep" class="form-check-input" id="np"
                               <?= (!$editing || (int)$editing['needs_prep'] === 1) ? 'checked' : '' ?>>
                        <label class="form-check-label" for="np">Send to the kitchen screen</label>
                        <div class="form-text">Uncheck for bottled drinks handed over straight away.</div>
                    </div>
                    <div class="form-check mb-3">
                        <input type="checkbox" name="is_available" class="form-check-input" id="av"
                               <?= (!$editing || (int)$editing['is_available'] === 1) ? 'checked' : '' ?>>
                        <label class="form-check-label" for="av">Available for sale</label>
                    </div>

                    <button class="btn text-white" style="background:var(--brand)">
                        <?= $editing ? 'Save changes' : 'Add item' ?>
                    </button>
                    <?php if ($editing): ?>
                        <a class="btn btn-outline-secondary" href="<?= url('admin/menu_items.php') ?>">Cancel</a>
                    <?php endif; ?>
                </form>
            </div>
        </div>
    </div>

    <div class="col-lg-8">
        <form class="mb-3" method="get">
            <div class="d-flex gap-2">
                <select name="cat" class="form-select" style="max-width:260px" onchange="this.form.submit()">
                    <option value="0">All categories</option>
                    <?php foreach ($categories as $c): ?>
                        <option value="<?= (int)$c['id'] ?>" <?= $filterCat === (int)$c['id'] ? 'selected' : '' ?>>
                            <?= e($c['name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <span class="align-self-center text-muted"><?= count($items) ?> item(s)</span>
            </div>
        </form>

        <table class="table align-middle">
            <thead>
                <tr><th>Item</th><th>Category</th><th class="text-end">Price</th>
                    <th class="text-end">Cost</th><th class="text-center">Kitchen</th>
                    <th class="text-center">Status</th><th class="text-end">Actions</th></tr>
            </thead>
            <tbody>
            <?php foreach ($items as $i): ?>
                <tr>
                    <td><?= e($i['name']) ?></td>
                    <td class="text-muted"><?= e($i['category']) ?></td>
                    <td class="text-end"><?= money($i['price']) ?></td>
                    <td class="text-end text-muted"><?= money($i['cost_price']) ?></td>
                    <td class="text-center"><?= (int)$i['needs_prep'] ? '✔' : '—' ?></td>
                    <td class="text-center">
                        <span class="badge bg-<?= (int)$i['is_available'] ? 'success' : 'secondary' ?>">
                            <?= (int)$i['is_available'] ? 'On sale' : 'Hidden' ?>
                        </span>
                    </td>
                    <td class="text-end text-nowrap">
                        <a class="btn btn-sm btn-outline-secondary"
                           href="<?= url('admin/menu_items.php?edit=' . (int)$i['id']) ?>">Edit</a>
                        <form method="post" class="d-inline">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="toggle">
                            <input type="hidden" name="id" value="<?= (int)$i['id'] ?>">
                            <button class="btn btn-sm btn-outline-warning">
                                <?= (int)$i['is_available'] ? 'Hide' : 'Show' ?>
                            </button>
                        </form>
                        <form method="post" class="d-inline" onsubmit="return confirm('Delete this item?');">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="delete">
                            <input type="hidden" name="id" value="<?= (int)$i['id'] ?>">
                            <button class="btn btn-sm btn-outline-danger">Delete</button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<?php require __DIR__ . '/../core/footer.php'; ?>
