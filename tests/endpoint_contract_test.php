<?php

require_once __DIR__ . '/../includes/services/BigshipService.php';

$failures = [];
$reflection = new ReflectionClass(BigshipService::class);
$requiredMethods = [
    'login', 'profile', 'saveWarehouse', 'warehouses', 'editWarehouse', 'packages',
    'paymentModes', 'riskTypes', 'rates', 'createOrder', 'courierCosts', 'placeOrder',
    'cancelOrder', 'trackOrder', 'shipmentDetails', 'downloadDocuments',
];
foreach ($requiredMethods as $method) {
    if (!$reflection->hasMethod($method) || !$reflection->getMethod($method)->isPublic()) {
        $failures[] = 'Missing public Bigship method: ' . $method;
    }
}

$source = (string) file_get_contents(__DIR__ . '/../includes/services/BigshipService.php');
$requiredEndpoints = [
    '/api/outbound/login',
    '/api/outbound/profile',
    '/api/outbound/save-warehouse-data',
    '/api/outbound/get-warehouse-list',
    '/api/outbound/edit-warehouse-data',
    '/api/outbound/hyperlocal/get-packages-list',
    '/api/outbound/get-payment-mode',
    '/api/outbound/domestic/risk-types',
    '/api/outbound/user-rate-calculator',
    '/api/outbound/create-order',
    '/api/outbound/courier-wise-shipment-cost',
    '/api/outbound/place-order',
    '/api/outbound/cancel-order',
    '/api/outbound/track-order',
    '/api/outbound/order-shipment-details',
    '/api/outbound/download-shipment-documents',
];
foreach ($requiredEndpoints as $endpoint) {
    if (!str_contains($source, $endpoint)) {
        $failures[] = 'Missing Bigship endpoint: ' . $endpoint;
    }
}

if (!str_contains($source, "'perPage' => 25") || !str_contains($source, "'page' => 1")) {
    $failures[] = 'Warehouse pagination defaults are missing.';
}
if (!str_contains($source, "'segment_type' =>")) {
    $failures[] = 'Warehouse segment_type default is missing.';
}
if (!str_contains($source, "['MasterCustomOrderId' => \$id]")) {
    $failures[] = 'Order Detail does not use MasterCustomOrderId.';
}
if (substr_count($source, 'getPayloadInBody: true') < 2) {
    $failures[] = 'Bigship GET JSON-body contracts are incomplete.';
}
if (!str_contains($source, "return \$this->request('POST', '/api/outbound/download-shipment-documents'")) {
    $failures[] = 'Document Download does not use the live POST contract.';
}
if (!str_contains($source, "\$payload['track_segment']") || !str_contains($source, "\$payload['courier_id']")) {
    $failures[] = 'Track Order context fields are missing.';
}

$warehouseSegmentMethod = $reflection->getMethod('warehouseSegment');
$domesticService = new BigshipService(['bigship_segment' => 'domestic_b2c']);
if ($warehouseSegmentMethod->invoke($domesticService) !== 'local') {
    $failures[] = 'Domestic B2C is not normalized for the warehouse endpoint.';
}
$overrideService = new BigshipService([
    'bigship_segment' => 'domestic_b2c',
    'bigship_warehouse_segment' => 'hyperlocal',
]);
if ($warehouseSegmentMethod->invoke($overrideService) !== 'hyperlocal') {
    $failures[] = 'Warehouse segment override is not honored.';
}

$pluginSource = (string) file_get_contents(__DIR__ . '/../plugins/shipping-courier/plugin.php');
if (!str_contains($pluginSource, "'awb_assigned'")) {
    $failures[] = 'Bigship awb_assigned mapping is missing.';
}
if (!str_contains($pluginSource, 'shipping_courier_bigship_allocate_product_totals')) {
    $failures[] = 'Bigship product invoice allocation is missing.';
}
if (!str_contains($pluginSource, 'shipping_courier_bigship_parcel')) {
    $failures[] = 'Bigship quantity-aware parcel calculation is missing.';
}

if ($failures !== []) {
    fwrite(STDERR, implode(PHP_EOL, $failures) . PHP_EOL);
    exit(1);
}

echo 'Bigship endpoint contract: 16/16 present.' . PHP_EOL;
