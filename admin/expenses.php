<?php
/**
 * Daily expenses. Anything paid out of the till attaches to the open shift
 * so the drawer reconciles at closing time.
 */

require_once __DIR__ . '/../core/config.php';
require_role('cashier');

$isAdmin = has_role('admin');
$shift   = open_shift(user_id());

$CATEGORIES = ['Supplies', 'Ingredients', 'Gas & Charcoal', 'Water', 'Electricity',
               'Transport', 'Wages', 'Rent', 'Repairs', 'General'];

if (is_post()) {
    csrf_check();
    $action = post('action');

    if ($action === 'save') {
        $desc   = post('description');
        $amount = post_amount('amount');
        $from   = post('paid_from') === 'other' ? 'other' : 'drawer';
        $cat    = in_array(post('category'), $CATEGORIES, true) ? post('category') : 'General';
        $date   = post('spent_on') !== '' ? post('spent_on') : date('Y-m-d');

        if ($desc === '' || $amount <= 0) {
            flash('An expense needs a description and an amount above zero.', 'danger');
        } else {
            db_exec(
                'INSERT INTO expenses (spent_on, category, description, amount, paid_from, shift_id, user_id)
                 VALUES (?,?,?,?,?,?,?)',
                [$date, $cat, $desc, $amount, $from,
                 ($from === 'drawer' && $shift) ? (int)$shift['id'] : null, user_id()]
            );
            flash('Expense recorded.');
        }
        redirect('admin/expenses.php');
    }

    if ($action === 'delete' && $isAdmin) {
        db_exec('DELETE FROM expenses WHERE id = ?', [(int)post('id')]);
        flash('Expense deleted.');
        redirect('admin/expenses.php');
    }
}

$from = get('from') !== '' ? get('from') : date('Y-m-01');
$to   = get('to')   !== '' ? get('to')   : date('Y-m-d');

$rows = db_all(
    'SELECT e.*, u.full_name
       FROM expenses e JOIN users u ON u.id = e.user_id
      WHERE e.spent_on BETWEEN ? AND ?
      ORDER BY e.spent_on DESC, e.id DESC',
    [$from, $to]
);

$total    = array_sum(array_map(fn($r) => (float)$r['amount'], $rows));
$byCat    = [];
foreach ($rows as $r) {
    $byCat[$r['category']] = ($byCat[$r['category']] ?? 0) + (float)$r['amount'];
}
arsort($byCat);

$pageTitle = 'Expenses';
require __DIR__ . '/../core/header.php';
?>

<div class="row g-3">
    <div class="col-lg-4">
        <div class="card">
            <div class="card-header">Record an expense</div>
            <div class="card-body">
                <?php if (!$shift): ?>
                    <div class="alert alert-warning py-2 small">
                        No shift is open, so a drawer expense will not be tied to a cash drawer.
                    </div>
                <?php endif; ?>
                <form method="post">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="save">

                    <div class="mb-3">
                        <label class="form-label">Description</label>
                        <input type="text" name="description" class="form-control" required maxlength="255"
                               placeholder="e.g. Sugar 5kg" autofocus>
                    </div>
                    <div class="row g-2 mb-3">
                        <div class="col-7">
                            <label class="form-label">Category</label>
                            <select name="category" class="form-select">
                                <?php foreach ($CATEGORIES as $c): ?>
                                    <option value="<?= e($c) ?>"><?= e($c) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-5">
                            <label class="form-label">Amount</label>
                            <input type="number" name="amount" step="0.01" min="0.01"
                                   class="form-control text-end" required>
                        </div>
                    </div>
                    <div class="row g-2 mb-3">
                        <div class="col-6">
                            <label class="form-label">Date</label>
                            <input type="date" name="spent_on" class="form-control" value="<?= date('Y-m-d') ?>">
                        </div>
                        <div class="col-6">
                            <label class="form-label">Paid from</label>
                            <select name="paid_from" class="form-select">
                                <option value="drawer">Till drawer</option>
                                <option value="other">Own / bank money</option>
                            </select>
                        </div>
                    </div>

                    <button class="btn text-white w-100" style="background:var(--brand)">Save expense</button>
                </form>
            </div>
        </div>

        <?php if ($byCat): ?>
            <div class="card mt-3">
                <div class="card-header">By category · <?= dt($from, 'd M') ?> to <?= dt($to, 'd M') ?></div>
                <ul class="list-group list-group-flush">
                    <?php foreach ($byCat as $cat => $amt): ?>
                        <li class="list-group-item d-flex justify-content-between">
                            <span><?= e($cat) ?></span><strong><?= money($amt) ?></strong>
                        </li>
                    <?php endforeach; ?>
                    <li class="list-group-item d-flex justify-content-between bg-light">
                        <strong>Total</strong><strong><?= money($total) ?></strong>
                    </li>
                </ul>
            </div>
        <?php endif; ?>
    </div>

    <div class="col-lg-8">
        <form class="row g-2 mb-3" method="get">
            <div class="col-auto">
                <input type="date" name="from" class="form-control" value="<?= e($from) ?>">
            </div>
            <div class="col-auto align-self-center">to</div>
            <div class="col-auto">
                <input type="date" name="to" class="form-control" value="<?= e($to) ?>">
            </div>
            <div class="col-auto">
                <button class="btn btn-outline-secondary">Filter</button>
            </div>
        </form>

        <table class="table table-sm align-middle">
            <thead>
                <tr><th>Date</th><th>Description</th><th>Category</th><th>Paid from</th>
                    <th>By</th><th class="text-end">Amount</th><?= $isAdmin ? '<th></th>' : '' ?></tr>
            </thead>
            <tbody>
            <?php foreach ($rows as $r): ?>
                <tr>
                    <td><?= dt($r['spent_on'], 'd M Y') ?></td>
                    <td><?= e($r['description']) ?></td>
                    <td class="text-muted"><?= e($r['category']) ?></td>
                    <td><span class="badge bg-<?= $r['paid_from'] === 'drawer' ? 'warning text-dark' : 'secondary' ?>">
                        <?= $r['paid_from'] === 'drawer' ? 'Drawer' : 'Other' ?></span></td>
                    <td class="text-muted"><?= e($r['full_name']) ?></td>
                    <td class="text-end fw-bold"><?= money($r['amount']) ?></td>
                    <?php if ($isAdmin): ?>
                        <td class="text-end">
                            <form method="post" onsubmit="return confirm('Delete this expense?');">
                                <?= csrf_field() ?>
                                <input type="hidden" name="action" value="delete">
                                <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                                <button class="btn btn-sm btn-outline-danger">×</button>
                            </form>
                        </td>
                    <?php endif; ?>
                </tr>
            <?php endforeach; ?>
            <?php if (!$rows): ?>
                <tr><td colspan="7" class="text-center text-muted py-4">No expenses in this period.</td></tr>
            <?php endif; ?>
            </tbody>
            <tfoot>
                <tr class="table-light">
                    <th colspan="5" class="text-end">Total</th>
                    <th class="text-end"><?= money($total) ?></th>
                    <?= $isAdmin ? '<th></th>' : '' ?>
                </tr>
            </tfoot>
        </table>
    </div>
</div>

<?php require __DIR__ . '/../core/footer.php'; ?>
