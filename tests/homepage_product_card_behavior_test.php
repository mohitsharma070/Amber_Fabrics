<?php
declare(strict_types=1);

$root = dirname(__DIR__);
require_once $root . '/includes/services/CartService.php';
require_once $root . '/includes/services/ProductAdminService.php';
require_once $root . '/includes/helpers/product-cards.php';

$failures = [];
$assert = static function (bool $condition, string $message) use (&$failures): void {
    if (!$condition) {
        $failures[] = $message;
    }
};
$assertSame = static function ($expected, $actual, string $message) use (&$failures): void {
    if ($actual !== $expected) {
        $failures[] = $message . ' Expected ' . var_export($expected, true) . ', got ' . var_export($actual, true) . '.';
    }
};

if (!function_exists('product_card_build_home_context')) {
    fwrite(STDERR, "FAIL: Homepage product-card behavior must have a dedicated testable state builder.\n");
    exit(1);
}

$baseProduct = [
    'id' => 10,
    'name' => 'Test Product',
    'unit_type' => 'piece',
    'price' => 100,
    'sale_price' => 0,
    'stock' => 5,
    'stock_meters' => 0,
    'is_available' => 1,
    'active_variant_count' => 0,
    'image' => 'parent.jpg',
    'size' => '',
];

$simplePiece = product_card_build_home_context($baseProduct, []);
$assertSame('piece', $simplePiece['unit_type'], 'Simple piece products must retain their selling unit.');
$assertSame(5.0, $simplePiece['stock'], 'Simple piece products must use parent piece stock.');
$assertSame(' each', $simplePiece['unit_suffix'], 'Simple piece prices must retain the each suffix.');
$assertSame('add_simple_ajax', $simplePiece['cta_mode'], 'Sellable simple piece products must retain AJAX Add to Cart.');

$simpleMeter = product_card_build_home_context(array_replace($baseProduct, [
    'unit_type' => 'meter',
    'stock' => 0,
    'stock_meters' => 7.5,
]), []);
$assertSame(7.5, $simpleMeter['stock'], 'Simple meter products must use parent meter stock.');
$assertSame('/m', $simpleMeter['unit_suffix'], 'Meter prices must retain the per-meter suffix.');
$assertSame('view_options', $simpleMeter['cta_mode'], 'Meter products must retain View Options.');

$variableProduct = array_replace($baseProduct, ['active_variant_count' => 2]);
$variableVariants = [
    ['stock' => 2, 'stock_meters' => 0, 'price_override' => 0, 'image' => 'first.jpg'],
    ['stock' => 3, 'stock_meters' => 0, 'price_override' => 0, 'image' => 'second.jpg'],
];
$variable = product_card_build_home_context($variableProduct, $variableVariants);
$assertSame(5.0, $variable['stock'], 'Variable product stock must remain the sum of active variant stock.');
$assertSame(true, $variable['has_sellable_variant'], 'A positive active variant must make the variable product sellable.');
$assertSame(true, $variable['needs_variant_selection'], 'More than one active variant must require a selection.');
$assertSame('view_options', $variable['cta_mode'], 'Multi-variant products must retain View Options.');

$variantOverride = product_card_build_home_context(
    array_replace($baseProduct, ['sale_price' => 90, 'active_variant_count' => 1]),
    [['stock' => 2, 'stock_meters' => 0, 'price_override' => 85, 'image' => 'variant.jpg']]
);
$assertSame(85.0, $variantOverride['unit_price'], 'The first in-stock representative variant override must win over parent pricing.');
$assertSame(true, $variantOverride['show_strike_price'], 'A representative override below regular price must retain strike-through pricing.');

$parentSale = product_card_build_home_context(array_replace($baseProduct, ['sale_price' => 80]), []);
$assertSame(80.0, $parentSale['unit_price'], 'A valid parent sale price must be used when no representative override exists.');
$assertSame(true, $parentSale['show_strike_price'], 'A valid parent sale must retain strike-through pricing.');

$inStockVariant = product_card_build_home_context(
    array_replace($baseProduct, ['active_variant_count' => 2, 'image' => '']),
    [
        ['stock' => 0, 'stock_meters' => 0, 'price_override' => 60, 'image' => 'sold-out.jpg'],
        ['stock' => 4, 'stock_meters' => 0, 'price_override' => 75, 'image' => '', 'image2' => 'sellable.jpg'],
    ]
);
$assertSame(true, $inStockVariant['in_stock'], 'Any positive active variant must keep the homepage product in stock.');
$assertSame(75.0, $inStockVariant['unit_price'], 'The first in-stock variant, not an earlier sold-out variant, must provide representative pricing.');
$assertSame('sellable.jpg', $inStockVariant['card_image'], 'An in-stock variant image must be preferred when parent media is missing.');

$outOfStockVariants = product_card_build_home_context(
    array_replace($baseProduct, ['active_variant_count' => 2, 'stock' => 99]),
    [
        ['stock' => 0, 'stock_meters' => 0, 'price_override' => 70, 'image' => 'one.jpg'],
        ['stock' => -2, 'stock_meters' => 0, 'price_override' => 65, 'image' => 'two.jpg'],
    ]
);
$assertSame(0.0, $outOfStockVariants['stock'], 'Negative variant stock must not reduce aggregate homepage stock below zero.');
$assertSame(false, $outOfStockVariants['in_stock'], 'Parent stock must not make a product sellable when all active variants are out of stock.');
$assertSame('unavailable', $outOfStockVariants['cta_mode'], 'Products with no sellable active variants must remain unavailable.');

$primaryMedia = product_card_build_home_context(
    array_replace($baseProduct, ['active_variant_count' => 1, 'image' => 'primary.jpg']),
    [['stock' => 2, 'stock_meters' => 0, 'price_override' => 0, 'image' => 'variant.jpg']]
);
$assertSame('primary.jpg', $primaryMedia['card_image'], 'Homepage parent primary media must remain preferred over variant media.');

$variantFallback = product_card_build_home_context(
    array_replace($baseProduct, ['active_variant_count' => 2, 'image' => '']),
    [
        ['stock' => 0, 'stock_meters' => 0, 'price_override' => 0, 'image' => 'first-active.jpg'],
        ['stock' => 0, 'stock_meters' => 0, 'price_override' => 0, 'image' => '', 'image3' => 'later-active.jpg'],
    ]
);
$assertSame('first-active.jpg', $variantFallback['card_image'], 'When no variant is sellable, homepage media must fall back to the first active variant image.');

$setProduct = product_card_build_home_context(array_replace($baseProduct, ['unit_type' => 'set']), []);
$assertSame(' each', $setProduct['unit_suffix'], 'Set prices must retain the same each suffix as piece prices.');

$sizeOptions = product_card_build_home_context(array_replace($baseProduct, ['size' => 'Single, Double']), []);
$assertSame(true, $sizeOptions['has_size_options'], 'Configured size options must remain detectable.');
$assertSame('view_options', $sizeOptions['cta_mode'], 'Simple products with size options must retain View Options.');

$singleVariant = product_card_build_home_context(
    array_replace($baseProduct, ['active_variant_count' => 1]),
    [['stock' => 3, 'stock_meters' => 0, 'price_override' => 0, 'image' => 'only.jpg']]
);
$assertSame(false, $singleVariant['needs_variant_selection'], 'One active variant must not trigger the homepage multi-variant selection rule.');
$assertSame('add_simple_ajax', $singleVariant['cta_mode'], 'One active variant without size or meter selection must retain homepage AJAX Add to Cart.');

$unavailableParent = product_card_build_home_context(array_replace($baseProduct, ['is_available' => 0]), []);
$assertSame(false, $unavailableParent['in_stock'], 'Product availability must remain part of the homepage sellability decision.');
$assertSame('unavailable', $unavailableParent['cta_mode'], 'An unavailable parent must not expose a purchase CTA.');

if ($failures !== []) {
    foreach ($failures as $failure) {
        fwrite(STDERR, 'FAIL: ' . $failure . PHP_EOL);
    }
    exit(1);
}

echo "homepage_product_card_behavior_test: OK\n";
