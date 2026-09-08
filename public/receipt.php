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
      WHERE o.id = ?',
    [$orderId]
);

if (!$order) {
    http_response_code(404);
    exit('Order not found.');
}

$lines    = db_all('SELECT * FROM order_items WHERE order_id = ? ORDER BY id', [$orderId]);
$isPaid   = $order['status'] === 'paid';
$autoPrint = get('print') === '1';

$pageTitle = ($isPaid ? 'Receipt ' : 'Bill ') . $order['order_no'];
$layout    = 'blank';
require __DIR__ . '/../core/header.php';
?>

<div class="no-print text-center py-3">
    <button class="btn btn-dark" onclick="window.print()">Print</button>
    <a class="btn btn-outline-secondary" href="<?= url('public/pos.php') ?>">Back to POS</a>
    <a class="btn btn-outline-secondary" href="<?= url('public/orders.php') ?>">Open orders</a>
</div>

<div class="receipt">
    <h2><?= e(setting('shop_name', 'Small Restaurant')) ?></h2>
    <div class="center"><?= e(setting('shop_tagline', 'Tea & Food')) ?></div>
    <?php if (setting('shop_address') !== ''): ?>
        <div class="center"><?= e(setting('shop_address')) ?></div>
    <?php endif; ?>
    <?php if (setting('shop_phone') !== ''): ?>
        <div class="center">Tel: <?= e(setting('shop_phone')) ?></div>
    <?php endif; ?>

    <div class="rule"></div>

    <div class="center"><strong><?= $isPaid ? 'RECEIPT' : 'BILL (UNPAID)' ?></strong></div>
    <?php if ($order['status'] === 'void'): ?>
        <div class="center"><strong>*** VOIDED ***</strong></div>
    <?php endif; ?>

    <table>
        <tr><td>No.</td><td class="r">#<?= e($order['order_no']) ?></td></tr>
        <tr><td>Date</td><td class="r"><?= dt($order['created_at']) ?></td></tr>
        <tr>
            <td>Type</td>
            <td class="r">
                <?= $order['order_type'] === 'takeaway' ? 'Takeaway' : 'Dine in' ?><?= $order['table_label'] ? ' · ' . e($order['table_label']) : '' ?>
            </td>
        </tr>
        <tr><td>Served by</td><td class="r"><?= e($order['taken_by']) ?></td></tr>
    </table>

    <div class="rule"></div>

    <table>
        <?php foreach ($lines as $li): ?>
            <tr>
                <td>
                    <?= e($li['item_name']) ?><br>
                    <?= (int)$li['qty'] ?> &times; <?= money($li['unit_price']) ?>
                    <?php if ($li['note']): ?><br><em><?= e($li['note']) ?></em><?php endif; ?>
                </td>
                <td class="r"><?= money($li['line_total']) ?></td>
            </tr>
        <?php endforeach; ?>
    </table>

    <div class="rule"></div>

    <table>
        <tr><td>Subtotal</td><td class="r"><?= money($order['subtotal']) ?></td></tr>
        <?php if ((float)$order['discount'] > 0): ?>
            <tr><td>Discount</td><td class="r">-<?= money($order['discount']) ?></td></tr>
        <?php endif; ?>
        <?php if ((float)$order['tax'] > 0): ?>
            <tr><td>Tax</td><td class="r"><?= money($order['tax']) ?></td></tr>
        <?php endif; ?>
        <tr>
            <td><strong>TOTAL</strong></td>
            <td class="r"><strong><?= money($order['total']) ?></strong></td>
        </tr>
        <?php if ($isPaid): ?>
            <tr><td>Paid (<?= e(ucfirst((string)$order['payment_method'])) ?>)</td>
                <td class="r"><?= money($order['paid_amount']) ?></td></tr>
            <tr><td>Change</td><td class="r"><?= money($order['change_amount']) ?></td></tr>
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
        <div class="center"><strong><?= $isPaid ? 'MOBILE MONEY ACCOUNT' : 'PAY BY MOBILE MONEY' ?></strong></div>
        <?php if (setting('merchant_name') !== ''): ?>
            <div class="center"><?= e(setting('merchant_name')) ?></div>
        <?php endif; ?>
        <div class="pay-code"><?= e($ussd) ?></div>
        <div class="center" style="font-size:11px;">
            <?= $isPaid
                ? 'Merchant ' . e(setting('merchant_id'))
                : 'Dial the code above to pay ' . e(money($order['total'])) ?>
        </div>
        <div class="center no-print" style="margin-top:6px;">
            <a href="<?= e(merchant_ussd_link($ussd)) ?>">Dial on this device</a>
        </div>
    <?php endif; ?>

    <div class="rule"></div>
    <div class="center"><?= e(setting('receipt_footer', 'Thank you!')) ?></div>
    <div class="center" style="margin-top:6px;font-size:11px;">
        <?= $isPaid ? 'Paid ' . dt($order['paid_at']) : 'Please pay at the counter' ?>
    </div>
</div>

<?php
if ($autoPrint) {
    $pageScripts = '<script>window.addEventListener("load", function () { window.print(); });</script>';
}
require __DIR__ . '/../core/footer.php';
