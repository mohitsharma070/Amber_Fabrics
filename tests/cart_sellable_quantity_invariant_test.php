<?php
declare(strict_types=1);

$root = dirname(__DIR__);
require_once $root . '/includes/helpers/core.php';
require_once $root . '/includes/services/CartService.php';

$failures = [];
$assertQuantity = static function ($actual, float $expected, string $message) use (&$failures): void {
    if (abs((float) $actual - $expected) > 0.0001) {
        $failures[] = $message . ' Expected ' . $expected . ', got ' . (float) $actual . '.';
    }
};

if (!method_exists(CartService::class, 'maximumSellableQuantity')) {
    fwrite(STDERR, "FAIL: CartService::maximumSellableQuantity policy is missing.\n");
    exit(1);
}
if (!method_exists(CartService::class, 'reconcileSellableQuantity')) {
    fwrite(STDERR, "FAIL: CartService::reconcileSellableQuantity policy is missing.\n");
    exit(1);
}

$cases = [
    ['piece minimum exceeds stock', 1.0, 'piece', 2.0, 0.0, null, 0.0],
    ['piece stock exactly meets minimum', 2.0, 'piece', 2.0, 0.0, null, 2.0],
    ['piece ceiling never exceeds stock', 5.0, 'piece', 2.0, 0.0, null, 5.0],
    ['set ceiling stays in whole sellable units', 5.75, 'set', 2.0, 3.0, null, 5.0],
    ['meter minimum exceeds stock', 0.75, 'meter', 1.0, 0.0, null, 0.0],
    ['meter stock exactly meets minimum', 1.0, 'meter', 1.0, 0.0, null, 1.0],
    ['meter zero step allows cent precision', 1.37, 'meter', 1.0, 0.0, null, 1.37],
    ['meter ceiling respects configured step', 1.60, 'meter', 1.0, 0.25, null, 1.50],
    ['meter ceiling respects bundle length', 5.0, 'meter', 1.0, 0.50, 2.0, 4.0],
    ['meter bundle unavailable below one bundle', 1.50, 'meter', 1.0, 0.50, 2.0, 0.0],
    ['meter ceiling finds lower step-aligned bundle', 4.90, 'meter', 1.0, 0.50, 1.25, 2.50],
];

foreach ($cases as [$label, $stock, $unitType, $minimum, $step, $meterLength, $expected]) {
    $actual = CartService::maximumSellableQuantity($stock, $unitType, $minimum, $step, $meterLength);
    $assertQuantity($actual, $expected, $label . '.');
    if ((float) $actual > $stock + 0.0001) {
        $failures[] = $label . ' returned a quantity above real stock.';
    }
    if ((float) $actual > 0 && (float) $actual + 0.0001 < $minimum) {
        $failures[] = $label . ' returned a positive quantity below the minimum.';
    }
}

$reconciliationCases = [
    ['stale piece quantity is capped', 10.0, 5.0, 'piece', 2.0, 0.0, null, 5.0],
    ['piece request below minimum is normalized', 1.0, 5.0, 'piece', 2.0, 0.0, null, 2.0],
    ['piece line is removed below minimum stock', 10.0, 1.0, 'piece', 2.0, 0.0, null, 0.0],
    ['merged meter quantity is reconciled to step', 2.0, 10.0, 'meter', 1.0, 0.30, null, 1.90],
    ['stale meter quantity is capped to bundle grid', 10.0, 5.0, 'meter', 1.0, 0.50, 2.0, 4.0],
    ['meter line is removed below one bundle', 2.0, 1.50, 'meter', 1.0, 0.50, 2.0, 0.0],
];
foreach ($reconciliationCases as [$label, $requested, $stock, $unitType, $minimum, $step, $meterLength, $expected]) {
    $actual = CartService::reconcileSellableQuantity(
        $requested,
        $stock,
        $unitType,
        $minimum,
        $step,
        $meterLength
    );
    $assertQuantity($actual, $expected, $label . '.');
}

$read = static fn(string $path): string => (string) file_get_contents($root . '/' . $path);
$addEndpoint = $read('add-to-cart.php');
$updateEndpoint = $read('update-cart.php');
$cartServiceSource = $read('includes/services/CartService.php');
$mergeServiceSource = $read('includes/services/CustomerSessionMergeService.php');
$moveEndpoint = $read('move-to-cart.php');
$cartPage = $read('cart.php');
$checkoutPage = $read('checkout.php');
$couponEndpoint = $read('apply-coupon.php');
$placeOrderEndpoint = $read('place-order.php');
$shippingEndpoint = $read('shipping-rate.php');
$assertQuantity(str_contains($addEndpoint, 'CartService::maximumSellableQuantity') ? 1 : 0, 1, 'Add endpoint must use the sellable ceiling.');
$assertQuantity(str_contains($updateEndpoint, 'CartService::maximumSellableQuantity') ? 1 : 0, 1, 'Update endpoint must use the sellable ceiling.');
$assertQuantity(str_contains($cartServiceSource, 'CartService::reconcileSellableQuantity') ? 1 : 0, 1, 'Hydration must reconcile stored cart quantities.');
$assertQuantity(str_contains($mergeServiceSource, 'CartService::reconcileSellableQuantity') ? 1 : 0, 1, 'Login merge must reconcile merged quantities to the sellable grid.');
$assertQuantity(str_contains($moveEndpoint, "maximum_sellable_quantity") ? 1 : 0, 1, 'Wishlist-to-cart must enforce the hydrated sellable ceiling.');
$assertQuantity(str_contains($cartPage, "quantity_updates") ? 1 : 0, 1, 'Cart page must persist hydrated quantity updates.');
$assertQuantity(str_contains($checkoutPage, "quantity_updates") ? 1 : 0, 1, 'Checkout must persist hydrated quantity updates.');
$assertQuantity(str_contains($couponEndpoint, "quantity_updates") ? 1 : 0, 1, 'Coupon application must persist hydrated quantity updates.');
$assertQuantity(str_contains($placeOrderEndpoint, "quantity_updates") ? 1 : 0, 1, 'Order placement must persist hydrated quantity updates before continuing.');
$assertQuantity(
    str_contains($shippingEndpoint, "quantity_updates")
        && str_contains($shippingEndpoint, "removed_keys")
        && str_contains($shippingEndpoint, "'code' => 'cart_changed'")
        && str_contains($shippingEndpoint, '], 409);'),
    1,
    'Shipping quote must reject a changed cart before pricing it.'
);
$assertQuantity(str_contains($addEndpoint, 'normalize_quantity_by_unit($stock') ? 1 : 0, 0, 'Add endpoint must not normalize real stock with a minimum.');
$assertQuantity(str_contains($updateEndpoint, 'normalize_quantity_by_unit($stock') ? 1 : 0, 0, 'Update endpoint must not normalize real stock with a minimum.');

if ($failures !== []) {
    foreach ($failures as $failure) {
        fwrite(STDERR, "FAIL: {$failure}\n");
    }
    exit(1);
}

echo "cart_sellable_quantity_invariant_test: OK\n";
