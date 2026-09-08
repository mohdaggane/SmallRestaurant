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
const DB_NAME = 'smallrest';

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

// ---------------------------------------------------------------- settings
/** @var array<string,string> $SETTINGS shop-level config from the settings table */
$SETTINGS = [];
foreach ($conn->query('SELECT setting_key, setting_value FROM settings') as $row) {
    $SETTINGS[$row['setting_key']] = (string)$row['setting_value'];
}

require_once __DIR__ . '/helper_functions.php';
require_once __DIR__ . '/sessions.php';
