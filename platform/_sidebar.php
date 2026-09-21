<?php
/**
 * Navigation for the platform owner's panel. Rendered by core/header.php
 * instead of core/sidebar.php when a page sets $platform = true.
 */

$current = basename($_SERVER['SCRIPT_NAME'] ?? '');

$nav = [
    ['Restaurants', 'platform/index.php', '🏪', ['index.php', 'company.php']],
    ['Plans',       'platform/plans.php', '📦', ['plans.php']],
];
?>
<aside class="w-[212px] flex-none bg-white border-r border-line py-3">
    <ul class="list-none m-0 p-0">
        <?php foreach ($nav as [$label, $path, $icon, $pages]): ?>
            <?php $isActive = in_array($current, $pages, true); ?>
            <li>
                <a href="<?= url($path) ?>"
                   class="flex items-center gap-2.5 px-4 py-[11px] text-sm no-underline transition-colors duration-150
                          border-l-[3px]
                          <?= $isActive
                              ? 'bg-brand-light border-l-accent font-semibold text-brand-dark'
                              : 'border-l-transparent text-ink hover:bg-brand-light' ?>">
                    <span class="w-5 text-center"><?= $icon ?></span>
                    <?= e($label) ?>
                </a>
            </li>
        <?php endforeach; ?>
    </ul>
</aside>
