<?php
/**
 * Shared helpers: escaping, money formatting, DB shortcuts, flash messages.
 * Included from core/config.php — do not include directly.
 */

declare(strict_types=1);

// ---------------------------------------------------------------- output
/** Escape a value for safe HTML output. */
function e(mixed $value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/** Read a shop setting with a fallback. */
function setting(string $key, string $default = ''): string
{
    global $SETTINGS;
    return $SETTINGS[$key] ?? $default;
}

/** Format an amount using the configured currency symbol. */
function money(float|string|null $amount): string
{
    return setting('currency', '$') . number_format((float)$amount, 2);
}

/** Human date/time for screens and receipts. */
function dt(?string $sqlDateTime, string $format = 'd M Y, g:i A'): string
{
    if ($sqlDateTime === null || $sqlDateTime === '' || str_starts_with($sqlDateTime, '0000')) {
        return '—';
    }
    return date($format, strtotime($sqlDateTime));
}

/** Build an absolute app URL: url('admin/reports.php'). */
function url(string $path = ''): string
{
    return BASE_URL . '/' . ltrim($path, '/');
}

// ---------------------------------------------------------------- database
/**
 * Run a prepared statement and return the mysqli_stmt.
 * Types are inferred: int -> i, float -> d, everything else -> s.
 */
function db_run(string $sql, array $params = []): mysqli_stmt
{
    global $conn;
    $stmt = $conn->prepare($sql);
    if ($params) {
        $types = '';
        foreach ($params as $p) {
            $types .= is_int($p) ? 'i' : (is_float($p) ? 'd' : 's');
        }
        $stmt->bind_param($types, ...$params);
    }
    $stmt->execute();
    return $stmt;
}

/** All matching rows as an array of associative arrays. */
function db_all(string $sql, array $params = []): array
{
    return db_run($sql, $params)->get_result()->fetch_all(MYSQLI_ASSOC);
}

/** First matching row, or null. */
function db_one(string $sql, array $params = []): ?array
{
    $row = db_run($sql, $params)->get_result()->fetch_assoc();
    return $row ?: null;
}

/** First column of the first row — for COUNT/SUM style queries. */
function db_value(string $sql, array $params = []): mixed
{
    $row = db_run($sql, $params)->get_result()->fetch_row();
    return $row[0] ?? null;
}

/** INSERT/UPDATE/DELETE; returns the new insert id (0 when not an insert). */
function db_exec(string $sql, array $params = []): int
{
    global $conn;
    db_run($sql, $params);
    return (int)$conn->insert_id;
}

// ---------------------------------------------------------------- requests
/**
 * Force a string to valid UTF-8.
 * Text pasted from Word or another Windows program can reach us as Windows-1252
 * bytes; the database columns are utf8mb4 and reject those outright, which would
 * otherwise abort the request mid-save. Converting is friendlier than failing.
 */
function clean_utf8(string $value): string
{
    if ($value === '' || preg_match('//u', $value) === 1) {
        return $value;
    }
    return mb_convert_encoding($value, 'UTF-8', 'Windows-1252');
}

/** Trimmed POST string, guaranteed to be storable UTF-8. */
function post(string $key, string $default = ''): string
{
    return clean_utf8(trim((string)($_POST[$key] ?? $default)));
}

/** Trimmed GET string, guaranteed to be storable UTF-8. */
function get(string $key, string $default = ''): string
{
    return clean_utf8(trim((string)($_GET[$key] ?? $default)));
}

/** POST value as a positive-or-zero amount. */
function post_amount(string $key): float
{
    return round(max(0, (float)($_POST[$key] ?? 0)), 2);
}

function is_post(): bool
{
    return ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';
}

/** Send a redirect and stop. */
function redirect(string $path): never
{
    header('Location: ' . (str_starts_with($path, 'http') ? $path : url($path)));
    exit;
}

/** Emit JSON and stop — used by the POS/kitchen endpoints. */
function json_out(array $payload, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload);
    exit;
}

// ---------------------------------------------------------------- flash
/** Queue a one-shot message shown on the next page load. */
function flash(string $message, string $type = 'success'): void
{
    $_SESSION['flash'][] = ['message' => $message, 'type' => $type];
}

/** Render and clear queued flash messages. */
function render_flashes(): string
{
    $html = '';
    foreach ($_SESSION['flash'] ?? [] as $f) {
        $html .= '<div class="alert alert-' . e($f['type']) . ' alert-dismissible fade show" role="alert">'
              . e($f['message'])
              . '<button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button></div>';
    }
    unset($_SESSION['flash']);
    return $html;
}

// ---------------------------------------------------------------- domain
/**
 * Sequential receipt number for an order: 251208-0042
 * (yymmdd of the order date + zero-padded order id).
 */
function build_order_no(int $orderId, string $createdAt): string
{
    return date('ymd', strtotime($createdAt)) . '-' . str_pad((string)$orderId, 4, '0', STR_PAD_LEFT);
}

/**
 * Mobile-money dial string for an amount, e.g. *789*123456*6.00#
 * The prefix and merchant number come from Settings; returns null when no
 * merchant account has been configured, so callers can simply skip the block.
 * The amount is never grouped with commas — a USSD string must be dialable.
 */
function merchant_ussd(float $amount): ?string
{
    $merchant = trim(setting('merchant_id'));
    if ($merchant === '') {
        return null;
    }
    $prefix = trim(setting('ussd_prefix', '*789*'));
    if ($prefix === '') {
        $prefix = '*789*';
    }
    return rtrim($prefix, '*') . '*' . $merchant . '*'
         . number_format($amount, 2, '.', '') . '#';
}

/** The same string as a tel: URI — '#' has to be percent-encoded to dial. */
function merchant_ussd_link(string $ussd): string
{
    return 'tel:' . str_replace('#', '%23', $ussd);
}

/** The caller's currently open shift, or null. */
function open_shift(int $userId): ?array
{
    return db_one("SELECT * FROM shifts WHERE user_id = ? AND status = 'open' ORDER BY id DESC LIMIT 1", [$userId]);
}

/**
 * Cash the drawer should hold for a shift:
 * opening float + cash sales - expenses paid out of the drawer.
 */
function shift_expected_cash(int $shiftId): float
{
    $s = db_one('SELECT opening_float FROM shifts WHERE id = ?', [$shiftId]);
    if (!$s) {
        return 0.0;
    }
    $sales = (float)db_value(
        "SELECT COALESCE(SUM(total),0) FROM orders WHERE shift_id = ? AND status = 'paid' AND payment_method = 'cash'",
        [$shiftId]
    );
    $out = (float)db_value(
        "SELECT COALESCE(SUM(amount),0) FROM expenses WHERE shift_id = ? AND paid_from = 'drawer'",
        [$shiftId]
    );
    return round((float)$s['opening_float'] + $sales - $out, 2);
}

/** Bootstrap badge class for an order status. */
function status_badge(string $status): string
{
    return match ($status) {
        'paid'  => 'success',
        'void'  => 'secondary',
        'open'  => 'warning',
        default => 'light',
    };
}
