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

if ($failures !== []) {
    fwrite(STDERR, implode(PHP_EOL, $failures) . PHP_EOL);
    exit(1);
}

echo 'Bigship endpoint contract: 16/16 present.' . PHP_EOL;
