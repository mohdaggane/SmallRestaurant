<?php
/**
 * Entry point. Signed-in staff go straight to their role's home screen;
 * everyone else sees the public landing page, with sign-up and sign-in.
 * Pricing is read live from the plans table the platform owner manages.
 */
require_once __DIR__ . '/core/config.php';

if (is_logged_in()) {
    redirect(home_for_role(user_role()));
}

$plans = db_all('SELECT * FROM plans WHERE is_active = 1 ORDER BY sort_order, price_month');
$paid     = array_values(array_filter($plans, fn($p) => (float)$p['price_month'] > 0));
$featured = $paid[0]['id'] ?? null;

$register      = url('public/register.php');
$login         = url('public/login.php');
$platformLogin = url('platform/login.php');

$companyName  = platform_setting('company_name', 'SAHAN ICT');
$systemName   = platform_setting('system_name', 'Restaurant POS');
$contactPhone = platform_setting('contact_phone', '+252 61 5000000');
$contactEmail = platform_setting('contact_email', 'info@sahanict.org');
$webName      = platform_setting('web_name', 'sahanict.org');
$webUrl       = platform_setting('web_url', 'https://sahanict.org');
$logoUrl      = platform_logo_url();

/** "$10" or "$12.50" — whole prices without the cents. */
function plan_price(float $p): string
{
    return '$' . (fmod($p, 1.0) == 0.0 ? number_format($p, 0) : number_format($p, 2));
}

$faqs = [];
foreach ([1, 2, 3, 4] as $i) {
    $faqs[] = [__("home.faq{$i}_q"), __("home.faq{$i}_a")];
}
?>
<!DOCTYPE html>
<html lang="en" class="scroll-smooth">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e(__('home.meta_title', '', ['system' => $systemName, 'company' => $companyName])) ?></title>
    <meta name="description" content="<?= e(__('home.meta_desc', '', ['company' => $companyName])) ?>">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap">
    <link rel="stylesheet" href="<?= url('assets/css/app.css') ?>">
    <style>
        :root {
            --sn: #1e2f6e;   /* navy */
            --sb: #1a7fe8;   /* blue */
            --sn2: #152257;
            --sb2: #1567c4;
            --sl: #e8f1fd;
        }

        body { color: #1e293b; font-family: 'Inter', sans-serif; }

        .btn-brand-primary {
            background: linear-gradient(135deg, var(--sn) 0%, var(--sb) 100%);
            color: #ffffff;
            box-shadow: 0 4px 14px rgba(26, 127, 232, 0.28);
            transition: all 0.2s ease;
        }
        .btn-brand-primary:hover {
            background: linear-gradient(135deg, var(--sn2) 0%, var(--sb2) 100%);
            box-shadow: 0 6px 20px rgba(26, 127, 232, 0.38);
            transform: translateY(-1px);
        }

        .btn-brand-outline {
            border: 1.5px solid #cbd5e1;
            color: var(--sn);
            background: #ffffff;
            transition: all 0.2s ease;
        }
        .btn-brand-outline:hover {
            border-color: var(--sb);
            color: var(--sb);
            background: #f8fafc;
        }

        details summary::-webkit-details-marker { display: none; }
    </style>
</head>
<body class="bg-white antialiased text-slate-800">

<!-- ════════════════════ NAVBAR ════════════════════ -->
<header class="sticky top-0 z-40 bg-white/95 backdrop-blur-md border-b border-slate-200">
    <nav class="max-w-6xl mx-auto px-4 sm:px-6 h-16 flex items-center justify-between">
        
        <!-- Logo -->
        <a href="<?= url('') ?>" class="flex items-center gap-2.5 no-underline group">
            <?php if ($logoUrl !== ''): ?>
                <img src="<?= e($logoUrl) ?>" alt="Logo" class="w-9 h-9 rounded-xl object-contain shadow-sm bg-white p-0.5 border border-slate-200">
            <?php else: ?>
                <div class="w-9 h-9 rounded-xl flex items-center justify-center shadow text-white" style="background: linear-gradient(135deg, var(--sn), var(--sb));">
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none">
                        <path d="M17 6s-2-2-5-2-5 1.8-5 4c0 2.5 2.5 3.5 5 4.5s5 2 5 4.5c0 2.2-2 3-5 3s-5-2-5-2"
                              stroke="#fff" stroke-width="2.6" stroke-linecap="round"/>
                    </svg>
                </div>
            <?php endif; ?>
            <div>
                <div class="font-extrabold text-[15px] tracking-tight leading-none" style="color:var(--sn);"><?= e($companyName) ?></div>
                <div class="text-[9px] font-bold tracking-widest uppercase mt-0.5" style="color:var(--sb);"><?= e($systemName) ?></div>
            </div>
        </a>

        <!-- Links -->
        <div class="hidden md:flex items-center gap-7 text-sm font-semibold text-slate-600">
            <a href="#features" class="hover:text-blue-600 transition-colors"><?= e(__('home.nav_features')) ?></a>
            <a href="#how" class="hover:text-blue-600 transition-colors"><?= e(__('home.nav_how')) ?></a>
            <a href="#pricing" class="hover:text-blue-600 transition-colors"><?= e(__('home.nav_pricing')) ?></a>
            <a href="#faq" class="hover:text-blue-600 transition-colors"><?= e(__('home.nav_faq')) ?></a>
        </div>

        <!-- Action Buttons -->
        <div class="hidden sm:flex items-center gap-3">
            <a href="<?= $login ?>" class="text-sm font-semibold px-3 py-2 text-slate-700 hover:text-blue-700 no-underline transition-colors">
                <?= e(__('home.sign_in')) ?>
            </a>
            <a href="<?= $register ?>" class="btn-brand-primary text-xs font-bold uppercase tracking-wider px-4 py-2.5 rounded-xl no-underline inline-flex items-center gap-1.5">
                <span><?= e(__('home.start_trial')) ?></span>
                <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round">
                    <line x1="5" y1="12" x2="19" y2="12"></line>
                    <polyline points="12 5 19 12 12 19"></polyline>
                </svg>
            </a>
        </div>

        <!-- Mobile Menu Toggle -->
        <button id="navToggle" class="sm:hidden p-2 text-slate-600" aria-label="Toggle menu">
            <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round">
                <line x1="3" y1="12" x2="21" y2="12"></line>
                <line x1="3" y1="6" x2="21" y2="6"></line>
                <line x1="3" y1="18" x2="21" y2="18"></line>
            </svg>
        </button>
    </nav>

    <!-- Mobile Dropdown -->
    <div id="mobileMenu" class="hidden sm:hidden border-t border-slate-200 bg-white px-5 py-4 space-y-3 text-sm font-medium">
        <a href="#features" class="block py-1 text-slate-700"><?= e(__('home.nav_features')) ?></a>
        <a href="#how" class="block py-1 text-slate-700"><?= e(__('home.nav_how')) ?></a>
        <a href="#pricing" class="block py-1 text-slate-700"><?= e(__('home.nav_pricing')) ?></a>
        <a href="#faq" class="block py-1 text-slate-700"><?= e(__('home.nav_faq')) ?></a>
        <div class="pt-3 border-t border-slate-100 flex gap-2">
            <a href="<?= $login ?>" class="flex-1 text-center py-2 text-xs font-bold border border-slate-200 rounded-lg text-slate-700 no-underline"><?= e(__('home.sign_in')) ?></a>
            <a href="<?= $register ?>" class="flex-1 text-center py-2 text-xs font-bold btn-brand-primary rounded-lg no-underline text-white"><?= e(__('home.free_trial')) ?></a>
        </div>
    </div>
</header>

<!-- ════════════════════ HERO SECTION ════════════════════ -->
<section class="relative bg-gradient-to-b from-blue-50/60 via-slate-50/40 to-white pt-12 pb-16 lg:pt-18 lg:pb-24 border-b border-slate-200">
    <div class="max-w-6xl mx-auto px-4 sm:px-6">
        
        <div class="grid lg:grid-cols-12 gap-10 lg:gap-12 items-center">
            
            <!-- Left Headline & Description -->
            <div class="lg:col-span-7 text-center lg:text-left">
                
                <div class="inline-flex items-center gap-2 px-3 py-1 rounded-full bg-white border border-blue-200 text-xs font-semibold text-blue-700 mb-5 shadow-sm">
                    <span class="w-2 h-2 rounded-full bg-emerald-500"></span>
                    <span><?= e(__('home.trial_badge', '', ['days' => TRIAL_DAYS])) ?></span>
                </div>

                <h1 class="text-3xl sm:text-5xl font-extrabold text-slate-900 tracking-tight leading-tight mb-4">
                    <?= e(__('home.hero_title')) ?>
                </h1>

                <p class="text-base sm:text-lg text-slate-600 leading-relaxed max-w-xl mx-auto lg:mx-0 mb-7">
                    <?= e(__('home.hero_sub')) ?>
                </p>

                <!-- Action Buttons -->
                <div class="flex flex-col sm:flex-row items-center justify-center lg:justify-start gap-3 max-w-md mx-auto lg:mx-0">
                    <a href="<?= $register ?>" class="btn-brand-primary w-full sm:w-auto px-6 py-3.5 rounded-xl font-bold text-sm text-center no-underline inline-flex items-center justify-center gap-2">
                        <span><?= e(__('home.start_trial_days', '', ['days' => TRIAL_DAYS])) ?></span>
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round">
                            <line x1="5" y1="12" x2="19" y2="12"></line>
                            <polyline points="12 5 19 12 12 19"></polyline>
                        </svg>
                    </a>
                    <a href="<?= $login ?>" class="btn-brand-outline w-full sm:w-auto px-6 py-3.5 rounded-xl font-semibold text-sm text-center no-underline">
                        <?= e(__('home.sign_in_station')) ?>
                    </a>
                </div>

                <!-- Trust Points -->
                <div class="mt-8 pt-6 border-t border-slate-200/80 flex flex-wrap items-center justify-center lg:justify-start gap-y-2 gap-x-6 text-xs font-semibold text-slate-600">
                    <span class="flex items-center gap-1.5"><span class="text-emerald-600 text-sm">✓</span> <?= e(__('home.trust_devices')) ?></span>
                    <span class="flex items-center gap-1.5"><span class="text-emerald-600 text-sm">✓</span> <?= e(__('home.trust_print')) ?></span>
                    <span class="flex items-center gap-1.5"><span class="text-emerald-600 text-sm">✓</span> <?= e(__('home.trust_private')) ?></span>
                </div>

            </div>

            <!-- Right Visual: Clean Restaurant Order Card -->
            <div class="lg:col-span-5">
                <div class="bg-white rounded-3xl p-5 sm:p-6 shadow-xl shadow-slate-200/80 border border-slate-200 max-w-md mx-auto">
                    
                    <!-- Top POS Header -->
                    <div class="flex items-center justify-between pb-4 border-b border-slate-100">
                        <div class="flex items-center gap-2">
                            <span class="text-xl">🍽️</span>
                            <div>
                                <div class="font-bold text-sm text-slate-900"><?= e(__('home.mock_table')) ?></div>
                                <div class="text-[11px] text-slate-400"><?= e(__('home.mock_cashier')) ?></div>
                            </div>
                        </div>
                        <span class="px-2.5 py-1 rounded-full text-xs font-bold text-emerald-700 bg-emerald-50 border border-emerald-100">
                            <?= e(__('home.mock_sent')) ?>
                        </span>
                    </div>

                    <!-- Items List -->
                    <div class="py-4 space-y-2.5 text-xs sm:text-sm">
                        <div class="flex justify-between items-center">
                            <div class="flex items-center gap-2">
                                <span class="w-5 h-5 rounded bg-blue-50 text-blue-700 font-bold flex items-center justify-center text-xs">2</span>
                                <span class="font-medium text-slate-800"><?= e(__('home.mock_item1')) ?></span>
                            </div>
                            <span class="font-semibold text-slate-900">$1.50</span>
                        </div>
                        <div class="flex justify-between items-center">
                            <div class="flex items-center gap-2">
                                <span class="w-5 h-5 rounded bg-blue-50 text-blue-700 font-bold flex items-center justify-center text-xs">1</span>
                                <span class="font-medium text-slate-800"><?= e(__('home.mock_item2')) ?></span>
                            </div>
                            <span class="font-semibold text-slate-900">$5.00</span>
                        </div>
                        <div class="flex justify-between items-center">
                            <div class="flex items-center gap-2">
                                <span class="w-5 h-5 rounded bg-blue-50 text-blue-700 font-bold flex items-center justify-center text-xs">1</span>
                                <span class="font-medium text-slate-800"><?= e(__('home.mock_item3')) ?></span>
                            </div>
                            <span class="font-semibold text-slate-900">$1.50</span>
                        </div>
                    </div>

                    <!-- Totals and Payment Details -->
                    <div class="pt-3 border-t border-slate-100 space-y-1.5 text-xs">
                        <div class="flex justify-between text-slate-500">
                            <span><?= e(__('lbl.subtotal')) ?></span>
                            <span>$8.00</span>
                        </div>
                        <div class="flex justify-between text-slate-500">
                            <span><?= e(__('home.mock_payment')) ?></span>
                            <span class="text-blue-700 font-semibold"><?= e(__('home.mock_payment_val')) ?></span>
                        </div>
                        <div class="flex justify-between text-sm font-bold text-slate-900 pt-1 border-t border-dashed border-slate-200">
                            <span><?= e(__('home.mock_total_due')) ?></span>
                            <span class="text-base text-blue-700 font-extrabold">$8.00</span>
                        </div>
                    </div>

                    <!-- Button Demo -->
                    <div class="mt-4 pt-2">
                        <div class="w-full py-2.5 rounded-xl text-center text-xs font-bold text-white bg-gradient-to-r from-blue-700 to-blue-600 shadow">
                            <?= e(__('home.mock_complete')) ?>
                        </div>
                    </div>

                </div>
            </div>

        </div>

    </div>
</section>

<!-- ════════════════════ WHO IT IS BUILT FOR ════════════════════ -->
<section class="py-6 bg-slate-50 border-b border-slate-200">
    <div class="max-w-6xl mx-auto px-4 sm:px-6">
        <div class="flex flex-wrap items-center justify-center gap-3 sm:gap-6 text-xs sm:text-sm font-semibold text-slate-600">
            <span class="text-slate-400 uppercase tracking-wider text-xs"><?= e(__('home.perfect_for')) ?></span>
            <span class="bg-white px-3 py-1.5 rounded-xl border border-slate-200 shadow-sm text-slate-800"><?= e(__('home.type_cafe')) ?></span>
            <span class="bg-white px-3 py-1.5 rounded-xl border border-slate-200 shadow-sm text-slate-800"><?= e(__('home.type_rest')) ?></span>
            <span class="bg-white px-3 py-1.5 rounded-xl border border-slate-200 shadow-sm text-slate-800"><?= e(__('home.type_fast')) ?></span>
            <span class="bg-white px-3 py-1.5 rounded-xl border border-slate-200 shadow-sm text-slate-800"><?= e(__('home.type_juice')) ?></span>
        </div>
    </div>
</section>

<!-- ════════════════════ 4 MAIN FEATURES ════════════════════ -->
<section id="features" class="py-16 lg:py-20 bg-white border-b border-slate-200">
    <div class="max-w-6xl mx-auto px-4 sm:px-6">
        
        <div class="text-center max-w-xl mx-auto mb-12">
            <span class="text-xs font-bold uppercase tracking-widest text-blue-600 bg-blue-50 px-3 py-1 rounded-full"><?= e(__('home.feat_badge')) ?></span>
            <h2 class="text-2xl sm:text-3xl font-extrabold text-slate-900 tracking-tight mt-2.5">
                <?= e(__('home.feat_title')) ?>
            </h2>
            <p class="text-slate-500 text-sm mt-2">
                <?= e(__('home.feat_sub')) ?>
            </p>
        </div>

        <div class="grid sm:grid-cols-2 lg:grid-cols-4 gap-6">
            
            <!-- Feature 1 -->
            <div class="p-6 rounded-2xl bg-slate-50 border border-slate-200 hover:border-blue-300 transition">
                <div class="w-12 h-12 rounded-xl bg-blue-100/70 text-blue-700 flex items-center justify-center text-xl mb-4">
                    🧾
                </div>
                <h3 class="font-bold text-slate-900 text-base mb-1.5"><?= e(__('home.f1_title')) ?></h3>
                <p class="text-slate-600 text-xs sm:text-sm leading-relaxed">
                    <?= e(__('home.f1_desc')) ?>
                </p>
            </div>

            <!-- Feature 2 -->
            <div class="p-6 rounded-2xl bg-slate-50 border border-slate-200 hover:border-blue-300 transition">
                <div class="w-12 h-12 rounded-xl bg-blue-100/70 text-blue-700 flex items-center justify-center text-xl mb-4">
                    👨‍🍳
                </div>
                <h3 class="font-bold text-slate-900 text-base mb-1.5"><?= e(__('home.f2_title')) ?></h3>
                <p class="text-slate-600 text-xs sm:text-sm leading-relaxed">
                    <?= e(__('home.f2_desc')) ?>
                </p>
            </div>

            <!-- Feature 3 -->
            <div class="p-6 rounded-2xl bg-slate-50 border border-slate-200 hover:border-blue-300 transition">
                <div class="w-12 h-12 rounded-xl bg-blue-100/70 text-blue-700 flex items-center justify-center text-xl mb-4">
                    💵
                </div>
                <h3 class="font-bold text-slate-900 text-base mb-1.5"><?= e(__('home.f3_title')) ?></h3>
                <p class="text-slate-600 text-xs sm:text-sm leading-relaxed">
                    <?= e(__('home.f3_desc')) ?>
                </p>
            </div>

            <!-- Feature 4 -->
            <div class="p-6 rounded-2xl bg-slate-50 border border-slate-200 hover:border-blue-300 transition">
                <div class="w-12 h-12 rounded-xl bg-blue-100/70 text-blue-700 flex items-center justify-center text-xl mb-4">
                    📱
                </div>
                <h3 class="font-bold text-slate-900 text-base mb-1.5"><?= e(__('home.f4_title')) ?></h3>
                <p class="text-slate-600 text-xs sm:text-sm leading-relaxed">
                    <?= e(__('home.f4_desc')) ?>
                </p>
            </div>

        </div>

    </div>
</section>

<!-- ════════════════════ HOW IT WORKS (3 SIMPLE STEPS) ════════════════════ -->
<section id="how" class="py-16 lg:py-20 bg-slate-50 border-b border-slate-200">
    <div class="max-w-6xl mx-auto px-4 sm:px-6">
        
        <div class="text-center max-w-xl mx-auto mb-12">
            <span class="text-xs font-bold uppercase tracking-widest text-blue-600 bg-blue-50 px-3 py-1 rounded-full"><?= e(__('home.how_badge')) ?></span>
            <h2 class="text-2xl sm:text-3xl font-extrabold text-slate-900 tracking-tight mt-2.5">
                <?= e(__('home.how_title')) ?>
            </h2>
        </div>

        <div class="grid md:grid-cols-3 gap-6">
            
            <div class="bg-white p-6 rounded-2xl border border-slate-200 shadow-sm relative">
                <span class="w-8 h-8 rounded-lg bg-blue-700 text-white font-bold flex items-center justify-center text-sm mb-3">1</span>
                <h3 class="font-bold text-slate-900 text-base mb-1"><?= e(__('home.s1_title')) ?></h3>
                <p class="text-slate-600 text-xs sm:text-sm leading-relaxed">
                    <?= e(__('home.s1_desc')) ?>
                </p>
            </div>

            <div class="bg-white p-6 rounded-2xl border border-slate-200 shadow-sm relative">
                <span class="w-8 h-8 rounded-lg bg-blue-700 text-white font-bold flex items-center justify-center text-sm mb-3">2</span>
                <h3 class="font-bold text-slate-900 text-base mb-1"><?= e(__('home.s2_title')) ?></h3>
                <p class="text-slate-600 text-xs sm:text-sm leading-relaxed">
                    <?= e(__('home.s2_desc')) ?>
                </p>
            </div>

            <div class="bg-white p-6 rounded-2xl border border-slate-200 shadow-sm relative">
                <span class="w-8 h-8 rounded-lg bg-blue-700 text-white font-bold flex items-center justify-center text-sm mb-3">3</span>
                <h3 class="font-bold text-slate-900 text-base mb-1"><?= e(__('home.s3_title')) ?></h3>
                <p class="text-slate-600 text-xs sm:text-sm leading-relaxed">
                    <?= e(__('home.s3_desc')) ?>
                </p>
            </div>

        </div>

    </div>
</section>

<!-- ════════════════════ PRICING PLANS ════════════════════ -->
<section id="pricing" class="py-16 lg:py-20 bg-white border-b border-slate-200">
    <div class="max-w-6xl mx-auto px-4 sm:px-6">
        
        <div class="text-center max-w-xl mx-auto mb-12">
            <span class="text-xs font-bold uppercase tracking-widest text-blue-600 bg-blue-50 px-3 py-1 rounded-full"><?= e(__('home.price_badge')) ?></span>
            <h2 class="text-2xl sm:text-3xl font-extrabold text-slate-900 tracking-tight mt-2.5">
                <?= e(__('home.price_title')) ?>
            </h2>
            <p class="text-slate-500 text-sm mt-2">
                <?= e(__('home.price_sub', '', ['days' => TRIAL_DAYS])) ?>
            </p>
        </div>

        <div class="grid gap-6 <?= count($plans) >= 3 ? 'lg:grid-cols-3' : 'md:grid-cols-2' ?> max-w-5xl mx-auto">
            <?php foreach ($plans as $p): ?>
                <?php
                $isFree     = (float)$p['price_month'] <= 0;
                $isFeatured = (int)$p['id'] === (int)$featured;
                ?>
                <div class="rounded-3xl p-7 flex flex-col justify-between border <?= $isFeatured ? 'border-blue-600 ring-2 ring-blue-600 bg-blue-50/20' : 'border-slate-200 bg-white' ?> shadow-sm">
                    <div>
                        <?php if ($isFeatured): ?>
                            <span class="inline-block text-[10px] font-extrabold uppercase tracking-wider px-2.5 py-0.5 rounded-full bg-blue-600 text-white mb-3">
                                <?= e(__('home.most_popular')) ?>
                            </span>
                        <?php endif; ?>

                        <h3 class="text-lg font-bold text-slate-900"><?= e($p['name']) ?></h3>

                        <div class="my-4 flex items-baseline gap-1">
                            <span class="text-3xl sm:text-4xl font-extrabold text-slate-900">
                                <?= $isFree ? e(__('home.free')) : plan_price((float)$p['price_month']) ?>
                            </span>
                            <span class="text-xs text-slate-500">
                                <?= e($isFree ? __('home.for_days', '', ['days' => TRIAL_DAYS]) : __('home.per_month')) ?>
                            </span>
                        </div>

                        <ul class="space-y-2.5 text-xs sm:text-sm text-slate-700 mb-6">
                            <li class="flex items-center gap-2">
                                <span class="text-emerald-600 font-bold">✓</span>
                                <span><?= e($p['max_users'] === null ? __('home.unlimited_staff') : __('home.n_staff', '', ['n' => (int)$p['max_users']])) ?></span>
                            </li>
                            <li class="flex items-center gap-2">
                                <span class="text-emerald-600 font-bold">✓</span>
                                <span><?= e($p['max_menu_items'] === null ? __('home.unlimited_menu') : __('home.n_menu', '', ['n' => (int)$p['max_menu_items']])) ?></span>
                            </li>
                            <li class="flex items-center gap-2">
                                <span class="text-emerald-600 font-bold">✓</span>
                                <span><?= e(__('home.plan_feat1')) ?></span>
                            </li>
                            <li class="flex items-center gap-2">
                                <span class="text-emerald-600 font-bold">✓</span>
                                <span><?= e(__('home.plan_feat2')) ?></span>
                            </li>
                            <li class="flex items-center gap-2">
                                <span class="text-emerald-600 font-bold">✓</span>
                                <span><?= e(__('home.plan_feat3')) ?></span>
                            </li>
                        </ul>
                    </div>

                    <a href="<?= $register ?>" class="w-full text-center font-bold text-xs uppercase tracking-wider rounded-xl py-3.5 no-underline transition <?= $isFeatured ? 'btn-brand-primary' : 'btn-brand-outline' ?>">
                        <?= e($isFree ? __('home.start_trial') : __('home.choose_plan')) ?>
                    </a>
                </div>
            <?php endforeach; ?>
        </div>

    </div>
</section>

<!-- ════════════════════ FAQ ════════════════════ -->
<section id="faq" class="py-16 lg:py-20 bg-slate-50 border-b border-slate-200">
    <div class="max-w-3xl mx-auto px-4 sm:px-6">
        
        <div class="text-center mb-10">
            <span class="text-xs font-bold uppercase tracking-widest text-blue-600 bg-blue-50 px-3 py-1 rounded-full"><?= e(__('home.faq_badge')) ?></span>
            <h2 class="text-2xl sm:text-3xl font-extrabold text-slate-900 tracking-tight mt-2.5">
                <?= e(__('home.faq_title')) ?>
            </h2>
        </div>

        <div class="space-y-3">
            <?php foreach ($faqs as [$q, $a]): ?>
                <details class="group bg-white rounded-2xl border border-slate-200 p-5 cursor-pointer">
                    <summary class="flex justify-between items-center gap-4 font-bold text-sm sm:text-base text-slate-900 list-none">
                        <span><?= e($q) ?></span>
                        <span class="w-6 h-6 rounded-full bg-slate-100 flex items-center justify-center font-bold text-slate-600 group-open:rotate-45 transition-transform text-xs">
                            +
                        </span>
                    </summary>
                    <p class="mt-3 text-xs sm:text-sm text-slate-600 leading-relaxed pr-6">
                        <?= e($a) ?>
                    </p>
                </details>
            <?php endforeach; ?>
        </div>

    </div>
</section>

<!-- ════════════════════ CTA BANNER ════════════════════ -->
<section class="py-16 lg:py-20 bg-white">
    <div class="max-w-4xl mx-auto px-4 sm:px-6 text-center">
        <h2 class="text-2xl sm:text-4xl font-extrabold text-slate-900 tracking-tight mb-3">
            <?= e(__('home.cta_title')) ?>
        </h2>
        <p class="text-slate-600 text-sm sm:text-base max-w-lg mx-auto mb-7">
            <?= e(__('home.cta_sub', '', ['days' => TRIAL_DAYS])) ?>
        </p>
        <div class="flex flex-col sm:flex-row items-center justify-center gap-3">
            <a href="<?= $register ?>" class="btn-brand-primary w-full sm:w-auto px-8 py-3.5 rounded-xl font-bold text-sm no-underline inline-flex items-center justify-center gap-2">
                <span><?= e(__('home.start_trial')) ?></span>
                <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round">
                    <line x1="5" y1="12" x2="19" y2="12"></line>
                    <polyline points="12 5 19 12 12 19"></polyline>
                </svg>
            </a>
            <a href="<?= $login ?>" class="btn-brand-outline w-full sm:w-auto px-7 py-3.5 rounded-xl font-semibold text-sm no-underline">
                <?= e(__('home.sign_in_restaurant')) ?>
            </a>
        </div>
    </div>
</section>

<!-- ════════════════════ FOOTER ════════════════════ -->
<footer class="border-t border-slate-200 bg-slate-50 py-8 text-xs text-slate-500">
    <div class="max-w-6xl mx-auto px-4 sm:px-6 flex flex-col sm:flex-row justify-between items-center gap-4">
        
        <div class="flex items-center gap-2.5">
            <?php if ($logoUrl !== ''): ?>
                <img src="<?= e($logoUrl) ?>" alt="Logo" class="w-6 h-6 rounded-lg object-contain bg-white p-0.5 border border-slate-200">
            <?php else: ?>
                <div class="w-6 h-6 rounded-lg flex items-center justify-center text-white" style="background: linear-gradient(135deg, var(--sn), var(--sb));">
                    <svg width="12" height="12" viewBox="0 0 24 24" fill="none">
                        <path d="M17 6s-2-2-5-2-5 1.8-5 4c0 2.5 2.5 3.5 5 4.5s5 2 5 4.5c0 2.2-2 3-5 3s-5-2-5-2"
                              stroke="#fff" stroke-width="2.6" stroke-linecap="round"/>
                    </svg>
                </div>
            <?php endif; ?>
            <span class="font-bold text-slate-800"><?= e($companyName) ?></span>
            <span>&middot;</span>
            <span><?= e($systemName) ?></span>
            <span>&middot;</span>
            <span>&copy; <?= date('Y') ?></span>
        </div>

        <div class="flex flex-wrap items-center justify-center sm:justify-end gap-x-5 gap-y-2 font-medium">
            <a href="#features" class="hover:text-blue-700 transition"><?= e(__('home.nav_features')) ?></a>
            <a href="#how" class="hover:text-blue-700 transition"><?= e(__('home.nav_how')) ?></a>
            <a href="#pricing" class="hover:text-blue-700 transition"><?= e(__('home.nav_pricing')) ?></a>
            <a href="<?= $login ?>" class="hover:text-blue-700 transition"><?= e(__('home.sign_in')) ?></a>
            <a href="<?= $register ?>" class="hover:text-blue-700 transition"><?= e(__('home.register')) ?></a>
            <a href="<?= $platformLogin ?>" class="text-slate-700 hover:text-blue-700 font-semibold transition"><?= e(__('nav.platform_admin')) ?></a>
            <?php if ($webUrl !== ''): ?>
                <a href="<?= e($webUrl) ?>" target="_blank" rel="noopener noreferrer" class="font-bold text-blue-700 hover:underline">
                    <?= e($webName ?: 'Website') ?>
                </a>
            <?php endif; ?>
        </div>


    </div>
</footer>

<script>
    const navToggle  = document.getElementById('navToggle');
    const mobileMenu = document.getElementById('mobileMenu');
    if (navToggle && mobileMenu) {
        navToggle.addEventListener('click', function() {
            mobileMenu.classList.toggle('hidden');
        });
        mobileMenu.querySelectorAll('a').forEach(function(link) {
            link.addEventListener('click', function() {
                mobileMenu.classList.add('hidden');
            });
        });
    }
</script>

</body>
</html>
