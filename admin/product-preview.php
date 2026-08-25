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
<div class="ui-alert ui-alert--warning u-flex u-justify-between u-items-center">
    <span><strong>Private admin preview.</strong> Status: <?php echo e((string) $product['status']); ?></span>
    <a href="edit-fabric.php?id=<?php echo $id; ?>" class="ui-button ui-button--small ui-button--secondary">Back to editor</a>
</div>
<div class="l-grid l-grid--12 u-gap-4">
    <div class="l-col-lg-half"><div class="l-grid l-grid--12 u-gap-2">
        <?php foreach ($media as $item): if ($item['media_type'] !== 'image') continue; $asset = fabric_image_asset_data((string) $item['filename']); ?>
            <div class="l-col-half"><img class="admin-responsive-media u-rounded u-border" src="..<?php echo e((string) $asset['src']); ?>" alt="<?php echo e((string) $item['alt_text']); ?>"></div>
        <?php endforeach; ?>
    </div></div>
    <div class="l-col-lg-half">
        <span class="ui-badge ui-badge--neutral u-mb-2"><?php echo e((string) $product['product_type']); ?></span>
        <h1><?php echo e((string) $product['name']); ?></h1>
        <p class="u-text-large u-font-semibold"><?php echo e(money((float) (($product['sale_price'] ?? 0) > 0 ? $product['sale_price'] : $product['price']))); ?></p>
        <p><?php echo nl2br(e((string) $product['description'])); ?></p>
        <dl class="l-grid l-grid--12">
            <dt class="l-col-third">SKU</dt><dd class="l-col-eight"><?php echo e((string) $product['sku']); ?></dd>
            <dt class="l-col-third">Material</dt><dd class="l-col-eight"><?php echo e((string) ($catalogData['attr_material'] ?: $catalogData['attr_fabric'])); ?></dd>
            <dt class="l-col-third">Dispatch</dt><dd class="l-col-eight"><?php echo e((string) $product['dispatch_min_days']); ?>–<?php echo e((string) $product['dispatch_max_days']); ?> business days</dd>
        </dl>
    </div>
</div>
<?php include 'partials/footer.php'; ?>
