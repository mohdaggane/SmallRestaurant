<?php
/**
 * Self sign-up for a new restaurant. Creates, in one transaction:
 *   the company (on the trial plan), its admin account, its default settings
 *   and, if asked, a few starter menu categories
 * and then signs the owner straight in to the admin dashboard.
 *
 * Usernames are unique across every restaurant (login finds the company from
 * the username), so the form suggests names that end in the restaurant's slug.
 */

require_once __DIR__ . '/../core/config.php';

if (is_logged_in()) {
    redirect(home_for_role(user_role()));
}

/** "Hodan Café & Tea" -> "hodan-cafe-tea" */
function make_slug(string $text): string
{
    $ascii = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $text) ?: $text;
    // Some iconv builds spell é as 'e or e' — drop the stray accent marks.
    $ascii = str_replace(["'", '"', '`', '^', '~'], '', $ascii);
    $slug  = trim((string)preg_replace('/[^a-z0-9]+/', '-', strtolower($ascii)), '-');
    return substr($slug, 0, 30);
}

$STARTER_CATEGORIES = ['Hot Drinks', 'Cold Drinks', 'Breakfast', 'Main Dishes', 'Snacks'];

$f = [
    'company_name' => '', 'slug' => '', 'phone' => '',
    'full_name' => '', 'username' => '', 'starter' => '1',
];
$errors = [];

if (is_post()) {
    csrf_check();
    foreach (array_keys($f) as $k) {
        $f[$k] = post($k);
    }
    $f['starter']  = isset($_POST['starter']) ? '1' : '';
    $f['username'] = strtolower($f['username']);   // as admin/users.php stores them
    $f['slug']    = make_slug($f['slug'] !== '' ? $f['slug'] : $f['company_name']);
    $password     = (string)($_POST['password'] ?? '');
    $password2    = (string)($_POST['password2'] ?? '');

    // One sign-up per browser session a minute: stops a script from filling the table.
    $lastAt = (int)($_SESSION['registered_at'] ?? 0);

    if (post('website') !== '') {
        $errors[] = 'Sign-up refused.';          // honeypot: only bots fill the hidden field
    } elseif ($lastAt > time() - 60) {
        $errors[] = 'Please wait a minute before registering another restaurant.';
    }
    if (mb_strlen($f['company_name']) < 2 || mb_strlen($f['company_name']) > 100) {
        $errors[] = 'Enter the restaurant name.';
    }
    if (strlen($f['slug']) < 3) {
        $errors[] = 'The short name needs at least 3 letters or digits.';
    } elseif (db_value('SELECT id FROM companies WHERE slug = ?', [$f['slug']]) !== null) {
        $errors[] = 'Another restaurant already uses the short name "' . $f['slug'] . '". Choose another.';
    }
    if ($f['full_name'] === '') {
        $errors[] = 'Enter your full name.';
    }
    if (!preg_match('/^[A-Za-z0-9._@-]{3,50}$/', $f['username'])) {
        $errors[] = 'The username needs 3–50 letters, digits or . _ @ - (no spaces).';
    } elseif (db_value('SELECT id FROM users WHERE username = ?', [$f['username']]) !== null) { // tenant-global: usernames are unique system-wide
        $errors[] = 'The username "' . $f['username'] . '" is taken. Try "'
            . $f['username'] . '.' . ($f['slug'] ?: 'yourshop') . '".';
    }
    if (strlen($password) < 6) {
        $errors[] = 'Choose a password of at least 6 characters.';
    } elseif ($password !== $password2) {
        $errors[] = 'The two passwords do not match.';
    }

    if (!$errors) {
        $conn->begin_transaction();
        try {
            $companyId = db_exec(
                "INSERT INTO companies (name, slug, phone, plan_id, status, trial_ends_at)
                 VALUES (?,?,?,?, 'trial', ?)",
                [$f['company_name'], $f['slug'], $f['phone'] !== '' ? $f['phone'] : null,
                 TRIAL_PLAN_ID, date('Y-m-d', strtotime('+' . TRIAL_DAYS . ' days'))]
            );
            $userId = db_exec(
                "INSERT INTO users (company_id, full_name, username, password_hash, role) VALUES (?,?,?,?, 'admin')",
                [$companyId, $f['full_name'], $f['username'], password_hash($password, PASSWORD_DEFAULT)]
            );
            seed_company_settings($companyId, $f['company_name'], $f['phone']);
            if ($f['starter'] === '1') {
                foreach ($STARTER_CATEGORIES as $i => $cat) {
                    db_exec('INSERT INTO categories (company_id, name, sort_order) VALUES (?,?,?)', [$companyId, $cat, $i + 1]);
                }
            }
            $conn->commit();
        } catch (mysqli_sql_exception $e) {
            $conn->rollback();
            // 1062 = duplicate key: someone took the name or username a moment ago.
            $errors[] = $e->getCode() === 1062
                ? 'That short name or username was just taken. Please choose another.'
                : 'Could not create the restaurant: ' . $e->getMessage();
        }

        if (!$errors) {
            $_SESSION['registered_at'] = time();
            login_user([
                'id' => $userId, 'company_id' => $companyId, 'company_name' => $f['company_name'],
                'full_name' => $f['full_name'], 'username' => $f['username'], 'role' => 'admin',
            ]);
            flash('Welcome! ' . $f['company_name'] . ' is ready. Your free trial runs for ' . TRIAL_DAYS . ' days.');
            redirect('admin/index.php');
        }
    }
}

$pageTitle = 'Register your restaurant';
$layout    = 'blank';
require __DIR__ . '/../core/header.php';
?>
<div class="min-h-screen flex items-center justify-center bg-gradient-to-br from-brand-dark via-brand to-accent p-5">
    <div class="w-full max-w-lg bg-white rounded-2xl p-8 shadow-2xl">
        <a href="<?= url('') ?>" class="block text-center text-5xl mb-3 no-underline" title="Back to the home page">☕</a>
        <h1 class="text-2xl font-bold text-center text-ink mb-0.5">Register your restaurant</h1>
        <p class="text-center text-muted text-sm mb-6">
            Free for <?= TRIAL_DAYS ?> days. Your menu, staff and sales stay private to your restaurant.
        </p>

        <?php foreach ($errors as $err): ?>
            <div class="flash-danger"><?= e($err) ?></div>
        <?php endforeach; ?>

        <form method="post" autocomplete="off" class="space-y-4">
            <?= csrf_field() ?>
            <!-- honeypot: hidden from people, filled in by bots -->
            <input type="text" name="website" value="" tabindex="-1" autocomplete="off"
                   style="position:absolute;left:-9999px" aria-hidden="true">

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <div class="sm:col-span-2">
                    <label class="label" for="company_name">Restaurant name</label>
                    <input type="text" id="company_name" name="company_name" class="input" maxlength="100"
                           value="<?= e($f['company_name']) ?>" required autofocus>
                </div>
                <div>
                    <label class="label" for="slug">Short name</label>
                    <input type="text" id="slug" name="slug" class="input" maxlength="30"
                           value="<?= e($f['slug']) ?>" placeholder="hodan-cafe">
                    <small class="text-muted text-xs">Letters, digits and dashes. Leave blank to use the name.</small>
                </div>
                <div>
                    <label class="label" for="phone">Phone</label>
                    <input type="text" id="phone" name="phone" class="input" maxlength="40" value="<?= e($f['phone']) ?>">
                </div>
            </div>

            <hr class="border-line">
            <p class="text-sm font-semibold text-ink -mb-1">Your admin account</p>

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <div class="sm:col-span-2">
                    <label class="label" for="full_name">Your full name</label>
                    <input type="text" id="full_name" name="full_name" class="input" maxlength="100"
                           value="<?= e($f['full_name']) ?>" required>
                </div>
                <div class="sm:col-span-2">
                    <label class="label" for="username">Username</label>
                    <input type="text" id="username" name="username" class="input" maxlength="50"
                           value="<?= e($f['username']) ?>" placeholder="ahmed.hodan-cafe" required>
                    <small class="text-muted text-xs">Unique across every restaurant on the system. You sign in with this.</small>
                </div>
                <div>
                    <label class="label" for="password">Password</label>
                    <input type="password" id="password" name="password" class="input" minlength="6" required>
                </div>
                <div>
                    <label class="label" for="password2">Repeat password</label>
                    <input type="password" id="password2" name="password2" class="input" minlength="6" required>
                </div>
            </div>

            <label class="flex items-center gap-2 text-sm">
                <input type="checkbox" name="starter" value="1" <?= $f['starter'] === '1' ? 'checked' : '' ?>>
                Add starter menu categories (you can rename or delete them)
            </label>

            <button class="btn btn-brand btn-lg w-full">Create my restaurant</button>
        </form>

        <p class="text-center text-muted mt-5 mb-0 text-sm">
            Already registered? <a href="<?= url('public/login.php') ?>" class="font-semibold text-brand-dark underline">Sign in</a>
        </p>
    </div>
</div>
<script>
// Suggest the short name and a username from the restaurant name as it is typed.
(function () {
    var name = document.getElementById('company_name'),
        slug = document.getElementById('slug'),
        user = document.getElementById('username'),
        touched = slug.value !== '';
    slug.addEventListener('input', function () { touched = true; });
    name.addEventListener('input', function () {
        if (touched) return;
        slug.value = name.value.normalize('NFD').replace(/[̀-ͯ]/g, '')
            .toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/^-+|-+$/g, '').slice(0, 30);
        user.placeholder = 'yourname.' + (slug.value || 'hodan-cafe');
    });
})();
</script>
<?php require __DIR__ . '/../core/footer.php'; ?>
