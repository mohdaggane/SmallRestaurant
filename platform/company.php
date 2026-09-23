<?php
/**
 * One restaurant, as the platform owner sees it: record a payment (which
 * extends its paid-until date), change plan / status / dates by hand, reset an
 * admin's password, and review its users and payment history.
 */

require_once __DIR__ . '/../core/config.php';
require_platform();

$id      = (int)(get('id') ?: post('id'));
$company = db_one('SELECT * FROM companies WHERE id = ?', [$id]);
if (!$company) {
    flash(__('pc.err_gone'), 'danger');
    redirect('platform/index.php');
}
$back  = 'platform/company.php?id=' . $id;
$plans = db_all('SELECT * FROM plans ORDER BY sort_order, price_month');
$planById = array_column($plans, null, 'id');

if (is_post()) {
    csrf_check();
    $action = post('action');

    if ($action === 'payment') {
        $planId = (int)post('plan_id');
        $months = max(1, min(36, (int)post('months')));
        $amount = post_amount('amount');
        $method = in_array(post('method'), ['mobile', 'cash', 'bank', 'card'], true) ? post('method') : 'mobile';
        $ref    = mb_substr(post('reference'), 0, 100);

        if (!isset($planById[$planId])) {
            flash(__('pc.err_plan'), 'danger');
            redirect($back);
        }
        // A renewal paid before the old period ends starts the day after it,
        // so the restaurant never loses days by paying early.
        $today = date('Y-m-d');
        $from  = ($company['status'] === 'active' && $company['paid_until'] !== null && $company['paid_until'] >= $today)
            ? date('Y-m-d', strtotime($company['paid_until'] . ' +1 day'))
            : $today;
        $to = date('Y-m-d', strtotime("$from +$months months -1 day"));

        $conn->begin_transaction();
        try {
            db_exec(
                'INSERT INTO company_payments (company_id, plan_id, amount, months, period_from, period_to, method, reference, recorded_by)
                 VALUES (?,?,?,?,?,?,?,?,?)',
                [$id, $planId, $amount, $months, $from, $to, $method, $ref !== '' ? $ref : null, platform_user()['id']]
            );
            db_exec("UPDATE companies SET plan_id = ?, status = 'active', paid_until = ? WHERE id = ?", [$planId, $to, $id]);
            $conn->commit();
        } catch (Throwable $e) {
            $conn->rollback();
            flash(__('pc.err_payment', '', ['error' => $e->getMessage()]), 'danger');
            redirect($back);
        }
        flash(__('pc.paid_ok', '', ['name' => $company['name'], 'date' => dt($to, 'd M Y')]));
        redirect($back);
    }

    if ($action === 'update') {
        $planId = (int)post('plan_id');
        $status = in_array(post('status'), ['trial', 'active', 'suspended'], true) ? post('status') : $company['status'];
        $trial  = valid_date(post('trial_ends_at')) ? post('trial_ends_at') : null;
        $paid   = valid_date(post('paid_until')) ? post('paid_until') : null;
        $name   = post('name');

        if ($name === '' || !isset($planById[$planId])) {
            flash(__('pc.err_name_plan'), 'danger');
        } else {
            db_exec(
                'UPDATE companies SET name = ?, phone = ?, email = ?, plan_id = ?, status = ?, trial_ends_at = ?, paid_until = ? WHERE id = ?',
                [$name, post('phone') ?: null, post('email') ?: null, $planId, $status, $trial, $paid, $id]
            );
            flash(__('pc.updated'));
        }
        redirect($back);
    }

    if ($action === 'password') {
        $userId   = (int)post('user_id');
        $password = (string)($_POST['password'] ?? '');
        $target   = db_one('SELECT id, full_name FROM users WHERE id = ? AND company_id = ?', [$userId, $id]);
        if (!$target) {
            flash(__('pc.err_user'), 'danger');
        } elseif (strlen($password) < 6) {
            flash(__('pc.err_pw'), 'danger');
        } else {
            db_exec('UPDATE users SET password_hash = ?, is_active = 1 WHERE id = ? AND company_id = ?',
                [password_hash($password, PASSWORD_DEFAULT), $userId, $id]);
            flash(__('pc.pw_reset_ok', '', ['name' => $target['full_name']]));
        }
        redirect($back);
    }
}

$company  = db_one('SELECT c.*, p.name AS plan_name FROM companies c JOIN plans p ON p.id = c.plan_id WHERE c.id = ?', [$id]);
$access   = company_access($company);
$term     = company_term($company);
$users    = db_all('SELECT id, full_name, username, role, is_active, created_at FROM users WHERE company_id = ? ORDER BY role, full_name', [$id]);
$payments = db_all(
    'SELECT cp.*, p.name AS plan_name, a.full_name AS recorded_by_name
       FROM company_payments cp JOIN plans p ON p.id = cp.plan_id
  LEFT JOIN platform_admins a ON a.id = cp.recorded_by
      WHERE cp.company_id = ? ORDER BY cp.created_at DESC',
    [$id]
);
$activity = db_one(
    "SELECT COUNT(*) AS orders, COALESCE(SUM(CASE WHEN status = 'paid' THEN total END), 0) AS sales,
            MAX(created_at) AS last_order
       FROM orders WHERE company_id = ?",
    [$id]
);
$menuCount = (int)db_value('SELECT COUNT(*) FROM menu_items WHERE company_id = ?', [$id]);

$pageTitle = $company['name'];
$platform  = true;
$pageActions = '<a class="btn btn-outline btn-sm" href="' . url('platform/index.php') . '">' . e(__('pc.all_restaurants')) . '</a>';
require __DIR__ . '/../core/header.php';
?>

<div class="grid grid-cols-2 lg:grid-cols-4 gap-3 mb-5">
    <div class="stat-card <?= $access === 'ok' ? 'good' : 'bad' ?>">
        <div class="label"><?= e(__('pc.access')) ?></div>
        <div class="value text-lg">
            <?= e(match ($access) { 'ok' => $company['status'] === 'trial' ? __('pi.on_trial') : __('st.active'),
                                  'suspended' => __('st.suspended'), default => __('st.expired') }) ?>
        </div>
        <small class="text-muted text-xs">
            <?php if ($term['until'] === null): ?><?= e(__('pc.no_expiry_date')) ?>
            <?php elseif ($term['days'] >= 0): ?><?= e(__('pc.days_left', '', ['days' => $term['days'], 'date' => dt($term['until'], 'd M Y')])) ?>
            <?php else: ?><?= e(__('pc.lapsed', '', ['days' => -$term['days'], 'date' => dt($term['until'], 'd M Y')])) ?>
            <?php endif; ?>
        </small>
    </div>
    <div class="stat-card accent">
        <div class="label"><?= e(__('pf.plan')) ?></div>
        <div class="value text-lg"><?= e($company['plan_name']) ?></div>
        <small class="text-muted text-xs"><?= e(__('pc.users_items', '', ['users' => count(array_filter($users, fn($u) => (int)$u['is_active'])), 'items' => $menuCount])) ?></small>
    </div>
    <div class="stat-card">
        <div class="label"><?= e(__('lbl.orders')) ?></div>
        <div class="value text-lg"><?= (int)$activity['orders'] ?></div>
        <small class="text-muted text-xs"><?= e(__('pc.last', '', ['date' => $activity['last_order'] ? dt($activity['last_order'], 'd M Y') : __('pc.never')])) ?></small>
    </div>
    <div class="stat-card">
        <div class="label"><?= e(__('pi.registered')) ?></div>
        <div class="value text-lg"><?= dt($company['created_at'], 'd M Y') ?></div>
        <small class="text-muted text-xs"><?= e(__('pc.short_name', '', ['slug' => $company['slug']])) ?></small>
    </div>
</div>

<div class="grid grid-cols-1 lg:grid-cols-2 gap-4 mb-4">
    <div class="card">
        <div class="card-header"><?= e(__('pc.record_payment_h')) ?></div>
        <div class="card-body">
            <form method="post" class="space-y-3" id="payForm">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="payment">
                <input type="hidden" name="id" value="<?= $id ?>">
                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <label class="label" for="pay_plan"><?= e(__('pf.plan')) ?></label>
                        <select id="pay_plan" name="plan_id" class="input">
                            <?php foreach ($plans as $p): ?>
                                <?php if (!(int)$p['is_active'] && (int)$p['id'] !== (int)$company['plan_id']) continue; ?>
                                <option value="<?= (int)$p['id'] ?>" data-price="<?= e($p['price_month']) ?>"
                                    <?= (int)$p['id'] === (int)$company['plan_id'] ? 'selected' : '' ?>>
                                    <?= e($p['name']) ?> — <?= e(number_format((float)$p['price_month'], 2)) ?><?= e(__('pc.per_month_short')) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <label class="label" for="pay_months"><?= e(__('pc.months')) ?></label>
                        <input type="number" id="pay_months" name="months" class="input" min="1" max="36" value="1">
                    </div>
                    <div>
                        <label class="label" for="pay_amount"><?= e(__('orders.amount_received')) ?></label>
                        <input type="number" id="pay_amount" name="amount" class="input" step="0.01" min="0">
                    </div>
                    <div>
                        <label class="label" for="pay_method"><?= e(__('pc.method')) ?></label>
                        <select id="pay_method" name="method" class="input">
                            <option value="mobile"><?= e(__('pay.mobile')) ?></option>
                            <option value="cash"><?= e(__('pay.cash')) ?></option>
                            <option value="bank"><?= e(__('pay.bank')) ?></option>
                            <option value="card"><?= e(__('pay.card')) ?></option>
                        </select>
                    </div>
                    <div class="col-span-2">
                        <label class="label" for="pay_ref"><?= e(__('pc.reference')) ?></label>
                        <input type="text" id="pay_ref" name="reference" class="input" maxlength="100" placeholder="<?= e(__('pc.txn_ph')) ?>">
                    </div>
                </div>
                <p class="text-muted text-xs m-0">
                    <?= e(__('pc.payment_help')) ?>
                </p>
                <button class="btn btn-brand"><?= e(__('pc.record_payment')) ?></button>
            </form>
        </div>
    </div>

    <div class="card">
        <div class="card-header"><?= e(__('pc.details')) ?></div>
        <div class="card-body">
            <form method="post" class="space-y-3">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="update">
                <input type="hidden" name="id" value="<?= $id ?>">
                <div class="grid grid-cols-2 gap-3">
                    <div class="col-span-2">
                        <label class="label" for="c_name"><?= e(__('lbl.name')) ?></label>
                        <input type="text" id="c_name" name="name" class="input" maxlength="100" value="<?= e($company['name']) ?>" required>
                    </div>
                    <div>
                        <label class="label" for="c_phone"><?= e(__('lbl.phone')) ?></label>
                        <input type="text" id="c_phone" name="phone" class="input" maxlength="40" value="<?= e($company['phone'] ?? '') ?>">
                    </div>
                    <div>
                        <label class="label" for="c_email"><?= e(__('lbl.email')) ?></label>
                        <input type="email" id="c_email" name="email" class="input" maxlength="120" value="<?= e($company['email'] ?? '') ?>">
                    </div>
                    <div>
                        <label class="label" for="c_plan"><?= e(__('pf.plan')) ?></label>
                        <select id="c_plan" name="plan_id" class="input">
                            <?php foreach ($plans as $p): ?>
                                <option value="<?= (int)$p['id'] ?>" <?= (int)$p['id'] === (int)$company['plan_id'] ? 'selected' : '' ?>><?= e($p['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <label class="label" for="c_status"><?= e(__('lbl.status')) ?></label>
                        <select id="c_status" name="status" class="input">
                            <?php foreach (['trial' => __('st.trial'), 'active' => __('pc.active_paid'), 'suspended' => __('st.suspended')] as $k => $label): ?>
                                <option value="<?= $k ?>" <?= $company['status'] === $k ? 'selected' : '' ?>><?= e($label) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <label class="label" for="c_trial"><?= e(__('pc.trial_ends')) ?></label>
                        <input type="date" id="c_trial" name="trial_ends_at" class="input" value="<?= e($company['trial_ends_at'] ?? '') ?>">
                    </div>
                    <div>
                        <label class="label" for="c_paid"><?= e(__('pc.paid_until')) ?></label>
                        <input type="date" id="c_paid" name="paid_until" class="input" value="<?= e($company['paid_until'] ?? '') ?>">
                    </div>
                </div>
                <p class="text-muted text-xs m-0"><?= e(__('pc.paid_until_help')) ?></p>
                <button class="btn btn-outline"><?= e(__('pc.save_details')) ?></button>
            </form>
        </div>
    </div>
</div>

<div class="grid grid-cols-1 lg:grid-cols-2 gap-4">
    <div class="card">
        <div class="card-header"><?= e(__('nav.users')) ?></div>
        <table class="tbl">
            <thead><tr><th><?= e(__('lbl.name')) ?></th><th><?= e(__('auth.username')) ?></th><th><?= e(__('lbl.role')) ?></th><th class="text-center"><?= e(__('st.active')) ?></th></tr></thead>
            <tbody>
            <?php foreach ($users as $u): ?>
                <tr>
                    <td><?= e($u['full_name']) ?></td>
                    <td class="font-mono text-sm"><?= e($u['username']) ?></td>
                    <td><?= e(__('role.' . $u['role'], ucfirst($u['role']))) ?></td>
                    <td class="text-center"><?= (int)$u['is_active'] ? '✓' : '<span class="text-muted">—</span>' ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <div class="card-body border-t border-line">
            <form method="post" class="flex flex-wrap gap-2 items-end">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="password">
                <input type="hidden" name="id" value="<?= $id ?>">
                <div>
                    <label class="label" for="pw_user"><?= e(__('pc.reset_for')) ?></label>
                    <select id="pw_user" name="user_id" class="input">
                        <?php foreach ($users as $u): ?>
                            <option value="<?= (int)$u['id'] ?>"><?= e($u['full_name'] . ' (' . $u['username'] . ')') ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label class="label" for="pw_new"><?= e(__('pc.new_password')) ?></label>
                    <input type="text" id="pw_new" name="password" class="input" minlength="6" required autocomplete="off">
                </div>
                <button class="btn btn-outline"><?= e(__('pc.reset')) ?></button>
            </form>
        </div>
    </div>

    <div class="card">
        <div class="card-header"><?= e(__('pc.payments')) ?></div>
        <table class="tbl">
            <thead><tr><th><?= e(__('lbl.date')) ?></th><th><?= e(__('pf.plan')) ?></th><th><?= e(__('pc.period')) ?></th><th><?= e(__('pc.ref')) ?></th><th class="text-right"><?= e(__('lbl.amount')) ?></th></tr></thead>
            <tbody>
            <?php foreach ($payments as $p): ?>
                <tr>
                    <td class="text-nowrap"><?= dt($p['created_at'], 'd M Y') ?></td>
                    <td><?= e($p['plan_name']) ?> · <?= e(__('pc.months_short', '', ['n' => (int)$p['months']])) ?></td>
                    <td class="text-nowrap text-xs"><?= dt($p['period_from'], 'd M Y') ?> – <?= dt($p['period_to'], 'd M Y') ?></td>
                    <td class="text-muted text-xs"><?= e(__('pay.' . $p['method'], ucfirst($p['method']))) ?> <?= e($p['reference'] ?? '') ?></td>
                    <td class="text-right"><?= e(number_format((float)$p['amount'], 2)) ?></td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$payments): ?>
                <tr><td colspan="5" class="text-center text-muted py-8"><?= e(__('pc.no_payments')) ?></td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<script>
// Pre-fill the amount as plan price x months; the owner can still overwrite it.
(function () {
    var plan = document.getElementById('pay_plan'), months = document.getElementById('pay_months'),
        amount = document.getElementById('pay_amount'), edited = false;
    function fill() {
        if (edited) return;
        var price = parseFloat(plan.options[plan.selectedIndex].dataset.price || '0');
        amount.value = (price * (parseInt(months.value, 10) || 1)).toFixed(2);
    }
    amount.addEventListener('input', function () { edited = true; });
    plan.addEventListener('change', fill);
    months.addEventListener('input', fill);
    fill();
})();
</script>

<?php require __DIR__ . '/../core/footer.php'; ?>
