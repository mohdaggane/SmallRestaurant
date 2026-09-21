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
        $cls  = match($f['type']) {
            'danger'  => 'flash-danger',
            'warning' => 'flash-warning',
            'info'    => 'flash-info',
            default   => 'flash-success',
        };
        $html .= '<div class="' . $cls . '" role="alert">' . e($f['message']) . '</div>';
    }
    unset($_SESSION['flash']);
    return $html;
}

// ---------------------------------------------------------------- domain
/**
 * Next receipt number for the signed-in company: 251208-0042
 * (yymmdd of the order date + the company's own zero-padded counter).
 * Each restaurant counts its own bills, so numbers never skip because
 * another company rang something up. Call inside the order's transaction:
 * the counter row stays locked until commit, so two tills can't draw the same number.
 */
function next_order_no(string $createdAt): string
{
    global $conn;
    db_exec('UPDATE companies SET order_seq = LAST_INSERT_ID(order_seq + 1) WHERE id = ?', [company_id()]);
    $seq = (int)$conn->insert_id;
    return date('ymd', strtotime($createdAt)) . '-' . str_pad((string)$seq, 4, '0', STR_PAD_LEFT);
}

// ---------------------------------------------------------------- company / plan
/** The signed-in company joined with its plan, or null. */
function current_company(): ?array
{
    static $cache = null;
    if ($cache === null && company_id() > 0) {
        $cache = db_one(
            'SELECT c.*, p.name AS plan_name, p.price_month, p.max_users, p.max_menu_items
               FROM companies c JOIN plans p ON p.id = c.plan_id
              WHERE c.id = ?',
            [company_id()]
        );
    }
    return $cache;
}

/**
 * The date a company's access runs to (trial end or paid-until), or null for no expiry,
 * and the whole days left from today (negative once it has lapsed).
 *
 * @return array{until: ?string, days: ?int}
 */
function company_term(array $c): array
{
    $until = $c['status'] === 'trial' ? $c['trial_ends_at'] : $c['paid_until'];
    if ($until === null) {
        return ['until' => null, 'days' => null];
    }
    $days = (int)floor((strtotime($until) - strtotime(date('Y-m-d'))) / 86400);
    return ['until' => $until, 'days' => $days];
}

/**
 * Give a new company its starting settings (the same keys database/schema.sql
 * seeds for the demo restaurant), so every Settings field has a row to update.
 */
function seed_company_settings(int $companyId, string $shopName, string $phone = ''): void
{
    $defaults = [
        'shop_name'           => $shopName,
        'shop_tagline'        => 'Tea & Food',
        'currency'            => '$',
        'tax_percent'         => '0',
        'receipt_footer'      => 'Thank you — come again!',
        'shop_phone'          => $phone,
        'shop_address'        => '',
        'merchant_name'       => '',
        'merchant_id'         => '',
        'ussd_prefix'         => '*789*',
        'merchant_on_receipt' => '1',
    ];
    foreach ($defaults as $key => $value) {
        db_exec('INSERT INTO settings (company_id, setting_key, setting_value) VALUES (?,?,?)', [$companyId, $key, $value]);
    }
}

/**
 * True when the company's plan allows no more of something.
 * $what: 'users' (active users) or 'menu_items'. A NULL limit is unlimited.
 */
function company_limit_reached(string $what): bool
{
    $c = current_company();
    $limit = match ($what) {
        'users'      => $c['max_users'] ?? null,
        'menu_items' => $c['max_menu_items'] ?? null,
    };
    if ($limit === null) {
        return false;
    }
    $count = match ($what) {
        'users'      => (int)db_value('SELECT COUNT(*) FROM users WHERE company_id = ? AND is_active = 1', [company_id()]),
        'menu_items' => (int)db_value('SELECT COUNT(*) FROM menu_items WHERE company_id = ?', [company_id()]),
    };
    return $count >= (int)$limit;
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
    return db_one("SELECT * FROM shifts WHERE company_id = ? AND user_id = ? AND status = 'open' ORDER BY id DESC LIMIT 1", [company_id(), $userId]);
}

/**
 * Cash the drawer should hold for a shift:
 * opening float + cash sales - expenses paid out of the drawer.
 */
function shift_expected_cash(int $shiftId): float
{
    $s = db_one('SELECT opening_float FROM shifts WHERE id = ? AND company_id = ?', [$shiftId, company_id()]);
    if (!$s) {
        return 0.0;
    }
    $sales = (float)db_value(
        "SELECT COALESCE(SUM(total),0) FROM orders WHERE company_id = ? AND shift_id = ? AND status = 'paid' AND payment_method = 'cash'",
        [company_id(), $shiftId]
    );
    $out = (float)db_value(
        "SELECT COALESCE(SUM(amount),0) FROM expenses WHERE company_id = ? AND shift_id = ? AND paid_from = 'drawer'",
        [company_id(), $shiftId]
    );
    return round((float)$s['opening_float'] + $sales - $out, 2);
}

/** Tailwind badge modifier class for an order status. */
function status_badge(string $status): string
{
    return match ($status) {
        'paid'  => 'badge-success',
        'void'  => 'badge-secondary',
        'open'  => 'badge-warning',
        default => 'badge-secondary',
    };
}

// ---------------------------------------------------------------- orders
/**
 * A business-rule refusal (paid order, item unavailable, underpayment…).
 * The code is the HTTP status to answer with. Kept apart from database
 * errors so the endpoints can tell "you can't do that" from "we broke".
 */
class OrderException extends Exception
{
}

/**
 * Price POS lines from the database. The browser only says which item and how
 * many; price, cost and kitchen routing always come from menu_items.
 *
 * @throws OrderException when an item is missing, unavailable, or nothing is valid
 */
function price_order_lines(array $lines): array
{
    $priced = [];

    foreach ($lines as $line) {
        if (!is_array($line)) {
            continue;
        }
        $itemId = (int)($line['id'] ?? 0);
        $qty    = (int)($line['qty'] ?? 0);
        if ($itemId <= 0 || $qty <= 0) {
            continue;
        }

        $item = db_one(
            'SELECT id, name, price, cost_price, needs_prep, is_available FROM menu_items WHERE id = ? AND company_id = ?',
            [$itemId, company_id()]
        );
        if (!$item) {
            throw new OrderException('A menu item on this order no longer exists.', 422);
        }
        if (!(int)$item['is_available']) {
            throw new OrderException($item['name'] . ' is marked unavailable.', 422);
        }

        $priced[] = [
            'item_id'    => (int)$item['id'],
            'name'       => $item['name'],
            'unit_price' => (float)$item['price'],
            'unit_cost'  => (float)$item['cost_price'],
            'qty'        => $qty,
            'line_total' => round((float)$item['price'] * $qty, 2),
            'needs_prep' => (int)$item['needs_prep'],
            'note'       => mb_substr(trim((string)($line['note'] ?? '')), 0, 120),
        ];
    }

    if (!$priced) {
        throw new OrderException('The order has no valid items.', 422);
    }
    return $priced;
}

/** Save priced lines onto an order as one round. */
function insert_order_lines(int $orderId, array $priced, int $round, string $now): void
{
    foreach ($priced as $p) {
        db_exec(
            'INSERT INTO order_items
                (company_id, order_id, round, menu_item_id, item_name, unit_price, unit_cost, qty, line_total,
                 note, needs_prep, kitchen_status, created_at)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)',
            [
                company_id(), $orderId, $round, $p['item_id'], $p['name'], $p['unit_price'], $p['unit_cost'],
                $p['qty'], $p['line_total'], $p['note'] !== '' ? $p['note'] : null,
                $p['needs_prep'],
                // Items that need no preparation (bottled drinks) skip the kitchen queue.
                $p['needs_prep'] ? 'pending' : 'served',
                $now,
            ]
        );
    }
}

// ---------------------------------------------------------------- VAT
/** The shop's VAT rate in percent (5 means 5%), from Settings. */
function vat_rate(): float
{
    return max(0.0, (float)setting('tax_percent', '0'));
}

/** "5%", "7.5%" — a rate without trailing zeros. */
function vat_label(float|string $rate): string
{
    return rtrim(rtrim(number_format((float)$rate, 2, '.', ''), '0'), '.') . '%';
}

/**
 * VAT due on an amount, added on top: 2.00 at 5% -> 0.10.
 * Worked in whole cents so a half cent (1.50 at 5% = 7.5c) always rounds up
 * to 8c instead of wobbling with floating point. pos.js uses the same rule.
 */
function vat_amount(float $net, float $rate): float
{
    $cents = (int)round($net * 100);
    return round($cents * $rate / 100) / 100;
}

/**
 * Recompute an order's money from its saved lines. This is the only place order
 * totals are calculated — create, add-a-round, remove-a-line and a VAT rate
 * change all call it, inside their own transaction.
 *
 *   net   = subtotal - discount        (the shop's sale, before VAT)
 *   tax   = VAT on net, added on top   (collected for the government)
 *   total = net + tax                  (what the customer pays)
 *
 * @return array{subtotal: float, discount: float, vat_rate: float, tax: float, total: float}
 */
function recalc_order_totals(int $orderId, float $discount, bool $touch = true): array
{
    $subtotal = round((float)db_value(
        'SELECT COALESCE(SUM(line_total), 0) FROM order_items WHERE order_id = ? AND company_id = ?',
        [$orderId, company_id()]
    ), 2);
    $discount = min(max(round($discount, 2), 0), $subtotal);
    $rate     = vat_rate();
    $tax      = vat_amount($subtotal - $discount, $rate);
    $total    = round($subtotal - $discount + $tax, 2);

    db_exec(
        'UPDATE orders SET subtotal = ?, discount = ?, vat_rate = ?, tax = ?, total = ?,
                updated_at = IF(?, ?, updated_at)
          WHERE id = ? AND company_id = ?',
        [$subtotal, $discount, $rate, $tax, $total, $touch ? 1 : 0, date('Y-m-d H:i:s'), $orderId, company_id()]
    );

    return ['subtotal' => $subtotal, 'discount' => $discount, 'vat_rate' => $rate, 'tax' => $tax, 'total' => $total];
}

/**
 * Re-price every unpaid order at the current VAT rate. Called when the rate
 * changes: an open bill hasn't been paid yet, so it should be charged at the
 * rate in force when it is. Paid and voided orders are never touched.
 * Returns how many open orders were updated.
 */
function reprice_open_orders(): int
{
    $open = db_all("SELECT id, discount FROM orders WHERE company_id = ? AND status = 'open'", [company_id()]);
    foreach ($open as $o) {
        recalc_order_totals((int)$o['id'], (float)$o['discount'], false);
    }
    return count($open);
}

/**
 * Settle an open order. Only succeeds if the order is still open at this
 * instant, so two cashiers paying the same bill cannot both record it.
 *
 * @throws OrderException on underpayment or when the order is no longer open
 */
function mark_order_paid(int $orderId, float $total, float $paid, string $method, int $shiftId): float
{
    if ($paid + 0.001 < $total) {
        throw new OrderException('Amount paid is less than the total (' . money($total) . ').', 422);
    }
    $change = round($paid - $total, 2);

    $stmt = db_run(
        "UPDATE orders
            SET status = 'paid', paid_amount = ?, change_amount = ?, payment_method = ?,
                paid_by = ?, shift_id = ?, paid_at = ?
          WHERE id = ? AND company_id = ? AND status = 'open'",
        [$paid, $change, $method, user_id(), $shiftId, date('Y-m-d H:i:s'), $orderId, company_id()]
    );
    if ($stmt->affected_rows !== 1) {
        throw new OrderException('This order was already paid or voided by someone else.', 409);
    }
    return $change;
}

/**
 * Staff may shrink or drop a line only while the kitchen has not started it.
 * No-prep items (bottled drinks) are auto-marked served at the till, so they
 * stay removable until the order is paid.
 */
function line_is_removable(array $line): bool
{
    return $line['kitchen_status'] === 'pending' || (int)$line['needs_prep'] === 0;
}

/** An order's lines shaped for the POS screen's "already on this order" list. */
function order_lines_for_pos(int $orderId): array
{
    $out = [];
    foreach (db_all('SELECT * FROM order_items WHERE order_id = ? AND company_id = ? ORDER BY round, id', [$orderId, company_id()]) as $li) {
        $out[] = [
            'id'             => (int)$li['id'],
            'name'           => $li['item_name'],
            'qty'            => (int)$li['qty'],
            'unit_price'     => (float)$li['unit_price'],
            'line_total'     => (float)$li['line_total'],
            'kitchen_status' => $li['kitchen_status'],
            'round'          => (int)$li['round'],
            'removable'      => line_is_removable($li),
        ];
    }
    return $out;
}

// ---------------------------------------------------------------- reports
function valid_date(string $value): bool
{
    $d = DateTime::createFromFormat('Y-m-d', $value);
    return $d !== false && $d->format('Y-m-d') === $value;
}

/**
 * Date range from ?from=&to= or a ?range= preset.
 * Returns [from, to, startDt, endDtExclusive]. Filter with
 * `col >= startDt AND col < endDtExclusive` — unlike DATE(col) BETWEEN,
 * that form can use the column's index.
 */
function report_range(string $defaultFrom, string $defaultTo): array
{
    $from = valid_date(get('from')) ? get('from') : $defaultFrom;
    $to   = valid_date(get('to'))   ? get('to')   : $defaultTo;

    switch (get('range')) {
        case 'today':     $from = $to = date('Y-m-d'); break;
        case 'yesterday': $from = $to = date('Y-m-d', strtotime('-1 day')); break;
        case 'week':      $from = date('Y-m-d', strtotime('-6 days')); $to = date('Y-m-d'); break;
        case 'month':     $from = date('Y-m-01'); $to = date('Y-m-d'); break;
        case 'lastmonth':
            $from = date('Y-m-01', strtotime('first day of last month'));
            $to   = date('Y-m-t', strtotime('last day of last month'));
            break;
    }

    if ($from > $to) {
        [$from, $to] = [$to, $from];
    }

    return [$from, $to, $from . ' 00:00:00', date('Y-m-d', strtotime($to . ' +1 day')) . ' 00:00:00'];
}

/**
 * Stream a CSV download and stop. Starts with a UTF-8 byte-order mark so Excel
 * shows Somali names and the em dash correctly. Text cells that begin with a
 * formula character are prefixed with an apostrophe so a table label typed as
 * "=cmd…" is shown as text, never run as an Excel formula.
 */
function csv_out(string $filename, array $header, array $rows): never
{
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');

    $safe = static function ($cell) {
        if (is_string($cell) && $cell !== '' && !is_numeric($cell)
            && in_array($cell[0], ['=', '+', '-', '@'], true)) {
            return "'" . $cell;
        }
        return $cell;
    };

    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, $header);
    foreach ($rows as $row) {
        fputcsv($out, array_map($safe, $row));
    }
    fclose($out);
    exit;
}

/**
 * One day's till figures. Shared by the Daily Transactions page and the
 * thermal end-of-day slip so the two can never disagree.
 */
function daily_totals(string $date): array
{
    $start = $date . ' 00:00:00';
    $end   = date('Y-m-d', strtotime($date . ' +1 day')) . ' 00:00:00';

    $paid = db_one(
        "SELECT COUNT(*) AS orders,
                COALESCE(SUM(total), 0)    AS total,
                COALESCE(SUM(subtotal - discount), 0) AS net,
                COALESCE(SUM(discount), 0) AS discount,
                COALESCE(SUM(tax), 0)      AS tax,
                COALESCE(SUM(CASE WHEN payment_method = 'cash'   THEN total END), 0) AS cash,
                COALESCE(SUM(CASE WHEN payment_method = 'mobile' THEN total END), 0) AS mobile,
                COALESCE(SUM(CASE WHEN payment_method = 'card'   THEN total END), 0) AS card
           FROM orders
          WHERE company_id = ? AND status = 'paid' AND paid_at >= ? AND paid_at < ?",
        [company_id(), $start, $end]
    );

    $exp = db_one(
        "SELECT COALESCE(SUM(amount), 0) AS total,
                COALESCE(SUM(CASE WHEN paid_from = 'drawer' THEN amount END), 0) AS drawer
           FROM expenses WHERE company_id = ? AND spent_on = ?",
        [company_id(), $date]
    );

    $voids = db_one(
        "SELECT COUNT(*) AS n, COALESCE(SUM(total), 0) AS total
           FROM orders WHERE company_id = ? AND status = 'void' AND voided_at >= ? AND voided_at < ?",
        [company_id(), $start, $end]
    );

    // Opened this day and still not paid: money the day has not collected.
    $unpaid = db_one(
        "SELECT COUNT(*) AS n, COALESCE(SUM(total), 0) AS total
           FROM orders WHERE company_id = ? AND status = 'open' AND created_at >= ? AND created_at < ?",
        [company_id(), $start, $end]
    );

    $shifts = db_all(
        "SELECT s.*, u.full_name
           FROM shifts s JOIN users u ON u.id = s.user_id
          WHERE s.company_id = ?
            AND ((s.opened_at >= ? AND s.opened_at < ?)
             OR (s.closed_at >= ? AND s.closed_at < ?)
             OR (s.status = 'open' AND s.opened_at < ?))
          ORDER BY s.opened_at",
        [company_id(), $start, $end, $start, $end, $end]
    );
    foreach ($shifts as &$s) {
        // A shift still open has no stored expectation yet — compute it live.
        if ($s['status'] === 'open') {
            $s['expected_cash'] = shift_expected_cash((int)$s['id']);
        }
    }
    unset($s);

    return [
        'date'       => $date,
        'start'      => $start,
        'end'        => $end,
        'orders'     => (int)$paid['orders'],
        'sales'      => (float)$paid['total'],   // collected, incl. VAT
        'net'        => (float)$paid['net'],     // sales before VAT (the shop's income)
        'discount'   => (float)$paid['discount'],
        'tax'        => (float)$paid['tax'],     // VAT collected (the government's)
        'cash'       => (float)$paid['cash'],
        'mobile'     => (float)$paid['mobile'],
        'card'       => (float)$paid['card'],
        'expenses'   => (float)$exp['total'],
        'exp_drawer' => (float)$exp['drawer'],
        'net_cash'   => round((float)$paid['cash'] - (float)$exp['drawer'], 2),
        'void_n'     => (int)$voids['n'],
        'void_total' => (float)$voids['total'],
        'unpaid_n'   => (int)$unpaid['n'],
        'unpaid'     => (float)$unpaid['total'],
        'shifts'     => $shifts,
    ];
}

/** "12 min", "2 h 15 m", "3 days" — how long a bill has been waiting. */
function duration_label(int $minutes): string
{
    $minutes = max(0, $minutes);
    if ($minutes < 60) {
        return $minutes . ' min';
    }
    if ($minutes < 1440) {
        return intdiv($minutes, 60) . ' h ' . ($minutes % 60) . ' m';
    }
    $days = intdiv($minutes, 1440);
    return $days . ' day' . ($days === 1 ? '' : 's');
}
