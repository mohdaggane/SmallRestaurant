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
            w.waiting_since,
            TIMESTAMPDIFF(MINUTE, w.waiting_since, ?) AS age_min
       FROM orders o
       JOIN users u ON u.id = o.created_by
       JOIN (SELECT order_id, MIN(created_at) AS waiting_since
               FROM order_items
              WHERE company_id = ? AND needs_prep = 1 AND kitchen_status <> 'served'
              GROUP BY order_id) w ON w.order_id = o.id
      WHERE o.company_id = ? AND o.status <> 'void'
      ORDER BY w.waiting_since ASC",
    [date('Y-m-d H:i:s'), company_id(), company_id()]
);

$linesByOrder = [];
if ($orders) {
    $ids = array_column($orders, 'id');
    $in  = implode(',', array_fill(0, count($ids), '?'));
    foreach (db_all("SELECT * FROM order_items WHERE company_id = ? AND order_id IN ($in) AND needs_prep = 1 ORDER BY round, id", [company_id(), ...$ids]) as $li) {
        $linesByOrder[(int)$li['order_id']][] = $li;
    }
}

$pageTitle = 'Kitchen';
$layout    = 'wide';
require __DIR__ . '/../core/header.php';
?>

<div class="flex justify-between items-center mb-4 px-1">
    <h1 class="text-lg font-semibold m-0">
        Kitchen queue · <?= count($orders) ?> order<?= count($orders) === 1 ? '' : 's' ?>
    </h1>
    <div class="flex items-center gap-3">
        <small class="text-muted">Refreshes in <span id="tick">15</span>s</small>
        <button class="btn btn-outline btn-sm" onclick="location.reload()">Refresh now</button>
    </div>
</div>

<?php if (!$orders): ?>
    <div class="card"><div class="card-body text-center text-muted py-12">
        Nothing waiting. All orders are served. 🎉
    </div></div>
<?php endif; ?>

<div class="grid grid-cols-[repeat(auto-fill,minmax(280px,1fr))] gap-3">
<?php foreach ($orders as $o): ?>
    <?php $stale = (int)$o['age_min'] >= 10; ?>
    <div class="kds-card <?= $stale ? 'stale' : '' ?>">
        <div class="kds-head">
            <div>
                <strong>#<?= e($o['order_no']) ?></strong><br>
                <small class="text-muted text-xs">
                    <?= $o['order_type'] === 'takeaway' ? 'Takeaway' : 'Dine in' ?><?= $o['table_label'] ? ' · ' . e($o['table_label']) : '' ?>
                </small>
            </div>
            <div class="text-right">
                <span class="badge <?= $stale ? 'badge-danger' : 'badge-secondary' ?>">
                    <?= (int)$o['age_min'] ?> min
                </span><br>
                <small class="text-muted text-xs"><?= e($o['taken_by']) ?></small>
            </div>
        </div>

        <ul class="list-none m-0 px-3 py-2 space-y-0">
            <?php $shownRound = 0; ?>
            <?php foreach ($linesByOrder[(int)$o['id']] ?? [] as $li): ?>
                <?php if ((int)$li['round'] > 1 && (int)$li['round'] !== $shownRound): $shownRound = (int)$li['round']; ?>
                    <li class="py-2 border-t border-dashed border-line mt-1">
                        <span class="badge badge-accent">Round <?= $shownRound ?> · added <?= dt($li['created_at'], 'g:i A') ?></span>
                    </li>
                <?php endif; ?>
                <li class="flex justify-between items-center gap-2 py-1.5 <?= $li['kitchen_status'] === 'served' ? 'opacity-40 line-through' : '' ?>">
                    <span>
                        <strong><?= (int)$li['qty'] ?>&times;</strong> <?= e($li['item_name']) ?>
                        <?php if ($li['note']): ?><br><small class="text-bad text-xs"><?= e($li['note']) ?></small><?php endif; ?>
                    </span>
                    <?php if ($li['kitchen_status'] !== 'served'): ?>
                        <form method="post" action="<?= url('public/kitchen_update.php') ?>" class="flex gap-1 shrink-0">
                            <?= csrf_field() ?>
                            <input type="hidden" name="item_id" value="<?= (int)$li['id'] ?>">
                            <?php if ($li['kitchen_status'] === 'pending'): ?>
                                <button name="to" value="preparing" class="btn btn-outline-warning btn-sm">Start</button>
                            <?php endif; ?>
                            <button name="to" value="served" class="btn btn-ok btn-sm">Done</button>
                        </form>
                    <?php endif; ?>
                </li>
            <?php endforeach; ?>
        </ul>

        <div class="card-footer text-right">
            <form method="post" action="<?= url('public/kitchen_update.php') ?>">
                <?= csrf_field() ?>
                <input type="hidden" name="order_id" value="<?= (int)$o['id'] ?>">
                <button name="to" value="served" class="btn btn-outline btn-sm">Mark whole order served</button>
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
