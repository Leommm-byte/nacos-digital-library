<?php

/**
 * Database Connection & Environment Configuration
 * Phase 12 Requirement
 */

// 1. SIMPLE .ENV PARSER
function load_env($path) {
    if (!file_exists($path)) return;
    $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        if (strpos(trim($line), '#') === 0) continue;
        list($name, $value) = explode('=', $line, 2);
        $name = trim($name);
        $value = trim($value);
        if (!array_key_exists($name, $_SERVER) && !array_key_exists($name, $_ENV)) {
            putenv(sprintf('%s=%s', $name, $value));
            $_ENV[$name] = $value;
            $_SERVER[$name] = $value;
        }
    }
}

load_env(__DIR__ . '/../.env');

// 2. CONFIGURATION
$servername = getenv('DB_SERVER') ?: 'localhost';
$username   = getenv('DB_USERNAME') ?: 'root';
$password   = getenv('DB_PASSWORD') ?: '';
$dbname     = getenv('DB_NAME') ?: 'nacos_library';
$BASE_URL   = getenv('BASE_URL') ?: '/nacos-app/';

// Global variables for convenience (Phase 15 - Code Quality)
$GLOBALS['BASE_URL'] = $BASE_URL;

// 3. DATABASE CONNECTION
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

try {
    $conn = new mysqli($servername, $username, $password, $dbname);
    $conn->set_charset("utf8mb4");
} catch (mysqli_sql_exception $e) {
    // In production, log this and show a generic error
    error_log("Database Connection Failed: " . $e->getMessage());
    die("A technical error occurred. Please try again later.");
}

// 4. INCLUDE HELPERS & SESSION AUTOMATICALLY (Foundation)
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/session.php';

secure_session_start();

