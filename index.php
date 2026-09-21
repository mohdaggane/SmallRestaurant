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
// The cheapest paid plan gets the "Most popular" badge.
$paid     = array_values(array_filter($plans, fn($p) => (float)$p['price_month'] > 0));
$featured = $paid[0]['id'] ?? null;

$register = url('public/register.php');
$login    = url('public/login.php');

$features = [
    ['🧾', 'Fast POS terminal', 'Tap items, add notes, hold a table or charge at once. Built for a busy counter, works on a tablet or a laptop.'],
    ['👨‍🍳', 'Live kitchen screen', 'Orders reach the kitchen as soon as they are taken. Cooks mark items preparing and served, and waiting orders stand out.'],
    ['🍽️', 'Open tables & extra rounds', 'Add a second round to an open bill, remove what the kitchen has not started, and settle when the guest is ready.'],
    ['🧮', 'Cash drawer control', 'Open a shift with a float, record cash expenses, and close it against what the drawer should hold, to the cent.'],
    ['📱', 'Mobile money on every bill', 'Bills print your merchant dial code with the exact amount, so customers pay by phone in seconds.'],
    ['📈', 'Reports that add up', 'Daily takings, unpaid bills, profit and loss, VAT and best sellers, with CSV export and an end-of-day slip.'],
];

$roles = [
    ['Admin',   'Menu, staff, prices, VAT, reports and billing. Sees everything.'],
    ['Cashier', 'Takes payment, runs the cash drawer, records expenses.'],
    ['Waiter',  'Takes orders to the kitchen. Cannot handle money.'],
    ['Kitchen', 'Sees only the preparation screen.'],
];

$faqs = [
    ['Do I need to install anything?', 'No. It runs in the browser on any tablet, phone or computer. Sign up, add your menu and start selling.'],
    ['Is my restaurant\'s data private?', 'Yes. Every restaurant has its own menu, staff, orders and reports. No other restaurant on the system can see them.'],
    ['What happens when the free trial ends?', 'Choose a plan and pay the platform owner. Your data stays exactly where it was, and your staff can sign in again as soon as the payment is recorded.'],
    ['Can I charge VAT?', 'Yes. Set your VAT rate in Settings. Every bill and receipt shows the amount before VAT, the VAT and the total, and reports keep them apart.'],
    ['How do my staff sign in?', 'You create an account for each person under Users and choose their role. They sign in with their own username and password.'],
];

/** "$10" or "$12.50" — whole prices without the cents. */
function plan_price(float $p): string
{
    return '$' . (fmod($p, 1.0) == 0.0 ? number_format($p, 0) : number_format($p, 2));
}
?>
<!DOCTYPE html>
<html lang="en" class="scroll-smooth">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Restaurant POS — run your restaurant from one screen</title>
    <meta name="description" content="Point of sale for cafés and restaurants: POS terminal, kitchen screen, cash drawer, mobile money and reports. Free <?= TRIAL_DAYS ?>-day trial.">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap">
    <link rel="stylesheet" href="<?= url('assets/css/app.css') ?>">
</head>
<body class="bg-white text-ink font-sans antialiased">

<!-- ───────────────────────── Nav ───────────────────────── -->
<header class="sticky top-0 z-40 bg-white/85 backdrop-blur border-b border-line/70">
    <nav class="max-w-6xl mx-auto px-4 sm:px-6 h-16 flex items-center justify-between">
        <a href="#top" class="flex items-center gap-2 no-underline">
            <span class="w-9 h-9 rounded-xl bg-gradient-to-br from-brand to-brand-dark text-white grid place-items-center text-lg shadow-sm">☕</span>
            <span class="font-bold text-lg tracking-tight text-ink">Restaurant<span class="text-brand">POS</span></span>
        </a>
        <div class="hidden md:flex items-center gap-8 text-sm font-medium text-muted">
            <a href="#features" class="hover:text-brand-dark transition-colors">Features</a>
            <a href="#how" class="hover:text-brand-dark transition-colors">How it works</a>
            <a href="#pricing" class="hover:text-brand-dark transition-colors">Pricing</a>
            <a href="#faq" class="hover:text-brand-dark transition-colors">FAQ</a>
        </div>
        <div class="hidden md:flex items-center gap-3">
            <a href="<?= $login ?>" class="text-sm font-semibold text-ink hover:text-brand-dark px-3 py-2">Sign in</a>
            <a href="<?= $register ?>" class="text-sm font-semibold text-white bg-brand hover:bg-brand-dark rounded-lg px-4 py-2.5 shadow-sm transition-colors">Start free trial</a>
        </div>
        <button type="button" id="menuBtn" class="md:hidden w-10 h-10 grid place-items-center rounded-lg border border-line" aria-label="Open menu" aria-expanded="false">
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M4 7h16M4 12h16M4 17h16"/></svg>
        </button>
    </nav>
    <div id="mobileMenu" class="hidden md:hidden border-t border-line bg-white px-4 pb-4">
        <div class="flex flex-col py-2 text-sm font-medium">
            <a href="#features" class="py-2.5">Features</a>
            <a href="#how" class="py-2.5">How it works</a>
            <a href="#pricing" class="py-2.5">Pricing</a>
            <a href="#faq" class="py-2.5">FAQ</a>
        </div>
        <div class="grid grid-cols-2 gap-2">
            <a href="<?= $login ?>" class="text-center text-sm font-semibold border border-line rounded-lg py-2.5">Sign in</a>
            <a href="<?= $register ?>" class="text-center text-sm font-semibold text-white bg-brand rounded-lg py-2.5">Start free trial</a>
        </div>
    </div>
</header>

<main id="top">

<!-- ───────────────────────── Hero ───────────────────────── -->
<section class="relative overflow-hidden">
    <div class="absolute inset-0 -z-10 bg-gradient-to-b from-brand-light via-white to-white"></div>
    <div class="absolute -top-24 -right-24 -z-10 w-[28rem] h-[28rem] rounded-full bg-accent/20 blur-3xl"></div>
    <div class="absolute top-40 -left-32 -z-10 w-[24rem] h-[24rem] rounded-full bg-brand/10 blur-3xl"></div>

    <div class="max-w-6xl mx-auto px-4 sm:px-6 pt-16 pb-20 lg:pt-24 lg:pb-28 grid lg:grid-cols-2 gap-14 items-center">
        <div>
            <span class="inline-flex items-center gap-2 text-xs font-semibold text-brand-dark bg-white border border-line rounded-full px-3 py-1.5 shadow-sm">
                <span class="w-2 h-2 rounded-full bg-ok"></span>
                Free for <?= TRIAL_DAYS ?> days · no card needed
            </span>
            <h1 class="mt-6 text-4xl sm:text-5xl lg:text-[3.4rem] font-extrabold tracking-tight leading-[1.08]">
                Run your restaurant<br class="hidden sm:block">
                <span class="bg-gradient-to-r from-brand to-accent bg-clip-text text-transparent">from one screen.</span>
            </h1>
            <p class="mt-6 text-lg text-muted leading-relaxed max-w-xl">
                Take orders, send them to the kitchen, get paid in cash or by mobile money,
                and close the day knowing every dollar is accounted for.
            </p>
            <div class="mt-8 flex flex-col sm:flex-row gap-3">
                <a href="<?= $register ?>" class="inline-flex justify-center items-center gap-2 text-base font-semibold text-white bg-brand hover:bg-brand-dark rounded-xl px-6 py-3.5 shadow-lg shadow-brand/20 transition-colors">
                    Register your restaurant
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12h14M13 6l6 6-6 6"/></svg>
                </a>
                <a href="<?= $login ?>" class="inline-flex justify-center items-center text-base font-semibold text-ink bg-white border border-line hover:border-brand rounded-xl px-6 py-3.5 transition-colors">
                    Sign in to your restaurant
                </a>
            </div>
            <ul class="mt-8 flex flex-wrap gap-x-6 gap-y-2 text-sm text-muted">
                <li class="flex items-center gap-1.5"><span class="text-ok font-bold">✓</span> Set up in minutes</li>
                <li class="flex items-center gap-1.5"><span class="text-ok font-bold">✓</span> Works on any device</li>
                <li class="flex items-center gap-1.5"><span class="text-ok font-bold">✓</span> Your data stays private</li>
            </ul>
        </div>

        <!-- Product preview: an illustration of the POS terminal, drawn in HTML -->
        <div class="relative" aria-hidden="true">
            <div class="relative rounded-2xl bg-white border border-line shadow-2xl shadow-brand-dark/10 overflow-hidden">
                <div class="h-11 bg-gradient-to-r from-brand-dark to-brand flex items-center justify-between px-4 text-white">
                    <div class="flex items-center gap-2 text-sm font-semibold"><span>☕</span> Your Café</div>
                    <div class="flex gap-1.5"><span class="w-2.5 h-2.5 rounded-full bg-white/40"></span><span class="w-2.5 h-2.5 rounded-full bg-white/40"></span><span class="w-2.5 h-2.5 rounded-full bg-accent"></span></div>
                </div>
                <div class="grid grid-cols-5">
                    <div class="col-span-3 p-4 bg-[#faf7f3]">
                        <div class="flex gap-1.5 mb-3 text-[11px] font-semibold">
                            <span class="px-2.5 py-1 rounded-full bg-brand text-white">Hot drinks</span>
                            <span class="px-2.5 py-1 rounded-full bg-white border border-line">Breakfast</span>
                            <span class="px-2.5 py-1 rounded-full bg-white border border-line">Mains</span>
                        </div>
                        <div class="grid grid-cols-2 gap-2">
                            <?php foreach ([['Milk tea', '0.50'], ['Coffee', '1.00'], ['Spiced tea', '0.70'], ['Mango juice', '1.50'], ['Omelette', '2.00'], ['Rice & beef', '4.00']] as $i => [$n, $p]): ?>
                                <div class="rounded-lg bg-white border <?= $i === 1 ? 'border-accent ring-2 ring-accent/30' : 'border-line' ?> p-2.5">
                                    <div class="text-[12px] font-semibold leading-tight"><?= $n ?></div>
                                    <div class="text-[12px] font-bold text-brand mt-2">$<?= $p ?></div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <div class="col-span-2 border-l border-line p-4 flex flex-col">
                        <div class="text-[11px] uppercase tracking-wider text-muted font-semibold">Table 4</div>
                        <div class="mt-2 space-y-2 text-[12px] flex-1">
                            <div class="flex justify-between"><span>2 × Milk tea</span><span class="font-semibold">1.00</span></div>
                            <div class="flex justify-between"><span>1 × Coffee</span><span class="font-semibold">1.00</span></div>
                            <div class="flex justify-between"><span>1 × Omelette</span><span class="font-semibold">2.00</span></div>
                        </div>
                        <div class="border-t border-dashed border-line pt-2 mt-3 text-[12px] flex justify-between font-bold">
                            <span>Total</span><span>$4.00</span>
                        </div>
                        <div class="mt-3 rounded-lg bg-accent text-ink text-center text-[12px] font-bold py-2">Charge &amp; print</div>
                    </div>
                </div>
            </div>
            <!-- floating cards -->
            <div class="hidden sm:flex absolute -left-6 -bottom-14 bg-white border border-line rounded-xl shadow-xl px-4 py-3 items-center gap-3">
                <span class="w-9 h-9 rounded-lg bg-ok/10 text-ok grid place-items-center">✓</span>
                <div class="text-xs leading-tight"><div class="font-bold text-sm">Drawer balanced</div><div class="text-muted">Shift closed · $0.00 variance</div></div>
            </div>
            <div class="hidden sm:flex absolute -right-4 -top-5 bg-white border border-line rounded-xl shadow-xl px-4 py-3 items-center gap-3">
                <span class="text-xl">👨‍🍳</span>
                <div class="text-xs leading-tight"><div class="font-bold text-sm">Sent to kitchen</div><div class="text-muted">Table 4 · 3 items</div></div>
            </div>
        </div>
    </div>
</section>

<!-- ───────────────────────── Built for ───────────────────────── -->
<section class="border-y border-line bg-[#faf7f3]">
    <div class="max-w-6xl mx-auto px-4 sm:px-6 py-6 flex flex-wrap justify-center gap-x-10 gap-y-3 text-sm font-semibold text-muted">
        <span>Built for</span>
        <span class="text-ink">☕ Tea shops</span>
        <span class="text-ink">🥐 Cafés</span>
        <span class="text-ink">🍛 Restaurants</span>
        <span class="text-ink">🥤 Juice bars</span>
        <span class="text-ink">🍔 Fast food</span>
    </div>
</section>

<!-- ───────────────────────── Features ───────────────────────── -->
<section id="features" class="py-20 lg:py-28 scroll-mt-16">
    <div class="max-w-6xl mx-auto px-4 sm:px-6">
        <div class="max-w-2xl mx-auto text-center">
            <p class="text-sm font-bold uppercase tracking-widest text-brand">Everything in one place</p>
            <h2 class="mt-3 text-3xl sm:text-4xl font-extrabold tracking-tight">From the first order to the end-of-day count</h2>
            <p class="mt-4 text-muted text-lg">No separate apps for the till, the kitchen and the books. One system your whole team uses.</p>
        </div>
        <div class="mt-14 grid sm:grid-cols-2 lg:grid-cols-3 gap-5">
            <?php foreach ($features as [$icon, $title, $text]): ?>
                <div class="group rounded-2xl border border-line bg-white p-6 hover:shadow-xl hover:shadow-brand-dark/5 hover:-translate-y-0.5 transition-all duration-200">
                    <div class="w-12 h-12 rounded-xl bg-brand-light grid place-items-center text-2xl group-hover:scale-110 transition-transform"><?= $icon ?></div>
                    <h3 class="mt-5 text-lg font-bold"><?= e($title) ?></h3>
                    <p class="mt-2 text-muted leading-relaxed text-[15px]"><?= e($text) ?></p>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
</section>

<!-- ───────────────────────── Roles ───────────────────────── -->
<section class="py-20 lg:py-24 bg-gradient-to-br from-brand-dark to-brand text-white">
    <div class="max-w-6xl mx-auto px-4 sm:px-6 grid lg:grid-cols-2 gap-12 items-center">
        <div>
            <p class="text-sm font-bold uppercase tracking-widest text-accent">Your whole team</p>
            <h2 class="mt-3 text-3xl sm:text-4xl font-extrabold tracking-tight">Everyone sees only what their job needs</h2>
            <p class="mt-4 text-white/80 text-lg leading-relaxed">
                Give each person their own sign-in. Waiters can't take money, the kitchen only sees
                orders, and only you see the profit.
            </p>
            <a href="<?= $register ?>" class="mt-8 inline-flex items-center gap-2 font-semibold bg-accent text-ink rounded-xl px-6 py-3.5 hover:bg-white transition-colors">
                Set up your team
            </a>
        </div>
        <div class="grid sm:grid-cols-2 gap-4">
            <?php foreach ($roles as [$role, $text]): ?>
                <div class="rounded-2xl bg-white/10 border border-white/15 p-5 backdrop-blur">
                    <div class="text-base font-bold"><?= e($role) ?></div>
                    <p class="mt-1.5 text-sm text-white/75 leading-relaxed"><?= e($text) ?></p>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
</section>

<!-- ───────────────────────── How it works ───────────────────────── -->
<section id="how" class="py-20 lg:py-28 scroll-mt-16">
    <div class="max-w-6xl mx-auto px-4 sm:px-6">
        <div class="max-w-2xl mx-auto text-center">
            <p class="text-sm font-bold uppercase tracking-widest text-brand">How it works</p>
            <h2 class="mt-3 text-3xl sm:text-4xl font-extrabold tracking-tight">Open for business today</h2>
        </div>
        <ol class="mt-14 grid md:grid-cols-3 gap-6 list-none p-0">
            <?php foreach ([
                ['Register your restaurant', 'Enter your restaurant name and create your admin account. Your trial starts at once.'],
                ['Add your menu and staff', 'Add categories, items and prices, then an account for each cashier, waiter and cook.'],
                ['Start selling', 'Open a cash drawer shift, take the first order and watch it appear in the kitchen.'],
            ] as $i => [$title, $text]): ?>
                <li class="relative rounded-2xl border border-line p-7 bg-white">
                    <span class="absolute -top-4 left-7 w-9 h-9 rounded-full bg-brand text-white font-bold grid place-items-center shadow-md"><?= $i + 1 ?></span>
                    <h3 class="mt-3 text-lg font-bold"><?= e($title) ?></h3>
                    <p class="mt-2 text-muted leading-relaxed"><?= e($text) ?></p>
                </li>
            <?php endforeach; ?>
        </ol>
    </div>
</section>

<!-- ───────────────────────── Pricing ───────────────────────── -->
<section id="pricing" class="py-20 lg:py-28 bg-[#faf7f3] border-y border-line scroll-mt-16">
    <div class="max-w-6xl mx-auto px-4 sm:px-6">
        <div class="max-w-2xl mx-auto text-center">
            <p class="text-sm font-bold uppercase tracking-widest text-brand">Pricing</p>
            <h2 class="mt-3 text-3xl sm:text-4xl font-extrabold tracking-tight">Simple monthly plans</h2>
            <p class="mt-4 text-muted text-lg">Start free for <?= TRIAL_DAYS ?> days. Pick a plan when you're ready.</p>
        </div>
        <div class="mt-14 grid gap-6 <?= count($plans) >= 3 ? 'lg:grid-cols-3' : 'md:grid-cols-2' ?> max-w-5xl mx-auto">
            <?php foreach ($plans as $p): ?>
                <?php
                $isFree     = (float)$p['price_month'] <= 0;
                $isFeatured = (int)$p['id'] === (int)$featured;
                ?>
                <div class="relative rounded-2xl bg-white p-8 flex flex-col <?= $isFeatured ? 'border-2 border-brand shadow-2xl shadow-brand-dark/10 lg:-translate-y-2' : 'border border-line' ?>">
                    <?php if ($isFeatured): ?>
                        <span class="absolute -top-3.5 left-1/2 -translate-x-1/2 text-xs font-bold uppercase tracking-wider bg-brand text-white rounded-full px-3 py-1">Most popular</span>
                    <?php endif; ?>
                    <h3 class="text-lg font-bold"><?= e($p['name']) ?></h3>
                    <div class="mt-4 flex items-baseline gap-1">
                        <span class="text-4xl font-extrabold tracking-tight"><?= $isFree ? 'Free' : plan_price((float)$p['price_month']) ?></span>
                        <span class="text-muted"><?= $isFree ? 'for ' . TRIAL_DAYS . ' days' : '/ month' ?></span>
                    </div>
                    <ul class="mt-6 space-y-3 text-[15px] flex-1">
                        <li class="flex gap-2"><span class="text-ok font-bold">✓</span>
                            <?= $p['max_users'] === null ? 'Unlimited staff accounts' : (int)$p['max_users'] . ' staff accounts' ?></li>
                        <li class="flex gap-2"><span class="text-ok font-bold">✓</span>
                            <?= $p['max_menu_items'] === null ? 'Unlimited menu items' : (int)$p['max_menu_items'] . ' menu items' ?></li>
                        <li class="flex gap-2"><span class="text-ok font-bold">✓</span> POS, kitchen screen &amp; cash drawer</li>
                        <li class="flex gap-2"><span class="text-ok font-bold">✓</span> Mobile money dial codes</li>
                        <li class="flex gap-2"><span class="text-ok font-bold">✓</span> All reports &amp; CSV export</li>
                    </ul>
                    <a href="<?= $register ?>"
                       class="mt-8 text-center font-semibold rounded-xl py-3 transition-colors <?= $isFeatured ? 'bg-brand text-white hover:bg-brand-dark' : 'border border-line hover:border-brand text-ink' ?>">
                        <?= $isFree ? 'Start free trial' : 'Start with a free trial' ?>
                    </a>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
</section>

<!-- ───────────────────────── FAQ ───────────────────────── -->
<section id="faq" class="py-20 lg:py-28 scroll-mt-16">
    <div class="max-w-3xl mx-auto px-4 sm:px-6">
        <div class="text-center">
            <p class="text-sm font-bold uppercase tracking-widest text-brand">FAQ</p>
            <h2 class="mt-3 text-3xl sm:text-4xl font-extrabold tracking-tight">Questions, answered</h2>
        </div>
        <div class="mt-12 divide-y divide-line border-y border-line">
            <?php foreach ($faqs as [$q, $a]): ?>
                <details class="group py-5">
                    <summary class="flex justify-between items-center gap-4 cursor-pointer list-none font-semibold text-lg">
                        <?= e($q) ?>
                        <span class="flex-none w-8 h-8 rounded-full border border-line grid place-items-center text-muted group-open:rotate-45 transition-transform">+</span>
                    </summary>
                    <p class="mt-3 text-muted leading-relaxed pr-12"><?= e($a) ?></p>
                </details>
            <?php endforeach; ?>
        </div>
    </div>
</section>

<!-- ───────────────────────── Final CTA ───────────────────────── -->
<section class="px-4 sm:px-6 pb-20 lg:pb-28">
    <div class="max-w-6xl mx-auto relative overflow-hidden rounded-3xl bg-gradient-to-br from-ink via-brand-dark to-brand px-6 py-16 sm:px-16 text-center text-white">
        <div class="absolute -top-20 -right-20 w-72 h-72 rounded-full bg-accent/30 blur-3xl"></div>
        <h2 class="relative text-3xl sm:text-4xl font-extrabold tracking-tight">Ready to open the till?</h2>
        <p class="relative mt-4 text-white/80 text-lg max-w-xl mx-auto">
            Register your restaurant now and take your first order in minutes. Free for <?= TRIAL_DAYS ?> days.
        </p>
        <div class="relative mt-8 flex flex-col sm:flex-row justify-center gap-3">
            <a href="<?= $register ?>" class="inline-flex justify-center font-semibold bg-accent text-ink rounded-xl px-7 py-3.5 hover:bg-white transition-colors">Register your restaurant</a>
            <a href="<?= $login ?>" class="inline-flex justify-center font-semibold border border-white/30 rounded-xl px-7 py-3.5 hover:bg-white/10 transition-colors">I already have an account</a>
        </div>
    </div>
</section>

</main>

<!-- ───────────────────────── Footer ───────────────────────── -->
<footer class="border-t border-line">
    <div class="max-w-6xl mx-auto px-4 sm:px-6 py-10 flex flex-col md:flex-row gap-6 justify-between items-center text-sm text-muted">
        <div class="flex items-center gap-2">
            <span class="w-7 h-7 rounded-lg bg-gradient-to-br from-brand to-brand-dark text-white grid place-items-center text-sm">☕</span>
            <span class="font-semibold text-ink">Restaurant<span class="text-brand">POS</span></span>
            <span>· © <?= date('Y') ?></span>
        </div>
        <div class="flex gap-6">
            <a href="#features" class="hover:text-brand-dark">Features</a>
            <a href="#pricing" class="hover:text-brand-dark">Pricing</a>
            <a href="<?= $login ?>" class="hover:text-brand-dark">Sign in</a>
            <a href="<?= $register ?>" class="hover:text-brand-dark">Register</a>
        </div>
        <div>
            Made by <a href="https://sahanict.org" target="_blank" rel="noopener noreferrer" class="font-semibold text-brand hover:underline">SAHAN ICT</a>
        </div>
    </div>
</footer>

<script>
(function () {
    var btn = document.getElementById('menuBtn'), menu = document.getElementById('mobileMenu');
    btn.addEventListener('click', function () {
        var open = menu.classList.toggle('hidden') === false;
        btn.setAttribute('aria-expanded', open ? 'true' : 'false');
    });
    menu.querySelectorAll('a[href^="#"]').forEach(function (a) {
        a.addEventListener('click', function () { menu.classList.add('hidden'); });
    });
})();
</script>
</body>
</html>
