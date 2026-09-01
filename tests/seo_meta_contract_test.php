<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$failures = [];
$assert = static function (bool $condition, string $message) use (&$failures): void {
    if (!$condition) {
        $failures[] = $message;
    }
};
$read = static fn (string $path): string => (string) file_get_contents($root . '/' . $path);

$storefrontHeader = $read('includes/views/layouts/header.php');
$adminHeader = $read('admin/partials/header.php');
$productPage = $read('fabric.php');
$seoSuite = $read('plugins/seo-suite/plugin.php');

$assert(
    str_contains($storefrontHeader, "SiteContext::url('/images/fabrics/default.jpg')")
        && str_contains($storefrontHeader, "isset(\$metaImage) ? \$metaImage")
        && str_contains($storefrontHeader, 'property="og:image"'),
    'Storefront default og:image must be absolute while preserving page-provided overrides.'
);
$assert(
    str_contains($productPage, '$metaImage = !empty($galleryImages[0])')
        && str_contains($productPage, "SiteContext::url('/images/fabrics/'")
        && str_contains($productPage, '$metaUrl = SiteContext::url(ProductAdminService::publicPath($product));'),
    'Product metadata must continue to provide an absolute image and canonical product URL override.'
);
$assert(
    str_contains($adminHeader, "SiteContext::url('/images/fabrics/default.jpg')")
        && str_contains($adminHeader, "isset(\$metaImage) ? \$metaImage")
        && str_contains($adminHeader, 'property="og:image"'),
    'Admin default og:image must be absolute while preserving page-provided overrides.'
);
$assert(
    str_contains($seoSuite, 'function seo_suite_current_canonical_url()')
        && str_contains($seoSuite, 'return SiteContext::url($uriPath);')
        && !str_contains($storefrontHeader, 'seo_suite_current_canonical_url('),
    'Canonical current-page ownership must remain with seo-suite without duplicate header logic.'
);

if ($failures !== []) {
    fwrite(STDERR, implode(PHP_EOL, $failures) . PHP_EOL);
    exit(1);
}

echo "seo_meta_contract_test: OK\n";
