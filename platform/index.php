<?php
/**
 * Platform owner's home: every registered restaurant with its plan, status,
 * expiry, size and last activity. Works across all companies by design.
 */

require_once __DIR__ . '/../core/config.php';
require_platform();

$filter = in_array(get('status'), ['trial', 'active', 'suspended', 'expiring', 'expired'], true) ? get('status') : '';
$q      = get('q');
$today  = date('Y-m-d');
$soon   = date('Y-m-d', strtotime('+7 days'));

// The date the company's access runs to, whichever kind it is.
$untilSql = "CASE WHEN c.status = 'trial' THEN c.trial_ends_at ELSE c.paid_until END";

$where  = ['1 = 1'];
$params = [];
if ($filter === 'trial' || $filter === 'active' || $filter === 'suspended') {
    $where[]  = 'c.status = ?';
    $params[] = $filter;
} elseif ($filter === 'expiring') {
    $where[]  = "c.status <> 'suspended' AND $untilSql BETWEEN ? AND ?";
    array_push($params, $today, $soon);
} elseif ($filter === 'expired') {
    $where[]  = "c.status <> 'suspended' AND $untilSql < ?";
    $params[] = $today;
}
if ($q !== '') {
    $where[]  = '(c.name LIKE ? OR c.slug LIKE ? OR c.phone LIKE ?)';
    array_push($params, "%$q%", "%$q%", "%$q%");
}

$companies = db_all(
    "SELECT c.*, p.name AS plan_name, $untilSql AS until_date,
            (SELECT COUNT(*) FROM users u WHERE u.company_id = c.id AND u.is_active = 1) AS user_count,
            (SELECT COUNT(*) FROM orders o WHERE o.company_id = c.id) AS order_count,
            (SELECT MAX(o.created_at) FROM orders o WHERE o.company_id = c.id) AS last_order
       FROM companies c JOIN plans p ON p.id = c.plan_id
      WHERE " . implode(' AND ', $where) . '
      ORDER BY c.created_at DESC',
    $params
);

$stats = db_one(
    "SELECT COUNT(*) AS total,
            SUM(c.status = 'trial') AS trial,
            SUM(c.status = 'active') AS active,
            SUM(c.status = 'suspended') AS suspended,
            SUM(c.status <> 'suspended' AND $untilSql BETWEEN ? AND ?) AS expiring,
            SUM(c.status <> 'suspended' AND $untilSql < ?) AS expired
       FROM companies c",
    [$today, $soon, $today]
);
$revenueMonth = (float)db_value(
    'SELECT COALESCE(SUM(amount), 0) FROM company_payments WHERE created_at >= ?',
    [date('Y-m-01 00:00:00')]
);

$pageTitle = 'Restaurants';
$platform  = true;
require __DIR__ . '/../core/header.php';
?>

<div class="grid grid-cols-2 lg:grid-cols-5 gap-3 mb-5">
    <a class="stat-card accent no-underline" href="?">
        <div class="label">Restaurants</div>
        <div class="value"><?= (int)$stats['total'] ?></div>
        <small class="text-muted text-xs"><?= (int)$stats['active'] ?> paying · <?= (int)$stats['trial'] ?> on trial</small>
    </a>
    <a class="stat-card no-underline" href="?status=expiring">
        <div class="label">Expiring in 7 days</div>
        <div class="value"><?= (int)$stats['expiring'] ?></div>
        <small class="text-muted text-xs">follow up for payment</small>
    </a>
    <a class="stat-card bad no-underline" href="?status=expired">
        <div class="label">Expired</div>
        <div class="value"><?= (int)$stats['expired'] ?></div>
        <small class="text-muted text-xs">staff are locked out</small>
    </a>
    <a class="stat-card no-underline" href="?status=suspended">
        <div class="label">Suspended</div>
        <div class="value"><?= (int)$stats['suspended'] ?></div>
        <small class="text-muted text-xs">blocked by you</small>
    </a>
    <div class="stat-card good">
        <div class="label">Received this month</div>
        <div class="value"><?= e('$' . number_format($revenueMonth, 2)) ?></div>
        <small class="text-muted text-xs">recorded payments</small>
    </div>
</div>

<form class="flex flex-wrap gap-2 items-end mb-4" method="get">
    <div>
        <label class="label" for="q">Search</label>
        <input type="text" id="q" name="q" class="input" value="<?= e($q) ?>" placeholder="Name, short name or phone">
    </div>
    <div>
        <label class="label" for="status">Show</label>
        <select id="status" name="status" class="input">
            <?php foreach (['' => 'All', 'trial' => 'On trial', 'active' => 'Paying', 'expiring' => 'Expiring in 7 days',
                            'expired' => 'Expired', 'suspended' => 'Suspended'] as $k => $label): ?>
                <option value="<?= e($k) ?>" <?= $filter === $k ? 'selected' : '' ?>><?= e($label) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <button class="btn btn-brand">Filter</button>
</form>

<div class="card">
    <table class="tbl">
        <thead><tr>
            <th>Restaurant</th><th>Plan</th><th class="text-center">Status</th><th>Runs until</th>
            <th class="text-right">Users</th><th class="text-right">Orders</th><th>Last order</th><th>Registered</th>
        </tr></thead>
        <tbody>
        <?php foreach ($companies as $c): ?>
            <?php
            $access = company_access($c);
            [$label, $badge] = match (true) {
                $access === 'suspended' => ['Suspended', 'badge-danger'],
                $access !== 'ok'        => ['Expired', 'badge-danger'],
                $c['status'] === 'trial' => ['Trial', 'badge-warning'],
                default                  => ['Active', 'badge-success'],
            };
            ?>
            <tr>
                <td>
                    <a class="font-semibold text-brand-dark hover:underline" href="<?= url('platform/company.php?id=' . (int)$c['id']) ?>"><?= e($c['name']) ?></a>
                    <div class="text-muted text-xs"><?= e($c['slug']) ?><?= $c['phone'] ? ' · ' . e($c['phone']) : '' ?></div>
                </td>
                <td><?= e($c['plan_name']) ?></td>
                <td class="text-center"><span class="badge <?= $badge ?>"><?= $label ?></span></td>
                <td class="text-nowrap"><?= $c['until_date'] ? dt($c['until_date'], 'd M Y') : '<span class="text-muted">no expiry</span>' ?></td>
                <td class="text-right"><?= (int)$c['user_count'] ?></td>
                <td class="text-right"><?= (int)$c['order_count'] ?></td>
                <td class="text-nowrap text-muted"><?= $c['last_order'] ? dt($c['last_order'], 'd M Y') : '—' ?></td>
                <td class="text-nowrap text-muted"><?= dt($c['created_at'], 'd M Y') ?></td>
            </tr>
        <?php endforeach; ?>
        <?php if (!$companies): ?>
            <tr><td colspan="8" class="text-center text-muted py-8">No restaurants match.</td></tr>
        <?php endif; ?>
        </tbody>
    </table>
</div>

<?php require __DIR__ . '/../core/footer.php'; ?>
