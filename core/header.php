<?php
/**
 * Opens every page: <head>, top bar, and (for the "app" layout) the sidebar.
 * Pages set these before including it:
 *   $pageTitle  string  browser + page heading
 *   $layout     string  'app'   sidebar + content   (default)
 *                       'wide'  top bar only, full width (POS, kitchen)
 *                       'blank' nothing but the page (login, receipt)
 *   $platform   bool    true on the platform owner's pages (platform/): the
 *                       top bar and sidebar are the owner's, not a restaurant's
 */

if (!defined('BASE_URL')) {
    require_once __DIR__ . '/config.php';
}

$pageTitle = $pageTitle ?? 'Dashboard';
$layout    = $layout    ?? 'app';
$platform  = $platform ?? false;
$u         = $platform ? null : current_user();
$brandName = $platform ? 'Platform Admin' : setting('shop_name', 'Small Restaurant');
$brandTag  = $platform ? 'Restaurants · plans · payments' : setting('shop_tagline', 'Tea & Food');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e($pageTitle) ?> · <?= e($brandName) ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap">
    <link rel="stylesheet" href="<?= url('assets/css/app.css') ?>">
    <style>
        /* ── Mobile sidebar drawer ───────────────────────────── */
        html, body { overflow-x: hidden; }

        .app-sidebar {
            position: fixed;
            inset: 0 auto 0 0;
            width: 220px;
            background: #fff;
            border-right: 1px solid #e6dcd1;
            z-index: 50;
            transform: translateX(-100%);
            transition: transform .25s cubic-bezier(.4,0,.2,1);
            overflow-y: auto;
            padding-top: 3.75rem; /* clears the topbar */
        }
        .app-sidebar.open { transform: translateX(0); }

        /* Overlay behind the open drawer */
        .sidebar-overlay {
            display: none;
            position: fixed;
            inset: 0;
            background: rgba(0,0,0,.45);
            z-index: 49;
        }
        .sidebar-overlay.open { display: block; }

        /* On md+ screens: sidebar is static, overlay never shown */
        @media (min-width: 768px) {
            .app-sidebar {
                position: static;
                transform: none !important;
                padding-top: 0;
                flex-shrink: 0;
                z-index: auto;
            }
            .sidebar-overlay { display: none !important; }
            #sidebarToggle { display: none !important; }
        }

        /* Main content: full width on mobile (sidebar is overlay) */
        .app-main-wrap {
            display: flex;
            align-items: stretch;
            min-height: calc(100vh - 60px);
        }
        .app-content {
            flex: 1 1 0%;
            min-width: 0;
            padding: 1rem 0.875rem;
        }
        @media (min-width: 768px) {
            .app-content { padding: 1.25rem 1.5rem; }
        }

        /* Table scroll wrapper */
        .tbl-scroll { overflow-x: auto; -webkit-overflow-scrolling: touch; }
        .tbl-scroll::-webkit-scrollbar { height: 4px; }
        .tbl-scroll::-webkit-scrollbar-thumb { background: #e6dcd1; border-radius: 2px; }

        /* Topbar action buttons: hide labels on tiny screens */
        @media (max-width: 479px) {
            .topbar-action-label { display: none; }
        }

        /* Page title row */
        .page-title-row {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: .75rem;
            flex-wrap: wrap;
            margin-bottom: 1.25rem;
        }
        .page-title-row h1 { margin: 0; font-size: 1.1rem; font-weight: 600; }
    </style>
</head>
<body class="bg-[#faf7f3] text-ink font-sans antialiased layout-<?= e($layout) ?>">

<?php if ($layout !== 'blank'): ?>
<!-- ── Top bar ──────────────────────────────────────────────── -->
<nav class="topbar h-[60px] bg-gradient-to-r from-brand-dark to-brand text-white flex items-center justify-between px-3 sm:px-4 sticky top-0 z-30 shadow-md gap-2">

    <div class="flex items-center gap-2">
        <?php if ($layout === 'app'): ?>
        <!-- Hamburger (mobile only) -->
        <button id="sidebarToggle" type="button"
                class="w-9 h-9 flex items-center justify-center rounded-lg hover:bg-white/15 transition-colors flex-shrink-0"
                aria-label="Open menu" aria-expanded="false">
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round">
                <path d="M4 7h16M4 12h16M4 17h16"/>
            </svg>
        </button>
        <?php endif; ?>

        <a href="<?= url('') ?>" class="flex items-center gap-2 text-sm leading-tight no-underline text-white group min-w-0">
            <span class="text-2xl flex-shrink-0">☕</span>
            <span class="hidden sm:block min-w-0">
                <strong class="font-semibold block truncate"><?= e($brandName) ?></strong>
                <small class="block opacity-75 text-xs truncate"><?= e($brandTag) ?></small>
            </span>
        </a>
    </div>

    <!-- Right actions -->
    <div class="flex items-center gap-1.5 sm:gap-2 flex-shrink-0">
        <?php if ($u && has_role('cashier', 'waiter', 'admin')): ?>
            <a class="btn btn-accent btn-sm" href="<?= url('public/pos.php') ?>">
                <span class="topbar-action-label">POS</span>
                <span class="sm:hidden" title="POS Terminal">🧾</span>
                <span class="hidden sm:inline">Terminal</span>
            </a>
        <?php endif; ?>
        <?php if ($u && has_role('kitchen', 'admin')): ?>
            <a class="btn btn-outline btn-sm border-white/40 text-white hover:bg-white/10" href="<?= url('public/kitchen.php') ?>">
                <span class="hidden sm:inline">Kitchen</span>
                <span class="sm:hidden" title="Kitchen">👨‍🍳</span>
            </a>
        <?php endif; ?>
        <?php if ($u): ?>
            <span class="hidden sm:block text-xs leading-tight text-right opacity-90">
                <?= e($u['full_name']) ?><br>
                <span class="opacity-70"><?= e(ucfirst($u['role'])) ?></span>
            </span>
            <a class="btn btn-outline btn-sm border-white/40 text-white hover:bg-white/10" href="<?= url('public/logout.php') ?>">
                <span class="hidden sm:inline">Sign out</span>
                <span class="sm:hidden" title="Sign out">↪</span>
            </a>
        <?php endif; ?>
        <?php if ($platform && platform_user()): ?>
            <span class="hidden sm:block text-xs leading-tight text-right opacity-90">
                <?= e(platform_user()['full_name']) ?><br>
                <span class="opacity-70">Platform owner</span>
            </span>
            <a class="btn btn-outline btn-sm border-white/40 text-white hover:bg-white/10" href="<?= url('platform/logout.php') ?>">
                <span class="hidden sm:inline">Sign out</span>
                <span class="sm:hidden" title="Sign out">↪</span>
            </a>
        <?php endif; ?>
    </div>
</nav>

<!-- Mobile sidebar overlay -->
<?php if ($layout === 'app'): ?>
<div id="sidebarOverlay" class="sidebar-overlay" aria-hidden="true"></div>
<?php endif; ?>
<?php endif; ?>

<?php if ($layout === 'app'): ?>
<div class="app-main-wrap">
    <?php require $platform ? __DIR__ . '/../platform/_sidebar.php' : __DIR__ . '/sidebar.php'; ?>
    <main class="app-content flex flex-col justify-between">
        <div class="flex-1">
            <div class="page-title-row">
                <h1><?= e($pageTitle) ?></h1>
                <?php if (!empty($pageActions)) echo $pageActions; ?>
            </div>
            <?= render_flashes() ?>
<?php elseif ($layout === 'wide'): ?>
    <main class="p-3 min-h-[calc(100vh-60px)] flex flex-col justify-between">
        <div class="flex-1">
            <?= render_flashes() ?>
<?php else: ?>
    <main>
<?php endif; ?>
