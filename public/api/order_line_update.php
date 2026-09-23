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
    json_out(['ok' => false, 'error' => __('msg.malformed')], 400);
}

$orderId = (int)($body['order_id'] ?? 0);
$lineId  = (int)($body['line_id'] ?? 0);
$qty     = (int)($body['qty'] ?? -1);

if ($orderId <= 0 || $lineId <= 0 || $qty < 0) {
    json_out(['ok' => false, 'error' => __('msg.missing')], 422);
}

$conn->begin_transaction();
try {
    $order = db_one('SELECT * FROM orders WHERE id = ? AND company_id = ? FOR UPDATE', [$orderId, company_id()]);
    if (!$order) {
        throw new OrderException(__('msg.order_gone'), 404);
    }
    if ($order['status'] !== 'open') {
        throw new OrderException(__('msg.order_already', '', ['no' => $order['order_no'], 'status' => mb_strtolower(__('ost.' . $order['status'], $order['status']))]), 409);
    }

    $line = db_one('SELECT * FROM order_items WHERE id = ? AND order_id = ? AND company_id = ?', [$lineId, $orderId, company_id()]);
    if (!$line) {
        throw new OrderException(__('msg.line_gone'), 404);
    }
    if (!line_is_removable($line)) {
        throw new OrderException(
            __('msg.line_locked', '', ['name' => $line['item_name'], 'status' => __('ks.' . $line['kitchen_status'], $line['kitchen_status'])]),
            409
        );
    }
    if ($qty > (int)$line['qty']) {
        throw new OrderException(__('msg.only_reduce'), 422);
    }

    if ($qty === 0) {
        $lineCount = (int)db_value('SELECT COUNT(*) FROM order_items WHERE order_id = ? AND company_id = ?', [$orderId, company_id()]);
        if ($lineCount <= 1) {
            throw new OrderException(__('msg.last_item'), 409);
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
    json_out(['ok' => false, 'error' => __('msg.update_failed', '', ['error' => $e->getMessage()])], 500);
}

json_out([
    'ok'     => true,
    'lines'  => order_lines_for_pos($orderId),
    'totals' => $totals,
]);
