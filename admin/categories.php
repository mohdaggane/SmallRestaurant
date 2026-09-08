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
                'SELECT id FROM categories WHERE name = ? AND id <> ?',
                [$name, $id]
            );
            if ($clash) {
                flash('Another category is already called "' . $name . '".', 'danger');
            } elseif ($id > 0) {
                db_exec('UPDATE categories SET name = ?, sort_order = ?, is_active = ? WHERE id = ?',
                    [$name, $sort, $active, $id]);
                flash('Category updated.');
            } else {
                db_exec('INSERT INTO categories (name, sort_order, is_active) VALUES (?,?,?)',
                    [$name, $sort, $active]);
                flash('Category added.');
            }
            redirect('admin/categories.php');
        }
    }

    if ($action === 'delete') {
        $id    = (int)post('id');
        $count = (int)db_value('SELECT COUNT(*) FROM menu_items WHERE category_id = ?', [$id]);
        if ($count > 0) {
            flash("That category still holds $count menu item(s). Move or delete them first.", 'danger');
        } else {
            db_exec('DELETE FROM categories WHERE id = ?', [$id]);
            flash('Category deleted.');
        }
        redirect('admin/categories.php');
    }
}

if (get('edit') !== '') {
    $editing = db_one('SELECT * FROM categories WHERE id = ?', [(int)get('edit')]);
}

$rows = db_all(
    'SELECT c.*, (SELECT COUNT(*) FROM menu_items i WHERE i.category_id = c.id) AS item_count
       FROM categories c ORDER BY c.sort_order, c.name'
);

$pageTitle = 'Categories';
require __DIR__ . '/../core/header.php';
?>

<div class="row g-3">
    <div class="col-lg-4">
        <div class="card">
            <div class="card-header"><?= $editing ? 'Edit category' : 'New category' ?></div>
            <div class="card-body">
                <form method="post">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="save">
                    <input type="hidden" name="id" value="<?= (int)($editing['id'] ?? 0) ?>">

                    <div class="mb-3">
                        <label class="form-label">Name</label>
                        <input type="text" name="name" class="form-control" required maxlength="60"
                               value="<?= e($editing['name'] ?? '') ?>">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Sort order</label>
                        <input type="number" name="sort_order" class="form-control"
                               value="<?= (int)($editing['sort_order'] ?? 0) ?>">
                        <div class="form-text">Lower numbers show first on the POS.</div>
                    </div>
                    <div class="form-check mb-3">
                        <input type="checkbox" name="is_active" class="form-check-input" id="ca"
                               <?= (!$editing || (int)$editing['is_active'] === 1) ? 'checked' : '' ?>>
                        <label class="form-check-label" for="ca">Active (shown on the POS)</label>
                    </div>

                    <button class="btn text-white" style="background:var(--brand)">
                        <?= $editing ? 'Save changes' : 'Add category' ?>
                    </button>
                    <?php if ($editing): ?>
                        <a class="btn btn-outline-secondary" href="<?= url('admin/categories.php') ?>">Cancel</a>
                    <?php endif; ?>
                </form>
            </div>
        </div>
    </div>

    <div class="col-lg-8">
        <table class="table align-middle">
            <thead>
                <tr><th>#</th><th>Name</th><th class="text-center">Items</th>
                    <th class="text-center">Status</th><th class="text-end">Actions</th></tr>
            </thead>
            <tbody>
            <?php foreach ($rows as $r): ?>
                <tr>
                    <td><?= (int)$r['sort_order'] ?></td>
                    <td><?= e($r['name']) ?></td>
                    <td class="text-center"><?= (int)$r['item_count'] ?></td>
                    <td class="text-center">
                        <span class="badge bg-<?= (int)$r['is_active'] ? 'success' : 'secondary' ?>">
                            <?= (int)$r['is_active'] ? 'Active' : 'Hidden' ?>
                        </span>
                    </td>
                    <td class="text-end">
                        <a class="btn btn-sm btn-outline-secondary"
                           href="<?= url('admin/categories.php?edit=' . (int)$r['id']) ?>">Edit</a>
                        <form method="post" class="d-inline"
                              onsubmit="return confirm('Delete this category?');">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="delete">
                            <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
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
