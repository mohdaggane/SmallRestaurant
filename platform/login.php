<?php
/** Sign-in for the platform owner (platform_admins), separate from restaurant logins. */

require_once __DIR__ . '/../core/config.php';

if (platform_user()) {
    redirect('platform/index.php');
}

$error    = '';
$username = '';

if (is_post()) {
    csrf_check();
    $username = post('username');
    $password = (string)($_POST['password'] ?? '');

    // Same throttle as the restaurant login: 5 failures locks the form for 60 seconds.
    $lockedUntil = (int)($_SESSION['platform_locked_until'] ?? 0);
    if ($lockedUntil > time()) {
        $error = __('auth.err_locked', '', ['s' => $lockedUntil - time()]);
    } else {
        $admin = db_one('SELECT * FROM platform_admins WHERE username = ? AND is_active = 1 LIMIT 1', [$username]);
        if ($admin && password_verify($password, $admin['password_hash'])) {
            unset($_SESSION['platform_fails'], $_SESSION['platform_locked_until']);
            platform_login($admin);
            redirect('platform/index.php');
        }
        $fails = (int)($_SESSION['platform_fails'] ?? 0) + 1;
        $_SESSION['platform_fails'] = $fails;
        if ($fails >= 5) {
            $_SESSION['platform_locked_until'] = time() + 60;
            $_SESSION['platform_fails'] = 0;
        }
        $error = __('auth.err_wrong');
    }
}

$pageTitle = __('plogin.page_title');
$layout    = 'blank';
$platform  = true;
require __DIR__ . '/../core/header.php';

$companyName = platform_setting('company_name', 'SAHAN ICT');
$webName     = platform_setting('web_name', 'sahanict.org');
$webUrl      = platform_setting('web_url', 'https://sahanict.org');
?>
<style>
    :root {
        --sn: #1e2f6e;   /* navy */
        --sb: #1a7fe8;   /* blue */
        --sn2: #152257;  /* dark navy */
        --sb2: #1567c4;  /* dark blue */
        --sl: #e8f1fd;   /* pale blue */
    }

    .platform-bg {
        background: radial-gradient(100% 100% at 50% 0%, #deeafb 0%, #edf4fc 40%, #f8fafc 100%);
    }

    .btn-platform-brand {
        background: linear-gradient(135deg, var(--sn) 0%, var(--sb) 100%);
        box-shadow: 0 4px 18px rgba(26, 127, 232, 0.32);
        transition: all 0.2s cubic-bezier(0.4, 0, 0.2, 1);
    }
    .btn-platform-brand:hover {
        background: linear-gradient(135deg, var(--sn2) 0%, var(--sb2) 100%);
        box-shadow: 0 6px 24px rgba(26, 127, 232, 0.45);
        transform: translateY(-1px);
    }
    .btn-platform-brand:active {
        transform: translateY(0);
        box-shadow: 0 2px 10px rgba(26, 127, 232, 0.3);
    }

    .input-field {
        transition: border-color 0.2s, box-shadow 0.2s;
    }
    .input-field:focus {
        border-color: var(--sb);
        box-shadow: 0 0 0 4px rgba(26, 127, 232, 0.12);
    }
</style>

<div class="min-h-screen platform-bg py-8 sm:py-12 px-4 sm:px-6 flex flex-col justify-between font-sans antialiased">
    <div class="w-full max-w-md mx-auto my-auto">

        <!-- Top Navigation / Logo -->
        <div class="flex items-center justify-between mb-6">
            <a href="<?= url('') ?>" class="flex items-center gap-2.5 no-underline group">
                <div class="w-10 h-10 rounded-xl flex items-center justify-center shadow-md text-white group-hover:shadow-lg transition-shadow" style="background: linear-gradient(135deg, var(--sn), var(--sb));">
                    <svg width="22" height="22" viewBox="0 0 24 24" fill="none">
                        <path d="M17 6s-2-2-5-2-5 1.8-5 4c0 2.5 2.5 3.5 5 4.5s5 2 5 4.5c0 2.2-2 3-5 3s-5-2-5-2"
                              stroke="#fff" stroke-width="2.6" stroke-linecap="round"/>
                    </svg>
                </div>
                <div>
                    <div class="font-black text-[15px] tracking-tight" style="color:var(--sn);"><?= e($companyName) ?></div>
                    <div class="text-[10px] font-bold tracking-widest uppercase text-blue-600"><?= e(__('nav.platform_admin')) ?></div>
                </div>
            </a>

            <a href="<?= url('') ?>" class="inline-flex items-center gap-1.5 text-xs font-semibold text-slate-600 hover:text-blue-700 bg-white border border-slate-200 px-3 py-1.5 rounded-lg shadow-sm hover:shadow transition">
                <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round">
                    <line x1="19" y1="12" x2="5" y2="12"></line>
                    <polyline points="12 19 5 12 12 5"></polyline>
                </svg>
                <span><?= e(__('auth.back_home')) ?></span>
            </a>
        </div>

        <!-- Form Card -->
        <div class="bg-white rounded-3xl p-7 sm:p-9 shadow-xl shadow-slate-200/80 border border-slate-100">

            <!-- Header Badge & Title -->
            <div class="mb-6">
                <div class="inline-flex items-center gap-1.5 px-3 py-1 rounded-full text-xs font-bold text-blue-700 bg-blue-50 border border-blue-100 mb-3">
                    <span>👑</span>
                    <span><?= e(__('plogin.badge')) ?></span>
                </div>
                <h1 class="text-2xl sm:text-3xl font-extrabold text-slate-900 tracking-tight mb-2">
                    <?= e(__('plogin.title')) ?>
                </h1>
                <p class="text-slate-500 text-sm leading-relaxed">
                    <?= e(__('plogin.sub')) ?>
                </p>
            </div>

            <?php if ($error !== ''): ?>
                <div class="flex items-start gap-3 p-4 mb-5 rounded-2xl bg-rose-50 border border-rose-200 text-rose-800 text-sm">
                    <svg class="w-5 h-5 text-rose-500 flex-shrink-0 mt-0.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <circle cx="12" cy="12" r="10"></circle>
                        <line x1="12" y1="8" x2="12" y2="12"></line>
                        <line x1="12" y1="16" x2="12.01" y2="16"></line>
                    </svg>
                    <div class="flex-1 font-medium"><?= e($error) ?></div>
                </div>
            <?php endif; ?>

            <form method="post" autocomplete="off" class="space-y-5">
                <?= csrf_field() ?>

                <!-- Username Field -->
                <div>
                    <label class="block text-xs font-bold uppercase tracking-wider text-slate-700 mb-2" for="platform_username">
                        <?= e(__('plogin.username')) ?>
                    </label>
                    <div class="relative">
                        <span class="absolute inset-y-0 left-0 flex items-center pl-3.5 pointer-events-none text-slate-400">
                            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                <path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"></path>
                                <circle cx="12" cy="7" r="4"></circle>
                            </svg>
                        </span>
                        <input type="text"
                               id="platform_username"
                               name="username"
                               class="input-field block w-full pl-10 pr-4 py-3 bg-slate-50 hover:bg-slate-100/60 focus:bg-white text-slate-900 text-sm font-medium rounded-xl border border-slate-200 outline-none transition"
                               placeholder="<?= e(__('plogin.username_ph')) ?>"
                               value="<?= e($username) ?>"
                               autofocus
                               required>
                    </div>
                </div>

                <!-- Password Field -->
                <div>
                    <div class="flex items-center justify-between mb-2">
                        <label class="block text-xs font-bold uppercase tracking-wider text-slate-700" for="platform_password">
                            <?= e(__('auth.password')) ?>
                        </label>
                        <span class="text-xs text-slate-400"><?= e(__('plogin.owner_key')) ?></span>
                    </div>
                    <div class="relative">
                        <span class="absolute inset-y-0 left-0 flex items-center pl-3.5 pointer-events-none text-slate-400">
                            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                <rect x="3" y="11" width="18" height="11" rx="2" ry="2"></rect>
                                <path d="M7 11V7a5 5 0 0 1 10 0v4"></path>
                            </svg>
                        </span>
                        <input type="password"
                               id="platform_password"
                               name="password"
                               class="input-field block w-full pl-10 pr-11 py-3 bg-slate-50 hover:bg-slate-100/60 focus:bg-white text-slate-900 text-sm font-medium rounded-xl border border-slate-200 outline-none transition"
                               placeholder="••••••••"
                               required>
                        <button type="button"
                                id="togglePassword"
                                class="absolute inset-y-0 right-0 flex items-center pr-3.5 text-slate-400 hover:text-slate-600 transition"
                                aria-label="<?= e(__('auth.toggle_pw')) ?>">
                            <svg id="eyeOpen" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"></path>
                                <circle cx="12" cy="12" r="3"></circle>
                            </svg>
                            <svg id="eyeClosed" class="hidden" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                <path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24"></path>
                                <line x1="1" y1="1" x2="23" y2="23"></line>
                            </svg>
                        </button>
                    </div>
                </div>

                <!-- Sign In Submit Button -->
                <button type="submit"
                        class="btn-platform-brand w-full flex items-center justify-center gap-2.5 py-3.5 px-4 text-white text-sm font-bold rounded-xl cursor-pointer">
                    <span><?= e(__('plogin.btn')) ?></span>
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                        <line x1="5" y1="12" x2="19" y2="12"></line>
                        <polyline points="12 5 19 12 12 19"></polyline>
                    </svg>
                </button>
            </form>

            <!-- Divider -->
            <div class="relative my-6">
                <div class="absolute inset-0 flex items-center">
                    <div class="w-full border-t border-slate-200"></div>
                </div>
                <div class="relative flex justify-center text-xs">
                    <span class="bg-white px-3 text-slate-400 font-medium uppercase tracking-wider"><?= e(__('plogin.staff_q')) ?></span>
                </div>
            </div>

            <!-- Link to Restaurant Staff Login -->
            <a href="<?= url('public/login.php') ?>"
               class="group flex items-center justify-between p-3.5 rounded-2xl bg-slate-50 hover:bg-blue-50/60 border border-slate-200 hover:border-blue-200 transition-all text-decoration-none">
                <div class="flex items-center gap-3">
                    <div class="w-9 h-9 rounded-xl flex items-center justify-center text-blue-700 bg-white shadow-sm font-bold text-sm">
                        🍽️
                    </div>
                    <div>
                        <div class="text-xs font-bold text-slate-900 group-hover:text-blue-700 transition-colors">
                            <?= e(__('plogin.staff_link')) ?>
                        </div>
                        <div class="text-[11px] text-slate-500">
                            <?= e(__('plogin.staff_roles')) ?>
                        </div>
                    </div>
                </div>
                <div class="w-7 h-7 rounded-lg flex items-center justify-center text-slate-400 group-hover:text-blue-600 group-hover:translate-x-0.5 transition-all">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round">
                        <line x1="5" y1="12" x2="19" y2="12"></line>
                        <polyline points="12 5 19 12 12 19"></polyline>
                    </svg>
                </div>
            </a>

        </div>

        <!-- Security Badge -->
        <div class="flex items-center justify-center gap-2 mt-5 text-slate-400 text-xs">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round">
                <rect x="3" y="11" width="18" height="11" rx="2" ry="2"></rect>
                <path d="M7 11V7a5 5 0 0 1 10 0v4"></path>
            </svg>
            <span><?= e(__('plogin.secure')) ?></span>
        </div>

    </div>

    <!-- Footer -->
    <footer class="text-center text-xs text-slate-500 pt-6">
        <?= e(__('auth.made_by')) ?> <a href="<?= e($webUrl) ?>" target="_blank" rel="noopener noreferrer" class="font-bold text-slate-800 hover:text-blue-600 transition-colors"><?= e($companyName) ?></a> ·
        <a href="<?= e($webUrl) ?>" target="_blank" rel="noopener noreferrer" class="text-slate-500 hover:text-blue-600 transition-colors"><?= e($webName) ?></a>
    </footer>
</div>

<script>
    // Password toggle visibility
    const toggleBtn = document.getElementById('togglePassword');
    const pwdInput  = document.getElementById('platform_password');
    const eyeOpen   = document.getElementById('eyeOpen');
    const eyeClosed = document.getElementById('eyeClosed');

    if (toggleBtn && pwdInput) {
        toggleBtn.addEventListener('click', function() {
            const isPassword = pwdInput.type === 'password';
            pwdInput.type = isPassword ? 'text' : 'password';
            if (isPassword) {
                eyeOpen.classList.add('hidden');
                eyeClosed.classList.remove('hidden');
            } else {
                eyeOpen.classList.remove('hidden');
                eyeClosed.classList.add('hidden');
            }
        });
    }
</script>
<?php require __DIR__ . '/../core/footer.php'; ?>
