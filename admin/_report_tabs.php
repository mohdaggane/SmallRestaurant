<?php
/**
 * Shared header for the report pages: tab bar, filter, and Print/CSV buttons.
 * Included by admin/report_*.php and admin/reports.php — never opened directly.
 *
 * The including page sets:
 *   $reportTab     'overview' | 'daily' | 'unpaid' | 'financial'
 *   $filterMode    'range'  (from/to + presets) | 'date' (one day) | 'none'
 *   $from, $to     for 'range';  $date for 'date'
 *   $reportExtra   optional HTML for extra buttons (e.g. the thermal slip)
 */

if (!defined('BASE_URL')) {
    exit;
}

$tabs = [
    'daily'     => ['Daily transactions', 'admin/report_daily.php',     ['admin', 'cashier']],
    'unpaid'    => ['Unpaid bills',       'admin/report_unpaid.php',    ['admin', 'cashier']],
    'financial' => ['Financial (P&L)',    'admin/report_financial.php', ['admin']],
    'overview'  => ['Sales overview',     'admin/reports.php',          ['admin']],
];

// CSV link keeps whatever filter is on screen.
$csvQuery = $_GET;
$csvQuery['export'] = 'csv';
$csvHref  = '?' . http_build_query($csvQuery);
?>
<!-- Tab bar -->
<div class="flex gap-0 border-b border-line mb-4 no-print overflow-x-auto">
    <?php foreach ($tabs as $key => [$label, $path, $roles]): ?>
        <?php if (!has_role(...$roles)) continue; ?>
        <a href="<?= url($path) ?>"
           class="px-4 py-2.5 text-sm font-medium whitespace-nowrap border-b-2 -mb-px transition-colors
                  <?= $reportTab === $key
                      ? 'border-accent text-brand-dark font-semibold'
                      : 'border-transparent text-muted hover:text-ink hover:border-line' ?>">
            <?= e($label) ?>
        </a>
    <?php endforeach; ?>
</div>

<form class="flex flex-wrap gap-2 mb-4 items-center no-print" method="get">
    <?php if ($filterMode === 'range'): ?>
        <input type="date" name="from" class="input" value="<?= e($from) ?>">
        <span class="text-muted self-center">to</span>
        <input type="date" name="to" class="input" value="<?= e($to) ?>">
        <button class="btn btn-outline">Apply</button>
        <div class="flex gap-1">
            <a class="btn btn-outline btn-sm" href="?range=today">Today</a>
            <a class="btn btn-outline btn-sm" href="?range=yesterday">Yesterday</a>
            <a class="btn btn-outline btn-sm" href="?range=week">Last 7 days</a>
            <a class="btn btn-outline btn-sm" href="?range=month">This month</a>
            <a class="btn btn-outline btn-sm" href="?range=lastmonth">Last month</a>
        </div>
    <?php elseif ($filterMode === 'date'): ?>
        <a class="btn btn-outline" href="?date=<?= e(date('Y-m-d', strtotime($date . ' -1 day'))) ?>">&larr;</a>
        <input type="date" name="date" class="input" value="<?= e($date) ?>"
               onchange="this.form.submit()">
        <a class="btn btn-outline" href="?date=<?= e(date('Y-m-d', strtotime($date . ' +1 day'))) ?>">&rarr;</a>
        <a class="btn btn-outline btn-sm" href="?date=<?= date('Y-m-d') ?>">Today</a>
    <?php endif; ?>

    <div class="flex gap-2 ml-auto">
        <?= $reportExtra ?? '' ?>
        <a class="btn btn-ok btn-sm" href="<?= e($csvHref) ?>">Download CSV</a>
        <button type="button" class="btn btn-dark btn-sm" onclick="window.print()">Print</button>
    </div>
</form>
