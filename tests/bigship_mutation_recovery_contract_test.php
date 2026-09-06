<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$service = (string) file_get_contents($root . '/includes/services/BigshipService.php');
$lifecycle = (string) file_get_contents($root . '/plugins/shipping-courier/modules/shipment-lifecycle.php');
$payloads = (string) file_get_contents($root . '/plugins/shipping-courier/modules/bigship-payloads.php');
$architecture = (string) file_get_contents($root . '/docs/repo-architecture.md');

$failures = [];
$assert = static function (bool $condition, string $message) use (&$failures): void {
    if (!$condition) {
        $failures[] = $message;
    }
};

$assert(str_contains($service, "'/api/outbound/create-order', \$payload, [], true, false, false, false")
    && str_contains($service, "'/api/outbound/place-order', \$payload, [], true, \$multipart, false, false"), 'Non-idempotent Bigship mutations must disable transport retries.');
$createIntent = strpos($lifecycle, "'create_order_intent'");
$createCall = strpos($lifecycle, '->createOrder(');
$placeIntent = strpos($lifecycle, "'place_order_intent'");
$placeCall = strpos($lifecycle, '->placeOrder(');
$assert($createIntent !== false && $createCall !== false && $createIntent < $createCall, 'Create intent must be durable before calling Bigship.');
$assert($placeIntent !== false && $placeCall !== false && $placeIntent < $placeCall, 'Place intent must be durable before calling Bigship.');
$assert(str_contains($lifecycle, 'outcome is unknown') && str_contains($lifecycle, 'shipmentDetails($customGlobalOrderId)'), 'Unknown outcomes must fail closed, with GET reconciliation where a provider order ID exists.');
$assert(str_contains($payloads, "\$order['created_at']") && !str_contains($payloads, "\$orderDate = gmdate('Y-m-d H:i:s')"), 'Bigship payload retries must use the stable order creation time.');
$assert(str_contains($architecture, 'Bigship') && str_contains($architecture, 'outcome-unknown'), 'Architecture documentation must describe Bigship mutation recovery.');

if ($failures !== []) {
    fwrite(STDERR, "Bigship recovery failures:\n- " . implode("\n- ", $failures) . "\n");
    exit(1);
}

echo "bigship_mutation_recovery_contract_test: OK\n";
