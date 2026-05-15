<?php require_once 'includes/init.php'; ?>
<?php
$metaTitle = 'Shop | Amber Fabrics';
$metaDescription = 'Shop premium Bedsheets, Towels, and Table Covers from Amber Fabrics.';
$metaKeywords = 'shop home textiles, bedsheets, towels, table covers, Amber Fabrics';
include 'includes/header.php';

$perPageOptions = [10, 20, 30];
$sortMap = [
    'newest' => 'created_at DESC',
    'oldest' => 'created_at ASC',
    'name_asc' => 'name ASC',
    'name_desc' => 'name DESC',
    'price_asc' => 'price ASC',
    'price_desc' => 'price DESC',
];
$sellableCategorySlugs = ['fabric-by-meter', 'bedsheets', 'towels', 'table-covers'];
$sellablePlaceholders = implode(',', array_fill(0, count($sellableCategorySlugs), '?'));

$categories = [];
try {
    $catStmt = $conn->prepare(
        "SELECT id, name, slug, parent_id
         FROM categories
         WHERE status = 'active' AND slug IN ($sellablePlaceholders)
         ORDER BY FIELD(slug, 'fabric-by-meter', 'bedsheets', 'towels', 'table-covers'), name ASC"
    );
    $catStmt->bind_param(str_repeat('s', count($sellableCategorySlugs)), ...$sellableCategorySlugs);
    $catStmt->execute();
    $categories = $catStmt->get_result()->fetch_all(MYSQLI_ASSOC);
} catch (Throwable $e) {
    $categories = [];
}

$state = [
    'q' => trim((string) ($_GET['q'] ?? '')),
    'category' => trim((string) ($_GET['category'] ?? '')),
    'sort' => list_sanitize_sort(trim((string) ($_GET['sort'] ?? 'newest')), $sortMap),
    'per_page' => list_sanitize_per_page((int) ($_GET['per_page'] ?? $perPageOptions[0]), $perPageOptions),
    'page' => list_sanitize_page((int) ($_GET['page'] ?? 1)),
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
$sortLabels = [
    'newest' => 'Newest',
    'oldest' => 'Oldest',
    'name_asc' => 'Name A-Z',
    'name_desc' => 'Name Z-A',
    'price_asc' => 'Price Low-High',
    'price_desc' => 'Price High-Low',
];

$where = ["status = 'active'", "category IN ($sellablePlaceholders)"];
$types = '';
$params = $sellableCategorySlugs;
$types .= str_repeat('s', count($sellableCategorySlugs));

if ($search !== '') {
    $where[] = "(name LIKE ? OR sku LIKE ? OR material LIKE ?)";
    $like = "%{$search}%";
    $types .= 'sss';
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
}
if ($category !== '') {
    $where[] = "category = ?";
    $types .= 's';
    $params[] = $category;
}

$whereSql = 'WHERE ' . implode(' AND ', $where);

$countSql = "SELECT COUNT(*) AS total FROM fabrics {$whereSql}";
$countStmt = $conn->prepare($countSql);
if (!empty($params)) {
    $countStmt->bind_param($types, ...$params);
}
$countStmt->execute();
$total = (int) $countStmt->get_result()->fetch_assoc()['total'];
$pages = max(1, (int) ceil($total / $perPage));

if ($page > $pages) {
    $page = list_clamp_page($page, $pages);
    $state['page'] = $page;
    $offset = ($page - 1) * $perPage;
}

$listSql = "SELECT id, name, category, image, material, size, unit_type, price, sale_price, price_inr, stock, stock_meters, is_available
            FROM fabrics {$whereSql}
            ORDER BY {$orderBy} LIMIT ? OFFSET ?";
$stmt = $conn->prepare($listSql);

if (!empty($params)) {
    $typesWithLimit = $types . 'ii';
    $paramsWithLimit = array_merge($params, [$perPage, $offset]);
    $stmt->bind_param($typesWithLimit, ...$paramsWithLimit);
} else {
    $stmt->bind_param('ii', $perPage, $offset);
}
$stmt->execute();
$result = $stmt->get_result();

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

function catalog_query(array $params): string {
    $query = http_build_query($params);
    return $query !== '' ? 'catalog.php?' . $query : 'catalog.php';
}
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
                <p class="catalog-results-count mb-0" aria-live="polite">Showing <?php echo $result->num_rows; ?> of <?php echo $total; ?> products</p>
                <?php if (!empty($activeFilters)): ?>
                    <div class="catalog-active-filters" aria-label="Active filters">
                        <?php foreach ($activeFilters as $chip): ?>
                            <a class="catalog-filter-chip" href="<?php echo e(catalog_query($chip['remove_state'])); ?>">
                                <span><?php echo e($chip['label']); ?></span>
                                <span aria-hidden="true">&times;</span>
                            </a>
                        <?php endforeach; ?>
                        <a class="catalog-clear-all" href="catalog.php">Clear all</a>
                    </div>
                <?php endif; ?>
            </div>
            <form class="catalog-search-strip" method="GET" action="catalog.php" role="search">
                <input type="hidden" name="category" value="<?php echo e($category); ?>">
                <input type="hidden" name="sort" value="<?php echo e($sort); ?>">
                <input type="hidden" name="per_page" value="<?php echo $perPage; ?>">
                <input type="hidden" name="page" value="1">
                <input class="form-control" type="search" name="q" value="<?php echo e($search); ?>" placeholder="Search products..." aria-label="Search products">
                <button class="btn btn-primary" type="submit">Search</button>
            </form>
        </div>

        <div class="catalog-layout">
            <aside class="catalog-filters">
                <div class="surface-panel catalog-filter-panel">
                    <h2 class="h5 mb-3">Filters</h2>
                    <form class="row g-2" method="GET" action="catalog.php">
                            <input type="hidden" name="q" value="<?php echo e($search); ?>">
                            <div class="col-12">
                                <label class="form-label">Category</label>
                                <select class="form-select" name="category">
                                    <option value="">All Categories</option>
                                    <?php foreach ($categories as $cat): ?>
                                        <option value="<?php echo e($cat['slug']); ?>" <?php echo $category === $cat['slug'] ? 'selected' : ''; ?>>
                                            <?php echo e($cat['name']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-12">
                                <label class="form-label">Sort</label>
                                <select class="form-select" name="sort">
                                    <option value="newest" <?php echo $sort === 'newest' ? 'selected' : ''; ?>>Newest First</option>
                                    <option value="oldest" <?php echo $sort === 'oldest' ? 'selected' : ''; ?>>Oldest First</option>
                                    <option value="name_asc" <?php echo $sort === 'name_asc' ? 'selected' : ''; ?>>Name A-Z</option>
                                    <option value="name_desc" <?php echo $sort === 'name_desc' ? 'selected' : ''; ?>>Name Z-A</option>
                                    <option value="price_asc" <?php echo $sort === 'price_asc' ? 'selected' : ''; ?>>Price Low-High</option>
                                    <option value="price_desc" <?php echo $sort === 'price_desc' ? 'selected' : ''; ?>>Price High-Low</option>
                                </select>
                            </div>
                            <div class="col-12">
                                <label class="form-label">Per Page</label>
                                <select class="form-select" name="per_page">
                                    <?php foreach ($perPageOptions as $size): ?>
                                        <option value="<?php echo $size; ?>" <?php echo $perPage === $size ? 'selected' : ''; ?>><?php echo $size; ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-12">
                                <button class="btn btn-primary w-100" type="submit">Apply Filters</button>
                            </div>
                            <div class="col-12">
                                <a href="catalog.php" class="btn btn-outline-primary w-100">Reset All</a>
                            </div>
                        </form>
                </div>
            </aside>

            <div class="catalog-results">

                <?php $activeFilterCount = ($search !== '' ? 1 : 0) + ($category !== '' ? 1 : 0); ?>
                <!-- Mobile filter bar (hidden on desktop) -->
                <div class="d-lg-none mobile-filter-bar mb-3">
                    <button type="button" class="mobile-filter-btn" data-bs-toggle="offcanvas" data-bs-target="#catalogFiltersDrawer" aria-controls="catalogFiltersDrawer">
                        <svg xmlns="http://www.w3.org/2000/svg" width="15" height="15" fill="currentColor" viewBox="0 0 16 16"><path d="M1.5 1.5A.5.5 0 0 1 2 1h12a.5.5 0 0 1 .5.5v2a.5.5 0 0 1-.128.334L10 8.692V13.5a.5.5 0 0 1-.342.474l-3 1A.5.5 0 0 1 6 14.5V8.692L1.628 3.834A.5.5 0 0 1 1.5 3.5v-2z"/></svg>
                        Filters
                        <?php if ($activeFilterCount > 0): ?>
                            <span class="filter-badge"><?php echo $activeFilterCount; ?></span>
                        <?php endif; ?>
                    </button>
                </div>

                <div class="catalog-products-grid">
                    <?php if ($result->num_rows === 0): ?>
                        <div class="surface-panel text-center text-muted">No products found for your current filters.</div>
                    <?php endif; ?>

                    <?php while ($row = $result->fetch_assoc()): ?>
                    <?php
                        $regularPrice = (float) (($row['price'] !== null && $row['price'] !== '') ? $row['price'] : ($row['price_inr'] ?? 0));
                        $salePrice    = (float) ($row['sale_price'] ?? 0);
                        $unitType = in_array((string) ($row['unit_type'] ?? ''), ['meter', 'piece', 'set'], true) ? (string) $row['unit_type'] : 'meter';
                        $displayStock = ($unitType === 'piece' || $unitType === 'set') ? (float) ($row['stock'] ?? 0) : (float) ($row['stock_meters'] ?? 0);
                        $inStock      = !empty($row['is_available']) && $displayStock > 0;
                        $hasSizeOptions = !empty(parse_size_options((string) ($row['size'] ?? '')));
                    ?>
                    <div class="animate-in">
                        <article class="card h-100">
                            <div class="fabric-thumb-wrap">
                                <a href="fabric.php?id=<?php echo (int) $row['id']; ?>" class="fabric-thumb-link" aria-label="View <?php echo e($row['name']); ?>">
                                    <?php if (!empty($row['image'])): ?>
                                        <img src="images/fabrics/<?php echo e($row['image']); ?>" class="fabric-thumb" alt="<?php echo e($row['name']); ?>" loading="lazy" width="600" height="800">
                                    <?php else: ?>
                                        <div class="fabric-thumb-empty">No image</div>
                                    <?php endif; ?>
                                </a>
                                <?php if (!$inStock): ?>
                                    <div class="fabric-out-overlay">Out of Stock</div>
                                <?php endif; ?>
                            </div>

                            <div class="card-body d-flex flex-column">
                                <?php if (!empty($row['category'])): ?>
                                    <p class="fabric-card-category"><?php echo e($row['category']); ?></p>
                                <?php endif; ?>
                                <a href="fabric.php?id=<?php echo (int) $row['id']; ?>" class="fabric-card-title-link">
                                    <p class="fabric-card-title"><?php echo e($row['name']); ?></p>
                                </a>

                                <div class="fabric-price mb-2">
                                    <?php if ($salePrice > 0 && $regularPrice > 0 && $salePrice < $regularPrice): ?>
                                        <span class="price-inr fw-bold">Rs <?php echo number_format($salePrice, 2); ?></span>
                                        <span class="text-muted small ms-1"><del>Rs <?php echo number_format($regularPrice, 2); ?></del></span>
                                    <?php elseif ($regularPrice > 0): ?>
                                        <span class="price-inr">Rs <?php echo number_format($regularPrice, 2); ?><?php echo ($unitType === 'piece' || $unitType === 'set') ? ' each' : '/m'; ?></span>
                                    <?php else: ?>
                                        <span class="text-muted small">Price on request</span>
                                    <?php endif; ?>
                                </div>
                                <p class="fabric-trust-note">Fast dispatch | Quality checked</p>

                                <div class="d-flex gap-1 mt-auto">
                                    <a href="fabric.php?id=<?php echo (int) $row['id']; ?>" class="btn btn-outline-dark btn-sm">View</a>
                                    <?php if ($inStock): ?>
                                        <?php if ($unitType === 'meter'): ?>
                                            <a href="fabric.php?id=<?php echo (int) $row['id']; ?>" class="btn btn-primary btn-sm flex-grow-1">Select Meter</a>
                                        <?php elseif ($hasSizeOptions): ?>
                                            <a href="fabric.php?id=<?php echo (int) $row['id']; ?>" class="btn btn-primary btn-sm flex-grow-1">Select Size</a>
                                        <?php else: ?>
                                            <form method="POST" action="/add-to-cart.php" class="flex-grow-1">
                                                <?php echo csrf_field(); ?>
                                                <input type="hidden" name="product_id" value="<?php echo (int) $row['id']; ?>">
                                                <input type="hidden" name="quantity" value="1">
                                                <button type="submit" class="btn btn-primary btn-sm w-100">Add to Cart</button>
                                            </form>
                                        <?php endif; ?>
                                    <?php else: ?>
                                        <button type="button" class="btn btn-secondary btn-sm flex-grow-1" disabled>Unavailable</button>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </article>
                    </div>
                    <?php endwhile; ?>
                </div>
            </div>
        </div>
    </div>
</section>

<?php echo render_pagination($page, $pages, $state, 'page', $total, $perPage); ?>

<section class="section-block pt-0">
    <div class="container">
        <div class="surface-panel text-center">
            <h5 class="mb-2">Need International Shipping or Bulk Quantities?</h5>
            <p class="text-muted mb-3">For overseas buyers and large-volume orders, contact us through International Inquiry.</p>
            <a href="contact.php" class="btn btn-outline-primary">International Inquiry</a>
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
        <form class="row g-3" method="GET" action="catalog.php">
            <input type="hidden" name="q" value="<?php echo e($search); ?>">
            <div class="col-12">
                <label class="form-label fw-semibold">Category</label>
                <select class="form-select" name="category">
                    <option value="">All Categories</option>
                    <?php foreach ($categories as $cat): ?>
                        <option value="<?php echo e($cat['slug']); ?>" <?php echo $category === $cat['slug'] ? 'selected' : ''; ?>>
                            <?php echo e($cat['name']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-6">
                <label class="form-label fw-semibold">Sort</label>
                <select class="form-select" name="sort">
                    <option value="newest" <?php echo $sort === 'newest' ? 'selected' : ''; ?>>Newest First</option>
                    <option value="oldest" <?php echo $sort === 'oldest' ? 'selected' : ''; ?>>Oldest First</option>
                    <option value="name_asc" <?php echo $sort === 'name_asc' ? 'selected' : ''; ?>>Name A-Z</option>
                    <option value="name_desc" <?php echo $sort === 'name_desc' ? 'selected' : ''; ?>>Name Z-A</option>
                    <option value="price_asc" <?php echo $sort === 'price_asc' ? 'selected' : ''; ?>>Price Low-High</option>
                    <option value="price_desc" <?php echo $sort === 'price_desc' ? 'selected' : ''; ?>>Price High-Low</option>
                </select>
            </div>
            <div class="col-6">
                <label class="form-label fw-semibold">Per Page</label>
                <select class="form-select" name="per_page">
                    <?php foreach ($perPageOptions as $size): ?>
                        <option value="<?php echo $size; ?>" <?php echo $perPage === $size ? 'selected' : ''; ?>><?php echo $size; ?> items</option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-12 d-flex gap-2">
                <button type="submit" class="btn btn-primary flex-grow-1" data-bs-dismiss="offcanvas">Apply Filters</button>
                <a href="catalog.php" class="btn btn-outline-secondary" data-bs-dismiss="offcanvas">Reset</a>
            </div>
        </form>
    </div>
</div>

<?php include 'includes/footer.php'; ?>
