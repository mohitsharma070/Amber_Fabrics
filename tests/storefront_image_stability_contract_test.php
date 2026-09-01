<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$failures = [];
$assert = static function (bool $condition, string $message) use (&$failures): void {
    if (!$condition) {
        $failures[] = $message;
    }
};
$read = static function (string $path) use ($root, $assert): string {
    $source = @file_get_contents($root . '/' . $path);
    $assert($source !== false, $path . ' must exist.');
    return $source === false ? '' : $source;
};

$media = $read('includes/helpers/media.php');
$home = $read('index.php');
$cards = $read('includes/helpers/product-cards.php');
$product = $read('fabric.php');
$cart = $read('cart.php');
$style = $read('css/style.css');

$assert(
    str_contains($media, 'function image_pipeline_asset_dimensions(')
        && str_contains($media, '@getimagesize($absolutePath)')
        && str_contains($media, "'width' =>")
        && str_contains($media, "'thumb_width' =>"),
    'First-party image assets must expose exact source and thumbnail dimensions only when the local file can be inspected.'
);
$assert(
    str_contains($media, 'function image_asset_dimension_attributes(')
        && str_contains($media, "'thumb_width'")
        && str_contains($media, "'thumb_height'")
        && !str_contains($media, 'width="600" height="800"'),
    'Image dimension attributes must be derived from inspected metadata rather than an arbitrary product ratio.'
);

$assert(
    str_contains($home, "image_asset_dimension_attributes(\$cardImageAsset, 'thumb')")
        && str_contains($home, 'webp_srcset')
        && str_contains($home, 'loading="lazy"'),
    'Latest Drops must reserve intrinsic thumbnail geometry while retaining responsive WebP and lazy loading.'
);
$assert(
    str_contains($cards, "image_asset_dimension_attributes(\$cardImageAsset)")
        && str_contains($cards, 'webp_srcset')
        && str_contains($cards, 'loading="lazy"'),
    'Shared catalog product cards must reserve intrinsic source geometry without losing responsive media behavior.'
);
$assert(
    str_contains($product, 'image_asset_dimension_attributes($mainImageAsset)')
        && str_contains($product, "image_asset_dimension_attributes(\$thumbAsset, 'thumb')")
        && str_contains($product, 'webp_srcset'),
    'The product gallery must retain stable main and thumbnail image geometry across source switching.'
);
$assert(
    str_contains($cart, "image_asset_dimension_attributes(\$cartImageAsset, 'thumb')")
        && str_contains($cart, 'loading="lazy"'),
    'Cart line thumbnails must reserve their inspected thumbnail geometry.'
);
$assert(
    str_contains($style, '.category-card-img')
        && str_contains($style, 'aspect-ratio: 4 / 3;')
        && str_contains($style, '.fabric-thumb')
        && str_contains($style, 'aspect-ratio: 3 / 4;')
        && str_contains($style, '.product-media-main')
        && str_contains($style, 'height: clamp(420px, 42vw, 540px);')
        && str_contains($style, '.product-media-thumb')
        && str_contains($style, '.cart-line-item .cart-item-img'),
    'Category, product-card, gallery, thumbnail, and cart containers must keep their existing stable responsive geometry.'
);

if ($failures !== []) {
    foreach ($failures as $failure) {
        fwrite(STDERR, 'FAIL: ' . $failure . PHP_EOL);
    }
    exit(1);
}

echo "storefront_image_stability_contract_test: OK\n";
