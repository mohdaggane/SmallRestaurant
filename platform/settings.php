<?php
/**
 * Platform settings management: owner can configure company name,
 * system name, contact phone, email, web name, URL, and logo.
 */

require_once __DIR__ . '/../core/config.php';
require_platform();

$error = '';

if (is_post()) {
    csrf_check();

    // Check for "remove logo" request
    if (post('action') === 'remove_logo') {
        db_run("INSERT INTO platform_settings (setting_key, setting_value) VALUES ('logo_url', '') ON DUPLICATE KEY UPDATE setting_value = ''");
        flash(__('ps.logo_reset'));
        redirect('platform/settings.php');
    }

    $companyName  = trim(post('company_name'));
    $systemName   = trim(post('system_name'));
    $contactPhone = trim(post('contact_phone'));
    $contactEmail = trim(post('contact_email'));
    $webName      = trim(post('web_name'));
    $webUrl       = trim(post('web_url'));
    $customLogoUrl = trim(post('logo_url'));

    if ($companyName === '') {
        $error = __('ps.err_company');
    } elseif ($systemName === '') {
        $error = __('ps.err_system');
    } else {
        $logoPath = $customLogoUrl;

        // Handle uploaded logo file if provided
        if (!empty($_FILES['logo_file']['name']) && $_FILES['logo_file']['error'] === UPLOAD_ERR_OK) {
            $tmpFile = $_FILES['logo_file']['tmp_name'];
            $origName = $_FILES['logo_file']['name'];
            $ext = strtolower(pathinfo($origName, PATHINFO_EXTENSION));
            $allowedExts = ['png', 'jpg', 'jpeg', 'svg', 'webp', 'gif'];

            if (!in_array($ext, $allowedExts, true)) {
                $error = __('ps.err_format');
            } else {
                $uploadsDir = __DIR__ . '/../uploads';
                if (!is_dir($uploadsDir)) {
                    mkdir($uploadsDir, 0755, true);
                }

                $fileName = 'platform_logo_' . time() . '.' . $ext;
                $targetPath = $uploadsDir . '/' . $fileName;

                if (move_uploaded_file($tmpFile, $targetPath)) {
                    $logoPath = 'uploads/' . $fileName;
                } else {
                    $error = __('ps.err_upload');
                }
            }
        }

        if ($error === '') {
            $settingsToSave = [
                'company_name'  => $companyName,
                'system_name'   => $systemName,
                'contact_phone' => $contactPhone,
                'contact_email' => $contactEmail,
                'web_name'      => $webName,
                'web_url'       => $webUrl,
            ];

            // Only update logo if a file was uploaded or direct URL was changed
            if ($logoPath !== '') {
                $settingsToSave['logo_url'] = $logoPath;
            }

            foreach ($settingsToSave as $k => $v) {
                db_run(
                    "INSERT INTO platform_settings (setting_key, setting_value) VALUES (?, ?)
                     ON DUPLICATE KEY UPDATE setting_value = ?",
                    [$k, $v, $v]
                );
            }

            flash(__('ps.saved'));
            redirect('platform/settings.php');
        }
    }
}

// Fetch fresh settings
$currentCompany = platform_setting('company_name', 'SAHAN ICT');
$currentSystem  = platform_setting('system_name', 'Restaurant POS');
$currentPhone   = platform_setting('contact_phone', '+252 61 5000000');
$currentEmail   = platform_setting('contact_email', 'info@sahanict.org');
$currentWebName = platform_setting('web_name', 'sahanict.org');
$currentWebUrl  = platform_setting('web_url', 'https://sahanict.org');
$currentLogo    = platform_setting('logo_url', '');

$pageTitle = __('ps.title');
$platform  = true;
require __DIR__ . '/../core/header.php';
?>

<div class="max-w-4xl mx-auto pb-10">

    <?php if ($error !== ''): ?>
        <div class="flash-danger mb-5"><?= e($error) ?></div>
    <?php endif; ?>

    <!-- Header info banner -->
    <div class="mb-6 p-5 rounded-2xl bg-white border border-line flex flex-col sm:flex-row sm:items-center justify-between gap-4 shadow-sm">
        <div>
            <h2 class="text-xl font-bold text-ink mb-1"><?= e(__('ps.heading')) ?></h2>
            <p class="text-muted text-xs sm:text-sm">
                <?= e(__('ps.intro')) ?>
            </p>
        </div>
        <div class="flex items-center gap-2">
            <a href="<?= url('') ?>" target="_blank" class="btn btn-outline btn-sm inline-flex items-center gap-1.5 whitespace-nowrap">
                <span><?= e(__('ps.view_landing')) ?></span>
                <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5">
                    <path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"></path>
                    <polyline points="15 3 21 3 21 9"></polyline>
                    <line x1="10" y1="14" x2="21" y2="3"></line>
                </svg>
            </a>
            <a href="<?= url('public/login.php') ?>" target="_blank" class="btn btn-outline btn-sm inline-flex items-center gap-1.5 whitespace-nowrap">
                <span><?= e(__('ps.staff_login')) ?></span>
            </a>
        </div>
    </div>

    <!-- Main Settings Form -->
    <form method="post" enctype="multipart/form-data" class="space-y-6">
        <?= csrf_field() ?>

        <!-- Brand & Identity Card -->
        <div class="card p-6 sm:p-7 rounded-2xl bg-white border border-line shadow-sm">
            <div class="flex items-center gap-2 pb-4 mb-5 border-b border-line">
                <span class="text-xl">🏷️</span>
                <h3 class="font-bold text-base text-ink m-0"><?= e(__('ps.sec_brand')) ?></h3>
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-5">
                <div>
                    <label class="label font-semibold text-xs uppercase tracking-wider text-muted" for="company_name">
                        <?= e(__('ps.company')) ?>
                    </label>
                    <input type="text" id="company_name" name="company_name" class="input input-lg w-full font-medium"
                           value="<?= e($currentCompany) ?>" placeholder="e.g. SAHAN ICT" required>
                    <small class="text-muted text-xs block mt-1"><?= e(__('ps.company_help')) ?></small>
                </div>

                <div>
                    <label class="label font-semibold text-xs uppercase tracking-wider text-muted" for="system_name">
                        <?= e(__('ps.system')) ?>
                    </label>
                    <input type="text" id="system_name" name="system_name" class="input input-lg w-full font-medium"
                           value="<?= e($currentSystem) ?>" placeholder="e.g. Restaurant POS" required>
                    <small class="text-muted text-xs block mt-1"><?= e(__('ps.system_help')) ?></small>
                </div>

                <!-- Logo Section -->
                <div class="sm:col-span-2 pt-3 border-t border-line">
                    <label class="label font-semibold text-xs uppercase tracking-wider text-muted block mb-2">
                        <?= e(__('ps.logo')) ?>
                    </label>
                    
                    <div class="flex flex-col sm:flex-row items-start sm:items-center gap-5 p-4 rounded-xl bg-slate-50 border border-slate-200">
                        
                        <!-- Logo Preview -->
                        <div class="flex-shrink-0 flex flex-col items-center gap-2">
                            <div class="w-16 h-16 rounded-2xl border border-slate-200 bg-white flex items-center justify-center p-2 shadow-sm overflow-hidden">
                                <?php if (platform_logo_url()): ?>
                                    <img src="<?= e(platform_logo_url()) ?>" alt="Platform Logo" class="max-w-full max-h-full object-contain">
                                <?php else: ?>
                                    <div class="w-full h-full rounded-xl flex items-center justify-center text-white" style="background: linear-gradient(135deg, #1e2f6e, #1a7fe8);">
                                        <svg width="24" height="24" viewBox="0 0 24 24" fill="none">
                                            <path d="M17 6s-2-2-5-2-5 1.8-5 4c0 2.5 2.5 3.5 5 4.5s5 2 5 4.5c0 2.2-2 3-5 3s-5-2-5-2"
                                                  stroke="#fff" stroke-width="2.6" stroke-linecap="round"/>
                                        </svg>
                                    </div>
                                <?php endif; ?>
                            </div>
                            <span class="text-[10px] text-muted font-bold uppercase tracking-wider">
                                <?= e(platform_logo_url() ? __('ps.custom_logo') : __('ps.default_badge')) ?>
                            </span>
                        </div>

                        <!-- Upload controls -->
                        <div class="flex-1 space-y-3">
                            <div>
                                <label class="text-xs font-semibold text-ink block mb-1"><?= e(__('ps.upload_logo')) ?></label>
                                <input type="file" name="logo_file" accept=".png,.jpg,.jpeg,.svg,.webp,.gif"
                                       class="block w-full text-xs text-slate-500 file:mr-3 file:py-2 file:px-3.5 file:rounded-xl file:border-0 file:text-xs file:font-semibold file:bg-blue-50 file:text-blue-700 hover:file:bg-blue-100 cursor-pointer">
                                <small class="text-muted text-[11px] block mt-1"><?= e(__('ps.logo_help')) ?></small>
                            </div>

                            <div class="flex items-center gap-3 pt-1">
                                <div class="flex-1">
                                    <input type="text" name="logo_url" class="input input-sm w-full text-xs font-mono"
                                           value="<?= e($currentLogo) ?>" placeholder="<?= e(__('ps.logo_url_ph')) ?>">
                                </div>
                                <?php if ($currentLogo !== ''): ?>
                                    <button type="submit" name="action" value="remove_logo"
                                            class="btn btn-outline btn-sm text-xs text-rose-600 border-rose-200 hover:bg-rose-50 whitespace-nowrap"
                                            onclick="return confirm(<?= e(json_encode(__('ps.remove_confirm'))) ?>);">
                                        <?= e(__('ps.remove_logo')) ?>
                                    </button>
                                <?php endif; ?>
                            </div>
                        </div>

                    </div>
                </div>
            </div>
        </div>

        <!-- Contact & Web Information Card -->
        <div class="card p-6 sm:p-7 rounded-2xl bg-white border border-line shadow-sm">
            <div class="flex items-center gap-2 pb-4 mb-5 border-b border-line">
                <span class="text-xl">🌐</span>
                <h3 class="font-bold text-base text-ink m-0"><?= e(__('ps.sec_contact')) ?></h3>
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-5">
                <div>
                    <label class="label font-semibold text-xs uppercase tracking-wider text-muted" for="contact_phone">
                        <?= e(__('ps.phone')) ?>
                    </label>
                    <input type="text" id="contact_phone" name="contact_phone" class="input input-lg w-full font-medium"
                           value="<?= e($currentPhone) ?>" placeholder="+252 61 0000000">
                    <small class="text-muted text-xs block mt-1"><?= e(__('ps.phone_help')) ?></small>
                </div>

                <div>
                    <label class="label font-semibold text-xs uppercase tracking-wider text-muted" for="contact_email">
                        <?= e(__('ps.email')) ?>
                    </label>
                    <input type="email" id="contact_email" name="contact_email" class="input input-lg w-full font-medium"
                           value="<?= e($currentEmail) ?>" placeholder="info@sahanict.org">
                    <small class="text-muted text-xs block mt-1"><?= e(__('ps.email_help')) ?></small>
                </div>

                <div>
                    <label class="label font-semibold text-xs uppercase tracking-wider text-muted" for="web_name">
                        <?= e(__('ps.web_name')) ?>
                    </label>
                    <input type="text" id="web_name" name="web_name" class="input input-lg w-full font-medium"
                           value="<?= e($currentWebName) ?>" placeholder="sahanict.org">
                    <small class="text-muted text-xs block mt-1"><?= e(__('ps.web_name_help')) ?></small>
                </div>

                <div>
                    <label class="label font-semibold text-xs uppercase tracking-wider text-muted" for="web_url">
                        <?= e(__('ps.web_url')) ?>
                    </label>
                    <input type="url" id="web_url" name="web_url" class="input input-lg w-full font-medium"
                           value="<?= e($currentWebUrl) ?>" placeholder="https://sahanict.org">
                    <small class="text-muted text-xs block mt-1"><?= e(__('ps.web_url_help')) ?></small>
                </div>
            </div>
        </div>

        <!-- Live Brand Badge Preview Card -->
        <div class="card p-6 sm:p-7 rounded-2xl bg-white border border-line shadow-sm">
            <div class="flex items-center justify-between pb-4 mb-4 border-b border-line">
                <div class="flex items-center gap-2">
                    <span class="text-xl">👁️</span>
                    <h3 class="font-bold text-base text-ink m-0"><?= e(__('ps.sec_preview')) ?></h3>
                </div>
                <span class="text-xs text-muted"><?= e(__('ps.preview_help')) ?></span>
            </div>

            <div class="p-6 rounded-2xl bg-slate-50 border border-slate-200 flex flex-col sm:flex-row items-center justify-between gap-6">
                <div class="flex items-center gap-3.5">
                    <div id="previewLogoBox" class="w-12 h-12 rounded-xl flex items-center justify-center shadow-md bg-white border border-slate-200 p-1 overflow-hidden">
                        <?php if (platform_logo_url()): ?>
                            <img src="<?= e(platform_logo_url()) ?>" alt="Logo" class="max-w-full max-h-full object-contain">
                        <?php else: ?>
                            <div class="w-full h-full rounded-lg flex items-center justify-center text-white" style="background: linear-gradient(135deg, #1e2f6e, #1a7fe8);">
                                <svg width="20" height="20" viewBox="0 0 24 24" fill="none">
                                    <path d="M17 6s-2-2-5-2-5 1.8-5 4c0 2.5 2.5 3.5 5 4.5s5 2 5 4.5c0 2.2-2 3-5 3s-5-2-5-2"
                                          stroke="#fff" stroke-width="2.6" stroke-linecap="round"/>
                                </svg>
                            </div>
                        <?php endif; ?>
                    </div>
                    <div>
                        <div id="previewCompany" class="font-black text-lg text-slate-900 leading-tight">
                            <?= e($currentCompany) ?>
                        </div>
                        <div id="previewSystem" class="text-xs font-bold tracking-widest uppercase text-blue-600">
                            <?= e($currentSystem) ?>
                        </div>
                    </div>
                </div>

                <div class="text-right text-xs text-slate-500 border-t sm:border-t-0 sm:border-l border-slate-200 pt-3 sm:pt-0 sm:pl-6">
                    <div><?= e(__('ps.lbl_website')) ?> <strong id="previewWeb" class="text-blue-700 font-semibold"><?= e($currentWebName) ?></strong></div>
                    <div class="mt-0.5"><?= e(__('ps.lbl_phone')) ?> <span id="previewPhone" class="text-slate-700"><?= e($currentPhone) ?></span></div>
                    <div class="mt-0.5"><?= e(__('ps.lbl_email')) ?> <span id="previewEmail" class="text-slate-700"><?= e($currentEmail) ?></span></div>
                </div>
            </div>
        </div>

        <!-- Submit Button -->
        <div class="flex items-center justify-end gap-3 pt-2">
            <button type="submit" class="btn btn-brand btn-lg px-8 font-bold shadow-md hover:shadow-lg transition">
                <span><?= e(__('ps.save')) ?></span>
            </button>
        </div>

    </form>

</div>

<script>
    // Live preview update as user types
    const companyInput = document.getElementById('company_name');
    const systemInput  = document.getElementById('system_name');
    const phoneInput   = document.getElementById('contact_phone');
    const emailInput   = document.getElementById('contact_email');
    const webInput     = document.getElementById('web_name');

    const prevCompany = document.getElementById('previewCompany');
    const prevSystem  = document.getElementById('previewSystem');
    const prevPhone   = document.getElementById('previewPhone');
    const prevEmail   = document.getElementById('previewEmail');
    const prevWeb     = document.getElementById('previewWeb');

    if (companyInput && prevCompany) {
        companyInput.addEventListener('input', () => prevCompany.textContent = companyInput.value || 'SAHAN ICT');
    }
    if (systemInput && prevSystem) {
        systemInput.addEventListener('input', () => prevSystem.textContent = systemInput.value || 'Restaurant POS');
    }
    if (phoneInput && prevPhone) {
        phoneInput.addEventListener('input', () => prevPhone.textContent = phoneInput.value || '—');
    }
    if (emailInput && prevEmail) {
        emailInput.addEventListener('input', () => prevEmail.textContent = emailInput.value || '—');
    }
    if (webInput && prevWeb) {
        webInput.addEventListener('input', () => prevWeb.textContent = webInput.value || '—');
    }
</script>

<?php require __DIR__ . '/../core/footer.php'; ?>
