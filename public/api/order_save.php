<?php
/**
 * Creates an order, or adds a round of items to an existing open order.
 * Body (JSON): action = 'hold' | 'pay', order_id (optional — present when
 *              adding to an open order), order_type, table_label, discount,
 *              payment_method, paid_amount, lines[{id, qty, note}]
 *
 * Prices, costs and totals are re-read from the database — the browser only
 * says which item and how many, never what it costs. Both paths share one
 * flow: save lines, recalculate totals from the saved lines, then optionally
 * take payment, all in a single transaction.
 */

require_once __DIR__ . '/../../core/config.php';
require_role('cashier', 'waiter');
csrf_check(true);

$body = json_decode((string)file_get_contents('php://input'), true);
if (!is_array($body)) {
    json_out(['ok' => false, 'error' => __('msg.malformed')], 400);
}

$action  = ($body['action'] ?? 'hold') === 'pay' ? 'pay' : 'hold';
$lines   = is_array($body['lines'] ?? null) ? $body['lines'] : [];
$orderId = (int)($body['order_id'] ?? 0);
$isAdd   = $orderId > 0;

if (!$lines) {
    json_out(['ok' => false, 'error' => __('msg.no_items')], 422);
}
if ($action === 'pay' && !has_role('admin', 'cashier')) {
    json_out(['ok' => false, 'error' => __('msg.cashier_only')], 403);
}

$shift = has_role('admin', 'cashier') ? open_shift(user_id()) : null;
if ($action === 'pay' && !$shift) {
    json_out(['ok' => false, 'error' => __('msg.open_shift')], 409);
}

$orderType = ($body['order_type'] ?? 'dine_in') === 'takeaway' ? 'takeaway' : 'dine_in';
$tableLbl  = mb_substr(trim((string)($body['table_label'] ?? '')), 0, 30);
$payMethod = in_array($body['payment_method'] ?? '', ['cash', 'mobile', 'card'], true)
    ? $body['payment_method'] : 'cash';
$paid      = round((float)($body['paid_amount'] ?? 0), 2);

$conn->begin_transaction();
try {
    $priced = price_order_lines($lines);
    $now    = date('Y-m-d H:i:s');

    if ($isAdd) {
        // Lock the row: two waiters adding to the same table at once must not
        // both read the old totals and overwrite each other.
        $order = db_one('SELECT * FROM orders WHERE id = ? AND company_id = ? FOR UPDATE', [$orderId, company_id()]);
        if (!$order) {
            throw new OrderException(__('msg.order_gone'), 404);
        }
        if ($order['status'] !== 'open') {
            throw new OrderException(
                __('msg.order_new', '', ['no' => $order['order_no'], 'status' => mb_strtolower(__('ost.' . $order['status'], $order['status']))]),
                409
            );
        }
        $orderNo  = $order['order_no'];
        $round    = (int)db_value('SELECT COALESCE(MAX(round), 0) FROM order_items WHERE order_id = ? AND company_id = ?', [$orderId, company_id()]) + 1;
        // Keep the discount already on the order unless the POS sent a new one.
        $discount = array_key_exists('discount', $body) ? (float)$body['discount'] : (float)$order['discount'];
    } else {
        $orderNo = next_order_no($now);
        $orderId = db_exec(
            'INSERT INTO orders (company_id, order_no, order_type, table_label, status, created_by, created_at)
             VALUES (?,?,?,?,?,?,?)',
            [company_id(), $orderNo, $orderType, $tableLbl !== '' ? $tableLbl : null, 'open', user_id(), $now]
        );
        $round    = 1;
        $discount = (float)($body['discount'] ?? 0);
    }

    insert_order_lines($orderId, $priced, $round, $now);
    $totals = recalc_order_totals($orderId, $discount, $isAdd);

    $change = 0.0;
    if ($action === 'pay') {
        $change = mark_order_paid($orderId, $totals['total'], $paid, $payMethod, (int)$shift['id']);
    }

    $conn->commit();
} catch (OrderException $e) {
    $conn->rollback();
    json_out(['ok' => false, 'error' => $e->getMessage()], $e->getCode() ?: 422);
} catch (Throwable $e) {
    $conn->rollback();
    json_out(['ok' => false, 'error' => __('msg.save_failed', '', ['error' => $e->getMessage()])], 500);
}

json_out([
    'ok'       => true,
    'order_id' => $orderId,
    'order_no' => $orderNo,
    'round'    => $round,
    'total'    => $totals['total'],
    'change'   => $change,
    'paid'     => $action === 'pay',
]);
