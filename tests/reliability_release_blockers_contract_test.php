<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$failures = [];
$assert = static function (bool $condition, string $message) use (&$failures): void {
    if (!$condition) {
        $failures[] = $message;
    }
};
$read = static fn(string $path): string => (string) file_get_contents($root . '/' . $path);

$metaCapi = $read('plugins/meta-capi/plugin.php');
$paymentService = $read('includes/services/PaymentService.php');
$webhook = $read('payment/razorpay-webhook.php');

$assert(
    str_contains($metaCapi, "add_action('order.after_commit', 'meta_capi_handle_cod_purchase', 30);")
        && !str_contains($metaCapi, "add_action('order.after_create', 'meta_capi_handle_cod_purchase', 30);"),
    'Meta CAPI COD Purchase delivery must run through the durable post-commit order hook, never inside order.after_create.'
);

$claimStart = strpos($paymentService, 'public static function payment_webhook_begin_processing');
$selectStart = $claimStart === false ? false : strpos($paymentService, '$select = $conn->prepare(', $claimStart);
$claimPrefix = ($claimStart !== false && $selectStart !== false)
    ? substr($paymentService, $claimStart, $selectStart - $claimStart)
    : '';
$assert(
    $claimPrefix !== '' && !str_contains($claimPrefix, 'updated_at = NOW()'),
    'Webhook duplicate upsert must not refresh updated_at before the stale-processing lease check.'
);

$missingOrderStart = strpos($webhook, "if ($rzpOrderId === '') {");
$paymentFailedStart = $missingOrderStart === false ? false : strpos($webhook, "if ($eventType === 'payment.failed')", $missingOrderStart);
$missingOrderBlock = ($missingOrderStart !== false && $paymentFailedStart !== false)
    ? substr($webhook, $missingOrderStart, $paymentFailedStart - $missingOrderStart)
    : '';
$assert(
    $missingOrderBlock !== ''
        && str_contains($missingOrderBlock, 'PaymentService::payment_webhook_mark_failed(')
        && str_contains($missingOrderBlock, "http_response_code(400)"),
    'A claimed supported webhook with no Razorpay order id must mark the lifecycle failed before returning HTTP 400.'
);

if ($failures !== []) {
    fwrite(STDERR, "Reliability release blocker contract failures:\n- " . implode("\n- ", $failures) . "\n");
    exit(1);
}

echo "Reliability release blocker contracts passed.\n";
