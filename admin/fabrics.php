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
if (!in_array($status, ['', 'active', 'inactive'], true)) {
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
    $where[] = '(f.name LIKE ? OR f.sku LIKE ?)';
    $like = "%{$search}%";
    $types .= 'ss';
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
                f.id, f.name, f.category, f.image, f.sku,
                f.price, f.sale_price, f.stock, f.unit_type,
                f.stock_meters,
                CASE
                    WHEN COALESCE(fv.variant_count, 0) > 0 THEN COALESCE(fv.variant_stock, 0)
                    WHEN f.stock_meters IS NOT NULL AND f.stock_meters > 0 THEN f.stock_meters
                    ELSE COALESCE(f.stock, 0)
                END AS effective_stock,
                f.status, f.is_featured,
                f.gsm, f.width, f.moq, f.lead_time,
                COALESCE(fv.variant_count, 0) AS variant_count,
                COALESCE(fv.variant_stock, 0) AS variant_stock
            FROM fabrics f
            LEFT JOIN (
                SELECT
                    fabric_id,
                    COUNT(*) AS variant_count,
                    SUM(CASE WHEN unit_type_parent = 'meter' THEN stock_meters ELSE stock END) AS variant_stock
                FROM (
                    SELECT fv2.fabric_id,
                           fv2.stock,
                           fv2.stock_meters,
                           fab.unit_type AS unit_type_parent
                    FROM fabric_variants fv2
                    JOIN fabrics fab ON fab.id = fv2.fabric_id
                    WHERE fv2.is_active = 1
                ) sub
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

$metaTitle = 'Manage Products | Amber Fabrics';
$metaDescription = 'Admin page to manage products for Amber Fabrics.';
$metaKeywords = 'admin, products, manage, Amber Fabrics';
include 'partials/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-3">
    <div>
        <h1 class="mb-1">Products</h1>
        <p class="text-muted mb-0">Showing <?php echo count($products); ?> of <?php echo $total; ?> products</p>
    </div>
    <a class="btn btn-primary" href="add-fabric.php">Add Product</a>
</div>

<form class="row g-2 mb-3" method="GET" action="fabrics.php">
    <div class="col-md-4">
        <label class="form-label">Search</label>
        <input type="text" name="q" class="form-control" value="<?php echo e($search); ?>" placeholder="Product name or SKU">
    </div>
    <div class="col-md-2">
        <label class="form-label">Status</label>
        <select name="status" class="form-select">
            <option value="">All</option>
            <option value="active" <?php echo $status === 'active' ? 'selected' : ''; ?>>Active</option>
            <option value="inactive" <?php echo $status === 'inactive' ? 'selected' : ''; ?>>Inactive</option>
        </select>
    </div>
    <div class="col-md-2">
        <label class="form-label">Sort</label>
        <select class="form-select" name="sort">
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
    <div class="col-md-2">
        <label class="form-label">Per Page</label>
        <select class="form-select" name="per_page">
            <?php foreach ($perPageOptions as $size): ?>
                <option value="<?php echo $size; ?>" <?php echo $perPage === $size ? 'selected' : ''; ?>><?php echo $size; ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="col-md-2 d-flex align-items-end gap-2">
        <button class="btn btn-dark w-100" type="submit">Apply</button>
        <a href="fabrics.php" class="btn btn-outline-secondary w-100">Reset</a>
    </div>
</form>

<div class="table-responsive">
    <table class="table table-striped align-middle admin-card-table">
        <thead class="table-dark">
            <tr>
                <th>Image</th>
                <th>Product Name</th>
                <th>Category</th>
                <th>Price</th>
                <th>Sale Price</th>
                <th>Stock</th>
                <th>Variants</th>
                <th>Status</th>
                <th>Featured</th>
                <th class="text-end">Actions</th>
            </tr>
        </thead>
        <tbody>
        <?php if (empty($products)): ?>
            <tr class="admin-empty-row"><td colspan="10" class="text-center text-muted">No products found.</td></tr>
        <?php endif; ?>

        <?php foreach ($products as $p): ?>
            <?php
                $stockVal = round((float) ($p['effective_stock'] ?? 0), 2);
                $unitType = in_array((string) ($p['unit_type'] ?? ''), ['meter', 'piece', 'set'], true) ? (string) $p['unit_type'] : 'meter';
                $isLowStock = $stockVal <= 3;
                $statusClass = ($p['status'] ?? 'inactive') === 'active' ? 'bg-success' : 'bg-secondary';
                $featuredClass = !empty($p['is_featured']) ? 'bg-warning text-dark' : 'bg-light text-dark';
            ?>
            <tr class="<?php echo $isLowStock ? 'table-warning' : ''; ?>">
                <td data-label="Image">
                    <?php if (!empty($p['image'])): ?>
                        <?php $adminImageAsset = fabric_image_asset_data((string) $p['image']); ?>
                        <img src="..<?php echo e((string) ($adminImageAsset['thumb_src'] ?? '')); ?>" width="60" class="rounded" alt="<?php echo e($p['name']); ?>">
                    <?php else: ?>
                        <span class="text-muted">No image</span>
                    <?php endif; ?>
                </td>
                <td data-label="Product Name">
                    <div class="fw-semibold"><?php echo e($p['name']); ?></div>
                    <?php if (!empty($p['sku'])): ?>
                        <div class="text-muted small">SKU: <?php echo e($p['sku']); ?></div>
                    <?php endif; ?>
                    <?php if (!empty($p['gsm']) || !empty($p['width']) || !empty($p['moq'])): ?>
                        <div class="text-muted small">
                            <?php if (!empty($p['gsm'])): ?>GSM: <?php echo e($p['gsm']); ?><?php endif; ?>
                            <?php if (!empty($p['width'])): ?> | Width: <?php echo e($p['width']); ?><?php endif; ?>
                            <?php if (!empty($p['moq'])): ?> | MOQ: <?php echo e($p['moq']); ?><?php endif; ?>
                        </div>
                    <?php endif; ?>
                </td>
                <td data-label="Category"><?php echo e($p['category'] ?: '-'); ?></td>
                <td data-label="Price"><?php echo isset($p['price']) ? number_format((float) $p['price'], 2) : '0.00'; ?></td>
                <td data-label="Sale Price">
                    <?php echo ($p['sale_price'] !== null && $p['sale_price'] !== '') ? number_format((float) $p['sale_price'], 2) : '-'; ?>
                </td>
                <td data-label="Stock">
                    <span class="<?php echo $isLowStock ? 'text-danger fw-bold' : ''; ?>">
                        <?php echo e(format_quantity_by_unit($stockVal, $unitType)); ?><?php echo quantity_unit_suffix($unitType); ?>
                    </span>
                    <?php if ($isLowStock): ?>
                        <div class="small text-danger">Low stock</div>
                    <?php endif; ?>
                </td>
                <td data-label="Variants">
                    <?php if ((int)($p['variant_count'] ?? 0) > 0): ?>
                        <a href="edit-fabric.php?id=<?php echo (int)$p['id']; ?>#variants-card" class="badge bg-info text-dark text-decoration-none">
                            <?php echo (int)$p['variant_count']; ?> var
                        </a>
                    <?php else: ?>
                        <a href="edit-fabric.php?id=<?php echo (int)$p['id']; ?>#variants-card" class="badge bg-light text-muted text-decoration-none">Add</a>
                    <?php endif; ?>
                </td>
                <td data-label="Status">
                    <span class="badge <?php echo $statusClass; ?>"><?php echo ucfirst((string) ($p['status'] ?? 'inactive')); ?></span>
                </td>
                <td data-label="Featured">
                    <span class="badge <?php echo $featuredClass; ?>"><?php echo !empty($p['is_featured']) ? 'Yes' : 'No'; ?></span>
                </td>
                <td data-label="Actions" class="text-end">
                    <a class="btn btn-sm btn-outline-secondary" href="edit-fabric.php?id=<?php echo (int) $p['id']; ?>"><i class="bi bi-pencil-square me-1" aria-hidden="true"></i>Edit</a>
                    <form action="delete-fabric.php" method="POST" class="d-inline" data-confirm-modal data-confirm-title="Archive Product" data-confirm-message="Archive this product and hide it from storefront?" data-confirm-ok="Archive">
                        <?php echo csrf_field(); ?>
                        <input type="hidden" name="id" value="<?php echo (int) $p['id']; ?>">
                        <button class="btn btn-sm btn-outline-danger"><i class="bi bi-archive me-1" aria-hidden="true"></i>Archive</button>
                    </form>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>

<?php echo render_pagination($page, $pages, $state, 'page', $total, $perPage); ?>

<?php include 'partials/footer.php'; ?>

