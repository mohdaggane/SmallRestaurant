<?php
/**
 * Printable 80mm receipt / bill.
 * ?id=<order>  and optionally &print=1 to open the print dialog at once.
 * An unpaid order prints as a BILL, a paid one as a RECEIPT.
 */

require_once __DIR__ . '/../core/config.php';
require_role('cashier', 'waiter');

$orderId = (int)get('id');
$order   = db_one(
    'SELECT o.*, c.full_name AS taken_by, p.full_name AS paid_by_name
       FROM orders o
       JOIN users c ON c.id = o.created_by
  LEFT JOIN users p ON p.id = o.paid_by
      WHERE o.id = ? AND o.company_id = ?',
    [$orderId, company_id()]
);

if (!$order) {
    http_response_code(404);
    exit('Order not found.');
}

$lines    = db_all('SELECT * FROM order_items WHERE order_id = ? AND company_id = ? ORDER BY id', [$orderId, company_id()]);
$isPaid   = $order['status'] === 'paid';
$autoPrint = get('print') === '1';

$pageTitle = ($isPaid ? 'Receipt ' : 'Bill ') . $order['order_no'];
$layout    = 'blank';
require __DIR__ . '/../core/header.php';
?>

<div class="no-print text-center py-3">
    <button class="btn btn-dark" onclick="window.print()">Print</button>
    <a class="btn btn-outline" href="<?= url('public/pos.php') ?>">Back to POS</a>
    <a class="btn btn-outline" href="<?= url('public/orders.php') ?>">Open orders</a>
</div>

<div class="receipt">
    <h2><?= e(setting('shop_name', 'Small Restaurant')) ?></h2>
    <?php if (setting('shop_phone') !== ''): ?>
        <div class="center" style="font-size:10.5px;margin-top:2px;">Tel: <?= e(setting('shop_phone')) ?></div>
    <?php endif; ?>

    <div class="rule"></div>

    <div class="center"><strong><?= $isPaid ? 'RECEIPT' : 'BILL (UNPAID)' ?></strong><?php if ($order['status'] === 'void'): ?> <strong>*** VOIDED ***</strong><?php endif; ?></div>
    <div class="center" style="font-size:10px;margin-top:2px;">
        #<?= e($order['order_no']) ?> &middot; <?= dt($order['created_at'], 'd M Y, g:i A') ?><?php if ($order['taken_by']): ?> &middot; <?= e($order['taken_by']) ?><?php endif; ?>
    </div>

    <div class="rule"></div>

    <table>
        <?php foreach ($lines as $li): ?>
            <tr>
                <td><?= e($li['item_name']) ?> <span style="font-size:10px;"><?= (int)$li['qty'] ?>&times;<?= money($li['unit_price']) ?></span><?php if ($li['note']): ?><br><em style="font-size:10px;"><?= e($li['note']) ?></em><?php endif; ?></td>
                <td class="r"><?= money($li['line_total']) ?></td>
            </tr>
        <?php endforeach; ?>
    </table>

    <div class="rule"></div>

    <table>
        <?php if ((float)$order['discount'] > 0): ?>
            <tr><td>Discount</td><td class="r">-<?= money($order['discount']) ?></td></tr>
        <?php endif; ?>
        <tr>
            <td>
                <strong>TOTAL</strong>
                <?php if ((float)$order['tax'] > 0): ?>
                    <span style="font-size:11px;font-weight:normal;">(<?= money((float)$order['subtotal'] - (float)$order['discount']) ?> + VAT <?= money($order['tax']) ?>)</span>
                <?php endif; ?>
            </td>
            <td class="r"><strong><?= money($order['total']) ?></strong></td>
        </tr>
        <?php if ($isPaid && (float)$order['change_amount'] > 0.005): ?>
            <tr style="font-size:11px;">
                <td>Paid <?= money($order['paid_amount']) ?></td>
                <td class="r">Change <?= money($order['change_amount']) ?></td>
            </tr>
        <?php endif; ?>
    </table>

    <?php
    // Mobile-money dial code. Always on an unpaid bill; on a paid receipt only
    // when the shop has asked for it in Settings.
    $ussd = merchant_ussd((float)$order['total']);
    $showUssd = $ussd !== null
        && $order['status'] !== 'void'
        && (!$isPaid || setting('merchant_on_receipt', '1') === '1');
    ?>
    <?php if ($showUssd): ?>
        <div class="rule"></div>
        <div class="center" style="font-size:10.5px;">
            <?php if (setting('merchant_name') !== ''): ?><?= e(setting('merchant_name')) ?><br><?php endif; ?>
            <span style="font-family:monospace;letter-spacing:0.5px;"><?= e($ussd) ?></span>
            <span class="no-print"> · <a href="<?= e(merchant_ussd_link($ussd)) ?>">Dial</a></span>
        </div>
    <?php endif; ?>

    <div class="rule"></div>
    <div class="center"><?= e(setting('receipt_footer', 'Thank you!')) ?></div>
    <div class="center" style="margin-top:6px;font-size:11px;">
        <!-- <?= $isPaid ? 'Paid (' . e(ucfirst((string)$order['payment_method'])) . ') · ' . dt($order['paid_at']) : 'Please pay at the counter' ?> -->
    </div>
    <div class="rule"></div>
    <div class="center" style="font-size:10.5px;margin-top:4px;letter-spacing:0.5px;">
        Made By SAHAN ICT<br>sahanict.org
    </div>
</div>

<?php
if ($autoPrint) {
    $pageScripts = '<script>window.addEventListener("load", function () { window.print(); });</script>';
}
require __DIR__ . '/../core/footer.php';
