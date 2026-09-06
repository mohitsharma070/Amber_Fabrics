<?php

$root = dirname(__DIR__);
require_once $root . '/includes/helpers/migration-checksum.php';
$failures = [];
$assert = static function (bool $condition, string $message) use (&$failures): void {
    if (!$condition) {
        $failures[] = $message;
    }
};
$read = static function (string $path) use ($root): string {
    $value = file_get_contents($root . '/' . $path);
    return $value === false ? '' : $value;
};

require_once $root . '/includes/services/CronService.php';
require_once $root . '/includes/hooks.php';

$normalized = CronService::normalizeResult(['processed' => 2, 'succeeded' => 1, 'failed' => 1]);
$assert($normalized['status'] === 'degraded', 'A result with failed records must normalize to degraded.');
$assert(CronService::sanitizeError('Authorization: Bearer secret-value?token=visible') !== 'Authorization: Bearer secret-value?token=visible', 'Cron errors must redact bearer/token values.');
$noncriticalSummary = CronService::summarize([['name' => 'mail', 'critical' => false, 'status' => 'failed']]);
$criticalSummary = CronService::summarize([['name' => 'payment', 'critical' => true, 'status' => 'degraded']]);
$assert($noncriticalSummary['status'] === 'degraded' && $noncriticalSummary['critical_failures'] === 0, 'Noncritical failures must degrade without becoming critical.');
$assert($criticalSummary['status'] === 'failed' && $criticalSummary['critical_failures'] === 1, 'Critical partial failures must fail the run.');

$GLOBALS['amber_hooks'] = [];
$GLOBALS['amber_cron_metadata'] = [];
$callback = static fn(array $context): array => CronService::result('degraded', 1, 0, 1);
add_cron_action($callback, 10, true);
$report = do_action_report('cron.tick');
$assert(count($report) === 1 && !empty($report[0]['critical']) && $report[0]['status'] === 'degraded', 'Reported cron hooks must preserve criticality and structured callback status.');

$cron = $read('cron/run-plugins.php');
$hooks = $read('includes/hooks.php');
$payment = $read('includes/services/PaymentService.php');
$abandoned = $read('plugins/abandoned-cart-email/plugin.php');
$backInStock = $read('plugins/back-in-stock-alert/plugin.php');
$feed = $read('plugins/product-feed/plugin.php');
$dashboard = $read('admin/dashboard.php');
$migrationPath = 'database/migrations/2026-08-21-cron-reliability-hardening.sql';
$migration = $read($migrationPath);
$schema = $read('database/schema.sql');
$setup = $read('database/setup.php');
$openapi = $read('openapi.yaml');

$assert(str_contains($cron, 'HTTP_X_CRON_TOKEN') && !str_contains($cron, "\$_GET['token']"), 'Cron must require the header token and reject the query fallback.');
$assert(str_contains($cron, "--check") && str_contains($cron, "if (!\$isCheck)"), '--check must exist and skip persistent health writes.');
$assert(str_contains($cron, 'finally') && str_contains($cron, 'cron_db_lock_state') && str_contains($cron, "'busy'"), 'Cron locks must distinguish contention and release in finally.');
$assert(str_contains($cron, 'http_response_code(500)') && str_contains($cron, 'http_response_code(405)'), 'Cron HTTP status handling must expose runtime failure and reject unsupported methods.');
$assert(str_contains($hooks, 'critical') && str_contains($hooks, 'callbackResult'), 'Hook reports must execute callbacks and retain critical metadata.');
$assert(str_contains($payment, 'release_stale_pending_razorpay_orders_global_report') && str_contains($payment, 'failed_order_ids'), 'Razorpay expiry must continue with a structured failure report.');
$assert(str_contains($abandoned, "status = 'processing'") && str_contains($abandoned, 'retry_base_minutes') && str_contains($abandoned, 'consecutive_failures'), 'Abandoned-cart reminders must use claims and bounded backoff.');
$assert(str_contains($backInStock, 'Recovered stale processing claim.') && str_contains($backInStock, "'failed' : 'pending'") && str_contains($backInStock, 'next_attempt_at'), 'Back-in-stock delivery must recover stale claims and terminate exhausted retries.');
$assert(str_contains($feed, 'tempnam') && str_contains($feed, 'LOCK_EX') && str_contains($feed, 'rename('), 'Product feeds must publish through verified atomic replacement.');
$assert(str_contains($dashboard, 'cron_last_success_at') && str_contains($dashboard, 'cron_last_status') && str_contains($dashboard, 'CRON_EXPECTED_INTERVAL_MINUTES'), 'Admin dashboard must expose cron health and overdue state.');

foreach (['delivery_attempts', 'next_attempt_at', 'idx_public_form_attempts_updated', 'idx_shipping_courier_provider_updated', 'idx_support_tickets_status_updated'] as $needle) {
    $assert(str_contains($migration, $needle) && str_contains($schema, $needle) && str_contains($setup, $needle), 'Migration/schema/setup must align for ' . $needle . '.');
}
$checksum = migration_file_checksum($root . '/' . $migrationPath);
$assert(is_string($checksum) && str_contains($schema, $checksum), 'Fresh-schema migration baseline must contain the current migration checksum.');
$assert(str_contains($openapi, 'Require X-Cron-Token') && str_contains($openapi, "'405':"), 'OpenAPI must document strict token requirement and method rejection.');

if ($failures !== []) {
    fwrite(STDERR, "Cron reliability hardening contract failures:\n- " . implode("\n- ", $failures) . "\n");
    exit(1);
}

echo "Cron reliability hardening contracts passed.\n";
