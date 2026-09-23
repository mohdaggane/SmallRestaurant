<?php
/**
 * Navigation for the platform owner's panel. Rendered by core/header.php
 * instead of core/sidebar.php when a page sets $platform = true.
 * On mobile: slide-in drawer. On md+: static sidebar.
 */

$current = basename($_SERVER['SCRIPT_NAME'] ?? '');

$nav = [
    [__('pf.restaurants', 'Restaurants'), 'platform/index.php', '🏪', ['index.php', 'company.php']],
    [__('pf.plans',       'Plans'),       'platform/plans.php', '📦', ['plans.php']],
    [__('pf.settings',    'Settings'),    'platform/settings.php', '⚙️', ['settings.php']],
];
?>
<aside id="appSidebar" class="app-sidebar">
    <!-- Brand shown inside sidebar on mobile -->
    <div class="flex items-center gap-2 px-4 py-3 border-b border-line md:hidden">
        <?php if (platform_logo_url()): ?>
            <img src="<?= e(platform_logo_url()) ?>" alt="Logo" class="w-8 h-8 rounded-lg object-contain">
        <?php else: ?>
            <div class="w-8 h-8 rounded-lg flex items-center justify-center text-white" style="background: linear-gradient(135deg, #1e2f6e, #1a7fe8);">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none">
                    <path d="M17 6s-2-2-5-2-5 1.8-5 4c0 2.5 2.5 3.5 5 4.5s5 2 5 4.5c0 2.2-2 3-5 3s-5-2-5-2"
                          stroke="#fff" stroke-width="2.6" stroke-linecap="round"/>
                </svg>
            </div>
        <?php endif; ?>
        <div class="min-w-0">
            <div class="font-bold text-sm text-ink truncate"><?= e(platform_setting('company_name', 'SAHAN ICT')) ?></div>
            <div class="text-[10px] text-muted font-semibold uppercase tracking-wider truncate"><?= e(platform_setting('system_name', 'Restaurant POS')) ?></div>
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
