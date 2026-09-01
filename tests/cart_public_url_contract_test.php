<?php
declare(strict_types=1);

$root = dirname(__DIR__);
require_once $root . '/includes/helpers/core.php';
require_once $root . '/includes/services/CartService.php';
require_once $root . '/includes/services/ProductAdminService.php';
require_once $root . '/includes/helpers/product-cards.php';

$failures = [];
$assert = static function (bool $condition, string $message) use (&$failures): void {
    if (!$condition) {
        $failures[] = $message;
    }
};

$urlCases = [
    ['product' => ['id' => 10, 'slug' => 'example-product'], 'variant_id' => 0, 'expected' => '/fabric/example-product', 'label' => 'slug product'],
    ['product' => ['id' => 10, 'slug' => 'example-product'], 'variant_id' => 123, 'expected' => '/fabric/example-product?variant=123', 'label' => 'slug variant'],
    ['product' => ['id' => 10, 'slug' => ''], 'variant_id' => 0, 'expected' => '/fabric.php?id=10', 'label' => 'legacy product'],
    ['product' => ['id' => 10, 'slug' => ''], 'variant_id' => 123, 'expected' => '/fabric.php?id=10&variant=123', 'label' => 'legacy variant'],
];

foreach ($urlCases as $case) {
    $actual = product_card_public_url($case['product'], $case['variant_id']);
    $assert($actual === $case['expected'], 'Cart URL is incorrect for ' . $case['label'] . '.');
}

$cartKey = '10::123';
$assert(
    CartService::cart_parse_key($cartKey) === [10, 123],
    'Cart identity must continue to derive from the existing product/variant cart key.'
);
foreach ($urlCases as $case) {
    product_card_public_url($case['product'], $case['variant_id']);
    $assert(
        CartService::cart_parse_key($cartKey) === [10, 123],
        'Slug availability must not affect cart-key identity.'
    );
}

$cartServiceSource = (string) file_get_contents($root . '/includes/services/CartService.php');
$cartSource = (string) file_get_contents($root . '/cart.php');
$assert(str_contains($cartServiceSource, 'f.slug'), 'Cart hydration must select the product slug in its existing bulk query.');
$assert(str_contains($cartServiceSource, "'slug' => (string) (\$row['slug'] ?? ''),"), 'Cart hydration must expose the selected slug on each read-model item.');
$assert(substr_count($cartSource, 'product_card_public_url(') >= 4, 'Cart and saved-for-later links must use the shared product URL helper.');
$assert(!str_contains($cartSource, '/fabric.php?id='), 'Cart presentation must not hard-code legacy product URLs.');
$assert(!str_contains($cartSource, '/catalog.php'), 'Cart continue-shopping links must use the canonical /catalog URL.');
$assert(substr_count($cartSource, 'href="/catalog"') === 3, 'All cart collection links must use the canonical /catalog URL.');

if ($failures !== []) {
    foreach ($failures as $failure) {
        fwrite(STDERR, 'FAIL: ' . $failure . PHP_EOL);
    }
    exit(1);
}

echo "cart_public_url_contract_test: OK\n";
