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
      WHERE o.status = 'open'
      ORDER BY o.created_at ASC"
);

// Load the lines for every listed order in one query.
$linesByOrder = [];
if ($orders) {
    $ids = array_column($orders, 'id');
    $in  = implode(',', array_fill(0, count($ids), '?'));
    foreach (db_all("SELECT * FROM order_items WHERE order_id IN ($in) ORDER BY id", $ids) as $li) {
        $linesByOrder[(int)$li['order_id']][] = $li;
    }
}

$pageTitle = 'Open Orders';
$layout    = 'app';
require __DIR__ . '/../core/header.php';
?>

<?php if ($canCharge && !$shift): ?>
    <div class="alert alert-warning d-flex justify-content-between align-items-center">
        <span>Your cash drawer is closed — open a shift to take payments.</span>
        <a class="btn btn-sm btn-dark" href="<?= url('admin/shifts.php') ?>">Open shift</a>
    </div>
<?php endif; ?>

<?php if (!$orders): ?>
    <div class="card"><div class="card-body text-center text-muted py-5">
        No unpaid orders right now.
        <div class="mt-3"><a class="btn btn-warning" href="<?= url('public/pos.php') ?>">Go to the POS terminal</a></div>
    </div></div>
<?php endif; ?>

<div class="row g-3">
<?php foreach ($orders as $o): $lines = $linesByOrder[(int)$o['id']] ?? []; ?>
    <div class="col-md-6 col-xl-4">
        <div class="card h-100">
            <div class="card-header d-flex justify-content-between align-items-center">
                <span>#<?= e($o['order_no']) ?></span>
                <span class="badge bg-<?= $o['order_type'] === 'takeaway' ? 'info' : 'secondary' ?>">
                    <?= $o['order_type'] === 'takeaway' ? 'Takeaway' : 'Dine in' ?><?= $o['table_label'] ? ' · ' . e($o['table_label']) : '' ?>
                </span>
            </div>
            <div class="card-body py-2">
                <p class="text-muted mb-2" style="font-size:12px;">
                    <?= dt($o['created_at'], 'g:i A') ?> · taken by <?= e($o['taken_by']) ?>
                </p>
                <table class="table table-sm mb-2">
                    <?php foreach ($lines as $li): ?>
                        <tr>
                            <td><?= (int)$li['qty'] ?>&times; <?= e($li['item_name']) ?>
                                <?php if ($li['kitchen_status'] !== 'served'): ?>
                                    <span class="badge bg-light text-dark"><?= e($li['kitchen_status']) ?></span>
                                <?php endif; ?>
                            </td>
                            <td class="text-end"><?= money($li['line_total']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                </table>
                <div class="d-flex justify-content-between fw-bold">
                    <span>Total</span><span><?= money($o['total']) ?></span>
                </div>
            </div>
            <div class="card-footer bg-white d-flex gap-2">
                <?php if ($canCharge): ?>
                    <button class="btn btn-sm text-white flex-fill" style="background:var(--ok)"
                            data-bs-toggle="modal" data-bs-target="#payModal"
                            data-order="<?= (int)$o['id'] ?>"
                            data-no="<?= e($o['order_no']) ?>"
                            data-total="<?= e($o['total']) ?>"
                            <?= $shift ? '' : 'disabled' ?>>Take payment</button>
                <?php endif; ?>
                <a class="btn btn-sm btn-outline-secondary" target="_blank"
                   href="<?= url('public/receipt.php?id=' . (int)$o['id']) ?>">Bill</a>
                <?php if (has_role('admin')): ?>
                    <form method="post" action="<?= url('public/order_void.php') ?>"
                          onsubmit="return confirm('Void this order? It cannot be undone.');">
                        <?= csrf_field() ?>
                        <input type="hidden" name="order_id" value="<?= (int)$o['id'] ?>">
                        <button class="btn btn-sm btn-outline-danger">Void</button>
                    </form>
                <?php endif; ?>
            </div>
        </div>
    </div>
<?php endforeach; ?>
</div>

<?php if ($canCharge): ?>
<!-- ------------------------------------------------ payment modal -->
<div class="modal fade" id="payModal" tabindex="-1">
  <div class="modal-dialog modal-dialog-centered">
    <form class="modal-content" method="post" action="<?= url('public/order_pay.php') ?>">
      <?= csrf_field() ?>
      <input type="hidden" name="order_id" id="payOrderId">
      <div class="modal-header">
        <h5 class="modal-title">Take payment · <span id="payOrderNo"></span></h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <div class="d-flex justify-content-between mb-3" style="font-size:20px;">
            <strong>Total due</strong><strong id="payTotalText"></strong>
        </div>
        <div class="mb-3">
            <label class="form-label">Payment method</label>
            <select name="payment_method" class="form-select">
                <option value="cash">Cash</option>
                <option value="mobile">Mobile money</option>
                <option value="card">Card</option>
            </select>
        </div>
        <div class="mb-2">
            <label class="form-label">Amount received</label>
            <input type="number" name="paid_amount" id="payPaid" class="form-control form-control-lg text-end"
                   step="0.01" min="0" required>
        </div>
        <div class="d-flex justify-content-between"><span>Change</span><strong id="payChange">—</strong></div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
        <button class="btn text-white" style="background:var(--ok)">Confirm payment</button>
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
    var modal = document.getElementById("payModal");
    if (!modal) { return; }
    var currency = ' . $currencyJs . ';
    var total = 0;

    function showChange() {
        var paid = Number(document.getElementById("payPaid").value) || 0;
        var diff = paid - total;
        var el = document.getElementById("payChange");
        el.textContent = currency + (diff > 0 ? diff : 0).toFixed(2);
        el.style.color = diff < -0.001 ? "var(--bad)" : "var(--ok)";
    }

    modal.addEventListener("show.bs.modal", function (ev) {
        var b = ev.relatedTarget;
        total = Number(b.dataset.total);
        document.getElementById("payOrderId").value = b.dataset.order;
        document.getElementById("payOrderNo").textContent = "#" + b.dataset.no;
        document.getElementById("payTotalText").textContent = currency + total.toFixed(2);
        document.getElementById("payPaid").value = total.toFixed(2);
        showChange();
    });

    document.getElementById("payPaid").addEventListener("input", showChange);
}());
</script>';

require __DIR__ . '/../core/footer.php';
