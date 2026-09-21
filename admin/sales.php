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

$pageTitle = 'Sales';
require __DIR__ . '/../core/header.php';
?>

<form class="flex flex-wrap gap-2 mb-4" method="get">
    <input type="date" name="from" class="input" value="<?= e($from) ?>">
    <span class="self-center text-muted">to</span>
    <input type="date" name="to" class="input" value="<?= e($to) ?>">
    <select name="status" class="select">
        <?php foreach (['paid' => 'Paid', 'open' => 'Unpaid', 'void' => 'Voided', 'all' => 'All'] as $k => $lbl): ?>
            <option value="<?= $k ?>" <?= $status === $k ? 'selected' : '' ?>><?= $lbl ?></option>
        <?php endforeach; ?>
    </select>
    <select name="method" class="select">
        <option value="">Any method</option>
        <?php foreach (['cash' => 'Cash', 'mobile' => 'Mobile money', 'card' => 'Card'] as $k => $lbl): ?>
            <option value="<?= $k ?>" <?= $method === $k ? 'selected' : '' ?>><?= $lbl ?></option>
        <?php endforeach; ?>
    </select>
    <button class="btn btn-outline">Apply</button>
    <a class="btn btn-outline" href="<?= url('admin/sales.php?from=' . date('Y-m-d') . '&to=' . date('Y-m-d')) ?>">Today</a>
</form>

<div class="grid grid-cols-2 lg:grid-cols-5 gap-3 mb-4">
    <div class="stat-card good">
        <div class="label">Sales before VAT</div><div class="value"><?= money($net) ?></div>
        <small class="text-muted text-xs"><?= $count ?> paid order(s)</small>
    </div>
    <div class="stat-card"><div class="label">VAT collected</div><div class="value"><?= money($vat) ?></div></div>
    <div class="stat-card"><div class="label">Total collected</div><div class="value"><?= money($sum) ?></div></div>
    <div class="stat-card accent">
        <div class="label">Average order</div><div class="value"><?= money($avg) ?></div>
        <small class="text-muted text-xs">before VAT</small>
    </div>
    <div class="stat-card">
        <div class="label">Rows shown</div><div class="value"><?= count($orders) ?></div>
        <small class="text-muted text-xs">newest 500</small>
    </div>
</div>

<div class="card overflow-x-auto">
<table class="tbl">
    <thead><tr>
        <th>Time</th><th>Order</th><th>Type</th><th>Taken by</th><th>Method</th>
        <th class="text-right">Before VAT</th><th class="text-right">VAT</th>
        <th class="text-right">Total</th><th class="text-center">Status</th><th></th>
    </tr></thead>
    <tbody>
    <?php foreach ($orders as $o): ?>
        <tr>
            <td class="text-nowrap"><?= dt($o['created_at'], 'd M, g:i A') ?></td>
            <td>#<?= e($o['order_no']) ?></td>
            <td class="text-muted">
                <?= $o['order_type'] === 'takeaway' ? 'Takeaway' : 'Dine in' ?><?= $o['table_label'] ? ' · ' . e($o['table_label']) : '' ?>
            </td>
            <td class="text-muted"><?= e($o['taken_by']) ?></td>
            <td><?= $o['payment_method'] ? e(ucfirst($o['payment_method'])) : '—' ?></td>
            <td class="text-right"><?= money((float)$o['subtotal'] - (float)$o['discount']) ?></td>
            <td class="text-right text-muted"><?= money($o['tax']) ?></td>
            <td class="text-right font-semibold"><?= money($o['total']) ?></td>
            <td class="text-center">
                <span class="badge <?= status_badge($o['status']) ?>"><?= e(ucfirst($o['status'])) ?></span>
            </td>
            <td class="text-right text-nowrap">
                <a class="btn btn-outline btn-sm" target="_blank"
                   href="<?= url('public/receipt.php?id=' . (int)$o['id']) ?>">Receipt</a>
                <?php if (has_role('admin') && $o['status'] !== 'void'): ?>
                    <form method="post" action="<?= url('public/order_void.php') ?>" class="inline"
                          onsubmit="return confirm('Void this order? It will be removed from all sales figures.');">
                        <?= csrf_field() ?>
                        <input type="hidden" name="order_id" value="<?= (int)$o['id'] ?>">
                        <input type="hidden" name="back" value="admin/sales.php">
                        <button class="btn btn-outline-danger btn-sm">Void</button>
                    </form>
                <?php endif; ?>
            </td>
        </tr>
    <?php endforeach; ?>
    <?php if (!$orders): ?>
        <tr><td colspan="10" class="text-center text-muted py-8">No orders match these filters.</td></tr>
    <?php endif; ?>
    </tbody>
</table>
</div>

<?php require __DIR__ . '/../core/footer.php'; ?>
