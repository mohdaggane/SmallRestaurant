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

$categories = db_all('SELECT id, name FROM categories WHERE company_id = ? AND is_active = 1 ORDER BY sort_order, name', [company_id()]);
$items      = db_all(
    'SELECT i.id, i.category_id, i.name, i.price, i.cost_price, i.needs_prep
       FROM menu_items i
       JOIN categories c ON c.id = i.category_id
      WHERE i.company_id = ? AND i.is_available = 1 AND c.is_active = 1
      ORDER BY i.sort_order, i.name',
    [company_id()]
);

$taxPercent = vat_rate();

// ?order=ID opens the terminal in "add to this order" mode.
$editOrder = null;
if (get('order') !== '') {
    $o = db_one('SELECT * FROM orders WHERE id = ? AND company_id = ?', [(int)get('order'), company_id()]);
    if (!$o) {
        flash(__('msg.order_gone'), 'danger');
        redirect('public/orders.php');
    }
    if ($o['status'] !== 'open') {
        flash(__('msg.order_locked', '', ['no' => $o['order_no'], 'status' => mb_strtolower(__('ost.' . $o['status'], $o['status']))]), 'warning');
        redirect('public/orders.php');
    }
    $editOrder = [
        'id'          => (int)$o['id'],
        'order_no'    => $o['order_no'],
        'order_type'  => $o['order_type'],
        'table_label' => (string)$o['table_label'],
        'discount'    => (float)$o['discount'],
        'lines'       => order_lines_for_pos((int)$o['id']),
    ];
}

$pageTitle = $editOrder ? __('pos.add_to_order', 'Add to order #') . $editOrder['order_no'] : __('pos.title', 'POS Terminal');
$layout    = 'wide';
require __DIR__ . '/../core/header.php';
?>

<?php if ($canCharge && !$shift): ?>
    <div class="flash-warning flex justify-between items-center mx-3 mb-0">
        <span><?= e(__('pos.no_shift_warning', 'Your cash drawer is closed. Open a shift before taking payments.')) ?></span>
        <a class="btn btn-dark btn-sm ml-3" href="<?= url('admin/shifts.php') ?>"><?= e(__('pos.open_shift', 'Open shift')) ?></a>
    </div>
<?php endif; ?>

<?php if ($editOrder): ?>
    <div class="flash-info flex justify-between items-center mx-3 mb-0">
        <span>
            <?= e(__('pos.adding_to', 'Adding to order')) ?> <strong>#<?= e($editOrder['order_no']) ?></strong>
            · <?= $editOrder['order_type'] === 'takeaway' ? e(__('pos.takeaway', 'Takeaway')) : e(__('pos.dine_in', 'Dine in')) ?><?= $editOrder['table_label'] !== '' ? ' · ' . e($editOrder['table_label']) : '' ?>
            — <?= e(__('pos.new_items_round', '— new items go to the kitchen as the next round.')) ?>
        </span>
        <a class="btn btn-outline btn-sm ml-3" href="<?= url('public/orders.php') ?>"><?= e(__('pos.cancel', 'Cancel')) ?></a>
    </div>
<?php endif; ?>

<div class="grid grid-cols-1 lg:grid-cols-[1fr_380px] gap-3 p-3 items-start">
    <!-- ------------------------------------------------ menu side -->
    <div>
        <div class="flex gap-2 mb-3">
            <input type="search" id="itemSearch" class="input flex-1" placeholder="<?= e(__('pos.search_menu', 'Search the menu…')) ?>" autocomplete="off">
            <a class="btn btn-outline" href="<?= url('public/orders.php') ?>"><?= e(__('pos.open_orders', 'Open Orders')) ?></a>
        </div>

        <div class="flex gap-2 flex-wrap mb-3" id="catTabs">
            <button class="cat-tab active" data-cat="all"><?= e(__('pos.all_categories', 'All')) ?></button>
            <?php foreach ($categories as $c): ?>
                <button class="cat-tab" data-cat="<?= (int)$c['id'] ?>"><?= e($c['name']) ?></button>
            <?php endforeach; ?>
        </div>

        <div class="grid grid-cols-[repeat(auto-fill,minmax(150px,1fr))] gap-2.5" id="itemGrid"></div>
        <p class="text-muted mt-3 hidden text-sm" id="noItems"><?= e(__('pos.no_match', 'No menu item matches that search.')) ?></p>
    </div>

    <!-- ------------------------------------------------ cart side -->
    <div class="bg-white border border-line rounded-xl sticky top-[72px] flex flex-col max-h-[calc(100vh-84px)]">
        <div class="px-3.5 py-3 border-b border-line">
            <div class="flex gap-2 mb-2">
                <select id="orderType" class="select text-sm flex-1" <?= $editOrder ? 'disabled' : '' ?>>
                    <option value="dine_in"><?= e(__('pos.dine_in', 'Dine in')) ?></option>
                    <option value="takeaway" <?= ($editOrder['order_type'] ?? '') === 'takeaway' ? 'selected' : '' ?>><?= e(__('pos.takeaway', 'Takeaway')) ?></option>
                </select>
                <input type="text" id="tableLabel" class="input text-sm flex-1"
                       placeholder="<?= e(__('pos.table_name', 'Table / name')) ?>" maxlength="30"
                       value="<?= e($editOrder['table_label'] ?? '') ?>" <?= $editOrder ? 'disabled' : '' ?>>
            </div>
            <div class="flex justify-between items-center">
                <strong class="text-sm font-semibold"><?= $editOrder ? e(__('pos.new_items', 'New items')) : e(__('pos.current_order', 'Current order')) ?></strong>
                <button class="btn btn-outline-danger btn-sm" id="clearCart"><?= e(__('pos.clear', 'Clear')) ?></button>
            </div>
        </div>

        <div class="flex-1 overflow-y-auto min-h-[120px]">
            <?php if ($editOrder): ?>
                <div class="existing-head"><?= e(__('pos.already_on_order', 'Already on this order')) ?></div>
                <div id="existingLines"></div>
                <div class="existing-head"><?= e(__('pos.new_items_label', 'New items')) ?></div>
            <?php endif; ?>
            <div id="cartLines"></div>
        </div>

        <div class="border-t border-line px-3.5 py-3">
            <div class="flex justify-between text-sm mb-1"><span><?= e(__('lbl.subtotal', 'Subtotal')) ?></span><span id="sumSub">—</span></div>
            <div class="flex justify-between text-sm mb-1">
                <span><?= e(__('lbl.discount', 'Discount')) ?></span>
                <span><input type="number" id="discount" class="input text-right text-sm"
                             style="width:100px" min="0" step="0.01"
                             value="<?= e(number_format((float)($editOrder['discount'] ?? 0), 2, '.', '')) ?>"></span>
            </div>
            <?php if ($taxPercent > 0): ?>
                <div class="flex justify-between text-sm mb-1"><span><?= e(__('lbl.before_vat', 'Before VAT')) ?></span><span id="sumNet">—</span></div>
                <div class="flex justify-between text-sm mb-1"><span>VAT <?= e(vat_label($taxPercent)) ?></span><span id="sumTax">—</span></div>
            <?php endif; ?>
            <div class="flex justify-between text-xl font-bold text-brand-dark my-2">
                <span><?= e(__('lbl.total', 'Total')) ?></span><span id="sumTotal">—</span>
            </div>

            <?php if ($canCharge): ?>
                <div class="grid grid-cols-2 gap-2 mb-2">
                    <select id="payMethod" class="select text-sm">
                        <option value="cash"><?= e(__('pos.pay_method', 'Cash')) ?></option>
                        <option value="mobile"><?= e(__('pos.mobile_money', 'Mobile money')) ?></option>
                        <option value="card"><?= e(__('pos.card', 'Card')) ?></option>
                    </select>
                    <input type="number" id="paidAmount" class="input text-right text-sm"
                           placeholder="<?= e(__('pos.amount_paid', 'Amount paid')) ?>" min="0" step="0.01">
                </div>
                <div class="flex justify-between text-sm mb-2">
                    <span><?= e(__('lbl.change', 'Change')) ?></span><strong id="changeDue">—</strong>
                </div>
            <?php endif; ?>

            <div class="flex flex-col gap-2 mt-2">
                <?php if ($canCharge): ?>
                    <button class="btn btn-ok btn-lg" id="btnCharge"
                            <?= $shift ? '' : 'disabled' ?>><?= $editOrder ? e(__('pos.add_charge', 'Add & Charge')) : e(__('pos.charge_print', 'Charge & Print')) ?></button>
                <?php endif; ?>
                <button class="btn btn-outline" id="btnHold">
                    <?= $editOrder ? e(__('pos.add_order_kitchen', 'Add to order (send to kitchen)')) : e(__('pos.send_kitchen', 'Send to Kitchen (unpaid)')) ?>
                </button>
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
    lineUrl:    <?= json_encode(url('public/api/order_line_update.php')) ?>,
    receiptUrl: <?= json_encode(url('public/receipt.php')) ?>,
    ordersUrl:  <?= json_encode(url('public/orders.php')) ?>,
    editOrder:  <?= json_encode($editOrder, JSON_UNESCAPED_UNICODE) ?>,
    i18n:       <?= json_encode([
        'st_pending'     => __('ks.pending'),
        'st_preparing'   => __('ks.preparing'),
        'st_served'      => __('ks.served'),
        'round'          => __('posjs.round'),
        'reduce_one'     => __('posjs.reduce_one'),
        'remove'         => __('posjs.remove'),
        'locked_help'    => __('posjs.locked_help'),
        'locked'         => __('posjs.locked'),
        'confirm_remove' => __('posjs.confirm_remove'),
        'confirm_reduce' => __('posjs.confirm_reduce'),
        'change_failed'  => __('posjs.change_failed'),
        'removed'        => __('posjs.removed'),
        'reduced'        => __('posjs.reduced'),
        'no_server'      => __('posjs.no_server'),
        'tap_add'        => __('posjs.tap_add'),
        'tap_start'      => __('posjs.tap_start'),
        'each'           => __('posjs.each'),
        'tap_first'      => __('posjs.tap_first'),
        'empty'          => __('posjs.empty'),
        'open_shift'     => __('msg.open_shift'),
        'underpaid'      => __('msg.underpaid'),
        'save_failed'    => __('posjs.save_failed'),
        'paid_saved'     => __('posjs.paid_saved'),
        'sent_kitchen'   => __('posjs.sent_kitchen'),
        'confirm_clear'  => __('posjs.confirm_clear'),
    ], JSON_UNESCAPED_UNICODE) ?>
};
</script>
<?php
$pageScripts = '<script src="' . url('assets/js/pos.js') . '"></script>';
require __DIR__ . '/../core/footer.php';
