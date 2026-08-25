<?php
require_once __DIR__ . '/../includes/init.php';
require_admin();

$perPageOptions = [15, 30, 50];
$sortMap = [
    'newest' => 'f.created_at DESC',
    'oldest' => 'f.created_at ASC',
    'name_asc' => 'f.name ASC',
    'name_desc' => 'f.name DESC',
    'price_asc' => 'f.price ASC',
    'price_desc' => 'f.price DESC',
    'stock_asc' => 'effective_stock ASC',
    'stock_desc' => 'effective_stock DESC',
];

$state = [
    'q' => trim((string) ($_GET['q'] ?? '')),
    'status' => trim((string) ($_GET['status'] ?? '')),
    'sort' => list_sanitize_sort(trim((string) ($_GET['sort'] ?? 'newest')), $sortMap),
    'per_page' => list_sanitize_per_page((int) ($_GET['per_page'] ?? $perPageOptions[0]), $perPageOptions),
    'page' => list_sanitize_page((int) ($_GET['page'] ?? 1)),
];

$search = $state['q'];
$status = $state['status'];
if (!in_array($status, ['', 'draft', 'active', 'inactive'], true)) {
    $status = '';
}
$sort = $state['sort'];
$perPage = $state['per_page'];
$page = $state['page'];
$offset = ($page - 1) * $perPage;
$orderBy = $sortMap[$sort];

$where = [];
$types = '';
$params = [];

if ($search !== '') {
    $where[] = '(f.name LIKE ? OR f.sku LIKE ? OR f.product_code LIKE ? OR f.amazon_asin LIKE ?)';
    $like = "%{$search}%";
    $types .= 'ssss';
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
}

if ($status !== '') {
    $where[] = 'f.status = ?';
    $types .= 's';
    $params[] = $status;
}

$whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

$countSql = "SELECT COUNT(*) AS total FROM fabrics f {$whereSql}";
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

$listSql = "SELECT
                f.id, f.name, f.category,
                COALESCE(fm.primary_image, fv.variant_preview_image, '') AS list_image,
                f.sku,
                f.price, f.sale_price, f.stock, f.unit_type,
                f.stock_meters,
                CASE
                    WHEN COALESCE(fv.variant_count, 0) > 0 THEN
                        CASE
                            WHEN f.unit_type = 'meter' THEN COALESCE(fv.variant_stock_meters, 0)
                            ELSE COALESCE(fv.variant_stock_units, 0)
                        END
                    WHEN f.stock_meters IS NOT NULL AND f.stock_meters > 0 THEN f.stock_meters
                    ELSE COALESCE(f.stock, 0)
                END AS effective_stock,
                f.status,
                COALESCE(fv.variant_count, 0) AS variant_count,
                CASE
                    WHEN f.unit_type = 'meter' THEN COALESCE(fv.variant_stock_meters, 0)
                    ELSE COALESCE(fv.variant_stock_units, 0)
                END AS variant_stock
            FROM fabrics f
            LEFT JOIN (
                SELECT fabric_id, MAX(CASE WHEN is_primary=1 THEN filename ELSE NULL END) AS preferred_image,
                       SUBSTRING_INDEX(GROUP_CONCAT(filename ORDER BY is_primary DESC, sort_order, id), ',', 1) AS primary_image
                FROM fabric_media WHERE media_type='image' GROUP BY fabric_id
            ) fm ON fm.fabric_id = f.id
            LEFT JOIN (
                SELECT
                    fabric_id,
                    COUNT(*) AS variant_count,
                    SUM(COALESCE(stock, 0)) AS variant_stock_units,
                    SUM(COALESCE(stock_meters, 0)) AS variant_stock_meters,
                    MAX(
                        COALESCE(
                            NULLIF(image, ''),
                            NULLIF(image2, ''),
                            NULLIF(image3, ''),
                            NULLIF(image4, '')
                        )
                    ) AS variant_preview_image
                FROM fabric_variants
                WHERE is_active = 1
                GROUP BY fabric_id
            ) fv ON fv.fabric_id = f.id
            {$whereSql}
            ORDER BY {$orderBy}
            LIMIT ? OFFSET ?";
$listStmt = $conn->prepare($listSql);
$allTypes = $types . 'ii';
$allParams = array_merge($params, [$perPage, $offset]);
$listStmt->bind_param($allTypes, ...$allParams);
$listStmt->execute();
$products = fetch_all_assoc($listStmt->get_result());

$metaTitle = SiteContext::title('Manage Products');
$metaDescription = 'Admin page to manage products for ' . SiteContext::name() . '.';
$metaKeywords = 'admin, products, manage, ' . SiteContext::name();
include 'partials/header.php';
?>

<div class="u-flex u-justify-between u-items-center u-mb-3">
    <div>
        <h1 class="u-mb-1">Products</h1>
        <p class="u-text-muted u-mb-0">Showing <?php echo count($products); ?> of <?php echo $total; ?> products</p>
    </div>
    <div class="u-flex u-gap-2">
        <a class="ui-button ui-button--outline" href="product-import.php">Import CSV</a>
        <a class="ui-button ui-button--primary" href="add-fabric.php">Add Product</a>
    </div>
</div>

<form class="l-grid l-grid--12 u-gap-2 u-mb-3" method="GET" action="fabrics.php">
    <div class="l-col-md-third">
        <label for="q" class="ui-label">Search</label>
        <input id="q" type="text" name="q" class="ui-input" value="<?php echo e($search); ?>" placeholder="Product name or SKU">
    </div>
    <div class="l-col-md-two">
        <label for="status" class="ui-label">Status</label>
        <select id="status" name="status" class="ui-select">
            <option value="">All</option>
            <option value="active" <?php echo $status === 'active' ? 'selected' : ''; ?>>Active</option>
            <option value="inactive" <?php echo $status === 'inactive' ? 'selected' : ''; ?>>Inactive</option>
        </select>
    </div>
    <div class="l-col-md-two">
        <label for="sort" class="ui-label">Sort</label>
        <select id="sort" class="ui-select" name="sort">
            <option value="newest" <?php echo $sort === 'newest' ? 'selected' : ''; ?>>Newest First</option>
            <option value="oldest" <?php echo $sort === 'oldest' ? 'selected' : ''; ?>>Oldest First</option>
            <option value="name_asc" <?php echo $sort === 'name_asc' ? 'selected' : ''; ?>>Name A-Z</option>
            <option value="name_desc" <?php echo $sort === 'name_desc' ? 'selected' : ''; ?>>Name Z-A</option>
            <option value="price_asc" <?php echo $sort === 'price_asc' ? 'selected' : ''; ?>>Price Low-High</option>
            <option value="price_desc" <?php echo $sort === 'price_desc' ? 'selected' : ''; ?>>Price High-Low</option>
            <option value="stock_asc" <?php echo $sort === 'stock_asc' ? 'selected' : ''; ?>>Stock Low-High</option>
            <option value="stock_desc" <?php echo $sort === 'stock_desc' ? 'selected' : ''; ?>>Stock High-Low</option>
        </select>
    </div>
    <div class="l-col-md-two">
        <label for="per_page" class="ui-label">Per Page</label>
        <select id="per_page" class="ui-select" name="per_page">
            <?php foreach ($perPageOptions as $size): ?>
                <option value="<?php echo $size; ?>" <?php echo $perPage === $size ? 'selected' : ''; ?>><?php echo $size; ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="l-col-md-two u-flex u-items-end u-gap-2">
        <button class="ui-button ui-button--navy u-w-full" type="submit">Apply</button>
        <a href="fabrics.php" class="ui-button ui-button--secondary u-w-full">Reset</a>
    </div>
</form>

<div class="ui-table-wrap">
    <table class="ui-table ui-table--striped u-align-middle admin-card-table">
        <thead class="ui-table__head--dark">
            <tr>
                <th>Image</th>
                <th>Product Name</th>
                <th>Product Type</th>
                <th>MRP</th>
                <th>Selling Price</th>
                <th>Quantity</th>
                <th>Variants</th>
                <th>Status</th>
                <th class="u-text-end">Actions</th>
            </tr>
        </thead>
        <tbody>
        <?php if (empty($products)): ?>
            <tr class="admin-empty-row"><td colspan="9" class="u-text-center u-text-muted">No products found.</td></tr>
        <?php endif; ?>

        <?php foreach ($products as $p): ?>
            <?php
                $stockVal = round((float) ($p['effective_stock'] ?? 0), 2);
                $unitType = in_array((string) ($p['unit_type'] ?? ''), ['meter', 'piece', 'set'], true) ? (string) $p['unit_type'] : 'meter';
                $isLowStock = $stockVal <= 3;
                $statusClass = ($p['status'] ?? 'inactive') === 'active' ? 'ui-badge--success' : (($p['status'] ?? '') === 'draft' ? 'ui-badge--warning' : 'ui-badge--neutral');
            ?>
            <tr class="<?php echo $isLowStock ? 'admin-row--warning' : ''; ?>">
                <td data-label="Image">
                    <?php if (!empty($p['list_image'])): ?>
                        <?php $adminImageAsset = fabric_image_asset_data((string) $p['list_image']); ?>
                        <img src="..<?php echo e((string) ($adminImageAsset['thumb_src'] ?? '')); ?>" width="60" class="u-rounded" alt="<?php echo e($p['name']); ?>">
                    <?php else: ?>
                        <span class="u-text-muted">No image</span>
                    <?php endif; ?>
                </td>
                <td data-label="Product Name">
                    <div class="u-font-semibold"><?php echo e($p['name']); ?></div>
                    <?php if (!empty($p['sku'])): ?>
                        <div class="u-text-muted u-text-small">SKU: <?php echo e($p['sku']); ?></div>
                    <?php endif; ?>
                    <?php if (!empty($p['product_code'])):?><div class="u-text-muted u-text-small">Product Code: <?php echo e($p['product_code']);?></div><?php endif;?>
                    <?php if (!empty($p['amazon_asin'])):?><div class="u-text-muted u-text-small">Amazon ASIN: <?php echo e($p['amazon_asin']);?></div><?php endif;?>
                </td>
                <td data-label="Product Type"><?php echo e($p['category'] ?: '-'); ?></td>
                <td data-label="MRP"><?php echo isset($p['price']) ? number_format((float) $p['price'], 2) : '0.00'; ?></td>
                <td data-label="Selling Price">
                    <?php echo number_format((float)((($p['sale_price']??0)>0)?$p['sale_price']:($p['price']??0)),2); ?>
                </td>
                <td data-label="Quantity">
                    <span class="<?php echo $isLowStock ? 'u-text-danger u-font-bold' : ''; ?>">
                        <?php echo e(format_quantity_by_unit($stockVal, $unitType)); ?><?php echo CommercePresenter::quantityUnitSuffix($unitType); ?>
                    </span>
                    <?php if ($isLowStock): ?>
                        <div class="u-text-small u-text-danger">Low stock</div>
                    <?php endif; ?>
                </td>
                <td data-label="Variants">
                    <?php if ((int)($p['variant_count'] ?? 0) > 0): ?>
                        <a href="edit-fabric.php?id=<?php echo (int)$p['id']; ?>#variants-card" class="ui-badge ui-badge--info u-text-ink u-no-decoration">
                            <?php echo (int)$p['variant_count']; ?> var
                        </a>
                    <?php else: ?>
                        <a href="edit-fabric.php?id=<?php echo (int)$p['id']; ?>#variants-card" class="ui-badge u-bg-soft u-text-muted u-no-decoration">Add</a>
                    <?php endif; ?>
                </td>
                <td data-label="Status">
                    <span class="ui-badge <?php echo $statusClass; ?>"><?php echo ucfirst((string) ($p['status'] ?? 'inactive')); ?></span>
                </td>
                <td data-label="Actions" class="u-text-end">
                    <a class="ui-button ui-button--small ui-button--secondary" href="edit-fabric.php?id=<?php echo (int) $p['id']; ?>"><?php echo ui_icon('pencil-square'); ?>Edit</a>
                    <form action="delete-fabric.php" method="POST" class="u-inline" data-confirm-modal data-confirm-title="Archive Product" data-confirm-message="Archive this product and hide it from storefront?" data-confirm-ok="Archive" data-confirm-variant="danger">
                        <?php echo csrf_field(); ?>
                        <input type="hidden" name="id" value="<?php echo (int) $p['id']; ?>">
                        <button class="ui-button ui-button--small ui-button--danger-outline"><?php echo ui_icon('archive'); ?>Archive</button>
                    </form>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>

<?php echo render_pagination($page, $pages, $state, 'page', $total, $perPage); ?>

<?php include 'partials/footer.php'; ?>

