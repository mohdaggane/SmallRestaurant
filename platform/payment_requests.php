<?php

require_once __DIR__ . '/../core/config.php';
require_platform();

$back = 'platform/payment_requests.php';

// ── Handle approve / dismiss ──────────────────────────────────────────────
if (is_post()) {
    csrf_check();
    $action = post('action');
    $reqId  = (int)post('req_id');

    $req = db_one(
        "SELECT pr.*, c.status AS c_status, c.paid_until, c.name AS company_name,
                p.name AS plan_name
           FROM payment_requests pr
           JOIN companies c ON c.id = pr.company_id
           JOIN plans     p ON p.id = pr.plan_id
          WHERE pr.id = ? AND pr.status = 'pending'",
        [$reqId]
    );

    if (!$req) {
        flash(__('pr.err_gone', 'Request not found or already reviewed.'), 'danger');
        redirect($back);
    }

    if ($action === 'approve') {
        $months = max(1, (int)$req['months']);
        $today  = date('Y-m-d');
        // Renewal before expiry: start the day after paid_until so no days are lost.
        $from = ($req['c_status'] === 'active' && $req['paid_until'] !== null && $req['paid_until'] >= $today)
            ? date('Y-m-d', strtotime($req['paid_until'] . ' +1 day'))
            : $today;
        $to = date('Y-m-d', strtotime("$from +$months months -1 day"));

        $conn->begin_transaction();
        try {
            // Record the payment in company_payments (same as company.php does).
            db_exec(
                'INSERT INTO company_payments
                   (company_id, plan_id, amount, months, period_from, period_to, method, reference, recorded_by)
                 VALUES (?,?,?,?,?,?,?,?,?)',
                [
                    $req['company_id'], $req['plan_id'], $req['amount'],
                    $months, $from, $to,
                    $req['method'],
                    $req['reference'] ?? null,
                    platform_user()['id'],
                ]
            );
            // Activate the company and extend its paid_until.
            db_exec(
                "UPDATE companies SET plan_id = ?, status = 'active', paid_until = ? WHERE id = ?",
                [$req['plan_id'], $to, $req['company_id']]
            );
            // Mark the request approved.
            db_exec(
                "UPDATE payment_requests
                    SET status = 'approved', reviewed_by = ?, reviewed_at = NOW()
                  WHERE id = ?",
                [platform_user()['id'], $reqId]
            );
            $conn->commit();
        } catch (Throwable $e) {
            $conn->rollback();
            flash(__('pr.err_approve', 'Could not record payment: {error}', ['error' => $e->getMessage()]), 'danger');
            redirect($back);
        }
        flash(__('pr.approved_ok', 'Payment approved. {name} is now active until {date}.', [
            'name' => $req['company_name'],
            'date' => dt($to, 'd M Y'),
        ]));
        redirect($back);
    }

    if ($action === 'dismiss') {
        db_exec(
            "UPDATE payment_requests
                SET status = 'dismissed', reviewed_by = ?, reviewed_at = NOW()
              WHERE id = ?",
            [platform_user()['id'], $reqId]
        );
        flash(__('pr.dismissed_ok', 'Request dismissed.'), 'warning');
        redirect($back);
    }
}

// ── Read data ─────────────────────────────────────────────────────────────
$filterStatus = in_array(get('status'), ['pending', 'approved', 'dismissed'], true)
    ? get('status') : 'pending';

$requests = db_all(
    "SELECT pr.*,
            c.name AS company_name, c.slug AS company_slug, c.status AS c_status,
            p.name AS plan_name,
            u.full_name AS submitted_by_name,
            a.full_name AS reviewed_by_name
       FROM payment_requests pr
       JOIN companies c ON c.id = pr.company_id
       JOIN plans     p ON p.id = pr.plan_id
  LEFT JOIN users     u ON u.id = pr.submitted_by
  LEFT JOIN platform_admins a ON a.id = pr.reviewed_by
      WHERE pr.status = ?
      ORDER BY pr.created_at DESC",
    [$filterStatus]
);

$counts = db_one(
    "SELECT
        SUM(status = 'pending')   AS pending,
        SUM(status = 'approved')  AS approved,
        SUM(status = 'dismissed') AS dismissed
       FROM payment_requests"
);

$pageTitle = __('pr.title', 'Payment Requests');
$platform  = true;
$pageActions = '<a class="btn btn-outline btn-sm" href="' . url('platform/index.php') . '">'
             . e(__('pc.all_restaurants')) . '</a>';
require __DIR__ . '/../core/header.php';
?>

<!-- ── Filter tabs ──────────────────────────────────────────────────────── -->
<div class="flex gap-1 mb-4 border-b border-line">
    <?php foreach ([
        'pending'   => [__('pr.tab_pending',   'Pending'),   (int)$counts['pending'],   'badge-warning'],
        'approved'  => [__('pr.tab_approved',  'Approved'),  (int)$counts['approved'],  'badge-success'],
        'dismissed' => [__('pr.tab_dismissed', 'Dismissed'), (int)$counts['dismissed'], ''],
    ] as $key => [$label, $count, $badgeCls]): ?>
        <a href="?status=<?= $key ?>"
           class="px-4 py-2 text-sm no-underline border-b-2 -mb-px transition-colors duration-150
                  <?= $filterStatus === $key
                      ? 'border-accent font-semibold text-brand-dark'
                      : 'border-transparent text-muted hover:text-ink' ?>">
            <?= e($label) ?>
            <?php if ($count > 0): ?>
                <span class="badge <?= $badgeCls ?> ml-1"><?= $count ?></span>
            <?php endif; ?>
        </a>
    <?php endforeach; ?>
</div>

<!-- ── Request list ─────────────────────────────────────────────────────── -->
<?php if (!$requests): ?>
    <div class="card">
        <div class="text-center text-muted py-12">
            <?= e(__('pr.none', 'No {status} requests.', ['status' => $filterStatus])) ?>
        </div>
    </div>
<?php else: ?>
<div class="flex flex-col gap-3">
    <?php foreach ($requests as $r): ?>
        <?php
        $isPending = $r['status'] === 'pending';
        [$accessLabel, $accessBadge] = match ($r['c_status']) {
            'active'    => [__('st.active'),    'badge-success'],
            'trial'     => [__('st.trial'),     'badge-warning'],
            'suspended' => [__('st.suspended'), 'badge-danger'],
            default     => [__('st.expired'),   'badge-danger'],
        };
        ?>
        <div class="card <?= $isPending ? 'border-l-4 border-l-amber-400' : '' ?>">
            <div class="card-body">
                <div class="flex flex-wrap items-start gap-3 justify-between">

                    <!-- Left: restaurant + request detail -->
                    <div class="flex-1 min-w-0">
                        <div class="flex flex-wrap items-center gap-2 mb-1">
                            <a class="font-semibold text-brand-dark hover:underline"
                               href="<?= url('platform/company.php?id=' . (int)$r['company_id']) ?>">
                                <?= e($r['company_name']) ?>
                            </a>
                            <span class="badge <?= $accessBadge ?>"><?= e($accessLabel) ?></span>
                            <span class="text-muted text-xs"><?= e($r['company_slug']) ?></span>
                        </div>

                        <div class="grid grid-cols-2 lg:grid-cols-4 gap-x-6 gap-y-1 text-sm mt-2">
                            <div>
                                <span class="text-muted"><?= e(__('pf.plan')) ?>:</span>
                                <strong><?= e($r['plan_name']) ?></strong>
                            </div>
                            <div>
                                <span class="text-muted"><?= e(__('pc.months')) ?>:</span>
                                <strong><?= (int)$r['months'] ?></strong>
                            </div>
                            <div>
                                <span class="text-muted"><?= e(__('lbl.amount')) ?>:</span>
                                <strong><?= money($r['amount']) ?></strong>
                            </div>
                            <div>
                                <span class="text-muted"><?= e(__('pc.method')) ?>:</span>
                                <strong><?= e(__('pay.' . $r['method'], ucfirst($r['method']))) ?></strong>
                            </div>
                            <?php if ($r['reference']): ?>
                            <div class="col-span-2">
                                <span class="text-muted"><?= e(__('pc.reference')) ?>:</span>
                                <span class="font-mono text-xs"><?= e($r['reference']) ?></span>
                            </div>
                            <?php endif; ?>
                            <?php if ($r['note']): ?>
                            <div class="col-span-2 lg:col-span-4">
                                <span class="text-muted"><?= e(__('bl.note', 'Note')) ?>:</span>
                                <?= e($r['note']) ?>
                            </div>
                            <?php endif; ?>
                        </div>

                        <div class="text-xs text-muted mt-2">
                            <?= e(__('pr.submitted_by', 'Submitted by {name} on {date}', [
                                'name' => $r['submitted_by_name'] ?? '—',
                                'date' => dt($r['created_at'], 'd M Y H:i'),
                            ])) ?>
                            <?php if (!$isPending && $r['reviewed_by_name']): ?>
                                · <?= e(__('pr.reviewed_by', 'reviewed by {name} on {date}', [
                                    'name' => $r['reviewed_by_name'],
                                    'date' => dt($r['reviewed_at'], 'd M Y H:i'),
                                ])) ?>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- Right: action buttons (pending only) -->
                    <?php if ($isPending): ?>
                    <div class="flex gap-2 flex-shrink-0">
                        <!-- Approve -->
                        <form method="post" id="approve-<?= (int)$r['id'] ?>">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action"  value="approve">
                            <input type="hidden" name="req_id"  value="<?= (int)$r['id'] ?>">
                            <button class="btn btn-brand btn-sm"
                                    onclick="return confirm('<?= e(__('pr.confirm_approve',
                                        'Approve this payment and activate {name}?',
                                        ['name' => $r['company_name']])) ?>')">
                                ✓ <?= e(__('pr.btn_approve', 'Approve')) ?>
                            </button>
                        </form>
                        <!-- Dismiss -->
                        <form method="post" id="dismiss-<?= (int)$r['id'] ?>">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action"  value="dismiss">
                            <input type="hidden" name="req_id"  value="<?= (int)$r['id'] ?>">
                            <button class="btn btn-outline btn-sm"
                                    onclick="return confirm('<?= e(__('pr.confirm_dismiss',
                                        'Dismiss this request from {name}?',
                                        ['name' => $r['company_name']])) ?>')">
                                ✕ <?= e(__('pr.btn_dismiss', 'Dismiss')) ?>
                            </button>
                        </form>
                    </div>
                    <?php else: ?>
                    <div class="flex-shrink-0">
                        <span class="badge <?= $r['status'] === 'approved' ? 'badge-success' : '' ?>">
                            <?= e(__('pr.status_' . $r['status'], ucfirst($r['status']))) ?>
                        </span>
                    </div>
                    <?php endif; ?>

                </div>
            </div>
        </div>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<?php require __DIR__ . '/../core/footer.php'; ?>
