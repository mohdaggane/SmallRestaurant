<?php
/** Sales log: every order with filters, totals and a link to its receipt. */

require_once __DIR__ . '/../core/config.php';
require_role('cashier');

$from   = get('from')   !== '' ? get('from')   : date('Y-m-d');
$to     = get('to')     !== '' ? get('to')     : date('Y-m-d');
$status = in_array(get('status'), ['paid', 'open', 'void', 'all'], true) ? get('status') : 'paid';
$method = in_array(get('method'), ['cash', 'mobile', 'card'], true) ? get('method') : '';

$where  = ['DATE(o.created_at) BETWEEN ? AND ?'];
$params = [$from, $to];

if ($status !== 'all') {
    $where[]  = 'o.status = ?';
    $params[] = $status;
}
if ($method !== '') {
    $where[]  = 'o.payment_method = ?';
    $params[] = $method;
}
$whereSql = implode(' AND ', $where);

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
$count    = count($paidOnly);
$avg      = $count > 0 ? $sum / $count : 0;

$pageTitle = 'Sales';
require __DIR__ . '/../core/header.php';
?>

<form class="row g-2 mb-3" method="get">
    <div class="col-auto"><input type="date" name="from" class="form-control" value="<?= e($from) ?>"></div>
    <div class="col-auto align-self-center">to</div>
    <div class="col-auto"><input type="date" name="to" class="form-control" value="<?= e($to) ?>"></div>
    <div class="col-auto">
        <select name="status" class="form-select">
            <?php foreach (['paid' => 'Paid', 'open' => 'Unpaid', 'void' => 'Voided', 'all' => 'All'] as $k => $lbl): ?>
                <option value="<?= $k ?>" <?= $status === $k ? 'selected' : '' ?>><?= $lbl ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="col-auto">
        <select name="method" class="form-select">
            <option value="">Any method</option>
            <?php foreach (['cash' => 'Cash', 'mobile' => 'Mobile money', 'card' => 'Card'] as $k => $lbl): ?>
                <option value="<?= $k ?>" <?= $method === $k ? 'selected' : '' ?>><?= $lbl ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="col-auto"><button class="btn btn-outline-secondary">Apply</button></div>
    <div class="col-auto">
        <a class="btn btn-outline-secondary"
           href="<?= url('admin/sales.php?from=' . date('Y-m-d') . '&to=' . date('Y-m-d')) ?>">Today</a>
    </div>
</form>

<div class="row g-3 mb-3">
    <div class="col-6 col-lg-3"><div class="stat-card good">
        <div class="label">Paid sales</div><div class="value"><?= money($sum) ?></div></div></div>
    <div class="col-6 col-lg-3"><div class="stat-card">
        <div class="label">Paid orders</div><div class="value"><?= $count ?></div></div></div>
    <div class="col-6 col-lg-3"><div class="stat-card accent">
        <div class="label">Average order</div><div class="value"><?= money($avg) ?></div></div></div>
    <div class="col-6 col-lg-3"><div class="stat-card">
        <div class="label">Rows shown</div><div class="value"><?= count($orders) ?></div>
        <small class="text-muted">newest 500</small></div></div>
</div>

<table class="table table-sm align-middle">
    <thead>
        <tr><th>Time</th><th>Order</th><th>Type</th><th>Taken by</th><th>Method</th>
            <th class="text-end">Total</th><th class="text-center">Status</th><th class="text-end"></th></tr>
    </thead>
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
            <td class="text-end fw-bold"><?= money($o['total']) ?></td>
            <td class="text-center">
                <span class="badge bg-<?= status_badge($o['status']) ?>"><?= e(ucfirst($o['status'])) ?></span>
            </td>
            <td class="text-end text-nowrap">
                <a class="btn btn-sm btn-outline-secondary" target="_blank"
                   href="<?= url('public/receipt.php?id=' . (int)$o['id']) ?>">Receipt</a>
                <?php if (has_role('admin') && $o['status'] !== 'void'): ?>
                    <form method="post" action="<?= url('public/order_void.php') ?>" class="d-inline"
                          onsubmit="return confirm('Void this order? It will be removed from all sales figures.');">
                        <?= csrf_field() ?>
                        <input type="hidden" name="order_id" value="<?= (int)$o['id'] ?>">
                        <input type="hidden" name="back" value="admin/sales.php">
                        <button class="btn btn-sm btn-outline-danger">Void</button>
                    </form>
                <?php endif; ?>
            </td>
        </tr>
    <?php endforeach; ?>
    <?php if (!$orders): ?>
        <tr><td colspan="8" class="text-center text-muted py-4">No orders match these filters.</td></tr>
    <?php endif; ?>
    </tbody>
</table>

<?php require __DIR__ . '/../core/footer.php'; ?>
