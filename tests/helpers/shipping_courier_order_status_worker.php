<?php

if (PHP_SAPI !== 'cli' || (string) getenv('AMBER_RUN_DISPOSABLE_MYSQL_TESTS') !== '1'
    || getenv('APP_MODE') !== 'local' || !preg_match('/_(test|e2e)$/', (string) getenv('DB_NAME'))
    || !in_array(getenv('DB_HOST'), ['127.0.0.1', 'localhost', '::1'], true)) {
    exit(2);
}

define('AMBER_TESTING', true);
$root = dirname(__DIR__, 2);
require $root . '/config/db.php';
require_once $root . '/includes/functions.php';
require_once $root . '/includes/hooks.php';
require_once $root . '/includes/plugin-loader.php';
require_once $root . '/plugins/shipping-courier/plugin.php';

final class ShippingCourierStaleStatusFake
{
    public function shipmentDetails(string $providerOrderId): array
    {
        return ['ok' => true, 'status' => 200, 'body' => [
            'CustomGlobalOrderId' => $providerOrderId,
            'BigshipOrderId' => 'BIG-STATUS-SHIPMENT',
            'status' => 'in_transit',
        ]];
    }

    public function trackOrder(string $providerOrderId, int $courierId = 0, string $trackSegment = ''): array
    {
        return ['ok' => true, 'status' => 200, 'body' => [
            'CustomGlobalOrderId' => $providerOrderId,
            'BigshipOrderId' => 'BIG-STATUS-SHIPMENT',
            'status' => 'in_transit',
        ]];
    }
}

$GLOBALS['shipping_courier_bigship_client_override'] = new ShippingCourierStaleStatusFake();
$GLOBALS['amber_plugin_settings']['shipping-courier'] = [
    'enabled' => 1,
    'provider' => 'bigship',
    'tracking_sync' => 1,
    'bigship_username' => 'fake',
    'bigship_password' => 'fake',
    'bigship_access_key' => 'fake',
    'api_base_url' => 'http://127.0.0.1',
    'bigship_warehouse_id' => 1,
    'bigship_warehouse_pincode' => '302001',
    'bigship_segment' => 'domestic_b2c',
];

$conn->query('SET SESSION innodb_lock_wait_timeout = 15');
fwrite(STDOUT, $conn->thread_id . "\n");
fflush(STDOUT);

try {
    $result = shipping_courier_sync_tracking($conn, (int) ($argv[1] ?? 0));
    fwrite(STDOUT, json_encode($result, JSON_UNESCAPED_SLASHES) . "\n");
} catch (Throwable $e) {
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(1);
}

