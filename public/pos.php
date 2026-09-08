<?php
/**
 * POS terminal — order entry for cashiers and waiters.
 * Cashier/admin can take payment; a waiter can only send orders to the kitchen,
 * which a cashier then settles from Open Orders.
 */

require_once __DIR__ . '/../core/config.php';
require_role('cashier', 'waiter');

$canCharge = has_role('admin', 'cashier');
$shift     = $canCharge ? open_shift(user_id()) : null;

$categories = db_all('SELECT id, name FROM categories WHERE is_active = 1 ORDER BY sort_order, name');
$items      = db_all(
    'SELECT i.id, i.category_id, i.name, i.price, i.cost_price, i.needs_prep
       FROM menu_items i
       JOIN categories c ON c.id = i.category_id
      WHERE i.is_available = 1 AND c.is_active = 1
      ORDER BY i.sort_order, i.name'
);

$taxPercent = (float)setting('tax_percent', '0');

$pageTitle = 'POS Terminal';
$layout    = 'wide';
require __DIR__ . '/../core/header.php';
?>

<?php if ($canCharge && !$shift): ?>
    <div class="alert alert-warning d-flex justify-content-between align-items-center">
        <span>Your cash drawer is closed. Open a shift before taking payments.</span>
        <a class="btn btn-sm btn-dark" href="<?= url('admin/shifts.php') ?>">Open shift</a>
    </div>
<?php endif; ?>

<div class="pos-grid">
    <!-- ------------------------------------------------ menu side -->
    <div>
        <div class="d-flex gap-2 mb-2">
            <input type="search" id="itemSearch" class="form-control" placeholder="Search the menu…" autocomplete="off">
            <a class="btn btn-outline-secondary" href="<?= url('public/orders.php') ?>">Open Orders</a>
        </div>

        <div class="cat-tabs" id="catTabs">
            <button class="cat-tab active" data-cat="all">All</button>
            <?php foreach ($categories as $c): ?>
                <button class="cat-tab" data-cat="<?= (int)$c['id'] ?>"><?= e($c['name']) ?></button>
            <?php endforeach; ?>
        </div>

        <div class="item-grid" id="itemGrid"></div>
        <p class="text-muted mt-3 d-none" id="noItems">No menu item matches that search.</p>
    </div>

    <!-- ------------------------------------------------ cart side -->
    <div class="cart-panel">
        <div class="cart-head">
            <div class="d-flex gap-2 mb-2">
                <select id="orderType" class="form-select form-select-sm">
                    <option value="dine_in">Dine in</option>
                    <option value="takeaway">Takeaway</option>
                </select>
                <input type="text" id="tableLabel" class="form-control form-control-sm"
                       placeholder="Table / name" maxlength="30">
            </div>
            <div class="d-flex justify-content-between align-items-center">
                <strong>Current order</strong>
                <button class="btn btn-sm btn-outline-danger" id="clearCart">Clear</button>
            </div>
        </div>

        <div class="cart-lines" id="cartLines"></div>

        <div class="cart-foot">
            <div class="total-row"><span>Subtotal</span><span id="sumSub">—</span></div>
            <div class="total-row">
                <span>Discount</span>
                <span><input type="number" id="discount" class="form-control form-control-sm text-end"
                             style="width:100px" min="0" step="0.01" value="0"></span>
            </div>
            <?php if ($taxPercent > 0): ?>
                <div class="total-row"><span>Tax (<?= e(rtrim(rtrim(number_format($taxPercent, 2), '0'), '.')) ?>%)</span><span id="sumTax">—</span></div>
            <?php endif; ?>
            <div class="total-row grand"><span>Total</span><span id="sumTotal">—</span></div>

            <?php if ($canCharge): ?>
                <div class="row g-2 mb-2">
                    <div class="col-6">
                        <select id="payMethod" class="form-select form-select-sm">
                            <option value="cash">Cash</option>
                            <option value="mobile">Mobile money</option>
                            <option value="card">Card</option>
                        </select>
                    </div>
                    <div class="col-6">
                        <input type="number" id="paidAmount" class="form-control form-control-sm text-end"
                               placeholder="Amount paid" min="0" step="0.01">
                    </div>
                </div>
                <div class="total-row"><span>Change</span><strong id="changeDue">—</strong></div>
            <?php endif; ?>

            <div class="d-grid gap-2 mt-2">
                <?php if ($canCharge): ?>
                    <button class="btn btn-lg text-white" style="background:var(--ok)" id="btnCharge"
                            <?= $shift ? '' : 'disabled' ?>>Charge &amp; Print</button>
                <?php endif; ?>
                <button class="btn btn-outline-secondary" id="btnHold">Send to Kitchen (unpaid)</button>
            </div>
        </div>
    </div>
</div>

<script>
window.POS = {
    items:      <?= json_encode($items, JSON_UNESCAPED_UNICODE) ?>,
    currency:   <?= json_encode(setting('currency', '$')) ?>,
    taxPercent: <?= json_encode($taxPercent) ?>,
    canCharge:  <?= $canCharge ? 'true' : 'false' ?>,
    hasShift:   <?= $shift ? 'true' : 'false' ?>,
    csrf:       <?= json_encode(csrf_token()) ?>,
    saveUrl:    <?= json_encode(url('public/api/order_save.php')) ?>,
    receiptUrl: <?= json_encode(url('public/receipt.php')) ?>
};
</script>
<?php
$pageScripts = '<script src="' . url('assets/js/pos.js') . '"></script>';
require __DIR__ . '/../core/footer.php';
