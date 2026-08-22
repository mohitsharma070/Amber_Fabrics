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
    echo '<div class="container py-5 text-center"><a href="/catalog.php">&larr; Back to Shop</a></div>';
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
<section class="page-hero py-4">
    <div class="container">
        <a href="/catalog" class="text-white opacity-75 small">&larr; Back to Shop</a>
    </div>
</section>

<section class="section-block">
    <div class="container">
        <div class="row g-3 g-md-5">
            <div class="col-md-5">
                <?php if (!empty($galleryImages)): ?>
                    <?php $mainImageAsset = fabric_image_asset_data((string) $galleryImages[0]); ?>
                    <div class="product-media-main mb-3 shadow-sm overflow-hidden">
                        <picture id="product-main-picture" class="d-block w-100 h-100">
                            <?php if (!empty($mainImageAsset['webp_srcset'])): ?>
                                <source id="product-main-webp-source" type="image/webp" srcset="<?php echo e($mainImageAsset['webp_srcset']); ?>" sizes="(max-width: 767px) 100vw, 45vw">
                            <?php else: ?>
                                <source id="product-main-webp-source" type="image/webp" srcset="">
                            <?php endif; ?>
                            <img id="product-main-image"
                                 src="<?php echo e($mainImageAsset['src']); ?>"
                                 alt="<?php echo e($product['name']); ?>"
                                 class="w-100 h-100">
                        </picture>
                        <?php if ($videoFile !== ''): ?>
                            <video id="product-main-video" class="w-100 h-100 d-none" style="object-fit:contain;background:#101418;" controls preload="metadata">
                                <source src="/images/fabrics/<?php echo e(rawurlencode($videoFile)); ?>">
                            </video>
                        <?php endif; ?>
                    </div>
                    <div id="product-media-thumbs" class="d-flex flex-wrap gap-2">
                        <?php foreach ($galleryImages as $index => $img): ?>
                            <?php $thumbAsset = fabric_image_asset_data((string) $img); ?>
                            <button type="button"
                                    class="btn p-0 border rounded media-thumb product-media-thumb <?php echo $index === 0 ? 'border-primary' : 'border-light'; ?>"
                                    data-type="image"
                                    data-src="<?php echo e($thumbAsset['src']); ?>"
                                    data-webp-srcset="<?php echo e((string) ($thumbAsset['webp_srcset'] ?? '')); ?>"
                                    aria-label="View <?php echo e($product['name']); ?> image <?php echo $index + 1; ?>"
                                    aria-current="<?php echo $index === 0 ? 'true' : 'false'; ?>">
                                <img src="<?php echo e((string) ($thumbAsset['thumb_src'] ?? '')); ?>" alt="<?php echo e($product['name']); ?> thumbnail <?php echo $index + 1; ?>" loading="lazy">
                            </button>
                        <?php endforeach; ?>
                        <?php if ($videoFile !== ''): ?>
                            <button type="button"
                                    class="btn p-0 border rounded media-thumb product-media-thumb border-light position-relative"
                                    data-type="video"
                                    data-src="/images/fabrics/<?php echo e(rawurlencode($videoFile)); ?>"
                                    aria-label="Play <?php echo e($product['name']); ?> video"
                                    aria-current="false">
                                <div class="product-media-thumb-video">Video</div>
                            </button>
                        <?php endif; ?>
                    </div>
                    <script nonce="<?php echo $cspNonce; ?>">
                    (function () {
                        var mainImage = document.getElementById('product-main-image');
                        var mainVideo = document.getElementById('product-main-video');
                        var mainWebpSource = document.getElementById('product-main-webp-source');
                        var thumbsWrap = document.getElementById('product-media-thumbs');
                        if (!mainImage || !thumbsWrap) return;

                        function activateThumb(thumb) {
                            var thumbs = thumbsWrap.querySelectorAll('.media-thumb');
                            thumbs.forEach(function (t) {
                                t.classList.remove('border-primary');
                                t.classList.add('border-light');
                                t.setAttribute('aria-current', 'false');
                            });
                            thumb.classList.remove('border-light');
                            thumb.classList.add('border-primary');
                            thumb.setAttribute('aria-current', 'true');

                            var type = thumb.getAttribute('data-type');
                            var src = thumb.getAttribute('data-src') || '';
                            var webpSrcset = thumb.getAttribute('data-webp-srcset') || '';
                            if (type === 'video' && mainVideo) {
                                mainImage.classList.add('d-none');
                                mainVideo.classList.remove('d-none');
                                if (mainWebpSource) {
                                    mainWebpSource.setAttribute('srcset', '');
                                }
                                var source = mainVideo.querySelector('source');
                                if (source && source.getAttribute('src') !== src) {
                                    source.setAttribute('src', src);
                                    mainVideo.load();
                                }
                            } else {
                                if (mainVideo) {
                                    mainVideo.pause();
                                    mainVideo.classList.add('d-none');
                                }
                                if (mainWebpSource) {
                                    mainWebpSource.setAttribute('srcset', webpSrcset);
                                }
                                mainImage.classList.remove('d-none');
                                mainImage.setAttribute('src', src);
                            }
                        }

                        thumbsWrap.addEventListener('click', function (event) {
                            var thumb = event.target && event.target.closest ? event.target.closest('.media-thumb') : null;
                            if (!thumb) return;
                            activateThumb(thumb);
                        });

                        window.productMediaController = {
                            setMedia: function (images, videoFile) {
                                var html = '';
                                (images || []).forEach(function (img, idx) {
                                    var src = '/images/fabrics/' + encodeURIComponent(img);
                                    html += '<button type="button" class="btn p-0 border rounded media-thumb product-media-thumb '
                                        + (idx === 0 ? 'border-primary' : 'border-light')
                                        + '" data-type="image" data-src="' + src + '" data-webp-srcset="" aria-label="View product image ' + (idx + 1) + '" aria-current="' + (idx === 0 ? 'true' : 'false') + '">'
                                        + '<img src="' + src + '" alt="Product thumbnail ' + (idx + 1) + '">'
                                        + '</button>';
                                });
                                if (videoFile) {
                                    var vsrc = '/images/fabrics/' + encodeURIComponent(videoFile);
                                    html += '<button type="button" class="btn p-0 border rounded media-thumb product-media-thumb border-light position-relative" data-type="video" data-src="' + vsrc + '" aria-label="Play product video" aria-current="false">'
                                        + '<div class="product-media-thumb-video">Video</div>'
                                        + '</button>';
                                }
                                thumbsWrap.innerHTML = html;

                                var firstThumb = thumbsWrap.querySelector('.media-thumb');
                                if (firstThumb) {
                                    activateThumb(firstThumb);
                                }
                            }
                        };
                    })();
                    </script>
                <?php else: ?>
                    <div class="bg-light rounded d-flex align-items-center justify-content-center" style="min-height:320px;">
                        <span class="text-muted">No image</span>
                    </div>
                <?php endif; ?>
            </div>

            <div class="col-md-7">
                <h1 class="mb-1"><?php echo e($product['name']); ?></h1>
                <?php if (!empty($product['category'])): ?>
                    <p class="text-muted mb-2"><?php echo e($product['category']); ?></p>
                <?php endif; ?>

                <div class="mb-3" id="product_price_block">
                    <?php if ($salePrice > 0 && $regularPrice > 0 && $salePrice < $regularPrice): ?>
                        <span class="fs-4 fw-bold text-primary"><?php echo e(money($salePrice)); ?> / <?php echo e($unitSingleLabel); ?></span>
                        <span class="ms-3 text-muted"><del><?php echo e(money($regularPrice)); ?> / <?php echo e($unitSingleLabel); ?></del></span>
                    <?php elseif ($regularPrice > 0): ?>
                        <span class="fs-4 fw-bold text-primary"><?php echo e(money($regularPrice)); ?> / <?php echo e($unitSingleLabel); ?></span>
                    <?php else: ?>
                        <span class="text-muted">Price on request</span>
                    <?php endif; ?>
                </div>

                <div class="d-flex flex-wrap gap-2 mb-3">
                    <?php if (!empty($product['color'])): ?>
                        <span class="badge-soft">Color: <?php echo e($product['color']); ?></span>
                    <?php endif; ?>
                    <span class="badge <?php echo $inStock ? 'bg-success' : 'bg-secondary'; ?>" id="base_stock_badge">
                        <?php echo $inStock ? 'Stock Status: In Stock (' . format_quantity_by_unit($displayStock, $unitType) . ' ' . e($unitLabel) . ')' : 'Stock Status: Out of Stock'; ?>
                    </span>
                </div>

<?php if (!empty($colorGroups)): ?>
                <!-- Colour swatches -->
                <?php if ($showColorPicker): ?>
                <div class="mb-3" id="color-picker-section">
                    <h6 class="fw-semibold mb-2">Colour</h6>
                    <div class="d-flex flex-wrap gap-2" id="color-swatch-group">
                        <?php foreach ($colorsForPicker as $cidx => $colorName): ?>
                            <button type="button"
                                    class="btn btn-sm color-swatch-btn <?php echo $cidx === 0 ? 'btn-dark' : 'btn-outline-dark'; ?>"
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
                <div class="mb-3" id="size-picker-section" <?php echo $showSizePicker ? '' : 'style="display:none"'; ?>>
                    <h6 class="fw-semibold mb-2">Size</h6>
                    <div class="d-flex flex-wrap gap-2" id="size-btn-group">
                        <?php
                        $sizeIdx = 0;
                        foreach ($allSizes as $sz => $szLabel):
                            $isDefault = $sz === $defaultSize;
                        ?>
                            <button type="button"
                                    class="btn btn-sm size-option-btn <?php echo $isDefault ? 'btn-dark' : 'btn-outline-dark'; ?>"
                                    data-size="<?php echo e($sz); ?>"
                                    aria-pressed="<?php echo $isDefault ? 'true' : 'false'; ?>">
                                <?php echo e($szLabel); ?>
                            </button>
                        <?php $sizeIdx++; endforeach; ?>
                    </div>
                </div>

                <?php if ($unitType === 'set'): ?>
                <div class="mb-3" id="pack-info-section" <?php echo ($defaultPackLabel !== '' || $defaultUnitsPerSet > 0) ? '' : 'style="display:none"'; ?>>
                    <h6 class="fw-semibold mb-2">Pack</h6>
                    <span class="badge bg-dark-subtle text-dark border" id="pack-info-label">
                        <?php if ($defaultPackLabel !== ''): ?>
                            <?php echo e($defaultPackLabel); ?>
                        <?php elseif ($defaultUnitsPerSet > 0): ?>
                            <?php echo e(format_pack_label($defaultUnitsPerSet)); ?>
                        <?php endif; ?>
                    </span>
                </div>
                <?php endif; ?>

                <!-- Variant stock badge (updated via JS) -->
                <div id="variant-stock-badge" class="mb-2"></div>

                <?php // Embed variant data for JS ?>
                <script nonce="<?php echo $cspNonce; ?>">
                    window.FABRIC_VARIANTS = <?php echo json_encode(array_values($variants), JSON_HEX_TAG | JSON_HEX_AMP); ?>;
                </script>
<?php elseif (!empty($sizeOptions)): ?>
                <!-- Legacy size buttons (no DB variants) -->
                <div class="mb-3">
                    <h6 class="fw-semibold mb-2">Available Sizes</h6>
                    <div class="d-flex flex-wrap gap-2">
                        <?php foreach ($sizeOptions as $idx => $opt): ?>
                            <button type="button"
                                    class="btn btn-sm size-option-btn <?php echo $idx === 0 ? 'btn-dark' : 'btn-outline-dark'; ?>"
                                    data-size="<?php echo e($opt); ?>"
                                    aria-pressed="<?php echo $idx === 0 ? 'true' : 'false'; ?>">
                                <?php echo e($opt); ?>
                            </button>
                        <?php endforeach; ?>
                    </div>
                </div>
<?php endif; ?>

                <?php if (!empty($meterOptions)): ?>
                <div class="mb-3">
                    <h6 class="fw-semibold mb-2">Select Meters</h6>
                    <div class="d-flex flex-wrap gap-2">
                        <?php foreach ($meterOptions as $idx => $mval): ?>
                            <button type="button"
                                    class="btn btn-sm meter-option-btn <?php echo $idx === 0 ? 'btn-primary' : 'btn-outline-primary'; ?>"
                                    data-meters="<?php echo e($mval); ?>"
                                    aria-pressed="<?php echo $idx === 0 ? 'true' : 'false'; ?>">
                                <?php echo e($mval); ?>m
                            </button>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php endif; ?>

                <div class="card p-3 mb-3" style="max-width:420px;">
                    <label class="form-label fw-semibold">
                        <?php echo $unitType === 'meter' ? 'Quantity (pieces)' : 'Quantity (' . e($unitLabel) . ')'; ?>
                    </label>
                    <form method="POST" action="/add-to-cart.php" id="add_to_cart_form">
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
                                <button type="button" id="qty_dec" class="btn btn-outline-secondary" aria-label="Decrease quantity">-</button>
                                <input type="number"
                                       id="product_quantity"
                                       name="bundle_quantity"
                                       class="form-control"
                                       style="width:120px;"
                                       min="1"
                                       step="1"
                                       value="1"
                                       <?php echo $inStock ? '' : 'disabled'; ?>>
                                <button type="button" id="qty_inc" class="btn btn-outline-secondary" aria-label="Increase quantity">+</button>
                            <?php else: ?>
                                <button type="button" id="qty_dec" class="btn btn-outline-secondary" aria-label="Decrease quantity">-</button>
                                <select
                                       id="product_quantity"
                                       name="quantity"
                                       class="form-control"
                                       style="width:120px;"
                                       <?php echo $inStock ? '' : 'disabled'; ?>>
                                    <?php foreach ($quantityOptions as $idx => $qOpt): ?>
                                        <?php $qVal = $isWholeUnit ? (string) ((int) round($qOpt)) : rtrim(rtrim(number_format((float) $qOpt, 2), '0'), '.'); ?>
                                        <option value="<?php echo e($qVal); ?>" <?php echo $idx === 0 ? 'selected' : ''; ?>>
                                            <?php echo e($qVal); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <button type="button" id="qty_inc" class="btn btn-outline-secondary" aria-label="Increase quantity">+</button>
                            <?php endif; ?>
                            </div>
                            <button type="submit" id="add_to_cart_submit" class="btn btn-primary product-add-cart-button" <?php echo $inStock ? '' : 'disabled'; ?>>
                                Add to Cart
                            </button>
                        </div>
                        <?php if ($unitType === 'meter'): ?>
                        <div class="small text-muted mt-2" id="meter_purchase_summary">
                            1 x <?php echo e(rtrim(rtrim(number_format($defaultMeterLength, 2), '0'), '.')); ?>m = <?php echo e(rtrim(rtrim(number_format($defaultMeterLength, 2), '0'), '.')); ?>m
                            <?php if ($effectiveBasePrice > 0): ?>
                                | Total: <?php echo e(money((float) $effectiveBasePrice * (float) $defaultMeterLength)); ?>
                            <?php endif; ?>
                        </div>
                        <?php endif; ?>
                    </form>
                    <?php if ($inStock): ?>
                        <form method="POST" action="/add-to-cart.php" class="d-grid mt-2" id="buy_now_form">
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
                            <button type="submit" id="buy_now_submit" class="btn btn-outline-dark">Buy Now</button>
                        </form>
                        <div class="trust-badge-block mt-3" aria-label="Purchase trust badges">
                            <span class="trust-badge-pill">COD Available</span>
                            <span class="trust-badge-pill">Secure Payment</span>
                            <span class="trust-badge-pill">Fast Dispatch</span>
                            <span class="trust-badge-pill">Easy Returns</span>
                        </div>
                        <script nonce="<?php echo $cspNonce; ?>">
                        (function () {
                            var qtyInput = document.getElementById('product_quantity');
                            var buyNowQty = document.getElementById('buy_now_quantity');
                            var qtyDec = document.getElementById('qty_dec');
                            var qtyInc = document.getElementById('qty_inc');
                            var isPieceUnit = <?php echo $isWholeUnit ? 'true' : 'false'; ?>;
                            var isMeterUnit = <?php echo $unitType === 'meter' ? 'true' : 'false'; ?>;
                            var meterLengthInput = document.getElementById('selected_meter_length');
                            var meterTotalInput = document.getElementById('meter_total_quantity');
                            var buyNowMeterLength = document.getElementById('buy_now_meter_length');
                            var buyNowBundleQty = document.getElementById('buy_now_bundle_quantity');
                            var meterPurchaseSummary = document.getElementById('meter_purchase_summary');
                            var basePricePerUnit = <?php echo json_encode((float) $effectiveBasePrice); ?>;
                            var currentPricePerUnit = basePricePerUnit;
                            var regularPricePerUnit = <?php echo json_encode((float) $regularPrice); ?>;
                            var unitSingleLabel = <?php echo json_encode((string) $unitSingleLabel); ?>;
                            var productPriceBlock = document.getElementById('product_price_block');
                            var basePriceMarkup = productPriceBlock ? productPriceBlock.innerHTML : '';
                            var minimumOrderQty = <?php echo json_encode((float) $minOrderQty); ?>;
                            var quantityStep = <?php echo json_encode((float) $qtyStepUi); ?>;
                            if (!qtyInput || !buyNowQty) return;

                            function syncQty() {
                                var qty = parseFloat(qtyInput.value);
                                if (!Number.isFinite(qty) || qty < 1) qty = 1;

                                if (isMeterUnit) {
                                    qty = Math.round(qty);
                                    qtyInput.value = String(qty);
                                    var meterLen = parseFloat(meterLengthInput ? meterLengthInput.value : '1');
                                    if (!Number.isFinite(meterLen) || meterLen <= 0) meterLen = 1;
                                    var totalMeters = meterLen * qty;
                                    var normalized = totalMeters.toFixed(2).replace(/\.00$/, '').replace(/(\.\d)0$/, '$1');
                                    if (meterTotalInput) {
                                        meterTotalInput.value = normalized;
                                    }
                                    buyNowQty.value = normalized;
                                    if (buyNowBundleQty) {
                                        buyNowBundleQty.value = String(qty);
                                    }
                                    if (buyNowMeterLength) {
                                        buyNowMeterLength.value = meterLen.toFixed(2).replace(/\.00$/, '').replace(/(\.\d)0$/, '$1');
                                    }
                                    if (meterPurchaseSummary) {
                                        var line = String(qty) + ' x ' + meterLen.toFixed(2).replace(/\.00$/, '').replace(/(\.\d)0$/, '$1') + 'm = ' + normalized + 'm';
                                        if (Number.isFinite(currentPricePerUnit) && currentPricePerUnit > 0) {
                                            line += ' | Total: Rs ' + (currentPricePerUnit * totalMeters).toFixed(2);
                                        }
                                        meterPurchaseSummary.textContent = line;
                                    }
                                } else {
                                    buyNowQty.value = isPieceUnit ? String(Math.round(qty)) : qty.toFixed(2);
                                }
                            }

                            qtyInput.addEventListener('change', syncQty);
                            qtyInput.addEventListener('input', syncQty);
                            syncQty();

                            function bump(dir) {
                                if (!qtyInput || qtyInput.disabled) return;
                                if (qtyInput.tagName === 'SELECT') {
                                    var idx = qtyInput.selectedIndex + dir;
                                    if (idx < 0) idx = 0;
                                    if (idx >= qtyInput.options.length) idx = qtyInput.options.length - 1;
                                    qtyInput.selectedIndex = idx;
                                } else {
                                    var step = parseFloat(qtyInput.getAttribute('step') || '1');
                                    if (!Number.isFinite(step) || step <= 0) step = 1;
                                    var current = parseFloat(qtyInput.value || '1');
                                    if (!Number.isFinite(current) || current < 1) current = 1;
                                    var next = current + (dir * step);
                                    if (next < 1) next = 1;
                                    qtyInput.value = step >= 1
                                        ? String(Math.round(next))
                                        : next.toFixed(2).replace(/\.00$/, '').replace(/(\.\d)0$/, '$1');
                                }
                                syncQty();
                            }

                            if (qtyDec) qtyDec.addEventListener('click', function () { bump(-1); });
                            if (qtyInc) qtyInc.addEventListener('click', function () { bump(1); });

                            // ── Variant-aware colour/size selection ──────────────────────────
                            var VARIANTS = window.FABRIC_VARIANTS || [];
                            var HIDE_VARIANT_SIZE = <?php echo $hideVariantSize ? 'true' : 'false'; ?>;
                            var mainImageEl = document.getElementById('product-main-image');
                            var mainVideoEl = document.getElementById('product-main-video');
                            var mainWebpSourceEl = document.getElementById('product-main-webp-source');
                            var defaultMainImageSrc = mainImageEl ? String(mainImageEl.getAttribute('src') || '') : '';
                            var defaultMainWebpSrcset = mainWebpSourceEl ? String(mainWebpSourceEl.getAttribute('srcset') || '') : '';
                            var defaultGalleryImages = <?php echo json_encode(array_values($galleryImages), JSON_HEX_TAG | JSON_HEX_AMP); ?>;
                            var defaultVideoFile = <?php echo json_encode((string) $videoFile, JSON_HEX_TAG | JSON_HEX_AMP); ?>;
                            var colorSwatches  = document.querySelectorAll('.color-swatch-btn');
                            var sizeButtons    = document.querySelectorAll('.size-option-btn');
                            var sizeSection    = document.getElementById('size-picker-section');
                            var packInfoSection = document.getElementById('pack-info-section');
                            var packInfoLabel = document.getElementById('pack-info-label');
                            var stockBadgeEl   = document.getElementById('variant-stock-badge');

                            var colorAdd   = document.getElementById('selected_color_add');
                            var colorBuy   = document.getElementById('selected_color_buy');
                            var sizeAdd    = document.getElementById('selected_size_add');
                            var sizeBuy    = document.getElementById('selected_size_buy');
                            var vidAdd     = document.getElementById('selected_variant_id_add');
                            var vidBuy     = document.getElementById('selected_variant_id_buy');

                            var addBtn = document.getElementById('add_to_cart_submit');
                            var buyBtn = document.getElementById('buy_now_submit');
                            var currentVariant = null;
                            var isSetUnit = <?php echo $unitType === 'set' ? 'true' : 'false'; ?>;
                            var isPackLikeSize = function (val) {
                                return /^pack\s+of\s+\d+$/i.test(String(val || '').trim());
                            };
                            var packLabelForVariant = function (v) {
                                if (!v || !isSetUnit) return '';
                                var pl = (v.pack_label && String(v.pack_label).trim() !== '') ? String(v.pack_label).trim() : '';
                                if (pl !== '') return pl;
                                var ups = parseInt(String(v.units_per_set || '0'), 10);
                                if (Number.isFinite(ups) && ups > 0) return 'Pack of ' + ups;
                                return '';
                            };

                            function variantSizeLabel(v) {
                                if (!v) return '';
                                var rawSize = String(v.size || '').trim();
                                if (rawSize !== '') return rawSize;
                                if (isSetUnit) {
                                    var pl = (v.pack_label && String(v.pack_label).trim() !== '') ? String(v.pack_label).trim() : '';
                                    if (pl !== '') return pl;
                                    var ups = parseInt(String(v.units_per_set || '0'), 10);
                                    if (Number.isFinite(ups) && ups > 0) return 'Pack of ' + ups;
                                }
                                return '';
                            }

                            function findVariant(color, size) {
                                var fallbackByColor = null;
                                for (var i = 0; i < VARIANTS.length; i++) {
                                    var v = VARIANTS[i];
                                    if (parseInt(v.is_active) !== 1) continue;
                                    if (v.color !== color) continue;
                                    if (fallbackByColor === null) fallbackByColor = v;
                                    if (HIDE_VARIANT_SIZE) {
                                        return v;
                                    }
                                    if (v.size === size) {
                                        return v;
                                    }
                                }
                                if (!HIDE_VARIANT_SIZE && String(size || '').trim() === '') {
                                    return fallbackByColor;
                                }
                                return null;
                            }

                            function currentColor() {
                                return colorAdd ? colorAdd.value : '';
                            }

                            function currentSize() {
                                return sizeAdd ? sizeAdd.value : '';
                            }

                            function formatInr(value) {
                                return new Intl.NumberFormat('en-IN', {
                                    style: 'currency',
                                    currency: 'INR',
                                    minimumFractionDigits: 2
                                }).format(value);
                            }

                            function updateVariantPrice(v) {
                                var override = v && v.price_override !== null && String(v.price_override).trim() !== ''
                                    ? parseFloat(v.price_override)
                                    : 0;
                                currentPricePerUnit = Number.isFinite(override) && override > 0 ? override : basePricePerUnit;
                                if (!productPriceBlock) return;
                                if (!(Number.isFinite(override) && override > 0)) {
                                    productPriceBlock.innerHTML = basePriceMarkup;
                                    return;
                                }
                                var price = document.createElement('span');
                                price.className = 'fs-4 fw-bold text-primary';
                                price.textContent = formatInr(override) + ' / ' + unitSingleLabel;
                                productPriceBlock.replaceChildren(price);
                                if (regularPricePerUnit > override) {
                                    var mrp = document.createElement('span');
                                    mrp.className = 'ms-3 text-muted';
                                    var deleted = document.createElement('del');
                                    deleted.textContent = formatInr(regularPricePerUnit) + ' / ' + unitSingleLabel;
                                    mrp.appendChild(deleted);
                                    productPriceBlock.appendChild(mrp);
                                }
                            }

                            function selectedVariantStock(v) {
                                if (!v) return 0;
                                var stock = parseFloat(isMeterUnit ? v.stock_meters : v.stock);
                                return Number.isFinite(stock) ? Math.max(0, stock) : 0;
                            }

                            function updateVariantQuantity(v) {
                                if (VARIANTS.length === 0) return true;
                                if (!v) {
                                    qtyInput.disabled = true;
                                    if (qtyDec) qtyDec.disabled = true;
                                    if (qtyInc) qtyInc.disabled = true;
                                    document.querySelectorAll('.meter-option-btn').forEach(function (button) { button.disabled = true; });
                                    return false;
                                }
                                var stock = selectedVariantStock(v);
                                var canBuy = false;
                                if (isMeterUnit) {
                                    var enabledMeterButton = null;
                                    document.querySelectorAll('.meter-option-btn').forEach(function (button) {
                                        var length = parseFloat(button.getAttribute('data-meters') || '0');
                                        var enabled = Number.isFinite(length) && length > 0 && length <= stock;
                                        button.disabled = !enabled;
                                        if (enabled && !enabledMeterButton) enabledMeterButton = button;
                                    });
                                    var meterLength = parseFloat(meterLengthInput ? meterLengthInput.value : '0');
                                    if ((!Number.isFinite(meterLength) || meterLength <= 0 || meterLength > stock) && enabledMeterButton) {
                                        meterLength = parseFloat(enabledMeterButton.getAttribute('data-meters') || '0');
                                        document.querySelectorAll('.meter-option-btn').forEach(function (button) {
                                            var selected = button === enabledMeterButton;
                                            button.classList.toggle('btn-primary', selected);
                                            button.classList.toggle('btn-outline-primary', !selected);
                                            button.setAttribute('aria-pressed', selected ? 'true' : 'false');
                                        });
                                        var meterValue = String(meterLength).replace(/\.0+$/, '');
                                        if (meterLengthInput) meterLengthInput.value = meterValue;
                                        if (buyNowMeterLength) buyNowMeterLength.value = meterValue;
                                    }
                                    var maxBundles = Number.isFinite(meterLength) && meterLength > 0
                                        ? Math.floor((stock + 0.000001) / meterLength)
                                        : 0;
                                    qtyInput.max = String(Math.max(0, maxBundles));
                                    var bundles = parseInt(qtyInput.value || '1', 10);
                                    if (!Number.isFinite(bundles) || bundles < 1) bundles = 1;
                                    if (maxBundles > 0 && bundles > maxBundles) bundles = maxBundles;
                                    qtyInput.value = String(bundles);
                                    canBuy = maxBundles >= 1;
                                } else if (qtyInput.tagName === 'SELECT') {
                                    var previous = parseFloat(qtyInput.value || '0');
                                    qtyInput.innerHTML = '';
                                    var start = Math.max(1, Math.ceil(minimumOrderQty));
                                    var step = Math.max(1, Math.round(quantityStep));
                                    var limit = Math.min(Math.floor(stock), 20);
                                    for (var quantity = start; quantity <= limit; quantity += step) {
                                        var option = document.createElement('option');
                                        option.value = String(quantity);
                                        option.textContent = String(quantity);
                                        qtyInput.appendChild(option);
                                    }
                                    canBuy = qtyInput.options.length > 0;
                                    if (canBuy) {
                                        var previousValue = String(Math.round(previous));
                                        if (Array.prototype.some.call(qtyInput.options, function (option) { return option.value === previousValue; })) {
                                            qtyInput.value = previousValue;
                                        }
                                    }
                                }
                                qtyInput.disabled = !canBuy;
                                if (qtyDec) qtyDec.disabled = !canBuy;
                                if (qtyInc) qtyInc.disabled = !canBuy;
                                syncQty();
                                return canBuy;
                            }

                            function updateVariantState(color, size) {
                                var v = (VARIANTS.length > 0) ? findVariant(color, size) : null;
                                currentVariant = v;
                                var vid = v ? v.id : 0;
                                if (colorAdd) colorAdd.value = color;
                                if (colorBuy) colorBuy.value = color;
                                if (sizeAdd)  sizeAdd.value  = size;
                                if (sizeBuy)  sizeBuy.value  = size;
                                if (vidAdd)   vidAdd.value   = vid;
                                if (vidBuy)   vidBuy.value   = vid;
                                updateVariantPrice(v);

                                if (isSetUnit && packInfoSection && packInfoLabel) {
                                    var packText = packLabelForVariant(v);
                                    if (packText !== '') {
                                        packInfoLabel.textContent = packText;
                                        packInfoSection.style.display = '';
                                    } else {
                                        packInfoSection.style.display = 'none';
                                    }
                                }

                                if (window.productMediaController && typeof window.productMediaController.setMedia === 'function') {
                                    var variantImages = [];
                                    ['image', 'image2', 'image3', 'image4'].forEach(function (k) {
                                        if (v && v[k] && String(v[k]).trim() !== '') {
                                            variantImages.push(String(v[k]).trim());
                                        }
                                    });
                                    var variantVideo = (v && v.video) ? String(v.video).trim() : '';
                                    if (variantImages.length > 0 || variantVideo !== '') {
                                        window.productMediaController.setMedia(variantImages, variantVideo);
                                    } else {
                                        window.productMediaController.setMedia(defaultGalleryImages, defaultVideoFile);
                                    }
                                } else if (mainImageEl && defaultMainImageSrc !== '') {
                                    mainImageEl.setAttribute('src', defaultMainImageSrc);
                                    if (mainWebpSourceEl) {
                                        mainWebpSourceEl.setAttribute('srcset', defaultMainWebpSrcset);
                                    }
                                    if (mainVideoEl) {
                                        mainVideoEl.pause();
                                        mainVideoEl.classList.add('d-none');
                                    }
                                    mainImageEl.classList.remove('d-none');
                                }

                                // Stock badge
                                if (stockBadgeEl && VARIANTS.length > 0) {
                                    if (!v) {
                                        stockBadgeEl.innerHTML = '';
                                    } else {
                                        var stockNum  = parseFloat(v.stock_meters) > 0 ? parseFloat(v.stock_meters) : parseFloat(v.stock);
                                        var inStk     = stockNum > 0;
                                        var cls       = inStk ? 'bg-success' : 'bg-secondary';
                                        var label     = inStk ? 'In Stock (' + stockNum + ')' : 'Out of Stock';
                                        stockBadgeEl.innerHTML = '<span class="badge ' + cls + '">' + label + '</span>';
                                    }
                                }

                                // Disable add/buy if no variant or no stock
                                var canAdd = VARIANTS.length > 0 ? updateVariantQuantity(v) : true;
                                if (addBtn) addBtn.disabled = VARIANTS.length > 0 ? !canAdd : addBtn.disabled;
                                if (buyBtn) buyBtn.disabled = VARIANTS.length > 0 ? !canAdd : buyBtn.disabled;
                            }

                            function activateColor(color, preferredSize) {
                                // Update colour swatches
                                colorSwatches.forEach(function (b) {
                                    var selected = b.getAttribute('data-color') === color;
                                    b.classList.toggle('btn-dark', selected);
                                    b.classList.toggle('btn-outline-dark', !selected);
                                    b.setAttribute('aria-pressed', selected ? 'true' : 'false');
                                });
                                // Filter size buttons for this colour
                                var sizesForColor = [];
                                if (!HIDE_VARIANT_SIZE) {
                                    VARIANTS.forEach(function (v) {
                                        var vSize = String(v.size || '').trim();
                                        if (parseInt(v.is_active) === 1 && v.color === color && vSize !== '' && !(isSetUnit && isPackLikeSize(vSize))) {
                                            sizesForColor.push(v.size);
                                        }
                                    });
                                }
                                var hasSizes = sizesForColor.length > 0;
                                if (sizeSection) sizeSection.style.display = hasSizes ? '' : 'none';
                                sizeButtons.forEach(function (b) {
                                    var sz = b.getAttribute('data-size');
                                    var visible = sizesForColor.indexOf(sz) !== -1;
                                    b.style.display = visible ? '' : 'none';
                                    if (visible) {
                                        var match = findVariant(color, sz);
                                        var lbl = variantSizeLabel(match);
                                        if (lbl !== '') {
                                            b.textContent = lbl;
                                        }
                                    }
                                });
                                // Pick first valid size for this colour
                                var preferred = String(preferredSize || '').trim();
                                var firstSize = hasSizes ? sizesForColor[0] : '';
                                if (preferred !== '' && sizesForColor.indexOf(preferred) !== -1) {
                                    firstSize = preferred;
                                }
                                sizeButtons.forEach(function (b) {
                                    var sz = b.getAttribute('data-size');
                                    b.classList.toggle('btn-dark', hasSizes && sz === firstSize);
                                    b.classList.toggle('btn-outline-dark', !(hasSizes && sz === firstSize));
                                    b.setAttribute('aria-pressed', hasSizes && sz === firstSize ? 'true' : 'false');
                                });
                                updateVariantState(color, HIDE_VARIANT_SIZE ? '' : firstSize);
                            }

                            // Colour swatch click
                            colorSwatches.forEach(function (btn) {
                                btn.addEventListener('click', function () {
                                    activateColor(btn.getAttribute('data-color') || '', '');
                                });
                            });

                            // Size button click
                            sizeButtons.forEach(function (btn) {
                                btn.addEventListener('click', function () {
                                    if (btn.style.display === 'none') return;
                                    sizeButtons.forEach(function (b) {
                                        if (b.style.display !== 'none') {
                                            b.classList.remove('btn-dark');
                                            b.classList.add('btn-outline-dark');
                                            b.setAttribute('aria-pressed', 'false');
                                        }
                                    });
                                    btn.classList.remove('btn-outline-dark');
                                    btn.classList.add('btn-dark');
                                    btn.setAttribute('aria-pressed', 'true');
                                    updateVariantState(currentColor(), btn.getAttribute('data-size') || '');
                                });
                            });

                            // Initialise
                            if (VARIANTS.length > 0) {
                                activateColor(currentColor(), currentSize());
                            } else if (sizeButtons.length) {
                                // Legacy path: no DB variants, just sync hidden inputs
                                sizeButtons.forEach(function (btn) {
                                    btn.addEventListener('click', function () {
                                        var val = btn.getAttribute('data-size') || '';
                                        sizeButtons.forEach(function (b) {
                                            b.classList.remove('btn-dark');
                                            b.classList.add('btn-outline-dark');
                                            b.setAttribute('aria-pressed', 'false');
                                        });
                                        btn.classList.remove('btn-outline-dark');
                                        btn.classList.add('btn-dark');
                                        btn.setAttribute('aria-pressed', 'true');
                                        if (sizeAdd) sizeAdd.value = val;
                                        if (sizeBuy) sizeBuy.value = val;
                                    });
                                });
                            }
                            // ── /Variant-aware colour/size selection ─────────────────────────

                            var meterButtons = document.querySelectorAll('.meter-option-btn');
                            if (meterButtons.length) {
                                meterButtons.forEach(function (btn) {
                                    btn.addEventListener('click', function () {
                                        var val = parseFloat(btn.getAttribute('data-meters') || '0');
                                        if (!Number.isFinite(val) || val <= 0) return;
                                        meterButtons.forEach(function (b) {
                                            b.classList.remove('btn-primary');
                                            b.classList.add('btn-outline-primary');
                                            b.setAttribute('aria-pressed', 'false');
                                        });
                                        btn.classList.remove('btn-outline-primary');
                                        btn.classList.add('btn-primary');
                                        btn.setAttribute('aria-pressed', 'true');
                                        var normalized = val.toFixed(2).replace(/\.00$/, '').replace(/(\.\d)0$/, '$1');
                                        if (isMeterUnit) {
                                            if (meterLengthInput) {
                                                meterLengthInput.value = normalized;
                                            }
                                            if (buyNowMeterLength) {
                                                buyNowMeterLength.value = normalized;
                                            }
                                        } else if (qtyInput.tagName === 'SELECT') {
                                            var hasOption = false;
                                            for (var i = 0; i < qtyInput.options.length; i++) {
                                                if (qtyInput.options[i].value === normalized) {
                                                    hasOption = true;
                                                    break;
                                                }
                                            }
                                            if (hasOption) {
                                                qtyInput.value = normalized;
                                            }
                                        } else {
                                            qtyInput.value = normalized;
                                        }
                                        if (currentVariant) {
                                            updateVariantQuantity(currentVariant);
                                        } else {
                                            syncQty();
                                        }
                                    });
                                });
                            }

                        })();
                        </script>
                    <?php else: ?>
                        <div class="d-grid mt-2">
                            <button type="button" class="btn btn-outline-dark" disabled>Buy Now</button>
                        </div>
                    <?php endif; ?>
                </div>

                <?php if (!empty($product['description'])): ?>
                    <div class="mb-3">
                        <h6 class="fw-semibold">Description</h6>
                        <p class="mb-0"><?php echo nl2br(e((string) $product['description'])); ?></p>
                    </div>
                <?php endif; ?>

                <div class="mb-3">
                    <h6 class="fw-semibold">Fabric / Material</h6>
                    <p class="mb-0"><?php echo e($catalogData['attr_material'] !== '' ? $catalogData['attr_material'] : ($catalogData['attr_fabric'] !== '' ? $catalogData['attr_fabric'] : 'Not specified')); ?></p>
                </div>

                <?php if ($catalogData['attr_printing_type'] !== ''): ?>
                    <div class="mb-3">
                        <h6 class="fw-semibold">Printing Type</h6>
                        <p class="mb-0"><?php echo e($catalogData['attr_printing_type']); ?></p>
                    </div>
                <?php endif; ?>

                <div class="mb-3">
                    <h6 class="fw-semibold">Check Delivery</h6>
                    <form id="pdp_delivery_form" class="row g-2 mb-2 js-no-loading">
                        <?php echo csrf_field(); ?>
                        <input type="hidden" name="product_id" value="<?php echo (int) $product['id']; ?>">
                        <input type="hidden" name="variant_id" id="delivery_variant_id" value="<?php echo (int) ($defaultVariantId ?? 0); ?>">
                        <input type="hidden" name="quantity" id="delivery_quantity" value="1">
                        <div class="col-sm-7">
                            <input class="form-control" name="pincode" inputmode="numeric" maxlength="6" pattern="[1-9][0-9]{5}" placeholder="6-digit pincode" value="<?php echo e((string) ($_SESSION['delivery_pincode'] ?? '')); ?>" required>
                        </div>
                        <div class="col-sm-3">
                            <select class="form-select" name="payment_method" aria-label="Payment method">
                                <option value="cod">Cash on delivery</option>
                                <option value="razorpay">Prepaid</option>
                            </select>
                        </div>
                        <div class="col-sm-2 d-grid">
                            <button class="btn btn-outline-primary" type="submit">Check</button>
                        </div>
                    </form>
                    <div id="pdp_delivery_result" class="small mb-2" aria-live="polite"></div>
                    <script nonce="<?php echo e($GLOBALS['cspNonce'] ?? ''); ?>">
                    (function () {
                        var form = document.getElementById('pdp_delivery_form');
                        if (!form) return;
                        form.addEventListener('submit', async function (event) {
                            event.preventDefault();
                            var output = document.getElementById('pdp_delivery_result');
                            var submitButton = form.querySelector('[type="submit"]');
                            if (submitButton && submitButton.disabled) return;
                            var selectedVariant = document.getElementById('selected_variant_id_add');
                            var quantity = document.getElementById('meter_total_quantity') || document.getElementById('product_quantity');
                            var deliveryVariant = document.getElementById('delivery_variant_id');
                            var deliveryQuantity = document.getElementById('delivery_quantity');
                            if (deliveryVariant) deliveryVariant.value = selectedVariant ? selectedVariant.value : '0';
                            if (deliveryQuantity) deliveryQuantity.value = quantity ? quantity.value : '1';
                            output.textContent = 'Checking…';
                            if (submitButton) {
                                submitButton.classList.add('is-loading');
                                submitButton.disabled = true;
                            }
                            try {
                                var response = await fetch('/delivery-estimate', {method: 'POST', body: new FormData(form)});
                                var data = await response.json();
                                if (!data.ok) {
                                    output.textContent = data.message || 'Delivery estimate is unavailable.';
                                    return;
                                }
                                var parts = [
                                    (data.serviceability_status === 'live' ? 'Live courier rate' : 'Estimated shipping'),
                                    'Dispatch ' + data.estimated_dispatch_label,
                                    'Delivery ' + data.estimated_delivery_label,
                                    data.shipping_total > 0 ? 'Shipping ₹' + Number(data.shipping_total).toFixed(2) : 'Free shipping'
                                ];
                                if (data.payment_method === 'cod' && Number(data.cod_fee) > 0) {
                                    parts.push('includes COD fee ₹' + Number(data.cod_fee).toFixed(2));
                                }
                                if (data.courier_name) parts.push(data.courier_name);
                                output.textContent = parts.join(' · ');
                            } catch (error) {
                                output.textContent = 'Unable to check delivery right now.';
                            } finally {
                                if (submitButton) {
                                    submitButton.classList.remove('is-loading');
                                    submitButton.disabled = false;
                                }
                            }
                        });
                    }());
                    </script>
                    <h6 class="fw-semibold">Shipping Note</h6>
                    <p class="mb-0 text-muted">Shipping timelines vary by destination and order volume. Final timeline is shared at confirmation.</p>
                </div>

                <div class="mb-0">
                    <h6 class="fw-semibold">Return Policy</h6>
                    <p class="mb-2 text-muted">Refund returns are supported for eligible cases within <?php echo return_request_window_days(); ?> calendar days of confirmed delivery.</p>
                    <a href="return-policy.php" class="btn btn-sm btn-outline-secondary">View Return Policy</a>
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
