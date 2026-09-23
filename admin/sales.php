<?php
/** Sales log: every order with filters, totals and a link to its receipt. */

require_once __DIR__ . '/../core/config.php';
require_role('cashier');

$from   = get('from')   !== '' ? get('from')   : date('Y-m-d');
$to     = get('to')     !== '' ? get('to')     : date('Y-m-d');
$status = in_array(get('status'), ['paid', 'open', 'void', 'all'], true) ? get('status') : 'paid';
$method = in_array(get('method'), ['cash', 'mobile', 'card'], true) ? get('method') : '';

$where  = ['o.company_id = ?', 'DATE(o.created_at) BETWEEN ? AND ?'];
$params = [company_id(), $from, $to];

if ($status !== 'all') {
    $where[]  = 'o.status = ?';
    $params[] = $status;
}
if ($method !== '') {
    $where[]  = 'o.payment_method = ?';
    $params[] = $method;
}
$whereSql = implode(' AND ', $where);

// tenant-global: scoped after all — $whereSql always starts with o.company_id = ?
$orders = db_all(
    "SELECT o.*, c.full_name AS taken_by, p.full_name AS paid_by_name
       FROM orders o
       JOIN users c ON c.id = o.created_by
  LEFT JOIN users p ON p.id = o.paid_by
      WHERE $whereSql
      ORDER BY o.created_at DESC
      LIMIT 500",
    $params
);

$paidOnly = array_filter($orders, fn($o) => $o['status'] === 'paid');
$sum      = array_sum(array_map(fn($o) => (float)$o['total'], $paidOnly));
$net      = array_sum(array_map(fn($o) => (float)$o['subtotal'] - (float)$o['discount'], $paidOnly));
$vat      = array_sum(array_map(fn($o) => (float)$o['tax'], $paidOnly));
$count    = count($paidOnly);
$avg      = $count > 0 ? $net / $count : 0;

$pageTitle = __('nav.sales');
require __DIR__ . '/../core/header.php';
?>

<form class="flex flex-wrap gap-2 mb-4" method="get">
    <input type="date" name="from" class="input" value="<?= e($from) ?>">
    <span class="self-center text-muted"><?= e(__('lbl.to')) ?></span>
    <input type="date" name="to" class="input" value="<?= e($to) ?>">
    <select name="status" class="select">
        <?php foreach (['paid' => __('ost.paid'), 'open' => __('ost.open'), 'void' => __('ost.void'), 'all' => __('lbl.all')] as $k => $lbl): ?>
            <option value="<?= $k ?>" <?= $status === $k ? 'selected' : '' ?>><?= e($lbl) ?></option>
        <?php endforeach; ?>
    </select>
    <select name="method" class="select">
        <option value=""><?= e(__('sa.any_method')) ?></option>
        <?php foreach (['cash' => __('pay.cash'), 'mobile' => __('pay.mobile'), 'card' => __('pay.card')] as $k => $lbl): ?>
            <option value="<?= $k ?>" <?= $method === $k ? 'selected' : '' ?>><?= e($lbl) ?></option>
        <?php endforeach; ?>
    </select>
    <button class="btn btn-outline"><?= e(__('btn.apply')) ?></button>
    <a class="btn btn-outline" href="<?= url('admin/sales.php?from=' . date('Y-m-d') . '&to=' . date('Y-m-d')) ?>"><?= e(__('lbl.today')) ?></a>
</form>

<div class="grid grid-cols-2 lg:grid-cols-5 gap-3 mb-4">
    <div class="stat-card good">
        <div class="label"><?= e(__('sa.before_vat')) ?></div><div class="value"><?= money($net) ?></div>
        <small class="text-muted text-xs"><?= e(__('sh.paid_orders', '', ['n' => $count])) ?></small>
    </div>
    <div class="stat-card"><div class="label"><?= e(__('sa.vat_collected')) ?></div><div class="value"><?= money($vat) ?></div></div>
    <div class="stat-card"><div class="label"><?= e(__('sa.total_collected')) ?></div><div class="value"><?= money($sum) ?></div></div>
    <div class="stat-card accent">
        <div class="label"><?= e(__('sa.avg')) ?></div><div class="value"><?= money($avg) ?></div>
        <small class="text-muted text-xs"><?= e(__('sa.before_vat_lc')) ?></small>
    </div>
    <div class="stat-card">
        <div class="label"><?= e(__('sa.rows')) ?></div><div class="value"><?= count($orders) ?></div>
        <small class="text-muted text-xs"><?= e(__('sa.newest')) ?></small>
    </div>
</div>

<div class="card overflow-x-auto">
<table class="tbl">
    <thead><tr>
        <th><?= e(__('lbl.time')) ?></th><th><?= e(__('sa.order')) ?></th><th><?= e(__('sa.type')) ?></th><th><?= e(__('sa.taken_by')) ?></th><th><?= e(__('pc.method')) ?></th>
        <th class="text-right"><?= e(__('lbl.before_vat')) ?></th><th class="text-right"><?= e(__('lbl.vat')) ?></th>
        <th class="text-right"><?= e(__('lbl.total')) ?></th><th class="text-center"><?= e(__('lbl.status')) ?></th><th></th>
    </tr></thead>
    <tbody>
    <?php foreach ($orders as $o): ?>
        <tr>
            <td class="text-nowrap"><?= dt($o['created_at'], 'd M, g:i A') ?></td>
            <td>#<?= e($o['order_no']) ?></td>
            <td class="text-muted">
                <?= e($o['order_type'] === 'takeaway' ? __('pos.takeaway') : __('pos.dine_in')) ?><?= $o['table_label'] ? ' · ' . e($o['table_label']) : '' ?>
            </td>
            <td class="text-muted"><?= e($o['taken_by']) ?></td>
            <td><?= $o['payment_method'] ? e(__('pay.' . $o['payment_method'], ucfirst($o['payment_method']))) : '—' ?></td>
            <td class="text-right"><?= money((float)$o['subtotal'] - (float)$o['discount']) ?></td>
            <td class="text-right text-muted"><?= money($o['tax']) ?></td>
            <td class="text-right font-semibold"><?= money($o['total']) ?></td>
            <td class="text-center">
                <span class="badge <?= status_badge($o['status']) ?>"><?= e(__('ost.' . $o['status'], ucfirst($o['status']))) ?></span>
            </td>
            <td class="text-right text-nowrap">
                <a class="btn btn-outline btn-sm" target="_blank"
                   href="<?= url('public/receipt.php?id=' . (int)$o['id']) ?>"><?= e(__('sa.receipt')) ?></a>
                <?php if (has_role('admin') && $o['status'] !== 'void'): ?>
                    <form method="post" action="<?= url('public/order_void.php') ?>" class="inline"
                          onsubmit="return confirm(<?= e(json_encode(__('sa.void_confirm'))) ?>);">
                        <?= csrf_field() ?>
                        <input type="hidden" name="order_id" value="<?= (int)$o['id'] ?>">
                        <input type="hidden" name="back" value="admin/sales.php">
                        <button class="btn btn-outline-danger btn-sm"><?= e(__('orders.void')) ?></button>
                    </form>
                <?php endif; ?>
            </td>
        </tr>
    <?php endforeach; ?>
    <?php if (!$orders): ?>
        <tr><td colspan="10" class="text-center text-muted py-8"><?= e(__('sa.no_match')) ?></td></tr>
    <?php endif; ?>
    </tbody>
</table>
</div>

<?php require __DIR__ . '/../core/footer.php'; ?>
