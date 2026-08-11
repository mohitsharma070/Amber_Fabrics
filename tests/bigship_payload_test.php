<?php

function add_action(...$args): void {}
function add_filter(...$args): void {}
function plugin_setting(string $plugin, string $key, $default = null)
{
    return $GLOBALS['test_plugin_settings'][$key] ?? $default;
}

require_once __DIR__ . '/../plugins/shipping-courier/plugin.php';

$failures = [];
$assert = static function (bool $condition, string $message) use (&$failures): void {
    if (!$condition) {
        $failures[] = $message;
    }
};

$shipment = shipping_courier_shipment_data_from_response([
    'data' => ['awb_assigned' => 'AWB-123456'],
]);
$assert(($shipment['awb_code'] ?? '') === 'AWB-123456', 'awb_assigned was not mapped to awb_code.');
$assert(($shipment['tracking_id'] ?? '') === 'AWB-123456', 'awb_assigned was not mapped to tracking_id.');
$assert(
    !shipping_courier_provider_shipment_exists(['provider_order_id' => '691843632']),
    'A Bigship draft was incorrectly treated as a placed shipment.'
);
$assert(
    shipping_courier_provider_shipment_exists(['provider_shipment_id' => 'AWB-123456']),
    'A placed Bigship shipment was not recognized.'
);

$invoiceAmount = 127.49;
$allocated = shipping_courier_bigship_allocate_product_totals([
    ['line_total' => 100.00],
    ['line_total' => 50.00],
    ['line_total' => 25.00],
], $invoiceAmount);
$assert(abs(array_sum($allocated) - $invoiceAmount) < 0.001, 'Discount allocation does not equal the invoice amount.');
$assert(count($allocated) === 3, 'Discount allocation changed the product count.');

$GLOBALS['test_plugin_settings'] = [
    'bigship_warehouse_id' => 123,
    'bigship_warehouse_pincode' => '302001',
    'bigship_segment' => 'domestic_b2c',
    'bigship_risk_type_id' => 2,
    'bigship_product_category_id' => 1,
    'bigship_parcel_weight_kg' => 0.5,
    'bigship_parcel_length_cm' => 30,
    'bigship_parcel_width_cm' => 25,
    'bigship_parcel_height_cm' => 5,
];
$request = shipping_courier_bigship_order_request([
    'order' => [
        'id' => 10,
        'order_number' => 'AF-TEST-10',
        'customer_name' => 'Test Customer',
        'customer_phone' => '9876543210',
        'customer_email' => 'test@example.test',
        'address' => 'Test Street',
        'city' => 'Jaipur',
        'state' => 'Rajasthan',
        'pincode' => '302001',
        'country' => 'India',
        'subtotal' => 175.00,
        'discount_amount' => 47.51,
        'total_amount' => 127.49,
        'payment_method' => 'razorpay',
    ],
    'items' => [
        ['product_name' => 'Fabric A', 'unit_type' => 'meter', 'quantity_meters' => 2, 'quantity' => 2, 'line_total' => 100.00],
        ['product_name' => 'Fabric B', 'unit_type' => 'piece', 'quantity' => 1, 'line_total' => 75.00],
    ],
]);
$requestBody = is_array($request['body'] ?? null) ? $request['body'] : [];
$products = (array) ($requestBody['boxes'][0]['products'] ?? []);
$productTotal = array_sum(array_map(static fn(array $product): float => (float) ($product['totalAmount'] ?? 0), $products));
$assert(!empty($request['ok']), 'Domestic B2C payload could not be built.');
$assert(abs($productTotal - (float) ($requestBody['MasterOrderInvoiceAmount'] ?? -1)) < 0.001, 'B2C product totals do not match MasterOrderInvoiceAmount.');

$settings = [
    'bigship_parcel_weight_kg' => 0.5,
    'bigship_parcel_length_cm' => 20,
    'bigship_parcel_width_cm' => 15,
    'bigship_parcel_height_cm' => 5,
    'bigship_packaging_weight_kg' => 0.1,
    'bigship_weight_per_meter_kg' => 0.25,
    'bigship_weight_per_piece_kg' => 0.35,
    'bigship_weight_per_set_kg' => 0.75,
    'bigship_parcel_height_per_unit_cm' => 1.5,
    'bigship_parcel_max_height_cm' => 60,
];
$smallParcel = shipping_courier_bigship_parcel([['unit_type' => 'meter', 'quantity_meters' => 1]], $settings);
$largeParcel = shipping_courier_bigship_parcel([['unit_type' => 'meter', 'quantity_meters' => 10]], $settings);
$assert($largeParcel['weight'] > $smallParcel['weight'], 'Parcel weight does not grow with order quantity.');
$assert($largeParcel['height'] > $smallParcel['height'], 'Parcel height does not grow with order quantity.');

if ($failures !== []) {
    fwrite(STDERR, implode(PHP_EOL, $failures) . PHP_EOL);
    exit(1);
}

echo 'Bigship payload invariants passed.' . PHP_EOL;
