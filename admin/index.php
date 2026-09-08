<?php
/** Admin dashboard: today at a glance. */

require_once __DIR__ . '/../core/config.php';
require_role('admin');

$today = date('Y-m-d');

$sales = db_one(
    "SELECT COALESCE(SUM(total),0) AS total, COUNT(*) AS orders
       FROM orders WHERE status = 'paid' AND DATE(paid_at) = ?",
    [$today]
);

$openOrders = db_one(
    "SELECT COUNT(*) AS n, COALESCE(SUM(total),0) AS total FROM orders WHERE status = 'open'"
);

$expensesToday = (float)db_value('SELECT COALESCE(SUM(amount),0) FROM expenses WHERE spent_on = ?', [$today]);

$cogsToday = (float)db_value(
    "SELECT COALESCE(SUM(oi.unit_cost * oi.qty),0)
       FROM order_items oi JOIN orders o ON o.id = oi.order_id
      WHERE o.status = 'paid' AND DATE(o.paid_at) = ?",
    [$today]
);

$netToday = (float)$sales['total'] - $cogsToday - $expensesToday;

$kitchenWaiting = (int)db_value(
    "SELECT COUNT(*) FROM order_items oi JOIN orders o ON o.id = oi.order_id
      WHERE o.status <> 'void' AND oi.needs_prep = 1 AND oi.kitchen_status <> 'served'"
);

$openShifts = db_all(
    "SELECT s.*, u.full_name FROM shifts s JOIN users u ON u.id = s.user_id
      WHERE s.status = 'open' ORDER BY s.opened_at"
);

$recent = db_all(
    "SELECT o.*, u.full_name AS taken_by FROM orders o JOIN users u ON u.id = o.created_by
      ORDER BY o.id DESC LIMIT 10"
);

$topToday = db_all(
    "SELECT oi.item_name, SUM(oi.qty) AS qty
       FROM order_items oi JOIN orders o ON o.id = oi.order_id
      WHERE o.status = 'paid' AND DATE(o.paid_at) = ?
      GROUP BY oi.item_name ORDER BY qty DESC LIMIT 6",
    [$today]
);

$pageTitle   = 'Dashboard';
$pageActions = '<a class="btn btn-warning" href="' . url('public/pos.php') . '">Open the POS terminal</a>';
require __DIR__ . '/../core/header.php';
?>

<p class="text-muted"><?= date('l, d F Y') ?></p>

<div class="row g-3 mb-4">
    <div class="col-6 col-lg-3"><div class="stat-card accent">
        <div class="label">Sales today</div><div class="value"><?= money($sales['total']) ?></div>
        <small class="text-muted"><?= (int)$sales['orders'] ?> paid order(s)</small></div></div>

    <div class="col-6 col-lg-3"><div class="stat-card <?= $netToday >= 0 ? 'good' : 'bad' ?>">
        <div class="label">Net today</div><div class="value"><?= money($netToday) ?></div>
        <small class="text-muted">after cost & expenses</small></div></div>

    <div class="col-6 col-lg-3"><div class="stat-card">
        <div class="label">Unpaid orders</div><div class="value"><?= (int)$openOrders['n'] ?></div>
        <small class="text-muted">worth <?= money($openOrders['total']) ?></small></div></div>

    <div class="col-6 col-lg-3"><div class="stat-card bad">
        <div class="label">Waiting in kitchen</div><div class="value"><?= $kitchenWaiting ?></div>
        <small class="text-muted">item(s) not yet served</small></div></div>
</div>

<div class="row g-3">
    <div class="col-lg-7">
        <div class="card">
            <div class="card-header d-flex justify-content-between">
                <span>Latest orders</span>
                <a class="small" href="<?= url('admin/sales.php') ?>">All sales</a>
            </div>
            <table class="table table-sm mb-0">
                <thead><tr><th>Time</th><th>Order</th><th>Taken by</th>
                    <th class="text-end">Total</th><th class="text-center">Status</th></tr></thead>
                <tbody>
                <?php foreach ($recent as $r): ?>
                    <tr>
                        <td class="text-nowrap"><?= dt($r['created_at'], 'd M, g:i A') ?></td>
                        <td>#<?= e($r['order_no']) ?></td>
                        <td class="text-muted"><?= e($r['taken_by']) ?></td>
                        <td class="text-end"><?= money($r['total']) ?></td>
                        <td class="text-center">
                            <span class="badge bg-<?= status_badge($r['status']) ?>"><?= e(ucfirst($r['status'])) ?></span>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$recent): ?>
                    <tr><td colspan="5" class="text-center text-muted py-4">
                        No orders yet. Open the POS terminal to ring up the first sale.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <div class="col-lg-5">
        <div class="card mb-3">
            <div class="card-header">Open cash drawers</div>
            <ul class="list-group list-group-flush">
                <?php foreach ($openShifts as $s): ?>
                    <li class="list-group-item d-flex justify-content-between align-items-center">
                        <span><?= e($s['full_name']) ?><br>
                            <small class="text-muted">since <?= dt($s['opened_at'], 'g:i A') ?></small></span>
                        <span class="text-end">
                            <strong><?= money(shift_expected_cash((int)$s['id'])) ?></strong><br>
                            <small class="text-muted">expected</small>
                        </span>
                    </li>
                <?php endforeach; ?>
                <?php if (!$openShifts): ?>
                    <li class="list-group-item text-muted text-center py-3">No shift is open.</li>
                <?php endif; ?>
            </ul>
        </div>

        <div class="card">
            <div class="card-header">Top items today</div>
            <ul class="list-group list-group-flush">
                <?php foreach ($topToday as $t): ?>
                    <li class="list-group-item d-flex justify-content-between">
                        <span><?= e($t['item_name']) ?></span>
                        <strong><?= (int)$t['qty'] ?></strong>
                    </li>
                <?php endforeach; ?>
                <?php if (!$topToday): ?>
                    <li class="list-group-item text-muted text-center py-3">Nothing sold yet today.</li>
                <?php endif; ?>
            </ul>
        </div>
    </div>
</div>

<?php require __DIR__ . '/../core/footer.php'; ?>
