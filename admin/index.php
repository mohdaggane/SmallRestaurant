<?php
/** Admin dashboard: today at a glance. */

require_once __DIR__ . '/../core/config.php';
require_role('admin');

$today = date('Y-m-d');

// Same figures as the Daily Transactions report — net (before VAT), VAT, collected.
$day = daily_totals($today);

$openOrders = db_one(
    "SELECT COUNT(*) AS n, COALESCE(SUM(total),0) AS total FROM orders WHERE company_id = ? AND status = 'open'",
    [company_id()]
);

$cogsToday = (float)db_value(
    "SELECT COALESCE(SUM(oi.unit_cost * oi.qty),0)
       FROM order_items oi JOIN orders o ON o.id = oi.order_id
      WHERE o.company_id = ? AND o.status = 'paid' AND o.paid_at >= ? AND o.paid_at < ?",
    [company_id(), $day['start'], $day['end']]
);

// Profit starts from sales BEFORE VAT: the VAT added on top is the
// government's money, not the shop's income.
$netToday = $day['net'] - $cogsToday - $day['expenses'];

$kitchenWaiting = (int)db_value(
    "SELECT COUNT(*) FROM order_items oi JOIN orders o ON o.id = oi.order_id
      WHERE o.company_id = ? AND o.status <> 'void' AND oi.needs_prep = 1 AND oi.kitchen_status <> 'served'",
    [company_id()]
);

$openShifts = db_all(
    "SELECT s.*, u.full_name FROM shifts s JOIN users u ON u.id = s.user_id
      WHERE s.company_id = ? AND s.status = 'open' ORDER BY s.opened_at",
    [company_id()]
);

$recent = db_all(
    "SELECT o.*, u.full_name AS taken_by FROM orders o JOIN users u ON u.id = o.created_by
      WHERE o.company_id = ?
      ORDER BY o.id DESC LIMIT 10",
    [company_id()]
);

$topToday = db_all(
    "SELECT oi.item_name, SUM(oi.qty) AS qty
       FROM order_items oi JOIN orders o ON o.id = oi.order_id
      WHERE o.company_id = ? AND o.status = 'paid' AND DATE(o.paid_at) = ?
      GROUP BY oi.item_name ORDER BY qty DESC LIMIT 6",
    [company_id(), $today]
);

// Subscription reminder and, for a newly registered restaurant, what to set up first.
$company = current_company();
$term    = company_term($company);
$setup   = [
    ['Add your menu items',       'admin/menu_items.php', (int)db_value('SELECT COUNT(*) FROM menu_items WHERE company_id = ?', [company_id()]) > 0],
    ['Add cashier / waiter / kitchen accounts', 'admin/users.php', (int)db_value('SELECT COUNT(*) FROM users WHERE company_id = ?', [company_id()]) > 1],
    ['Set your shop details and VAT rate', 'admin/settings.php', setting('settings_saved_at') !== ''],
];
$setupLeft = array_filter($setup, fn($s) => !$s[2]);

$pageTitle   = 'Dashboard';
$pageActions = '<a class="btn btn-accent" href="' . url('public/pos.php') . '">Open the POS terminal</a>';
require __DIR__ . '/../core/header.php';
?>

<p class="text-muted text-sm -mt-2 mb-4"><?= date('l, d F Y') ?></p>

<?php if ($term['days'] !== null && $term['days'] <= 7): ?>
    <div class="flash-warning">
        Your <?= $company['status'] === 'trial' ? 'free trial' : 'subscription' ?> ends in <?= $term['days'] ?> day(s)
        (<?= dt($term['until'], 'd M Y') ?>). <a class="underline font-semibold" href="<?= url('admin/billing.php') ?>">See billing</a>
    </div>
<?php endif; ?>

<?php if ($setupLeft): ?>
    <div class="card mb-5">
        <div class="card-header">Getting started</div>
        <ul class="divide-y divide-line">
            <?php foreach ($setup as [$label, $path, $done]): ?>
                <li class="px-4 py-2.5 text-sm flex items-center gap-2">
                    <span><?= $done ? '✅' : '⬜' ?></span>
                    <?php if ($done): ?>
                        <span class="text-muted line-through"><?= e($label) ?></span>
                    <?php else: ?>
                        <a class="text-brand-dark font-semibold hover:underline" href="<?= url($path) ?>"><?= e($label) ?></a>
                    <?php endif; ?>
                </li>
            <?php endforeach; ?>
        </ul>
    </div>
<?php endif; ?>

<div class="grid grid-cols-2 lg:grid-cols-4 gap-3 mb-5">
    <div class="stat-card accent">
        <div class="label">Sales today (before VAT)</div>
        <div class="value"><?= money($day['net']) ?></div>
        <small class="text-muted text-xs"><?= $day['orders'] ?> paid order(s) · VAT <?= money($day['tax']) ?> · collected <?= money($day['sales']) ?></small>
    </div>
    <div class="stat-card <?= $netToday >= 0 ? 'good' : 'bad' ?>">
        <div class="label">Net today</div>
        <div class="value"><?= money($netToday) ?></div>
        <small class="text-muted text-xs">after cost &amp; expenses</small>
    </div>
    <div class="stat-card">
        <div class="label">Unpaid orders</div>
        <div class="value"><?= (int)$openOrders['n'] ?></div>
        <small class="text-muted text-xs">worth <?= money($openOrders['total']) ?></small>
    </div>
    <div class="stat-card bad">
        <div class="label">Waiting in kitchen</div>
        <div class="value"><?= $kitchenWaiting ?></div>
        <small class="text-muted text-xs">item(s) not yet served</small>
    </div>
</div>

<div class="grid grid-cols-1 lg:grid-cols-[1fr_auto] gap-3">
    <div class="card">
        <div class="card-header flex justify-between items-center">
            <span>Latest orders</span>
            <a class="text-sm text-brand hover:underline" href="<?= url('admin/sales.php') ?>">All sales</a>
        </div>
        <table class="tbl">
            <thead><tr>
                <th>Time</th><th>Order</th><th>Taken by</th>
                <th class="text-right">Total</th><th class="text-center">Status</th>
            </tr></thead>
            <tbody>
            <?php foreach ($recent as $r): ?>
                <tr>
                    <td class="text-nowrap"><?= dt($r['created_at'], 'd M, g:i A') ?></td>
                    <td>#<?= e($r['order_no']) ?></td>
                    <td class="text-muted"><?= e($r['taken_by']) ?></td>
                    <td class="text-right"><?= money($r['total']) ?></td>
                    <td class="text-center">
                        <span class="badge <?= status_badge($r['status']) ?>"><?= e(ucfirst($r['status'])) ?></span>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$recent): ?>
                <tr><td colspan="5" class="text-center text-muted py-8">
                    No orders yet. Open the POS terminal to ring up the first sale.</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>

    <div class="flex flex-col gap-3 w-full lg:w-72">
        <div class="card">
            <div class="card-header">Open cash drawers</div>
            <ul class="divide-y divide-line">
                <?php foreach ($openShifts as $s): ?>
                    <li class="px-4 py-3 flex justify-between items-center">
                        <span><?= e($s['full_name']) ?><br>
                            <small class="text-muted text-xs">since <?= dt($s['opened_at'], 'g:i A') ?></small></span>
                        <span class="text-right">
                            <strong><?= money(shift_expected_cash((int)$s['id'])) ?></strong><br>
                            <small class="text-muted text-xs">expected</small>
                        </span>
                    </li>
                <?php endforeach; ?>
                <?php if (!$openShifts): ?>
                    <li class="px-4 py-3 text-muted text-center text-sm">No shift is open.</li>
                <?php endif; ?>
            </ul>
        </div>

        <div class="card">
            <div class="card-header">Top items today</div>
            <ul class="divide-y divide-line">
                <?php foreach ($topToday as $t): ?>
                    <li class="px-4 py-2.5 flex justify-between text-sm">
                        <span><?= e($t['item_name']) ?></span>
                        <strong><?= (int)$t['qty'] ?></strong>
                    </li>
                <?php endforeach; ?>
                <?php if (!$topToday): ?>
                    <li class="px-4 py-3 text-muted text-center text-sm">Nothing sold yet today.</li>
                <?php endif; ?>
            </ul>
        </div>
    </div>
</div>

<?php require __DIR__ . '/../core/footer.php'; ?>
