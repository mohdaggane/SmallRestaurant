<?php
/**
 * Sales overview over a date range: trend chart, best sellers, staff, mix.
 * Voided orders are excluded everywhere; only 'paid' orders count as sales.
 * The formal profit-and-loss statement lives in admin/report_financial.php.
 */

require_once __DIR__ . '/../core/config.php';
require_role('admin');

[$from, $to, $startDt, $endDt] = report_range(date('Y-m-01'), date('Y-m-d'));

$range = [company_id(), $startDt, $endDt];   // for paid_at / created_at â€” index-friendly
$days  = [company_id(), $from, $to];         // for DATE columns such as expenses.spent_on

// ---------------------------------------------------------------- headline
$head = db_one(
    "SELECT COALESCE(SUM(o.total),0)          AS gross,
            COALESCE(SUM(o.tax),0)            AS tax,
            COALESCE(SUM(o.discount),0)       AS discount,
            COUNT(*)                          AS orders
       FROM orders o
      WHERE o.company_id = ? AND o.status = 'paid' AND o.paid_at >= ? AND o.paid_at < ?",
    $range
);

$cogs = (float)db_value(
    "SELECT COALESCE(SUM(oi.unit_cost * oi.qty),0)
       FROM order_items oi JOIN orders o ON o.id = oi.order_id
      WHERE o.company_id = ? AND o.status = 'paid' AND o.paid_at >= ? AND o.paid_at < ?",
    $range
);

$expenses = (float)db_value(
    'SELECT COALESCE(SUM(amount),0) FROM expenses WHERE company_id = ? AND spent_on BETWEEN ? AND ?',
    $days
);

$gross      = (float)$head['gross'];
$netSales   = $gross - (float)$head['tax'];   // what the shop actually earns
$orderCount = (int)$head['orders'];
$grossProfit = $netSales - $cogs;
$netProfit   = $grossProfit - $expenses;
$avgOrder    = $orderCount > 0 ? $netSales / $orderCount : 0;   // before VAT

// ---------------------------------------------------------------- breakdowns
$daily = db_all(
    "SELECT DATE(o.paid_at) AS d, SUM(o.subtotal - o.discount) AS total, COUNT(*) AS orders
       FROM orders o
      WHERE o.company_id = ? AND o.status = 'paid' AND o.paid_at >= ? AND o.paid_at < ?
      GROUP BY DATE(o.paid_at) ORDER BY d",
    $range
);

$byMethod = db_all(
    "SELECT o.payment_method AS m, SUM(o.total) AS total, COUNT(*) AS orders
       FROM orders o
      WHERE o.company_id = ? AND o.status = 'paid' AND o.paid_at >= ? AND o.paid_at < ?
      GROUP BY o.payment_method ORDER BY total DESC",
    $range
);

$byCategory = db_all(
    "SELECT c.name AS category, SUM(oi.qty) AS qty, SUM(oi.line_total) AS revenue,
            SUM(oi.line_total - oi.unit_cost * oi.qty) AS profit
       FROM order_items oi
       JOIN orders o      ON o.id = oi.order_id
  LEFT JOIN menu_items mi ON mi.id = oi.menu_item_id
  LEFT JOIN categories c  ON c.id = mi.category_id
      WHERE o.company_id = ? AND o.status = 'paid' AND o.paid_at >= ? AND o.paid_at < ?
      GROUP BY c.name ORDER BY revenue DESC",
    $range
);

$topItems = db_all(
    "SELECT oi.item_name, SUM(oi.qty) AS qty, SUM(oi.line_total) AS revenue,
            SUM(oi.line_total - oi.unit_cost * oi.qty) AS profit
       FROM order_items oi JOIN orders o ON o.id = oi.order_id
      WHERE o.company_id = ? AND o.status = 'paid' AND o.paid_at >= ? AND o.paid_at < ?
      GROUP BY oi.item_name ORDER BY qty DESC LIMIT 15",
    $range
);

$byStaff = db_all(
    "SELECT u.full_name, u.role, COUNT(*) AS orders, SUM(o.subtotal - o.discount) AS total
       FROM orders o JOIN users u ON u.id = o.created_by
      WHERE o.company_id = ? AND o.status = 'paid' AND o.paid_at >= ? AND o.paid_at < ?
      GROUP BY u.id ORDER BY total DESC",
    $range
);

$expByCat = db_all(
    'SELECT category, SUM(amount) AS total FROM expenses
      WHERE company_id = ? AND spent_on BETWEEN ? AND ? GROUP BY category ORDER BY total DESC',
    $days
);

$voided = db_one(
    "SELECT COUNT(*) AS n, COALESCE(SUM(total),0) AS total FROM orders
      WHERE company_id = ? AND status = 'void' AND created_at >= ? AND created_at < ?",
    $range
);

if (get('export') === 'csv') {
    $rows = [];
    foreach ($daily as $d) {
        $rows[] = [$d['d'], (int)$d['orders'], number_format((float)$d['total'], 2, '.', '')];
    }
    $rows[] = [];
    $rows[] = ['Best selling items', 'Sold', 'Revenue', 'Profit'];
    foreach ($topItems as $t) {
        $rows[] = [$t['item_name'], (int)$t['qty'],
                   number_format((float)$t['revenue'], 2, '.', ''),
                   number_format((float)$t['profit'], 2, '.', '')];
    }
    csv_out("sales-overview_{$from}_to_{$to}.csv", ['Date', 'Paid orders', 'Sales'], $rows);
}

$maxDaily = 0.0;
foreach ($daily as $d) {
    $maxDaily = max($maxDaily, (float)$d['total']);
}

$pageTitle  = 'Reports · Sales overview';
$reportTab  = 'overview';
$filterMode = 'range';
require __DIR__ . '/../core/header.php';
require __DIR__ . '/_report_tabs.php';
?>

<p class="text-sm text-muted mb-4">
    Showing <strong><?= dt($from, 'd M Y') ?></strong> to <strong><?= dt($to, 'd M Y') ?></strong>.
    Voided orders are excluded.
</p>

<!-- Headline numbers -->
<div class="grid grid-cols-2 lg:grid-cols-4 gap-3 mb-5">
    <div class="stat-card accent">
        <div class="label">Sales before VAT</div>
        <div class="value"><?= money($netSales) ?></div>
        <small class="text-xs text-muted block mt-1">VAT <?= money($head['tax']) ?> · collected <?= money($gross) ?></small>
        <small class="text-xs text-muted block"><?= $orderCount ?> paid order(s)</small>
    </div>
    <div class="stat-card">
        <div class="label">Average order</div>
        <div class="value"><?= money($avgOrder) ?></div>
        <small class="text-xs text-muted block mt-1">Discounts given: <?= money($head['discount']) ?></small>
    </div>
    <div class="stat-card good">
        <div class="label">Gross profit</div>
        <div class="value"><?= money($grossProfit) ?></div>
        <small class="text-xs text-muted block mt-1">Net sales <?= money($netSales) ?> − cost <?= money($cogs) ?></small>
    </div>
    <div class="stat-card <?= $netProfit >= 0 ? 'good' : 'bad' ?>">
        <div class="label">Net profit</div>
        <div class="value"><?= money($netProfit) ?></div>
        <small class="text-xs text-muted block mt-1">after <?= money($expenses) ?> expenses</small>
    </div>
</div>

<!-- Daily trend -->
<div class="card mb-5">
    <div class="card-header">Sales per day <span class="text-xs font-normal text-muted">(before VAT)</span></div>
    <div class="card-body">
        <?php if (!$daily): ?>
            <p class="text-muted text-center py-4 mb-0 text-sm">No paid orders in this period.</p>
        <?php else: ?>
            <div class="chart">
                <div class="chart-plot">
                    <div class="chart-grid" style="bottom:100%"><span><?= money($maxDaily) ?></span></div>
                    <?php foreach ($daily as $d):
                        $val = (float)$d['total'];
                        $pct = $maxDaily > 0 ? max(1.5, $val / $maxDaily * 100) : 1.5;
                        $isPeak = $maxDaily > 0 && abs($val - $maxDaily) < 0.005;
                    ?>
                        <div class="bar-col <?= $isPeak ? 'peak' : '' ?>">
                            <div class="bar-tip">
                                <?= dt($d['d'], 'D d M') ?> · <?= money($val) ?> · <?= (int)$d['orders'] ?> order(s)
                            </div>
                            <?php if ($isPeak): ?>
                                <span class="bar-val"><?= money($val) ?></span>
                            <?php endif; ?>
                            <div class="bar" style="height:<?= number_format($pct, 2, '.', '') ?>%"></div>
                        </div>
                    <?php endforeach; ?>
                </div>
                <div class="chart-labels flex justify-between text-[11px] text-muted pt-2 border-t border-line">
                    <?php foreach ($daily as $d): ?>
                        <div><?= dt($d['d'], count($daily) > 15 ? 'j' : 'j M') ?></div>
                    <?php endforeach; ?>
                </div>
            </div>
            <p class="text-xs text-muted mt-3 mb-0">
                Highest day highlighted. Hover a bar for its exact takings.
            </p>
        <?php endif; ?>
    </div>
</div>

<div class="grid grid-cols-1 lg:grid-cols-12 gap-5 mb-5">
    <!-- Top items -->
    <div class="lg:col-span-7">
        <div class="card h-full">
            <div class="card-header">Best selling items</div>
            <table class="tbl">
                <thead><tr><th>Item</th><th class="text-right">Sold</th>
                    <th class="text-right">Revenue</th><th class="text-right">Profit</th></tr></thead>
                <tbody>
                <?php foreach ($topItems as $t): ?>
                    <tr>
                        <td class="font-medium"><?= e($t['item_name']) ?></td>
                        <td class="text-right"><?= (int)$t['qty'] ?></td>
                        <td class="text-right font-medium"><?= money($t['revenue']) ?></td>
                        <td class="text-right text-muted"><?= money($t['profit']) ?></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$topItems): ?>
                    <tr><td colspan="4" class="text-center text-muted py-4">Nothing sold in this period.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- Payment mix & categories -->
    <div class="lg:col-span-5 flex flex-col gap-5">
        <div class="card">
            <div class="card-header">How customers paid <span class="text-xs font-normal text-muted">(incl. VAT)</span></div>
            <table class="tbl">
                <tbody>
                <?php foreach ($byMethod as $m): ?>
                    <tr>
                        <td><?= e(ucfirst((string)$m['m'])) ?></td>
                        <td class="text-right text-muted"><?= (int)$m['orders'] ?> order(s)</td>
                        <td class="text-right font-bold"><?= money($m['total']) ?></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$byMethod): ?>
                    <tr><td class="text-center text-muted py-3">No payments yet.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>

        <div class="card">
            <div class="card-header">Sales by category</div>
            <table class="tbl">
                <thead><tr><th>Category</th><th class="text-right">Qty</th><th class="text-right">Revenue</th></tr></thead>
                <tbody>
                <?php foreach ($byCategory as $c): ?>
                    <tr>
                        <td><?= e($c['category'] ?? 'Removed item') ?></td>
                        <td class="text-right"><?= (int)$c['qty'] ?></td>
                        <td class="text-right font-medium"><?= money($c['revenue']) ?></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$byCategory): ?>
                    <tr><td colspan="3" class="text-center text-muted py-3">No data.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<div class="grid grid-cols-1 lg:grid-cols-2 gap-5 mb-5">
    <!-- Staff -->
    <div class="card">
        <div class="card-header">Orders taken by staff <span class="text-xs font-normal text-muted">(before VAT)</span></div>
        <table class="tbl">
            <thead><tr><th>Name</th><th>Role</th><th class="text-right">Orders</th><th class="text-right">Value</th></tr></thead>
            <tbody>
            <?php foreach ($byStaff as $s): ?>
                <tr>
                    <td class="font-medium"><?= e($s['full_name']) ?></td>
                    <td class="text-muted"><?= e(ucfirst($s['role'])) ?></td>
                    <td class="text-right"><?= (int)$s['orders'] ?></td>
                    <td class="text-right font-medium"><?= money($s['total']) ?></td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$byStaff): ?>
                <tr><td colspan="4" class="text-center text-muted py-3">No data.</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>

    <!-- Expenses -->
    <div class="card">
        <div class="card-header">Expenses by category</div>
        <table class="tbl">
            <tbody>
            <?php foreach ($expByCat as $x): ?>
                <tr><td><?= e($x['category']) ?></td>
                    <td class="text-right font-bold"><?= money($x['total']) ?></td></tr>
            <?php endforeach; ?>
            <?php if (!$expByCat): ?>
                <tr><td class="text-center text-muted py-3">No expenses recorded.</td></tr>
            <?php endif; ?>
            </tbody>
            <tfoot><tr class="bg-brand-light/50">
                <th>Total expenses</th><th class="text-right"><?= money($expenses) ?></th>
            </tr></tfoot>
        </table>
    </div>
</div>

<?php if ((int)$voided['n'] > 0): ?>
    <p class="text-xs text-muted mt-3">
        <?= (int)$voided['n'] ?> order(s) worth <?= money($voided['total']) ?> were voided in this period
        and are not included above.
    </p>
<?php endif; ?>

<?php require __DIR__ . '/../core/footer.php'; ?>

