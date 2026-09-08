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

/** Place a user record into the session after a successful password check. */
function login_user(array $row): void
{
    session_regenerate_id(true);
    $_SESSION['user'] = [
        'id'        => (int)$row['id'],
        'full_name' => $row['full_name'],
        'username'  => $row['username'],
        'role'      => $row['role'],
    ];
}

function logout_user(): void
{
    $_SESSION = [];
    session_destroy();
}

// ---------------------------------------------------------------- gates
/** Stop the page unless someone is signed in. */
function require_login(): void
{
    if (!is_logged_in()) {
        $_SESSION['redirect_after_login'] = $_SERVER['REQUEST_URI'] ?? '';
        redirect('public/login.php');
    }
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
    flash('You do not have access to that page.', 'danger');
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
