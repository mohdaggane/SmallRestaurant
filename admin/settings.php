<?php
/** Shop settings that appear on screens and receipts. */

require_once __DIR__ . '/../core/config.php';
require_role('admin');

// key => [label, input type, help text]
$SHOP_FIELDS = [
    'shop_name'      => ['Shop name', 'text', ''],
    'shop_tagline'   => ['Tagline', 'text', ''],
    'shop_address'   => ['Address (printed on receipts)', 'text', ''],
    'shop_phone'     => ['Phone number', 'text', ''],
    'currency'       => ['Currency symbol', 'text', ''],
    'tax_percent'    => ['Tax percentage applied to every order', 'number', ''],
    'receipt_footer' => ['Receipt footer message', 'text', ''],
];

// Mobile-money merchant account. The dial code printed on bills and receipts is
// built as <prefix><merchant number>*<amount># — e.g. *789*123456*6.00#
$MERCHANT_FIELDS = [
    'merchant_name'  => ['Merchant account name', 'text',
                         'Shown above the dial code so the customer knows who they are paying.'],
    'merchant_id'    => ['Merchant number', 'text',
                         'Leave blank to keep the dial code off every printout.'],
    'ussd_prefix'    => ['USSD prefix', 'text',
                         'EVC Plus uses *789*. Change it only if your provider differs.'],
];

$CHECKBOXES = [
    'merchant_on_receipt' => ['Also print the dial code on paid receipts',
                              'Unpaid bills always show it. Turn this off if printing it on a
                               cash-paid receipt confuses customers.'],
];

if (is_post()) {
    csrf_check();

    // All of it or none of it: a failure halfway through the loop must not leave
    // the shop with a new merchant number but the old currency symbol.
    $conn->begin_transaction();
    try {
        foreach ($SHOP_FIELDS + $MERCHANT_FIELDS as $key => [$label, $type, $help]) {
            $value = post($key);
            if ($key === 'tax_percent') {
                $value = (string)min(max((float)$value, 0), 100);
            }
            if ($key === 'currency' && $value === '') {
                $value = '$';
            }
            if ($key === 'ussd_prefix' && $value === '') {
                $value = '*789*';
            }
            if ($key === 'merchant_id') {
                // A dial string cannot carry spaces or punctuation.
                $value = preg_replace('/[^0-9]/', '', $value) ?? '';
            }
            db_exec(
                'INSERT INTO settings (setting_key, setting_value) VALUES (?,?)
                 ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)',
                [$key, $value]
            );
        }

        foreach ($CHECKBOXES as $key => $meta) {
            db_exec(
                'INSERT INTO settings (setting_key, setting_value) VALUES (?,?)
                 ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)',
                [$key, isset($_POST[$key]) ? '1' : '0']
            );
        }

        $conn->commit();
        flash('Settings saved.');
    } catch (Throwable $err) {
        $conn->rollback();
        flash('Nothing was saved — the database rejected one of those values: '
            . $err->getMessage(), 'danger');
    }

    redirect('admin/settings.php');
}

$pageTitle = 'Settings';
require __DIR__ . '/../core/header.php';
?>

<form method="post">
<?= csrf_field() ?>
<div class="row">
    <div class="col-lg-6">
        <div class="card mb-3">
            <div class="card-header">Shop details</div>
            <div class="card-body">
                <?php foreach ($SHOP_FIELDS as $key => [$label, $type, $help]): ?>
                    <div class="mb-3">
                        <label class="form-label"><?= e($label) ?></label>
                        <input type="<?= $type ?>" name="<?= e($key) ?>" class="form-control"
                               <?= $type === 'number' ? 'step="0.01" min="0" max="100"' : 'maxlength="120"' ?>
                               value="<?= e(setting($key)) ?>">
                    </div>
                <?php endforeach; ?>
            </div>
        </div>

        <div class="card">
            <div class="card-header">Mobile money merchant account</div>
            <div class="card-body">
                <p class="text-muted small">
                    Every bill and receipt prints a dial code the customer can enter on their
                    phone to pay you directly:
                    <code>*789*&lt;merchant number&gt;*&lt;amount&gt;#</code>
                </p>

                <?php foreach ($MERCHANT_FIELDS as $key => [$label, $type, $help]): ?>
                    <div class="mb-3">
                        <label class="form-label"><?= e($label) ?></label>
                        <input type="<?= $type ?>" name="<?= e($key) ?>" class="form-control"
                               id="set_<?= e($key) ?>" maxlength="120"
                               value="<?= e(setting($key, $key === 'ussd_prefix' ? '*789*' : '')) ?>">
                        <?php if ($help !== ''): ?>
                            <div class="form-text"><?= e($help) ?></div>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>

                <?php foreach ($CHECKBOXES as $key => [$label, $help]): ?>
                    <div class="form-check mb-3">
                        <input type="checkbox" class="form-check-input" id="cb_<?= e($key) ?>"
                               name="<?= e($key) ?>" <?= setting($key, '1') === '1' ? 'checked' : '' ?>>
                        <label class="form-check-label" for="cb_<?= e($key) ?>"><?= e($label) ?></label>
                        <div class="form-text"><?= e($help) ?></div>
                    </div>
                <?php endforeach; ?>

                <div class="alert alert-light border mb-0">
                    <div class="small text-muted mb-1">Printed on a <?= e(money(6)) ?> order:</div>
                    <div id="ussdPreview" style="font-family:Consolas,monospace;font-size:19px;font-weight:700;">
                        <?= e(merchant_ussd(6.00) ?? 'No merchant number set — nothing will print.') ?>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="col-lg-6">
        <div class="card">
            <div class="card-header">About this install</div>
            <div class="card-body small">
                <p class="mb-2">Tax applies to every new order at the rate above; set it to <strong>0</strong> to
                   sell tax-free. Changing a rate never alters orders that are already saved.</p>
                <table class="table table-sm mb-0">
                    <tr><td>PHP</td><td class="text-end"><?= e(PHP_VERSION) ?></td></tr>
                    <tr><td>Database</td><td class="text-end"><?= e($conn->server_info) ?></td></tr>
                    <tr><td>Menu items</td><td class="text-end"><?= (int)db_value('SELECT COUNT(*) FROM menu_items') ?></td></tr>
                    <tr><td>Orders recorded</td><td class="text-end"><?= (int)db_value('SELECT COUNT(*) FROM orders') ?></td></tr>
                    <tr><td>Staff accounts</td><td class="text-end"><?= (int)db_value('SELECT COUNT(*) FROM users') ?></td></tr>
                </table>
            </div>
        </div>

        <div class="card mt-3">
            <div class="card-header">How the dial code prints</div>
            <div class="card-body">
                <div class="receipt" style="width:auto;margin:0;">
                    <div class="rule"></div>
                    <div class="center">PAY BY MOBILE MONEY</div>
                    <?php if (setting('merchant_name') !== ''): ?>
                        <div class="center"><?= e(setting('merchant_name')) ?></div>
                    <?php endif; ?>
                    <div class="pay-code"><?= e(merchant_ussd(6.00) ?? '*789*…*6.00#') ?></div>
                    <div class="center" style="font-size:11px;">Dial the code above to pay <?= e(money(6)) ?></div>
                    <div class="rule"></div>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="mt-3 mb-4">
    <button class="btn btn-lg text-white" style="background:var(--brand)">Save settings</button>
</div>
</form>

<?php
// Live preview so the merchant number can be checked before saving.
$pageScripts = '
<script>
(function () {
    var idBox     = document.getElementById("set_merchant_id");
    var prefixBox = document.getElementById("set_ussd_prefix");
    var out       = document.getElementById("ussdPreview");
    if (!idBox || !prefixBox || !out) { return; }

    function redraw() {
        var id = idBox.value.replace(/[^0-9]/g, "");
        var prefix = prefixBox.value.trim() || "*789*";
        while (prefix.charAt(prefix.length - 1) === "*") {
            prefix = prefix.slice(0, -1);
        }
        out.textContent = id === ""
            ? "No merchant number set — nothing will print."
            : prefix + "*" + id + "*6.00#";
    }
    idBox.addEventListener("input", redraw);
    prefixBox.addEventListener("input", redraw);
}());
</script>';
require __DIR__ . '/../core/footer.php';
