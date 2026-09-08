<?php
/**
 * Role-aware navigation. Rendered by core/header.php in the "app" layout.
 * Each entry is [label, path, roles-that-see-it]; admin sees everything.
 */

$current = basename($_SERVER['SCRIPT_NAME'] ?? '');

$nav = [
    ['Dashboard',    'admin/index.php',      '📊', ['admin']],
    ['POS Terminal', 'public/pos.php',       '🧾', ['admin', 'cashier', 'waiter']],
    ['Open Orders',  'public/orders.php',    '🍽️', ['admin', 'cashier', 'waiter']],
    ['Kitchen',      'public/kitchen.php',   '👨‍🍳', ['admin', 'kitchen']],
    ['Sales',        'admin/sales.php',      '💵', ['admin', 'cashier']],
    ['Menu Items',   'admin/menu_items.php', '🥘', ['admin']],
    ['Categories',   'admin/categories.php', '🗂️', ['admin']],
    ['Expenses',     'admin/expenses.php',   '📉', ['admin', 'cashier']],
    ['Cash Drawer',  'admin/shifts.php',     '🧮', ['admin', 'cashier']],
    ['Reports',      'admin/reports.php',    '📈', ['admin']],
    ['Users',        'admin/users.php',      '👥', ['admin']],
    ['Settings',     'admin/settings.php',   '⚙️', ['admin']],
];
?>
<aside class="app-sidebar">
    <ul class="nav-list">
        <?php foreach ($nav as [$label, $path, $icon, $roles]): ?>
            <?php if (!has_role(...$roles)) continue; ?>
            <li>
                <a href="<?= url($path) ?>" class="<?= basename($path) === $current ? 'active' : '' ?>">
                    <span class="nav-icon"><?= $icon ?></span><?= e($label) ?>
                </a>
            </li>
        <?php endforeach; ?>
    </ul>
</aside>
