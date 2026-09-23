<?php
/**
 * Navigation for the platform owner's panel. Rendered by core/header.php
 * instead of core/sidebar.php when a page sets $platform = true.
 * On mobile: slide-in drawer. On md+: static sidebar.
 */

$current = basename($_SERVER['SCRIPT_NAME'] ?? '');

$nav = [
    ['Restaurants', 'platform/index.php', '🏪', ['index.php', 'company.php']],
    ['Plans',       'platform/plans.php', '📦', ['plans.php']],
];
?>
<aside id="appSidebar" class="app-sidebar">
    <!-- Brand shown inside sidebar on mobile -->
    <div class="flex items-center gap-2 px-4 py-3 border-b border-line md:hidden">
        <span class="text-xl">☕</span>
        <div class="min-w-0">
            <div class="font-semibold text-sm text-ink truncate">Platform Admin</div>
            <div class="text-xs text-muted truncate">Restaurants · plans · payments</div>
        </div>
    </div>

    <ul class="list-none m-0 p-0 py-2">
        <?php foreach ($nav as [$label, $path, $icon, $pages]): ?>
            <?php $isActive = in_array($current, $pages, true); ?>
            <li>
                <a href="<?= url($path) ?>"
                   class="flex items-center gap-2.5 px-4 py-[11px] text-sm no-underline transition-colors duration-150
                          border-l-[3px]
                          <?= $isActive
                              ? 'bg-brand-light border-l-accent font-semibold text-brand-dark'
                              : 'border-l-transparent text-ink hover:bg-brand-light' ?>">
                    <span class="w-5 text-center flex-shrink-0"><?= $icon ?></span>
                    <?= e($label) ?>
                </a>
            </li>
        <?php endforeach; ?>
    </ul>
</aside>

<script>
(function () {
    var toggle  = document.getElementById('sidebarToggle');
    var sidebar = document.getElementById('appSidebar');
    var overlay = document.getElementById('sidebarOverlay');
    if (!toggle || !sidebar) return;

    function openSidebar() {
        sidebar.classList.add('open');
        if (overlay) overlay.classList.add('open');
        toggle.setAttribute('aria-expanded', 'true');
        document.body.style.overflow = 'hidden';
    }
    function closeSidebar() {
        sidebar.classList.remove('open');
        if (overlay) overlay.classList.remove('open');
        toggle.setAttribute('aria-expanded', 'false');
        document.body.style.overflow = '';
    }

    toggle.addEventListener('click', function () {
        sidebar.classList.contains('open') ? closeSidebar() : openSidebar();
    });
    if (overlay) overlay.addEventListener('click', closeSidebar);

    sidebar.querySelectorAll('a').forEach(function (a) {
        a.addEventListener('click', function () {
            if (window.innerWidth < 768) closeSidebar();
        });
    });

    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape') closeSidebar();
    });
})();
</script>
