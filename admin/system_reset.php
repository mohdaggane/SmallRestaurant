<?php
/**
 * System Reset — admin only.
 * Clears this restaurant's transactional data (orders, order_items, expenses,
 * shifts) while preserving its users, menu items, categories and settings.
 * Other restaurants on the system are never touched.
 * Requires the admin to type "RESET" to confirm.
 */

require_once __DIR__ . '/../core/config.php';
require_role('admin');

$counts = [
    'orders'    => (int)db_value('SELECT COUNT(*) FROM orders WHERE company_id = ?', [company_id()]),
    'expenses'  => (int)db_value('SELECT COUNT(*) FROM expenses WHERE company_id = ?', [company_id()]),
    'shifts'    => (int)db_value('SELECT COUNT(*) FROM shifts WHERE company_id = ?', [company_id()]),
];

$error = '';

if (is_post()) {
    csrf_check();

    $confirm = trim(post('confirm_word'));

    if ($confirm !== 'RESET') {
        $error = 'You must type RESET (all caps) to confirm.';
    } else {
        $conn->begin_transaction();
        try {
            // Delete in FK-safe order: order_items references orders, expenses/shifts reference users
            db_exec('DELETE FROM order_items WHERE company_id = ?', [company_id()]);
            db_exec('DELETE FROM orders WHERE company_id = ?', [company_id()]);
            db_exec('DELETE FROM expenses WHERE company_id = ?', [company_id()]);
            db_exec('DELETE FROM shifts WHERE company_id = ?', [company_id()]);

            // Receipt numbers start again from 0001. (Row ids are shared by
            // every restaurant, so they are never reset.)
            db_exec('UPDATE companies SET order_seq = 0 WHERE id = ?', [company_id()]);

            // Log the reset time
            db_exec(
                'INSERT INTO settings (company_id, setting_key, setting_value) VALUES (?,?,?)
                 ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)',
                [company_id(), 'last_reset_at', date('Y-m-d H:i:s')]
            );

            $conn->commit();
            flash('System reset complete. All orders, expenses, and shifts have been cleared. Users and menu are intact.', 'warning');
            redirect('admin/index.php');
        } catch (Throwable $e) {
            $conn->rollback();
            flash('Reset failed — nothing was deleted: ' . $e->getMessage(), 'danger');
            redirect('admin/system_reset.php');
        }
    }
}

$lastReset = setting('last_reset_at', '');
$pageTitle = 'System Reset';
require __DIR__ . '/../core/header.php';
?>

<div class="max-w-xl">

    <!-- Warning banner -->
    <div class="bg-red-50 border-2 border-bad rounded-xl p-5 mb-5">
        <div class="flex items-start gap-3">
            <span class="text-3xl">⚠️</span>
            <div>
                <h2 class="text-bad font-bold text-base mb-1">Danger zone — this cannot be undone</h2>
                <p class="text-sm text-red-800">
                    This will permanently delete <strong>all orders, expenses, and cash drawer shifts</strong>.
                    Your users, menu items, categories, and settings will be preserved.
                    Use this to start fresh at the beginning of a new season or after testing.
                </p>
            </div>
        </div>
    </div>

    <!-- What will be deleted -->
    <div class="card mb-5">
        <div class="card-header text-bad">What will be deleted</div>
        <table class="tbl">
            <tbody>
                <tr><td>Orders (all statuses)</td><td class="text-right font-semibold text-bad"><?= number_format($counts['orders']) ?></td></tr>
                <tr><td>Expenses records</td><td class="text-right font-semibold text-bad"><?= number_format($counts['expenses']) ?></td></tr>
                <tr><td>Cash drawer shifts</td><td class="text-right font-semibold text-bad"><?= number_format($counts['shifts']) ?></td></tr>
            </tbody>
        </table>
        <div class="card-footer text-xs text-muted">
            Users, menu items, categories, and settings are <strong>not</strong> affected.
            <?php if ($lastReset): ?>
                Last reset was on <?= dt($lastReset, 'd M Y, g:i A') ?>.
            <?php endif; ?>
        </div>
    </div>

    <?php if ($error !== ''): ?>
        <div class="flash-danger"><?= e($error) ?></div>
    <?php endif; ?>

    <!-- Confirm form -->
    <div class="card">
        <div class="card-header">Confirm reset</div>
        <div class="card-body">
            <form method="post" class="space-y-4"
                  onsubmit="return confirm('Are you absolutely sure? This CANNOT be undone.');">
                <?= csrf_field() ?>
                <div>
                    <label class="label" for="confirm_word">
                        Type <strong class="text-bad font-mono">RESET</strong> to confirm
                    </label>
                    <input type="text" id="confirm_word" name="confirm_word" class="input font-mono tracking-widest"
                           placeholder="RESET" autocomplete="off" autofocus>
                </div>
                <div class="flex gap-3">
                    <button class="btn btn-danger btn-lg" id="resetBtn" disabled>
                        🗑️ Reset system data
                    </button>
                    <a class="btn btn-outline btn-lg" href="<?= url('admin/index.php') ?>">Cancel</a>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
(function () {
    var input = document.getElementById('confirm_word');
    var btn   = document.getElementById('resetBtn');
    input.addEventListener('input', function () {
        btn.disabled = input.value.trim() !== 'RESET';
    });
}());
</script>

<?php require __DIR__ . '/../core/footer.php'; ?>
