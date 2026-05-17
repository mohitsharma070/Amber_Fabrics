<?php
require_once __DIR__ . '/../includes/init.php';

if (PHP_SAPI !== 'cli') {
    $expectedToken = trim((string) _cfg('CRON_RUN_TOKEN', ''));
    $providedToken = trim((string) ($_GET['token'] ?? ($_SERVER['HTTP_X_CRON_TOKEN'] ?? '')));
    if ($expectedToken === '' || $providedToken === '' || !hash_equals($expectedToken, $providedToken)) {
        http_response_code(403);
        echo "Forbidden\n";
        exit;
    }
}

release_stale_pending_razorpay_orders_global($conn, 30, 200);
try {
    if (function_exists('save_site_settings_to_db')) {
        save_site_settings_to_db($conn, ['cron_last_run_at' => date('Y-m-d H:i:s')]);
    }
} catch (Throwable $e) {
    error_log('[cron] cron_last_run_at save failed: ' . $e->getMessage());
}

do_action('cron.tick', [
    'conn' => $conn,
    'ran_at' => date('Y-m-d H:i:s'),
]);

echo "OK\n";
