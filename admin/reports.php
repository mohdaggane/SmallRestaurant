<?php
/**
 * Sales and profit reporting over a date range.
 * Voided orders are excluded everywhere; only 'paid' orders count as sales.
 */

require_once __DIR__ . '/../core/config.php';
require_role('admin');

$from = get('from') !== '' ? get('from') : date('Y-m-01');
$to   = get('to')   !== '' ? get('to')   : date('Y-m-d');

// Quick range buttons.
switch (get('range')) {
    case 'today':     $from = $to = date('Y-m-d'); break;
    case 'yesterday': $from = $to = date('Y-m-d', strtotime('-1 day')); break;
    case 'week':      $from = date('Y-m-d', strtotime('-6 days')); $to = date('Y-m-d'); break;
    case 'month':     $from = date('Y-m-01'); $to = date('Y-m-d'); break;
}

$range = [$from, $to];

// ---------------------------------------------------------------- headline
$head = db_one(
    "SELECT COALESCE(SUM(o.total),0)          AS gross,
            COALESCE(SUM(o.tax),0)            AS tax,
            COALESCE(SUM(o.discount),0)       AS discount,
            COUNT(*)                          AS orders
       FROM orders o
      WHERE o.status = 'paid' AND DATE(o.paid_at) BETWEEN ? AND ?",
    $range
);

$cogs = (float)db_value(
    "SELECT COALESCE(SUM(oi.unit_cost * oi.qty),0)
       FROM order_items oi JOIN orders o ON o.id = oi.order_id
      WHERE o.status = 'paid' AND DATE(o.paid_at) BETWEEN ? AND ?",
    $range
);

$expenses = (float)db_value(
    'SELECT COALESCE(SUM(amount),0) FROM expenses WHERE spent_on BETWEEN ? AND ?',
    $range
);

$gross      = (float)$head['gross'];
$netSales   = $gross - (float)$head['tax'];   // what the shop actually earns
$orderCount = (int)$head['orders'];
$grossProfit = $netSales - $cogs;
$netProfit   = $grossProfit - $expenses;
$avgOrder    = $orderCount > 0 ? $gross / $orderCount : 0;

// ---------------------------------------------------------------- breakdowns
$daily = db_all(
    "SELECT DATE(o.paid_at) AS d, SUM(o.total) AS total, COUNT(*) AS orders
       FROM orders o
      WHERE o.status = 'paid' AND DATE(o.paid_at) BETWEEN ? AND ?
      GROUP BY DATE(o.paid_at) ORDER BY d",
    $range
);

$byMethod = db_all(
    "SELECT o.payment_method AS m, SUM(o.total) AS total, COUNT(*) AS orders
       FROM orders o
      WHERE o.status = 'paid' AND DATE(o.paid_at) BETWEEN ? AND ?
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
      WHERE o.status = 'paid' AND DATE(o.paid_at) BETWEEN ? AND ?
      GROUP BY c.name ORDER BY revenue DESC",
    $range
);

$topItems = db_all(
    "SELECT oi.item_name, SUM(oi.qty) AS qty, SUM(oi.line_total) AS revenue,
            SUM(oi.line_total - oi.unit_cost * oi.qty) AS profit
       FROM order_items oi JOIN orders o ON o.id = oi.order_id
      WHERE o.status = 'paid' AND DATE(o.paid_at) BETWEEN ? AND ?
      GROUP BY oi.item_name ORDER BY qty DESC LIMIT 15",
    $range
);

$byStaff = db_all(
    "SELECT u.full_name, u.role, COUNT(*) AS orders, SUM(o.total) AS total
       FROM orders o JOIN users u ON u.id = o.created_by
      WHERE o.status = 'paid' AND DATE(o.paid_at) BETWEEN ? AND ?
      GROUP BY u.id ORDER BY total DESC",
    $range
);

$expByCat = db_all(
    'SELECT category, SUM(amount) AS total FROM expenses
      WHERE spent_on BETWEEN ? AND ? GROUP BY category ORDER BY total DESC',
    $range
);

$voided = db_one(
    "SELECT COUNT(*) AS n, COALESCE(SUM(total),0) AS total FROM orders
      WHERE status = 'void' AND DATE(created_at) BETWEEN ? AND ?",
    $range
);

$maxDaily = 0.0;
foreach ($daily as $d) {
    $maxDaily = max($maxDaily, (float)$d['total']);
}

$pageTitle = 'Reports';
require __DIR__ . '/../core/header.php';
?>

<form class="row g-2 mb-3" method="get">
    <div class="col-auto"><input type="date" name="from" class="form-control" value="<?= e($from) ?>"></div>
    <div class="col-auto align-self-center">to</div>
    <div class="col-auto"><input type="date" name="to" class="form-control" value="<?= e($to) ?>"></div>
    <div class="col-auto"><button class="btn btn-outline-secondary">Apply</button></div>
    <div class="col-auto btn-group">
        <a class="btn btn-sm btn-outline-secondary" href="?range=today">Today</a>
        <a class="btn btn-sm btn-outline-secondary" href="?range=yesterday">Yesterday</a>
        <a class="btn btn-sm btn-outline-secondary" href="?range=week">Last 7 days</a>
        <a class="btn btn-sm btn-outline-secondary" href="?range=month">This month</a>
    </div>
    <div class="col-auto ms-auto">
        <button type="button" class="btn btn-outline-dark no-print" onclick="window.print()">Print</button>
    </div>
</form>

<p class="text-muted">
    Showing <strong><?= dt($from, 'd M Y') ?></strong> to <strong><?= dt($to, 'd M Y') ?></strong>.
    Voided orders are excluded.
</p>

<!-- ------------------------------------------------ headline numbers -->
<div class="row g-3 mb-4">
    <div class="col-6 col-lg-3"><div class="stat-card accent">
        <div class="label">Gross sales</div><div class="value"><?= money($gross) ?></div>
        <small class="text-muted"><?= $orderCount ?> paid order(s)</small></div></div>
    <div class="col-6 col-lg-3"><div class="stat-card">
        <div class="label">Average order</div><div class="value"><?= money($avgOrder) ?></div>
        <small class="text-muted">Discounts given: <?= money($head['discount']) ?></small></div></div>
    <div class="col-6 col-lg-3"><div class="stat-card good">
        <div class="label">Gross profit</div><div class="value"><?= money($grossProfit) ?></div>
        <small class="text-muted">Net sales <?= money($netSales) ?> − cost <?= money($cogs) ?></small></div></div>
    <div class="col-6 col-lg-3"><div class="stat-card <?= $netProfit >= 0 ? 'good' : 'bad' ?>">
        <div class="label">Net profit</div><div class="value"><?= money($netProfit) ?></div>
        <small class="text-muted">after <?= money($expenses) ?> expenses</small></div></div>
</div>

<!-- ------------------------------------------------ daily trend -->
<div class="card mb-4">
    <div class="card-header">Sales per day</div>
    <div class="card-body">
        <?php if (!$daily): ?>
            <p class="text-muted text-center py-4 mb-0">No paid orders in this period.</p>
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
                <div class="chart-labels">
                    <?php foreach ($daily as $d): ?>
                        <div><?= dt($d['d'], count($daily) > 15 ? 'j' : 'j M') ?></div>
                    <?php endforeach; ?>
                </div>
            </div>
            <p class="text-muted small mt-3 mb-0">
                Highest day highlighted. Hover a bar for its exact takings.
            </p>
        <?php endif; ?>
    </div>
</div>

<div class="row g-3">
    <!-- ------------------------------------------------ top items -->
    <div class="col-lg-7">
        <div class="card h-100">
            <div class="card-header">Best selling items</div>
            <table class="table table-sm mb-0">
                <thead><tr><th>Item</th><th class="text-end">Sold</th>
                    <th class="text-end">Revenue</th><th class="text-end">Profit</th></tr></thead>
                <tbody>
                <?php foreach ($topItems as $t): ?>
                    <tr>
                        <td><?= e($t['item_name']) ?></td>
                        <td class="text-end"><?= (int)$t['qty'] ?></td>
                        <td class="text-end"><?= money($t['revenue']) ?></td>
                        <td class="text-end text-muted"><?= money($t['profit']) ?></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$topItems): ?>
                    <tr><td colspan="4" class="text-center text-muted py-4">Nothing sold in this period.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- ------------------------------------------------ payment mix -->
    <div class="col-lg-5">
        <div class="card mb-3">
            <div class="card-header">How customers paid</div>
            <table class="table table-sm mb-0">
                <tbody>
                <?php foreach ($byMethod as $m): ?>
                    <tr>
                        <td><?= e(ucfirst((string)$m['m'])) ?></td>
                        <td class="text-end text-muted"><?= (int)$m['orders'] ?> order(s)</td>
                        <td class="text-end fw-bold"><?= money($m['total']) ?></td>
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
            <table class="table table-sm mb-0">
                <thead><tr><th>Category</th><th class="text-end">Qty</th><th class="text-end">Revenue</th></tr></thead>
                <tbody>
                <?php foreach ($byCategory as $c): ?>
                    <tr>
                        <td><?= e($c['category'] ?? 'Removed item') ?></td>
                        <td class="text-end"><?= (int)$c['qty'] ?></td>
                        <td class="text-end"><?= money($c['revenue']) ?></td>
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

<div class="row g-3 mt-1">
    <!-- ------------------------------------------------ staff -->
    <div class="col-lg-6">
        <div class="card h-100">
            <div class="card-header">Orders taken by staff</div>
            <table class="table table-sm mb-0">
                <thead><tr><th>Name</th><th>Role</th><th class="text-end">Orders</th><th class="text-end">Value</th></tr></thead>
                <tbody>
                <?php foreach ($byStaff as $s): ?>
                    <tr>
                        <td><?= e($s['full_name']) ?></td>
                        <td class="text-muted"><?= e(ucfirst($s['role'])) ?></td>
                        <td class="text-end"><?= (int)$s['orders'] ?></td>
                        <td class="text-end"><?= money($s['total']) ?></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$byStaff): ?>
                    <tr><td colspan="4" class="text-center text-muted py-3">No data.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- ------------------------------------------------ expenses -->
    <div class="col-lg-6">
        <div class="card h-100">
            <div class="card-header">Expenses by category</div>
            <table class="table table-sm mb-0">
                <tbody>
                <?php foreach ($expByCat as $x): ?>
                    <tr><td><?= e($x['category']) ?></td>
                        <td class="text-end fw-bold"><?= money($x['total']) ?></td></tr>
                <?php endforeach; ?>
                <?php if (!$expByCat): ?>
                    <tr><td class="text-center text-muted py-3">No expenses recorded.</td></tr>
                <?php endif; ?>
                </tbody>
                <tfoot><tr class="table-light">
                    <th>Total expenses</th><th class="text-end"><?= money($expenses) ?></th>
                </tr></tfoot>
            </table>
        </div>
    </div>
</div>

<?php if ((int)$voided['n'] > 0): ?>
    <p class="text-muted small mt-3">
        <?= (int)$voided['n'] ?> order(s) worth <?= money($voided['total']) ?> were voided in this period
        and are not included above.
    </p>
<?php endif; ?>

<?php require __DIR__ . '/../core/footer.php'; ?>
