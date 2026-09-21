<?php
/**
 * Sign-in screen. Username + password only: usernames are unique across the
 * platform, so the user row says which restaurant (company) they belong to.
 * Sends each role to its own home page.
 */

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
        $user = db_one(
            'SELECT u.*, c.name AS company_name, c.status AS company_status, c.trial_ends_at, c.paid_until
               FROM users u JOIN companies c ON c.id = u.company_id
              WHERE u.username = ? LIMIT 1',
            [$username]
        );
        // Checked only after the password is right, so a stranger learns nothing about the company.
        $access = $user ? company_access([
            'status'        => $user['company_status'],
            'trial_ends_at' => $user['trial_ends_at'],
            'paid_until'    => $user['paid_until'],
        ]) : 'ok';

        if ($user && !(int)$user['is_active']) {
            $error = 'That account has been disabled. Ask the administrator.';
        } elseif ($user && password_verify($password, $user['password_hash'])
                  && $access !== 'ok' && ($user['role'] !== 'admin' || $access === 'suspended')) {
            // Blocked company: staff stay out; the admin (below) is let in to the billing page.
            unset($_SESSION['login_fails'], $_SESSION['login_locked_until']);
            $error = company_block_message($access);
        } elseif ($user && password_verify($password, $user['password_hash'])) {
            unset($_SESSION['login_fails'], $_SESSION['login_locked_until']);
            login_user($user);
            flash('Welcome back, ' . $user['full_name'] . '.');
            $back = $_SESSION['redirect_after_login'] ?? '';
            unset($_SESSION['redirect_after_login']);
            if ($access !== 'ok') {
                // Lapsed trial or subscription: the admin may only see how to renew.
                flash(company_block_message($access), 'warning');
                redirect('admin/billing.php');
            }
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
<div class="min-h-screen flex items-center justify-center bg-gradient-to-br from-brand-dark via-brand to-accent p-5">
    <div class="w-full max-w-sm bg-white rounded-2xl p-8 shadow-2xl">
        <a href="<?= url('') ?>" class="block text-center text-5xl mb-3 no-underline" title="Back to the home page">☕</a>
        <h1 class="text-2xl font-bold text-center text-ink mb-0.5">
            Restaurant POS
        </h1>
        <p class="text-center text-muted text-sm mb-6">
            Sign in to your restaurant
        </p>

        <?= render_flashes() ?>

        <?php if ($error !== ''): ?>
            <div class="flash-danger"><?= e($error) ?></div>
        <?php endif; ?>

        <form method="post" autocomplete="off" class="space-y-4">
            <?= csrf_field() ?>
            <div>
                <label class="label" for="login_username">Username</label>
                <input type="text" id="login_username" name="username" class="input input-lg"
                       value="<?= e($username) ?>" autofocus required>
            </div>
            <div>
                <label class="label" for="login_password">Password</label>
                <input type="password" id="login_password" name="password" class="input input-lg" required>
            </div>
            <button class="btn btn-brand btn-lg w-full mt-2">Sign in</button>
        </form>

        <p class="text-center text-muted mt-5 mb-0 text-sm">
            New restaurant? <a href="<?= url('public/register.php') ?>" class="font-semibold text-brand-dark underline">Register your company</a>
        </p>
    </div>
    <div class="text-center text-xs text-white/80 mt-4 absolute bottom-4">
        Made By <a href="https://sahanict.org" target="_blank" rel="noopener noreferrer" class="font-semibold text-white underline hover:text-white/90">SAHAN ICT</a> · <a href="https://sahanict.org" target="_blank" rel="noopener noreferrer" class="text-white/80 hover:text-white underline">sahanict.org</a>
    </div>
</div>
<?php require __DIR__ . '/../core/footer.php'; ?>
