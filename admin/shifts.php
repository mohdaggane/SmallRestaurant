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
            flash(__('sh.err_already'), 'warning');
        } else {
            db_exec('INSERT INTO shifts (company_id, user_id, opening_float, note) VALUES (?,?,?,?)',
                [company_id(), user_id(), post_amount('opening_float'), post('note') ?: null]);
            flash(__('sh.opened_ok'));
        }
        redirect('admin/shifts.php');
    }

    if ($action === 'close') {
        if (!$shift) {
            flash(__('sh.err_none'), 'warning');
            redirect('admin/shifts.php');
        }

        $stillOpen = (int)db_value("SELECT COUNT(*) FROM orders WHERE company_id = ? AND status = 'open'", [company_id()]);

        $counted  = post_amount('counted_cash');
        $expected = shift_expected_cash((int)$shift['id']);

        db_exec(
            "UPDATE shifts
                SET closed_at = NOW(), counted_cash = ?, expected_cash = ?,
                    variance = ?, note = ?, status = 'closed'
              WHERE id = ? AND company_id = ? AND status = 'open'",
            [$counted, $expected, round($counted - $expected, 2), post('note') ?: null, (int)$shift['id'], company_id()]
        );

        $variance = round($counted - $expected, 2);
        if (abs($variance) < 0.005) {
            flash(__('sh.balanced'));
        } else {
            flash(__($variance > 0 ? 'sh.over' : 'sh.short', '', ['amount' => money(abs($variance))]),
                $variance > 0 ? 'warning' : 'danger');
        }
        if ($stillOpen > 0) {
            flash(__('sh.still_open', '', ['n' => $stillOpen]), 'warning');
        }
        redirect('admin/shifts.php');
    }
}

// Live figures for the open shift.
$live = null;
if ($shift) {
    $sid  = (int)$shift['id'];
    $live = [
        'cash_sales'  => (float)db_value("SELECT COALESCE(SUM(total),0) FROM orders WHERE company_id = ? AND shift_id = ? AND status = 'paid' AND payment_method = 'cash'", [company_id(), $sid]),
        'other_sales' => (float)db_value("SELECT COALESCE(SUM(total),0) FROM orders WHERE company_id = ? AND shift_id = ? AND status = 'paid' AND payment_method <> 'cash'", [company_id(), $sid]),
        'orders'      => (int)db_value("SELECT COUNT(*) FROM orders WHERE company_id = ? AND shift_id = ? AND status = 'paid'", [company_id(), $sid]),
        'expenses'    => (float)db_value("SELECT COALESCE(SUM(amount),0) FROM expenses WHERE company_id = ? AND shift_id = ? AND paid_from = 'drawer'", [company_id(), $sid]),
        'expected'    => shift_expected_cash($sid),
    ];
}

$history = $isAdmin
    ? db_all("SELECT s.*, u.full_name FROM shifts s JOIN users u ON u.id = s.user_id
               WHERE s.company_id = ? AND s.status = 'closed' ORDER BY s.id DESC LIMIT 40", [company_id()])
    : db_all("SELECT s.*, u.full_name FROM shifts s JOIN users u ON u.id = s.user_id
               WHERE s.company_id = ? AND s.status = 'closed' AND s.user_id = ? ORDER BY s.id DESC LIMIT 40", [company_id(), user_id()]);

$pageTitle = __('nav.cash_drawer');
require __DIR__ . '/../core/header.php';
?>

<?php if (!$shift): ?>
    <div class="max-w-lg">
        <div class="card">
            <div class="card-header"><?= e(__('sh.open_a')) ?></div>
            <div class="card-body">
                <p class="text-muted text-sm mb-3"><?= e(__('sh.open_help')) ?></p>
                <form method="post" class="space-y-3">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="open">
                    <div>
                        <label class="label"><?= e(__('sh.float')) ?></label>
                        <input type="number" name="opening_float" step="0.01" min="0"
                               class="input input-lg text-right" value="0.00" required autofocus>
                    </div>
                    <div>
                        <label class="label"><?= e(__('sh.note_opt')) ?></label>
                        <input type="text" name="note" class="input" maxlength="255">
                    </div>
                    <button class="btn btn-ok btn-lg w-full"><?= e(__('pos.open_shift')) ?></button>
                </form>
            </div>
        </div>
    </div>
<?php else: ?>
    <div class="grid grid-cols-2 lg:grid-cols-4 gap-3 mb-4">
        <div class="stat-card">
            <div class="label"><?= e(__('sh.float')) ?></div><div class="value"><?= money($shift['opening_float']) ?></div>
            <small class="text-muted text-xs"><?= e(__('sh.since', '', ['time' => dt($shift['opened_at'], 'g:i A')])) ?></small>
        </div>
        <div class="stat-card good">
            <div class="label"><?= e(__('sh.cash_sales')) ?></div><div class="value"><?= money($live['cash_sales']) ?></div>
            <small class="text-muted text-xs"><?= e(__('sh.paid_orders', '', ['n' => (int)$live['orders']])) ?></small>
        </div>
        <div class="stat-card bad">
            <div class="label"><?= e(__('sh.paid_out')) ?></div><div class="value"><?= money($live['expenses']) ?></div>
            <small class="text-muted text-xs"><?= e(__('sh.exp_this')) ?></small>
        </div>
        <div class="stat-card accent">
            <div class="label"><?= e(__('sh.should_hold')) ?></div><div class="value"><?= money($live['expected']) ?></div>
            <small class="text-muted text-xs"><?= e(__('sh.formula')) ?></small>
        </div>
    </div>

    <?php if ($live['other_sales'] > 0): ?>
        <p class="text-muted text-sm mb-3"><?= strtr(e(__('sh.other_sales')), ['{amount}' => '<strong>' . money($live['other_sales']) . '</strong>']) ?></p>
    <?php endif; ?>

    <div class="grid grid-cols-1 lg:grid-cols-2 gap-4 mb-5">
        <div class="card">
            <div class="card-header"><?= e(__('sh.close_the')) ?></div>
            <div class="card-body">
                <form method="post" onsubmit="return confirm(<?= e(json_encode(__('sh.close_confirm'))) ?>);" class="space-y-3">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="close">
                    <div>
                        <label class="label"><?= e(__('sh.counted_lbl')) ?></label>
                        <input type="number" name="counted_cash" step="0.01" min="0"
                               class="input input-lg text-right" required autofocus>
                    </div>
                    <div>
                        <label class="label"><?= e(__('sh.note_opt')) ?></label>
                        <input type="text" name="note" class="input" maxlength="255"
                               placeholder="<?= e(__('sh.diff_ph')) ?>">
                    </div>
                    <button class="btn btn-dark btn-lg w-full"><?= e(__('sh.close')) ?></button>
                </form>
            </div>
        </div>
        <div class="flex gap-2 items-start pt-4">
            <a class="btn btn-outline" href="<?= url('admin/expenses.php') ?>"><?= e(__('ex.record')) ?></a>
            <a class="btn btn-accent" href="<?= url('public/pos.php') ?>"><?= e(__('sh.back_pos')) ?></a>
        </div>
    </div>
<?php endif; ?>

<h2 class="text-base font-semibold mt-5 mb-2"><?= e(__('sh.closed_shifts')) ?></h2>
<div class="card overflow-x-auto">
<table class="tbl">
    <thead><tr>
        <th><?= e(__('sh.opened')) ?></th><th><?= e(__('sh.closed')) ?></th><?= $isAdmin ? '<th>' . e(__('sh.cashier')) . '</th>' : '' ?>
        <th class="text-right"><?= e(__('sh.float_col')) ?></th><th class="text-right"><?= e(__('sh.expected')) ?></th>
        <th class="text-right"><?= e(__('sh.counted')) ?></th><th class="text-right"><?= e(__('sh.variance')) ?></th><th><?= e(__('sh.note')) ?></th>
    </tr></thead>
    <tbody>
    <?php foreach ($history as $h): $v = (float)$h['variance']; ?>
        <tr>
            <td><?= dt($h['opened_at'], 'd M, g:i A') ?></td>
            <td><?= dt($h['closed_at'], 'd M, g:i A') ?></td>
            <?= $isAdmin ? '<td>' . e($h['full_name']) . '</td>' : '' ?>
            <td class="text-right"><?= money($h['opening_float']) ?></td>
            <td class="text-right"><?= money($h['expected_cash']) ?></td>
            <td class="text-right"><?= money($h['counted_cash']) ?></td>
            <td class="text-right font-semibold" style="color:<?= abs($v) < 0.005 ? '#1f7a4d' : '#b3261e' ?>">
                <?= ($v > 0 ? '+' : '') . money($v) ?>
            </td>
            <td class="text-muted text-xs"><?= e($h['note'] ?? '') ?></td>
        </tr>
    <?php endforeach; ?>
    <?php if (!$history): ?>
        <tr><td colspan="8" class="text-center text-muted py-8"><?= e(__('sh.none_closed')) ?></td></tr>
    <?php endif; ?>
    </tbody>
</table>
</div>

<?php require __DIR__ . '/../core/footer.php'; ?>
