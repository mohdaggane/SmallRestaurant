<?php
/** Sign-in screen. Sends each role to its own home page. */

require_once __DIR__ . '/../core/config.php';

if (is_logged_in()) {
    redirect(home_for_role(user_role()));
}

$error    = '';
$username = '';

if (is_post()) {
    csrf_check();
    $username = post('username');
    $password = (string)($_POST['password'] ?? '');

    // Simple throttle: 5 failures locks the form for 60 seconds.
    $lockedUntil = (int)($_SESSION['login_locked_until'] ?? 0);
    if ($lockedUntil > time()) {
        $error = 'Too many failed attempts. Try again in ' . ($lockedUntil - time()) . ' seconds.';
    } elseif ($username === '' || $password === '') {
        $error = 'Enter both a username and a password.';
    } else {
        $user = db_one('SELECT * FROM users WHERE username = ? LIMIT 1', [$username]);

        if ($user && !(int)$user['is_active']) {
            $error = 'That account has been disabled. Ask the administrator.';
        } elseif ($user && password_verify($password, $user['password_hash'])) {
            unset($_SESSION['login_fails'], $_SESSION['login_locked_until']);
            login_user($user);
            flash('Welcome back, ' . $user['full_name'] . '.');
            $back = $_SESSION['redirect_after_login'] ?? '';
            unset($_SESSION['redirect_after_login']);
            redirect($back !== '' ? $back : home_for_role($user['role']));
        } else {
            $fails = (int)($_SESSION['login_fails'] ?? 0) + 1;
            $_SESSION['login_fails'] = $fails;
            if ($fails >= 5) {
                $_SESSION['login_locked_until'] = time() + 60;
                $_SESSION['login_fails'] = 0;
            }
            $error = 'Wrong username or password.';
        }
    }
}

$pageTitle = 'Sign in';
$layout    = 'blank';
require __DIR__ . '/../core/header.php';
?>
<div class="login-wrap">
    <div class="login-card">
        <div class="text-center mb-3" style="font-size:38px;">☕</div>
        <h1 class="text-center"><?= e(setting('shop_name', 'Small Restaurant')) ?></h1>
        <p class="sub text-center"><?= e(setting('shop_tagline', 'Tea & Food')) ?> · Point of Sale</p>

        <?php if ($error !== ''): ?>
            <div class="alert alert-danger py-2"><?= e($error) ?></div>
        <?php endif; ?>

        <form method="post" autocomplete="off">
            <?= csrf_field() ?>
            <div class="mb-3">
                <label class="form-label">Username</label>
                <input type="text" name="username" class="form-control form-control-lg"
                       value="<?= e($username) ?>" autofocus required>
            </div>
            <div class="mb-3">
                <label class="form-label">Password</label>
                <input type="password" name="password" class="form-control form-control-lg" required>
            </div>
            <button class="btn btn-lg w-100 text-white" style="background:var(--brand)">Sign in</button>
        </form>

        <p class="text-center text-muted mt-3 mb-0" style="font-size:12px;">
            First run? Sign in as <strong>admin</strong> / <strong>admin123</strong> and change the password.
        </p>
    </div>
</div>
<?php require __DIR__ . '/../core/footer.php'; ?>
