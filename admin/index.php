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
    [__('dash.setup_menu',     'Add your menu items'),       'admin/menu_items.php', (int)db_value('SELECT COUNT(*) FROM menu_items WHERE company_id = ?', [company_id()]) > 0],
    [__('dash.setup_users',    'Add cashier / waiter / kitchen accounts'), 'admin/users.php', (int)db_value('SELECT COUNT(*) FROM users WHERE company_id = ?', [company_id()]) > 1],
    [__('dash.setup_settings', 'Set your shop details and VAT rate'), 'admin/settings.php', setting('settings_saved_at') !== ''],
];
$setupLeft = array_filter($setup, fn($s) => !$s[2]);

$pageTitle   = __('nav.dashboard', 'Dashboard');
$pageActions = '<a class="btn btn-accent" href="' . url('public/pos.php') . '">' . e(__('dash.open_pos', 'Open the POS terminal')) . '</a>';
require __DIR__ . '/../core/header.php';
?>

<p class="text-muted text-sm -mt-2 mb-4"><?= date('l, d F Y') ?></p>

<?php if ($term['days'] !== null && $term['days'] <= 7): ?>
    <div class="flash-warning">
        <?= e(__('dash.subscription_ends', 'Your {type} ends in {days} day(s) ({date}).', [
            'type' => $company['status'] === 'trial' ? __('dash.trial', 'free trial') : __('dash.subscription', 'subscription'),
            'days' => $term['days'],
            'date' => dt($term['until'], 'd M Y'),
        ])) ?>
        <a class="underline font-semibold" href="<?= url('admin/billing.php') ?>"><?= e(__('dash.see_billing', 'See billing')) ?></a>
    </div>
<?php endif; ?>

<?php if ($setupLeft): ?>
    <div class="card mb-5">
        <div class="card-header"><?= e(__('dash.getting_started', 'Getting started')) ?></div>
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
        <div class="label"><?= e(__('dash.sales_today', 'Sales today (before VAT)')) ?></div>
        <div class="value"><?= money($day['net']) ?></div>
        <small class="text-muted text-xs"><?= $day['orders'] ?> <?= e(__('dash.paid_orders', 'paid order(s)')) ?> · VAT <?= money($day['tax']) ?> · <?= e(__('dash.collected', 'collected')) ?> <?= money($day['sales']) ?></small>
    </div>
    <div class="stat-card <?= $netToday >= 0 ? 'good' : 'bad' ?>">
        <div class="label"><?= e(__('dash.net_today', 'Net today')) ?></div>
        <div class="value"><?= money($netToday) ?></div>
        <small class="text-muted text-xs"><?= e(__('dash.after_cost', 'after cost & expenses')) ?></small>
    </div>
    <div class="stat-card">
        <div class="label"><?= e(__('dash.unpaid_orders', 'Unpaid orders')) ?></div>
        <div class="value"><?= (int)$openOrders['n'] ?></div>
        <small class="text-muted text-xs"><?= e(__('dash.worth', 'worth')) ?> <?= money($openOrders['total']) ?></small>
    </div>
    <div class="stat-card bad">
        <div class="label"><?= e(__('dash.kitchen_waiting', 'Waiting in kitchen')) ?></div>
        <div class="value"><?= $kitchenWaiting ?></div>
        <small class="text-muted text-xs"><?= e(__('dash.items_not_served', 'item(s) not yet served')) ?></small>
    </div>
</div>

<div class="grid grid-cols-1 lg:grid-cols-[1fr_auto] gap-3">
    <div class="card">
        <div class="card-header flex justify-between items-center">
            <span><?= e(__('dash.latest_orders', 'Latest orders')) ?></span>
            <a class="text-sm text-brand hover:underline" href="<?= url('admin/sales.php') ?>"><?= e(__('dash.all_sales', 'All sales')) ?></a>
        </div>
        <table class="tbl">
            <thead><tr>
                <th><?= e(__('lbl.time', 'Time')) ?></th><th><?= e(__('orders.title', 'Order')) ?></th><th><?= e(__('lbl.taken_by', 'Taken by')) ?></th>
                <th class="text-right"><?= e(__('lbl.total', 'Total')) ?></th><th class="text-center"><?= e(__('lbl.status', 'Status')) ?></th>
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
                    <?= e(__('dash.no_orders_yet', 'No orders yet. Open the POS terminal to ring up the first sale.')) ?></td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>

    <div class="flex flex-col gap-3 w-full lg:w-72">
        <div class="card">
            <div class="card-header"><?= e(__('dash.open_drawers', 'Open cash drawers')) ?></div>
            <ul class="divide-y divide-line">
                <?php foreach ($openShifts as $s): ?>
                    <li class="px-4 py-3 flex justify-between items-center">
                        <span><?= e($s['full_name']) ?><br>
                            <small class="text-muted text-xs"><?= e(__('lbl.since', 'since')) ?> <?= dt($s['opened_at'], 'g:i A') ?></small></span>
                        <span class="text-right">
                            <strong><?= money(shift_expected_cash((int)$s['id'])) ?></strong><br>
                            <small class="text-muted text-xs"><?= e(__('lbl.expected', 'expected')) ?></small>
                        </span>
                    </li>
                <?php endforeach; ?>
                <?php if (!$openShifts): ?>
                    <li class="px-4 py-3 text-muted text-center text-sm"><?= e(__('dash.no_shift', 'No shift is open.')) ?></li>
                <?php endif; ?>
            </ul>
        </div>

        <div class="card">
            <div class="card-header"><?= e(__('dash.top_items', 'Top items today')) ?></div>
            <ul class="divide-y divide-line">
                <?php foreach ($topToday as $t): ?>
                    <li class="px-4 py-2.5 flex justify-between text-sm">
                        <span><?= e($t['item_name']) ?></span>
                        <strong><?= (int)$t['qty'] ?></strong>
                    </li>
                <?php endforeach; ?>
                <?php if (!$topToday): ?>
                    <li class="px-4 py-3 text-muted text-center text-sm"><?= e(__('dash.nothing_sold', 'Nothing sold yet today.')) ?></li>
                <?php endif; ?>
            </ul>
        </div>
    </div>
</div>

<?php require __DIR__ . '/../core/footer.php'; ?>
