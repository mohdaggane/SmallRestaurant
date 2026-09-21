<?php
/**
 * Unpaid Bills — every order served or sent to the kitchen whose money has
 * not been collected, from any day. Shows how long each has waited, groups
 * them by age, and totals what is still owed. Cashier and admin.
 */

require_once __DIR__ . '/../core/config.php';
require_role('cashier');

$now        = date('Y-m-d H:i:s');
$todayStart = date('Y-m-d') . ' 00:00:00';

$bills = db_all(
    "SELECT o.*, u.full_name AS taken_by,
            TIMESTAMPDIFF(MINUTE, o.created_at, ?) AS age_min,
            (SELECT COALESCE(SUM(qty), 0) FROM order_items WHERE order_id = o.id) AS item_count,
            (SELECT COUNT(*) FROM order_items
              WHERE order_id = o.id AND needs_prep = 1 AND kitchen_status <> 'served') AS waiting_lines
       FROM orders o
       JOIN users u ON u.id = o.created_by
      WHERE o.company_id = ? AND o.status = 'open'
      ORDER BY o.created_at ASC",
    [$now, company_id()]
);

// Age buckets, oldest money first in the eye of whoever chases it.
$BUCKETS = [
    'hour'  => 'Under 1 hour',
    'today' => 'Earlier today',
    'days'  => '1 – 3 days',
    'old'   => 'Over 3 days',
];
$bucketOf = static function (array $b) use ($todayStart): string {
    $age = (int)$b['age_min'];
    if ($age < 60)                     return 'hour';
    if ($b['created_at'] >= $todayStart) return 'today';
    if ($age < 3 * 1440)               return 'days';
    return 'old';
};

$summary = array_fill_keys(array_keys($BUCKETS), ['n' => 0, 'total' => 0.0]);
$owed    = 0.0;
foreach ($bills as &$b) {
    $b['bucket'] = $bucketOf($b);
    $summary[$b['bucket']]['n']++;
    $summary[$b['bucket']]['total'] += (float)$b['total'];
    $owed += (float)$b['total'];
}
unset($b);

if (get('export') === 'csv') {
    $rows = [];
    foreach ($bills as $b) {
        $rows[] = [
            $b['order_no'], $b['created_at'], duration_label((int)$b['age_min']), $BUCKETS[$b['bucket']],
            $b['order_type'] === 'takeaway' ? 'Takeaway' : 'Dine in', (string)$b['table_label'],
            $b['taken_by'], (int)$b['item_count'],
            (int)$b['waiting_lines'] > 0 ? 'In kitchen' : 'Served',
            number_format((float)$b['total'], 2, '.', ''),
        ];
    }
    $rows[] = [];
    $rows[] = ['TOTAL OWED', '', '', '', '', '', '', '', '', number_format($owed, 2, '.', '')];
    csv_out('unpaid-bills_' . date('Y-m-d_Hi') . '.csv',
        ['Order', 'Opened', 'Waiting', 'Age group', 'Type', 'Table', 'Taken by', 'Items', 'Kitchen', 'Amount'],
        $rows);
}

$canCharge  = has_role('admin', 'cashier');
$pageTitle  = 'Reports · Unpaid bills';
$reportTab  = 'unpaid';
$filterMode = 'none';
require __DIR__ . '/../core/header.php';
require __DIR__ . '/_report_tabs.php';
?>

<div class="grid grid-cols-2 lg:grid-cols-5 gap-3 mb-3">
    <div class="stat-card <?= $owed > 0 ? 'bad' : 'good' ?>">
        <div class="label">Money still owed</div><div class="value"><?= money($owed) ?></div>
        <small class="text-muted text-xs"><?= count($bills) ?> unpaid bill(s) · as of <?= date('g:i A') ?></small>
    </div>
    <?php foreach ($BUCKETS as $key => $label): ?>
        <div class="stat-card <?= $key === 'old' && $summary[$key]['n'] ? 'bad' : '' ?>">
            <div class="label"><?= e($label) ?></div>
            <div class="value text-xl"><?= money($summary[$key]['total']) ?></div>
            <small class="text-muted text-xs"><?= $summary[$key]['n'] ?> bill(s)</small>
        </div>
    <?php endforeach; ?>
</div>

<div class="card overflow-x-auto">
    <div class="card-header">Unpaid bills, oldest first</div>
    <table class="tbl">
        <thead>
            <tr><th>Order</th><th>Opened</th><th>Waiting</th><th>Table</th><th>Taken by</th>
                <th class="text-center">Items</th><th>Kitchen</th><th class="text-right">Amount</th>
                <th class="text-right no-print">Actions</th></tr>
        </thead>
        <tbody>
        <?php foreach ($bills as $b): ?>
            <tr>
                <td class="text-nowrap">#<?= e($b['order_no']) ?></td>
                <td class="text-nowrap"><?= dt($b['created_at'], 'd M, g:i A') ?></td>
                <td class="text-nowrap">
                    <span class="badge <?= match ($b['bucket']) {
                        'old' => 'badge-danger', 'days' => 'badge-warning', default => 'badge-secondary' } ?>">
                        <?= e(duration_label((int)$b['age_min'])) ?>
                    </span>
                </td>
                <td><?= $b['order_type'] === 'takeaway' ? 'Takeaway' : 'Dine in' ?><?= $b['table_label'] ? ' · ' . e($b['table_label']) : '' ?></td>
                <td class="text-muted"><?= e($b['taken_by']) ?></td>
                <td class="text-center"><?= (int)$b['item_count'] ?></td>
                <td><?= (int)$b['waiting_lines'] > 0
                        ? '<span class="badge badge-warning">' . (int)$b['waiting_lines'] . ' cooking</span>'
                        : '<span class="badge badge-success">served</span>' ?></td>
                <td class="text-right font-semibold"><?= money($b['total']) ?></td>
                <td class="text-right text-nowrap no-print">
                    <a class="btn btn-outline btn-sm" target="_blank"
                       href="<?= url('public/receipt.php?id=' . (int)$b['id']) ?>">Bill</a>
                    <a class="btn btn-accent btn-sm"
                       href="<?= url('public/pos.php?order=' . (int)$b['id']) ?>">Add items</a>
                    <?php if ($canCharge): ?>
                        <a class="btn btn-ok btn-sm"
                           href="<?= url('public/orders.php') ?>#order-<?= (int)$b['id'] ?>">Take payment</a>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        <?php if (!$bills): ?>
            <tr><td colspan="9" class="text-center text-muted py-8">Every bill is paid. Nothing is owed.</td></tr>
        <?php endif; ?>
        </tbody>
        <?php if ($bills): ?>
        <tfoot>
            <tr>
                <th colspan="7" class="text-right">Total owed</th>
                <th class="text-right"><?= money($owed) ?></th>
                <th class="no-print"></th>
            </tr>
        </tfoot>
        <?php endif; ?>
    </table>
</div>

<?php require __DIR__ . '/../core/footer.php'; ?>
