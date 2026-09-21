<?php
/**
 * Small Restaurant POS — application bootstrap.
 * Every page includes this file first; it opens the DB connection,
 * loads shop settings and defines the URL base used by all links.
 */

declare(strict_types=1);




// ---------------------------------------------------------------- database
const DB_HOST = 'localhost';
const DB_USER = 'root';
const DB_PASS = '';
// The test runner (tests/run_all.sh) serves the app from PHP's built-in server
// against a throwaway copy. The override is honoured ONLY under that server,
// so Apache — the real till — can never be pointed at another database.
define('DB_NAME', PHP_SAPI === 'cli-server' && getenv('SMALLREST_DB')
    ? (string)getenv('SMALLREST_DB')
    : 'smallrest');

// ---------------------------------------------------------------- sign-up
// A newly registered restaurant starts on this plan (plans.id) for this many days.
const TRIAL_PLAN_ID = 1;
const TRIAL_DAYS    = 14;
// Shown on every restaurant's Billing page: how to pay the platform owner.
const PLATFORM_PAY_INFO = 'Pay by mobile money to the platform owner, then send the transaction reference. Your plan is extended as soon as the payment is recorded.';

// ---------------------------------------------------------------- runtime
date_default_timezone_set('Africa/Mogadishu');

// Show errors while developing; switch to 0 on a live machine.
const APP_DEBUG = true;
ini_set('display_errors', APP_DEBUG ? '1' : '0');
error_reporting(APP_DEBUG ? E_ALL : 0);

// Let mysqli raise exceptions instead of silently returning false.
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

try {
    $conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
    $conn->set_charset('utf8mb4');
} catch (mysqli_sql_exception $e) {
    http_response_code(500);
    exit('Database connection failed. Is MySQL running in XAMPP? (' . $e->getMessage() . ')');
}

/**
 * URL prefix of the app, e.g. "/smallrest". Derived from the folder
 * position under the web root so the app works if the folder is renamed.
 */
$appRoot = str_replace(DIRECTORY_SEPARATOR, '/', dirname(__DIR__));
$docRoot = str_replace(DIRECTORY_SEPARATOR, '/', rtrim((string)($_SERVER['DOCUMENT_ROOT'] ?? ''), '/'));
$prefix  = ($docRoot !== '' && str_starts_with($appRoot, $docRoot))
    ? substr($appRoot, strlen($docRoot))
    : '';
define('BASE_URL', rtrim($prefix, '/'));

require_once __DIR__ . '/helper_functions.php';
require_once __DIR__ . '/sessions.php';

// ---------------------------------------------------------------- settings
/**
 * @var array<string,string> $SETTINGS the signed-in company's shop config.
 * Empty before login (login/register pages), so setting() falls back to its defaults.
 */
$SETTINGS = [];
if (company_id() > 0) {
    foreach (db_all('SELECT setting_key, setting_value FROM settings WHERE company_id = ?', [company_id()]) as $row) {
        $SETTINGS[$row['setting_key']] = (string)$row['setting_value'];
    }
}
