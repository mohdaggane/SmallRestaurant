<?php
/**
 * Settles an open order (the one a waiter sent to the kitchen).
 * Posted from the payment modal on public/orders.php.
 */

require_once __DIR__ . '/../core/config.php';
require_role('cashier');

if (!is_post()) {
    redirect('public/orders.php');
}
csrf_check();

$orderId = (int)($_POST['order_id'] ?? 0);
$method  = in_array($_POST['payment_method'] ?? '', ['cash', 'mobile', 'card'], true)
    ? (string)$_POST['payment_method'] : 'cash';
$paid    = post_amount('paid_amount');

$order = db_one('SELECT * FROM orders WHERE id = ?', [$orderId]);

if (!$order) {
    flash('That order no longer exists.', 'danger');
    redirect('public/orders.php');
}
if ($order['status'] !== 'open') {
    flash('Order ' . $order['order_no'] . ' is already ' . $order['status'] . '.', 'warning');
    redirect('public/orders.php');
}

$shift = open_shift(user_id());
if (!$shift) {
    flash('Open your cash drawer shift before taking payment.', 'warning');
    redirect('admin/shifts.php');
}

$total = (float)$order['total'];
if ($paid + 0.001 < $total) {
    flash('Amount received (' . money($paid) . ') is less than the total ' . money($total) . '.', 'danger');
    redirect('public/orders.php');
}

db_exec(
    "UPDATE orders
        SET status = 'paid', paid_amount = ?, change_amount = ?, payment_method = ?,
            paid_by = ?, shift_id = ?, paid_at = NOW()
      WHERE id = ? AND status = 'open'",
    [$paid, round($paid - $total, 2), $method, user_id(), (int)$shift['id'], $orderId]
);

flash('Order ' . $order['order_no'] . ' paid. Change ' . money($paid - $total) . '.');
redirect('public/receipt.php?id=' . $orderId . '&print=1');
