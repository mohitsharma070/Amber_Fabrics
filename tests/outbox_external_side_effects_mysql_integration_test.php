<?php
if (PHP_SAPI !== 'cli') { exit(2); }
if (getenv('AMBER_RUN_DISPOSABLE_MYSQL_TESTS') !== '1') {
    echo "Outbox external side-effect MySQL tests skipped; set AMBER_RUN_DISPOSABLE_MYSQL_TESTS=1.\n";
    exit(0);
}
if (getenv('APP_MODE') !== 'local' || !preg_match('/_(test|e2e)$/', (string) getenv('DB_NAME'))
    || !in_array(getenv('DB_HOST'), ['127.0.0.1', 'localhost', '::1'], true)) {
    throw new RuntimeException('An explicitly configured loopback disposable _test/_e2e database is required.');
}

define('AMBER_TESTING', true);
$root = dirname(__DIR__);
require $root . '/config/db.php';
require_once $root . '/includes/functions.php';
require_once $root . '/includes/hooks.php';
require_once $root . '/includes/plugin-loader.php';
require_once $root . '/plugins/shipping-courier/plugin.php';

final class CrashWindowBigshipFake
{
    public int $createCalls = 0;
    public int $placeCalls = 0;
    public int $detailsCalls = 0;
    public array $lastCreatePayload = [];
    public bool $remotePlaced = false;

    public function createOrder(array $payload): array
    {
        $this->createCalls++;
        $this->lastCreatePayload = $payload;
        return ['ok' => true, 'status' => 200, 'body' => ['CustomGlobalOrderId' => 'BIG-ORDER-1']];
    }

    public function courierCosts(array $payload): array
    {
        return ['ok' => true, 'status' => 200, 'body' => ['courier_name' => 'Fake Courier']];
    }

    public function placeOrder(array $payload, bool $multipart = false): array
    {
        $this->placeCalls++;
        $this->remotePlaced = true;
        return ['ok' => true, 'status' => 200, 'body' => [
            'CustomGlobalOrderId' => 'BIG-ORDER-1',
            'BigshipOrderId' => 'BIG-SHIPMENT-1',
            'awb_assigned' => 'AWB-1',
            'status' => 'booked',
        ]];
    }

    public function shipmentDetails(string $providerOrderId): array
    {
        $this->detailsCalls++;
        $body = ['CustomGlobalOrderId' => $providerOrderId, 'status' => 'created'];
        if ($this->remotePlaced) {
            $body += ['BigshipOrderId' => 'BIG-SHIPMENT-1', 'awb_assigned' => 'AWB-1', 'status' => 'booked'];
        }
        return ['ok' => true, 'status' => 200, 'body' => $body];
    }

    public function downloadDocuments(string $providerOrderId, string $type = 'label'): array
    {
        return ['ok' => false, 'status' => 404, 'body' => []];
    }
}

$failures = [];
$assert = static function (bool $condition, string $message) use (&$failures): void {
    if (!$condition) { $failures[] = $message; }
};
$suffix = bin2hex(random_bytes(5));
$orderNumber = 'CRASH-' . $suffix;
$fake = new CrashWindowBigshipFake();
$GLOBALS['shipping_courier_bigship_client_override'] = $fake;
$GLOBALS['amber_plugin_settings']['shipping-courier'] = [
    'enabled' => 1,
    'provider' => 'bigship',
    'bigship_username' => 'fake',
    'bigship_password' => 'fake',
    'bigship_access_key' => 'fake',
    'api_base_url' => 'http://127.0.0.1',
    'bigship_warehouse_id' => 1,
    'bigship_warehouse_pincode' => '302001',
    'bigship_segment' => 'domestic_b2c',
    'bigship_risk_type_id' => 2,
    'bigship_product_category_id' => 1,
    'bigship_parcel_weight_kg' => 0.5,
    'bigship_parcel_length_cm' => 20,
    'bigship_parcel_width_cm' => 15,
    'bigship_parcel_height_cm' => 5,
];
$orderId = 0;
$shipmentId = 0;

try {
    $stmt = $conn->prepare(
        "INSERT INTO orders
            (order_number, customer_name, customer_phone, customer_email, address, city, state, pincode, country,
             subtotal, total_amount, total, payment_method, payment_status, order_status, status,
             courier_id, courier_name, base_shipping, created_at)
         VALUES (?, 'Crash Test', '9876543210', 'crash@example.test', '1 Test Road', 'Jaipur', 'Rajasthan', '302001', 'India',
                 100, 100, 100, 'cod', 'pending', 'confirmed', 'confirmed', 77, 'Fake Courier', 10, '2026-01-02 03:04:05')"
    );
    $stmt->bind_param('s', $orderNumber);
    $stmt->execute();
    $orderId = (int) $conn->insert_id;

    $builtOnce = shipping_courier_bigship_order_request((array) shipping_courier_order_payload($conn, $orderId));
    usleep(1100000);
    $builtAgain = shipping_courier_bigship_order_request((array) shipping_courier_order_payload($conn, $orderId));
    $assert(
        ($builtOnce['body']['MasterOrderDate'] ?? null) === ($builtAgain['body']['MasterOrderDate'] ?? null),
        'The Bigship create payload must be deterministic across worker retries.'
    );
    $assert(($builtOnce['body']['OrderInvoiceNo'] ?? '') === $orderNumber, 'The stable local order number must be the Bigship business key.');

    $faults = ['create_order' => 1, 'place_order' => 0];
    $GLOBALS['shipping_courier_bigship_fault_after_success'] = static function (string $operation) use (&$faults): void {
        if (($faults[$operation] ?? 0) > 0) {
            $faults[$operation]--;
            throw new RuntimeException('Injected worker crash after ' . $operation . ' success.');
        }
    };

    try {
        shipping_courier_create_shipment($conn, $orderId);
        $assert(false, 'The create-order crash fault was not reached.');
    } catch (RuntimeException $e) {
        $assert(str_contains($e->getMessage(), 'create_order'), 'Unexpected create-order fault exception.');
    }
    $assert($fake->createCalls === 1, 'The fake provider must observe one successful createOrder call before the crash.');

    $uncertainRetry = shipping_courier_create_shipment($conn, $orderId);
    $assert(empty($uncertainRetry['ok']), 'An unknown createOrder outcome must fail closed pending reconciliation.');
    $assert($fake->createCalls === 1, 'An unknown createOrder outcome must not call Bigship createOrder again.');

    $shipment = shipping_courier_get_shipment($conn, $orderId);
    $shipmentId = (int) ($shipment['id'] ?? 0);
    $metadata = shipping_courier_get_metadata($conn, $shipmentId, 'bigship') ?: [];
    $raw = json_decode((string) ($metadata['raw_response_json'] ?? ''), true);
    $assert(($raw['create_order_intent']['business_id'] ?? '') === $orderNumber, 'The pre-call create intent must retain the stable business ID.');

    // Simulate reconciliation from the Bigship panel/support using the stable invoice number.
    shipping_courier_upsert_metadata($conn, $orderId, $shipmentId, [
        'provider' => 'bigship',
        'provider_order_id' => 'BIG-ORDER-1',
        'raw_response_json' => $raw,
    ]);
    $faults['place_order'] = 1;
    try {
        shipping_courier_create_shipment($conn, $orderId);
        $assert(false, 'The place-order crash fault was not reached.');
    } catch (RuntimeException $e) {
        $assert(str_contains($e->getMessage(), 'place_order'), 'Unexpected place-order fault exception.');
    }
    $assert($fake->createCalls === 1 && $fake->placeCalls === 1, 'A persisted provider order ID must prevent duplicate createOrder execution.');

    $reconciled = shipping_courier_create_shipment($conn, $orderId);
    $assert(!empty($reconciled['ok']), 'A retry must reconcile a remotely placed shipment.');
    $assert($fake->placeCalls === 1, 'Reconciliation must prevent a second placeOrder call.');
    $metadata = shipping_courier_get_metadata($conn, $shipmentId, 'bigship') ?: [];
    $assert(($metadata['provider_order_id'] ?? '') === 'BIG-ORDER-1', 'Reconciliation must preserve the provider order ID.');
    $assert(($metadata['provider_shipment_id'] ?? '') === 'BIG-SHIPMENT-1', 'Reconciliation must persist the remote shipment ID.');

    $again = shipping_courier_create_shipment($conn, $orderId);
    $assert(!empty($again['ok']) && $fake->createCalls === 1 && $fake->placeCalls === 1, 'Completed retries must be idempotent.');
} finally {
    unset($GLOBALS['shipping_courier_bigship_client_override'], $GLOBALS['shipping_courier_bigship_fault_after_success']);
    if ($shipmentId > 0) {
        $deleteShipment = $conn->prepare('DELETE FROM shipments WHERE id = ?');
        $deleteShipment->bind_param('i', $shipmentId);
        $deleteShipment->execute();
    }
    if ($orderId > 0) {
        $deleteOrder = $conn->prepare('DELETE FROM orders WHERE id = ?');
        $deleteOrder->bind_param('i', $orderId);
        $deleteOrder->execute();
    }
}

if ($failures !== []) {
    fwrite(STDERR, implode(PHP_EOL, $failures) . PHP_EOL);
    exit(1);
}
echo "Outbox external side-effect crash-window MySQL tests passed.\n";
