<?php
/**
 * Creates an order from the POS terminal.
 * Body (JSON): action = 'hold' | 'pay', order_type, table_label, discount,
 *              payment_method, paid_amount, lines[{id, qty, note}]
 *
 * Prices, costs and totals are re-read from the database — the browser only
 * says which item and how many, never what it costs.
 */

require_once __DIR__ . '/../../core/config.php';
require_role('cashier', 'waiter');
csrf_check(true);

$body = json_decode((string)file_get_contents('php://input'), true);
if (!is_array($body)) {
    json_out(['ok' => false, 'error' => 'Malformed request.'], 400);
}

$action = ($body['action'] ?? 'hold') === 'pay' ? 'pay' : 'hold';
$lines  = is_array($body['lines'] ?? null) ? $body['lines'] : [];

if (!$lines) {
    json_out(['ok' => false, 'error' => 'The order has no items.'], 422);
}
if ($action === 'pay' && !has_role('admin', 'cashier')) {
    json_out(['ok' => false, 'error' => 'Only a cashier can take payment.'], 403);
}

$shift = has_role('admin', 'cashier') ? open_shift(user_id()) : null;
if ($action === 'pay' && !$shift) {
    json_out(['ok' => false, 'error' => 'Open your cash drawer shift before taking payment.'], 409);
}

$orderType = ($body['order_type'] ?? 'dine_in') === 'takeaway' ? 'takeaway' : 'dine_in';
$tableLbl  = mb_substr(trim((string)($body['table_label'] ?? '')), 0, 30);
$payMethod = in_array($body['payment_method'] ?? '', ['cash', 'mobile', 'card'], true)
    ? $body['payment_method'] : 'cash';

// ---------------------------------------------------------------- pricing
$priced   = [];
$subtotal = 0.0;

foreach ($lines as $line) {
    $itemId = (int)($line['id'] ?? 0);
    $qty    = (int)($line['qty'] ?? 0);
    if ($itemId <= 0 || $qty <= 0) {
        continue;
    }

    $item = db_one('SELECT id, name, price, cost_price, needs_prep, is_available FROM menu_items WHERE id = ?', [$itemId]);
    if (!$item) {
        json_out(['ok' => false, 'error' => 'A menu item on this order no longer exists.'], 422);
    }
    if (!(int)$item['is_available']) {
        json_out(['ok' => false, 'error' => $item['name'] . ' is marked unavailable.'], 422);
    }

    $lineTotal = round((float)$item['price'] * $qty, 2);
    $subtotal += $lineTotal;

    $priced[] = [
        'item_id'    => (int)$item['id'],
        'name'       => $item['name'],
        'unit_price' => (float)$item['price'],
        'unit_cost'  => (float)$item['cost_price'],
        'qty'        => $qty,
        'line_total' => $lineTotal,
        'needs_prep' => (int)$item['needs_prep'],
        'note'       => mb_substr(trim((string)($line['note'] ?? '')), 0, 120),
    ];
}

if (!$priced) {
    json_out(['ok' => false, 'error' => 'The order has no valid items.'], 422);
}

$subtotal = round($subtotal, 2);
$discount = min(max(round((float)($body['discount'] ?? 0), 2), 0), $subtotal);
$tax      = round(($subtotal - $discount) * (float)setting('tax_percent', '0') / 100, 2);
$total    = round($subtotal - $discount + $tax, 2);

$paid   = $action === 'pay' ? round((float)($body['paid_amount'] ?? 0), 2) : 0.0;
$change = 0.0;

if ($action === 'pay') {
    if ($paid + 0.001 < $total) {
        json_out(['ok' => false, 'error' => 'Amount paid is less than the total.'], 422);
    }
    $change = round($paid - $total, 2);
}

// ---------------------------------------------------------------- persist
$conn->begin_transaction();
try {
    $now = date('Y-m-d H:i:s');

    $orderId = db_exec(
        'INSERT INTO orders
            (order_type, table_label, status, subtotal, discount, tax, total,
             paid_amount, change_amount, payment_method, created_by, paid_by, shift_id, created_at, paid_at)
         VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)',
        [
            $orderType,
            $tableLbl !== '' ? $tableLbl : null,
            $action === 'pay' ? 'paid' : 'open',
            $subtotal, $discount, $tax, $total,
            $paid, $change,
            $action === 'pay' ? $payMethod : null,
            user_id(),
            $action === 'pay' ? user_id() : null,
            $action === 'pay' ? (int)$shift['id'] : null,
            $now,
            $action === 'pay' ? $now : null,
        ]
    );

    $orderNo = build_order_no($orderId, $now);
    db_exec('UPDATE orders SET order_no = ? WHERE id = ?', [$orderNo, $orderId]);

    foreach ($priced as $p) {
        db_exec(
            'INSERT INTO order_items
                (order_id, menu_item_id, item_name, unit_price, unit_cost, qty, line_total, note, needs_prep, kitchen_status)
             VALUES (?,?,?,?,?,?,?,?,?,?)',
            [
                $orderId, $p['item_id'], $p['name'], $p['unit_price'], $p['unit_cost'],
                $p['qty'], $p['line_total'], $p['note'] !== '' ? $p['note'] : null,
                $p['needs_prep'],
                // Items that need no preparation (bottled drinks) skip the kitchen queue.
                $p['needs_prep'] ? 'pending' : 'served',
            ]
        );
    }

    $conn->commit();
} catch (Throwable $e) {
    $conn->rollback();
    json_out(['ok' => false, 'error' => 'Could not save the order: ' . $e->getMessage()], 500);
}

json_out([
    'ok'       => true,
    'order_id' => $orderId,
    'order_no' => $orderNo,
    'total'    => $total,
    'change'   => $change,
]);
