<?php
/**
 * Static guard: every SQL string that touches a restaurant's own table must
 * mention company_id, or one company could read or change another's data.
 *
 *   php tests/check_tenant_sql.php        exit 0 = clean, 1 = unscoped SQL found
 *
 * A query that is deliberately platform-wide (login looking a username up
 * across every company, the username-taken check) is allowed when the line
 * holding the SQL, or one of the 3 lines above it, carries the comment
 *   // tenant-global: <why>
 * platform/ (the owner's panel) is skipped: it works across companies by design.
 */

declare(strict_types=1);

const TENANT_TABLES = ['users', 'settings', 'categories', 'menu_items', 'shifts', 'orders', 'order_items', 'expenses'];

$root  = dirname(__DIR__);
$dirs  = ['admin', 'public', 'public/api', 'core'];
$pat   = '/\b(?:FROM|JOIN|INTO|UPDATE)\s+(' . implode('|', TENANT_TABLES) . ')\b/i';
$fails = [];

foreach ($dirs as $dir) {
    foreach (glob("$root/$dir/*.php") as $file) {
        $lines  = file($file);
        $tokens = token_get_all(file_get_contents($file));

        // Stitch "..."-with-$vars strings back together so they are checked whole.
        $strings = [];
        $buf = null;
        foreach ($tokens as $t) {
            if ($t === '"') {
                if ($buf === null) {
                    $buf = ['text' => '', 'line' => 0];
                } else {
                    $strings[] = $buf;
                    $buf = null;
                }
                continue;
            }
            if (!is_array($t)) {
                continue;
            }
            [$id, $text, $line] = $t;
            if ($buf !== null) {
                $buf['text'] .= $text;
                $buf['line'] = $buf['line'] ?: $line;
            } elseif ($id === T_CONSTANT_ENCAPSED_STRING || $id === T_ENCAPSED_AND_WHITESPACE) {
                $strings[] = ['text' => $text, 'line' => $line];
            }
        }

        foreach ($strings as $s) {
            if (!preg_match($pat, $s['text']) || stripos($s['text'], 'company_id') !== false) {
                continue;
            }
            $allowed = false;
            for ($l = max(1, $s['line'] - 3); $l <= $s['line']; $l++) {
                if (str_contains($lines[$l - 1] ?? '', 'tenant-global:')) {
                    $allowed = true;
                    break;
                }
            }
            if (!$allowed) {
                $sql = preg_replace('/\s+/', ' ', trim($s['text'], "'\""));
                $fails[] = substr($file, strlen($root) + 1) . ':' . $s['line'] . '  ' . substr($sql, 0, 110);
            }
        }
    }
}

if ($fails) {
    echo "Unscoped SQL on a tenant table (add a company_id filter, or // tenant-global: <why>):\n  "
        . implode("\n  ", $fails) . "\n";
    exit(1);
}
echo "tenant SQL guard: all queries are scoped by company_id\n";
