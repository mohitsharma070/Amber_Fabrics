<?php
declare(strict_types=1);

$root = dirname(__DIR__);
require_once $root . '/includes/services/CartService.php';

$failures = [];
$assert = static function (bool $condition, string $message) use (&$failures): void {
    if (!$condition) {
        $failures[] = $message;
    }
};

$cart = ['41::0' => 2, '42::501' => 1, '43::XL' => 3, 44 => 1];

$assert(
    CartService::resolveExistingUpdateIdentity($cart, '999::0') === null,
    'A nonexistent cart key must be rejected instead of becoming a new cart line.'
);
$assert(
    CartService::resolveExistingUpdateIdentity($cart, '41::0') === ['cart_key' => '41::0', 'product_id' => 41, 'variant_id' => 0],
    'An existing canonical cart key must resolve for update.'
);
$assert(
    CartService::resolveExistingUpdateIdentity($cart, '43::XL') === ['cart_key' => '43::XL', 'product_id' => 43, 'variant_id' => 0],
    'An exact existing legacy size key must remain updateable as a simple-product line.'
);
$assert(
    CartService::resolveExistingUpdateIdentity($cart, '44') === ['cart_key' => '44', 'product_id' => 44, 'variant_id' => 0],
    'An exact existing bare legacy product key must remain updateable.'
);
$assert(
    CartService::resolveExistingUpdateIdentity($cart, '', '41') === ['cart_key' => '41::0', 'product_id' => 41, 'variant_id' => 0],
    'The legacy product-ID request must resolve only when its canonical line already exists.'
);
$assert(
    CartService::resolveExistingUpdateIdentity($cart, '', '42', '501') === ['cart_key' => '42::501', 'product_id' => 42, 'variant_id' => 501],
    'The product/variant fallback must resolve only the matching existing canonical line.'
);
$assert(
    CartService::resolveExistingUpdateIdentity($cart, '', '999') === null,
    'The product-ID fallback must not create a missing cart line.'
);
$assert(
    CartService::resolveExistingUpdateIdentity($cart, '41::0', '42') === null,
    'A posted product ID that disagrees with the cart key must be rejected.'
);
$assert(
    CartService::resolveExistingUpdateIdentity($cart, '42::501', '42', '502') === null,
    'A posted variant ID that disagrees with the cart key must be rejected.'
);
$assert(
    CartService::resolveExistingUpdateIdentity($cart, '41::not-a-variant') === null,
    'A malformed cart key must not be normalized into a different identity.'
);
$assert(
    CartService::resolveExistingUpdateIdentity($cart + ['41::00' => 1], '41::00') === null,
    'A noncanonical numeric suffix must remain rejected even when present in session state.'
);

$variableProduct = ['id' => 42, 'product_type' => 'variable', 'status' => 'active', 'is_available' => 1];
$simpleProduct = ['id' => 41, 'product_type' => 'simple', 'status' => 'active', 'is_available' => 1];
$validVariant = ['id' => 501, 'fabric_id' => 42, 'is_active' => 1];

$assert(
    !CartService::isValidUpdateSelection(42, 0, $variableProduct, null),
    'A variable product without a variant must be rejected.'
);
$assert(
    !CartService::isValidUpdateSelection(42, 501, $variableProduct, ['id' => 501, 'fabric_id' => 77, 'is_active' => 1]),
    'A variant belonging to another product must be rejected.'
);
$assert(
    !CartService::isValidUpdateSelection(42, 501, $variableProduct, ['id' => 501, 'fabric_id' => 42, 'is_active' => 0]),
    'An inactive variant must be rejected.'
);
$assert(
    !CartService::isValidUpdateSelection(41, 501, $simpleProduct, ['id' => 501, 'fabric_id' => 41, 'is_active' => 1]),
    'A simple product with an arbitrary variant must be rejected.'
);
$assert(
    CartService::isValidUpdateSelection(42, 501, $variableProduct, $validVariant),
    'A valid active variant owned by a variable product must remain updateable.'
);
$assert(
    CartService::isValidUpdateSelection(41, 0, $simpleProduct, null),
    'A valid active simple product must remain updateable.'
);
$assert(
    !CartService::isValidUpdateSelection(41, 0, ['id' => 41, 'product_type' => 'simple', 'status' => 'inactive', 'is_available' => 1], null),
    'An inactive product must be rejected.'
);
$assert(
    !CartService::isValidUpdateSelection(41, 0, ['id' => 41, 'product_type' => 'simple', 'status' => 'active', 'is_available' => 0], null),
    'A product that is no longer sellable must be rejected.'
);

$updateEndpoint = (string) file_get_contents($root . '/update-cart.php');
$openapi = (string) file_get_contents($root . '/openapi.yaml');
$assert(
    str_contains($updateEndpoint, 'CartService::resolveExistingUpdateIdentity'),
    'The update endpoint must resolve only an existing cart identity.'
);
$assert(
    str_contains($updateEndpoint, 'CartService::isValidUpdateSelection'),
    'The update endpoint must enforce product and variant mode validity.'
);
$updateContractStart = strpos($openapi, '  /update-cart.php:');
$updateContractEnd = strpos($openapi, '  /remove-cart.php:', $updateContractStart === false ? 0 : $updateContractStart);
$updateContract = ($updateContractStart !== false && $updateContractEnd !== false)
    ? substr($openapi, $updateContractStart, $updateContractEnd - $updateContractStart)
    : '';
$assert(
    str_contains($updateContract, 'anyOf:')
        && str_contains($updateContract, 'required: [cart_key]')
        && str_contains($updateContract, 'required: [product_id]'),
    'OpenAPI must document the existing-line cart-key or product-ID identity contract.'
);

if ($failures !== []) {
    foreach ($failures as $failure) {
        fwrite(STDERR, "FAIL: {$failure}\n");
    }
    exit(1);
}

echo "update_cart_identity_invariant_test: OK\n";
