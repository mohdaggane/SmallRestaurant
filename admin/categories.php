<?php
/** Menu categories: add, rename, reorder, activate/deactivate, delete. */

require_once __DIR__ . '/../core/config.php';
require_role('admin');

$editing = null;

if (is_post()) {
    csrf_check();
    $action = post('action');

    if ($action === 'save') {
        $id    = (int)post('id');
        $name  = post('name');
        $sort  = (int)post('sort_order');
        $active = isset($_POST['is_active']) ? 1 : 0;

        if ($name === '') {
            flash('The category needs a name.', 'danger');
        } else {
            $clash = db_one(
                'SELECT id FROM categories WHERE company_id = ? AND name = ? AND id <> ?',
                [company_id(), $name, $id]
            );
            if ($clash) {
                flash('Another category is already called "' . $name . '".', 'danger');
            } elseif ($id > 0) {
                db_exec('UPDATE categories SET name = ?, sort_order = ?, is_active = ? WHERE id = ? AND company_id = ?',
                    [$name, $sort, $active, $id, company_id()]);
                flash('Category updated.');
            } else {
                db_exec('INSERT INTO categories (company_id, name, sort_order, is_active) VALUES (?,?,?,?)',
                    [company_id(), $name, $sort, $active]);
                flash('Category added.');
            }
            redirect('admin/categories.php');
        }
    }

    if ($action === 'delete') {
        $id    = (int)post('id');
        $count = (int)db_value('SELECT COUNT(*) FROM menu_items WHERE category_id = ? AND company_id = ?', [$id, company_id()]);
        if ($count > 0) {
            flash("That category still holds $count menu item(s). Move or delete them first.", 'danger');
        } else {
            db_exec('DELETE FROM categories WHERE id = ? AND company_id = ?', [$id, company_id()]);
            flash('Category deleted.');
        }
        redirect('admin/categories.php');
    }
}

if (get('edit') !== '') {
    $editing = db_one('SELECT * FROM categories WHERE id = ? AND company_id = ?', [(int)get('edit'), company_id()]);
}

$rows = db_all(
    'SELECT c.*, (SELECT COUNT(*) FROM menu_items i WHERE i.category_id = c.id) AS item_count
       FROM categories c WHERE c.company_id = ? ORDER BY c.sort_order, c.name',
    [company_id()]
);

$pageTitle = 'Categories';
require __DIR__ . '/../core/header.php';
?>

<div class="grid grid-cols-1 lg:grid-cols-[350px_1fr] gap-4">
    <div class="card">
        <div class="card-header"><?= $editing ? 'Edit category' : 'New category' ?></div>
        <div class="card-body">
            <form method="post" class="space-y-3">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="save">
                <input type="hidden" name="id" value="<?= (int)($editing['id'] ?? 0) ?>">

                <div>
                    <label class="label">Name</label>
                    <input type="text" name="name" class="input" required maxlength="60"
                           value="<?= e($editing['name'] ?? '') ?>">
                </div>
                <div>
                    <label class="label">Sort order</label>
                    <input type="number" name="sort_order" class="input"
                           value="<?= (int)($editing['sort_order'] ?? 0) ?>">
                    <p class="form-text">Lower numbers show first on the POS.</p>
                </div>
                <label class="flex items-center gap-2 cursor-pointer">
                    <input type="checkbox" name="is_active" class="checkbox"
                           <?= (!$editing || (int)$editing['is_active'] === 1) ? 'checked' : '' ?>>
                    <span class="text-sm">Active (shown on the POS)</span>
                </label>

                <div class="flex gap-2 pt-1">
                    <button class="btn btn-brand"><?= $editing ? 'Save changes' : 'Add category' ?></button>
                    <?php if ($editing): ?>
                        <a class="btn btn-outline" href="<?= url('admin/categories.php') ?>">Cancel</a>
                    <?php endif; ?>
                </div>
            </form>
        </div>
    </div>

    <div class="card overflow-x-auto">
    <table class="tbl">
        <thead><tr>
            <th>#</th><th>Name</th><th class="text-center">Items</th>
            <th class="text-center">Status</th><th class="text-right">Actions</th>
        </tr></thead>
        <tbody>
        <?php foreach ($rows as $r): ?>
            <tr>
                <td><?= (int)$r['sort_order'] ?></td>
                <td><?= e($r['name']) ?></td>
                <td class="text-center"><?= (int)$r['item_count'] ?></td>
                <td class="text-center">
                    <span class="badge <?= (int)$r['is_active'] ? 'badge-success' : 'badge-secondary' ?>">
                        <?= (int)$r['is_active'] ? 'Active' : 'Hidden' ?>
                    </span>
                </td>
                <td class="text-right text-nowrap">
                    <a class="btn btn-outline btn-sm"
                       href="<?= url('admin/categories.php?edit=' . (int)$r['id']) ?>">Edit</a>
                    <form method="post" class="inline"
                          onsubmit="return confirm('Delete this category?');">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="delete">
                        <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                        <button class="btn btn-outline-danger btn-sm">Delete</button>
                    </form>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
</div>

<?php require __DIR__ . '/../core/footer.php'; ?>
