<?php
require_once __DIR__ . '/includes/init.php';

$id = (int) ($_GET['id'] ?? 0);
$slug = trim((string) ($_GET['slug'] ?? ''));
if ($id <= 0 && $slug === '') {
    redirect('catalog.php');
}

$product = ProductReadService::activeByReference($conn, $id, $slug);

if (!$product) {
    header('HTTP/1.1 404 Not Found');
    $metaTitle = SiteContext::title('Product Not Found');
    include 'includes/header.php';
    echo '<div class="l-container u-py-5 u-text-center"><a href="/catalog.php">&larr; Back to Shop</a></div>';
    include 'includes/footer.php';
    exit;
}

if ($slug === '' && trim((string)($product['slug'] ?? '')) !== '') {
    $target = ProductAdminService::publicPath($product);
    if ((int)($_GET['variant'] ?? 0) > 0) $target .= '?variant=' . (int)$_GET['variant'];
    header('Location: ' . $target, true, 301);
    exit;
}

$regularPrice = (float) ($product['price'] ?? 0);
$salePrice = (float) ($product['sale_price'] ?? 0);
$effectiveBasePrice = 0.0;
if ($salePrice > 0 && ($regularPrice <= 0 || $salePrice < $regularPrice)) {
    $effectiveBasePrice = $salePrice;
} elseif ($regularPrice > 0) {
    $effectiveBasePrice = $regularPrice;
}
$unitType = in_array((string) ($product['unit_type'] ?? ''), ['meter', 'piece', 'set'], true)
    ? (string) $product['unit_type']
    : 'meter';
$isWholeUnit = $unitType === 'piece' || $unitType === 'set';
// Quantity controls from admin settings.
$qtyStepDb = (float) ($product['qty_step'] ?? 0);
if ($isWholeUnit) {
    $minOrderQty = (float) max(1, (int) round((float) ($product['min_order_meters'] ?? 1)));
    $qtyStepUi = $qtyStepDb > 0 ? (float) max(1, (int) round($qtyStepDb)) : 1.0;
} else {
    $minOrderQty = normalize_meter_quantity($product['min_order_meters'] ?? 1, 1.0);
    $qtyStepUi = $qtyStepDb > 0 ? round($qtyStepDb, 4) : 0.01;
}
if ($qtyStepUi > 0) {
    $unitStep = rtrim(rtrim(number_format($qtyStepUi, 4), '0'), '.');
} else {
    $unitStep = $isWholeUnit ? '1' : '0.01';
}
$unitLabel = $unitType === 'piece' ? 'pieces' : ($unitType === 'set' ? 'sets' : 'meters');
$unitSingleLabel = $unitType === 'piece' ? 'piece' : ($unitType === 'set' ? 'set' : 'meter');
$displayStock = $isWholeUnit ? (float) ($product['stock'] ?? 0) : (float) ($product['stock_meters'] ?? 0);
$inStock = !empty($product['is_available']) && $displayStock > 0;
$galleryImages = [];
$videoFile = '';
try {
    $managedMedia = ProductAdminService::media($conn, (int)$product['id']);
    $managedImages = [];
    foreach ($managedMedia as $mediaItem) {
        if (($mediaItem['media_type'] ?? '') === 'image') $managedImages[] = (string)$mediaItem['filename'];
        if (($mediaItem['media_type'] ?? '') === 'video' && $videoFile === '') $videoFile = (string)$mediaItem['filename'];
    }
    $galleryImages = $managedImages;
} catch (Throwable $ignored) {
    // The storefront renders its normal empty-media state.
}
$catalogData = ProductAdminService::catalogData($product);
// --- Variant-level data ---
$variants = (($product['product_type'] ?? 'simple') === 'variable')
    ? InventoryService::get_fabric_variants($conn, (int) $product['id'])
    : [];
$firstVariantWithMedia = null;
foreach ($variants as $vv) {
    if ((int) ($vv['is_active'] ?? 0) !== 1) {
        continue;
    }
    $hasAnyMedia = false;
    foreach (['image', 'image2', 'image3', 'image4', 'video'] as $mk) {
        if (trim((string) ($vv[$mk] ?? '')) !== '') {
            $hasAnyMedia = true;
            break;
        }
    }
    if ($hasAnyMedia) {
        $firstVariantWithMedia = $vv;
        break;
    }
}
if (empty($galleryImages) && is_array($firstVariantWithMedia)) {
    $galleryImages = array_values(array_filter([
        (string) ($firstVariantWithMedia['image'] ?? ''),
        (string) ($firstVariantWithMedia['image2'] ?? ''),
        (string) ($firstVariantWithMedia['image3'] ?? ''),
        (string) ($firstVariantWithMedia['image4'] ?? ''),
    ]));
    if ($videoFile === '') {
        $videoFile = (string) ($firstVariantWithMedia['video'] ?? '');
    }
}
$variantSizePolicy = get_variant_size_policy_by_unit_type($unitType);
$variantSizeMode = (string) ($variantSizePolicy['mode'] ?? 'preset_with_custom');
$hideVariantSize = ($variantSizeMode === 'hidden');
$requestedVariantId = (int) ($_GET['variant'] ?? 0);
$requestedVariant = null;
if ($requestedVariantId > 0) {
    foreach ($variants as $candidateRequestedVariant) {
        if ((int) ($candidateRequestedVariant['id'] ?? 0) === $requestedVariantId && (int) ($candidateRequestedVariant['is_active'] ?? 0) === 1) {
            $requestedVariant = $candidateRequestedVariant;
            break;
        }
    }
}
$colorGroups = []; // color => [variant, ...]
$isPackLikeSize = static function (string $size): bool {
    return preg_match('/^pack\s+of\s+\d+$/i', trim($size)) === 1;
};
$isPlaceholderColor = static function (string $color): bool {
    $normalized = strtolower(trim($color));
    return $normalized === '' || $normalized === 'default';
};
foreach ($variants as $v) {
    if (!(int)$v['is_active']) continue;
    $colorGroups[$v['color']][] = $v;
}
$hasRealColors = false;
foreach (array_keys($colorGroups) as $colorName) {
    if (!$isPlaceholderColor((string) $colorName)) {
        $hasRealColors = true;
        break;
    }
}
if ($hasRealColors) {
    foreach (array_keys($colorGroups) as $colorName) {
        if ($isPlaceholderColor((string) $colorName)) {
            unset($colorGroups[$colorName]);
        }
    }
}
$colorsForPicker = array_keys($colorGroups);
$showColorPicker = false;
if (!empty($colorsForPicker)) {
    if (count($colorsForPicker) > 1) {
        $showColorPicker = true;
    } else {
        $showColorPicker = !$isPlaceholderColor((string) ($colorsForPicker[0] ?? ''));
    }
}
$defaultColor = array_key_first($colorGroups) ?? '';
if ($requestedVariant !== null) {
    $requestedColor = (string) ($requestedVariant['color'] ?? '');
    if (array_key_exists($requestedColor, $colorGroups)) {
        $defaultColor = $requestedColor;
    }
}
$defaultVariant = null;
if ($requestedVariant !== null && (string) ($requestedVariant['color'] ?? '') === (string) $defaultColor) {
    $defaultVariant = $requestedVariant;
}
if (!empty($colorGroups[$defaultColor])) {
    if ($defaultVariant === null) {
        foreach ($colorGroups[$defaultColor] as $candidateVariant) {
            $candidateStock = $isWholeUnit
                ? (float) ($candidateVariant['stock'] ?? 0)
                : (float) ($candidateVariant['stock_meters'] ?? 0);
            if ($candidateStock > 0) {
                $defaultVariant = $candidateVariant;
                break;
            }
        }
    }
    if ($defaultVariant === null) {
        $defaultVariant = $colorGroups[$defaultColor][0];
    }
}
$defaultVariantId = $defaultVariant ? (int)$defaultVariant['id'] : 0;
$defaultSize = '';
if ($defaultVariant) {
    $defaultSizeRaw = trim((string) ($defaultVariant['size'] ?? ''));
    if (!($unitType === 'set' && $isPackLikeSize($defaultSizeRaw))) {
        $defaultSize = $defaultSizeRaw;
    }
}
if ($hideVariantSize) {
    $defaultSize = '';
}

$defaultPackLabel = '';
$defaultUnitsPerSet = 0;
if ($unitType === 'set' && $defaultVariant) {
    $defaultPackLabel = trim((string) ($defaultVariant['pack_label'] ?? ''));
    $defaultUnitsPerSet = (int) ($defaultVariant['units_per_set'] ?? 0);
    if ($defaultPackLabel === '' && $defaultUnitsPerSet > 0) {
        $defaultPackLabel = format_pack_label($defaultUnitsPerSet);
    }
}

if (!empty($variants)) {
    $variantStockTotal = 0.0;
    $hasSellableVariant = false;
    foreach ($variants as $variantRow) {
        if ((int) ($variantRow['is_active'] ?? 0) !== 1) {
            continue;
        }
        $variantStock = $isWholeUnit
            ? (float) ($variantRow['stock'] ?? 0)
            : (float) ($variantRow['stock_meters'] ?? 0);
        $variantStockTotal += max(0.0, $variantStock);
        if ($variantStock > 0) {
            $hasSellableVariant = true;
        }
    }
    $displayStock = $variantStockTotal;
    $inStock = !empty($product['is_available']) && $hasSellableVariant;
}

// Legacy fallback: build sizeOptions from fabric.size if no DB variants
$sizeOptions = [];
if (!$hideVariantSize && empty($colorGroups) && !empty($product['size'])) {
    $parts = preg_split('/[,\|\/]+/', (string) $product['size']);
    if (is_array($parts)) {
        foreach ($parts as $part) {
            $clean = trim((string) $part);
            if ($clean !== '') {
                $sizeOptions[] = $clean;
            }
        }
    }
    $sizeOptions = array_values(array_unique($sizeOptions));
    $defaultSize = !empty($sizeOptions) ? (string) $sizeOptions[0] : '';
}

$meterOptions = [];
if ($unitType === 'meter' && !empty($product['meter_options'])) {
    $parts = preg_split('/[,\|]+/', (string) $product['meter_options']);
    if (is_array($parts)) {
        foreach ($parts as $part) {
            $val = trim((string) $part);
            if ($val !== '' && is_numeric($val) && (float) $val > 0) {
                $meterOptions[] = (float) $val;
            }
        }
    }
    $meterOptions = array_values(array_unique($meterOptions));
    sort($meterOptions);
    $meterOptions = array_values(array_filter($meterOptions, static function ($m) use ($minOrderQty) {
        return (float) $m >= (float) $minOrderQty;
    }));
}

$quantityOptions = [];
if ($unitType === 'meter') {
    // Meter quick options are fully controlled by admin via meter_options.
    $quantityOptions = $meterOptions;
    if (empty($quantityOptions)) {
        // Safe fallback when admin has not set options yet.
        $quantityOptions = [max(1.0, (float) $minOrderQty)];
    }
    if ($displayStock > 0) {
        $quantityOptions = array_values(array_filter($quantityOptions, static function ($q) use ($displayStock) {
            return (float) $q <= (float) $displayStock;
        }));
        if (empty($quantityOptions)) {
            $quantityOptions = [(float) $displayStock];
        }
    }
} else {
    $qtyStart = $minOrderQty;
    $qtyStep = $qtyStepUi;
    $qtyLimit = $displayStock > 0 ? min($displayStock, 20.0) : 20.0;
    if ($qtyLimit < $qtyStart) {
        $qtyLimit = $qtyStart;
    }
    for ($q = $qtyStart; $q <= $qtyLimit + 0.0001; $q += $qtyStep) {
        $normalized = $isWholeUnit ? (float) round($q) : (float) round($q, 2);
        if ($normalized > 0) {
            $quantityOptions[] = $normalized;
        }
    }
}
$quantityOptions = array_values(array_unique($quantityOptions));
sort($quantityOptions);

$metaTitle = (string) $product['name'] . ' | ' . SiteContext::name();
$metaDescriptionRaw = (string) ($product['description'] ?? '');
if ($metaDescriptionRaw !== '') {
    $metaDescriptionTrimmed = function_exists('mb_strimwidth')
        ? mb_strimwidth($metaDescriptionRaw, 0, 155, '...')
        : (strlen($metaDescriptionRaw) > 155 ? substr($metaDescriptionRaw, 0, 155) . '...' : $metaDescriptionRaw);
    $metaDescription = $metaDescriptionTrimmed;
} else {
    $metaDescription = 'Product details from ' . SiteContext::name() . '.';
}
$metaImage = !empty($galleryImages[0])
    ? SiteContext::url('/images/fabrics/' . rawurlencode((string) $galleryImages[0]))
    : SiteContext::url('/images/fabrics/default.jpg');
$metaUrl = SiteContext::url(ProductAdminService::publicPath($product));
include 'includes/header.php';
do_action('product.view', [
    'conn' => $conn,
    'product_id' => (int) $product['id'],
    'customer_id' => (int) ($_SESSION['customer_id'] ?? 0),
]);
?>
<section class="page-hero u-py-4">
    <div class="l-container">
        <a href="/catalog" class="u-text-light u-opacity-75 u-text-small">&larr; Back to Shop</a>
    </div>
</section>

<section
    class="section-block"
    data-ui-product
    data-product-config="<?php echo ui_data_json([
        'variants' => array_values($variants),
        'hideVariantSize' => $hideVariantSize,
        'galleryImages' => array_values($galleryImages),
        'videoFile' => (string) $videoFile,
        'isWholeUnit' => $isWholeUnit,
        'unitType' => $unitType,
        'basePrice' => (float) $effectiveBasePrice,
        'regularPrice' => (float) $regularPrice,
        'unitLabel' => (string) $unitSingleLabel,
        'minimumOrderQuantity' => (float) $minOrderQty,
        'quantityStep' => (float) $qtyStepUi,
    ]); ?>"
>
    <div class="l-container">
        <div class="l-grid l-grid--12 u-gap-3 u-gap-tablet-5">
            <div class="l-col-md-five">
                <?php if (!empty($galleryImages)): ?>
                    <?php $mainImageAsset = fabric_image_asset_data((string) $galleryImages[0]); ?>
                    <div class="product-media-main u-mb-3 u-shadow u-overflow-hidden" data-ui-product-gallery>
                        <picture id="product-main-picture" class="u-block u-w-full u-h-full">
                            <?php if (!empty($mainImageAsset['webp_srcset'])): ?>
                                <source id="product-main-webp-source" type="image/webp" srcset="<?php echo e($mainImageAsset['webp_srcset']); ?>" sizes="(max-width: 767px) 100vw, 45vw">
                            <?php else: ?>
                                <source id="product-main-webp-source" type="image/webp" srcset="">
                            <?php endif; ?>
                            <img id="product-main-image"
                                 src="<?php echo e($mainImageAsset['src']); ?>"
                                 alt="<?php echo e($product['name']); ?>"
                                 class="u-w-full u-h-full">
                        </picture>
                        <?php if ($videoFile !== ''): ?>
                            <video id="product-main-video" class="u-w-full u-h-full u-hidden product-media-video" controls preload="metadata">
                                <source src="/images/fabrics/<?php echo e(rawurlencode($videoFile)); ?>">
                            </video>
                        <?php endif; ?>
                    </div>
                    <div id="product-media-thumbs" class="u-flex u-wrap u-gap-2" data-product-media-thumbs>
                        <?php foreach ($galleryImages as $index => $img): ?>
                            <?php $thumbAsset = fabric_image_asset_data((string) $img); ?>
                            <button type="button"
                                    class="ui-button u-p-0 u-border u-rounded media-thumb product-media-thumb <?php echo $index === 0 ? 'u-border-primary' : 'u-border-light'; ?>"
                                     data-media-type="image"
                                     data-media-src="<?php echo e($thumbAsset['src']); ?>"
                                    data-webp-srcset="<?php echo e((string) ($thumbAsset['webp_srcset'] ?? '')); ?>"
                                    aria-label="View <?php echo e($product['name']); ?> image <?php echo $index + 1; ?>"
                                    aria-current="<?php echo $index === 0 ? 'true' : 'false'; ?>">
                                <img src="<?php echo e((string) ($thumbAsset['thumb_src'] ?? '')); ?>" alt="<?php echo e($product['name']); ?> thumbnail <?php echo $index + 1; ?>" loading="lazy">
                            </button>
                        <?php endforeach; ?>
                        <?php if ($videoFile !== ''): ?>
                            <button type="button"
                                    class="ui-button u-p-0 u-border u-rounded media-thumb product-media-thumb u-border-light u-relative"
                                     data-media-type="video"
                                     data-media-src="/images/fabrics/<?php echo e(rawurlencode($videoFile)); ?>"
                                    aria-label="Play <?php echo e($product['name']); ?> video"
                                    aria-current="false">
                                <div class="product-media-thumb-video">Video</div>
                            </button>
                        <?php endif; ?>
                    </div>
                <?php else: ?>
                    <div class="ui-surface-soft u-rounded u-flex u-items-center u-justify-center product-media-placeholder">
                        <span class="u-text-muted">No image</span>
                    </div>
                <?php endif; ?>
            </div>

            <div class="l-col-md-seven">
                <h1 class="u-mb-1"><?php echo e($product['name']); ?></h1>
                <?php if (!empty($product['category'])): ?>
                    <p class="u-text-muted u-mb-2"><?php echo e($product['category']); ?></p>
                <?php endif; ?>

                <div class="u-mb-3" id="product_price_block">
                    <?php if ($salePrice > 0 && $regularPrice > 0 && $salePrice < $regularPrice): ?>
                        <span class="u-text-large u-font-bold u-text-primary"><?php echo e(money($salePrice)); ?> / <?php echo e($unitSingleLabel); ?></span>
                        <span class="ms-3 u-text-muted"><del><?php echo e(money($regularPrice)); ?> / <?php echo e($unitSingleLabel); ?></del></span>
                    <?php elseif ($regularPrice > 0): ?>
                        <span class="u-text-large u-font-bold u-text-primary"><?php echo e(money($regularPrice)); ?> / <?php echo e($unitSingleLabel); ?></span>
                    <?php else: ?>
                        <span class="u-text-muted">Price on request</span>
                    <?php endif; ?>
                </div>

                <div class="u-flex u-wrap u-gap-2 u-mb-3">
                    <?php if (!empty($product['color'])): ?>
                        <span class="ui-badge--soft">Color: <?php echo e($product['color']); ?></span>
                    <?php endif; ?>
                    <span class="ui-badge <?php echo $inStock ? 'bg-success' : 'bg-secondary'; ?>" id="base_stock_badge">
                        <?php echo $inStock ? 'Stock Status: In Stock (' . format_quantity_by_unit($displayStock, $unitType) . ' ' . e($unitLabel) . ')' : 'Stock Status: Out of Stock'; ?>
                    </span>
                </div>

<?php if (!empty($colorGroups)): ?>
                <!-- Colour swatches -->
                <?php if ($showColorPicker): ?>
                <div class="u-mb-3" id="color-picker-section">
                    <h6 class="u-font-semibold u-mb-2">Colour</h6>
                    <div class="u-flex u-wrap u-gap-2" id="color-swatch-group">
                        <?php foreach ($colorsForPicker as $cidx => $colorName): ?>
                            <button type="button"
                                    class="ui-button ui-button--small color-swatch-btn <?php echo $cidx === 0 ? 'ui-button--navy' : 'ui-button--secondary'; ?>"
                                    data-color="<?php echo e($colorName); ?>"
                                    aria-pressed="<?php echo $cidx === 0 ? 'true' : 'false'; ?>">
                                <?php echo e($colorName ?: 'Default'); ?>
                            </button>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php endif; ?>

                <!-- Size buttons (all rendered; JS hides/shows by colour) -->
                <?php
                $allSizes = [];
                foreach ($variants as $v) {
                    if (!(int)$v['is_active']) continue;
                    $rawSize = trim((string) ($v['size'] ?? ''));
                    if ($rawSize === '') continue;
                    if ($unitType === 'set' && $isPackLikeSize($rawSize)) continue;
                    $allSizes[$rawSize] = $rawSize;
                }
                $showSizePicker = !$hideVariantSize && !empty($allSizes);
                ?>
                <div class="u-mb-3" id="size-picker-section" <?php echo $showSizePicker ? '' : 'hidden'; ?>>
                    <h6 class="u-font-semibold u-mb-2">Size</h6>
                    <div class="u-flex u-wrap u-gap-2" id="size-btn-group">
                        <?php
                        $sizeIdx = 0;
                        foreach ($allSizes as $sz => $szLabel):
                            $isDefault = $sz === $defaultSize;
                        ?>
                            <button type="button"
                                    class="ui-button ui-button--small size-option-btn <?php echo $isDefault ? 'ui-button--navy' : 'ui-button--secondary'; ?>"
                                    data-size="<?php echo e($sz); ?>"
                                    aria-pressed="<?php echo $isDefault ? 'true' : 'false'; ?>">
                                <?php echo e($szLabel); ?>
                            </button>
                        <?php $sizeIdx++; endforeach; ?>
                    </div>
                </div>

                <?php if ($unitType === 'set'): ?>
                <div class="u-mb-3" id="pack-info-section" <?php echo ($defaultPackLabel !== '' || $defaultUnitsPerSet > 0) ? '' : 'hidden'; ?>>
                    <h6 class="u-font-semibold u-mb-2">Pack</h6>
                    <span class="ui-badge ui-badge--neutral u-text-ink u-border" id="pack-info-label">
                        <?php if ($defaultPackLabel !== ''): ?>
                            <?php echo e($defaultPackLabel); ?>
                        <?php elseif ($defaultUnitsPerSet > 0): ?>
                            <?php echo e(format_pack_label($defaultUnitsPerSet)); ?>
                        <?php endif; ?>
                    </span>
                </div>
                <?php endif; ?>

                <!-- Variant stock badge (updated via JS) -->
                <div id="variant-stock-badge" class="u-mb-2"></div>

<?php elseif (!empty($sizeOptions)): ?>
                <!-- Legacy size buttons (no DB variants) -->
                <div class="u-mb-3">
                    <h6 class="u-font-semibold u-mb-2">Available Sizes</h6>
                    <div class="u-flex u-wrap u-gap-2">
                        <?php foreach ($sizeOptions as $idx => $opt): ?>
                            <button type="button"
                                    class="ui-button ui-button--small size-option-btn <?php echo $idx === 0 ? 'ui-button--navy' : 'ui-button--secondary'; ?>"
                                    data-size="<?php echo e($opt); ?>"
                                    aria-pressed="<?php echo $idx === 0 ? 'true' : 'false'; ?>">
                                <?php echo e($opt); ?>
                            </button>
                        <?php endforeach; ?>
                    </div>
                </div>
<?php endif; ?>

                <?php if (!empty($meterOptions)): ?>
                <div class="u-mb-3">
                    <h6 class="u-font-semibold u-mb-2">Select Meters</h6>
                    <div class="u-flex u-wrap u-gap-2">
                        <?php foreach ($meterOptions as $idx => $mval): ?>
                            <button type="button"
                                    class="ui-button ui-button--small meter-option-btn <?php echo $idx === 0 ? 'ui-button--primary' : 'ui-button--outline'; ?>"
                                    data-meters="<?php echo e($mval); ?>"
                                    aria-pressed="<?php echo $idx === 0 ? 'true' : 'false'; ?>">
                                <?php echo e($mval); ?>m
                            </button>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php endif; ?>

                <div class="ui-card u-p-3 u-mb-3 product-purchase-card">
                    <label class="ui-label u-font-semibold">
                        <?php echo $unitType === 'meter' ? 'Quantity (pieces)' : 'Quantity (' . e($unitLabel) . ')'; ?>
                    </label>
                        <form method="POST" action="/add-to-cart.php" id="add_to_cart_form" data-ajax-cart data-ui-async="true">
                        <?php echo csrf_field(); ?>
                        <input type="hidden" name="product_id" value="<?php echo (int) $product['id']; ?>">
                        <?php if ($unitType === 'meter'): ?>
                            <?php $defaultMeterLength = !empty($meterOptions) ? (float) $meterOptions[0] : max(1.0, (float) ($product['min_order_meters'] ?? 1)); ?>
                            <input type="hidden" name="meter_length" id="selected_meter_length" value="<?php echo e(rtrim(rtrim(number_format($defaultMeterLength, 2), '0'), '.')); ?>">
                            <input type="hidden" name="quantity" id="meter_total_quantity" value="<?php echo e(rtrim(rtrim(number_format($defaultMeterLength, 2), '0'), '.')); ?>">
                        <?php endif; ?>
                        <?php if (!empty($colorGroups)): ?>
                            <input type="hidden" name="selected_color" id="selected_color_add" value="<?php echo e($defaultColor); ?>">
                            <input type="hidden" name="selected_size" id="selected_size_add" value="<?php echo e($defaultSize); ?>">
                            <input type="hidden" name="variant_id" id="selected_variant_id_add" value="<?php echo $defaultVariantId; ?>">
                        <?php elseif (!empty($sizeOptions)): ?>
                            <input type="hidden" name="selected_size" id="selected_size_add" value="<?php echo e($defaultSize); ?>">
                        <?php endif; ?>
                        <div class="product-purchase-controls">
                            <div class="product-quantity-controls">
                            <?php if ($unitType === 'meter'): ?>
                                <button type="button" id="qty_dec" class="ui-button ui-button--secondary" aria-label="Decrease quantity">-</button>
                                <input type="number"
                                       id="product_quantity"
                                       name="bundle_quantity"
                                       class="ui-input product-quantity-input"
                                       min="1"
                                       step="1"
                                       value="1"
                                       <?php echo $inStock ? '' : 'disabled'; ?>>
                                <button type="button" id="qty_inc" class="ui-button ui-button--secondary" aria-label="Increase quantity">+</button>
                            <?php else: ?>
                                <button type="button" id="qty_dec" class="ui-button ui-button--secondary" aria-label="Decrease quantity">-</button>
                                <select
                                       id="product_quantity"
                                       name="quantity"
                                       class="ui-input product-quantity-input"
                                       <?php echo $inStock ? '' : 'disabled'; ?>>
                                    <?php foreach ($quantityOptions as $idx => $qOpt): ?>
                                        <?php $qVal = $isWholeUnit ? (string) ((int) round($qOpt)) : rtrim(rtrim(number_format((float) $qOpt, 2), '0'), '.'); ?>
                                        <option value="<?php echo e($qVal); ?>" <?php echo $idx === 0 ? 'selected' : ''; ?>>
                                            <?php echo e($qVal); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <button type="button" id="qty_inc" class="ui-button ui-button--secondary" aria-label="Increase quantity">+</button>
                            <?php endif; ?>
                            </div>
                            <button type="submit" id="add_to_cart_submit" class="ui-button ui-button--primary product-add-cart-button" <?php echo $inStock ? '' : 'disabled'; ?>>
                                Add to Cart
                            </button>
                        </div>
                        <?php if ($unitType === 'meter'): ?>
                        <div class="u-text-small u-text-muted u-mt-2" id="meter_purchase_summary">
                            1 x <?php echo e(rtrim(rtrim(number_format($defaultMeterLength, 2), '0'), '.')); ?>m = <?php echo e(rtrim(rtrim(number_format($defaultMeterLength, 2), '0'), '.')); ?>m
                            <?php if ($effectiveBasePrice > 0): ?>
                                | Total: <?php echo e(money((float) $effectiveBasePrice * (float) $defaultMeterLength)); ?>
                            <?php endif; ?>
                        </div>
                        <?php endif; ?>
                    </form>
                    <?php if ($inStock): ?>
                        <form method="POST" action="/add-to-cart.php" class="u-grid u-mt-2" id="buy_now_form">
                            <?php echo csrf_field(); ?>
                            <input type="hidden" name="product_id" value="<?php echo (int) $product['id']; ?>">
                            <input type="hidden" name="quantity" id="buy_now_quantity" value="1">
                            <?php if ($unitType === 'meter'): ?>
                                <input type="hidden" name="meter_length" id="buy_now_meter_length" value="<?php echo e(rtrim(rtrim(number_format($defaultMeterLength ?? max(1.0, (float) ($product['min_order_meters'] ?? 1)), 2), '0'), '.')); ?>">
                                <input type="hidden" name="bundle_quantity" id="buy_now_bundle_quantity" value="1">
                            <?php endif; ?>
                            <?php if (!empty($colorGroups)): ?>
                                <input type="hidden" name="selected_color" id="selected_color_buy" value="<?php echo e($defaultColor); ?>">
                                <input type="hidden" name="selected_size" id="selected_size_buy" value="<?php echo e($defaultSize); ?>">
                                <input type="hidden" name="variant_id" id="selected_variant_id_buy" value="<?php echo $defaultVariantId; ?>">
                            <?php elseif (!empty($sizeOptions)): ?>
                                <input type="hidden" name="selected_size" id="selected_size_buy" value="<?php echo e($defaultSize); ?>">
                            <?php endif; ?>
                            <input type="hidden" name="redirect_to" value="checkout">
                            <button type="submit" id="buy_now_submit" class="ui-button ui-button--outline">Buy Now</button>
                        </form>
                        <div class="trust-badge-block u-mt-3" aria-label="Purchase trust badges">
                            <span class="trust-badge-pill">COD Available</span>
                            <span class="trust-badge-pill">Secure Payment</span>
                            <span class="trust-badge-pill">Fast Dispatch</span>
                            <span class="trust-badge-pill">Easy Returns</span>
                        </div>
                    <?php else: ?>
                        <div class="u-grid u-mt-2">
                            <button type="button" class="ui-button ui-button--outline" disabled>Buy Now</button>
                        </div>
                    <?php endif; ?>
                </div>

                <?php if (!empty($product['description'])): ?>
                    <div class="u-mb-3">
                        <h6 class="u-font-semibold">Description</h6>
                        <p class="u-mb-0"><?php echo nl2br(e((string) $product['description'])); ?></p>
                    </div>
                <?php endif; ?>

                <div class="u-mb-3">
                    <h6 class="u-font-semibold">Fabric / Material</h6>
                    <p class="u-mb-0"><?php echo e($catalogData['attr_material'] !== '' ? $catalogData['attr_material'] : ($catalogData['attr_fabric'] !== '' ? $catalogData['attr_fabric'] : 'Not specified')); ?></p>
                </div>

                <?php if ($catalogData['attr_printing_type'] !== ''): ?>
                    <div class="u-mb-3">
                        <h6 class="u-font-semibold">Printing Type</h6>
                        <p class="u-mb-0"><?php echo e($catalogData['attr_printing_type']); ?></p>
                    </div>
                <?php endif; ?>

                <div class="u-mb-3">
                    <h6 class="u-font-semibold">Check Delivery</h6>
                    <form id="pdp_delivery_form" class="l-grid l-grid--12 u-gap-2 u-mb-2 js-no-loading" data-ui-delivery-estimate>
                        <?php echo csrf_field(); ?>
                        <input type="hidden" name="product_id" value="<?php echo (int) $product['id']; ?>">
                        <input type="hidden" name="variant_id" id="delivery_variant_id" value="<?php echo (int) ($defaultVariantId ?? 0); ?>">
                        <input type="hidden" name="quantity" id="delivery_quantity" value="1">
                        <div class="l-col-sm-seven">
                            <input class="ui-input" name="pincode" inputmode="numeric" maxlength="6" pattern="[1-9][0-9]{5}" placeholder="6-digit pincode" value="<?php echo e((string) ($_SESSION['delivery_pincode'] ?? '')); ?>" required>
                        </div>
                        <div class="l-col-sm-quarter">
                            <select class="ui-select" name="payment_method" aria-label="Payment method">
                                <option value="cod">Cash on delivery</option>
                                <option value="razorpay">Prepaid</option>
                            </select>
                        </div>
                        <div class="l-col-sm-two u-grid">
                            <button class="ui-button ui-button--outline" type="submit">Check</button>
                        </div>
                    </form>
                    <div id="pdp_delivery_result" class="u-text-small u-mb-2" aria-live="polite"></div>
                    <h6 class="u-font-semibold">Shipping Note</h6>
                    <p class="u-mb-0 u-text-muted">Shipping timelines vary by destination and order volume. Final timeline is shared at confirmation.</p>
                </div>

                <div class="u-mb-0">
                    <h6 class="u-font-semibold">Return Policy</h6>
                    <p class="u-mb-2 u-text-muted">Refund returns are supported for eligible cases within <?php echo return_request_window_days(); ?> calendar days of confirmed delivery.</p>
                    <a href="return-policy.php" class="ui-button ui-button--small ui-button--secondary">View Return Policy</a>
                </div>

                <?php do_action('product.details.after', [
                    'conn' => $conn,
                    'product' => $product,
                    'customer_id' => (int) ($_SESSION['customer_id'] ?? 0),
                ]); ?>
            </div>
        </div>
    </div>
</section>

<?php include 'includes/footer.php'; ?>
