<?php
/**
 * Kitchen display. Shows every order line still waiting to be prepared,
 * oldest first, and refreshes itself every 15 seconds.
 * Lines flagged needs_prep = 0 (bottled drinks) never reach this screen.
 */

require_once __DIR__ . '/../core/config.php';
require_role('kitchen');

// Orders that still have unserved kitchen lines. Voided orders drop out.
$orders = db_all(
    "SELECT o.id, o.order_no, o.order_type, o.table_label, o.status, o.created_at,
            u.full_name AS taken_by,
            TIMESTAMPDIFF(MINUTE, o.created_at, NOW()) AS age_min
       FROM orders o
       JOIN users u ON u.id = o.created_by
      WHERE o.status <> 'void'
        AND EXISTS (
              SELECT 1 FROM order_items oi
               WHERE oi.order_id = o.id
                 AND oi.needs_prep = 1
                 AND oi.kitchen_status <> 'served'
            )
      ORDER BY o.created_at ASC"
);

$linesByOrder = [];
if ($orders) {
    $ids = array_column($orders, 'id');
    $in  = implode(',', array_fill(0, count($ids), '?'));
    foreach (db_all("SELECT * FROM order_items WHERE order_id IN ($in) AND needs_prep = 1 ORDER BY id", $ids) as $li) {
        $linesByOrder[(int)$li['order_id']][] = $li;
    }
}

$pageTitle = 'Kitchen';
$layout    = 'wide';
require __DIR__ . '/../core/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-3">
    <h1 class="h5 mb-0">Kitchen queue · <?= count($orders) ?> order<?= count($orders) === 1 ? '' : 's' ?></h1>
    <div class="d-flex align-items-center gap-2">
        <small class="text-muted">Refreshes in <span id="tick">15</span>s</small>
        <button class="btn btn-sm btn-outline-secondary" onclick="location.reload()">Refresh now</button>
    </div>
</div>

<?php if (!$orders): ?>
    <div class="card"><div class="card-body text-center text-muted py-5">
        Nothing waiting. All orders are served.
    </div></div>
<?php endif; ?>

<div class="kds-grid">
<?php foreach ($orders as $o): ?>
    <div class="kds-card <?= (int)$o['age_min'] >= 10 ? 'stale' : '' ?>">
        <div class="kds-head">
            <div>
                <strong>#<?= e($o['order_no']) ?></strong><br>
                <small class="text-muted">
                    <?= $o['order_type'] === 'takeaway' ? 'Takeaway' : 'Dine in' ?><?= $o['table_label'] ? ' · ' . e($o['table_label']) : '' ?>
                </small>
            </div>
            <div class="text-end">
                <span class="badge bg-<?= (int)$o['age_min'] >= 10 ? 'danger' : 'secondary' ?>">
                    <?= (int)$o['age_min'] ?> min
                </span><br>
                <small class="text-muted"><?= e($o['taken_by']) ?></small>
            </div>
        </div>

        <ul>
            <?php foreach ($linesByOrder[(int)$o['id']] ?? [] as $li): ?>
                <li class="<?= $li['kitchen_status'] === 'served' ? 'done' : '' ?>">
                    <span>
                        <strong><?= (int)$li['qty'] ?>&times;</strong> <?= e($li['item_name']) ?>
                        <?php if ($li['note']): ?><br><small class="text-danger"><?= e($li['note']) ?></small><?php endif; ?>
                    </span>
                    <?php if ($li['kitchen_status'] !== 'served'): ?>
                        <form method="post" action="<?= url('public/kitchen_update.php') ?>" class="d-flex gap-1">
                            <?= csrf_field() ?>
                            <input type="hidden" name="item_id" value="<?= (int)$li['id'] ?>">
                            <?php if ($li['kitchen_status'] === 'pending'): ?>
                                <button name="to" value="preparing" class="btn btn-sm btn-outline-warning">Start</button>
                            <?php endif; ?>
                            <button name="to" value="served" class="btn btn-sm btn-success">Done</button>
                        </form>
                    <?php endif; ?>
                </li>
            <?php endforeach; ?>
        </ul>

        <div class="card-footer bg-white text-end">
            <form method="post" action="<?= url('public/kitchen_update.php') ?>">
                <?= csrf_field() ?>
                <input type="hidden" name="order_id" value="<?= (int)$o['id'] ?>">
                <button name="to" value="served" class="btn btn-sm btn-outline-success">Mark whole order served</button>
            </form>
        </div>
    </div>
<?php endforeach; ?>
</div>

<?php
$pageScripts = '
<script>
(function () {
    var left = 15;
    var el = document.getElementById("tick");
    setInterval(function () {
        left -= 1;
        if (left <= 0) { location.reload(); return; }
        el.textContent = left;
    }, 1000);
}());
</script>';
require __DIR__ . '/../core/footer.php';
