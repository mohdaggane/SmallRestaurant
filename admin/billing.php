<?php


require_once __DIR__ . '/../core/config.php';
require_role('admin');

// ── Handle payment notification submission ───────────────────────────────
if (is_post()) {
    csrf_check();
    if (post('action') === 'pay_request') {
        $planId = (int)post('plan_id');
        $months = max(1, min(36, (int)post('months')));
        $amount = post_amount('amount');
        $method = in_array(post('method'), ['mobile', 'cash', 'bank', 'card'], true) ? post('method') : 'mobile';
        $ref    = mb_substr(post('reference'), 0, 100);
        $note   = mb_substr(post('note'), 0, 255);
        $plans  = db_all('SELECT * FROM plans WHERE is_active = 1 ORDER BY sort_order, price_month');
        $planById = array_column($plans, null, 'id');

        if (!isset($planById[$planId])) {
            flash(__('bl.req_err_plan', 'Please select a valid plan.'), 'danger');
        } elseif ($amount <= 0) {
            flash(__('bl.req_err_amount', 'Please enter the amount you paid.'), 'danger');
        } else {
            db_exec(
                'INSERT INTO payment_requests
                   (company_id, plan_id, months, amount, method, reference, note, submitted_by)
                 VALUES (?,?,?,?,?,?,?,?)',
                [
                    company_id(), $planId, $months, $amount, $method,
                    $ref !== '' ? $ref : null,
                    $note !== '' ? $note : null,
                    user_id(),
                ]
            );
            flash(__('bl.req_sent', 'Payment notification submitted. The platform owner will review and activate your plan.'));
        }
        redirect('admin/billing.php');
    }
}

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
$planById = array_column($plans, null, 'id');

// Pending requests this restaurant has submitted (not yet reviewed)
$pendingRequests = db_all(
    "SELECT pr.*, p.name AS plan_name FROM payment_requests pr JOIN plans p ON p.id = pr.plan_id
      WHERE pr.company_id = ? AND pr.status = 'pending' ORDER BY pr.created_at DESC",
    [company_id()]
);

$statusLabel = match (true) {
    $access === 'suspended'     => [__('st.suspended', 'Suspended'), 'badge-danger'],
    $access !== 'ok'            => [__('st.expired', 'Expired'), 'badge-danger'],
    $c['status'] === 'trial'    => [__('bl.free_trial', 'Free Trial'), 'badge-warning'],
    default                     => [__('st.active', 'Active'), 'badge-success'],
};

$platformPhone = platform_setting('contact_phone', '');
$platformEmail = platform_setting('contact_email', '');
$platformName  = platform_setting('company_name', 'Platform Admin');

$pageTitle = __('nav.billing', 'Billing & Plans');
require __DIR__ . '/../core/header.php';
?>

<!-- Alerts -->
<?php if ($access !== 'ok'): ?>
    <div class="flash-danger flex items-center gap-2 mb-4">
        <span class="text-xl">⚠️</span>
        <div>
            <div class="font-bold"><?= e(company_block_message($access)) ?></div>
            <div class="text-xs"><?= e(__('bl.staff_blocked', 'Staff logins are currently restricted until your subscription is renewed.')) ?></div>
        </div>
    </div>
<?php elseif ($term['days'] !== null && $term['days'] <= 5): ?>
    <div class="flash-warning flex items-center gap-2 mb-4">
        <span class="text-xl">⏳</span>
        <div>
            <div class="font-bold"><?= e(__('bl.ends_on', '', [
                'type' => $c['status'] === 'trial' ? __('dash.trial', 'Trial') : __('dash.subscription', 'Subscription'),
                'days' => $term['days'],
                'date' => dt($term['until'], 'd M Y'),
            ])) ?></div>
            <div class="text-xs"><?= e(__('bl.renew_prompt', 'Renew your plan now to ensure uninterrupted service.')) ?></div>
        </div>
    </div>
<?php endif; ?>

<!-- ── 1. Top Stat Overview Cards ────────────────────────────────────────── -->
<div class="grid grid-cols-2 lg:grid-cols-4 gap-3 mb-6">
    <div class="stat-card accent shadow-sm">
        <div class="flex items-center justify-between">
            <span class="label"><?= e(__('pf.plan', 'Current Plan')) ?></span>
            <span class="text-xs font-semibold px-2 py-0.5 rounded-full bg-brand-light text-brand-dark">⭐</span>
        </div>
        <div class="value text-brand-dark"><?= e($c['plan_name']) ?></div>
        <small class="text-muted text-xs font-medium"><?= e(__('bl.per_month', '', ['price' => money($c['price_month'])])) ?></small>
    </div>

    <div class="stat-card <?= $access === 'ok' ? 'good' : 'bad' ?> shadow-sm">
        <div class="flex items-center justify-between">
            <span class="label"><?= e(__('lbl.status', 'Subscription Status')) ?></span>
            <span class="badge <?= $statusLabel[1] ?>"><?= e($statusLabel[0]) ?></span>
        </div>
        <div class="value text-lg mt-1">
            <?php if ($term['until'] === null): ?>
                <span class="text-slate-800"><?= e(__('pc.no_expiry_date', 'Lifetime / Unlimited')) ?></span>
            <?php else: ?>
                <?= dt($term['until'], 'd M Y') ?>
            <?php endif; ?>
        </div>
        <small class="text-muted text-xs">
            <?php if ($term['until'] !== null): ?>
                <?php if ($term['days'] >= 0): ?>
                    <span class="<?= $term['days'] <= 5 ? 'text-bad font-semibold' : 'text-ok font-semibold' ?>">
                        <?= $term['days'] ?> <?= e(__('pc.days_left', 'days remaining')) ?>
                    </span>
                <?php else: ?>
                    <span class="text-bad font-semibold"><?= abs($term['days']) ?> <?= e(__('pc.days_overdue', 'days overdue')) ?></span>
                <?php endif; ?>
            <?php else: ?>
                <?= e(__('bl.active_no_limit', 'No expiration date')) ?>
            <?php endif; ?>
        </small>
    </div>

    <div class="stat-card shadow-sm">
        <div class="flex items-center justify-between">
            <span class="label"><?= e(__('bl.active_users', 'Staff Accounts')) ?></span>
            <span class="text-xs text-muted">👥</span>
        </div>
        <div class="value flex items-baseline gap-1">
            <span><?= $users ?></span>
            <span class="text-sm font-normal text-muted">/ <?= $c['max_users'] === null ? '∞' : (int)$c['max_users'] ?></span>
        </div>
        <div class="w-full bg-slate-100 rounded-full h-1.5 mt-2 overflow-hidden">
            <?php $userPct = $c['max_users'] ? min(100, round(($users / $c['max_users']) * 100)) : 25; ?>
            <div class="bg-brand h-1.5 rounded-full" style="width: <?= $userPct ?>%"></div>
        </div>
    </div>

    <div class="stat-card shadow-sm">
        <div class="flex items-center justify-between">
            <span class="label"><?= e(__('nav.menu_items', 'Menu Items')) ?></span>
            <span class="text-xs text-muted">🍽️</span>
        </div>
        <div class="value flex items-baseline gap-1">
            <span><?= $items ?></span>
            <span class="text-sm font-normal text-muted">/ <?= $c['max_menu_items'] === null ? '∞' : (int)$c['max_menu_items'] ?></span>
        </div>
        <div class="w-full bg-slate-100 rounded-full h-1.5 mt-2 overflow-hidden">
            <?php $itemPct = $c['max_menu_items'] ? min(100, round(($items / $c['max_menu_items']) * 100)) : 30; ?>
            <div class="bg-brand h-1.5 rounded-full" style="width: <?= $itemPct ?>%"></div>
        </div>
    </div>
</div>

<!-- ── 2. Pricing Showcase Cards ─────────────────────────────────────────── -->
<div class="mb-8">
    <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-2 mb-4">
        <div>
            <h2 class="text-lg font-bold text-ink m-0"><?= e(__('pf.plans', 'Available Subscription Plans')) ?></h2>
            <p class="text-xs text-muted m-0"><?= e(__('bl.plans_subtitle', 'Choose a plan and select 24 Months for long-term uninterrupted service and best value.')) ?></p>
        </div>
        <div class="inline-flex items-center gap-1.5 px-3 py-1 rounded-full bg-emerald-50 text-emerald-700 text-xs font-semibold border border-emerald-100">
            <span>✨</span> <?= e(__('bl.discount_hint', '24 Months (2 Years) is set as default')) ?>
        </div>
    </div>

    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-5">
        <?php foreach ($plans as $p): ?>
            <?php
            $isCurrent = (int)$p['id'] === (int)$c['plan_id'];
            $pricePerMo = (float)$p['price_month'];
            $price24Mo = $pricePerMo * 24;
            $price12Mo = $pricePerMo * 12;
            ?>
            <div class="card relative flex flex-col justify-between transition-all duration-200 hover:shadow-md <?= $isCurrent ? 'border-2 border-accent bg-gradient-to-b from-white to-amber-50/20' : 'hover:border-slate-300' ?>">
                <?php if ($isCurrent): ?>
                    <div class="absolute -top-3 right-4">
                        <span class="badge badge-accent shadow-sm font-bold text-xs uppercase px-2.5 py-0.5 tracking-wide">
                            <?= e(__('bl.current_plan', 'Current Plan')) ?>
                        </span>
                    </div>
                <?php endif; ?>

                <div class="card-body pb-2">
                    <div class="flex items-center justify-between mb-2">
                        <h3 class="font-bold text-base text-ink m-0"><?= e($p['name']) ?></h3>
                        <span class="text-xl"><?= $isCurrent ? '⭐' : '📦' ?></span>
                    </div>

                    <div class="my-3">
                        <div class="flex items-baseline gap-1">
                            <span class="text-3xl font-extrabold text-brand-dark"><?= money($pricePerMo) ?></span>
                            <span class="text-xs text-muted font-medium"><?= e(__('pc.per_month_short', '/ month')) ?></span>
                        </div>
                        <div class="text-xs text-muted mt-1 bg-slate-50 border border-slate-100 rounded-lg p-2 flex items-center justify-between">
                            <span><?= e(__('bl.total_24m', '24-Month Total:')) ?></span>
                            <span class="font-bold text-slate-800"><?= money($price24Mo) ?></span>
                        </div>
                    </div>

                    <!-- Feature list -->
                    <ul class="space-y-2 text-xs text-slate-700 my-4 pl-0 list-none">
                        <li class="flex items-center gap-2">
                            <span class="text-emerald-600 font-bold">✓</span>
                            <span>
                                <?= $p['max_users'] === null
                                    ? '<strong>' . e(__('lbl.unlimited', 'Unlimited')) . '</strong> ' . e(__('bl.staff_accounts', 'Staff Accounts'))
                                    : '<strong>' . (int)$p['max_users'] . '</strong> ' . e(__('bl.staff_accounts', 'Staff Accounts')) ?>
                            </span>
                        </li>
                        <li class="flex items-center gap-2">
                            <span class="text-emerald-600 font-bold">✓</span>
                            <span>
                                <?= $p['max_menu_items'] === null
                                    ? '<strong>' . e(__('lbl.unlimited', 'Unlimited')) . '</strong> ' . e(__('nav.menu_items', 'Menu Items'))
                                    : '<strong>' . (int)$p['max_menu_items'] . '</strong> ' . e(__('nav.menu_items', 'Menu Items')) ?>
                            </span>
                        </li>
                        <li class="flex items-center gap-2">
                            <span class="text-emerald-600 font-bold">✓</span>
                            <span><?= e(__('bl.feat_pos', 'Fast Offline & Online POS Terminal')) ?></span>
                        </li>
                        <li class="flex items-center gap-2">
                            <span class="text-emerald-600 font-bold">✓</span>
                            <span><?= e(__('bl.feat_kds', 'Kitchen Display & Order Rounds')) ?></span>
                        </li>
                        <li class="flex items-center gap-2">
                            <span class="text-emerald-600 font-bold">✓</span>
                            <span><?= e(__('bl.feat_reports', 'Daily Sales, Drawer & P&L Reports')) ?></span>
                        </li>
                    </ul>
                </div>

                <div class="card-footer bg-transparent border-t border-line/50 pt-3">
                    <button type="button"
                            class="btn w-full plan-pick-btn <?= $isCurrent ? 'btn-brand font-semibold' : 'btn-outline' ?>"
                            data-plan-id="<?= (int)$p['id'] ?>"
                            data-plan-name="<?= e($p['name']) ?>"
                            data-plan-price="<?= e($p['price_month']) ?>">
                        <?= $isCurrent ? '✓ ' . e(__('bl.renew_this_plan', 'Renew this Plan')) : e(__('bl.choose_plan', 'Select ' . $p['name'])) ?>
                    </button>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
</div>

<!-- ── 3. Payment Notification & Instructions Section ───────────────────── -->
<!-- ── 3. How to Pay & Quick Action Banner ──────────────────────────────── -->
<div class="card shadow-sm border border-slate-200 mb-8 bg-gradient-to-r from-slate-50 via-white to-amber-50/30">
    <div class="card-body p-5">
        <div class="flex flex-col lg:flex-row items-start lg:items-center justify-between gap-4">
            <div class="space-y-1">
                <div class="flex items-center gap-2">
                    <span class="text-xl">ℹ️</span>
                    <h3 class="font-bold text-base text-ink m-0"><?= e(__('bl.how_to_pay', 'How to Renew or Upgrade')) ?></h3>
                    <span class="badge badge-accent text-[10px] uppercase font-bold tracking-wider">Fast Activation</span>
                </div>
                <p class="text-xs text-slate-600 m-0 leading-relaxed max-w-3xl">
                    <?= e(PLATFORM_PAY_INFO) ?>
                    <?php if ($platformPhone !== '' || $platformEmail !== ''): ?>
                        <span class="font-semibold text-brand-dark block sm:inline mt-1 sm:mt-0">
                            <?= $platformPhone ? ' · 📞 ' . e($platformPhone) : '' ?>
                            <?= $platformEmail ? ' · ✉️ ' . e($platformEmail) : '' ?>
                        </span>
                    <?php endif; ?>
                </p>
            </div>
            <button type="button"
                    class="btn btn-brand py-2.5 px-5 text-sm font-bold shadow-sm hover:shadow flex-shrink-0 flex items-center gap-2"
                    id="openPayModalBtn">
                <span>💳</span>
                <span><?= e(__('bl.notify_payment', 'Notify Us of a Payment')) ?></span>
            </button>
        </div>
    </div>
</div>

<!-- ── 4. Payment History (Recorded Payments) ────────────────────────────── -->
<div class="card shadow-sm mb-6">
    <div class="card-header bg-slate-50/70 border-b border-line flex items-center justify-between py-3 px-4">
        <div class="flex items-center gap-2">
            <span class="text-base">📜</span>
            <span class="font-bold text-sm text-ink"><?= e(__('pc.payments', 'Subscription Payment History')) ?></span>
        </div>
        <span class="text-xs text-muted"><?= count($payments) ?> <?= e(__('bl.records', 'record(s)')) ?></span>
    </div>
    <div class="tbl-scroll">
        <table class="tbl">
            <thead>
                <tr>
                    <th><?= e(__('lbl.date', 'Payment Date')) ?></th>
                    <th><?= e(__('pf.plan', 'Plan')) ?></th>
                    <th><?= e(__('pc.period', 'Coverage Period')) ?></th>
                    <th><?= e(__('pc.method', 'Method')) ?></th>
                    <th><?= e(__('pc.reference', 'Reference / Receipt')) ?></th>
                    <th class="text-right"><?= e(__('lbl.amount', 'Amount')) ?></th>
                    <th class="text-center"><?= e(__('lbl.status', 'Status')) ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($payments as $p): ?>
                    <tr>
                        <td class="text-nowrap font-medium text-xs"><?= dt($p['created_at'], 'd M Y') ?></td>
                        <td>
                            <span class="font-bold text-brand-dark"><?= e($p['plan_name']) ?></span>
                            <span class="text-xs text-muted block"><?= (int)$p['months'] ?> <?= e(__('pc.months', 'months')) ?></span>
                        </td>
                        <td class="text-nowrap text-xs text-slate-600">
                            <?= dt($p['period_from'], 'd M Y') ?> – <?= dt($p['period_to'], 'd M Y') ?>
                        </td>
                        <td>
                            <span class="badge badge-secondary text-xs">
                                <?= e(__('pay.' . $p['method'], ucfirst($p['method']))) ?>
                            </span>
                        </td>
                        <td class="font-mono text-xs text-muted">
                            <?= e($p['reference'] ?: '—') ?>
                        </td>
                        <td class="text-right font-bold text-ink">
                            <?= money($p['amount']) ?>
                        </td>
                        <td class="text-center">
                            <span class="badge badge-success text-[10px] font-semibold">
                                ✓ <?= e(__('st.recorded', 'Confirmed')) ?>
                            </span>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$payments): ?>
                    <tr>
                        <td colspan="7" class="text-center text-muted py-8 text-sm">
                            <div class="text-2xl mb-1">🧾</div>
                            <?= e(__('bl.no_payments', 'No payment records yet.')) ?>
                        </td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- ── 5. Payment Modal (Popup) ─────────────────────────────────────────── -->
<div class="modal-backdrop hidden" id="paymentModal">
    <div class="modal-box max-w-xl max-h-[92vh] overflow-y-auto">
        <form method="post" id="payReqForm">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="pay_request">

            <div class="modal-header bg-slate-50/80 border-b border-line px-5 py-3.5 sticky top-0 bg-white z-10">
                <div class="flex items-center gap-2">
                    <span class="text-xl">💳</span>
                    <div>
                        <h4 class="font-bold text-base text-ink m-0"><?= e(__('bl.notify_payment', 'Notify Us of a Payment')) ?></h4>
                        <div class="text-xs text-muted"><?= e(__('bl.modal_subtitle', 'Choose your plan & duration, then enter payment details.')) ?></div>
                    </div>
                </div>
                <button type="button" class="text-muted hover:text-ink text-2xl leading-none cursor-pointer p-1" id="payModalClose" aria-label="Close">&times;</button>
            </div>

            <div class="modal-body p-5 space-y-4">
                <!-- Step 1: Plan & Duration -->
                <div class="bg-slate-50 p-3.5 rounded-xl border border-slate-100 space-y-3">
                    <div class="text-xs font-bold uppercase tracking-wider text-slate-500 flex items-center gap-1.5">
                        <span class="w-4 h-4 rounded-full bg-brand text-white flex items-center justify-center text-[10px]">1</span>
                        <span><?= e(__('bl.step_plan_duration', 'Subscription Plan & Duration')) ?></span>
                    </div>

                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                        <div>
                            <label class="label text-xs font-semibold" for="req_plan"><?= e(__('pf.plan', 'Select Plan')) ?></label>
                            <select id="req_plan" name="plan_id" class="input font-medium">
                                <?php foreach ($plans as $p): ?>
                                    <option value="<?= (int)$p['id'] ?>"
                                            data-name="<?= e($p['name']) ?>"
                                            data-price="<?= e($p['price_month']) ?>"
                                            <?= (int)$p['id'] === (int)$c['plan_id'] ? 'selected' : '' ?>>
                                        <?= e($p['name']) ?> — <?= money($p['price_month']) ?>/mo
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div>
                            <label class="label text-xs font-semibold" for="req_months"><?= e(__('pc.months', 'Duration')) ?></label>
                            <select id="req_months" name="months" class="input font-medium">
                                <option value="1">1 Month (1 mo)</option>
                                <option value="3">3 Months (Quarterly)</option>
                                <option value="6">6 Months (Half Year)</option>
                                <option value="12">12 Months (1 Year)</option>
                                <option value="24" selected>24 Months (2 Years) — ⭐ Recommended</option>
                            </select>
                        </div>
                    </div>

                    <!-- Quick duration pill buttons -->
                    <div class="flex items-center gap-1.5 flex-wrap pt-1">
                        <span class="text-[11px] text-muted font-medium mr-1"><?= e(__('bl.quick_pick', 'Quick pick:')) ?></span>
                        <?php foreach ([1 => '1 Mo', 3 => '3 Mo', 6 => '6 Mo', 12 => '1 Year', 24 => '2 Years (24 Mo)'] as $mVal => $mLabel): ?>
                            <button type="button"
                                    class="duration-pill px-2.5 py-1 text-xs rounded-full border border-slate-200 bg-white hover:bg-slate-100 transition-colors <?= $mVal === 24 ? 'border-brand bg-brand-light font-bold text-brand-dark ring-1 ring-brand' : 'text-slate-600' ?>"
                                    data-months="<?= $mVal ?>">
                                <?= e($mLabel) ?>
                            </button>
                        <?php endforeach; ?>
                    </div>
                </div>

                <!-- Step 2: Payment Method Selection -->
                <div class="bg-slate-50 p-3.5 rounded-xl border border-slate-100 space-y-3">
                    <div class="text-xs font-bold uppercase tracking-wider text-slate-500 flex items-center gap-1.5">
                        <span class="w-4 h-4 rounded-full bg-brand text-white flex items-center justify-center text-[10px]">2</span>
                        <span><?= e(__('pc.method', 'Payment Method')) ?></span>
                    </div>

                    <div class="grid grid-cols-2 sm:grid-cols-4 gap-2">
                        <?php
                        $methods = [
                            ['mobile', '📱 ' . __('pay.mobile', 'Mobile Money'), 'EVC / Telesom'],
                            ['cash',   '💵 ' . __('pay.cash', 'Cash'),         'Office / Cashier'],
                            ['bank',   '🏦 ' . __('pay.bank', 'Bank Transfer'),'Deposit'],
                            ['card',   '💳 ' . __('pay.card', 'Debit / Card'), 'Card'],
                        ];
                        ?>
                        <?php foreach ($methods as $idx => [$mKey, $mTitle, $mDesc]): ?>
                            <label class="method-card flex flex-col justify-between p-2 rounded-lg border cursor-pointer transition-all text-left <?= $idx === 0 ? 'border-brand bg-brand-light/50 ring-1 ring-brand' : 'border-slate-200 bg-white hover:bg-slate-50' ?>">
                                <div class="flex items-center justify-between mb-0.5">
                                    <span class="text-xs font-bold text-ink"><?= e($mTitle) ?></span>
                                    <input type="radio" name="method" value="<?= $mKey ?>" <?= $idx === 0 ? 'checked' : '' ?> class="sr-only">
                                </div>
                                <span class="text-[10px] text-muted leading-tight"><?= e($mDesc) ?></span>
                            </label>
                        <?php endforeach; ?>
                    </div>
                </div>

                <!-- Step 3: Amount, Reference & Notes -->
                <div class="bg-slate-50 p-3.5 rounded-xl border border-slate-100 space-y-3">
                    <div class="text-xs font-bold uppercase tracking-wider text-slate-500 flex items-center gap-1.5">
                        <span class="w-4 h-4 rounded-full bg-brand text-white flex items-center justify-center text-[10px]">3</span>
                        <span><?= e(__('bl.payment_details', 'Amount & Transaction Reference')) ?></span>
                    </div>

                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                        <div>
                            <label class="label text-xs font-semibold" for="req_amount">
                                <?= e(__('bl.amount_paid', 'Total Amount Paid ($)')) ?> <span class="text-bad">*</span>
                            </label>
                            <div class="relative">
                                <span class="absolute left-3 top-2.5 text-muted font-bold text-sm">$</span>
                                <input type="number" id="req_amount" name="amount" class="input pl-7 font-bold text-ink text-base" step="0.01" min="0.01" required>
                            </div>
                        </div>

                        <div>
                            <label class="label text-xs font-semibold" for="req_ref">
                                <?= e(__('pc.reference', 'Transaction ID / Reference')) ?>
                            </label>
                            <input type="text" id="req_ref" name="reference" class="input font-mono" maxlength="100" placeholder="<?= e(__('pc.txn_ph', 'e.g. TXN98234729 or receipt #')) ?>">
                        </div>

                        <div class="sm:col-span-2">
                            <label class="label text-xs font-semibold" for="req_note">
                                <?= e(__('bl.note', 'Note / Sender details (optional)')) ?>
                            </label>
                            <input type="text" id="req_note" name="note" class="input" maxlength="255" placeholder="<?= e(__('bl.note_ph', 'e.g. Paid from 061xxxxxxx via EVC Plus')) ?>">
                        </div>
                    </div>
                </div>

                <!-- Live Summary Breakdown Box -->
                <div class="p-3.5 rounded-xl bg-amber-50/70 border border-amber-200 flex flex-col sm:flex-row items-start sm:items-center justify-between gap-3">
                    <div>
                        <div class="text-xs font-semibold text-amber-900"><?= e(__('bl.order_summary', 'Subscription Order Summary')) ?></div>
                        <div class="text-xs text-amber-800 mt-0.5" id="summaryText">
                            <!-- Populated by JS -->
                        </div>
                    </div>
                    <div class="text-right sm:self-center">
                        <div class="text-[10px] uppercase font-bold text-amber-700 tracking-wider"><?= e(__('bl.total_payable', 'Total Payable')) ?></div>
                        <div class="text-2xl font-black text-brand-dark" id="summaryTotal">$0.00</div>
                    </div>
                </div>
            </div>

            <div class="modal-footer bg-slate-50/70 border-t border-line px-5 py-3 flex items-center justify-end gap-2 sticky bottom-0 bg-white">
                <button type="button" class="btn btn-outline" id="payModalCancelBtn"><?= e(__('btn.cancel', 'Cancel')) ?></button>
                <button type="submit" class="btn btn-brand font-bold flex items-center gap-1.5 shadow-sm">
                    <span>🔒</span>
                    <span><?= e(__('bl.submit_notification', 'Submit Payment Notification')) ?></span>
                </button>
            </div>
        </form>
    </div>
</div>

<script>
(function () {
    var modal          = document.getElementById('paymentModal');
    var openBtn        = document.getElementById('openPayModalBtn');
    var closeBtn       = document.getElementById('payModalClose');
    var cancelBtn      = document.getElementById('payModalCancelBtn');
    var planSelect     = document.getElementById('req_plan');
    var monthsSelect   = document.getElementById('req_months');
    var amountInput    = document.getElementById('req_amount');
    var summaryText    = document.getElementById('summaryText');
    var summaryTotal   = document.getElementById('summaryTotal');
    var durationPills  = document.querySelectorAll('.duration-pill');
    var methodCards    = document.querySelectorAll('.method-card');
    var planPickButtons = document.querySelectorAll('.plan-pick-btn');
    var userEditedAmount = false;

    // Open Modal
    function openModal(planId) {
        if (planId && planSelect) {
            planSelect.value = planId;
        }
        userEditedAmount = false;
        updateCalculations();
        if (modal) {
            modal.classList.remove('hidden');
            document.body.style.overflow = 'hidden';
        }
    }

    // Close Modal
    function closeModal() {
        if (modal) {
            modal.classList.add('hidden');
            document.body.style.overflow = '';
        }
    }

    if (openBtn) {
        openBtn.addEventListener('click', function () { openModal(); });
    }
    if (closeBtn) {
        closeBtn.addEventListener('click', closeModal);
    }
    if (cancelBtn) {
        cancelBtn.addEventListener('click', closeModal);
    }
    if (modal) {
        modal.addEventListener('click', function (e) {
            if (e.target === modal) closeModal();
        });
    }
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape' && modal && !modal.classList.contains('hidden')) {
            closeModal();
        }
    });

    // Recalculate amount and update live summary box
    function updateCalculations() {
        if (!planSelect || !monthsSelect) return;
        var selectedOpt = planSelect.options[planSelect.selectedIndex];
        if (!selectedOpt) return;
        var planName    = selectedOpt.dataset.name || selectedOpt.text;
        var planPrice   = parseFloat(selectedOpt.dataset.price || '0');
        var months      = parseInt(monthsSelect.value, 10) || 1;
        var total       = planPrice * months;

        if (!userEditedAmount && amountInput) {
            amountInput.value = total.toFixed(2);
        }

        if (summaryText && summaryTotal) {
            var durationLabel = months === 1 ? '1 Month' : (months === 12 ? '12 Months (1 Year)' : (months === 24 ? '24 Months (2 Years)' : months + ' Months'));
            summaryText.innerHTML = '<strong>' + planName + '</strong> (' + '$' + planPrice.toFixed(2) + '/mo) &times; ' + durationLabel;
            summaryTotal.textContent = '$' + total.toFixed(2);
        }

        // Highlight matching duration pill
        durationPills.forEach(function (pill) {
            var pillMonths = parseInt(pill.dataset.months, 10);
            if (pillMonths === months) {
                pill.classList.add('border-brand', 'bg-brand-light', 'font-bold', 'text-brand-dark', 'ring-1', 'ring-brand');
                pill.classList.remove('border-slate-200', 'bg-white', 'text-slate-600');
            } else {
                pill.classList.remove('border-brand', 'bg-brand-light', 'font-bold', 'text-brand-dark', 'ring-1', 'ring-brand');
                pill.classList.add('border-slate-200', 'bg-white', 'text-slate-600');
            }
        });
    }

    if (amountInput) {
        amountInput.addEventListener('input', function () {
            userEditedAmount = true;
            if (summaryTotal) {
                var val = parseFloat(amountInput.value) || 0;
                summaryTotal.textContent = '$' + val.toFixed(2);
            }
        });
    }

    if (planSelect) {
        planSelect.addEventListener('change', function () {
            userEditedAmount = false;
            updateCalculations();
        });
    }

    if (monthsSelect) {
        monthsSelect.addEventListener('change', function () {
            userEditedAmount = false;
            updateCalculations();
        });
    }

    // Duration pills click handlers
    durationPills.forEach(function (pill) {
        pill.addEventListener('click', function () {
            var m = this.dataset.months;
            if (monthsSelect) {
                monthsSelect.value = m;
                userEditedAmount = false;
                updateCalculations();
            }
        });
    });

    // Method selection radio card style sync
    methodCards.forEach(function (card) {
        card.addEventListener('click', function () {
            methodCards.forEach(function (c) {
                c.classList.remove('border-brand', 'bg-brand-light/50', 'ring-1', 'ring-brand');
                c.classList.add('border-slate-200', 'bg-white');
            });
            card.classList.add('border-brand', 'bg-brand-light/50', 'ring-1', 'ring-brand');
            card.classList.remove('border-slate-200', 'bg-white');
            var radio = card.querySelector('input[type="radio"]');
            if (radio) radio.checked = true;
        });
    });

    // "Select / Renew Plan" button on pricing cards opens the modal for that plan
    planPickButtons.forEach(function (btn) {
        btn.addEventListener('click', function () {
            var planId = this.dataset.planId;
            openModal(planId);
        });
    });

    // Initial calculation run
    updateCalculations();
})();
</script>

<?php require __DIR__ . '/../core/footer.php'; ?>
