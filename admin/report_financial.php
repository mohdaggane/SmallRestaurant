<?php
/**
 * Financial report â€” a profit-and-loss statement for a date range, with the
 * money collected by channel, control figures, and a day-by-day P&L.
 * Admin only (it shows cost and profit).
 *
 * Definitions (paid orders only, by the day they were paid):
 *   Gross sales    = SUM(subtotal)                 menu value of what was sold
 *   Discounts      = SUM(discount)
 *   Net sales      = gross sales âˆ’ discounts       the shop's income
 *   VAT collected  = SUM(tax)                      added on top, owed to the government â€” not income
 *   Cost of goods  = SUM(unit_cost Ã— qty)          cost price captured at the time of sale
 *   Gross profit   = net sales âˆ’ cost of goods
 *   Net profit     = gross profit âˆ’ expenses
 *   Collections    = SUM(total) by payment method  = net sales + VAT
 */

require_once __DIR__ . '/../core/config.php';
require_role('admin');

[$from, $to, $startDt, $endDt] = report_range(date('Y-m-01'), date('Y-m-d'));
$range = [company_id(), $startDt, $endDt];

// ---------------------------------------------------------------- statement
$s = db_one(
    "SELECT COUNT(*) AS orders,
            COALESCE(SUM(subtotal), 0) AS gross,
            COALESCE(SUM(discount), 0) AS discount,
            COALESCE(SUM(tax), 0)      AS tax,
            COALESCE(SUM(total), 0)    AS collected
       FROM orders
      WHERE company_id = ? AND status = 'paid' AND paid_at >= ? AND paid_at < ?",
    $range
);
$cogs = (float)db_value(
    "SELECT COALESCE(SUM(oi.unit_cost * oi.qty), 0)
       FROM order_items oi JOIN orders o ON o.id = oi.order_id
      WHERE o.company_id = ? AND o.status = 'paid' AND o.paid_at >= ? AND o.paid_at < ?",
    $range
);
$expByCat = db_all(
    'SELECT category, COALESCE(SUM(amount), 0) AS total
       FROM expenses WHERE company_id = ? AND spent_on BETWEEN ? AND ?
      GROUP BY category ORDER BY total DESC',
    [company_id(), $from, $to]
);

$gross       = (float)$s['gross'];
$discount    = (float)$s['discount'];
$netSales    = round($gross - $discount, 2);
$tax         = (float)$s['tax'];
$grossProfit = round($netSales - $cogs, 2);
$expenses    = round(array_sum(array_map(fn($r) => (float)$r['total'], $expByCat)), 2);
$netProfit   = round($grossProfit - $expenses, 2);
$pct = static fn(float $part, float $whole): string =>
    $whole > 0 ? number_format($part / $whole * 100, 1) . '%' : '—';

// ---------------------------------------------------------------- VAT account
// Taxable sales and VAT per rate â€” the figures a VAT return asks for. Each
// order carries the rate it was charged at, so a mid-period rate change splits.
$vatByRate = db_all(
    "SELECT vat_rate, COUNT(*) AS orders,
            COALESCE(SUM(subtotal - discount), 0) AS taxable,
            COALESCE(SUM(tax), 0) AS vat
       FROM orders
      WHERE company_id = ? AND status = 'paid' AND paid_at >= ? AND paid_at < ?
      GROUP BY vat_rate ORDER BY vat_rate DESC",
    $range
);

// ---------------------------------------------------------------- collections & controls
$byMethod = db_all(
    "SELECT payment_method AS m, COUNT(*) AS n, COALESCE(SUM(total), 0) AS total
       FROM orders
      WHERE company_id = ? AND status = 'paid' AND paid_at >= ? AND paid_at < ?
      GROUP BY payment_method",
    $range
);
$methods = ['cash' => 0.0, 'mobile' => 0.0, 'card' => 0.0];
foreach ($byMethod as $m) {
    $methods[$m['m']] = (float)$m['total'];
}

$variance = db_one(
    "SELECT COUNT(*) AS n, COALESCE(SUM(variance), 0) AS total,
            COALESCE(SUM(CASE WHEN variance < 0 THEN variance END), 0) AS short
       FROM shifts WHERE company_id = ? AND status = 'closed' AND closed_at >= ? AND closed_at < ?",
    $range
);
$voids = db_one(
    "SELECT COUNT(*) AS n, COALESCE(SUM(total), 0) AS total
       FROM orders WHERE company_id = ? AND status = 'void' AND voided_at >= ? AND voided_at < ?",
    $range
);
// Bills opened by the end of the range and still unpaid now.
$unpaid = db_one(
    "SELECT COUNT(*) AS n, COALESCE(SUM(total), 0) AS total
       FROM orders WHERE company_id = ? AND status = 'open' AND created_at < ?",
    [company_id(), $endDt]
);

// ---------------------------------------------------------------- daily P&L
$salesByDay = db_all(
    "SELECT DATE(paid_at) AS d, COUNT(*) AS orders,
            SUM(subtotal - discount) AS net_sales,
            SUM(tax) AS vat
       FROM orders
      WHERE company_id = ? AND status = 'paid' AND paid_at >= ? AND paid_at < ?
      GROUP BY DATE(paid_at)",
    $range
);
$costByDay = db_all(
    "SELECT DATE(o.paid_at) AS d, SUM(oi.unit_cost * oi.qty) AS cost
       FROM order_items oi JOIN orders o ON o.id = oi.order_id
      WHERE o.company_id = ? AND o.status = 'paid' AND o.paid_at >= ? AND o.paid_at < ?
      GROUP BY DATE(o.paid_at)",
    $range
);
$expByDay = db_all(
    'SELECT spent_on AS d, SUM(amount) AS expenses FROM expenses
      WHERE company_id = ? AND spent_on BETWEEN ? AND ? GROUP BY spent_on',
    [company_id(), $from, $to]
);

$days = [];
$blank = ['orders' => 0, 'net_sales' => 0.0, 'vat' => 0.0, 'cost' => 0.0, 'expenses' => 0.0];
foreach ($salesByDay as $r) {
    $days[$r['d']] = ($days[$r['d']] ?? $blank);
    $days[$r['d']]['orders']    = (int)$r['orders'];
    $days[$r['d']]['net_sales'] = (float)$r['net_sales'];
    $days[$r['d']]['vat']       = (float)$r['vat'];
}
foreach ($costByDay as $r) {
    $days[$r['d']] = ($days[$r['d']] ?? $blank);
    $days[$r['d']]['cost'] = (float)$r['cost'];
}
foreach ($expByDay as $r) {
    $days[$r['d']] = ($days[$r['d']] ?? $blank);
    $days[$r['d']]['expenses'] = (float)$r['expenses'];
}
ksort($days);

// ---------------------------------------------------------------- CSV
if (get('export') === 'csv') {
    $f = static fn(float $v): string => number_format($v, 2, '.', '');
    $rows = [
        ['Gross sales', $f($gross)],
        ['Less discounts', $f(-$discount)],
        ['Net sales', $f($netSales)],
        ['Less cost of goods', $f(-$cogs)],
        ['Gross profit', $f($grossProfit)],
    ];
    foreach ($expByCat as $x) {
        $rows[] = ['Expense: ' . $x['category'], $f(-(float)$x['total'])];
    }
    $rows[] = ['Net profit', $f($netProfit)];
    $rows[] = [];
    $rows[] = ['VAT ACCOUNT (not income)', ''];
    foreach ($vatByRate as $v) {
        $rows[] = ['Sales taxed at ' . vat_label($v['vat_rate']), $f((float)$v['taxable'])];
        $rows[] = ['VAT at ' . vat_label($v['vat_rate']), $f((float)$v['vat'])];
    }
    $rows[] = ['Total VAT collected', $f($tax)];
    $rows[] = [];
    $rows[] = ['Collected in cash', $f($methods['cash'])];
    $rows[] = ['Collected by mobile money', $f($methods['mobile'])];
    $rows[] = ['Collected by card', $f($methods['card'])];
    $rows[] = ['Total collected (net sales + VAT)', $f((float)$s['collected'])];
    $rows[] = [];
    $rows[] = ['Date', 'Paid orders', 'Net sales (before VAT)', 'VAT', 'Cost of goods', 'Gross profit',
               'Expenses', 'Net profit'];
    foreach ($days as $d => $r) {
        $gp = $r['net_sales'] - $r['cost'];
        $rows[] = [$d, $r['orders'], $f($r['net_sales']), $f($r['vat']), $f($r['cost']), $f($gp),
                   $f($r['expenses']), $f($gp - $r['expenses'])];
    }
    csv_out("financial_{$from}_to_{$to}.csv", ["Profit & loss {$from} to {$to}", 'Amount'], $rows);
}

$pageTitle  = __('rf.title');
$reportTab  = 'financial';
$filterMode = 'range';
require __DIR__ . '/../core/header.php';
require __DIR__ . '/_report_tabs.php';
?>

<h2 class="text-xl font-bold mb-4"><?= e(__('rf.heading', '', ['from' => dt($from, 'd M Y'), 'to' => dt($to, 'd M Y')])) ?></h2>

<div class="grid grid-cols-2 lg:grid-cols-5 gap-3 mb-5">
    <div class="stat-card accent">
        <div class="label"><?= e(__('rf.net_sales')) ?></div><div class="value"><?= money($netSales) ?></div>
        <small class="text-xs text-muted block mt-1"><?= e(__('rf.before_vat_n', '', ['n' => (int)$s['orders']])) ?></small>
    </div>
    <div class="stat-card">
        <div class="label"><?= e(__('sa.vat_collected')) ?></div><div class="value"><?= money($tax) ?></div>
        <small class="text-xs text-muted block mt-1"><?= e(__('rd.owed_gov')) ?></small>
    </div>
    <div class="stat-card good">
        <div class="label"><?= e(__('rp.gross_profit')) ?></div><div class="value"><?= money($grossProfit) ?></div>
        <small class="text-xs text-muted block mt-1"><?= e(__('rf.margin', '', ['pct' => $pct($grossProfit, $netSales)])) ?></small>
    </div>
    <div class="stat-card bad">
        <div class="label"><?= e(__('nav.expenses')) ?></div><div class="value"><?= money($expenses) ?></div>
        <small class="text-xs text-muted block mt-1"><?= e(__('rf.n_cats', '', ['n' => count($expByCat)])) ?></small>
    </div>
    <div class="stat-card <?= $netProfit >= 0 ? 'good' : 'bad' ?>">
        <div class="label"><?= e(__('rp.net_profit')) ?></div><div class="value"><?= money($netProfit) ?></div>
        <small class="text-xs text-muted block mt-1"><?= e(__('rf.margin', '', ['pct' => $pct($netProfit, $netSales)])) ?></small>
    </div>
</div>

<div class="grid grid-cols-1 lg:grid-cols-12 gap-5 mb-5">
    <!-- ------------------------------------------------ statement -->
    <div class="lg:col-span-7">
        <div class="card h-full">
            <div class="card-header"><?= e(__('rf.statement')) ?></div>
            <table class="tbl pl-table">
                <tr><td><?= e(__('rf.gross_sales')) ?></td><td class="text-right"><?= money($gross) ?></td><td></td></tr>
                <tr><td class="pl-4 text-muted"><?= e(__('rf.less_disc')) ?></td><td class="text-right text-muted">(<?= money($discount) ?>)</td><td></td></tr>
                <tr class="subtotal"><td><strong><?= e(__('rf.net_sales')) ?></strong></td><td class="text-right"><strong><?= money($netSales) ?></strong></td><td class="text-right text-muted">100%</td></tr>
                <tr><td class="pl-4 text-muted"><?= e(__('rf.less_cogs')) ?></td><td class="text-right text-muted">(<?= money($cogs) ?>)</td><td class="text-right text-muted"><?= $pct($cogs, $netSales) ?></td></tr>
                <tr class="subtotal"><td><strong><?= e(__('rp.gross_profit')) ?></strong></td><td class="text-right"><strong><?= money($grossProfit) ?></strong></td><td class="text-right text-muted"><?= $pct($grossProfit, $netSales) ?></td></tr>
                <?php foreach ($expByCat as $x): ?>
                    <tr><td class="pl-4 text-muted"><?= e(__('rf.less_x', '', ['name' => __('expcat.' . $x['category'], $x['category'])])) ?></td>
                        <td class="text-right text-muted">(<?= money($x['total']) ?>)</td>
                        <td class="text-right text-muted"><?= $pct((float)$x['total'], $netSales) ?></td></tr>
                <?php endforeach; ?>
                <?php if (!$expByCat): ?>
                    <tr><td class="pl-4 text-muted"><?= e(__('rf.no_exp')) ?></td><td class="text-right text-muted">—</td><td></td></tr>
                <?php endif; ?>
                <tr class="grand <?= $netProfit < 0 ? 'loss' : '' ?>">
                    <td><strong><?= e(__('rp.net_profit')) ?></strong></td>
                    <td class="text-right"><strong><?= money($netProfit) ?></strong></td>
                    <td class="text-right"><?= $pct($netProfit, $netSales) ?></td>
                </tr>
            </table>
            <div class="card-body text-xs text-muted pt-2">
                <?= e(__('rf.note', '', ['vat' => money($tax)])) ?>
            </div>
        </div>
    </div>

    <!-- ------------------------------------------------ VAT, collections & controls -->
    <div class="lg:col-span-5 flex flex-col gap-4">
        <div class="card">
            <div class="card-header"><?= e(__('rf.vat_account')) ?></div>
            <table class="tbl">
                <thead><tr><th><?= e(__('rf.rate')) ?></th><th class="text-right"><?= e(__('lbl.orders')) ?></th>
                    <th class="text-right"><?= e(__('rf.sales_taxed')) ?></th><th class="text-right"><?= e(__('lbl.vat')) ?></th></tr></thead>
                <tbody>
                <?php foreach ($vatByRate as $v): ?>
                    <tr>
                        <td><?= e(vat_label($v['vat_rate'])) ?><?= (float)$v['vat_rate'] == 0 ? ' <small class="text-muted">' . e(__('rf.no_vat')) . '</small>' : '' ?></td>
                        <td class="text-right"><?= (int)$v['orders'] ?></td>
                        <td class="text-right"><?= money($v['taxable']) ?></td>
                        <td class="text-right"><?= money($v['vat']) ?></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$vatByRate): ?>
                    <tr><td colspan="4" class="text-center text-muted py-3"><?= e(__('rp.no_paid')) ?></td></tr>
                <?php endif; ?>
                </tbody>
                <tfoot>
                    <tr class="bg-brand-light/50"><th colspan="3"><?= e(__('rf.vat_owed')) ?></th>
                        <th class="text-right"><?= money($tax) ?></th></tr>
                </tfoot>
            </table>
        </div>

        <div class="card">
            <div class="card-header"><?= e(__('rf.money_collected')) ?></div>
            <table class="tbl">
                <tr><td><?= e(__('pay.cash')) ?></td><td class="text-right"><?= money($methods['cash']) ?></td></tr>
                <tr><td><?= e(__('pay.mobile')) ?></td><td class="text-right"><?= money($methods['mobile']) ?></td></tr>
                <tr><td><?= e(__('pay.card')) ?></td><td class="text-right"><?= money($methods['card']) ?></td></tr>
                <tr class="bg-brand-light/50"><th><?= e(__('sa.total_collected')) ?></th><th class="text-right"><?= money($s['collected']) ?></th></tr>
            </table>
            <div class="card-body text-xs text-muted pt-2"><?= e(__('rf.collected_eq', '', ['net' => money($netSales), 'vat' => money($tax)])) ?></div>
        </div>

        <div class="card">
            <div class="card-header"><?= e(__('rf.controls')) ?></div>
            <table class="tbl">
                <tr>
                    <td><?= e(__('rf.drawer_diff')) ?><br><small class="text-muted"><?= e(__('rf.closed_shifts', '', ['n' => (int)$variance['n']])) ?></small></td>
                    <td class="text-right font-bold <?= abs((float)$variance['total']) < 0.005 ? 'text-ok' : 'text-bad' ?>">
                        <?= ((float)$variance['total'] > 0 ? '+' : '') . money($variance['total']) ?>
                        <?php if ((float)$variance['short'] < 0): ?><br><small><?= e(__('rf.short', '', ['amount' => money(abs((float)$variance['short']))])) ?></small><?php endif; ?>
                    </td>
                </tr>
                <tr><td><?= e(__('rf.voided')) ?><br><small class="text-muted"><?= e(__('rp.n_orders', '', ['n' => (int)$voids['n']])) ?></small></td>
                    <td class="text-right"><?= money($voids['total']) ?></td></tr>
                <tr><td><?= e(__('rf.unpaid_open')) ?><br><small class="text-muted"><?= e(__('ru.n_bills', '', ['n' => (int)$unpaid['n']])) ?> · <a class="text-brand hover:underline" href="<?= url('admin/report_unpaid.php') ?>"><?= e(__('rd.see_all')) ?></a></small></td>
                    <td class="text-right font-bold <?= (float)$unpaid['total'] > 0 ? 'text-bad' : '' ?>"><?= money($unpaid['total']) ?></td></tr>
            </table>
        </div>
    </div>
</div>

<!-- ------------------------------------------------ daily P&L -->
<div class="card">
    <div class="card-header"><?= e(__('rf.by_day')) ?></div>
    <div class="overflow-x-auto">
        <table class="tbl">
            <thead><tr><th><?= e(__('lbl.date')) ?></th><th class="text-right"><?= e(__('lbl.orders')) ?></th><th class="text-right"><?= e(__('rf.net_sales')) ?></th>
                <th class="text-right"><?= e(__('lbl.vat')) ?></th><th class="text-right"><?= e(__('mi.cost')) ?></th><th class="text-right"><?= e(__('rp.gross_profit')) ?></th>
                <th class="text-right"><?= e(__('nav.expenses')) ?></th><th class="text-right"><?= e(__('rp.net_profit')) ?></th></tr></thead>
            <tbody>
            <?php foreach ($days as $d => $r): $gp = $r['net_sales'] - $r['cost']; $np = $gp - $r['expenses']; ?>
                <tr>
                    <td class="whitespace-nowrap"><a class="text-brand hover:underline font-medium" href="<?= url('admin/report_daily.php?date=' . e($d)) ?>"><?= dt($d, 'D d M') ?></a></td>
                    <td class="text-right"><?= $r['orders'] ?></td>
                    <td class="text-right"><?= money($r['net_sales']) ?></td>
                    <td class="text-right text-muted"><?= money($r['vat']) ?></td>
                    <td class="text-right text-muted"><?= money($r['cost']) ?></td>
                    <td class="text-right"><?= money($gp) ?></td>
                    <td class="text-right text-muted"><?= money($r['expenses']) ?></td>
                    <td class="text-right font-bold <?= $np < 0 ? 'text-bad' : 'text-ok' ?>"><?= money($np) ?></td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$days): ?>
                <tr><td colspan="8" class="text-center text-muted py-4"><?= e(__('rf.no_data')) ?></td></tr>
            <?php endif; ?>
            </tbody>
            <?php if ($days): ?>
            <tfoot>
                <tr class="bg-brand-light/50">
                    <th><?= e(__('lbl.total')) ?></th><th class="text-right"><?= (int)$s['orders'] ?></th>
                    <th class="text-right"><?= money($netSales) ?></th><th class="text-right"><?= money($tax) ?></th>
                    <th class="text-right"><?= money($cogs) ?></th>
                    <th class="text-right"><?= money($grossProfit) ?></th><th class="text-right"><?= money($expenses) ?></th>
                    <th class="text-right"><?= money($netProfit) ?></th>
                </tr>
            </tfoot>
            <?php endif; ?>
        </table>
    </div>
</div>

<?php require __DIR__ . '/../core/footer.php'; ?>

