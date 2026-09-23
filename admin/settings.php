<?php
/** Shop settings that appear on screens and receipts. */

require_once __DIR__ . '/../core/config.php';
require_role('admin');

// key => [label, input type, help text]
$SHOP_FIELDS = [
    'shop_name'      => [__('set.f_shop_name'), 'text', ''],
    'shop_tagline'   => [__('set.f_tagline'), 'text', ''],
    'shop_address'   => [__('set.f_address'), 'text', ''],
    'shop_phone'     => [__('set.f_phone'), 'text', ''],
    'currency'       => [__('set.f_currency'), 'text', ''],
    'receipt_footer' => [__('set.f_footer'), 'text', ''],
];

$MERCHANT_FIELDS = [
    'merchant_name'  => [__('set.f_merchant_name'), 'text', __('set.h_merchant_name')],
    'merchant_id'    => [__('set.f_merchant_id'), 'text', __('set.h_merchant_id')],
    'ussd_prefix'    => [__('set.f_ussd'), 'text', __('set.h_ussd')],
];

$CHECKBOXES = [
    'merchant_on_receipt' => [__('set.cb_receipt'), __('set.cb_receipt_help')],
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
        flash(__('set.saved'));

        if (abs($newRate - $oldRate) > 0.0001) {
            flash(__('set.vat_changed', '', ['new' => vat_label($newRate), 'old' => vat_label($oldRate)])
                . ($repriced ? __('set.vat_repriced', '', ['n' => $repriced]) : ''),
                'info');
        }
        if ($newRate > 0 && $newRate < 1) {
            $meant = $newRate * 100;
            flash(__('set.vat_tiny', '', [
                'rate'  => vat_label($newRate),
                'meant' => vat_label($meant),
                'typed' => rtrim(rtrim(number_format($meant, 2, '.', ''), '0'), '.'),
            ]), 'warning');
        }
    } catch (Throwable $err) {
        $conn->rollback();
        flash(__('set.err_db', '', ['error' => $err->getMessage()]), 'danger');
    }

    redirect('admin/settings.php');
}

$pageTitle = __('nav.settings');
require __DIR__ . '/../core/header.php';
?>

<form method="post">
<?= csrf_field() ?>
<div class="grid grid-cols-1 lg:grid-cols-2 gap-4">
    <div class="space-y-4">
        <div class="card">
            <div class="card-header"><?= e(__('set.shop_details')) ?></div>
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
            <div class="card-header"><?= e(__('lbl.vat')) ?></div>
            <div class="card-body space-y-3">
                <div>
                    <label class="label" for="set_vat"><?= e(__('set.vat_rate')) ?></label>
                    <div class="flex max-w-[200px]">
                        <input type="number" name="tax_percent" id="set_vat" class="input rounded-r-none text-right"
                               step="0.01" min="0" max="100"
                               value="<?= e(rtrim(rtrim(number_format(vat_rate(), 2, '.', ''), '0'), '.')) ?>">
                        <span class="px-3 flex items-center bg-brand-light border border-l-0 border-line rounded-r-lg text-muted text-sm">%</span>
                    </div>
                    <p class="form-text"><?= strtr(e(__('set.vat_help')), ['{five}' => '<strong>5</strong>', '{zero}' => '<strong>0</strong>']) ?></p>
                </div>
                <div class="bg-brand-light/50 rounded-lg px-4 py-3 text-sm" id="vatPreview">
                    <?php $ex = vat_amount(2.00, vat_rate()); ?>
                    <?= strtr(e(__('set.vat_preview')), ['{food}' => e(money(2)), '{vat}' => e(money($ex)), '{total}' => '<strong>' . e(money(2 + $ex)) . '</strong>']) ?>
                </div>
            </div>
        </div>

        <div class="card">
            <div class="card-header"><?= e(__('set.merchant_card')) ?></div>
            <div class="card-body space-y-3">
                <p class="text-muted text-sm">
                    <?= e(__('set.merchant_intro')) ?>
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
                    <div class="text-xs text-muted mb-1"><?= e(__('set.printed_on', '', ['amount' => money(6)])) ?></div>
                    <div id="ussdPreview" class="font-mono text-lg font-bold">
                        <?= e(merchant_ussd(6.00) ?? __('set.no_merchant')) ?>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="space-y-4">
        <div class="card">
            <div class="card-header"><?= e(__('set.about')) ?></div>
            <div class="card-body text-sm space-y-2">
                <p class="text-muted"><?= strtr(e(__('set.about_text')), ['{unpaid}' => '<strong>' . e(__('set.unpaid')) . '</strong>']) ?></p>
                <table class="tbl">
                    <tr><td>PHP</td><td class="text-right"><?= e(PHP_VERSION) ?></td></tr>
                    <tr><td><?= e(__('set.database')) ?></td><td class="text-right"><?= e($conn->server_info) ?></td></tr>
                    <tr><td><?= e(__('nav.menu_items')) ?></td><td class="text-right"><?= (int)db_value('SELECT COUNT(*) FROM menu_items WHERE company_id = ?', [company_id()]) ?></td></tr>
                    <tr><td><?= e(__('set.orders_recorded')) ?></td><td class="text-right"><?= (int)db_value('SELECT COUNT(*) FROM orders WHERE company_id = ?', [company_id()]) ?></td></tr>
                    <tr><td><?= e(__('set.staff_accounts')) ?></td><td class="text-right"><?= (int)db_value('SELECT COUNT(*) FROM users WHERE company_id = ?', [company_id()]) ?></td></tr>
                </table>
            </div>
        </div>

        <div class="card">
            <div class="card-header"><?= e(__('set.how_prints')) ?></div>
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
    <button class="btn btn-brand btn-lg"><?= e(__('set.save')) ?></button>
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
            ? ' . json_encode(__('set.no_merchant')) . '
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
        var note = rate > 0 && rate < 1 ? ' . json_encode(__('set.vat_small_note')) . ' : "";
        var tpl  = ' . json_encode(__('set.vat_preview')) . ';
        out.textContent = tpl.replace("{food}", m(2)).replace("{vat}", m(vat)).replace("{total}", m(2 + vat)) + note;
    });
}());
</script>';
require __DIR__ . '/../core/footer.php';
