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
</head>
<body class="bg-[#faf7f3] text-ink font-sans antialiased layout-<?= e($layout) ?>">

<?php if ($layout !== 'blank'): ?>
<nav class="topbar h-[60px] bg-gradient-to-r from-brand-dark to-brand text-white flex items-center justify-between px-4 sticky top-0 z-30 shadow-md">
    <div class="flex items-center gap-2.5 text-sm leading-tight">
        <span class="text-2xl">☕</span>
        <span>
            <strong class="font-semibold"><?= e($brandName) ?></strong>
            <small class="block opacity-75 text-xs"><?= e($brandTag) ?></small>
        </span>
    </div>

    <div class="flex items-center gap-2">
        <?php if ($u && has_role('cashier', 'waiter', 'admin')): ?>
            <a class="btn btn-accent btn-sm" href="<?= url('public/pos.php') ?>">POS Terminal</a>
        <?php endif; ?>
        <?php if ($u && has_role('kitchen', 'admin')): ?>
            <a class="btn btn-outline btn-sm border-white/40 text-white hover:bg-white/10" href="<?= url('public/kitchen.php') ?>">Kitchen</a>
        <?php endif; ?>
        <?php if ($u): ?>
            <span class="text-xs leading-tight text-right opacity-90">
                <?= e($u['full_name']) ?><br>
                <span class="opacity-70"><?= e(ucfirst($u['role'])) ?></span>
            </span>
            <a class="btn btn-outline btn-sm border-white/40 text-white hover:bg-white/10" href="<?= url('public/logout.php') ?>">Sign out</a>
        <?php endif; ?>
        <?php if ($platform && platform_user()): ?>
            <span class="text-xs leading-tight text-right opacity-90">
                <?= e(platform_user()['full_name']) ?><br>
                <span class="opacity-70">Platform owner</span>
            </span>
            <a class="btn btn-outline btn-sm border-white/40 text-white hover:bg-white/10" href="<?= url('platform/logout.php') ?>">Sign out</a>
        <?php endif; ?>
    </div>
</nav>
<?php endif; ?>

<?php if ($layout === 'app'): ?>
<div class="flex items-stretch min-h-[calc(100vh-60px)]">
    <?php require $platform ? __DIR__ . '/../platform/_sidebar.php' : __DIR__ . '/sidebar.php'; ?>
    <main class="flex-1 min-w-0 px-6 py-5 flex flex-col justify-between">
        <div class="flex-1">
            <div class="flex items-center justify-between gap-3 mb-5 flex-wrap">
                <h1 class="text-xl font-semibold m-0"><?= e($pageTitle) ?></h1>
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
