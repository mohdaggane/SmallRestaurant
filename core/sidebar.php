<?php
/**
 * Role-aware navigation. Rendered by core/header.php in the "app" layout.
 * Each entry is [label, path, icon, roles-that-see-it]; admin sees everything.
 */

$current = basename($_SERVER['SCRIPT_NAME'] ?? '');

$nav = [
    ['Dashboard',    'admin/index.php',        '📊', ['admin']],
    ['POS Terminal', 'public/pos.php',         '🧾', ['admin', 'cashier', 'waiter']],
    ['Open Orders',  'public/orders.php',      '🍽️', ['admin', 'cashier', 'waiter']],
    ['Kitchen',      'public/kitchen.php',     '👨‍🍳', ['admin', 'kitchen']],
    ['Sales',        'admin/sales.php',        '💵', ['admin', 'cashier']],
    ['Menu Items',   'admin/menu_items.php',   '🥘', ['admin']],
    ['Categories',   'admin/categories.php',   '🗂️', ['admin']],
    ['Expenses',     'admin/expenses.php',     '📉', ['admin', 'cashier']],
    ['Cash Drawer',  'admin/shifts.php',       '🧮', ['admin', 'cashier']],
    ['Reports',      'admin/report_daily.php', '📈', ['admin', 'cashier']],
    ['Users',        'admin/users.php',        '👥', ['admin']],
    ['Settings',     'admin/settings.php',     '⚙️', ['admin']],
    ['Billing',      'admin/billing.php',      '💳', ['admin']],
    ['System Reset', 'admin/system_reset.php', '🗑️', ['admin']],
];
?>
<aside class="w-[212px] flex-none bg-white border-r border-line py-3">
    <ul class="list-none m-0 p-0">
        <?php foreach ($nav as [$label, $path, $icon, $roles]): ?>
            <?php if (!has_role(...$roles)) continue; ?>
            <?php
            // Every report tab (report_*.php and the overview) lights up "Reports".
            $isActive = basename($path) === $current
                || ($label === 'Reports' && (str_starts_with($current, 'report_') || $current === 'reports.php'));
            $isReset  = $label === 'System Reset';
            ?>
            <li>
                <a href="<?= url($path) ?>"
                   class="flex items-center gap-2.5 px-4 py-[11px] text-sm no-underline transition-colors duration-150
                          border-l-[3px]
                          <?php if ($isActive): ?>
                              bg-brand-light border-l-accent font-semibold text-brand-dark
                          <?php elseif ($isReset): ?>
                              border-l-transparent text-bad hover:bg-red-50
                          <?php else: ?>
                              border-l-transparent text-ink hover:bg-brand-light
                          <?php endif; ?>">
                    <span class="w-5 text-center"><?= $icon ?></span>
                    <?= e($label) ?>
                </a>
            </li>
        <?php endforeach; ?>
    </ul>
</aside>
