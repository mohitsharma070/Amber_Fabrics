<?php require_once 'includes/init.php'; ?>
<?php
$metaTitle = SiteContext::title('Shop');
$metaDescription = 'Shop premium Bedsheets, Towels, and Table Covers from ' . SiteContext::name() . '.';
$metaKeywords = 'shop home textiles, bedsheets, towels, table covers, ' . SiteContext::name();
include 'includes/header.php';

$perPageOptions = [10, 20, 30];
$sortMap = [
    'newest' => 'f.created_at DESC, f.id DESC, COALESCE(v.id, 0) DESC',
    'oldest' => 'f.created_at ASC, f.id ASC, COALESCE(v.id, 0) ASC',
    'name_asc' => 'f.name ASC, f.id ASC, COALESCE(v.id, 0) ASC',
    'name_desc' => 'f.name DESC, f.id DESC, COALESCE(v.id, 0) DESC',
    'price_asc' => 'effective_price ASC, f.id ASC, COALESCE(v.id, 0) ASC',
    'price_desc' => 'effective_price DESC, f.id DESC, COALESCE(v.id, 0) DESC',
];
$sellableCategorySlugs = locked_storefront_category_slugs();
$sellablePlaceholders = implode(',', array_fill(0, count($sellableCategorySlugs), '?'));

$categories = storefront_categories_fetch($conn);

$state = [
    'q' => trim((string) ($_GET['q'] ?? '')),
    'category' => trim((string) ($_GET['category'] ?? '')),
    'min_price' => max(0, (int) ($_GET['min_price'] ?? 0)),
    'max_price' => max(0, (int) ($_GET['max_price'] ?? 0)),
    'in_stock' => (string) ($_GET['in_stock'] ?? ''),
    'material' => trim((string) ($_GET['material'] ?? '')),
    'color' => trim((string) ($_GET['color'] ?? '')),
    'size' => trim((string) ($_GET['size'] ?? '')),
    'dispatch' => trim((string) ($_GET['dispatch'] ?? '')),
    'sort' => list_sanitize_sort(trim((string) ($_GET['sort'] ?? 'newest')), $sortMap),
    'per_page' => list_sanitize_per_page((int) ($_GET['per_page'] ?? $perPageOptions[0]), $perPageOptions),
    'page' => list_sanitize_page((int) ($_GET['page'] ?? 1)),
    'cursor' => trim((string) ($_GET['cursor'] ?? '')),
];
$search = $state['q'];
$category = $state['category'];
if ($category !== '' && !in_array($category, $sellableCategorySlugs, true)) {
    $category = '';
    $state['category'] = '';
}
$sort = $state['sort'];
$perPage = $state['per_page'];
$page = $state['page'];
$offset = ($page - 1) * $perPage;
$orderBy = $sortMap[$sort];
$minPrice = (int) ($state['min_price'] ?? 0);
$maxPrice = (int) ($state['max_price'] ?? 0);
$inStock = ($state['in_stock'] === '1') ? '1' : '';
$materialFilter = $state['material'];
$colorFilter = $state['color'];
$sizeFilter = $state['size'];
$dispatchFilter = $state['dispatch'];
$cursor = $state['cursor'];
$debugExplainAuthorized = (($GLOBALS['_app_mode'] ?? '') === 'local');
$debugExplainRequested = (string) ($_GET['debug_explain'] ?? '') === '1';
$debugExplain = $debugExplainAuthorized && $debugExplainRequested;
if ($maxPrice > 0 && $maxPrice < $minPrice) {
    $maxPrice = $minPrice;
    $state['max_price'] = $maxPrice;
}
$effectivePriceExpr = "LEAST(
    CASE
        WHEN COALESCE(v.price_override, 0) > 0 THEN v.price_override
        WHEN COALESCE(f.sale_price, 0) > 0 AND f.sale_price < COALESCE(NULLIF(f.price, 0), 99999999) THEN f.sale_price
        ELSE COALESCE(NULLIF(f.price, 0), 99999999)
    END,
    99999999
)";

// Keep joins sargable and index-friendly; avoid variant subquery materialization.
$fromSql = "FROM fabrics f
            LEFT JOIN fabric_variants v
              ON v.fabric_id = f.id
             AND v.is_active = 1";

$where = ["f.status = 'active'", "f.category IN ($sellablePlaceholders)"];
$types = '';
$params = $sellableCategorySlugs;
$types .= str_repeat('s', count($sellableCategorySlugs));

if ($search !== '') {
    $where[] = "(f.name LIKE ? OR f.sku LIKE ? OR f.catalog_data LIKE ? OR v.color LIKE ? OR v.size LIKE ? OR v.sku LIKE ?)";
    $like = "%{$search}%";
    $types .= 'ssssss';
    for ($i = 0; $i < 6; $i++) $params[] = $like;
}
if ($category !== '') {
    $where[] = "f.category = ?";
    $types .= 's';
    $params[] = $category;
}
if ($minPrice > 0) {
    $where[] = $effectivePriceExpr . " >= ?";
    $types .= 'd';
    $params[] = (float) $minPrice;
}
if ($maxPrice > 0) {
    $where[] = $effectivePriceExpr . " <= ?";
    $types .= 'd';
    $params[] = (float) $maxPrice;
}
if ($inStock === '1') {
    $where[] = "(f.is_available = 1 AND ((f.unit_type IN ('piece','set') AND (COALESCE(v.stock, f.stock) >= GREATEST(1, CEIL(f.min_order_meters)))) OR (f.unit_type = 'meter' AND (COALESCE(v.stock_meters, f.stock_meters) >= f.min_order_meters))))";
}
if ($materialFilter !== '') {
    $where[] = "COALESCE(JSON_UNQUOTE(JSON_EXTRACT(f.catalog_data, '$.attr_material')), JSON_UNQUOTE(JSON_EXTRACT(f.catalog_data, '$.attr_fabric')), '') LIKE ?";
    $types .= 's';
    $params[] = '%' . $materialFilter . '%';
}
if ($colorFilter !== '') {
    $where[] = "COALESCE(NULLIF(v.color, ''), f.color) LIKE ?";
    $types .= 's';
    $params[] = '%' . $colorFilter . '%';
}
if ($sizeFilter !== '') {
    $where[] = "COALESCE(NULLIF(v.size, ''), f.size) LIKE ?";
    $types .= 's';
    $params[] = '%' . $sizeFilter . '%';
}
if ($dispatchFilter !== '') {
    $where[] = "CONCAT(COALESCE(f.dispatch_min_days,''), '-', COALESCE(f.dispatch_max_days,'')) LIKE ?";
    $types .= 's';
    $params[] = '%' . $dispatchFilter . '%';
}

$baseWhereSql = 'WHERE ' . implode(' AND ', $where);
$listWhereSql = $baseWhereSql;
$listTypes = $types;
$listParams = $params;
$keysetMode = false;
$nextCursor = '';
$cursorContext = catalog_pagination_context($state);
$paginationState = catalog_pagination_resolve($page, $sort, $cursor, $cursorContext);
if (($paginationState['mode'] ?? '') === 'cursor') {
    $cursorData = (array) ($paginationState['cursor_data'] ?? []);
    $cursorCreatedAt = (string) ($cursorData['created_at'] ?? '');
    $cursorFabricId = (int) ($cursorData['fabric_id'] ?? 0);
    $cursorVariantId = (int) ($cursorData['variant_id'] ?? 0);
    if ($cursorCreatedAt !== '' && $cursorFabricId > 0) {
        if ($sort === 'newest') {
            $listWhereSql .= " AND (
                f.created_at < ? OR
                (f.created_at = ? AND f.id < ?) OR
                (f.created_at = ? AND f.id = ? AND COALESCE(v.id, 0) < ?)
            )";
        } else {
            $listWhereSql .= " AND (
                f.created_at > ? OR
                (f.created_at = ? AND f.id > ?) OR
                (f.created_at = ? AND f.id = ? AND COALESCE(v.id, 0) > ?)
            )";
        }
        $listTypes .= 'ssisii';
        $listParams[] = $cursorCreatedAt;
        $listParams[] = $cursorCreatedAt;
        $listParams[] = $cursorFabricId;
        $listParams[] = $cursorCreatedAt;
        $listParams[] = $cursorFabricId;
        $listParams[] = $cursorVariantId;
        $keysetMode = true;
        $page = 1;
        $offset = 0;
        $state = catalog_cursor_page_state($state, (string) ($paginationState['cursor'] ?? ''));
    }
}
if (!$keysetMode) {
    $cursor = '';
    $state = catalog_numbered_page_state($state, $page);
}

$countSql = "SELECT COUNT(*) AS total
             {$fromSql}
             {$baseWhereSql}";

// Avoid repeating expensive count on every page hit for the same filters.
$countCacheKey = 'catalog_count_' . hash('sha256', json_encode([
    'q' => $search,
    'category' => $category,
    'min_price' => $minPrice,
    'max_price' => $maxPrice,
    'in_stock' => $inStock,
    'material' => $materialFilter,
    'color' => $colorFilter,
    'size' => $sizeFilter,
    'dispatch' => $dispatchFilter,
], JSON_UNESCAPED_UNICODE));
$countCached = $_SESSION[$countCacheKey] ?? null;
$countCachedAt = (int) ($_SESSION[$countCacheKey . '_ts'] ?? 0);
$countCacheTtlSec = 60;

if (is_int($countCached) && $countCached >= 0 && (time() - $countCachedAt) <= $countCacheTtlSec) {
    $total = $countCached;
} else {
    $countStmt = $conn->prepare($countSql);
    if (!empty($params)) {
        $countStmt->bind_param($types, ...$params);
    }
    $countStmt->execute();
    $total = (int) $countStmt->get_result()->fetch_assoc()['total'];
    $_SESSION[$countCacheKey] = $total;
    $_SESSION[$countCacheKey . '_ts'] = time();
}
$pages = max(1, (int) ceil($total / $perPage));

if (!$keysetMode && $page > $pages) {
    $page = list_clamp_page($page, $pages);
    $state = catalog_numbered_page_state($state, $page);
    $offset = ($page - 1) * $perPage;
}

$listSql = "SELECT
                " . product_card_select_columns(["{$effectivePriceExpr} AS effective_price"], false) . "
            {$fromSql}
            {$listWhereSql}
            ORDER BY {$orderBy} LIMIT ?" . ($keysetMode ? '' : ' OFFSET ?');
$stmt = $conn->prepare($listSql);

if (!empty($listParams)) {
    $typesWithLimit = $listTypes . ($keysetMode ? 'i' : 'ii');
    $paramsWithLimit = array_merge($listParams, $keysetMode ? [$perPage] : [$perPage, $offset]);
    $stmt->bind_param($typesWithLimit, ...$paramsWithLimit);
} else {
    if ($keysetMode) {
        $stmt->bind_param('i', $perPage);
    } else {
        $stmt->bind_param('ii', $perPage, $offset);
    }
}
$stmt->execute();
$result = $stmt->get_result();
$rows = $result->fetch_all(MYSQLI_ASSOC);
$fabricIds = [];
foreach ($rows as $row) {
    $fabricId = (int) ($row['id'] ?? 0);
    if ($fabricId > 0) {
        $fabricIds[$fabricId] = $fabricId;
    }
}
$primaryMediaRows = [];
if ($fabricIds !== []) {
    $fabricIds = array_values($fabricIds);
    $mediaPlaceholders = implode(',', array_fill(0, count($fabricIds), '?'));
    $mediaStmt = $conn->prepare(
        "SELECT fabric_id, filename
         FROM fabric_media
         WHERE media_type = 'image' AND fabric_id IN ({$mediaPlaceholders})
         ORDER BY fabric_id, is_primary DESC, sort_order, id"
    );
    $mediaTypes = str_repeat('i', count($fabricIds));
    $mediaStmt->bind_param($mediaTypes, ...$fabricIds);
    $mediaStmt->execute();
    $primaryMediaRows = $mediaStmt->get_result()->fetch_all(MYSQLI_ASSOC);
}
$rows = product_card_apply_primary_media($rows, $primaryMediaRows);
if ($keysetMode && count($rows) === $perPage) {
    $last = $rows[count($rows) - 1];
    $nextCursor = catalog_cursor_encode(
        (string) ($last['created_at'] ?? ''),
        (int) ($last['id'] ?? 0),
        (int) ($last['variant_id'] ?? 0),
        $cursorContext
    );
}

$explainRows = [];
if ($debugExplain) {
    try {
        $explainSql = "EXPLAIN " . $listSql;
        $expStmt = $conn->prepare($explainSql);
        if (!empty($listParams)) {
            $expTypes = $listTypes . ($keysetMode ? 'i' : 'ii');
            $expParams = array_merge($listParams, $keysetMode ? [$perPage] : [$perPage, $offset]);
            $expStmt->bind_param($expTypes, ...$expParams);
        } else {
            if ($keysetMode) {
                $expStmt->bind_param('i', $perPage);
            } else {
                $expStmt->bind_param('ii', $perPage, $offset);
            }
        }
        $expStmt->execute();
        $explainRows = $expStmt->get_result()->fetch_all(MYSQLI_ASSOC);
    } catch (Throwable $explainError) {
        app_log('warning', 'catalog_explain_failed', [
            'exception_type' => get_class($explainError),
        ]);
        $explainRows = [['error' => 'Catalog query plan unavailable.']];
    }
}

$activeFilters = [];
if ($search !== '') {
    $activeFilters[] = [
        'label' => 'Search: ' . $search,
        'remove_state' => array_merge($state, ['q' => '', 'page' => 1]),
    ];
}
if ($category !== '') {
    $matchedCategory = null;
    foreach ($categories as $cat) {
        if (($cat['slug'] ?? '') === $category) {
            $matchedCategory = $cat['name'] ?? $category;
            break;
        }
    }
    $activeFilters[] = [
        'label' => 'Category: ' . ($matchedCategory ?? $category),
        'remove_state' => array_merge($state, ['category' => '', 'page' => 1]),
    ];
}
if ($minPrice > 0 || $maxPrice > 0) {
    $label = 'Price: ';
    $label .= ($minPrice > 0) ? money((float) $minPrice) : 'Any';
    $label .= ' - ';
    $label .= ($maxPrice > 0) ? money((float) $maxPrice) : 'Any';
    $activeFilters[] = [
        'label' => $label,
        'remove_state' => array_merge($state, ['min_price' => 0, 'max_price' => 0, 'page' => 1]),
    ];
}
if ($inStock === '1') {
    $activeFilters[] = [
        'label' => 'Availability: In Stock',
        'remove_state' => array_merge($state, ['in_stock' => '', 'page' => 1]),
    ];
}
if ($materialFilter !== '') {
    $activeFilters[] = [
        'label' => 'Material: ' . $materialFilter,
        'remove_state' => array_merge($state, ['material' => '', 'page' => 1]),
    ];
}
if ($colorFilter !== '') {
    $activeFilters[] = [
        'label' => 'Color: ' . $colorFilter,
        'remove_state' => array_merge($state, ['color' => '', 'page' => 1]),
    ];
}
if ($sizeFilter !== '') {
    $activeFilters[] = [
        'label' => 'Size/Pack: ' . $sizeFilter,
        'remove_state' => array_merge($state, ['size' => '', 'page' => 1]),
    ];
}
if ($dispatchFilter !== '') {
    $activeFilters[] = [
        'label' => 'Dispatch: ' . $dispatchFilter,
        'remove_state' => array_merge($state, ['dispatch' => '', 'page' => 1]),
    ];
}
foreach ($activeFilters as &$activeFilter) {
    $activeFilter['remove_state'] = catalog_reset_pagination_state((array) ($activeFilter['remove_state'] ?? []));
}
unset($activeFilter);

$catalogCategoryOptions = [['value' => '', 'label' => 'All Categories']];
foreach ($categories as $cat) {
    $catalogCategoryOptions[] = [
        'value' => (string) ($cat['slug'] ?? ''),
        'label' => (string) ($cat['name'] ?? ''),
    ];
}
$catalogFilterFields = [
    'category' => ['name' => 'category', 'label' => 'Category', 'type' => 'select', 'value' => $category, 'options' => $catalogCategoryOptions],
    'min_price' => ['name' => 'min_price', 'label' => 'Minimum Price', 'type' => 'number', 'value' => $minPrice, 'min' => 0, 'placeholder' => 'Min'],
    'max_price' => ['name' => 'max_price', 'label' => 'Maximum Price', 'type' => 'number', 'value' => $maxPrice, 'min' => 0, 'placeholder' => 'Max'],
    'in_stock' => ['name' => 'in_stock', 'label' => 'In Stock Only', 'type' => 'checkbox', 'value' => '1', 'checked' => $inStock === '1'],
    'material' => ['name' => 'material', 'label' => 'Material', 'type' => 'text', 'value' => $materialFilter, 'placeholder' => 'Cotton, Linen...'],
    'color' => ['name' => 'color', 'label' => 'Color', 'type' => 'text', 'value' => $colorFilter, 'placeholder' => 'Indigo, Red...'],
    'size' => ['name' => 'size', 'label' => 'Size / Pack', 'type' => 'text', 'value' => $sizeFilter, 'placeholder' => 'L, Queen, Pack of 2...'],
    'dispatch' => ['name' => 'dispatch', 'label' => 'Dispatch Time', 'type' => 'text', 'value' => $dispatchFilter, 'placeholder' => '2-3 days, 1 week...'],
    'sort' => [
        'name' => 'sort', 'label' => 'Sort', 'type' => 'select', 'value' => $sort,
        'options' => [
            ['value' => 'newest', 'label' => 'Newest First'], ['value' => 'oldest', 'label' => 'Oldest First'],
            ['value' => 'name_asc', 'label' => 'Name A-Z'], ['value' => 'name_desc', 'label' => 'Name Z-A'],
            ['value' => 'price_asc', 'label' => 'Price Low-High'], ['value' => 'price_desc', 'label' => 'Price High-Low'],
        ],
    ],
    'per_page' => ['name' => 'per_page', 'label' => 'Per Page', 'type' => 'select', 'value' => $perPage, 'options' => array_map(static fn (int $size): array => ['value' => $size, 'label' => (string) $size], $perPageOptions)],
];
$catalogFilterAdvancedOpen = $materialFilter !== '' || $colorFilter !== '' || $sizeFilter !== '' || $dispatchFilter !== '' || $perPage !== $perPageOptions[0];
?>

<section class="page-hero">
    <div class="container">
        <h1 class="mb-2">Shop Collection</h1>
        <p class="mb-1">Discover authentic Indian textiles curated for everyday elegance.</p>
    </div>
</section>

<section class="section-block pt-0">
    <div class="container">
        <div class="surface-panel catalog-utility-row mb-3">
            <div class="catalog-utility-main">
                <p class="catalog-results-count mb-0" aria-live="polite">Showing <?php echo count($rows); ?> of <?php echo $total; ?> products</p>
                <?php if (!empty($activeFilters)): ?>
                    <div class="catalog-active-filters" aria-label="Active filters">
                        <?php foreach ($activeFilters as $chip): ?>
                            <a class="catalog-filter-chip" href="<?php echo e(catalog_query($chip['remove_state'])); ?>">
                                <span><?php echo e($chip['label']); ?></span>
                                <span aria-hidden="true">&times;</span>
                            </a>
                        <?php endforeach; ?>
                        <a class="catalog-clear-all" href="/catalog">Clear all</a>
                    </div>
                <?php endif; ?>
            </div>
            <form class="catalog-search-strip" method="GET" action="/catalog" role="search">
                <input type="hidden" name="category" value="<?php echo e($category); ?>">
                <input type="hidden" name="min_price" value="<?php echo (int) $minPrice; ?>">
                <input type="hidden" name="max_price" value="<?php echo (int) $maxPrice; ?>">
                <input type="hidden" name="in_stock" value="<?php echo e($inStock); ?>">
                <input type="hidden" name="material" value="<?php echo e($materialFilter); ?>">
                <input type="hidden" name="color" value="<?php echo e($colorFilter); ?>">
                <input type="hidden" name="size" value="<?php echo e($sizeFilter); ?>">
                <input type="hidden" name="dispatch" value="<?php echo e($dispatchFilter); ?>">
                <input type="hidden" name="sort" value="<?php echo e($sort); ?>">
                <input type="hidden" name="per_page" value="<?php echo $perPage; ?>">
                <input class="form-control" type="search" name="q" value="<?php echo e($search); ?>" placeholder="Search products..." aria-label="Search products">
                <button class="btn btn-primary" type="submit">Search</button>
            </form>
        </div>

        <div class="catalog-layout">
            <aside class="catalog-filters">
                <div class="surface-panel catalog-filter-panel">
                    <h2 class="h5 mb-3">Filters</h2>
                    <form class="row g-2" method="GET" action="/catalog">
                            <input type="hidden" name="q" value="<?php echo e($search); ?>">
                            <?php
                            $catalogFilterMode = 'desktop';
                            $catalogFilterIdPrefix = 'catalog_';
                            include __DIR__ . '/includes/partials/catalog-filter-fields.php';
                            ?>
                            <div class="col-12">
                                <button class="btn btn-primary w-100" type="submit">Apply Filters</button>
                            </div>
                            <div class="col-12">
                                <a href="/catalog" class="btn btn-outline-primary w-100">Reset All</a>
                            </div>
                        </form>
                </div>
            </aside>

            <div class="catalog-results">

                <?php $activeFilterCount = count($activeFilters); ?>
                <!-- Mobile filter bar (hidden on desktop) -->
                <div class="d-lg-none mobile-filter-bar mb-3">
                    <button type="button" class="mobile-filter-btn" data-bs-toggle="offcanvas" data-bs-target="#catalogFiltersDrawer" aria-controls="catalogFiltersDrawer" aria-expanded="false">
                        <svg xmlns="http://www.w3.org/2000/svg" width="15" height="15" fill="currentColor" viewBox="0 0 16 16"><path d="M1.5 1.5A.5.5 0 0 1 2 1h12a.5.5 0 0 1 .5.5v2a.5.5 0 0 1-.128.334L10 8.692V13.5a.5.5 0 0 1-.342.474l-3 1A.5.5 0 0 1 6 14.5V8.692L1.628 3.834A.5.5 0 0 1 1.5 3.5v-2z"/></svg>
                        Filters
                        <?php if ($activeFilterCount > 0): ?>
                            <span class="filter-badge"><?php echo $activeFilterCount; ?></span>
                        <?php endif; ?>
                    </button>
                </div>

                <div class="catalog-products-grid">
                    <?php if (count($rows) === 0): ?>
                        <div class="surface-panel text-center text-muted">No products found for your current filters.</div>
                    <?php endif; ?>

                    <?php foreach ($rows as $row): ?>
                    <?php
                        $productCard = product_card_build_context($conn, $row);
                        $filteredProductCard = apply_filters('product.card.context', $productCard, [
                            'conn' => $conn,
                            'row' => $row,
                        ]);
                        if (is_array($filteredProductCard)) {
                            $productCard = $filteredProductCard;
                        }

                        $productCardBadges = apply_filters('product.card.badges', [], [
                            'conn' => $conn,
                            'row' => $row,
                            'card' => $productCard,
                            'product_id' => (int) ($productCard['product_id'] ?? 0),
                            'variant_id' => (int) ($productCard['variant_id'] ?? 0),
                            'display_name' => (string) ($productCard['display_name'] ?? ''),
                            'product_url' => (string) ($productCard['product_url'] ?? ''),
                            'unit_type' => (string) ($productCard['unit_type'] ?? 'meter'),
                            'unit_price' => (float) ($productCard['unit_price'] ?? 0),
                            'regular_price' => (float) ($productCard['regular_price'] ?? 0),
                            'in_stock' => !empty($productCard['in_stock']),
                            'stock' => (float) ($productCard['stock'] ?? 0),
                        ]);
                        if (!is_array($productCardBadges)) {
                            $productCardBadges = [];
                        }
                        $productCard['badges'] = $productCardBadges;

                        $defaultProductCardHtml = product_card_render($productCard);
                        $productCardHtml = apply_filters('product.card.render', $defaultProductCardHtml, [
                            'conn' => $conn,
                            'row' => $row,
                            'card' => $productCard,
                        ]);
                        if (!is_string($productCardHtml) || trim($productCardHtml) === '') {
                            $productCardHtml = $defaultProductCardHtml;
                        }
                    ?>
                    <?php echo $productCardHtml; ?>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
    </div>
</section>

<?php if ($debugExplain): ?>
<section class="section-block pt-0">
    <div class="container">
        <div class="surface-panel">
            <h5 class="mb-2">EXPLAIN (Catalog)</h5>
            <pre class="small mb-0"><?php echo e(json_encode($explainRows, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)); ?></pre>
        </div>
    </div>
</section>
<?php endif; ?>

<?php if (!$keysetMode): ?>
    <?php echo render_pagination($page, $pages, $state, 'page', $total, $perPage); ?>
<?php endif; ?>
<?php if ($keysetMode && $nextCursor !== ''): ?>
<section class="section-block pt-0">
    <div class="container text-end">
        <a class="btn btn-outline-primary" href="<?php echo e(catalog_query(catalog_cursor_page_state($state, $nextCursor))); ?>">Next Page</a>
    </div>
</section>
<?php endif; ?>

<?php do_action('catalog.after_results', [
    'conn' => $conn,
    'rows' => $rows,
    'state' => $state,
    'total' => $total,
    'page' => $page,
    'pages' => $pages,
    'per_page' => $perPage,
    'category' => $category,
    'search' => $search,
    'sort' => $sort,
]); ?>

<section class="section-block">
    <div class="container">
        <div class="surface-panel text-center">
            <h5 class="mb-2">Need International Shipping or Bulk Quantities?</h5>
            <p class="text-muted mb-3">For overseas buyers and large-volume orders, contact us through International Inquiry.</p>
            <a href="/international-buyers" class="btn btn-outline-primary">International Inquiry</a>
        </div>
    </div>
</section>

<!-- Offcanvas filter drawer (mobile only) -->
<div class="offcanvas offcanvas-bottom filter-offcanvas d-lg-none" tabindex="-1" id="catalogFiltersDrawer" aria-labelledby="catalogFiltersDrawerLabel">
    <div class="offcanvas-header">
        <h5 class="offcanvas-title" id="catalogFiltersDrawerLabel">Filters &amp; Sort</h5>
        <button type="button" class="btn-close" data-bs-dismiss="offcanvas" aria-label="Close"></button>
    </div>
    <div class="offcanvas-body">
        <form class="row g-3" method="GET" action="/catalog">
            <input type="hidden" name="q" value="<?php echo e($search); ?>">
            <?php
            $catalogFilterMode = 'mobile';
            $catalogFilterIdPrefix = 'catalog_mobile_';
            include __DIR__ . '/includes/partials/catalog-filter-fields.php';
            ?>
            <div class="col-12 d-flex gap-2">
                <button type="submit" class="btn btn-primary flex-grow-1" data-bs-dismiss="offcanvas">Apply Filters</button>
                <a href="/catalog" class="btn btn-outline-secondary" data-bs-dismiss="offcanvas">Reset</a>
            </div>
        </form>
    </div>
</div>

<?php include 'includes/footer.php'; ?>
