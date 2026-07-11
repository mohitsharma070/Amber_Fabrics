<?php

function product_card_select_columns(array $extraColumns = []): string
{
    $columns = [
        'f.id',
        'f.name',
        'f.category',
        'f.image',
        'f.material',
        'f.size',
        'f.unit_type',
        'f.price',
        'f.sale_price',
        'f.price_inr',
        'f.stock',
        'f.stock_meters',
        'f.is_available',
        'f.dispatch_time',
        'f.created_at',
        'COALESCE(v.id, 0) AS variant_id',
        "COALESCE(v.color, '') AS variant_color",
        "COALESCE(v.size, '') AS variant_size",
        'COALESCE(v.price_override, 0) AS variant_price_override',
        'COALESCE(v.stock, 0) AS variant_stock',
        'COALESCE(v.stock_meters, 0) AS variant_stock_meters',
        "COALESCE(v.image, '') AS variant_image",
        "COALESCE(v.image2, '') AS variant_image2",
        "COALESCE(v.image3, '') AS variant_image3",
        "COALESCE(v.image4, '') AS variant_image4",
        "COALESCE(v.pack_label, '') AS variant_pack_label",
        'COALESCE(v.units_per_set, 0) AS variant_units_per_set',
    ];

    foreach ($extraColumns as $extraColumn) {
        $extraColumn = trim((string) $extraColumn);
        if ($extraColumn !== '') {
            $columns[] = $extraColumn;
        }
    }

    return implode(",\n                ", $columns);
}

function product_card_build_context(mysqli $conn, array $row): array
{
    $regularPrice = (float) (($row['price'] !== null && $row['price'] !== '') ? $row['price'] : ($row['price_inr'] ?? 0));
    $salePrice = (float) ($row['sale_price'] ?? 0);
    $variantId = (int) ($row['variant_id'] ?? 0);
    $variantColor = trim((string) ($row['variant_color'] ?? ''));
    $variantRawSize = trim((string) ($row['variant_size'] ?? ''));
    $variantPackLabel = trim((string) ($row['variant_pack_label'] ?? ''));
    $variantUnitsPerSet = (int) ($row['variant_units_per_set'] ?? 0);
    $unitType = in_array((string) ($row['unit_type'] ?? ''), ['meter', 'piece', 'set'], true) ? (string) $row['unit_type'] : 'meter';

    $variantSizeLabel = '';
    if ($variantId > 0) {
        $variantSizeLabel = CartService::variant_size_display([
            'size' => $variantRawSize,
            'pack_label' => $variantPackLabel,
            'units_per_set' => $variantUnitsPerSet,
        ], $unitType);
    }

    $variantTitleParts = [];
    if ($variantColor !== '' && strtolower($variantColor) !== 'default') {
        $variantTitleParts[] = $variantColor;
    }
    if ($variantSizeLabel !== '') {
        $variantTitleParts[] = $variantSizeLabel;
    }
    $displayName = (string) ($row['name'] ?? '');
    if ($variantId > 0 && !empty($variantTitleParts)) {
        $displayName .= ' - ' . implode(' / ', $variantTitleParts);
    }

    $imageCandidates = [];
    if ($variantId > 0) {
        $imageCandidates[] = (string) ($row['variant_image'] ?? '');
        $imageCandidates[] = (string) ($row['variant_image2'] ?? '');
        $imageCandidates[] = (string) ($row['variant_image3'] ?? '');
        $imageCandidates[] = (string) ($row['variant_image4'] ?? '');
    }
    $imageCandidates[] = (string) ($row['image'] ?? '');

    $cardImage = '';
    foreach ($imageCandidates as $candidateImage) {
        $candidateImage = trim((string) $candidateImage);
        if ($candidateImage !== '') {
            $cardImage = $candidateImage;
            break;
        }
    }

    $cardImageAsset = $cardImage !== '' ? fabric_image_asset_data($cardImage) : null;

    if ($variantId > 0) {
        $displayStock = ($unitType === 'piece' || $unitType === 'set')
            ? (float) ($row['variant_stock'] ?? 0)
            : (float) ($row['variant_stock_meters'] ?? 0);
    } else {
        $displayStock = ($unitType === 'piece' || $unitType === 'set') ? (float) ($row['stock'] ?? 0) : (float) ($row['stock_meters'] ?? 0);
    }
    $inStock = !empty($row['is_available']) && $displayStock > 0;

    $variantPriceOverride = ($variantId > 0) ? (float) ($row['variant_price_override'] ?? 0) : 0.0;
    if ($variantPriceOverride > 0) {
        $unitPrice = $variantPriceOverride;
    } else {
        $unitPrice = ($salePrice > 0 && $regularPrice > 0 && $salePrice < $regularPrice) ? $salePrice : $regularPrice;
    }
    $showStrikePrice = $regularPrice > 0 && $unitPrice > 0 && $unitPrice < $regularPrice;

    $hasSizeOptions = !empty(CartService::parse_size_options((string) ($row['size'] ?? '')));
    $productUrl = 'fabric.php?id=' . (int) ($row['id'] ?? 0);
    if ($variantId > 0) {
        $productUrl .= '&variant=' . $variantId;
    }

    if (!$inStock) {
        $ctaMode = 'unavailable';
    } elseif ($unitType === 'meter') {
        $ctaMode = 'view_options';
    } elseif ($variantId > 0) {
        $ctaMode = 'add_variant';
    } elseif ($hasSizeOptions) {
        $ctaMode = 'view_options';
    } else {
        $ctaMode = 'add_simple';
    }

    return [
        'row' => $row,
        'product_id' => (int) ($row['id'] ?? 0),
        'variant_id' => $variantId,
        'variant_color' => $variantColor,
        'variant_size_label' => $variantSizeLabel,
        'display_name' => $displayName,
        'category' => (string) ($row['category'] ?? ''),
        'product_url' => $productUrl,
        'card_image' => $cardImage,
        'card_image_asset' => $cardImageAsset,
        'unit_type' => $unitType,
        'regular_price' => $regularPrice,
        'unit_price' => $unitPrice,
        'show_strike_price' => $showStrikePrice,
        'has_size_options' => $hasSizeOptions,
        'in_stock' => $inStock,
        'stock' => $displayStock,
        'cta_mode' => $ctaMode,
        'badges' => [],
    ];
}

function product_card_render(array $card): string
{
    $row = is_array($card['row'] ?? null) ? $card['row'] : [];
    $productUrl = (string) ($card['product_url'] ?? '');
    $displayName = (string) ($card['display_name'] ?? '');
    $cardImage = (string) ($card['card_image'] ?? '');
    $cardImageAsset = is_array($card['card_image_asset'] ?? null) ? $card['card_image_asset'] : null;
    $inStock = !empty($card['in_stock']);
    $unitType = (string) ($card['unit_type'] ?? 'meter');
    $unitPrice = (float) ($card['unit_price'] ?? 0);
    $regularPrice = (float) ($card['regular_price'] ?? 0);
    $showStrikePrice = !empty($card['show_strike_price']);
    $variantId = (int) ($card['variant_id'] ?? 0);
    $variantColor = (string) ($card['variant_color'] ?? '');
    $variantSizeLabel = (string) ($card['variant_size_label'] ?? '');
    $ctaMode = (string) ($card['cta_mode'] ?? 'unavailable');
    $badges = is_array($card['badges'] ?? null) ? $card['badges'] : [];

    ob_start();
    ?>
    <div class="animate-in">
        <article class="card h-100 product-click-card" data-href="<?php echo e($productUrl); ?>">
            <div class="fabric-thumb-wrap">
                <a href="<?php echo e($productUrl); ?>" class="fabric-thumb-link" aria-label="View <?php echo e($displayName); ?>">
                    <?php if ($cardImage !== ''): ?>
                        <picture>
                            <?php if (!empty($cardImageAsset['webp_srcset'])): ?>
                                <source type="image/webp" srcset="<?php echo e($cardImageAsset['webp_srcset']); ?>" sizes="(max-width: 767px) 80vw, (max-width: 1199px) 40vw, 320px">
                            <?php endif; ?>
                            <img src="<?php echo e((string) ($cardImageAsset['src'] ?? '')); ?>" class="fabric-thumb" alt="<?php echo e($displayName); ?>" loading="lazy" width="600" height="800">
                        </picture>
                    <?php else: ?>
                        <div class="fabric-thumb-empty">No image</div>
                    <?php endif; ?>
                </a>
                <?php if (!$inStock): ?>
                    <div class="fabric-out-overlay">Out of Stock</div>
                <?php endif; ?>
                <?php foreach ($badges as $productCardBadge): ?>
                    <?php
                    if (!is_array($productCardBadge)) {
                        continue;
                    }
                    $productCardBadgeLabel = trim((string) ($productCardBadge['label'] ?? ''));
                    if ($productCardBadgeLabel === '') {
                        continue;
                    }
                    $productCardBadgeClass = preg_replace('/[^A-Za-z0-9 _:-]/', '', (string) ($productCardBadge['class'] ?? '')) ?? '';
                    ?>
                    <div class="<?php echo e(trim('badge bg-secondary product-card-plugin-badge ' . $productCardBadgeClass)); ?>"><?php echo e($productCardBadgeLabel); ?></div>
                <?php endforeach; ?>
            </div>

            <div class="card-body d-flex flex-column">
                <?php if (!empty($card['category'])): ?>
                    <p class="fabric-card-category"><?php echo e((string) $card['category']); ?></p>
                <?php endif; ?>
                <a href="<?php echo e($productUrl); ?>" class="fabric-card-title-link">
                    <p class="fabric-card-title"><?php echo e($displayName); ?></p>
                </a>

                <div class="fabric-price mb-2">
                    <?php if ($showStrikePrice): ?>
                        <span class="price-inr fw-bold"><?php echo e(money($unitPrice)); ?></span>
                        <span class="text-muted small ms-1"><del><?php echo e(money($regularPrice)); ?></del></span>
                    <?php elseif ($unitPrice > 0): ?>
                        <span class="price-inr"><?php echo e(money($unitPrice)); ?><?php echo ($unitType === 'piece' || $unitType === 'set') ? ' each' : '/m'; ?></span>
                    <?php else: ?>
                        <span class="text-muted small">Price on request</span>
                    <?php endif; ?>
                </div>
                <p class="fabric-trust-note">Fast dispatch | Quality checked</p>

                <div class="d-flex gap-1 mt-auto">
                    <?php if ($ctaMode === 'view_options'): ?>
                        <a href="<?php echo e($productUrl); ?>" class="btn btn-primary btn-sm flex-grow-1">View Options</a>
                    <?php elseif ($ctaMode === 'add_variant'): ?>
                        <form method="POST" action="/add-to-cart.php" class="flex-grow-1">
                            <?php echo csrf_field(); ?>
                            <input type="hidden" name="product_id" value="<?php echo (int) ($card['product_id'] ?? 0); ?>">
                            <input type="hidden" name="variant_id" value="<?php echo $variantId; ?>">
                            <input type="hidden" name="selected_color" value="<?php echo e($variantColor); ?>">
                            <input type="hidden" name="selected_size" value="<?php echo e($variantSizeLabel); ?>">
                            <input type="hidden" name="quantity" value="1">
                            <button type="submit" class="btn btn-primary btn-sm w-100">Add to Cart</button>
                        </form>
                    <?php elseif ($ctaMode === 'add_simple'): ?>
                        <form method="POST" action="/add-to-cart.php" class="flex-grow-1">
                            <?php echo csrf_field(); ?>
                            <input type="hidden" name="product_id" value="<?php echo (int) ($card['product_id'] ?? 0); ?>">
                            <input type="hidden" name="quantity" value="1">
                            <button type="submit" class="btn btn-primary btn-sm w-100">Add to Cart</button>
                        </form>
                    <?php else: ?>
                        <button type="button" class="btn btn-secondary btn-sm flex-grow-1" disabled>Unavailable</button>
                    <?php endif; ?>
                </div>
            </div>
        </article>
    </div>
    <?php
    return (string) ob_get_clean();
}
