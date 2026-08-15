<?php
require_once __DIR__ . '/../includes/init.php';
require_admin();
$id = (int) ($_GET['id'] ?? 0);
$stmt = $conn->prepare('SELECT * FROM fabrics WHERE id=? LIMIT 1');
$stmt->bind_param('i', $id);
$stmt->execute();
$product = $stmt->get_result()->fetch_assoc();
if (!$product) { http_response_code(404); exit('Product not found.'); }
$media = ProductAdminService::media($conn, $id);
$catalogData = ProductAdminService::catalogData($product);
$metaTitle = 'Preview: ' . (string) $product['name'];
include 'partials/header.php';
?>
<div class="alert alert-warning d-flex justify-content-between align-items-center">
    <span><strong>Private admin preview.</strong> Status: <?php echo e((string) $product['status']); ?></span>
    <a href="edit-fabric.php?id=<?php echo $id; ?>" class="btn btn-sm btn-outline-dark">Back to editor</a>
</div>
<div class="row g-4">
    <div class="col-lg-6"><div class="row g-2">
        <?php foreach ($media as $item): if ($item['media_type'] !== 'image') continue; $asset = fabric_image_asset_data((string) $item['filename']); ?>
            <div class="col-6"><img class="img-fluid rounded border" src="..<?php echo e((string) $asset['src']); ?>" alt="<?php echo e((string) $item['alt_text']); ?>"></div>
        <?php endforeach; ?>
    </div></div>
    <div class="col-lg-6">
        <span class="badge bg-secondary mb-2"><?php echo e((string) $product['product_type']); ?></span>
        <h1><?php echo e((string) $product['name']); ?></h1>
        <p class="lead"><?php echo e(money((float) (($product['sale_price'] ?? 0) > 0 ? $product['sale_price'] : $product['price']))); ?></p>
        <p><?php echo nl2br(e((string) $product['description'])); ?></p>
        <dl class="row">
            <dt class="col-4">SKU</dt><dd class="col-8"><?php echo e((string) $product['sku']); ?></dd>
            <dt class="col-4">Material</dt><dd class="col-8"><?php echo e((string) ($catalogData['attr_material'] ?: $catalogData['attr_fabric'])); ?></dd>
            <dt class="col-4">Dispatch</dt><dd class="col-8"><?php echo e((string) $product['dispatch_min_days']); ?>–<?php echo e((string) $product['dispatch_max_days']); ?> business days</dd>
        </dl>
    </div>
</div>
<?php include 'partials/footer.php'; ?>
