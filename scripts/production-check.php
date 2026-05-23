<?php
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Forbidden\n");
}

$passes = [];
$failures = [];
$bootstrapOk = false;

$markPass = static function (string $message) use (&$passes): void {
    $passes[] = $message;
    fwrite(STDOUT, '[PASS] ' . $message . PHP_EOL);
};
$markFail = static function (string $message) use (&$failures): void {
    $failures[] = $message;
    fwrite(STDERR, '[FAIL] ' . $message . PHP_EOL);
};

try {
    require __DIR__ . '/../config/db.php';
    $markPass('Bootstrap loaded.');
    $bootstrapOk = true;
} catch (Throwable $e) {
    $markFail('Bootstrap/config/database initialization failed: ' . $e->getMessage());
}

if ($bootstrapOk) {
    require __DIR__ . '/../includes/functions.php';
    $markPass('Functions loaded.');
}

$requiredExtensions = ['curl', 'fileinfo', 'json', 'mbstring', 'mysqli', 'openssl'];
foreach ($requiredExtensions as $extension) {
    if (!extension_loaded($extension)) {
        $markFail('Missing PHP extension: ' . $extension);
    } else {
        $markPass('Extension loaded: ' . $extension);
    }
}

if (PHP_VERSION_ID < 80200) {
    $markFail('PHP 8.2+ is required.');
} else {
    $markPass('PHP version is 8.2+.');
}

$appMode = strtolower((string) ($GLOBALS['_app_mode'] ?? ''));
if ($bootstrapOk) {
    if ($appMode !== 'production') {
        $markFail('APP_MODE must be production for this check. Current mode: ' . ($appMode === '' ? '(unknown)' : $appMode));
    } else {
        $markPass('APP_MODE is production.');
    }

    if (!isset($conn) || !($conn instanceof mysqli)) {
        $markFail('Database connection object is not available.');
    } else {
        try {
            $conn->query('SELECT 1');
            $markPass('Database connectivity check passed (SELECT 1).');
        } catch (Throwable $e) {
            $markFail('Database check failed: ' . $e->getMessage());
        }
    }
} else {
    $markFail('Skipping APP_MODE and database checks because bootstrap failed.');
}

if ($bootstrapOk && isset($conn) && ($conn instanceof mysqli)) {
    try {
        $stmt = $conn->prepare("SELECT setting_value FROM site_settings WHERE setting_key = 'cron_last_run_at' LIMIT 1");
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $lastRun = trim((string) ($row['setting_value'] ?? ''));
        if ($lastRun === '') {
            $markFail('Cron heartbeat missing: cron_last_run_at is empty.');
        } elseif (strtotime($lastRun) < strtotime('-15 minutes')) {
            $markFail('Cron heartbeat stale: ' . $lastRun);
        } else {
            $markPass('Cron heartbeat is fresh: ' . $lastRun);
        }
    } catch (Throwable $e) {
        $markFail('Cron heartbeat check failed: ' . $e->getMessage());
    }
} elseif (!$bootstrapOk) {
    $markFail('Skipping cron heartbeat check because bootstrap failed.');
}

fwrite(STDOUT, PHP_EOL . 'Production Check Summary' . PHP_EOL);
fwrite(STDOUT, 'Passed: ' . count($passes) . PHP_EOL);
fwrite(STDOUT, 'Failed: ' . count($failures) . PHP_EOL);

if (!empty($failures)) {
    fwrite(STDERR, 'Result: FAIL' . PHP_EOL);
    exit(1);
}

fwrite(STDOUT, 'Result: PASS' . PHP_EOL);
exit(0);
