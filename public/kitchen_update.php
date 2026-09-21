<?php
/**
 * Advances kitchen state for one line (item_id) or a whole order (order_id).
 * Posted from the buttons on public/kitchen.php.
 */

require_once __DIR__ . '/../core/config.php';
require_role('kitchen', 'waiter', 'cashier');

if (!is_post()) {
    redirect('public/kitchen.php');
}
csrf_check();

$to = in_array($_POST['to'] ?? '', ['pending', 'preparing', 'served'], true)
    ? (string)$_POST['to'] : 'served';

$itemId  = (int)($_POST['item_id'] ?? 0);
$orderId = (int)($_POST['order_id'] ?? 0);

if ($itemId > 0) {
    db_exec('UPDATE order_items SET kitchen_status = ? WHERE id = ? AND company_id = ?', [$to, $itemId, company_id()]);
} elseif ($orderId > 0) {
    db_exec(
        'UPDATE order_items SET kitchen_status = ? WHERE order_id = ? AND company_id = ? AND needs_prep = 1',
        [$to, $orderId, company_id()]
    );
    flash('Order marked ' . $to . '.');
} else {
    flash('Nothing to update.', 'warning');
}

redirect('public/kitchen.php');
