<?php
/**
 * Centralized database connection.
 * Uses mysqli with error reporting and utf8mb4 for safer defaults.
 */
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

function load_env_file(string $envPath, bool $overwrite = false): bool
{
    if (!is_file($envPath) || !is_readable($envPath)) {
        return false;
    }

    $lines = file($envPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        $trimmed = trim($line);
        if ($trimmed === '' || strpos($trimmed, '#') === 0 || strpos($trimmed, '=') === false) {
            continue;
        }

        [$key, $value] = explode('=', $trimmed, 2);
        $key = trim($key);
        $value = trim($value, " \t\n\r\0\x0B\"'");
        if ($key === '') {
            continue;
        }

        if (!$overwrite && getenv($key) !== false) {
            continue;
        }

        $_ENV[$key] = $value;
        $_SERVER[$key] = $value;
        putenv($key . '=' . $value);
    }

    return true;
}

function load_php_config_file(string $configPath, bool $overwrite = false): bool
{
    if (!is_file($configPath) || !is_readable($configPath)) {
        return false;
    }

    $data = require $configPath;
    if (!is_array($data)) {
        return false;
    }

    foreach ($data as $key => $value) {
        $key = trim((string) $key);
        if ($key === '') {
            continue;
        }
        if (!$overwrite && getenv($key) !== false) {
            continue;
        }
        $stringValue = is_scalar($value) ? (string) $value : '';
        $_ENV[$key] = $stringValue;
        $_SERVER[$key] = $stringValue;
        putenv($key . '=' . $stringValue);
    }

    return true;
}

$projectRoot = dirname(__DIR__);
$accountRoot = dirname($projectRoot);

foreach ([$projectRoot . DIRECTORY_SEPARATOR . '.env', $accountRoot . DIRECTORY_SEPARATOR . '.env'] as $envPath) {
    if (load_env_file($envPath)) {
        break;
    }
}

// Local machine override (never committed).
load_env_file($projectRoot . DIRECTORY_SEPARATOR . '.env.local', true);

// Hosting-safe fallback (InfinityFree/shared hosting):
// Load private PHP config from account root (outside htdocs) when env vars are missing.
if (
    getenv('APP_ENV') === false ||
    getenv('DB_HOST') === false ||
    getenv('DB_USER') === false ||
    getenv('DB_NAME') === false
) {
    load_php_config_file($accountRoot . DIRECTORY_SEPARATOR . 'secure-config.php');
}

$appEnv = strtolower((string) (getenv('APP_ENV') ?: 'local'));
$isProduction = in_array($appEnv, ['production', 'prod'], true);

$httpHost = strtolower((string) ($_SERVER['HTTP_HOST'] ?? ''));
$isLocalHost =
    $httpHost === 'localhost' ||
    $httpHost === '127.0.0.1' ||
    strpos($httpHost, 'localhost:') === 0 ||
    strpos($httpHost, '127.0.0.1:') === 0;
$isCliServer = PHP_SAPI === 'cli-server';

$dbHost = trim((string) (getenv('DB_HOST') ?: ''));
$dbPort = trim((string) (getenv('DB_PORT') ?: ''));
$dbUser = trim((string) (getenv('DB_USER') ?: ''));
$dbPass = (string) (getenv('DB_PASSWORD') ?: '');
$dbName = trim((string) (getenv('DB_NAME') ?: ''));

// Permanent local safety: when running localhost and no local override exists,
// do not use production remote DB settings by accident.
if (($isLocalHost || $isCliServer) && !is_file($projectRoot . DIRECTORY_SEPARATOR . '.env.local')) {
    if (strpos($dbHost, 'infinityfree') !== false || $isProduction) {
        $appEnv = 'local';
        $isProduction = false;
        $dbHost = '127.0.0.1';
        $dbPort = '3306';
        $dbUser = 'root';
        $dbPass = '';
        $dbName = 'fabric_export';
        error_log('[fabric-export] Local safety fallback active. Create .env.local to override defaults.');
    }
}

if ($isProduction) {
    $missingVars = [];
    foreach (['DB_HOST' => $dbHost, 'DB_PORT' => $dbPort, 'DB_USER' => $dbUser, 'DB_NAME' => $dbName] as $var => $value) {
        if ($value === '') {
            $missingVars[] = $var;
        }
    }

    if (!empty($missingVars)) {
        error_log('[fabric-export] FATAL: missing production DB env vars: ' . implode(', ', $missingVars));
        http_response_code(500);
        exit('Server configuration error. Missing database settings.');
    }

    if (in_array($dbHost, ['localhost', '127.0.0.1'], true) || in_array($dbUser, ['root', ''], true)) {
        error_log('[fabric-export] FATAL: refusing local DB defaults in production.');
        http_response_code(500);
        exit('Server configuration error. Invalid production database settings.');
    }
} else {
    $dbHost = $dbHost !== '' ? $dbHost : '127.0.0.1';
    $dbPort = $dbPort !== '' ? $dbPort : '3306';
    $dbUser = $dbUser !== '' ? $dbUser : 'root';
    $dbName = $dbName !== '' ? $dbName : 'fabric_export';
}

try {
    $conn = new mysqli($dbHost, $dbUser, $dbPass, $dbName, (int) $dbPort);
    $conn->set_charset('utf8mb4');
} catch (Throwable $e) {
    error_log('[fabric-export] DB connection failed: ' . $e->getMessage());
    http_response_code(500);
    exit('Server configuration error. Database connection failed.');
}

// Helper to quickly check health in admin pages if needed.
function db_connected(): bool
{
    return isset($GLOBALS['conn']) && $GLOBALS['conn'] instanceof mysqli;
}
