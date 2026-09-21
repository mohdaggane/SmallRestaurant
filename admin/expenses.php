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
                'INSERT INTO expenses (company_id, spent_on, category, description, amount, paid_from, shift_id, user_id)
                 VALUES (?,?,?,?,?,?,?,?)',
                [company_id(), $date, $cat, $desc, $amount, $from,
                 ($from === 'drawer' && $shift) ? (int)$shift['id'] : null, user_id()]
            );
            flash('Expense recorded.');
        }
        redirect('admin/expenses.php');
    }

    if ($action === 'delete' && $isAdmin) {
        db_exec('DELETE FROM expenses WHERE id = ? AND company_id = ?', [(int)post('id'), company_id()]);
        flash('Expense deleted.');
        redirect('admin/expenses.php');
    }
}

$from = get('from') !== '' ? get('from') : date('Y-m-01');
$to   = get('to')   !== '' ? get('to')   : date('Y-m-d');

$rows = db_all(
    'SELECT e.*, u.full_name
       FROM expenses e JOIN users u ON u.id = e.user_id
      WHERE e.company_id = ? AND e.spent_on BETWEEN ? AND ?
      ORDER BY e.spent_on DESC, e.id DESC',
    [company_id(), $from, $to]
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

<div class="grid grid-cols-1 lg:grid-cols-[350px_1fr] gap-4">
    <div class="space-y-3">
        <div class="card">
            <div class="card-header">Record an expense</div>
            <div class="card-body">
                <?php if (!$shift): ?>
                    <div class="flash-warning text-sm">
                        No shift is open, so a drawer expense will not be tied to a cash drawer.
                    </div>
                <?php endif; ?>
                <form method="post" class="space-y-3">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="save">

                    <div>
                        <label class="label">Description</label>
                        <input type="text" name="description" class="input" required maxlength="255"
                               placeholder="e.g. Sugar 5kg" autofocus>
                    </div>
                    <div class="grid grid-cols-2 gap-2">
                        <div>
                            <label class="label">Category</label>
                            <select name="category" class="select">
                                <?php foreach ($CATEGORIES as $c): ?>
                                    <option value="<?= e($c) ?>"><?= e($c) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div>
                            <label class="label">Amount</label>
                            <input type="number" name="amount" step="0.01" min="0.01"
                                   class="input text-right" required>
                        </div>
                    </div>
                    <div class="grid grid-cols-2 gap-2">
                        <div>
                            <label class="label">Date</label>
                            <input type="date" name="spent_on" class="input" value="<?= date('Y-m-d') ?>">
                        </div>
                        <div>
                            <label class="label">Paid from</label>
                            <select name="paid_from" class="select">
                                <option value="drawer">Till drawer</option>
                                <option value="other">Own / bank money</option>
                            </select>
                        </div>
                    </div>

                    <button class="btn btn-brand w-full">Save expense</button>
                </form>
            </div>
        </div>

        <?php if ($byCat): ?>
            <div class="card">
                <div class="card-header">By category · <?= dt($from, 'd M') ?> to <?= dt($to, 'd M') ?></div>
                <ul class="divide-y divide-line">
                    <?php foreach ($byCat as $cat => $amt): ?>
                        <li class="px-4 py-2.5 flex justify-between text-sm">
                            <span><?= e($cat) ?></span><strong><?= money($amt) ?></strong>
                        </li>
                    <?php endforeach; ?>
                    <li class="px-4 py-2.5 flex justify-between text-sm bg-brand-light/40">
                        <strong>Total</strong><strong><?= money($total) ?></strong>
                    </li>
                </ul>
            </div>
        <?php endif; ?>
    </div>

    <div>
        <form class="flex flex-wrap gap-2 mb-3" method="get">
            <input type="date" name="from" class="input" value="<?= e($from) ?>">
            <span class="self-center text-muted">to</span>
            <input type="date" name="to" class="input" value="<?= e($to) ?>">
            <button class="btn btn-outline">Filter</button>
        </form>

        <div class="card overflow-x-auto">
        <table class="tbl">
            <thead><tr>
                <th>Date</th><th>Description</th><th>Category</th><th>Paid from</th>
                <th>By</th><th class="text-right">Amount</th><?= $isAdmin ? '<th></th>' : '' ?>
            </tr></thead>
            <tbody>
            <?php foreach ($rows as $r): ?>
                <tr>
                    <td><?= dt($r['spent_on'], 'd M Y') ?></td>
                    <td><?= e($r['description']) ?></td>
                    <td class="text-muted"><?= e($r['category']) ?></td>
                    <td>
                        <span class="badge <?= $r['paid_from'] === 'drawer' ? 'badge-warning' : 'badge-secondary' ?>">
                            <?= $r['paid_from'] === 'drawer' ? 'Drawer' : 'Other' ?>
                        </span>
                    </td>
                    <td class="text-muted"><?= e($r['full_name']) ?></td>
                    <td class="text-right font-semibold"><?= money($r['amount']) ?></td>
                    <?php if ($isAdmin): ?>
                        <td class="text-right">
                            <form method="post" onsubmit="return confirm('Delete this expense?');">
                                <?= csrf_field() ?>
                                <input type="hidden" name="action" value="delete">
                                <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                                <button class="btn btn-outline-danger btn-sm">&times;</button>
                            </form>
                        </td>
                    <?php endif; ?>
                </tr>
            <?php endforeach; ?>
            <?php if (!$rows): ?>
                <tr><td colspan="7" class="text-center text-muted py-8">No expenses in this period.</td></tr>
            <?php endif; ?>
            </tbody>
            <tfoot>
                <tr>
                    <th colspan="5" class="text-right">Total</th>
                    <th class="text-right"><?= money($total) ?></th>
                    <?= $isAdmin ? '<th></th>' : '' ?>
                </tr>
            </tfoot>
        </table>
        </div>
    </div>
</div>

<?php require __DIR__ . '/../core/footer.php'; ?>
