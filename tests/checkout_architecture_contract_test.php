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

$checkout = $read('checkout.php');
$placeOrder = $read('place-order.php');
$functions = $read('includes/functions.php');
$inputSource = $read('includes/domain/CheckoutInput.php');
$readService = $read('includes/services/CheckoutReadService.php');
$snapshotService = $read('includes/services/OrderItemSnapshotService.php');
$pricingService = $read('includes/services/CheckoutPricingService.php');

foreach ([
    'domain/CheckoutInput.php',
    'services/CheckoutReadService.php',
    'services/OrderItemSnapshotService.php',
    'services/CheckoutPricingService.php',
] as $module) {
    $assert(str_contains($functions, $module), 'Shared bootstrap must load checkout architecture module: ' . $module);
}

$assert(str_contains($checkout, 'CheckoutReadService::customerPrefill') && str_contains($checkout, 'CheckoutReadService::savedAddressState'), 'Checkout view must delegate customer/address reads.');
$assert(!str_contains($checkout, '$conn->prepare'), 'Checkout view must not own SQL statements.');
$assert(str_contains($placeOrder, 'CheckoutInput::fromRequest') && str_contains($placeOrder, 'CheckoutInput::validateContactDeliveryPayment'), 'Order endpoint must delegate request mapping and validation.');
$assert(str_contains($placeOrder, 'OrderItemSnapshotService::lockProducts') && str_contains($placeOrder, 'OrderItemSnapshotService::build'), 'Order endpoint must delegate locked catalog reads and item snapshots.');
$assert(str_contains($placeOrder, 'CheckoutPricingService::taxContext') && str_contains($placeOrder, 'CheckoutPricingService::allocateIncludedTax'), 'Order endpoint must delegate tax allocation.');
$transactionStart = strpos($placeOrder, '$conn->begin_transaction()');
$lockedRead = strpos($placeOrder, 'OrderItemSnapshotService::lockProducts');
$transactionCommit = strpos($placeOrder, '$conn->commit()');
$assert($transactionStart !== false && $lockedRead !== false && $transactionCommit !== false && $transactionStart < $lockedRead && $lockedRead < $transactionCommit, 'place-order.php must retain ownership of the order transaction.');
$assert(str_contains($snapshotService, 'FOR UPDATE') && str_contains($snapshotService, "product_type'] ?? 'simple'") && str_contains($snapshotService, '$requiresVariant'), 'Item snapshot service must lock products and enforce variant mode.');
$assert(str_contains($pricingService, "'igst'") && str_contains($pricingService, "'cgst_sgst'") && str_contains($pricingService, '100 + $itemGstRate'), 'Pricing service must preserve inclusive GST allocation.');
$assert(str_contains($inputSource, 'International checkout is inquiry-only') && str_contains($inputSource, 'Invalid payment method.'), 'Checkout input policy must preserve canonical validation errors.');
$assert(str_contains($readService, 'CustomerAccountService::profile') && str_contains($readService, 'CustomerAddressService::list'), 'Checkout reads must reuse customer services.');

require_once $root . '/includes/functions.php';

$input = CheckoutInput::fromRequest([
    'full_name' => '  Test Buyer  ',
    'phone' => '9876543210',
    'email' => 'buyer@example.test',
    'address' => '  12 Test Street ',
    'city' => ' Jaipur ',
    'state' => ' Rajasthan ',
    'pincode' => '302001',
    'country' => 'India',
    'payment_method' => ' RAZORPAY ',
    'online_method' => ' UPI ',
    'create_account' => '1',
], 0);
$assert($input['full_name'] === 'Test Buyer' && $input['payment_method'] === 'razorpay', 'Checkout input normalization changed.');
$assert($input['online_method'] === 'upi' && $input['wants_create_account'] === true, 'Online preference or guest account intent normalization changed.');
$assert(CheckoutInput::validateContactDeliveryPayment($input) === [], 'Valid India checkout input must remain accepted.');
$assert(CheckoutInput::hasCompleteDelivery($input), 'Complete delivery input must unlock checkout pricing.');
$invalid = $input;
$invalid['country'] = 'Other';
$invalid['payment_method'] = 'cash';
$invalidErrors = CheckoutInput::validateContactDeliveryPayment($invalid);
$assert(isset($invalidErrors['country'], $invalidErrors['payment_method']), 'Country and payment allowlists must remain enforced.');

$product = [
    'id' => 1,
    'name' => 'Test Fabric',
    'sku' => 'TEST-1',
    'product_type' => 'simple',
    'unit_type' => 'piece',
    'meter_options' => '',
    'min_order_meters' => 1,
    'qty_step' => 1,
    'stock' => 10,
    'stock_meters' => 0,
    'is_available' => 1,
    'status' => 'active',
    'price' => 100,
    'sale_price' => 90,
    'cost_price' => 40,
    'size' => '',
    'color' => 'Amber',
    'gst_rate' => null,
    'hsn_code' => '',
    'shipping_weight_kg' => null,
    'parcel_length_cm' => null,
    'parcel_width_cm' => null,
    'parcel_height_cm' => null,
];
$snapshot = OrderItemSnapshotService::build(['1' => 2], [], [], [1 => $product], [], 18.0, '5208');
$assert(abs((float) $snapshot['subtotal'] - 180.0) < 0.001 && count($snapshot['items']) === 1, 'Order item subtotal or line count changed during extraction.');
$assert((float) $snapshot['items'][0]['quantity'] === 2.0 && (float) $snapshot['items'][0]['price'] === 90.0, 'Order item quantity or sale-price snapshot changed.');

$taxed = CheckoutPricingService::allocateIncludedTax($snapshot['items'], 180.0, 30.0, 'cgst_sgst', 18.0, '5208');
$assert((float) $taxed[0]['discount_amount'] === 30.0 && (float) $taxed[0]['taxable_amount'] === 150.0, 'Discount allocation changed during extraction.');
$assert(abs(((float) $taxed[0]['cgst_amount'] + (float) $taxed[0]['sgst_amount']) - (float) $taxed[0]['gst_amount']) < 0.001, 'Inclusive GST split must reconcile exactly.');

if ($failures !== []) {
    fwrite(STDERR, "Checkout architecture contract failures:\n- " . implode("\n- ", $failures) . "\n");
    exit(1);
}

echo "Checkout architecture contracts passed.\n";
