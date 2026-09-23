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
    'daily'     => [__('rt.daily'),     'admin/report_daily.php',     ['admin', 'cashier']],
    'unpaid'    => [__('rt.unpaid'),    'admin/report_unpaid.php',    ['admin', 'cashier']],
    'financial' => [__('rt.financial'), 'admin/report_financial.php', ['admin']],
    'overview'  => [__('rt.overview'),  'admin/reports.php',          ['admin']],
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
        <span class="text-muted self-center"><?= e(__('lbl.to')) ?></span>
        <input type="date" name="to" class="input" value="<?= e($to) ?>">
        <button class="btn btn-outline"><?= e(__('btn.apply')) ?></button>
        <div class="flex gap-1">
            <a class="btn btn-outline btn-sm" href="?range=today"><?= e(__('lbl.today')) ?></a>
            <a class="btn btn-outline btn-sm" href="?range=yesterday"><?= e(__('rt.yesterday')) ?></a>
            <a class="btn btn-outline btn-sm" href="?range=week"><?= e(__('rt.last7')) ?></a>
            <a class="btn btn-outline btn-sm" href="?range=month"><?= e(__('rt.this_month')) ?></a>
            <a class="btn btn-outline btn-sm" href="?range=lastmonth"><?= e(__('rt.last_month')) ?></a>
        </div>
    <?php elseif ($filterMode === 'date'): ?>
        <a class="btn btn-outline" href="?date=<?= e(date('Y-m-d', strtotime($date . ' -1 day'))) ?>">&larr;</a>
        <input type="date" name="date" class="input" value="<?= e($date) ?>"
               onchange="this.form.submit()">
        <a class="btn btn-outline" href="?date=<?= e(date('Y-m-d', strtotime($date . ' +1 day'))) ?>">&rarr;</a>
        <a class="btn btn-outline btn-sm" href="?date=<?= date('Y-m-d') ?>"><?= e(__('lbl.today')) ?></a>
    <?php endif; ?>

    <div class="flex gap-2 ml-auto">
        <?= $reportExtra ?? '' ?>
        <a class="btn btn-ok btn-sm" href="<?= e($csvHref) ?>"><?= e(__('rt.csv')) ?></a>
        <button type="button" class="btn btn-dark btn-sm" onclick="window.print()"><?= e(__('btn.print')) ?></button>
    </div>
</form>
