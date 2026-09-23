<?php
/**
 * Session start, login state, role gates and CSRF protection.
 * Included from core/config.php — do not include directly.
 */

declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {
    session_set_cookie_params(['httponly' => true, 'samesite' => 'Lax']);
    session_name('SMALLREST');
    session_start();
}

// ---------------------------------------------------------------- identity
function is_logged_in(): bool
{
    return isset($_SESSION['user']['id']);
}

/** The signed-in user array (id, full_name, username, role), or null. */
function current_user(): ?array
{
    return $_SESSION['user'] ?? null;
}

function user_id(): int
{
    return (int)($_SESSION['user']['id'] ?? 0);
}

function user_role(): string
{
    return (string)($_SESSION['user']['role'] ?? '');
}

/** True when the signed-in user holds any of the given roles. */
function has_role(string ...$roles): bool
{
    return in_array(user_role(), $roles, true);
}

/**
 * The signed-in user's company. Every query on a business table filters on it,
 * so one restaurant can never see another's menu, orders or money.
 * 0 when nobody is signed in.
 */
function company_id(): int
{
    return (int)($_SESSION['user']['company_id'] ?? 0);
}

/**
 * Place a user record into the session after a successful password check.
 * The row must carry company_id and company_name (login joins companies).
 */
function login_user(array $row): void
{
    session_regenerate_id(true);
    $_SESSION['user'] = [
        'id'           => (int)$row['id'],
        'company_id'   => (int)$row['company_id'],
        'company_name' => (string)($row['company_name'] ?? ''),
        'full_name'    => $row['full_name'],
        'username'     => $row['username'],
        'role'         => $row['role'],
    ];
}

// ---------------------------------------------------------------- subscription
/**
 * Whether a company may use the till today.
 * Takes a companies row (status, trial_ends_at, paid_until) and answers
 * 'ok', 'trial_expired', 'expired' or 'suspended'.
 */
function company_access(array $c): string
{
    $today = date('Y-m-d');
    return match ($c['status']) {
        'suspended' => 'suspended',
        'trial'     => ($c['trial_ends_at'] !== null && $c['trial_ends_at'] < $today) ? 'trial_expired' : 'ok',
        default     => ($c['paid_until'] !== null && $c['paid_until'] < $today) ? 'expired' : 'ok',
    };
}

/** What a blocked company's staff are told. */
function company_block_message(string $access): string
{
    return match ($access) {
        'suspended'     => __('auth.block_suspended'),
        'trial_expired' => __('auth.block_trial'),
        default         => __('auth.block_expired'),
    };
}

/**
 * Sign the restaurant user out. Only their own keys are cleared, so a platform
 * owner signed in to platform/ in the same browser stays signed in.
 */
function logout_user(): void
{
    unset($_SESSION['user'], $_SESSION['redirect_after_login'], $_SESSION['csrf']);
    session_regenerate_id(true);
}

// ---------------------------------------------------------------- gates
/** Stop the page unless someone is signed in. */
function require_login(): void
{
    if (!is_logged_in()) {
        $_SESSION['redirect_after_login'] = $_SERVER['REQUEST_URI'] ?? '';
        redirect('public/login.php');
    }
    require_company_access();
}

/**
 * Re-checked on every request, so a suspension or an expiry takes effect at
 * once, not at the next login. Staff of a blocked company are signed out; its
 * admin may still reach the billing page to see how to renew.
 */
function require_company_access(): void
{
    $c = db_one('SELECT status, trial_ends_at, paid_until FROM companies WHERE id = ?', [company_id()]);
    $access = $c ? company_access($c) : 'suspended';
    if ($access === 'ok') {
        return;
    }
    if (user_role() === 'admin' && $access !== 'suspended') {
        if (basename($_SERVER['SCRIPT_NAME'] ?? '') !== 'billing.php') {
            flash(company_block_message($access), 'warning');
            redirect('admin/billing.php');
        }
        return;
    }
    logout_user();
    flash(company_block_message($access), 'danger');
    redirect('public/login.php');
}

/**
 * Stop the page unless the signed-in user holds one of these roles.
 * Admin passes every gate.
 */
function require_role(string ...$roles): void
{
    require_login();
    if (user_role() === 'admin' || in_array(user_role(), $roles, true)) {
        return;
    }
    http_response_code(403);
    flash(__('msg.no_access'), 'danger');
    redirect(home_for_role(user_role()));
}

/** Where each role lands after login. */
function home_for_role(string $role): string
{
    return match ($role) {
        'admin'   => 'admin/index.php',
        'cashier' => 'public/pos.php',
        'waiter'  => 'public/pos.php',
        'kitchen' => 'public/kitchen.php',
        default   => 'public/login.php',
    };
}

// ---------------------------------------------------------------- platform owner
/**
 * The platform owner (platform_admins row) is a separate identity from any
 * restaurant user, kept under its own session key: signing in to the owner's
 * panel never grants access to a restaurant's till, and vice versa.
 */
function platform_user(): ?array
{
    return $_SESSION['platform'] ?? null;
}

function platform_login(array $row): void
{
    session_regenerate_id(true);
    $_SESSION['platform'] = [
        'id'        => (int)$row['id'],
        'full_name' => $row['full_name'],
        'username'  => $row['username'],
    ];
}

/** Stop the page unless the platform owner is signed in. */
function require_platform(): void
{
    if (!platform_user()) {
        redirect('platform/login.php');
    }
}

// ---------------------------------------------------------------- CSRF
function csrf_token(): string
{
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf'];
}

/** Hidden input for forms. */
function csrf_field(): string
{
    return '<input type="hidden" name="csrf" value="' . e(csrf_token()) . '">';
}

/**
 * Verify the token on any state-changing request.
 * JSON endpoints get a JSON error, normal pages a 419 page.
 */
function csrf_check(bool $json = false): void
{
    $sent = $_POST['csrf'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
    if (!hash_equals($_SESSION['csrf'] ?? '', (string)$sent)) {
        if ($json) {
            json_out(['ok' => false, 'error' => 'Your session expired. Please reload the page.'], 419);
        }
        http_response_code(419);
        exit('Invalid or expired form token. Go back and try again.');
    }
}
