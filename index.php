<?php require_once 'includes/init.php'; ?>
<?php
$metaTitle = 'Home | Amber Fabrics';
$metaDescription = 'A fast-growing home textile startup for Bedsheets, Towels, and Table Covers. Shop in India and connect for bulk sourcing.';
$metaKeywords = 'home textiles, bedsheets, towels, table covers, ecommerce, bulk inquiry, Amber Fabrics';
include 'includes/header.php'; ?>

<?php
$siteSettings = get_site_settings();

// Latest 8 active fabrics — no filter/pagination on home page
$stmt = $conn->prepare(
    "SELECT
        f.id, f.name, f.image, f.material, f.size, f.unit_type, f.price, f.sale_price, f.price_inr,
        f.min_order_meters, f.stock, f.stock_meters, f.is_available,
        COALESCE(vs.active_variant_count, 0) AS active_variant_count
     FROM fabrics f
     LEFT JOIN (
        SELECT fabric_id, COUNT(*) AS active_variant_count
        FROM fabric_variants
        WHERE is_active = 1
        GROUP BY fabric_id
     ) vs ON vs.fabric_id = f.id
     WHERE f.status = 'active'
     ORDER BY f.created_at DESC
     LIMIT 8"
);
$stmt->execute();
$homeProducts = $stmt->get_result();
$homeProductRows = $homeProducts ? $homeProducts->fetch_all(MYSQLI_ASSOC) : [];

$homeProductIds = [];
foreach ($homeProductRows as $hpRow) {
    $pid = (int) ($hpRow['id'] ?? 0);
    if ($pid > 0) {
        $homeProductIds[] = $pid;
    }
}
$homeProductIds = array_values(array_unique($homeProductIds));

$homeVariantRowsByFabric = [];
if (!empty($homeProductIds)) {
    $variantPlaceholders = implode(',', array_fill(0, count($homeProductIds), '?'));
    $variantSql = "SELECT id, fabric_id, image, image2, image3, image4, stock, stock_meters, is_active, sort_order
                   FROM fabric_variants
                   WHERE is_active = 1 AND fabric_id IN ($variantPlaceholders)
                   ORDER BY fabric_id ASC, sort_order ASC, id ASC";
    $variantStmt = $conn->prepare($variantSql);
    $variantStmt->bind_param(str_repeat('i', count($homeProductIds)), ...$homeProductIds);
    $variantStmt->execute();
    $variantRows = $variantStmt->get_result()->fetch_all(MYSQLI_ASSOC);
    foreach ($variantRows as $vRow) {
        $fid = (int) ($vRow['fabric_id'] ?? 0);
        if ($fid <= 0) {
            continue;
        }
        if (!isset($homeVariantRowsByFabric[$fid])) {
            $homeVariantRowsByFabric[$fid] = [];
        }
        $homeVariantRowsByFabric[$fid][] = $vRow;
    }
}

// Active categories for the category grid
$homeCategoriesResult = $conn->query("SELECT id, name, slug, image FROM categories WHERE status = 'active' ORDER BY name ASC");
$homeCategories = $homeCategoriesResult ? $homeCategoriesResult->fetch_all(MYSQLI_ASSOC) : [];

$announcementMessages = [];
for ($i = 1; $i <= 5; $i++) {
    $textKey = 'announcement_' . $i . '_text';
    $enabledKey = 'announcement_' . $i . '_enabled';
    $text = trim((string) ($siteSettings[$textKey] ?? ''));
    $enabled = ((string) ($siteSettings[$enabledKey] ?? '0')) === '1';
    if ($enabled && $text !== '') {
        $announcementMessages[] = $text;
    }
}
$announcementKey = md5(implode('|', $announcementMessages));
?>

<!-- ═══════════════════════════════════════ -->
<!-- ANNOUNCEMENT BAR                       -->
<!-- ═══════════════════════════════════════ -->
<?php if (!empty($announcementMessages)): ?>
<div class="announce-bar" id="announceBar" data-announce-key="<?php echo e($announcementKey); ?>">
    <div class="announce-content">
        <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" fill="currentColor" viewBox="0 0 16 16" class="announce-icon" aria-hidden="true"><path d="M8 16A8 8 0 1 0 8 0a8 8 0 0 0 0 16zm.93-9.412-1 4.705c-.07.34.029.533.304.533.194 0 .487-.07.686-.246l-.088.416c-.287.346-.92.598-1.465.598-.703 0-1.002-.422-.808-1.319l.738-3.468c.064-.293.006-.399-.287-.47l-.451-.081.082-.381 2.29-.287zM8 5.5a1 1 0 1 1 0-2 1 1 0 0 1 0 2z"/></svg>
        <div class="announce-viewport" id="announceViewport" aria-live="polite">
            <div class="announce-track" id="announceTrack">
                <?php foreach ($announcementMessages as $message): ?>
                    <div class="announce-item"><?php echo e($message); ?></div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
    <button class="announce-close" id="announceClose" aria-label="Dismiss announcement">&#x2715;</button>
</div>
<?php endif; ?>

<!-- ═══════════════════════════════════════ -->
<!-- HERO                                   -->
<!-- ═══════════════════════════════════════ -->
<section class="hero-section hero-section--home">
    <div class="hero-pattern" aria-hidden="true"></div>
    <div class="container position-relative">
        <div class="row g-4 g-lg-5 align-items-center">
            <div class="col-lg-6 animate-in">
                <span class="badge-soft badge-soft--light mb-3 d-inline-block">Home Textile Startup</span>
                <h1 class="hero-home-title">Modern Home Textiles, Built for Everyday Living</h1>
                <p class="hero-home-desc mb-4">Amber Fabrics is a growing Indian startup focused on quality Bedsheets, Towels, and Table Covers for retail and bulk buyers.</p>
                <div class="hero-actions">
                    <a href="catalog.php" class="btn btn-light btn-hero">
                        <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="currentColor" viewBox="0 0 16 16" aria-hidden="true"><path d="M2.97 1.35A1 1 0 0 1 3.73 1h8.54a1 1 0 0 1 .76.35l2.609 3.044A1.5 1.5 0 0 1 16 5.37v.255a2.375 2.375 0 0 1-4.25 1.458A2.371 2.371 0 0 1 9.875 8 2.37 2.37 0 0 1 8 7.083 2.37 2.37 0 0 1 6.125 8a2.37 2.37 0 0 1-1.875-.917A2.375 2.375 0 0 1 0 5.625V5.37a1.5 1.5 0 0 1 .361-.976zm1.78 4.275a1.375 1.375 0 0 0 2.75 0 .5.5 0 0 1 1 0 1.375 1.375 0 0 0 2.75 0 .5.5 0 0 1 1 0 1.375 1.375 0 1 0 2.75 0V5.37a.5.5 0 0 0-.12-.325L12.27 2H3.73L1.12 5.045A.5.5 0 0 0 1 5.37v.255a1.375 1.375 0 0 0 2.75 0 .5.5 0 0 1 1 0zM1.5 8.5A.5.5 0 0 1 2 8h1a.5.5 0 0 1 .5.5V14h8V8.5A.5.5 0 0 1 12 8h1a.5.5 0 0 1 .5.5V15a1 1 0 0 1-1 1H3a1 1 0 0 1-1-1z"/></svg>
                        Shop in India
                    </a>
                    <a href="international-buyers.php" class="btn btn-outline-light btn-hero">
                        <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="currentColor" viewBox="0 0 16 16" aria-hidden="true"><path d="M0 8a8 8 0 1 1 16 0A8 8 0 0 1 0 8zm7.5-6.923c-.67.204-1.335.82-1.887 1.855A7.97 7.97 0 0 0 5.145 4H7.5V1.077zM4.09 4a9.267 9.267 0 0 1 .64-1.539 6.7 6.7 0 0 1 .597-.933A7.025 7.025 0 0 0 2.255 4H4.09zm-.582 3.5c.03-.877.138-1.718.312-2.5H1.674a6.958 6.958 0 0 0-.656 2.5h2.49zM4.847 5a12.5 12.5 0 0 0-.338 2.5H7.5V5H4.847zM8.5 5v2.5h2.99a12.495 12.495 0 0 0-.337-2.5H8.5zM4.51 8.5a12.5 12.5 0 0 0 .337 2.5H7.5V8.5H4.51zm3.99 0V11h2.653c.187-.765.306-1.608.338-2.5H8.5zM5.145 12c.138.386.295.744.468 1.068.552 1.035 1.218 1.65 1.887 1.855V12H5.145zm.182 2.472a6.696 6.696 0 0 1-.597-.933A9.268 9.268 0 0 1 4.09 12H2.255a7.024 7.024 0 0 0 3.072 2.472zM3.82 11a13.652 13.652 0 0 1-.312-2.5h-2.49c.062.89.291 1.733.656 2.5H3.82zm6.853 3.472A7.024 7.024 0 0 0 13.745 12H11.91a9.27 9.27 0 0 1-.64 1.539 6.688 6.688 0 0 1-.597.933zM8.5 12v2.923c.67-.204 1.335-.82 1.887-1.855.173-.324.33-.682.468-1.068H8.5zm3.68-1h2.146c.365-.767.594-1.61.656-2.5h-2.49a13.65 13.65 0 0 1-.312 2.5zm2.802-3.5a6.959 6.959 0 0 0-.656-2.5H12.18c.174.782.282 1.623.312 2.5h2.49zM11.27 2.461c.247.464.462.98.64 1.539h1.835a7.024 7.024 0 0 0-3.072-2.472c.218.284.418.598.597.933zM10.855 4a7.966 7.966 0 0 0-.468-1.068C9.835 1.897 9.17 1.282 8.5 1.077V4h2.355z"/></svg>
                        International / Bulk Inquiry
                    </a>
                </div>
            </div>
            <div class="col-lg-6 animate-in hero-swatch-col" aria-hidden="true">
                <div class="hero-swatch-grid">
                    <?php for ($i = 1; $i <= 6; $i++): ?>
                        <?php
                        $heroKey = 'hero_swatch_' . $i;
                        $heroPath = (string) ($siteSettings[$heroKey] ?? '');
                        $heroStyle = '';
                        if ($heroPath !== '') {
                            $heroStyle = "background-image:url('" . e('/' . ltrim($heroPath, '/')) . "');";
                        }
                        ?>
                        <div class="swatch swatch-<?php echo $i; ?>" style="<?php echo $heroStyle; ?>"></div>
                    <?php endfor; ?>
                </div>
            </div>
        </div>
    </div>
</section>

<!-- ═══════════════════════════════════════ -->
<!-- TRUST BAR                              -->
<!-- ═══════════════════════════════════════ -->
<!-- ═══════════════════════════════════════ -->
<!-- SHOP BY CATEGORY                       -->
<!-- ═══════════════════════════════════════ -->
<?php if (!empty($homeCategories)): ?>
<section class="section-block">
    <div class="container">
        <div class="section-head text-center mb-4">
            <h2 class="mb-2">Shop by Category</h2>
            <p class="text-muted mb-0">Discover our focused collection for modern homes and gifting</p>
        </div>
        <div class="slider-wrap" id="catSlider">
            <div class="slider-track cat-slider-track">
            <?php
            $catColors = ['#0f766e','#c77d2f','#17263d','#6d6875','#a4133c','#4361ee','#3a0ca3'];
            $ci = 0;
            foreach ($homeCategories as $cat):
                $bgColor = $catColors[$ci % count($catColors)];
                $ci++;
            ?>
            <a href="catalog.php?category=<?php echo e($cat['slug']); ?>" class="category-card" style="--cat-color: <?php echo $bgColor; ?>">
                <div class="category-card-img">
                    <?php if (!empty($cat['image'])): ?>
                        <img src="<?php echo e($cat['image']); ?>" alt="<?php echo e($cat['name']); ?>" loading="lazy">
                    <?php else: ?>
                        <div class="category-card-placeholder">
                            <svg xmlns="http://www.w3.org/2000/svg" width="30" height="30" fill="currentColor" viewBox="0 0 16 16" aria-hidden="true"><path d="M0 2a2 2 0 0 1 2-2h12a2 2 0 0 1 2 2v12a2 2 0 0 1-2 2H2a2 2 0 0 1-2-2V2zm15 2h-4v3h4V4zm0 4h-4v3h4V8zm0 4h-4v3h3a1 1 0 0 0 1-1v-2zm-5 3v-3H6v3h4zm-5 0v-3H1v2a1 1 0 0 0 1 1h3zm-4-4h4V8H1v3zm0-4h4V4H1v3zm5-3v3h4V4H6zm4 4H6v3h4V8z"/></svg>
                        </div>
                    <?php endif; ?>
                </div>
                <div class="category-card-label"><?php echo e($cat['name']); ?></div>
            </a>
            <?php endforeach; ?>
            </div>
        </div>
    </div>
</section>
<?php endif; ?>

<!-- ═══════════════════════════════════════ -->
<!-- NEW ARRIVALS                           -->
<!-- ═══════════════════════════════════════ -->
<section class="section-block section-block--alt">
    <div class="container">
        <div class="section-head d-flex justify-content-between align-items-end mb-4">
            <div>
                <h2 class="mb-1">Latest Drops</h2>
                <p class="mb-0 text-muted">Newly launched designs across our fast-growing range</p>
            </div>
            <a href="catalog.php" class="btn btn-outline-primary btn-sm">View All</a>
        </div>

        <div class="slider-wrap" id="prodSlider">
            <div class="slider-track prod-slider-track">
            <?php if (empty($homeProductRows)): ?>
                <div class="surface-panel text-center text-muted py-5 px-4">
                    No products added yet. <a href="catalog.php">Browse catalog</a>
                </div>
            <?php endif; ?>

            <?php foreach ($homeProductRows as $row): ?>
            <?php
                $unitType = in_array((string) ($row['unit_type'] ?? ''), ['meter', 'piece', 'set'], true)
                    ? (string) $row['unit_type']
                    : 'meter';
                $activeVariantCount = (int) ($row['active_variant_count'] ?? 0);
                $hasActiveVariants = $activeVariantCount > 0;
                $displayStock = $unitType === 'meter' ? (float) ($row['stock_meters'] ?? 0) : (float) ($row['stock'] ?? 0);
                $hasSellableVariant = false;
                $variantRows = $homeVariantRowsByFabric[(int) ($row['id'] ?? 0)] ?? [];
                $firstVariantImage = '';
                $firstInStockVariantImage = '';

                if ($hasActiveVariants) {
                    $displayStock = 0.0;
                    foreach ($variantRows as $variantRow) {
                        $variantStock = ($unitType === 'meter')
                            ? (float) ($variantRow['stock_meters'] ?? 0)
                            : (float) ($variantRow['stock'] ?? 0);
                        $displayStock += max(0.0, $variantStock);
                        $variantImage = '';
                        foreach (['image', 'image2', 'image3', 'image4'] as $ik) {
                            $cand = trim((string) ($variantRow[$ik] ?? ''));
                            if ($cand !== '') {
                                $variantImage = $cand;
                                break;
                            }
                        }
                        if ($firstVariantImage === '' && $variantImage !== '') {
                            $firstVariantImage = $variantImage;
                        }
                        if ($variantStock > 0) {
                            $hasSellableVariant = true;
                            if ($firstInStockVariantImage === '' && $variantImage !== '') {
                                $firstInStockVariantImage = $variantImage;
                            }
                        }
                    }
                }

                $cardImage = (string) ($row['image'] ?? '');
                if ($cardImage === '') {
                    $cardImage = ($firstInStockVariantImage !== '') ? $firstInStockVariantImage : $firstVariantImage;
                }
                $cardImageAsset = $cardImage !== '' ? fabric_image_asset_data($cardImage) : null;
                $cardIsInStock = !empty($row['is_available']) && ($hasActiveVariants ? $hasSellableVariant : ($displayStock > 0));
                $hasSizeOptions = !empty(parse_size_options((string) ($row['size'] ?? '')));
                $needsVariantSelection = $activeVariantCount > 1;
            ?>
            <div class="prod-slide">
                <article class="card h-100 product-click-card" data-href="fabric.php?id=<?php echo (int)$row['id']; ?>">
                    <div class="fabric-thumb-wrap">
                        <?php if ($cardImage !== ''): ?>
                            <picture>
                                <?php if (!empty($cardImageAsset['webp_srcset'])): ?>
                                    <source type="image/webp" srcset="<?php echo e($cardImageAsset['webp_srcset']); ?>" sizes="(max-width: 767px) 80vw, 280px">
                                <?php endif; ?>
                                <img src="<?php echo e((string) ($cardImageAsset['thumb_src'] ?? '')); ?>" class="fabric-thumb" alt="<?php echo e($row['name']); ?>" loading="lazy">
                            </picture>
                        <?php else: ?>
                            <div class="fabric-thumb-empty">No image</div>
                        <?php endif; ?>
                        <?php if (!$cardIsInStock): ?>
                            <div class="fabric-out-overlay">Out of Stock</div>
                        <?php endif; ?>
                    </div>
                    <div class="card-body d-flex flex-column">
                        <?php if (!empty($row['material'])): ?>
                            <p class="fabric-card-category"><?php echo e($row['material']); ?></p>
                        <?php endif; ?>
                        <p class="fabric-card-title"><?php echo e($row['name']); ?></p>
                        <?php
                            $cardRegular = (float) (($row['price'] !== null && $row['price'] !== '') ? $row['price'] : ($row['price_inr'] ?? 0));
                            $cardSale    = (float) ($row['sale_price'] ?? 0);
                        ?>
                        <?php if ($cardRegular > 0 || $cardSale > 0): ?>
                            <div class="fabric-price mb-2">
                                <?php if ($cardSale > 0 && $cardRegular > 0 && $cardSale < $cardRegular): ?>
                                    <span class="price-inr fw-bold">Rs <?php echo number_format($cardSale, 2); ?></span>
                                    <span class="text-muted small ms-1"><del>Rs <?php echo number_format($cardRegular, 2); ?></del></span>
                                <?php elseif ($cardRegular > 0): ?>
                                    <span class="price-inr">Rs <?php echo number_format($cardRegular, 2); ?>/m</span>
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>
                        <div class="d-flex gap-1 mt-auto">
                            <?php if ($cardIsInStock): ?>
                                <?php if ($unitType === 'meter'): ?>
                                    <a href="fabric.php?id=<?php echo (int)$row['id']; ?>" class="btn btn-primary btn-sm flex-grow-1">View Options</a>
                                <?php elseif ($needsVariantSelection): ?>
                                    <a href="fabric.php?id=<?php echo (int)$row['id']; ?>" class="btn btn-primary btn-sm flex-grow-1">View Options</a>
                                <?php elseif ($hasSizeOptions): ?>
                                    <a href="fabric.php?id=<?php echo (int)$row['id']; ?>" class="btn btn-primary btn-sm flex-grow-1">View Options</a>
                                <?php else: ?>
                                    <button class="btn btn-primary btn-sm flex-grow-1 add-to-cart-btn"
                                        data-fabric-id="<?php echo (int)$row['id']; ?>"
                                        data-name="<?php echo e($row['name']); ?>"
                                        data-min="<?php echo (int)$row['min_order_meters']; ?>">
                                        Add to Cart
                                    </button>
                                <?php endif; ?>
                            <?php else: ?>
                                <button class="btn btn-secondary btn-sm flex-grow-1" disabled>Unavailable</button>
                            <?php endif; ?>
                        </div>
                    </div>
                </article>
            </div>
            <?php endforeach; ?>
            </div>
        </div>

        <div class="text-center mt-4">
            <a href="catalog.php" class="btn btn-primary btn-lg px-5">
                Browse Full Collection
                <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="currentColor" class="ms-2" viewBox="0 0 16 16" aria-hidden="true"><path fill-rule="evenodd" d="M1 8a.5.5 0 0 1 .5-.5h11.793l-3.147-3.146a.5.5 0 0 1 .708-.708l4 4a.5.5 0 0 1 0 .708l-4 4a.5.5 0 0 1-.708-.708L13.293 8.5H1.5A.5.5 0 0 1 1 8z"/></svg>
            </a>
        </div>
    </div>
</section>

<!-- ═══════════════════════════════════════ -->
<!-- WHY CHOOSE US                          -->
<!-- ═══════════════════════════════════════ -->
<section class="section-block">
    <div class="container">
        <div class="section-head text-center mb-4">
            <h2 class="mb-2">Why Choose Amber Fabrics</h2>
            <p class="text-muted mb-0">Startup speed with dependable quality and fulfillment</p>
        </div>
        <div class="why-grid">
            <div class="why-card animate-in">
                <div class="why-icon">
                    <svg xmlns="http://www.w3.org/2000/svg" width="26" height="26" fill="currentColor" viewBox="0 0 16 16" aria-hidden="true"><path d="M9.669.864 8 0 6.331.864l-1.858.282-.842 1.68-1.337 1.32L2.6 6l-.306 1.854 1.337 1.32.842 1.68 1.858.282L8 12l1.669-.864 1.858-.282.842-1.68 1.337-1.32L13.4 6l.306-1.854-1.337-1.32-.842-1.68L9.669.864zm1.196 1.193.684 1.365 1.086 1.072L12.387 6l.248 1.506-1.086 1.072-.684 1.365-1.51.229L8 11l-1.355-.828-1.51-.229-.684-1.365-1.086-1.072L3.612 6l-.248-1.506 1.086-1.072.684-1.365 1.51-.229L8 1l1.355.828 1.51.229z"/><path d="M4 11.794V16l4-1 4 1v-4.206l-2.018.306L8 13.126 6.018 12.1 4 11.794z"/></svg>
                </div>
                <h4>Consistent Quality</h4>
                <p class="mb-0">Every batch is checked for finish, stitching standards, and fabric consistency before dispatch.</p>
            </div>
            <div class="why-card animate-in">
                <div class="why-icon">
                    <svg xmlns="http://www.w3.org/2000/svg" width="26" height="26" fill="currentColor" viewBox="0 0 16 16" aria-hidden="true"><path d="M0 1.5A.5.5 0 0 1 .5 1H2a.5.5 0 0 1 .485.379L2.89 3H14.5a.5.5 0 0 1 .491.592l-1.5 8A.5.5 0 0 1 13 12H4a.5.5 0 0 1-.491-.408L2.01 3.607 1.61 2H.5a.5.5 0 0 1-.5-.5zM3.102 4l1.313 7h8.17l1.313-7H3.102zM5 12a2 2 0 1 0 0 4 2 2 0 0 0 0-4zm7 0a2 2 0 1 0 0 4 2 2 0 0 0 0-4zm-7 1a1 1 0 1 1 0 2 1 1 0 0 1 0-2zm7 0a1 1 0 1 1 0 2 1 1 0 0 1 0-2z"/></svg>
                </div>
                <h4>Startup-Friendly MOQ</h4>
                <p class="mb-0">Built for growing sellers and boutiques with practical order quantities and quick restocks.</p>
            </div>
            <div class="why-card animate-in">
                <div class="why-icon">
                    <svg xmlns="http://www.w3.org/2000/svg" width="26" height="26" fill="currentColor" viewBox="0 0 16 16" aria-hidden="true"><path d="M0 8a8 8 0 1 1 16 0A8 8 0 0 1 0 8zm7.5-6.923c-.67.204-1.335.82-1.887 1.855A7.97 7.97 0 0 0 5.145 4H7.5V1.077zM4.09 4a9.267 9.267 0 0 1 .64-1.539 6.7 6.7 0 0 1 .597-.933A7.025 7.025 0 0 0 2.255 4H4.09zm-.582 3.5c.03-.877.138-1.718.312-2.5H1.674a6.958 6.958 0 0 0-.656 2.5h2.49zM4.847 5a12.5 12.5 0 0 0-.338 2.5H7.5V5H4.847zM8.5 5v2.5h2.99a12.495 12.495 0 0 0-.337-2.5H8.5zM4.51 8.5a12.5 12.5 0 0 0 .337 2.5H7.5V8.5H4.51zm3.99 0V11h2.653c.187-.765.306-1.608.338-2.5H8.5zM5.145 12c.138.386.295.744.468 1.068.552 1.035 1.218 1.65 1.887 1.855V12H5.145zm.182 2.472a6.696 6.696 0 0 1-.597-.933A9.268 9.268 0 0 1 4.09 12H2.255a7.024 7.024 0 0 0 3.072 2.472zM3.82 11a13.652 13.652 0 0 1-.312-2.5h-2.49c.062.89.291 1.733.656 2.5H3.82zm6.853 3.472A7.024 7.024 0 0 0 13.745 12H11.91a9.27 9.27 0 0 1-.64 1.539 6.688 6.688 0 0 1-.597.933zM8.5 12v2.923c.67-.204 1.335-.82 1.887-1.855.173-.324.33-.682.468-1.068H8.5zm3.68-1h2.146c.365-.767.594-1.61.656-2.5h-2.49a13.65 13.65 0 0 1-.312 2.5zm2.802-3.5a6.959 6.959 0 0 0-.656-2.5H12.18c.174.782.282 1.623.312 2.5h2.49zM11.27 2.461c.247.464.462.98.64 1.539h1.835a7.024 7.024 0 0 0-3.072-2.472c.218.284.418.598.597.933zM10.855 4a7.966 7.966 0 0 0-.468-1.068C9.835 1.897 9.17 1.282 8.5 1.077V4h2.355z"/></svg>
                </div>
                <h4>Bulk &amp; B2B Ready</h4>
                <p class="mb-0">From sample discussions to larger purchase planning, our team supports smooth B2B coordination.</p>
            </div>
            <div class="why-card animate-in">
                <div class="why-icon">
                    <svg xmlns="http://www.w3.org/2000/svg" width="26" height="26" fill="currentColor" viewBox="0 0 16 16" aria-hidden="true"><path d="M0 3.5C0 2.119 1.119 1 2.5 1h.258a.5.5 0 0 1 .32.115l.637.54A1.5 1.5 0 0 0 4.678 2H14.5A1.5 1.5 0 0 1 16 3.5v8a1.5 1.5 0 0 1-1.5 1.5H2.5A1.5 1.5 0 0 1 1 11.5V7h1v4.5a.5.5 0 0 0 .5.5h12a.5.5 0 0 0 .5-.5v-8a.5.5 0 0 0-.5-.5H4.678a2.5 2.5 0 0 1-1.609-.585L2.5 2.012A.5.5 0 0 0 2.258 2H2.5A1.5 1.5 0 0 0 1 3.5v1H0v-1z"/><path d="M4.5 12a.5.5 0 0 0 0 1H14a.5.5 0 0 0 0-1H4.5zm-1-5a.5.5 0 0 0 0 1H14a.5.5 0 0 0 0-1H3.5zm2-2a.5.5 0 0 0 0 1H14a.5.5 0 0 0 0-1H5.5z"/></svg>
                </div>
                <h4>Fast Dispatch</h4>
                <p class="mb-0">Ready stock is shipped quickly so your shelf and online listings stay active without long waits.</p>
            </div>
        </div>
    </div>
</section>

<!-- ═══════════════════════════════════════ -->
<!-- INTERNATIONAL BUYERS CTA BANNER        -->
<!-- ═══════════════════════════════════════ -->
<section class="intl-cta">
    <div class="container">
        <div class="row align-items-center g-4">
            <div class="col-lg-8">
                <p class="intl-cta-eyebrow">B2B Growth Partner</p>
                <h2 class="intl-cta-heading">Need Reliable Home Textile Supply?</h2>
                <p class="intl-cta-desc">Share your quantity, target price, and delivery timeline. Our team will get back with practical sourcing options.</p>
            </div>
            <div class="col-lg-4 d-flex flex-column flex-sm-row flex-lg-column gap-3">
                <a href="international-buyers.php" class="btn btn-light btn-lg">Bulk / Export Inquiry</a>
                <a href="international-buyers.php" class="btn btn-outline-light btn-lg">Contact Our Team</a>
            </div>
        </div>
    </div>
</section>

<!-- ═══════════════════════════════════════ -->
<!-- TESTIMONIALS                           -->
<!-- ═══════════════════════════════════════ -->
<section class="section-block">
    <div class="container">
        <div class="section-head text-center mb-4">
            <h2 class="mb-2">What Our Buyers Say</h2>
            <p class="text-muted mb-0">Feedback from early customers and growing retail partners</p>
        </div>
        <div class="testimonials-grid">
            <div class="testimonial-card animate-in">
                <div class="testimonial-stars" aria-label="5 out of 5 stars">&#9733;&#9733;&#9733;&#9733;&#9733;</div>
                <p class="testimonial-text">"The bedsheet quality is premium for the price point. Finishing was clean, packing was neat, and repeat order process was smooth."</p>
                <div class="testimonial-author">
                    <div class="testimonial-avatar">P</div>
                    <div>
                        <div class="testimonial-name">Priya Mehta</div>
                        <div class="testimonial-location">Mumbai Retail Buyer</div>
                    </div>
                </div>
            </div>
            <div class="testimonial-card animate-in">
                <div class="testimonial-stars" aria-label="5 out of 5 stars">&#9733;&#9733;&#9733;&#9733;&#9733;</div>
                <p class="testimonial-text">"We sourced table covers in bulk for our marketplace store. Team communication was quick, and the dispatch timeline matched what was promised."</p>
                <div class="testimonial-author">
                    <div class="testimonial-avatar">C</div>
                    <div>
                        <div class="testimonial-name">Chirag Arora</div>
                        <div class="testimonial-location">D2C Seller, Delhi</div>
                    </div>
                </div>
            </div>
            <div class="testimonial-card animate-in">
                <div class="testimonial-stars" aria-label="4 out of 5 stars">&#9733;&#9733;&#9733;&#9733;&#9734;</div>
                <p class="testimonial-text">"Started with a small towel order and scaled in weeks. MOQ flexibility and consistent product quality helped us grow without inventory stress."</p>
                <div class="testimonial-author">
                    <div class="testimonial-avatar">A</div>
                    <div>
                        <div class="testimonial-name">Ayesha Khan</div>
                        <div class="testimonial-location">Boutique Owner, Bengaluru</div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</section>

<?php include 'includes/footer.php'; ?>

