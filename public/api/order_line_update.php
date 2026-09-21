<?php
/**
 * Reduces or removes one line of an open order from the POS edit screen.
 * Body (JSON): order_id, line_id, qty   (qty = 0 removes the line)
 *
 * Only lines the kitchen has not started may change (see line_is_removable);
 * anything cooking or served can only go through an admin void. The last line
 * cannot be removed — an empty order is a void, not an edit.
 */

require_once __DIR__ . '/../../core/config.php';
require_role('cashier', 'waiter');
csrf_check(true);

$body = json_decode((string)file_get_contents('php://input'), true);
if (!is_array($body)) {
    json_out(['ok' => false, 'error' => 'Malformed request.'], 400);
}

$orderId = (int)($body['order_id'] ?? 0);
$lineId  = (int)($body['line_id'] ?? 0);
$qty     = (int)($body['qty'] ?? -1);

if ($orderId <= 0 || $lineId <= 0 || $qty < 0) {
    json_out(['ok' => false, 'error' => 'Missing order, line or quantity.'], 422);
}

$conn->begin_transaction();
try {
    $order = db_one('SELECT * FROM orders WHERE id = ? AND company_id = ? FOR UPDATE', [$orderId, company_id()]);
    if (!$order) {
        throw new OrderException('That order no longer exists.', 404);
    }
    if ($order['status'] !== 'open') {
        throw new OrderException('Order ' . $order['order_no'] . ' is already ' . $order['status'] . '.', 409);
    }

    $line = db_one('SELECT * FROM order_items WHERE id = ? AND order_id = ? AND company_id = ?', [$lineId, $orderId, company_id()]);
    if (!$line) {
        throw new OrderException('That item is not on this order.', 404);
    }
    if (!line_is_removable($line)) {
        throw new OrderException(
            $line['item_name'] . ' is already ' . $line['kitchen_status'] . ' in the kitchen. '
            . 'Only an admin can remove it by voiding the order.',
            409
        );
    }
    if ($qty > (int)$line['qty']) {
        throw new OrderException('Use the menu to add more — this screen only reduces.', 422);
    }

    if ($qty === 0) {
        $lineCount = (int)db_value('SELECT COUNT(*) FROM order_items WHERE order_id = ? AND company_id = ?', [$orderId, company_id()]);
        if ($lineCount <= 1) {
            throw new OrderException('That is the last item on the order — void the order instead.', 409);
        }
        db_exec('DELETE FROM order_items WHERE id = ? AND company_id = ?', [$lineId, company_id()]);
    } else {
        db_exec(
            'UPDATE order_items SET qty = ?, line_total = ? WHERE id = ? AND company_id = ?',
            [$qty, round((float)$line['unit_price'] * $qty, 2), $lineId, company_id()]
        );
    }

    $totals = recalc_order_totals($orderId, (float)$order['discount']);
    $conn->commit();
} catch (OrderException $e) {
    $conn->rollback();
    json_out(['ok' => false, 'error' => $e->getMessage()], $e->getCode() ?: 422);
} catch (Throwable $e) {
    $conn->rollback();
    json_out(['ok' => false, 'error' => 'Could not update the order: ' . $e->getMessage()], 500);
}

json_out([
    'ok'     => true,
    'lines'  => order_lines_for_pos($orderId),
    'totals' => $totals,
]);
