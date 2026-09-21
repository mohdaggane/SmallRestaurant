<?php
/**
 * Voids an order. Admin only — a voided order keeps its lines for the
 * audit trail but is excluded from every sales figure.
 */

require_once __DIR__ . '/../core/config.php';
require_role('admin');

if (!is_post()) {
    redirect('public/orders.php');
}
csrf_check();

$orderId = (int)($_POST['order_id'] ?? 0);
$reason  = mb_substr(post('reason', 'Voided by administrator'), 0, 255);
$order   = db_one('SELECT * FROM orders WHERE id = ? AND company_id = ?', [$orderId, company_id()]);

if (!$order) {
    flash('That order no longer exists.', 'danger');
    redirect('public/orders.php');
}
if ($order['status'] === 'void') {
    flash('Order ' . $order['order_no'] . ' is already void.', 'warning');
    redirect('public/orders.php');
}

db_exec(
    "UPDATE orders SET status = 'void', voided_at = NOW(), void_reason = ? WHERE id = ? AND company_id = ?",
    [$reason, $orderId, company_id()]
);

flash('Order ' . $order['order_no'] . ' has been voided.', 'warning');

// Only return to a page we actually link this form from — never an arbitrary URL.
$allowedBack = ['public/orders.php', 'admin/sales.php'];
$back = post('back');
redirect(in_array($back, $allowedBack, true) ? $back : 'public/orders.php');
