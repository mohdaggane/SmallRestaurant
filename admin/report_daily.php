<?php
/**
 * Daily Transactions — everything that moved on one day, in time order:
 * sales, bills opened and still unpaid, voids and expenses, plus the
 * cash drawer shifts. Cashier and admin; used to close the day.
 */

require_once __DIR__ . '/../core/config.php';
require_role('cashier');

$date = valid_date(get('date')) ? get('date') : date('Y-m-d');
$t    = daily_totals($date);

// ---------------------------------------------------------------- the log
// An order belongs to this day if it was opened, paid or voided on it. It is
// logged at the moment that matters: paid time for a sale, void time for a
// void, opening time for a bill still unpaid.
$orders = db_all(
    "SELECT o.*, c.full_name AS taken_by, p.full_name AS paid_by_name,
            (SELECT COALESCE(SUM(qty), 0) FROM order_items WHERE order_id = o.id) AS item_count
       FROM orders o
       JOIN users c ON c.id = o.created_by
  LEFT JOIN users p ON p.id = o.paid_by
      WHERE o.company_id = ?
        AND ((o.created_at >= ? AND o.created_at < ?)
         OR (o.paid_at    >= ? AND o.paid_at    < ?)
         OR (o.voided_at  >= ? AND o.voided_at  < ?))",
    [company_id(), $t['start'], $t['end'], $t['start'], $t['end'], $t['start'], $t['end']]
);

$expenses = db_all(
    'SELECT e.*, u.full_name FROM expenses e JOIN users u ON u.id = e.user_id
      WHERE e.company_id = ? AND e.spent_on = ? ORDER BY e.created_at',
    [company_id(), $date]
);

$log = [];
foreach ($orders as $o) {
    $inDay = static fn($v) => $v !== null && $v >= $t['start'] && $v < $t['end'];

    if ($o['status'] === 'paid' && $inDay($o['paid_at'])) {
        $kind = 'Sale';      $at = $o['paid_at'];   $amount = (float)$o['total'];
    } elseif ($o['status'] === 'void' && $inDay($o['voided_at'])) {
        $kind = 'Void';      $at = $o['voided_at']; $amount = 0.0;
    } elseif ($o['status'] === 'open') {
        $kind = 'Unpaid';    $at = $o['created_at']; $amount = 0.0;
    } else {
        // Opened today but paid/voided on another day: shown for completeness.
        $kind = $o['status'] === 'paid' ? 'Sale (paid ' . dt($o['paid_at'], 'd M') . ')' : 'Void (later)';
        $at   = $o['created_at'];
        $amount = 0.0;
    }

    $log[] = [
        'at'      => $at,
        'kind'    => $kind,
        'ref'     => '#' . $o['order_no'],
        'id'      => (int)$o['id'],
        'detail'  => ($o['order_type'] === 'takeaway' ? 'Takeaway' : 'Dine in')
                     . ($o['table_label'] ? ' · ' . $o['table_label'] : '')
                     . ' · ' . (int)$o['item_count'] . ' item(s)',
        'by'      => $o['taken_by'] . ($o['paid_by_name'] && $o['paid_by_name'] !== $o['taken_by']
                     ? ' → ' . $o['paid_by_name'] : ''),
        'method'  => $o['payment_method'] ? ucfirst($o['payment_method']) : '—',
        'net'     => (float)$o['subtotal'] - (float)$o['discount'],
        'vat'     => (float)$o['tax'],
        'value'   => (float)$o['total'],
        'amount'  => $amount,
        'status'  => $o['status'],
    ];
}
foreach ($expenses as $x) {
    $log[] = [
        'at'      => $x['created_at'] >= $t['start'] && $x['created_at'] < $t['end']
                     ? $x['created_at'] : $date . ' 23:59:59',
        'kind'    => 'Expense',
        'ref'     => $x['category'],
        'id'      => null,
        'detail'  => $x['description'],
        'by'      => $x['full_name'],
        'method'  => $x['paid_from'] === 'drawer' ? 'Drawer' : 'Other',
        'net'     => null,
        'vat'     => null,
        'value'   => (float)$x['amount'],
        'amount'  => -(float)$x['amount'],
        'status'  => 'expense',
    ];
}
usort($log, fn($a, $b) => strcmp($a['at'], $b['at']));

// ---------------------------------------------------------------- CSV
if (get('export') === 'csv') {
    $n = static fn(?float $v): string => $v === null ? '' : number_format($v, 2, '.', '');
    $rows = [];
    foreach ($log as $r) {
        $rows[] = [dt($r['at'], 'H:i'), $r['kind'], $r['ref'], $r['detail'], $r['by'], $r['method'],
                   $n($r['net']), $n($r['vat']), $n($r['value']), $n($r['amount'])];
    }
    $rows[] = [];
    foreach ([
        'Sales before VAT' => $t['net'], 'VAT collected' => $t['tax'], 'Total collected (incl. VAT)' => $t['sales'],
        'Cash' => $t['cash'], 'Mobile money' => $t['mobile'], 'Card' => $t['card'],
        'Discounts' => $t['discount'], 'Expenses' => $t['expenses'],
        'Net cash (cash sales - drawer expenses)' => $t['net_cash'],
        'Unpaid bills opened this day' => $t['unpaid'], 'Voided' => $t['void_total'],
    ] as $label => $v) {
        $rows[] = ['', $label, '', '', '', '', '', '', '', $n($v)];
    }
    csv_out("daily-transactions_{$date}.csv",
        ['Time', 'Type', 'Reference', 'Detail', 'Staff', 'Method', 'Before VAT', 'VAT', 'Total', 'Cash movement'],
        $rows);
}

$pageTitle   = 'Reports · Daily transactions';
$reportTab   = 'daily';
$filterMode  = 'date';
$reportExtra = '<a class="btn btn-outline btn-sm" target="_blank" href="'
             . e(url('admin/report_daily_slip.php?date=' . $date . '&print=1')) . '">Thermal slip</a>';
require __DIR__ . '/../core/header.php';
require __DIR__ . '/_report_tabs.php';

$kindBadge = static fn(string $k): string => match (true) {
    str_starts_with($k, 'Sale')    => 'badge-success',
    str_starts_with($k, 'Void')    => 'badge-secondary',
    $k === 'Unpaid'                => 'badge-warning',
    $k === 'Expense'               => 'badge-danger',
    default                        => 'badge-secondary',
};
?>

<h2 class="text-lg font-semibold mb-3"><?= dt($date, 'l, d F Y') ?></h2>

<div class="grid grid-cols-2 lg:grid-cols-5 gap-3 mb-3">
    <div class="stat-card accent">
        <div class="label">Sales before VAT</div><div class="value"><?= money($t['net']) ?></div>
        <small class="text-muted text-xs"><?= $t['orders'] ?> order(s) · discounts <?= money($t['discount']) ?></small>
    </div>
    <div class="stat-card">
        <div class="label">VAT collected</div><div class="value"><?= money($t['tax']) ?></div>
        <small class="text-muted text-xs">owed to the government</small>
    </div>
    <div class="stat-card">
        <div class="label">Total collected</div><div class="value"><?= money($t['sales']) ?></div>
        <div class="text-xs mt-1">
            Cash <strong class="float-right"><?= money($t['cash']) ?></strong><br>
            Mobile money <strong class="float-right"><?= money($t['mobile']) ?></strong><br>
            Card <strong class="float-right"><?= money($t['card']) ?></strong>
        </div>
    </div>
    <div class="stat-card bad">
        <div class="label">Expenses</div><div class="value"><?= money($t['expenses']) ?></div>
        <small class="text-muted text-xs"><?= money($t['exp_drawer']) ?> from the drawer</small>
    </div>
    <div class="stat-card <?= $t['unpaid_n'] ? 'bad' : 'good' ?>">
        <div class="label">Still unpaid</div><div class="value"><?= money($t['unpaid']) ?></div>
        <small class="text-muted text-xs"><?= $t['unpaid_n'] ?> bill(s) opened this day
            <?php if ($t['unpaid_n']): ?>· <a href="<?= url('admin/report_unpaid.php') ?>">see all</a><?php endif; ?>
        </small>
    </div>
</div>

<p class="text-muted text-xs mb-3">
    Net cash for the drawer: cash sales <?= money($t['cash']) ?> − drawer expenses <?= money($t['exp_drawer']) ?>
    = <strong><?= money($t['net_cash']) ?></strong>.
    <?php if ($t['void_n']): ?>
        <?= $t['void_n'] ?> order(s) worth <?= money($t['void_total']) ?> voided (not counted).
    <?php endif; ?>
</p>

<div class="card mb-4 overflow-x-auto">
    <div class="card-header">Transactions in time order · <?= count($log) ?></div>
    <table class="tbl">
        <thead>
            <tr><th>Time</th><th>Type</th><th>Reference</th><th>Detail</th><th>Staff</th>
                <th>Method</th><th class="text-right">Before VAT</th><th class="text-right">VAT</th>
                <th class="text-right">Total</th><th class="text-right">Cash movement</th></tr>
        </thead>
        <tbody>
        <?php foreach ($log as $r): ?>
            <tr class="<?= $r['status'] === 'void' ? 'opacity-50' : '' ?>">
                <td class="text-nowrap"><?= dt($r['at'], 'g:i A') ?></td>
                <td><span class="badge <?= $kindBadge($r['kind']) ?>"><?= e($r['kind']) ?></span></td>
                <td class="text-nowrap">
                    <?php if ($r['id']): ?>
                        <a href="<?= url('public/receipt.php?id=' . $r['id']) ?>" target="_blank"><?= e($r['ref']) ?></a>
                    <?php else: ?><?= e($r['ref']) ?><?php endif; ?>
                </td>
                <td><?= e($r['detail']) ?></td>
                <td class="text-muted"><?= e($r['by']) ?></td>
                <td><?= e($r['method']) ?></td>
                <td class="text-right"><?= $r['net'] === null ? '—' : money($r['net']) ?></td>
                <td class="text-right text-muted"><?= $r['vat'] === null ? '—' : money($r['vat']) ?></td>
                <td class="text-right"><?= money($r['value']) ?></td>
                <td class="text-right font-semibold" style="color:<?= $r['amount'] < 0 ? '#b3261e' : ($r['amount'] > 0 ? '#1f7a4d' : 'inherit') ?>">
                    <?= $r['amount'] == 0 ? '—' : ($r['amount'] > 0 ? '+' : '−') . money(abs($r['amount'])) ?>
                </td>
            </tr>
        <?php endforeach; ?>
        <?php if (!$log): ?>
            <tr><td colspan="10" class="text-center text-muted py-8">Nothing happened on this day.</td></tr>
        <?php endif; ?>
        </tbody>
        <tfoot>
            <tr>
                <th colspan="6" class="text-right">Paid sales</th>
                <th class="text-right"><?= money($t['net']) ?></th>
                <th class="text-right"><?= money($t['tax']) ?></th>
                <th class="text-right"><?= money($t['sales']) ?></th>
                <th></th>
            </tr>
            <tr>
                <th colspan="9" class="text-right">Sales before VAT − expenses</th>
                <th class="text-right"><?= money($t['net'] - $t['expenses']) ?></th>
            </tr>
        </tfoot>
    </table>
</div>

<div class="card">
    <div class="card-header">Cash drawer shifts</div>
    <table class="tbl">
        <thead><tr><th>Cashier</th><th>Opened</th><th>Closed</th><th class="text-right">Float</th>
            <th class="text-right">Expected</th><th class="text-right">Counted</th><th class="text-right">Variance</th></tr></thead>
        <tbody>
        <?php foreach ($t['shifts'] as $s): $v = $s['variance'] === null ? null : (float)$s['variance']; ?>
            <tr>
                <td><?= e($s['full_name']) ?></td>
                <td><?= dt($s['opened_at'], 'd M, g:i A') ?></td>
                <td><?= $s['status'] === 'open' ? '<span class="badge badge-warning">still open</span>' : dt($s['closed_at'], 'd M, g:i A') ?></td>
                <td class="text-right"><?= money($s['opening_float']) ?></td>
                <td class="text-right"><?= money($s['expected_cash']) ?></td>
                <td class="text-right"><?= $s['counted_cash'] === null ? '—' : money($s['counted_cash']) ?></td>
                <td class="text-right font-semibold" style="color:<?= $v === null ? 'inherit' : (abs($v) < 0.005 ? '#1f7a4d' : '#b3261e') ?>">
                    <?= $v === null ? '—' : ($v > 0 ? '+' : '') . money($v) ?>
                </td>
            </tr>
        <?php endforeach; ?>
        <?php if (!$t['shifts']): ?>
            <tr><td colspan="7" class="text-center text-muted py-4">No drawer shift on this day.</td></tr>
        <?php endif; ?>
        </tbody>
    </table>
</div>

<?php require __DIR__ . '/../core/footer.php'; ?>
