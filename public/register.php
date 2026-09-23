<?php
/**
 * Self sign-up for a new restaurant. Creates, in one transaction:
 *   the company (on the trial plan), its admin account, its default settings
 *   and, if asked, a few starter menu categories
 * and then signs the owner straight in to the admin dashboard.
 *
 * Usernames are unique across every restaurant (login finds the company from
 * the username), so the form suggests names that end in the restaurant's slug.
 */

require_once __DIR__ . '/../core/config.php';

if (is_logged_in()) {
    redirect(home_for_role(user_role()));
}

/** "Hodan Café & Tea" -> "hodan-cafe-tea" */
function make_slug(string $text): string
{
    $ascii = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $text) ?: $text;
    // Some iconv builds spell é as 'e or e' — drop the stray accent marks.
    $ascii = str_replace(["'", '"', '`', '^', '~'], '', $ascii);
    $slug  = trim((string)preg_replace('/[^a-z0-9]+/', '-', strtolower($ascii)), '-');
    return substr($slug, 0, 30);
}

$STARTER_CATEGORIES = array_map(fn($k) => __('reg.cat_' . $k), ['hot', 'cold', 'breakfast', 'mains', 'snacks']);

$f = [
    'company_name' => '', 'slug' => '', 'phone' => '',
    'full_name' => '', 'username' => '', 'starter' => '1',
];
$errors = [];

if (is_post()) {
    csrf_check();
    foreach (array_keys($f) as $k) {
        $f[$k] = post($k);
    }
    $f['starter']  = isset($_POST['starter']) ? '1' : '';
    $f['username'] = strtolower($f['username']);   // as admin/users.php stores them
    $f['slug']    = make_slug($f['slug'] !== '' ? $f['slug'] : $f['company_name']);
    $password     = (string)($_POST['password'] ?? '');
    $password2    = (string)($_POST['password2'] ?? '');

    // One sign-up per browser session a minute: stops a script from filling the table.
    $lastAt = (int)($_SESSION['registered_at'] ?? 0);

    if (post('website') !== '') {
        $errors[] = __('reg.err_refused');          // honeypot: only bots fill the hidden field
    } elseif ($lastAt > time() - 60) {
        $errors[] = __('reg.err_wait');
    }
    if (mb_strlen($f['company_name']) < 2 || mb_strlen($f['company_name']) > 100) {
        $errors[] = __('reg.err_name');
    }
    if (strlen($f['slug']) < 3) {
        $errors[] = __('reg.err_slug_short');
    } elseif (db_value('SELECT id FROM companies WHERE slug = ?', [$f['slug']]) !== null) {
        $errors[] = __('reg.err_slug_taken', '', ['slug' => $f['slug']]);
    }
    if ($f['full_name'] === '') {
        $errors[] = __('reg.err_full_name');
    }
    if (!preg_match('/^[A-Za-z0-9._@-]{3,50}$/', $f['username'])) {
        $errors[] = __('reg.err_username');
    } elseif (db_value('SELECT id FROM users WHERE username = ?', [$f['username']]) !== null) { // tenant-global: usernames are unique system-wide
        $errors[] = __('reg.err_username_taken', '', [
            'username' => $f['username'],
            'suggest'  => $f['username'] . '.' . ($f['slug'] ?: 'yourshop'),
        ]);
    }
    if (strlen($password) < 6) {
        $errors[] = __('reg.err_pw_short');
    } elseif ($password !== $password2) {
        $errors[] = __('reg.err_pw_match');
    }

    if (!$errors) {
        $conn->begin_transaction();
        try {
            $companyId = db_exec(
                "INSERT INTO companies (name, slug, phone, plan_id, status, trial_ends_at)
                 VALUES (?,?,?,?, 'trial', ?)",
                [$f['company_name'], $f['slug'], $f['phone'] !== '' ? $f['phone'] : null,
                 TRIAL_PLAN_ID, date('Y-m-d', strtotime('+' . TRIAL_DAYS . ' days'))]
            );
            $userId = db_exec(
                "INSERT INTO users (company_id, full_name, username, password_hash, role) VALUES (?,?,?,?, 'admin')",
                [$companyId, $f['full_name'], $f['username'], password_hash($password, PASSWORD_DEFAULT)]
            );
            seed_company_settings($companyId, $f['company_name'], $f['phone']);
            if ($f['starter'] === '1') {
                foreach ($STARTER_CATEGORIES as $i => $cat) {
                    db_exec('INSERT INTO categories (company_id, name, sort_order) VALUES (?,?,?)', [$companyId, $cat, $i + 1]);
                }
            }
            $conn->commit();
        } catch (mysqli_sql_exception $e) {
            $conn->rollback();
            // 1062 = duplicate key: someone took the name or username a moment ago.
            $errors[] = $e->getCode() === 1062
                ? __('reg.err_dup')
                : __('reg.err_create', '', ['error' => $e->getMessage()]);
        }

        if (!$errors) {
            $_SESSION['registered_at'] = time();
            login_user([
                'id' => $userId, 'company_id' => $companyId, 'company_name' => $f['company_name'],
                'full_name' => $f['full_name'], 'username' => $f['username'], 'role' => 'admin',
            ]);
            flash(__('reg.welcome', '', ['name' => $f['company_name'], 'days' => TRIAL_DAYS]));
            redirect('admin/index.php');
        }
    }
}

$pageTitle = __('reg.page_title');
$layout    = 'blank';
require __DIR__ . '/../core/header.php';

$companyName = platform_setting('company_name', 'SAHAN ICT');
$systemName  = platform_setting('system_name', 'Restaurant POS');
$webName     = platform_setting('web_name', 'sahanict.org');
$webUrl      = platform_setting('web_url', 'https://sahanict.org');
$logoUrl     = platform_logo_url();
?>
<style>
    :root {
        --sn: #1e2f6e;
        --sb: #1a7fe8;
        --sn2: #152257;
        --sb2: #1567c4;
    }
    .reg-brand-bg {
        background: radial-gradient(100% 100% at 50% 0%, #deeafb 0%, #edf4fc 40%, #f8fafc 100%);
    }
    .btn-reg-brand {
        background: linear-gradient(135deg, var(--sn) 0%, var(--sb) 100%);
        box-shadow: 0 4px 18px rgba(26, 127, 232, 0.32);
        transition: all 0.2s cubic-bezier(0.4, 0, 0.2, 1);
    }
    .btn-reg-brand:hover {
        background: linear-gradient(135deg, var(--sn2) 0%, var(--sb2) 100%);
        box-shadow: 0 6px 24px rgba(26, 127, 232, 0.45);
        transform: translateY(-1px);
    }
    .btn-reg-brand:active {
        transform: translateY(0);
    }
    .input-clean {
        transition: border-color 0.2s, box-shadow 0.2s;
    }
    .input-clean:focus {
        border-color: var(--sb);
        box-shadow: 0 0 0 4px rgba(26, 127, 232, 0.12);
    }
</style>

<div class="min-h-screen reg-brand-bg py-8 sm:py-12 px-4 sm:px-6 flex flex-col justify-between font-sans antialiased">
    <div class="w-full max-w-xl mx-auto my-auto">

        <!-- Top Navigation / Logo -->
        <div class="flex items-center justify-between mb-6">
            <a href="<?= url('') ?>" class="flex items-center gap-2.5 no-underline">
                <?php if ($logoUrl !== ''): ?>
                    <img src="<?= e($logoUrl) ?>" alt="Logo" class="w-9 h-9 rounded-xl object-contain shadow-sm bg-white p-0.5 border border-slate-200">
                <?php else: ?>
                    <div class="w-9 h-9 rounded-xl flex items-center justify-center shadow-md text-white" style="background: linear-gradient(135deg, var(--sn), var(--sb));">
                        <svg width="20" height="20" viewBox="0 0 24 24" fill="none">
                            <path d="M17 6s-2-2-5-2-5 1.8-5 4c0 2.5 2.5 3.5 5 4.5s5 2 5 4.5c0 2.2-2 3-5 3s-5-2-5-2"
                                  stroke="#fff" stroke-width="2.5" stroke-linecap="round"/>
                        </svg>
                    </div>
                <?php endif; ?>
                <div>
                    <div class="font-extrabold text-sm tracking-tight" style="color:var(--sn);"><?= e($companyName) ?></div>
                    <div class="text-[9px] font-bold tracking-widest uppercase" style="color:var(--sb);"><?= e($systemName) ?></div>
                </div>
            </a>

            <a href="<?= url('') ?>" class="inline-flex items-center gap-1.5 text-xs font-semibold text-slate-600 hover:text-blue-700 bg-white border border-slate-200 px-3 py-1.5 rounded-lg shadow-sm">
                <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round">
                    <line x1="19" y1="12" x2="5" y2="12"></line>
                    <polyline points="12 19 5 12 12 5"></polyline>
                </svg>
                <span><?= e(__('auth.back_home')) ?></span>
            </a>
        </div>

        <!-- Registration Card -->
        <div class="bg-white rounded-3xl p-6 sm:p-9 shadow-xl shadow-slate-200/80 border border-slate-100">
            <div class="mb-6">
                <div class="inline-flex items-center gap-1.5 px-3 py-1 rounded-full text-xs font-semibold text-blue-700 bg-blue-50 border border-blue-100 mb-3">
                    <span class="w-1.5 h-1.5 rounded-full bg-blue-600"></span>
                    <span><?= e(__('reg.trial_badge', '', ['days' => TRIAL_DAYS])) ?></span>
                </div>
                <h1 class="text-2xl sm:text-3xl font-extrabold text-slate-900 tracking-tight mb-1.5">
                    <?= e(__('reg.title')) ?>
                </h1>
                <p class="text-slate-500 text-sm leading-relaxed">
                    <?= e(__('reg.sub')) ?>
                </p>
            </div>

            <?php if (!empty($errors)): ?>
                <div class="mb-5 space-y-2">
                    <?php foreach ($errors as $err): ?>
                        <div class="flex items-start gap-2.5 p-3.5 rounded-xl bg-rose-50 border border-rose-200 text-rose-800 text-xs sm:text-sm font-medium">
                            <svg class="w-4 h-4 text-rose-500 flex-shrink-0 mt-0.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <circle cx="12" cy="12" r="10"></circle>
                                <line x1="12" y1="8" x2="12" y2="12"></line>
                                <line x1="12" y1="16" x2="12.01" y2="16"></line>
                            </svg>
                            <span><?= e($err) ?></span>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <form method="post" autocomplete="off" class="space-y-5">
                <?= csrf_field() ?>
                <!-- honeypot -->
                <input type="text" name="website" value="" tabindex="-1" autocomplete="off"
                       style="position:absolute;left:-9999px" aria-hidden="true">

                <!-- Company Details Section -->
                <div>
                    <h3 class="text-xs font-bold uppercase tracking-wider text-slate-400 mb-3">
                        <?= e(__('reg.sec_restaurant')) ?>
                    </h3>
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                        <div class="sm:col-span-2">
                            <label class="block text-xs font-semibold text-slate-700 mb-1.5" for="company_name">
                                <?= e(__('reg.restaurant_name')) ?>
                            </label>
                            <input type="text" id="company_name" name="company_name"
                                   class="input-clean block w-full px-3.5 py-2.5 bg-slate-50 focus:bg-white text-slate-900 text-sm rounded-xl border border-slate-200 outline-none"
                                   maxlength="100" value="<?= e($f['company_name']) ?>" placeholder="<?= e(__('reg.ph_name')) ?>" required autofocus>
                        </div>
                        <div>
                            <label class="block text-xs font-semibold text-slate-700 mb-1.5" for="slug">
                                <?= e(__('reg.slug')) ?>
                            </label>
                            <input type="text" id="slug" name="slug"
                                   class="input-clean block w-full px-3.5 py-2.5 bg-slate-50 focus:bg-white text-slate-900 text-sm rounded-xl border border-slate-200 outline-none"
                                   maxlength="30" value="<?= e($f['slug']) ?>" placeholder="bluesea-cafe">
                            <small class="text-slate-400 text-[11px] block mt-1"><?= e(__('reg.slug_help')) ?></small>
                        </div>
                        <div>
                            <label class="block text-xs font-semibold text-slate-700 mb-1.5" for="phone">
                                <?= e(__('reg.phone')) ?>
                            </label>
                            <input type="text" id="phone" name="phone"
                                   class="input-clean block w-full px-3.5 py-2.5 bg-slate-50 focus:bg-white text-slate-900 text-sm rounded-xl border border-slate-200 outline-none"
                                   maxlength="40" value="<?= e($f['phone']) ?>" placeholder="+252 ...">
                        </div>
                    </div>
                </div>

                <div class="border-t border-slate-100 pt-5">
                    <h3 class="text-xs font-bold uppercase tracking-wider text-slate-400 mb-3">
                        <?= e(__('reg.sec_admin')) ?>
                    </h3>
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                        <div class="sm:col-span-2">
                            <label class="block text-xs font-semibold text-slate-700 mb-1.5" for="full_name">
                                <?= e(__('reg.full_name')) ?>
                            </label>
                            <input type="text" id="full_name" name="full_name"
                                   class="input-clean block w-full px-3.5 py-2.5 bg-slate-50 focus:bg-white text-slate-900 text-sm rounded-xl border border-slate-200 outline-none"
                                   maxlength="100" value="<?= e($f['full_name']) ?>" placeholder="<?= e(__('reg.ph_full_name')) ?>" required>
                        </div>
                        <div class="sm:col-span-2">
                            <label class="block text-xs font-semibold text-slate-700 mb-1.5" for="username">
                                <?= e(__('reg.username')) ?>
                            </label>
                            <input type="text" id="username" name="username"
                                   class="input-clean block w-full px-3.5 py-2.5 bg-slate-50 focus:bg-white text-slate-900 text-sm rounded-xl border border-slate-200 outline-none"
                                   maxlength="50" value="<?= e($f['username']) ?>" placeholder="ahmed.bluesea" required>
                            <small class="text-slate-400 text-[11px] block mt-1"><?= e(__('reg.username_help')) ?></small>
                        </div>
                        <div>
                            <label class="block text-xs font-semibold text-slate-700 mb-1.5" for="password">
                                <?= e(__('reg.password')) ?>
                            </label>
                            <input type="password" id="password" name="password"
                                   class="input-clean block w-full px-3.5 py-2.5 bg-slate-50 focus:bg-white text-slate-900 text-sm rounded-xl border border-slate-200 outline-none"
                                   minlength="6" placeholder="<?= e(__('reg.ph_password')) ?>" required>
                        </div>
                        <div>
                            <label class="block text-xs font-semibold text-slate-700 mb-1.5" for="password2">
                                <?= e(__('reg.password2')) ?>
                            </label>
                            <input type="password" id="password2" name="password2"
                                   class="input-clean block w-full px-3.5 py-2.5 bg-slate-50 focus:bg-white text-slate-900 text-sm rounded-xl border border-slate-200 outline-none"
                                   minlength="6" placeholder="<?= e(__('reg.ph_password2')) ?>" required>
                        </div>
                    </div>
                </div>

                <div class="bg-slate-50 border border-slate-100 rounded-xl p-3.5">
                    <label class="flex items-start gap-2.5 text-xs sm:text-sm text-slate-700 cursor-pointer">
                        <input type="checkbox" name="starter" value="1" <?= $f['starter'] === '1' ? 'checked' : '' ?> class="mt-0.5 rounded text-blue-600 focus:ring-blue-500">
                        <span><?= e(__('reg.starter')) ?></span>
                    </label>
                </div>

                <button type="submit" class="btn-reg-brand w-full flex items-center justify-center gap-2 py-3.5 px-4 text-white text-sm font-bold rounded-xl cursor-pointer">
                    <span><?= e(__('reg.submit')) ?></span>
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round">
                        <line x1="5" y1="12" x2="19" y2="12"></line>
                        <polyline points="12 5 19 12 12 19"></polyline>
                    </svg>
                </button>
            </form>

            <div class="mt-6 text-center text-xs text-slate-500">
                <?= e(__('reg.already')) ?> <a href="<?= url('public/login.php') ?>" class="font-bold text-blue-700 hover:underline"><?= e(__('reg.sign_in_link')) ?></a>
            </div>
        </div>

    </div>

    <!-- Footer -->
    <footer class="text-center text-xs text-slate-500 pt-6">
        <?= e(__('auth.made_by')) ?> <a href="<?= e($webUrl) ?>" target="_blank" rel="noopener noreferrer" class="font-bold text-slate-800 hover:text-blue-600 transition-colors"><?= e($companyName) ?></a> ·
        <a href="<?= e($webUrl) ?>" target="_blank" rel="noopener noreferrer" class="text-slate-500 hover:text-blue-600 transition-colors"><?= e($webName) ?></a>
    </footer>
</div>
<script>
// Suggest the short name and a username from the restaurant name as it is typed.
(function () {
    var name = document.getElementById('company_name'),
        slug = document.getElementById('slug'),
        user = document.getElementById('username'),
        touched = slug.value !== '';
    slug.addEventListener('input', function () { touched = true; });
    name.addEventListener('input', function () {
        if (touched) return;
        slug.value = name.value.normalize('NFD').replace(/[̀-ͯ]/g, '')
            .toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/^-+|-+$/g, '').slice(0, 30);
        user.placeholder = 'yourname.' + (slug.value || 'hodan-cafe');
    });
})();
</script>
<?php require __DIR__ . '/../core/footer.php'; ?>
