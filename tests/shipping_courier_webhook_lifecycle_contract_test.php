<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$module = (string) file_get_contents($root . '/plugins/shipping-courier/modules/webhook-handling.php');
$endpoint = (string) file_get_contents($root . '/shipping-courier-webhook.php');
$failures = [];
$assert = static function (bool $condition, string $message) use (&$failures): void {
    if (!$condition) {
        $failures[] = $message;
    }
};

$assert(
    str_contains($module, 'FOR UPDATE')
        && str_contains($module, "'attempts' =>")
        && str_contains($module, "status = 'processing' AND attempts = ?"),
    'Courier webhook claims and completion must be fenced by the claimed attempt.'
);
$assert(
    substr_count($module, '$stmt->affected_rows !== 1') >= 2,
    'Courier processed and failed transitions must reject stale lease owners.'
);
$assert(
    str_contains($endpoint, '$lifecycleAttempt')
        && str_contains($endpoint, "header('Retry-After: 5')")
        && str_contains($endpoint, 'http_response_code(503)'),
    'An active courier webhook duplicate must receive a retriable response.'
);
$assert(
    str_contains($endpoint, 'shipping_courier_webhook_mark_processed($conn, $provider, $eventId, $signature, $rawPayload, $lifecycleAttempt)')
        && str_contains($endpoint, 'shipping_courier_webhook_mark_failed($conn, $provider, $eventId, $e->getMessage(), $signature, $lifecycleAttempt)'),
    'Courier lifecycle completion calls must carry the claimed attempt number.'
);

if ($failures !== []) {
    fwrite(STDERR, implode(PHP_EOL, $failures) . PHP_EOL);
    exit(1);
}

echo "shipping_courier_webhook_lifecycle_contract_test: OK\n";
