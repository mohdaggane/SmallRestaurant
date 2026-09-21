<?php
/**
 * Billing: the restaurant's plan, how long it has left, what the plan allows,
 * and every payment the platform owner has recorded. Read-only here — plans
 * are changed and payments recorded from the platform panel (platform/).
 * This is the one page an admin can still open once the trial or
 * subscription has lapsed (see require_company_access()).
 */

require_once __DIR__ . '/../core/config.php';
require_role('admin');

$c        = current_company();
$term     = company_term($c);
$access   = company_access($c);
$users    = (int)db_value('SELECT COUNT(*) FROM users WHERE company_id = ? AND is_active = 1', [company_id()]);
$items    = (int)db_value('SELECT COUNT(*) FROM menu_items WHERE company_id = ?', [company_id()]);
$payments = db_all(
    'SELECT cp.*, p.name AS plan_name FROM company_payments cp JOIN plans p ON p.id = cp.plan_id
      WHERE cp.company_id = ? ORDER BY cp.created_at DESC',
    [company_id()]
);
$plans = db_all('SELECT * FROM plans WHERE is_active = 1 ORDER BY sort_order, price_month');

$statusLabel = match (true) {
    $access === 'suspended'     => ['Suspended', 'badge-danger'],
    $access !== 'ok'            => ['Expired', 'badge-danger'],
    $c['status'] === 'trial'    => ['Free trial', 'badge-warning'],
    default                     => ['Active', 'badge-success'],
};

$pageTitle = 'Billing';
require __DIR__ . '/../core/header.php';
?>

<?php if ($access !== 'ok'): ?>
    <div class="flash-danger"><?= e(company_block_message($access)) ?> Staff cannot sign in until it is renewed.</div>
<?php elseif ($term['days'] !== null && $term['days'] <= 5): ?>
    <div class="flash-warning">Your <?= $c['status'] === 'trial' ? 'free trial' : 'subscription' ?> ends in
        <?= $term['days'] ?> day(s), on <?= e(dt($term['until'], 'd M Y')) ?>.</div>
<?php endif; ?>

<div class="grid grid-cols-2 lg:grid-cols-4 gap-3 mb-5">
    <div class="stat-card accent">
        <div class="label">Plan</div>
        <div class="value"><?= e($c['plan_name']) ?></div>
        <small class="text-muted text-xs"><?= money($c['price_month']) ?> / month</small>
    </div>
    <div class="stat-card <?= $access === 'ok' ? 'good' : 'bad' ?>">
        <div class="label">Status</div>
        <div class="value"><span class="badge <?= $statusLabel[1] ?>"><?= $statusLabel[0] ?></span></div>
        <small class="text-muted text-xs">
            <?= $term['until'] === null ? 'no expiry date' : 'until ' . e(dt($term['until'], 'd M Y')) ?>
        </small>
    </div>
    <div class="stat-card">
        <div class="label">Active users</div>
        <div class="value"><?= $users ?> <span class="text-base text-muted">/ <?= $c['max_users'] === null ? '∞' : (int)$c['max_users'] ?></span></div>
        <small class="text-muted text-xs">allowed by your plan</small>
    </div>
    <div class="stat-card">
        <div class="label">Menu items</div>
        <div class="value"><?= $items ?> <span class="text-base text-muted">/ <?= $c['max_menu_items'] === null ? '∞' : (int)$c['max_menu_items'] ?></span></div>
        <small class="text-muted text-xs">allowed by your plan</small>
    </div>
</div>

<div class="grid grid-cols-1 lg:grid-cols-[1fr_320px] gap-4">
    <div class="card">
        <div class="card-header">Payments</div>
        <table class="tbl">
            <thead><tr>
                <th>Date</th><th>Plan</th><th>Period</th><th>Method</th><th>Reference</th><th class="text-right">Amount</th>
            </tr></thead>
            <tbody>
            <?php foreach ($payments as $p): ?>
                <tr>
                    <td class="text-nowrap"><?= dt($p['created_at'], 'd M Y') ?></td>
                    <td><?= e($p['plan_name']) ?></td>
                    <td class="text-nowrap"><?= dt($p['period_from'], 'd M Y') ?> – <?= dt($p['period_to'], 'd M Y') ?></td>
                    <td><?= e(ucfirst($p['method'])) ?></td>
                    <td class="text-muted"><?= e($p['reference'] ?? '') ?></td>
                    <td class="text-right"><?= money($p['amount']) ?></td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$payments): ?>
                <tr><td colspan="6" class="text-center text-muted py-8">No payments recorded yet.</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>

    <div class="flex flex-col gap-3">
        <div class="card">
            <div class="card-header">How to pay</div>
            <div class="card-body text-sm"><?= e(PLATFORM_PAY_INFO) ?></div>
        </div>
        <div class="card">
            <div class="card-header">Plans</div>
            <ul class="divide-y divide-line">
                <?php foreach ($plans as $p): ?>
                    <li class="px-4 py-3 text-sm <?= (int)$p['id'] === (int)$c['plan_id'] ? 'bg-brand-light' : '' ?>">
                        <div class="flex justify-between font-semibold">
                            <span><?= e($p['name']) ?></span><span><?= money($p['price_month']) ?>/mo</span>
                        </div>
                        <div class="text-muted text-xs">
                            <?= $p['max_users'] === null ? 'Unlimited' : (int)$p['max_users'] ?> users ·
                            <?= $p['max_menu_items'] === null ? 'unlimited' : (int)$p['max_menu_items'] ?> menu items
                        </div>
                    </li>
                <?php endforeach; ?>
            </ul>
        </div>
    </div>
</div>

<?php require __DIR__ . '/../core/footer.php'; ?>
