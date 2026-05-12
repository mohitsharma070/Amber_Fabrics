<?php
/**
 * Centralized database + app credential bootstrap.
 * Loads config/app-config.php and uses active values directly.
 */
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

$configFile = __DIR__ . '/app-config.php';
$allConfig = is_file($configFile) ? require $configFile : [];
if (!is_array($allConfig)) {
    http_response_code(500);
    exit('Server configuration error. Invalid app config.');
}

$httpHost = strtolower((string) ($_SERVER['HTTP_HOST'] ?? ''));
$isLocalHost =
    $httpHost === 'localhost' ||
    $httpHost === '127.0.0.1' ||
    strpos($httpHost, 'localhost:') === 0 ||
    strpos($httpHost, '127.0.0.1:') === 0;
$isCliServer = PHP_SAPI === 'cli-server';
$isCli = PHP_SAPI === 'cli';

// CLI should default to local mode so setup/migration scripts don't target production by accident.
$mode = ($isLocalHost || $isCliServer || $isCli) ? 'local' : 'production';
$activeConfig = $allConfig[$mode] ?? [];
if (!is_array($activeConfig)) {
    http_response_code(500);
    exit('Server configuration error. Missing mode config.');
}

// Keep active config globally accessible for downstream helpers.
$GLOBALS['_app_config'] = $activeConfig;

$dbHost = trim((string) ($activeConfig['DB_HOST'] ?? ''));
$dbPort = trim((string) ($activeConfig['DB_PORT'] ?? '3306'));
$dbUser = trim((string) ($activeConfig['DB_USER'] ?? ''));
$dbPass = (string) ($activeConfig['DB_PASSWORD'] ?? '');
$dbName = trim((string) ($activeConfig['DB_NAME'] ?? ''));

if ($dbHost === '' || $dbUser === '' || $dbName === '') {
    error_log('[fabric-export] FATAL: database configuration is incomplete in config/app-config.php');
    http_response_code(500);
    exit('Server configuration error. Missing database settings.');
}

try {
    $conn = new mysqli($dbHost, $dbUser, $dbPass, $dbName, (int) $dbPort);
    $conn->set_charset('utf8mb4');
} catch (Throwable $e) {
    error_log('[fabric-export] DB connection failed: ' . $e->getMessage());
    http_response_code(500);
    exit('Server configuration error. Database connection failed.');
}

function db_connected(): bool
{
    return isset($GLOBALS['conn']) && $GLOBALS['conn'] instanceof mysqli;
}

