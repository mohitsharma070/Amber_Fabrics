<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$failures = [];

$assert = static function (bool $condition, string $message) use (&$failures): void {
    if (!$condition) {
        $failures[] = $message;
    }
};

$read = static function (string $relative) use ($root, $assert): string {
    $path = $root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);
    $assert(is_file($path), $relative . ' must remain present.');
    if (!is_file($path)) {
        return '';
    }
    $contents = file_get_contents($path);
    $assert($contents !== false && trim((string) $contents) !== '', $relative . ' must not be empty.');
    return $contents === false ? '' : $contents;
};

$deadFunctionsByFile = [
    'catalog.php' => ['catalog_fulltext_available', 'catalog_build_boolean_search'],
    'config/db.php' => ['db_connected'],
    'includes/coupon-functions.php' => ['mark_coupon_used_once', 'validate_coupon_for_subtotal'],
    'includes/helpers/media.php' => ['image_pipeline_low_resolution_fabric_images'],
    'includes/helpers/persistence.php' => ['get_variant_size_policy_by_category'],
    'includes/helpers/site-settings.php' => [
        'site_settings_defaults',
        'ensure_site_settings_table',
        'load_site_settings_from_db',
        'is_storefront_category_slug',
        'get_site_settings',
    ],
    'plugins/meta-pixel/plugin.php' => ['meta_pixel_track_script'],
    'plugins/shipping-courier/modules/reference-and-rates.php' => ['shipping_courier_http_post_multipart'],
    'plugins/recommendations/plugin.php' => ['recommendations_render_product_grid'],
];

foreach ($deadFunctionsByFile as $relative => $functionNames) {
    $source = $read($relative);
    foreach ($functionNames as $functionName) {
        $assert(
            !str_contains($source, 'function ' . $functionName . '('),
            $relative . ' must not restore unused function ' . $functionName . '.'
        );
    }
}

$couponFunctions = $read('includes/coupon-functions.php');
$applyCoupon = $read('apply-coupon.php');
$removeCoupon = $read('remove-coupon.php');
$assert(substr_count($couponFunctions, 'function coupon_redirect_target(') === 1, 'Coupon redirects must have one shared implementation.');
$assert(str_contains($applyCoupon, "redirect(coupon_redirect_target('/cart.php'))"), 'Coupon application must use the shared redirect helper.');
$assert(str_contains($removeCoupon, "redirect(coupon_redirect_target('/cart.php'))"), 'Coupon removal must use the shared redirect helper.');
$assert(!str_contains($removeCoupon, 'coupon_remove_redirect_target'), 'The duplicate coupon removal redirect helper must stay removed.');

foreach (['login.php', 'admin/index.php', 'feeds/products.xml', 'feeds/products.json'] as $relative) {
    $read($relative);
}
foreach (['logo.svg', 'logo-white.svg', 'logo-icon.svg', 'logo-brand.svg'] as $filename) {
    $read('images/' . $filename);
}

if ($failures) {
    foreach ($failures as $failure) {
        fwrite(STDERR, "FAIL: {$failure}\n");
    }
    exit(1);
}

echo "repository_cleanup_contract_test: OK\n";
