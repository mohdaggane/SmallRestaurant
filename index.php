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

/** "$10" or "$12.50" — whole prices without the cents. */
function plan_price(float $p): string
{
    return '$' . (fmod($p, 1.0) == 0.0 ? number_format($p, 0) : number_format($p, 2));
}

$faqs = [
    [
        'Do I need to buy special equipment?',
        'No. It works in the web browser on any tablet, phone, laptop, or computer you already have. You can connect standard receipt printers anytime.'
    ],
    [
        'Can customers pay with mobile money?',
        'Yes. Every bill automatically prints your merchant payment code and exact total, so customers can pay by phone in seconds.'
    ],
    [
        'How do my employees sign in?',
        'You create simple accounts for each staff member with their specific role (Admin, Cashier, Waiter, or Kitchen). They only see what they need for their job.'
    ],
    [
        'What happens after the 14-day free trial?',
        'You can choose a simple monthly plan to keep going. All your menu items, orders, and sales history stay right where they are.'
    ]
];
?>
<!DOCTYPE html>
<html lang="en" class="scroll-smooth">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Restaurant POS by SAHAN ICT — Easy Point of Sale & Kitchen System</title>
    <meta name="description" content="Simple, fast restaurant POS software by SAHAN ICT. Take orders, send tickets to the kitchen, accept mobile money and track daily cash sales.">
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
            <div class="w-9 h-9 rounded-xl flex items-center justify-center shadow text-white" style="background: linear-gradient(135deg, var(--sn), var(--sb));">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none">
                    <path d="M17 6s-2-2-5-2-5 1.8-5 4c0 2.5 2.5 3.5 5 4.5s5 2 5 4.5c0 2.2-2 3-5 3s-5-2-5-2"
                          stroke="#fff" stroke-width="2.6" stroke-linecap="round"/>
                </svg>
            </div>
            <div>
                <div class="font-extrabold text-[15px] tracking-tight leading-none" style="color:var(--sn);">SAHAN ICT</div>
                <div class="text-[9px] font-bold tracking-widest uppercase mt-0.5" style="color:var(--sb);">Restaurant POS</div>
            </div>
        </a>

        <!-- Links -->
        <div class="hidden md:flex items-center gap-7 text-sm font-semibold text-slate-600">
            <a href="#features" class="hover:text-blue-600 transition-colors">Features</a>
            <a href="#how" class="hover:text-blue-600 transition-colors">How It Works</a>
            <a href="#pricing" class="hover:text-blue-600 transition-colors">Pricing</a>
            <a href="#faq" class="hover:text-blue-600 transition-colors">FAQ</a>
        </div>

        <!-- Action Buttons -->
        <div class="hidden sm:flex items-center gap-3">
            <a href="<?= $login ?>" class="text-sm font-semibold px-3 py-2 text-slate-700 hover:text-blue-700 no-underline transition-colors">
                Sign In
            </a>
            <a href="<?= $register ?>" class="btn-brand-primary text-xs font-bold uppercase tracking-wider px-4 py-2.5 rounded-xl no-underline inline-flex items-center gap-1.5">
                <span>Start Free Trial</span>
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
        <a href="#features" class="block py-1 text-slate-700">Features</a>
        <a href="#how" class="block py-1 text-slate-700">How It Works</a>
        <a href="#pricing" class="block py-1 text-slate-700">Pricing</a>
        <a href="#faq" class="block py-1 text-slate-700">FAQ</a>
        <div class="pt-3 border-t border-slate-100 flex gap-2">
            <a href="<?= $login ?>" class="flex-1 text-center py-2 text-xs font-bold border border-slate-200 rounded-lg text-slate-700 no-underline">Sign In</a>
            <a href="<?= $register ?>" class="flex-1 text-center py-2 text-xs font-bold btn-brand-primary rounded-lg no-underline text-white">Free Trial</a>
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
                    <span>Free <?= TRIAL_DAYS ?>-Day Trial &middot; No Credit Card Needed</span>
                </div>

                <h1 class="text-3xl sm:text-5xl font-extrabold text-slate-900 tracking-tight leading-tight mb-4">
                    The easy POS system for restaurants &amp; cafes.
                </h1>

                <p class="text-base sm:text-lg text-slate-600 leading-relaxed max-w-xl mx-auto lg:mx-0 mb-7">
                    Take customer orders quickly, send tickets straight to the kitchen screen, accept cash or mobile money, and balance your daily cash drawer with zero stress.
                </p>

                <!-- Action Buttons -->
                <div class="flex flex-col sm:flex-row items-center justify-center lg:justify-start gap-3 max-w-md mx-auto lg:mx-0">
                    <a href="<?= $register ?>" class="btn-brand-primary w-full sm:w-auto px-6 py-3.5 rounded-xl font-bold text-sm text-center no-underline inline-flex items-center justify-center gap-2">
                        <span>Start Free <?= TRIAL_DAYS ?>-Day Trial</span>
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round">
                            <line x1="5" y1="12" x2="19" y2="12"></line>
                            <polyline points="12 5 19 12 12 19"></polyline>
                        </svg>
                    </a>
                    <a href="<?= $login ?>" class="btn-brand-outline w-full sm:w-auto px-6 py-3.5 rounded-xl font-semibold text-sm text-center no-underline">
                        Sign In to Station
                    </a>
                </div>

                <!-- Trust Points -->
                <div class="mt-8 pt-6 border-t border-slate-200/80 flex flex-wrap items-center justify-center lg:justify-start gap-y-2 gap-x-6 text-xs font-semibold text-slate-600">
                    <span class="flex items-center gap-1.5"><span class="text-emerald-600 text-sm">✓</span> Works on tablets &amp; laptops</span>
                    <span class="flex items-center gap-1.5"><span class="text-emerald-600 text-sm">✓</span> Fast receipt printing</span>
                    <span class="flex items-center gap-1.5"><span class="text-emerald-600 text-sm">✓</span> 100% private data</span>
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
                                <div class="font-bold text-sm text-slate-900">Table 05 &middot; Lunch</div>
                                <div class="text-[11px] text-slate-400">Cashier: Ahmed &middot; Live</div>
                            </div>
                        </div>
                        <span class="px-2.5 py-1 rounded-full text-xs font-bold text-emerald-700 bg-emerald-50 border border-emerald-100">
                            Sent to Kitchen
                        </span>
                    </div>

                    <!-- Items List -->
                    <div class="py-4 space-y-2.5 text-xs sm:text-sm">
                        <div class="flex justify-between items-center">
                            <div class="flex items-center gap-2">
                                <span class="w-5 h-5 rounded bg-blue-50 text-blue-700 font-bold flex items-center justify-center text-xs">2</span>
                                <span class="font-medium text-slate-800">Spiced Milk Tea</span>
                            </div>
                            <span class="font-semibold text-slate-900">$1.50</span>
                        </div>
                        <div class="flex justify-between items-center">
                            <div class="flex items-center gap-2">
                                <span class="w-5 h-5 rounded bg-blue-50 text-blue-700 font-bold flex items-center justify-center text-xs">1</span>
                                <span class="font-medium text-slate-800">Chicken Steak &amp; Rice</span>
                            </div>
                            <span class="font-semibold text-slate-900">$5.00</span>
                        </div>
                        <div class="flex justify-between items-center">
                            <div class="flex items-center gap-2">
                                <span class="w-5 h-5 rounded bg-blue-50 text-blue-700 font-bold flex items-center justify-center text-xs">1</span>
                                <span class="font-medium text-slate-800">Fresh Mango Juice</span>
                            </div>
                            <span class="font-semibold text-slate-900">$1.50</span>
                        </div>
                    </div>

                    <!-- Totals and Payment Details -->
                    <div class="pt-3 border-t border-slate-100 space-y-1.5 text-xs">
                        <div class="flex justify-between text-slate-500">
                            <span>Subtotal</span>
                            <span>$8.00</span>
                        </div>
                        <div class="flex justify-between text-slate-500">
                            <span>Payment Option</span>
                            <span class="text-blue-700 font-semibold">Cash &middot; Mobile Money Dial</span>
                        </div>
                        <div class="flex justify-between text-sm font-bold text-slate-900 pt-1 border-t border-dashed border-slate-200">
                            <span>Total Due</span>
                            <span class="text-base text-blue-700 font-extrabold">$8.00</span>
                        </div>
                    </div>

                    <!-- Button Demo -->
                    <div class="mt-4 pt-2">
                        <div class="w-full py-2.5 rounded-xl text-center text-xs font-bold text-white bg-gradient-to-r from-blue-700 to-blue-600 shadow">
                            ✓ Complete Order &amp; Print Receipt
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
            <span class="text-slate-400 uppercase tracking-wider text-xs">Perfect for:</span>
            <span class="bg-white px-3 py-1.5 rounded-xl border border-slate-200 shadow-sm text-slate-800">☕ Cafes &amp; Tea Shops</span>
            <span class="bg-white px-3 py-1.5 rounded-xl border border-slate-200 shadow-sm text-slate-800">🍛 Restaurants &amp; Grills</span>
            <span class="bg-white px-3 py-1.5 rounded-xl border border-slate-200 shadow-sm text-slate-800">🍔 Fast Food &amp; Takeout</span>
            <span class="bg-white px-3 py-1.5 rounded-xl border border-slate-200 shadow-sm text-slate-800">🥤 Juice Bars &amp; Bakeries</span>
        </div>
    </div>
</section>

<!-- ════════════════════ 4 MAIN FEATURES ════════════════════ -->
<section id="features" class="py-16 lg:py-20 bg-white border-b border-slate-200">
    <div class="max-w-6xl mx-auto px-4 sm:px-6">
        
        <div class="text-center max-w-xl mx-auto mb-12">
            <span class="text-xs font-bold uppercase tracking-widest text-blue-600 bg-blue-50 px-3 py-1 rounded-full">Simple Features</span>
            <h2 class="text-2xl sm:text-3xl font-extrabold text-slate-900 tracking-tight mt-2.5">
                Everything you need to run your floor.
            </h2>
            <p class="text-slate-500 text-sm mt-2">
                No complex manuals or complicated setup. Designed so staff can learn it in 5 minutes.
            </p>
        </div>

        <div class="grid sm:grid-cols-2 lg:grid-cols-4 gap-6">
            
            <!-- Feature 1 -->
            <div class="p-6 rounded-2xl bg-slate-50 border border-slate-200 hover:border-blue-300 transition">
                <div class="w-12 h-12 rounded-xl bg-blue-100/70 text-blue-700 flex items-center justify-center text-xl mb-4">
                    🧾
                </div>
                <h3 class="font-bold text-slate-900 text-base mb-1.5">Fast Counter POS</h3>
                <p class="text-slate-600 text-xs sm:text-sm leading-relaxed">
                    Tap menu items, hold open tables, add extra rounds, and print customer bills in seconds.
                </p>
            </div>

            <!-- Feature 2 -->
            <div class="p-6 rounded-2xl bg-slate-50 border border-slate-200 hover:border-blue-300 transition">
                <div class="w-12 h-12 rounded-xl bg-blue-100/70 text-blue-700 flex items-center justify-center text-xl mb-4">
                    👨‍🍳
                </div>
                <h3 class="font-bold text-slate-900 text-base mb-1.5">Live Kitchen Screen</h3>
                <p class="text-slate-600 text-xs sm:text-sm leading-relaxed">
                    Cooks see orders instantly as they are taken. Mark food preparing and served without lost paper tickets.
                </p>
            </div>

            <!-- Feature 3 -->
            <div class="p-6 rounded-2xl bg-slate-50 border border-slate-200 hover:border-blue-300 transition">
                <div class="w-12 h-12 rounded-xl bg-blue-100/70 text-blue-700 flex items-center justify-center text-xl mb-4">
                    💵
                </div>
                <h3 class="font-bold text-slate-900 text-base mb-1.5">Cash Drawer Balance</h3>
                <p class="text-slate-600 text-xs sm:text-sm leading-relaxed">
                    Open shifts with an opening float, log cash expenses, and count your cash drawer down to the exact dollar.
                </p>
            </div>

            <!-- Feature 4 -->
            <div class="p-6 rounded-2xl bg-slate-50 border border-slate-200 hover:border-blue-300 transition">
                <div class="w-12 h-12 rounded-xl bg-blue-100/70 text-blue-700 flex items-center justify-center text-xl mb-4">
                    📱
                </div>
                <h3 class="font-bold text-slate-900 text-base mb-1.5">Mobile Money Ready</h3>
                <p class="text-slate-600 text-xs sm:text-sm leading-relaxed">
                    Receipts automatically show your merchant phone dial code with the exact total for fast mobile customer payments.
                </p>
            </div>

        </div>

    </div>
</section>

<!-- ════════════════════ HOW IT WORKS (3 SIMPLE STEPS) ════════════════════ -->
<section id="how" class="py-16 lg:py-20 bg-slate-50 border-b border-slate-200">
    <div class="max-w-6xl mx-auto px-4 sm:px-6">
        
        <div class="text-center max-w-xl mx-auto mb-12">
            <span class="text-xs font-bold uppercase tracking-widest text-blue-600 bg-blue-50 px-3 py-1 rounded-full">Easy Setup</span>
            <h2 class="text-2xl sm:text-3xl font-extrabold text-slate-900 tracking-tight mt-2.5">
                Up and running in 3 simple steps.
            </h2>
        </div>

        <div class="grid md:grid-cols-3 gap-6">
            
            <div class="bg-white p-6 rounded-2xl border border-slate-200 shadow-sm relative">
                <span class="w-8 h-8 rounded-lg bg-blue-700 text-white font-bold flex items-center justify-center text-sm mb-3">1</span>
                <h3 class="font-bold text-slate-900 text-base mb-1">Create Your Restaurant</h3>
                <p class="text-slate-600 text-xs sm:text-sm leading-relaxed">
                    Fill in your restaurant name and admin password. Your 14-day free trial opens instantly.
                </p>
            </div>

            <div class="bg-white p-6 rounded-2xl border border-slate-200 shadow-sm relative">
                <span class="w-8 h-8 rounded-lg bg-blue-700 text-white font-bold flex items-center justify-center text-sm mb-3">2</span>
                <h3 class="font-bold text-slate-900 text-base mb-1">Add Your Menu &amp; Staff</h3>
                <p class="text-slate-600 text-xs sm:text-sm leading-relaxed">
                    Type in your food and drinks with prices. Add simple sign-ins for cashiers, waiters, and kitchen cooks.
                </p>
            </div>

            <div class="bg-white p-6 rounded-2xl border border-slate-200 shadow-sm relative">
                <span class="w-8 h-8 rounded-lg bg-blue-700 text-white font-bold flex items-center justify-center text-sm mb-3">3</span>
                <h3 class="font-bold text-slate-900 text-base mb-1">Start Taking Orders</h3>
                <p class="text-slate-600 text-xs sm:text-sm leading-relaxed">
                    Open your shift, tap items for customer orders, print receipts, and watch your daily sales add up.
                </p>
            </div>

        </div>

    </div>
</section>

<!-- ════════════════════ PRICING PLANS ════════════════════ -->
<section id="pricing" class="py-16 lg:py-20 bg-white border-b border-slate-200">
    <div class="max-w-6xl mx-auto px-4 sm:px-6">
        
        <div class="text-center max-w-xl mx-auto mb-12">
            <span class="text-xs font-bold uppercase tracking-widest text-blue-600 bg-blue-50 px-3 py-1 rounded-full">Plans &amp; Pricing</span>
            <h2 class="text-2xl sm:text-3xl font-extrabold text-slate-900 tracking-tight mt-2.5">
                Affordable plans for any restaurant size.
            </h2>
            <p class="text-slate-500 text-sm mt-2">
                Start with a free <?= TRIAL_DAYS ?>-day trial. Pick a plan whenever you're ready.
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
                                Most Popular
                            </span>
                        <?php endif; ?>

                        <h3 class="text-lg font-bold text-slate-900"><?= e($p['name']) ?></h3>

                        <div class="my-4 flex items-baseline gap-1">
                            <span class="text-3xl sm:text-4xl font-extrabold text-slate-900">
                                <?= $isFree ? 'Free' : plan_price((float)$p['price_month']) ?>
                            </span>
                            <span class="text-xs text-slate-500">
                                <?= $isFree ? 'for ' . TRIAL_DAYS . ' days' : '/ month' ?>
                            </span>
                        </div>

                        <ul class="space-y-2.5 text-xs sm:text-sm text-slate-700 mb-6">
                            <li class="flex items-center gap-2">
                                <span class="text-emerald-600 font-bold">✓</span>
                                <span><?= $p['max_users'] === null ? 'Unlimited staff accounts' : (int)$p['max_users'] . ' staff accounts' ?></span>
                            </li>
                            <li class="flex items-center gap-2">
                                <span class="text-emerald-600 font-bold">✓</span>
                                <span><?= $p['max_menu_items'] === null ? 'Unlimited menu items' : (int)$p['max_menu_items'] . ' menu items' ?></span>
                            </li>
                            <li class="flex items-center gap-2">
                                <span class="text-emerald-600 font-bold">✓</span>
                                <span>Fast Counter POS &amp; Kitchen Screen</span>
                            </li>
                            <li class="flex items-center gap-2">
                                <span class="text-emerald-600 font-bold">✓</span>
                                <span>Mobile money dial code printing</span>
                            </li>
                            <li class="flex items-center gap-2">
                                <span class="text-emerald-600 font-bold">✓</span>
                                <span>Shift cash tracking &amp; sales reports</span>
                            </li>
                        </ul>
                    </div>

                    <a href="<?= $register ?>" class="w-full text-center font-bold text-xs uppercase tracking-wider rounded-xl py-3.5 no-underline transition <?= $isFeatured ? 'btn-brand-primary' : 'btn-brand-outline' ?>">
                        <?= $isFree ? 'Start Free Trial' : 'Choose Plan' ?>
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
            <span class="text-xs font-bold uppercase tracking-widest text-blue-600 bg-blue-50 px-3 py-1 rounded-full">Common Questions</span>
            <h2 class="text-2xl sm:text-3xl font-extrabold text-slate-900 tracking-tight mt-2.5">
                Questions &amp; Answers
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
            Ready to speed up your restaurant?
        </h2>
        <p class="text-slate-600 text-sm sm:text-base max-w-lg mx-auto mb-7">
            Start taking orders today with your free <?= TRIAL_DAYS ?>-day trial. Setup takes less than 2 minutes.
        </p>
        <div class="flex flex-col sm:flex-row items-center justify-center gap-3">
            <a href="<?= $register ?>" class="btn-brand-primary w-full sm:w-auto px-8 py-3.5 rounded-xl font-bold text-sm no-underline inline-flex items-center justify-center gap-2">
                <span>Start Free Trial</span>
                <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round">
                    <line x1="5" y1="12" x2="19" y2="12"></line>
                    <polyline points="12 5 19 12 12 19"></polyline>
                </svg>
            </a>
            <a href="<?= $login ?>" class="btn-brand-outline w-full sm:w-auto px-7 py-3.5 rounded-xl font-semibold text-sm no-underline">
                Sign In to Restaurant
            </a>
        </div>
    </div>
</section>

<!-- ════════════════════ FOOTER ════════════════════ -->
<footer class="border-t border-slate-200 bg-slate-50 py-8 text-xs text-slate-500">
    <div class="max-w-6xl mx-auto px-4 sm:px-6 flex flex-col sm:flex-row justify-between items-center gap-4">
        
        <div class="flex items-center gap-2.5">
            <div class="w-6 h-6 rounded-lg flex items-center justify-center text-white" style="background: linear-gradient(135deg, var(--sn), var(--sb));">
                <svg width="12" height="12" viewBox="0 0 24 24" fill="none">
                    <path d="M17 6s-2-2-5-2-5 1.8-5 4c0 2.5 2.5 3.5 5 4.5s5 2 5 4.5c0 2.2-2 3-5 3s-5-2-5-2"
                          stroke="#fff" stroke-width="2.6" stroke-linecap="round"/>
                </svg>
            </div>
            <span class="font-bold text-slate-800">SAHAN ICT</span>
            <span>&middot;</span>
            <span>Restaurant POS</span>
            <span>&middot;</span>
            <span>&copy; <?= date('Y') ?></span>
        </div>

        <div class="flex flex-wrap items-center justify-center sm:justify-end gap-x-5 gap-y-2 font-medium">
            <a href="#features" class="hover:text-blue-700 transition">Features</a>
            <a href="#how" class="hover:text-blue-700 transition">How It Works</a>
            <a href="#pricing" class="hover:text-blue-700 transition">Pricing</a>
            <a href="<?= $login ?>" class="hover:text-blue-700 transition">Sign In</a>
            <a href="<?= $register ?>" class="hover:text-blue-700 transition">Register</a>
            <a href="<?= $platformLogin ?>" class="text-slate-700 hover:text-blue-700 font-semibold transition">Platform Admin</a>
            <a href="https://sahanict.org" target="_blank" rel="noopener noreferrer" class="font-bold text-blue-700 hover:underline">
                sahanict.org
            </a>
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
