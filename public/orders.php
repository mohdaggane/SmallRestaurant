<?php
/**
 * Open (unpaid) orders — a waiter sends them here, a cashier settles them.
 */

require_once __DIR__ . '/../core/config.php';
require_role('cashier', 'waiter');

$canCharge = has_role('admin', 'cashier');
$shift     = $canCharge ? open_shift(user_id()) : null;

$orders = db_all(
    "SELECT o.*, u.full_name AS taken_by
       FROM orders o
       JOIN users u ON u.id = o.created_by
      WHERE o.company_id = ? AND o.status = 'open'
      ORDER BY o.created_at ASC",
    [company_id()]
);

// Load the lines for every listed order in one query.
$linesByOrder = [];
if ($orders) {
    $ids = array_column($orders, 'id');
    $in  = implode(',', array_fill(0, count($ids), '?'));
    foreach (db_all("SELECT * FROM order_items WHERE company_id = ? AND order_id IN ($in) ORDER BY round, id", [company_id(), ...$ids]) as $li) {
        $linesByOrder[(int)$li['order_id']][] = $li;
    }
}

$pageTitle = 'Open Orders';
$layout    = 'app';
require __DIR__ . '/../core/header.php';
?>

<?php if ($canCharge && !$shift): ?>
    <div class="flash-warning flex justify-between items-center">
        <span>Your cash drawer is closed — open a shift to take payments.</span>
        <a class="btn btn-dark btn-sm ml-3" href="<?= url('admin/shifts.php') ?>">Open shift</a>
    </div>
<?php endif; ?>

<?php if (!$orders): ?>
    <div class="card"><div class="card-body text-center text-muted py-12">
        No unpaid orders right now.
        <div class="mt-4"><a class="btn btn-accent" href="<?= url('public/pos.php') ?>">Go to the POS terminal</a></div>
    </div></div>
<?php endif; ?>

<div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 gap-3">
<?php foreach ($orders as $o): $lines = $linesByOrder[(int)$o['id']] ?? []; ?>
    <div id="order-<?= (int)$o['id'] ?>">
        <div class="card h-full flex flex-col">
            <div class="card-header flex justify-between items-center">
                <span class="font-bold">#<?= e($o['order_no']) ?></span>
                <span class="badge <?= $o['order_type'] === 'takeaway' ? 'badge-info' : 'badge-secondary' ?>">
                    <?= $o['order_type'] === 'takeaway' ? 'Takeaway' : 'Dine in' ?><?= $o['table_label'] ? ' · ' . e($o['table_label']) : '' ?>
                </span>
            </div>
            <div class="card-body flex-1 py-2">
                <p class="text-muted text-xs mb-2">
                    <?= dt($o['created_at'], 'g:i A') ?> · taken by <?= e($o['taken_by']) ?>
                    <?php if ($o['updated_at']): ?> · last added <?= dt($o['updated_at'], 'g:i A') ?><?php endif; ?>
                </p>
                <table class="tbl mb-2">
                    <?php foreach ($lines as $li): ?>
                        <tr>
                            <td><?= (int)$li['qty'] ?>&times; <?= e($li['item_name']) ?>
                                <?php if ((int)$li['round'] > 1): ?>
                                    <span class="badge badge-accent">Round <?= (int)$li['round'] ?></span>
                                <?php endif; ?>
                                <?php if ($li['kitchen_status'] !== 'served'): ?>
                                    <span class="badge badge-secondary"><?= e($li['kitchen_status']) ?></span>
                                <?php endif; ?>
                            </td>
                            <td class="text-right"><?= money($li['line_total']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                </table>
                <?php if ((float)$o['tax'] > 0): ?>
                    <div class="flex justify-between text-xs text-muted">
                        <span>Before VAT <?= money((float)$o['subtotal'] - (float)$o['discount']) ?></span>
                        <span>VAT <?= e(vat_label($o['vat_rate'])) ?> <?= money($o['tax']) ?></span>
                    </div>
                <?php endif; ?>
                <div class="flex justify-between font-bold mt-1">
                    <span>Total<?= (float)$o['tax'] > 0 ? ' incl. VAT' : '' ?></span><span><?= money($o['total']) ?></span>
                </div>
            </div>
            <div class="card-footer flex gap-2 flex-wrap">
                <?php if ($canCharge): ?>
                    <button class="btn btn-ok btn-sm flex-1 pay-btn"
                            data-order="<?= (int)$o['id'] ?>"
                            data-no="<?= e($o['order_no']) ?>"
                            data-total="<?= e($o['total']) ?>"
                            <?= $shift ? '' : 'disabled' ?>>Take payment</button>
                <?php endif; ?>
                <a class="btn btn-accent btn-sm"
                   href="<?= url('public/pos.php?order=' . (int)$o['id']) ?>">Add items</a>
                <a class="btn btn-outline btn-sm" target="_blank"
                   href="<?= url('public/receipt.php?id=' . (int)$o['id']) ?>">Bill</a>
                <?php if (has_role('admin')): ?>
                    <form method="post" action="<?= url('public/order_void.php') ?>"
                          onsubmit="return confirm('Void this order? It cannot be undone.');">
                        <?= csrf_field() ?>
                        <input type="hidden" name="order_id" value="<?= (int)$o['id'] ?>">
                        <button class="btn btn-outline-danger btn-sm">Void</button>
                    </form>
                <?php endif; ?>
            </div>
        </div>
    </div>
<?php endforeach; ?>
</div>

<?php if ($canCharge): ?>
<!-- ------------------------------------------------ payment modal (vanilla JS) -->
<div class="modal-backdrop hidden" id="payModal">
  <div class="modal-box">
    <form method="post" action="<?= url('public/order_pay.php') ?>">
      <?= csrf_field() ?>
      <input type="hidden" name="order_id" id="payOrderId">
      <div class="modal-header">
        <h5 class="font-semibold">Take payment · <span id="payOrderNo"></span></h5>
        <button type="button" class="text-muted hover:text-ink text-xl leading-none" id="payModalClose">&times;</button>
      </div>
      <div class="modal-body space-y-3">
        <div class="flex justify-between text-xl">
            <strong>Total due</strong><strong id="payTotalText"></strong>
        </div>
        <div>
            <label class="label">Payment method</label>
            <select name="payment_method" class="select">
                <option value="cash">Cash</option>
                <option value="mobile">Mobile money</option>
                <option value="card">Card</option>
            </select>
        </div>
        <div>
            <label class="label">Amount received</label>
            <input type="number" name="paid_amount" id="payPaid" class="input input-lg text-right"
                   step="0.01" min="0" required>
        </div>
        <div class="flex justify-between"><span>Change</span><strong id="payChange">—</strong></div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-outline" id="payModalCancelBtn">Cancel</button>
        <button class="btn btn-ok">Confirm payment</button>
      </div>
    </form>
  </div>
</div>
<?php endif; ?>

<?php
$currencyJs  = json_encode(setting('currency', '$'));
$pageScripts = '
<script>
(function () {
    var modal    = document.getElementById("payModal");
    var closeBtn = document.getElementById("payModalClose");
    var cancelBtn= document.getElementById("payModalCancelBtn");
    if (!modal) { return; }
    var currency = ' . $currencyJs . ';
    var total = 0;

    function openModal() { modal.classList.remove("hidden"); }
    function closeModal(){ modal.classList.add("hidden"); }

    function showChange() {
        var paid = Number(document.getElementById("payPaid").value) || 0;
        var diff = paid - total;
        var el = document.getElementById("payChange");
        el.textContent = currency + (diff > 0 ? diff : 0).toFixed(2);
        el.style.color = diff < -0.001 ? "#b3261e" : "#1f7a4d";
    }

    document.querySelectorAll(".pay-btn").forEach(function(b) {
        b.addEventListener("click", function() {
            total = Number(b.dataset.total);
            document.getElementById("payOrderId").value = b.dataset.order;
            document.getElementById("payOrderNo").textContent = "#" + b.dataset.no;
            document.getElementById("payTotalText").textContent = currency + total.toFixed(2);
            document.getElementById("payPaid").value = total.toFixed(2);
            showChange();
            openModal();
        });
    });

    if (closeBtn)  closeBtn.addEventListener("click",  closeModal);
    if (cancelBtn) cancelBtn.addEventListener("click", closeModal);
    modal.addEventListener("click", function(e) { if (e.target === modal) closeModal(); });
    document.getElementById("payPaid").addEventListener("input", showChange);
}());
</script>';

require __DIR__ . '/../core/footer.php';
