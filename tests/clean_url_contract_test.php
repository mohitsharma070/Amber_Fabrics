<?php

$failures = [];
$root = dirname(__DIR__);
$assert = static function (bool $condition, string $message) use (&$failures): void {
    if (!$condition) {
        $failures[] = $message;
    }
};

require_once $root . '/includes/services/ProductAdminService.php';
require_once $root . '/includes/helpers/product-cards.php';

$productCardUrlCases = [
    ['product' => ['id' => 10, 'slug' => 'example-product'], 'variant_id' => 0, 'expected' => '/fabric/example-product', 'label' => 'slug product without a variant'],
    ['product' => ['id' => 10, 'slug' => 'example-product'], 'variant_id' => 123, 'expected' => '/fabric/example-product?variant=123', 'label' => 'slug product with a variant'],
    ['product' => ['id' => 10, 'slug' => ''], 'variant_id' => 0, 'expected' => '/fabric.php?id=10', 'label' => 'legacy product without a variant'],
    ['product' => ['id' => 10, 'slug' => ''], 'variant_id' => 123, 'expected' => '/fabric.php?id=10&variant=123', 'label' => 'legacy product with a variant'],
];

if (!function_exists('product_card_public_url')) {
    $failures[] = 'Shared product-card URL composer is missing.';
} else {
    foreach ($productCardUrlCases as $case) {
        $actual = product_card_public_url($case['product'], $case['variant_id']);
        $assert($actual === $case['expected'], 'Shared product-card URL is incorrect for ' . $case['label'] . '.');
        $assert(str_starts_with($actual, '/'), 'Shared product-card URL must be root-relative for ' . $case['label'] . '.');
        $assert(!str_contains($actual, 'example-product&variant='), 'Slug product variants must not use an ampersand without a query string.');
    }
}

$htaccess = (string) file_get_contents(__DIR__ . '/../.htaccess');
$router = (string) file_get_contents(__DIR__ . '/../router.php');

foreach ([
    'RewriteRule ^admin/([A-Za-z0-9-]+)\\.php$ /admin/$1 [R=301,L,NE]' => 'Admin GET canonical redirect is missing.',
    'RewriteRule ^admin/([A-Za-z0-9-]+)$ admin/$1.php [L,QSA]' => 'Admin clean-route rewrite is missing.',
    'RewriteRule ^fabric/([^/]+)$ fabric.php?slug=$1 [L,QSA]' => 'Product slug route is missing.',
] as $rule => $message) {
    if (!str_contains($htaccess, $rule)) {
        $failures[] = $message;
    }
}

if (str_contains($htaccess, '[R=307,L,NE]')) {
    $failures[] = 'Admin POST requests must not be redirected.';
}

if (!str_contains($router, "preg_match('#^admin/([A-Za-z0-9-]+)$#'")) {
    $failures[] = 'Local router does not support clean admin routes.';
}
if (!str_contains($router, "preg_match('#^fabric/([^/]+)$#'")) {
    $failures[] = 'Local router does not support product slugs.';
}
if (!str_contains($router, "\$isReadRequest && preg_match('#^admin/([A-Za-z0-9-]+)\\.php$#'")
    || str_contains($router, 'http_response_code($isReadRequest ? 301 : 307)')) {
    $failures[] = 'Local router must redirect only read requests for legacy admin PHP URLs.';
}

$couponPage = (string) file_get_contents(__DIR__ . '/../admin/coupons.php');
if (substr_count($couponPage, 'action="/admin/coupons"') !== 4
    || str_contains($couponPage, 'action="coupons.php"')) {
    $failures[] = 'Coupon forms must submit directly to the canonical admin route.';
}

if ($failures !== []) {
    fwrite(STDERR, implode(PHP_EOL, $failures) . PHP_EOL);
    exit(1);
}

echo 'Clean URL contract passed.' . PHP_EOL;
