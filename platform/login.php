<?php
/** Sign-in for the platform owner (platform_admins), separate from restaurant logins. */

require_once __DIR__ . '/../core/config.php';

if (platform_user()) {
    redirect('platform/index.php');
}

$error    = '';
$username = '';

if (is_post()) {
    csrf_check();
    $username = post('username');
    $password = (string)($_POST['password'] ?? '');

    // Same throttle as the restaurant login: 5 failures locks the form for 60 seconds.
    $lockedUntil = (int)($_SESSION['platform_locked_until'] ?? 0);
    if ($lockedUntil > time()) {
        $error = 'Too many failed attempts. Try again in ' . ($lockedUntil - time()) . ' seconds.';
    } else {
        $admin = db_one('SELECT * FROM platform_admins WHERE username = ? AND is_active = 1 LIMIT 1', [$username]);
        if ($admin && password_verify($password, $admin['password_hash'])) {
            unset($_SESSION['platform_fails'], $_SESSION['platform_locked_until']);
            platform_login($admin);
            redirect('platform/index.php');
        }
        $fails = (int)($_SESSION['platform_fails'] ?? 0) + 1;
        $_SESSION['platform_fails'] = $fails;
        if ($fails >= 5) {
            $_SESSION['platform_locked_until'] = time() + 60;
            $_SESSION['platform_fails'] = 0;
        }
        $error = 'Wrong username or password.';
    }
}

$pageTitle = 'Platform sign in';
$layout    = 'blank';
$platform  = true;
require __DIR__ . '/../core/header.php';
?>
<div class="min-h-screen flex items-center justify-center bg-gradient-to-br from-ink via-brand-dark to-brand p-5">
    <div class="w-full max-w-sm bg-white rounded-2xl p-8 shadow-2xl">
        <div class="text-center text-5xl mb-3">🏪</div>
        <h1 class="text-2xl font-bold text-center text-ink mb-0.5">Platform Admin</h1>
        <p class="text-center text-muted text-sm mb-6">Manage restaurants, plans and payments</p>

        <?php if ($error !== ''): ?>
            <div class="flash-danger"><?= e($error) ?></div>
        <?php endif; ?>

        <form method="post" autocomplete="off" class="space-y-4">
            <?= csrf_field() ?>
            <div>
                <label class="label" for="username">Username</label>
                <input type="text" id="username" name="username" class="input input-lg" value="<?= e($username) ?>" autofocus required>
            </div>
            <div>
                <label class="label" for="password">Password</label>
                <input type="password" id="password" name="password" class="input input-lg" required>
            </div>
            <button class="btn btn-brand btn-lg w-full mt-2">Sign in</button>
        </form>
    </div>
</div>
<?php require __DIR__ . '/../core/footer.php'; ?>
