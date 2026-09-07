<?php
declare(strict_types=1);

$root = dirname(__DIR__);
require_once $root . '/includes/services/PaymentService.php';

$failures = [];
$assert = static function (bool $condition, string $message) use (&$failures): void {
    if (!$condition) {
        $failures[] = $message;
    }
};
$read = static function (string $path) use ($root): string {
    $contents = file_get_contents($root . '/' . $path);
    return $contents === false ? '' : $contents;
};

$payment = $read('includes/services/PaymentService.php');
$cron = $read('cron/run-plugins.php');
$config = $read('config/db.php');
$architecture = $read('docs/repo-architecture.md');

$assert(method_exists(PaymentService::class, 'cleanup_payment_webhook_events'), 'PaymentService must expose bounded payment webhook retention cleanup.');
$assert(str_contains($payment, "status = 'processed'") && str_contains($payment, 'raw_payload IS NOT NULL'), 'Payload scrubbing must target only safely processed events with retained payloads.');
$assert(str_contains($payment, 'updated_at = updated_at'), 'Payload scrubbing must preserve lifecycle timestamps.');
$assert(str_contains($cron, "'payment_webhook_retention'") && str_contains($cron, 'PAYMENT_WEBHOOK_PAYLOAD_RETENTION_DAYS'), 'Cron must run configurable payment webhook retention.');
$assert(str_contains($config, 'PAYMENT_WEBHOOK_AUDIT_RETENTION_DAYS') && str_contains($config, 'PAYMENT_WEBHOOK_CLEANUP_BATCH_SIZE'), 'Retention and bounded cleanup settings must accept environment overrides.');
$assert(str_contains($architecture, 'payment webhook payload') && str_contains($architecture, 'retryable'), 'Architecture documentation must describe payload retention and retry safety.');

if ($failures !== []) {
    fwrite(STDERR, "Payment webhook retention contract failures:\n- " . implode("\n- ", $failures) . "\n");
    exit(1);
}

echo "Payment webhook retention contract passed.\n";
