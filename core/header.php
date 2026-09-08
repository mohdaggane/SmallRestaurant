<?php
/**
 * Opens every page: <head>, top bar, and (for the "app" layout) the sidebar.
 * Pages set these before including it:
 *   $pageTitle  string  browser + page heading
 *   $layout     string  'app'   sidebar + content   (default)
 *                       'wide'  top bar only, full width (POS, kitchen)
 *                       'blank' nothing but the page (login, receipt)
 */

if (!defined('BASE_URL')) {
    require_once __DIR__ . '/config.php';
}

$pageTitle = $pageTitle ?? 'Dashboard';
$layout    = $layout    ?? 'app';
$u         = current_user();
?>
<!DOCTYPE html>
<html lang="en" data-bs-theme="light">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e($pageTitle) ?> · <?= e(setting('shop_name', 'Small Restaurant')) ?></title>
    <link rel="stylesheet" href="<?= url('assets/vendor/bootstrap.min.css') ?>">
    <link rel="stylesheet" href="<?= url('assets/css/app.css') ?>">
</head>
<body class="layout-<?= e($layout) ?>">

<?php if ($layout !== 'blank'): ?>
<nav class="topbar">
    <div class="topbar-brand">
        <span class="brand-mark">☕</span>
        <span>
            <strong><?= e(setting('shop_name', 'Small Restaurant')) ?></strong>
            <small><?= e(setting('shop_tagline', 'Tea &amp; Food')) ?></small>
        </span>
    </div>

    <div class="topbar-actions">
        <?php if ($u && has_role('cashier', 'waiter', 'admin')): ?>
            <a class="btn btn-sm btn-warning" href="<?= url('public/pos.php') ?>">POS Terminal</a>
        <?php endif; ?>
        <?php if ($u && has_role('kitchen', 'admin')): ?>
            <a class="btn btn-sm btn-outline-light" href="<?= url('public/kitchen.php') ?>">Kitchen</a>
        <?php endif; ?>
        <?php if ($u): ?>
            <span class="topbar-user">
                <?= e($u['full_name']) ?><br>
                <small><?= e(ucfirst($u['role'])) ?></small>
            </span>
            <a class="btn btn-sm btn-outline-light" href="<?= url('public/logout.php') ?>">Sign out</a>
        <?php endif; ?>
    </div>
</nav>
<?php endif; ?>

<?php if ($layout === 'app'): ?>
<div class="app-shell">
    <?php require __DIR__ . '/sidebar.php'; ?>
    <main class="app-main">
        <div class="page-head">
            <h1><?= e($pageTitle) ?></h1>
            <?php if (!empty($pageActions)) echo $pageActions; ?>
        </div>
        <?= render_flashes() ?>
<?php elseif ($layout === 'wide'): ?>
    <main class="wide-main">
        <?= render_flashes() ?>
<?php else: ?>
    <main class="blank-main">
<?php endif; ?>
