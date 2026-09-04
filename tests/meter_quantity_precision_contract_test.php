<?php
declare(strict_types=1);

$root = dirname(__DIR__);
require_once $root . '/includes/helpers/core.php';
require_once $root . '/includes/services/CartService.php';
require_once $root . '/includes/services/OrderItemSnapshotService.php';
require_once $root . '/includes/services/ProductAdminService.php';
require_once $root . '/includes/services/ProductImportService.php';

$failures = [];
$assert = static function (bool $condition, string $message) use (&$failures): void {
    if (!$condition) {
        $failures[] = $message;
    }
};
$assertQuantity = static function ($actual, float $expected, string $message) use (&$failures): void {
    if (abs((float) $actual - $expected) > 0.0001) {
        $failures[] = $message . ' Expected ' . $expected . ', got ' . (float) $actual . '.';
    }
};
$read = static fn(string $path): string => (string) file_get_contents($root . '/' . $path);

$assertQuantity(normalize_meter_quantity('1.00', 0.01), 1.00, 'One meter must remain exact.');
$assertQuantity(normalize_meter_quantity('2.50', 0.01), 2.50, 'Two and a half meters must remain exact.');
$assertQuantity(normalize_meter_quantity('2.504', 0.01), 2.50, 'Meter quantity normalization must remain two-decimal.');

$hasRepresentabilityPolicy = function_exists('meter_qty_step_is_representable');
$assert($hasRepresentabilityPolicy, 'A shared four-decimal meter-step representability policy is required.');
if ($hasRepresentabilityPolicy) {
    foreach ([0.0001, 0.001, 0.01, 0.05, 0.10, 0.125, 0.25, 0.50, 1.00] as $step) {
        $assert(meter_qty_step_is_representable($step), 'Valid four-decimal step ' . $step . ' was rejected.');
    }
    $assert(!meter_qty_step_is_representable(0.00001), 'A step with more than four decimals must be rejected.');
}

$assert(meter_qty_respects_step(1.01, 1.00, 0.01), 'A 0.01-meter step must remain valid.');
$assert(meter_qty_respects_step(1.10, 1.00, 0.05), 'A 0.05-meter step must remain valid.');
$assert(meter_qty_respects_step(1.25, 1.00, 0.25), 'A 0.25-meter step must remain valid.');
$assert(meter_qty_respects_step(1.25, 1.00, 0.125), 'Runtime validation must accept a cent-storable quantity on a 0.125-meter step.');
$assert(!meter_qty_respects_step(1.20, 1.00, 0.125), 'Runtime validation must reject a quantity outside the 0.125-meter grid.');

$validationConnection = mysqli_init();
$validationInput = [
    'sku' => 'x', // Keep this invalid so the pure quantity checks do not query a database.
    'unit_type' => 'meter',
    'stock' => 10,
    'min_order_meters' => 1.00,
    'qty_step' => '0.125',
    'price' => 100,
    'sale_price' => '',
    'cost_price' => 50,
];
$validFourDecimalStepValidation = ProductAdminService::validateExtended($validationConnection, $validationInput);
$assert(
    !isset($validFourDecimalStepValidation['errors']['qty_step']),
    'Admin validation must accept a four-decimal meter step.'
);
foreach (['0.0001', '0.001', '0.01', '0.05', '0.10', '0.125', '0.25', '0.50', '1.00'] as $step) {
    $validationInput['qty_step'] = $step;
    $validStepValidation = ProductAdminService::validateExtended($validationConnection, $validationInput);
    $assert(!isset($validStepValidation['errors']['qty_step']), 'Admin validation rejected valid meter step ' . $step . '.');
}

$assertQuantity(
    CartService::maximumSellableQuantity(9.00, 'meter', 1.00, 0.25, 2.50),
    7.50,
    'Multiple 2.50-meter cuts must stay on the configured two-decimal grid.'
);
$assertQuantity(
    CartService::maximumSellableQuantity(9.00, 'meter', 1.00, 0.125, 2.50),
    7.50,
    'A four-decimal step must intersect the selected cut-length grid.'
);
$assertQuantity(
    CartService::maximumSellableQuantity(1.40, 'meter', 1.00, 0.125, null),
    1.25,
    'A four-decimal step must reconcile to the highest two-decimal quantity on its grid.'
);

$product = [
    'id' => 1,
    'name' => 'Meter Fabric',
    'sku' => 'METER-1',
    'product_type' => 'variable',
    'unit_type' => 'meter',
    'meter_options' => '2.50',
    'min_order_meters' => 1.00,
    'qty_step' => 0.25,
    'stock' => 0,
    'stock_meters' => 0,
    'is_available' => 1,
    'status' => 'active',
    'price' => 100,
    'sale_price' => null,
    'cost_price' => 40,
    'size' => '',
    'color' => '',
    'gst_rate' => null,
    'hsn_code' => '',
    'shipping_weight_kg' => null,
    'parcel_length_cm' => null,
    'parcel_width_cm' => null,
    'parcel_height_cm' => null,
];
$variant = [
    'id' => 11,
    'fabric_id' => 1,
    'is_active' => 1,
    'stock' => 0,
    'stock_meters' => 10.00,
    'price_override' => null,
    'color' => 'Amber',
    'size' => '',
];
$snapshot = OrderItemSnapshotService::build(
    ['1::11' => 5.00],
    [],
    ['1::11' => 2.50],
    [1 => $product],
    [11 => $variant],
    18.0,
    '5208'
);
$assertQuantity($snapshot['items'][0]['quantity'] ?? 0, 5.00, 'Checkout snapshot must preserve total meter quantity.');
$assertQuantity($snapshot['items'][0]['meter_length'] ?? 0, 2.50, 'Checkout snapshot must preserve cut length.');
$assert(($snapshot['items'][0]['bundle_quantity'] ?? 0) === 2, 'Checkout snapshot must preserve the number of cuts.');

$legacyProduct = $product;
$legacyProduct['product_type'] = 'simple';
$legacyProduct['stock_meters'] = 10.00;
$legacyProduct['qty_step'] = 0.125;
$legacyProduct['meter_options'] = '1.25';
$fourDecimalAccepted = true;
try {
    OrderItemSnapshotService::build(
        ['1::0' => 1.25],
        [],
        ['1::0' => 1.25],
        [1 => $legacyProduct],
        [],
        18.0,
        '5208'
    );
} catch (RuntimeException $exception) {
    $fourDecimalAccepted = false;
}
$assert($fourDecimalAccepted, 'Checkout/order snapshot creation must accept a valid cent-storable quantity on a four-decimal step.');

$schema = $read('database/schema.sql');
$setup = $read('database/setup.php');
foreach ([$schema, $setup] as $storageSource) {
    $assert(str_contains($storageSource, 'stock_meters') && str_contains($storageSource, 'DECIMAL(10,2)'), 'Meter stock storage must remain two-decimal.');
    $assert(str_contains($storageSource, 'min_order_meters') && str_contains($storageSource, 'DECIMAL(10,2)'), 'Minimum meter storage must remain two-decimal.');
    $assert(str_contains($storageSource, 'quantity_meters') && str_contains($storageSource, 'DECIMAL(10,2)'), 'Cart/order meter quantities must remain two-decimal.');
}
$assert(str_contains($schema, 'qty_step         DECIMAL(10,4)'), 'The legacy qty_step storage definition must remain backward compatible.');

$adminService = $read('includes/services/ProductAdminService.php');
$adminEditor = $read('admin/edit-fabric.php');
$adminForm = $read('admin/partials/fabric-product-form.php');
$importService = $read('includes/services/ProductImportService.php');
$cartService = $read('includes/services/CartService.php');
$deliveryEndpoint = $read('delivery-estimate.php');
$inventoryService = $read('includes/services/InventoryService.php');
$assert(
    str_contains($adminService, 'meter_qty_step_is_representable')
        && str_contains($adminService, 'four decimal places'),
    'Admin/readiness validation must reject meter steps outside four-decimal precision.'
);
$assert(
    str_contains($adminEditor, "\$_POST['qty_step']")
        && str_contains($adminEditor, 'round((float) $qtyStepRaw, 4)')
        && str_contains($adminForm, 'name="qty_step"')
        && str_contains($adminForm, 'Meter steps support up to four decimal places'),
    'The product editor must submit, normalize, and explain the four-decimal meter-step boundary.'
);
$assert(
    !in_array('Quantity Step', ProductImportService::HEADERS, true)
        && str_contains($importService, "min_order_meters,qty_step,status,is_available) VALUES")
        && str_contains($importService, ",1,1,?,?)"),
    'Imports must keep their existing safe 1.00 step default and must not accept an unvalidated step column.'
);
$assert(
    str_contains($cartService, '$stepUnits = (int) round($quantityStep * 10000)')
        && str_contains($cartService, '$stockUnits = $stockCents * 100'),
    'Cart hydration/reconciliation must use scaled-integer arithmetic for four-decimal steps.'
);
$assert(str_contains($deliveryEndpoint, 'meter_qty_respects_step'), 'Delivery estimates must enforce the authoritative server-side step policy.');
$assert(
    substr_count($inventoryService, '$amount = round($qty, 2);') >= 2,
    'Product and variant inventory mutations must continue reserving two-decimal meter quantities.'
);

if ($failures !== []) {
    fwrite(STDERR, "Meter quantity precision contract failures:\n- " . implode("\n- ", $failures) . "\n");
    exit(1);
}

echo "meter_quantity_precision_contract_test: OK\n";
