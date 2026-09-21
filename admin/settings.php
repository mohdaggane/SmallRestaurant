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
    'receipt_footer' => ['Receipt footer message', 'text', ''],
];

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

    $conn->begin_transaction();
    try {
        foreach ($SHOP_FIELDS + $MERCHANT_FIELDS as $key => [$label, $type, $help]) {
            $value = post($key);
            if ($key === 'currency' && $value === '') {
                $value = '$';
            }
            if ($key === 'ussd_prefix' && $value === '') {
                $value = '*789*';
            }
            if ($key === 'merchant_id') {
                $value = preg_replace('/[^0-9]/', '', $value) ?? '';
            }
            db_exec(
                'INSERT INTO settings (company_id, setting_key, setting_value) VALUES (?,?,?)
                 ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)',
                [company_id(), $key, $value]
            );
        }

        foreach ($CHECKBOXES as $key => $meta) {
            db_exec(
                'INSERT INTO settings (company_id, setting_key, setting_value) VALUES (?,?,?)
                 ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)',
                [company_id(), $key, isset($_POST[$key]) ? '1' : '0']
            );
        }

        $oldRate = vat_rate();
        $newRate = round(min(max((float)post('tax_percent'), 0), 100), 2);
        db_exec(
            'INSERT INTO settings (company_id, setting_key, setting_value) VALUES (?,?,?)
             ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)',
            [company_id(), 'tax_percent', (string)$newRate]
        );

        $repriced = 0;
        if (abs($newRate - $oldRate) > 0.0001) {
            $SETTINGS['tax_percent'] = (string)$newRate;
            $repriced = reprice_open_orders();
        }

        // Ticks "Set your shop details" on the dashboard's getting-started list.
        db_exec(
            'INSERT INTO settings (company_id, setting_key, setting_value) VALUES (?,?,?)
             ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)',
            [company_id(), 'settings_saved_at', date('Y-m-d H:i:s')]
        );

        $conn->commit();
        flash('Settings saved.');

        if (abs($newRate - $oldRate) > 0.0001) {
            flash('VAT is now ' . vat_label($newRate) . ' (was ' . vat_label($oldRate) . ').'
                . ($repriced ? " $repriced unpaid bill(s) re-priced at the new rate; paid orders are unchanged." : ''),
                'info');
        }
        if ($newRate > 0 && $newRate < 1) {
            $meant = $newRate * 100;
            flash('VAT is set to ' . vat_label($newRate) . ', which is less than one percent. '
                . 'If you meant ' . vat_label($meant) . ', type '
                . rtrim(rtrim(number_format($meant, 2, '.', ''), '0'), '.') . ' in the VAT box.', 'warning');
        }
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
<div class="grid grid-cols-1 lg:grid-cols-2 gap-4">
    <div class="space-y-4">
        <div class="card">
            <div class="card-header">Shop details</div>
            <div class="card-body space-y-3">
                <?php foreach ($SHOP_FIELDS as $key => [$label, $type, $help]): ?>
                    <div>
                        <label class="label"><?= e($label) ?></label>
                        <input type="<?= $type ?>" name="<?= e($key) ?>" class="input"
                               <?= $type === 'number' ? 'step="0.01" min="0" max="100"' : 'maxlength="120"' ?>
                               value="<?= e(setting($key)) ?>">
                    </div>
                <?php endforeach; ?>
            </div>
        </div>

        <div class="card">
            <div class="card-header">VAT</div>
            <div class="card-body space-y-3">
                <div>
                    <label class="label" for="set_vat">VAT rate</label>
                    <div class="flex max-w-[200px]">
                        <input type="number" name="tax_percent" id="set_vat" class="input rounded-r-none text-right"
                               step="0.01" min="0" max="100"
                               value="<?= e(rtrim(rtrim(number_format(vat_rate(), 2, '.', ''), '0'), '.')) ?>">
                        <span class="px-3 flex items-center bg-brand-light border border-l-0 border-line rounded-r-lg text-muted text-sm">%</span>
                    </div>
                    <p class="form-text">Enter the percentage: <strong>5</strong> for 5%. Enter <strong>0</strong> for no VAT.</p>
                </div>
                <div class="bg-brand-light/50 rounded-lg px-4 py-3 text-sm" id="vatPreview">
                    <?php $ex = vat_amount(2.00, vat_rate()); ?>
                    <?= e(money(2)) ?> of food + VAT <?= e(money($ex)) ?>
                    = customer pays <strong><?= e(money(2 + $ex)) ?></strong>
                </div>
            </div>
        </div>

        <div class="card">
            <div class="card-header">Mobile money merchant account</div>
            <div class="card-body space-y-3">
                <p class="text-muted text-sm">
                    Every bill and receipt prints a dial code the customer can enter on their
                    phone to pay you directly:
                    <code class="bg-brand-light px-1 rounded">*789*&lt;merchant number&gt;*&lt;amount&gt;#</code>
                </p>

                <?php foreach ($MERCHANT_FIELDS as $key => [$label, $type, $help]): ?>
                    <div>
                        <label class="label"><?= e($label) ?></label>
                        <input type="<?= $type ?>" name="<?= e($key) ?>" class="input"
                               id="set_<?= e($key) ?>" maxlength="120"
                               value="<?= e(setting($key, $key === 'ussd_prefix' ? '*789*' : '')) ?>">
                        <?php if ($help !== ''): ?>
                            <p class="form-text"><?= e($help) ?></p>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>

                <?php foreach ($CHECKBOXES as $key => [$label, $help]): ?>
                    <label class="flex items-start gap-2 cursor-pointer">
                        <input type="checkbox" class="checkbox mt-0.5" id="cb_<?= e($key) ?>"
                               name="<?= e($key) ?>" <?= setting($key, '1') === '1' ? 'checked' : '' ?>>
                        <span>
                            <span class="text-sm font-medium"><?= e($label) ?></span>
                            <p class="form-text"><?= e($help) ?></p>
                        </span>
                    </label>
                <?php endforeach; ?>

                <div class="bg-brand-light/50 rounded-lg px-4 py-3">
                    <div class="text-xs text-muted mb-1">Printed on a <?= e(money(6)) ?> order:</div>
                    <div id="ussdPreview" class="font-mono text-lg font-bold">
                        <?= e(merchant_ussd(6.00) ?? 'No merchant number set — nothing will print.') ?>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="space-y-4">
        <div class="card">
            <div class="card-header">About this install</div>
            <div class="card-body text-sm space-y-2">
                <p class="text-muted">VAT is added on top of every order at the rate set here. Changing the
                   rate re-prices <strong>unpaid</strong> bills; paid receipts keep the rate they were sold at.</p>
                <table class="tbl">
                    <tr><td>PHP</td><td class="text-right"><?= e(PHP_VERSION) ?></td></tr>
                    <tr><td>Database</td><td class="text-right"><?= e($conn->server_info) ?></td></tr>
                    <tr><td>Menu items</td><td class="text-right"><?= (int)db_value('SELECT COUNT(*) FROM menu_items WHERE company_id = ?', [company_id()]) ?></td></tr>
                    <tr><td>Orders recorded</td><td class="text-right"><?= (int)db_value('SELECT COUNT(*) FROM orders WHERE company_id = ?', [company_id()]) ?></td></tr>
                    <tr><td>Staff accounts</td><td class="text-right"><?= (int)db_value('SELECT COUNT(*) FROM users WHERE company_id = ?', [company_id()]) ?></td></tr>
                </table>
            </div>
        </div>

        <div class="card">
            <div class="card-header">How the dial code prints</div>
            <div class="card-body">
                <div class="receipt" style="width:auto;margin:0;">
                    <div class="rule"></div>
                    <div class="text-center">PAY BY MOBILE MONEY</div>
                    <?php if (setting('merchant_name') !== ''): ?>
                        <div class="text-center"><?= e(setting('merchant_name')) ?></div>
                    <?php endif; ?>
                    <div class="pay-code"><?= e(merchant_ussd(6.00) ?? '*789*…*6.00#') ?></div>
                    <div class="text-center text-xs">Dial the code above to pay <?= e(money(6)) ?></div>
                    <div class="rule"></div>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="mt-4 mb-6">
    <button class="btn btn-brand btn-lg">Save settings</button>
</div>
</form>

<?php
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

(function () {
    var box = document.getElementById("set_vat");
    var out = document.getElementById("vatPreview");
    if (!box || !out) { return; }
    var cur = ' . json_encode(setting('currency', '$')) . ';
    function m(n) { return cur + n.toFixed(2); }
    box.addEventListener("input", function () {
        var rate = Math.max(0, Number(box.value) || 0);
        var vat  = Math.round(200 * rate / 100) / 100;
        var note = rate > 0 && rate < 1 ? "  (less than 1% — for five percent type 5)" : "";
        out.textContent = m(2) + " of food + VAT " + m(vat) + " = customer pays " + m(2 + vat) + note;
    });
}());
</script>';
require __DIR__ . '/../core/footer.php';
