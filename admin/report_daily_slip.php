<?php
/**
 * End-of-day summary slip for the 80mm thermal printer.
 * ?date=YYYY-MM-DD, and &print=1 to open the print dialog straight away.
 * Figures come from daily_totals(), the same source as the Daily page.
 */

require_once __DIR__ . '/../core/config.php';
require_role('cashier');

$date = valid_date(get('date')) ? get('date') : date('Y-m-d');
$t    = daily_totals($date);

$pageTitle = 'End of day ' . $date;
$layout    = 'blank';
require __DIR__ . '/../core/header.php';
?>

<div class="no-print text-center py-3">
    <button class="btn btn-dark" onclick="window.print()">Print</button>
    <a class="btn btn-outline" href="<?= url('admin/report_daily.php?date=' . e($date)) ?>">Back to the report</a>
</div>

<div class="receipt">
    <h2><?= e(setting('shop_name', 'Small Restaurant')) ?></h2>
    <?php if (setting('shop_phone') !== ''): ?>
        <div class="center" style="font-size:10.5px;margin-top:2px;">Tel: <?= e(setting('shop_phone')) ?></div>
    <?php endif; ?>
    <div class="rule"></div>
    <div class="center"><strong>END OF DAY</strong></div>
    <div class="center"><?= dt($date, 'D d M Y') ?></div>
    <div class="rule"></div>

    <table>
        <tr><td>Paid orders</td><td class="r"><?= $t['orders'] ?></td></tr>
        <?php if ($t['discount'] > 0): ?>
            <tr><td>Discounts given</td><td class="r"><?= money($t['discount']) ?></td></tr>
        <?php endif; ?>
        <tr><td><strong>SALES before VAT</strong></td><td class="r"><strong><?= money($t['net']) ?></strong></td></tr>
        <tr><td><strong>VAT collected</strong></td><td class="r"><strong><?= money($t['tax']) ?></strong></td></tr>
        <tr><td><strong>TOTAL COLLECTED</strong></td><td class="r"><strong><?= money($t['sales']) ?></strong></td></tr>
    </table>

    <div class="rule"></div>
    <table>
        <tr><td>Cash</td><td class="r"><?= money($t['cash']) ?></td></tr>
        <tr><td>Mobile money</td><td class="r"><?= money($t['mobile']) ?></td></tr>
        <tr><td>Card</td><td class="r"><?= money($t['card']) ?></td></tr>
    </table>

    <div class="rule"></div>
    <table>
        <tr><td>Expenses (all)</td><td class="r">-<?= money($t['expenses']) ?></td></tr>
        <tr><td>&nbsp;from drawer</td><td class="r">-<?= money($t['exp_drawer']) ?></td></tr>
        <tr><td><strong>NET CASH</strong></td><td class="r"><strong><?= money($t['net_cash']) ?></strong></td></tr>
    </table>

    <?php foreach ($t['shifts'] as $s): ?>
        <div class="rule"></div>
        <div><strong>Drawer: <?= e($s['full_name']) ?></strong></div>
        <table>
            <tr><td>Opened</td><td class="r"><?= dt($s['opened_at'], 'g:i A') ?></td></tr>
            <tr><td>Float</td><td class="r"><?= money($s['opening_float']) ?></td></tr>
            <tr><td>Expected</td><td class="r"><?= money($s['expected_cash']) ?></td></tr>
            <?php if ($s['status'] === 'closed'): ?>
                <tr><td>Counted</td><td class="r"><?= money($s['counted_cash']) ?></td></tr>
                <tr><td>Variance</td>
                    <td class="r"><?= ((float)$s['variance'] > 0 ? '+' : '') . money($s['variance']) ?></td></tr>
            <?php else: ?>
                <tr><td colspan="2">** still open **</td></tr>
            <?php endif; ?>
        </table>
    <?php endforeach; ?>

    <div class="rule"></div>
    <table>
        <tr><td>Unpaid bills</td><td class="r"><?= $t['unpaid_n'] ?> · <?= money($t['unpaid']) ?></td></tr>
        <tr><td>Voided</td><td class="r"><?= $t['void_n'] ?> · <?= money($t['void_total']) ?></td></tr>
    </table>

    <div class="rule"></div>
    <div class="center" style="font-size:11px;">
        Printed <?= date('d M Y, g:i A') ?><br>by <?= e(current_user()['full_name'] ?? '') ?>
    </div>
    <div class="rule"></div>
    <div class="center" style="font-size:10.5px;margin-top:4px;letter-spacing:0.5px;">
        Made By SAHAN ICT<br>sahanict.org
    </div>
</div>

<?php
if (get('print') === '1') {
    $pageScripts = '<script>window.addEventListener("load", function () { window.print(); });</script>';
}
require __DIR__ . '/../core/footer.php';
