<?php
/**
 * Cash drawer shifts. A cashier opens a shift with a starting float, every
 * cash sale and drawer expense attaches to it, and closing it reconciles
 * the counted cash against what the system expects.
 */

require_once __DIR__ . '/../core/config.php';
require_role('cashier');

$isAdmin = has_role('admin');
$shift   = open_shift(user_id());

if (is_post()) {
    csrf_check();
    $action = post('action');

    if ($action === 'open') {
        if ($shift) {
            flash('You already have an open shift.', 'warning');
        } else {
            db_exec('INSERT INTO shifts (user_id, opening_float, note) VALUES (?,?,?)',
                [user_id(), post_amount('opening_float'), post('note') ?: null]);
            flash('Shift opened. The drawer is ready.');
        }
        redirect('admin/shifts.php');
    }

    if ($action === 'close') {
        if (!$shift) {
            flash('You have no open shift to close.', 'warning');
            redirect('admin/shifts.php');
        }

        // An unpaid order left open would not be counted anywhere — warn, do not block.
        $stillOpen = (int)db_value("SELECT COUNT(*) FROM orders WHERE status = 'open'");

        $counted  = post_amount('counted_cash');
        $expected = shift_expected_cash((int)$shift['id']);

        db_exec(
            "UPDATE shifts
                SET closed_at = NOW(), counted_cash = ?, expected_cash = ?,
                    variance = ?, note = ?, status = 'closed'
              WHERE id = ? AND status = 'open'",
            [$counted, $expected, round($counted - $expected, 2), post('note') ?: null, (int)$shift['id']]
        );

        $variance = round($counted - $expected, 2);
        if (abs($variance) < 0.005) {
            flash('Shift closed and the drawer balances exactly.');
        } else {
            flash('Shift closed. Drawer is ' . ($variance > 0 ? 'over' : 'short')
                . ' by ' . money(abs($variance)) . '.', $variance > 0 ? 'warning' : 'danger');
        }
        if ($stillOpen > 0) {
            flash("Note: $stillOpen unpaid order(s) are still open and are not part of this shift's cash.", 'warning');
        }
        redirect('admin/shifts.php');
    }
}

// Live figures for the open shift.
$live = null;
if ($shift) {
    $sid  = (int)$shift['id'];
    $live = [
        'cash_sales'  => (float)db_value("SELECT COALESCE(SUM(total),0) FROM orders WHERE shift_id = ? AND status = 'paid' AND payment_method = 'cash'", [$sid]),
        'other_sales' => (float)db_value("SELECT COALESCE(SUM(total),0) FROM orders WHERE shift_id = ? AND status = 'paid' AND payment_method <> 'cash'", [$sid]),
        'orders'      => (int)db_value("SELECT COUNT(*) FROM orders WHERE shift_id = ? AND status = 'paid'", [$sid]),
        'expenses'    => (float)db_value("SELECT COALESCE(SUM(amount),0) FROM expenses WHERE shift_id = ? AND paid_from = 'drawer'", [$sid]),
        'expected'    => shift_expected_cash($sid),
    ];
}

$history = $isAdmin
    ? db_all("SELECT s.*, u.full_name FROM shifts s JOIN users u ON u.id = s.user_id
               WHERE s.status = 'closed' ORDER BY s.id DESC LIMIT 40")
    : db_all("SELECT s.*, u.full_name FROM shifts s JOIN users u ON u.id = s.user_id
               WHERE s.status = 'closed' AND s.user_id = ? ORDER BY s.id DESC LIMIT 40", [user_id()]);

$pageTitle = 'Cash Drawer';
require __DIR__ . '/../core/header.php';
?>

<?php if (!$shift): ?>
    <div class="row g-3">
        <div class="col-lg-5">
            <div class="card">
                <div class="card-header">Open a shift</div>
                <div class="card-body">
                    <p class="text-muted">Count the cash already in the drawer and enter it as the opening float.</p>
                    <form method="post">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="open">
                        <div class="mb-3">
                            <label class="form-label">Opening float</label>
                            <input type="number" name="opening_float" step="0.01" min="0"
                                   class="form-control form-control-lg text-end" value="0.00" required autofocus>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Note (optional)</label>
                            <input type="text" name="note" class="form-control" maxlength="255">
                        </div>
                        <button class="btn btn-lg text-white w-100" style="background:var(--ok)">Open shift</button>
                    </form>
                </div>
            </div>
        </div>
    </div>
<?php else: ?>
    <div class="row g-3 mb-3">
        <div class="col-6 col-lg-3"><div class="stat-card">
            <div class="label">Opening float</div><div class="value"><?= money($shift['opening_float']) ?></div>
            <small class="text-muted">Since <?= dt($shift['opened_at'], 'g:i A') ?></small>
        </div></div>
        <div class="col-6 col-lg-3"><div class="stat-card good">
            <div class="label">Cash sales</div><div class="value"><?= money($live['cash_sales']) ?></div>
            <small class="text-muted"><?= (int)$live['orders'] ?> paid order(s)</small>
        </div></div>
        <div class="col-6 col-lg-3"><div class="stat-card bad">
            <div class="label">Paid out of drawer</div><div class="value"><?= money($live['expenses']) ?></div>
            <small class="text-muted">Expenses this shift</small>
        </div></div>
        <div class="col-6 col-lg-3"><div class="stat-card accent">
            <div class="label">Drawer should hold</div><div class="value"><?= money($live['expected']) ?></div>
            <small class="text-muted">Float + cash − expenses</small>
        </div></div>
    </div>

    <?php if ($live['other_sales'] > 0): ?>
        <p class="text-muted">Card and mobile-money sales this shift: <strong><?= money($live['other_sales']) ?></strong> (not in the drawer).</p>
    <?php endif; ?>

    <div class="row g-3">
        <div class="col-lg-5">
            <div class="card">
                <div class="card-header">Close the shift</div>
                <div class="card-body">
                    <form method="post" onsubmit="return confirm('Close this shift? It cannot be reopened.');">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="close">
                        <div class="mb-3">
                            <label class="form-label">Cash counted in the drawer</label>
                            <input type="number" name="counted_cash" step="0.01" min="0"
                                   class="form-control form-control-lg text-end" required autofocus>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Note (optional)</label>
                            <input type="text" name="note" class="form-control" maxlength="255"
                                   placeholder="Explain any difference">
                        </div>
                        <button class="btn btn-lg btn-dark w-100">Close shift</button>
                    </form>
                </div>
            </div>
        </div>
        <div class="col-lg-7">
            <a class="btn btn-outline-secondary mb-2" href="<?= url('admin/expenses.php') ?>">Record an expense</a>
            <a class="btn btn-warning mb-2" href="<?= url('public/pos.php') ?>">Back to the POS</a>
        </div>
    </div>
<?php endif; ?>

<h2 class="h6 mt-4 mb-2">Closed shifts</h2>
<table class="table table-sm align-middle">
    <thead>
        <tr><th>Opened</th><th>Closed</th><?= $isAdmin ? '<th>Cashier</th>' : '' ?>
            <th class="text-end">Float</th><th class="text-end">Expected</th>
            <th class="text-end">Counted</th><th class="text-end">Variance</th><th>Note</th></tr>
    </thead>
    <tbody>
    <?php foreach ($history as $h): $v = (float)$h['variance']; ?>
        <tr>
            <td><?= dt($h['opened_at'], 'd M, g:i A') ?></td>
            <td><?= dt($h['closed_at'], 'd M, g:i A') ?></td>
            <?= $isAdmin ? '<td>' . e($h['full_name']) . '</td>' : '' ?>
            <td class="text-end"><?= money($h['opening_float']) ?></td>
            <td class="text-end"><?= money($h['expected_cash']) ?></td>
            <td class="text-end"><?= money($h['counted_cash']) ?></td>
            <td class="text-end fw-bold" style="color:<?= abs($v) < 0.005 ? 'var(--ok)' : 'var(--bad)' ?>">
                <?= ($v > 0 ? '+' : '') . money($v) ?>
            </td>
            <td class="text-muted small"><?= e($h['note'] ?? '') ?></td>
        </tr>
    <?php endforeach; ?>
    <?php if (!$history): ?>
        <tr><td colspan="8" class="text-center text-muted py-4">No shift has been closed yet.</td></tr>
    <?php endif; ?>
    </tbody>
</table>

<?php require __DIR__ . '/../core/footer.php'; ?>
