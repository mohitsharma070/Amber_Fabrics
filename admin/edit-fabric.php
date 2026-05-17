<?php
require_once __DIR__ . '/../includes/init.php';
require_admin();

$id = isset($_GET['id']) ? (int) $_GET['id'] : 0;
$stmt = $conn->prepare("SELECT * FROM fabrics WHERE id = ?");
$stmt->bind_param('i', $id);
$stmt->execute();
$fabric = $stmt->get_result()->fetch_assoc();

if (!$fabric) {
    flash('error', 'Product not found.');
    redirect('fabrics.php');
}

function cleanup_placeholder_variants_for_product(mysqli $conn, int $fabricId): void
{
    if ($fabricId <= 0) {
        return;
    }

    $countStmt = $conn->prepare(
        "SELECT COUNT(*) AS cnt
         FROM fabric_variants
         WHERE fabric_id = ?
           AND is_active = 1
           AND (
                (TRIM(COALESCE(color, '')) <> '' AND LOWER(TRIM(COALESCE(color, ''))) <> 'default')
                OR TRIM(COALESCE(size, '')) <> ''
           )"
    );
    $countStmt->bind_param('i', $fabricId);
    $countStmt->execute();
    $hasReal = (int) (($countStmt->get_result()->fetch_assoc()['cnt'] ?? 0)) > 0;
    if (!$hasReal) {
        return;
    }

    $legacyStmt = $conn->prepare(
        "SELECT id
         FROM fabric_variants
         WHERE fabric_id = ?
           AND (TRIM(COALESCE(color, '')) = '' OR LOWER(TRIM(COALESCE(color, ''))) = 'default')
           AND TRIM(COALESCE(size, '')) = ''"
    );
    $legacyStmt->bind_param('i', $fabricId);
    $legacyStmt->execute();
    $legacyRows = $legacyStmt->get_result()->fetch_all(MYSQLI_ASSOC);

    foreach ($legacyRows as $lr) {
        $legacyId = (int) ($lr['id'] ?? 0);
        if ($legacyId <= 0) {
            continue;
        }
        $check = $conn->prepare("SELECT COUNT(*) AS cnt FROM order_items WHERE variant_id = ? LIMIT 1");
        $check->bind_param('i', $legacyId);
        $check->execute();
        $hasOrders = ((int) (($check->get_result()->fetch_assoc()['cnt'] ?? 0)) > 0);
        if ($hasOrders) {
            $deactivate = $conn->prepare("UPDATE fabric_variants SET is_active = 0 WHERE id = ? AND fabric_id = ?");
            $deactivate->bind_param('ii', $legacyId, $fabricId);
            $deactivate->execute();
        } else {
            $delete = $conn->prepare("DELETE FROM fabric_variants WHERE id = ? AND fabric_id = ?");
            $delete->bind_param('ii', $legacyId, $fabricId);
            $delete->execute();
        }
    }
}

cleanup_placeholder_variants_for_product($conn, $id);
$variants = get_fabric_variants($conn, $id);

function is_placeholder_variant_row(array $variantRow): bool
{
    $vColor = strtolower(trim((string) ($variantRow['color'] ?? '')));
    $vSize = trim((string) ($variantRow['size'] ?? ''));
    return ($vSize === '') && ($vColor === '' || $vColor === 'default');
}

$hasRealVariants = false;
foreach ($variants as $variantRow) {
    if (!is_placeholder_variant_row($variantRow)) {
        $hasRealVariants = true;
        break;
    }
}

function variant_has_sellable_stock(array $variantRow, string $unitType): bool
{
    $unit = in_array($unitType, ['meter', 'piece', 'set'], true) ? $unitType : 'meter';
    if ((int) ($variantRow['is_active'] ?? 0) !== 1) {
        return false;
    }
    if ($unit === 'meter') {
        return (float) ($variantRow['stock_meters'] ?? 0) > 0;
    }
    return (float) ($variantRow['stock'] ?? 0) > 0;
}

$categories = [];
try {
    $catStmt = $conn->prepare("SELECT name, slug FROM categories WHERE status = 'active' ORDER BY name ASC");
    $catStmt->execute();
    $categories = $catStmt->get_result()->fetch_all(MYSQLI_ASSOC);
} catch (Throwable $e) {
    $categories = [];
}

// Backward-safe defaults for older records.
$old = [
    'name' => (string) ($fabric['name'] ?? ''),
    'category' => (string) ($fabric['category'] ?? ''),
    'unit_type' => in_array((string) ($fabric['unit_type'] ?? ''), ['meter', 'piece', 'set'], true) ? (string) $fabric['unit_type'] : 'meter',
    'price' => ($fabric['price'] !== null && $fabric['price'] !== '') ? (string) $fabric['price'] : (string) ($fabric['price_inr'] ?? ''),
    'sale_price' => (string) ($fabric['sale_price'] ?? ''),
    'cost_price' => (string) ($fabric['cost_price'] ?? ''),
    'stock' => format_meter_quantity(((float) ($fabric['stock_meters'] ?? 0) > 0) ? (float) $fabric['stock_meters'] : (float) ($fabric['stock'] ?? 0)),
    'sku' => (string) ($fabric['sku'] ?? ''),
    'size' => (string) ($fabric['size'] ?? ''),
    'meter_options' => (string) ($fabric['meter_options'] ?? ''),
    'print_style' => (string) ($fabric['print_style'] ?? ''),
    'color' => (string) ($fabric['color'] ?? ''),
    'material' => (string) ($fabric['material'] ?? ''),
    'gsm' => (string) ($fabric['gsm'] ?? ''),
    'width' => (string) ($fabric['width'] ?? ''),
    'moq' => (string) ($fabric['moq'] ?? ''),
    'lead_time' => (string) ($fabric['lead_time'] ?? ''),
    'dispatch_time' => (string) ($fabric['dispatch_time'] ?? ''),
    'wash_care' => (string) ($fabric['wash_care'] ?? ''),
    'description' => (string) ($fabric['description'] ?? ''),
    'status' => (string) ($fabric['status'] ?? 'active'),
    'is_featured' => !empty($fabric['is_featured']) ? 1 : 0,
    'is_available' => !empty($fabric['is_available']) ? 1 : 0,
    'min_order_meters' => format_meter_quantity((float) ($fabric['min_order_meters'] ?? 1)),
    'qty_step' => ((float) ($fabric['qty_step'] ?? 0) > 0) ? rtrim(rtrim((string) ($fabric['qty_step'] ?? ''), '0'), '.') : '',
];

$errors = [];

if (isset($_POST['submit'])) {
    if (!verify_csrf()) {
        flash('error', 'Invalid session token. Please try again.');
        redirect("edit-fabric.php?id={$id}");
    }

    $name          = trim($_POST['name']          ?? '');
    $category      = trim($_POST['category']      ?? '');
    $unitType      = trim((string) ($_POST['unit_type'] ?? 'meter'));
    $price         = trim($_POST['price']         ?? '');
    $salePrice     = trim($_POST['sale_price']    ?? '');
    $costPrice     = trim($_POST['cost_price']    ?? '');
    $stock         = (string) ($fabric['stock'] ?? '0');
    $size          = (string) ($fabric['size'] ?? '');
    $meterOptions  = trim($_POST['meter_options']  ?? '');
    $printStyle    = trim($_POST['print_style']    ?? '');
    $color         = (string) ($fabric['color'] ?? '');
    $material      = trim($_POST['material']      ?? '');
    $gsm           = trim($_POST['gsm']           ?? '');
    $sku           = generate_unique_fabric_sku($conn, $category, $material, '', $gsm, $id);
    $width         = trim($_POST['width']         ?? '');
    $moq           = trim($_POST['moq']           ?? '');
    $lead          = trim($_POST['lead_time']     ?? '');
    $dispatchTime  = trim($_POST['dispatch_time'] ?? '');
    $washCare      = trim($_POST['wash_care']     ?? '');
    $description   = trim($_POST['description']   ?? '');
    $status        = trim($_POST['status']        ?? 'active');
    $isFeatured    = isset($_POST['is_featured']) ? 1 : 0;
    $isAvailInput  = isset($_POST['is_available']) ? 1 : 0;
    $minOrder      = normalize_meter_quantity($_POST['min_order_meters'] ?? 1, 1.0);
    $qtyStepRaw    = trim($_POST['qty_step'] ?? '');
    $qtyStep       = ($qtyStepRaw !== '' && is_numeric($qtyStepRaw) && (float) $qtyStepRaw > 0) ? round((float) $qtyStepRaw, 4) : 0.0;

    $old = [
        'name' => $name,
        'category' => $category,
        'unit_type' => $unitType,
        'price' => $price,
        'sale_price' => $salePrice,
        'cost_price' => $costPrice,
        'stock' => $stock,
        'sku' => $sku,
        'size' => $size,
        'meter_options' => $meterOptions,
        'print_style' => $printStyle,
        'color' => $color,
        'material' => $material,
        'gsm' => $gsm,
        'width' => $width,
        'moq' => $moq,
        'lead_time' => $lead,
        'dispatch_time' => $dispatchTime,
        'wash_care' => $washCare,
        'description' => $description,
        'status' => $status,
        'is_featured' => $isFeatured,
        'is_available' => $isAvailInput,
        'min_order_meters' => format_meter_quantity($minOrder),
        'qty_step' => $qtyStepRaw,
    ];

    if ($name === '') {
        $errors['name'] = 'Product name is required.';
    }
    if ($category === '') {
        $errors['category'] = 'Category is required.';
    }
    if (!in_array($unitType, ['meter', 'piece', 'set'], true)) {
        $errors['unit_type'] = 'Select a valid unit type.';
    }
    if ($price === '' || !is_numeric($price) || (float) $price < 0) {
        $errors['price'] = 'Regular price is required and must be 0 or more.';
    }
    if ($salePrice !== '' && (!is_numeric($salePrice) || (float) $salePrice < 0)) {
        $errors['sale_price'] = 'Sale price must be 0 or more.';
    }
    if ($costPrice === '' || !is_numeric($costPrice) || (float) $costPrice < 0) {
        $errors['cost_price'] = 'Cost price is required and must be 0 or more.';
    }
    if (!in_array($status, ['active', 'inactive'], true)) {
        $errors['status'] = 'Invalid status selected.';
    }

    $imageName = (string) ($fabric['image'] ?? '');
    $image2Name = (string) ($fabric['image2'] ?? '');
    $image3Name = (string) ($fabric['image3'] ?? '');
    $image4Name = (string) ($fabric['image4'] ?? '');
    $videoName = (string) ($fabric['video'] ?? '');
    $imageFields = [
        'image' => ['label' => 'Main image', 'existing' => $imageName],
        'image2' => ['label' => 'Image 2', 'existing' => $image2Name],
        'image3' => ['label' => 'Image 3', 'existing' => $image3Name],
        'image4' => ['label' => 'Image 4', 'existing' => $image4Name],
    ];

    foreach ($imageFields as $field => $meta) {
        if (empty($_FILES[$field]['name'])) {
            continue;
        }
        $file = $_FILES[$field];
        try {
            $saved = save_fabric_image_upload($file, (string) $meta['label']);
        } catch (Throwable $e) {
            $errors[$field] = $e->getMessage();
            continue;
        }
        if (!empty($meta['existing'])) {
            image_pipeline_delete_files(__DIR__ . '/../images/fabrics', (string) $meta['existing']);
        }
        if ($field === 'image') { $imageName = $saved; }
        if ($field === 'image2') { $image2Name = $saved; }
        if ($field === 'image3') { $image3Name = $saved; }
        if ($field === 'image4') { $image4Name = $saved; }
    }

    if (!empty($_FILES['video']['name'])) {
        $file = $_FILES['video'];
        $allowedVideoExt = ['mp4', 'webm', 'ogg'];
        $allowedVideoMime = ['video/mp4', 'video/webm', 'video/ogg'];
        $maxVideoSize = 25 * 1024 * 1024;
        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        $mime = mime_content_type($file['tmp_name']) ?: '';
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            $errors['video'] = 'Video upload failed. Please try again.';
        } elseif ($file['size'] > $maxVideoSize) {
            $errors['video'] = 'Video must be under 25MB.';
        } elseif (!in_array($ext, $allowedVideoExt, true) || !in_array($mime, $allowedVideoMime, true)) {
            $errors['video'] = 'Video must be MP4, WEBM or OGG.';
        } else {
            $newVideoName = random_filename($file['name']);
            $target = __DIR__ . "/../images/fabrics/{$newVideoName}";
            if (!move_uploaded_file($file['tmp_name'], $target)) {
                $errors['video'] = 'Video upload failed.';
            } else {
                if (!empty($videoName)) {
                    @unlink(__DIR__ . "/../images/fabrics/{$videoName}");
                }
                $videoName = $newVideoName;
            }
        }
    }

    if (empty($errors)) {
        $priceVal      = (float) $price;
        $salePriceVal  = ($salePrice !== '') ? (float) $salePrice : null;
        $costPriceVal  = (float) $costPrice;
        $stockVal = (float) ($fabric['stock'] ?? 0);
        $stockMeters = (float) ($fabric['stock_meters'] ?? 0);
        $size = (string) ($fabric['size'] ?? '');
        $color = (string) ($fabric['color'] ?? '');
        $minOrderVal = ($unitType === 'meter') ? round($minOrder, 2) : 1.00;
        $isAvailable   = ($status === 'active' && $isAvailInput === 1) ? 1 : 0;
        if ($hasRealVariants) {
            $latestVariants = get_fabric_variants($conn, $id);
            $hasSellableVariant = false;
            foreach ($latestVariants as $vrow) {
                if (variant_has_sellable_stock($vrow, $unitType)) {
                    $hasSellableVariant = true;
                    break;
                }
            }
            $isAvailable = ($status === 'active' && $hasSellableVariant) ? 1 : 0;
        }
        $priceInrVal   = $priceVal;
        $priceUsdVal   = null;

        $upd = $conn->prepare(
            "UPDATE fabrics SET
                name = ?, sku = ?, category = ?, unit_type = ?, meter_options = ?, print_style = ?, material = ?, gsm = ?, width = ?, moq = ?, lead_time = ?, dispatch_time = ?,
                size = ?, color = ?, description = ?, wash_care = ?, image = ?,
                image2 = ?, image3 = ?, image4 = ?, video = ?,
                price = ?, sale_price = ?, cost_price = ?, price_inr = ?, price_usd = ?,
                stock = ?, stock_meters = ?, min_order_meters = ?, qty_step = ?,
                is_featured = ?, status = ?, is_available = ?
             WHERE id = ?"
        );
        $upd->bind_param(
            'sssssssssssssssssssssdddddddddisii',
            $name, $sku, $category, $unitType, $meterOptions, $printStyle, $material, $gsm, $width, $moq, $lead, $dispatchTime,
            $size, $color, $description, $washCare, $imageName, $image2Name, $image3Name, $image4Name, $videoName,
            $priceVal, $salePriceVal, $costPriceVal, $priceInrVal, $priceUsdVal,
            $stockVal, $stockMeters, $minOrderVal, $qtyStep,
            $isFeatured, $status, $isAvailable,
            $id
        );
        $upd->execute();

        flash('success', 'Product updated.');
        redirect('fabrics.php');
    }
}
?>

<?php
$metaTitle = 'Edit Product | Amber Fabrics';
$metaDescription = 'Admin page to edit product details in Amber Fabrics shop.';
$metaKeywords = 'admin, edit product, catalog, Amber Fabrics';
include 'partials/header.php'; ?>

<h1 class="mb-4">Edit Product</h1>

<?php if (!empty($errors)): ?>
    <div class="alert alert-warning">Please fix the errors below.</div>
<?php endif; ?>
<?php if ($hasRealVariants): ?>
    <div class="alert alert-info">
        This product has variants. Variant stock, colour and sizes are managed in the variants section below.
    </div>
<?php endif; ?>

<div class="card mb-3">
    <div class="card-body py-2">
        <ul class="nav nav-pills product-editor-tabs" id="productEditorTabs">
            <li class="nav-item"><button type="button" class="nav-link active product-editor-tab" data-editor-tab="details">Details</button></li>
            <li class="nav-item"><button type="button" class="nav-link product-editor-tab" data-editor-tab="pricing">Pricing & Inventory</button></li>
            <li class="nav-item"><button type="button" class="nav-link product-editor-tab" data-editor-tab="content">Content</button></li>
            <li class="nav-item"><a class="nav-link" href="#variants-card">Variants</a></li>
        </ul>
    </div>
</div>

<form method="POST" enctype="multipart/form-data" class="row g-3" id="product-editor-form">
    <?php echo csrf_field(); ?>
    <div class="col-sm-6">
        <label class="form-label">Product Name *</label>
        <input type="text" name="name" class="<?php echo form_class($errors, 'name'); ?>" required value="<?php echo e($old['name']); ?>">
        <?php echo form_error($errors, 'name'); ?>
    </div>
    <div class="col-sm-6">
        <label class="form-label">Category *</label>
        <select name="category" class="<?php echo form_class($errors, 'category', 'form-select'); ?>" required>
            <option value="">Select Category</option>
            <?php foreach ($categories as $cat): ?>
                <option value="<?php echo e($cat['slug']); ?>" <?php echo $old['category'] === $cat['slug'] ? 'selected' : ''; ?>>
                    <?php echo e($cat['name']); ?>
                </option>
            <?php endforeach; ?>
        </select>
        <?php echo form_error($errors, 'category'); ?>
    </div>
    <div class="col-sm-6">
        <label class="form-label">Unit Type *</label>
        <select name="unit_type" class="<?php echo form_class($errors, 'unit_type', 'form-select'); ?>" required>
            <option value="meter" <?php echo $old['unit_type'] === 'meter' ? 'selected' : ''; ?>>Meter (decimal qty, e.g. 1.5m)</option>
            <option value="piece" <?php echo $old['unit_type'] === 'piece' ? 'selected' : ''; ?>>Piece (whole numbers)</option>
            <option value="set" <?php echo $old['unit_type'] === 'set' ? 'selected' : ''; ?>>Set (whole numbers)</option>
        </select>
        <?php echo form_error($errors, 'unit_type'); ?>
    </div>
    <div class="col-sm-6">
        <label class="form-label">SKU <small class="text-muted">(auto-generated)</small></label>
        <input type="text" id="sku_preview" class="form-control" value="<?php echo e($old['sku']); ?>" readonly>
        <input type="hidden" name="sku" id="sku_hidden" value="<?php echo e($old['sku']); ?>">
        <small class="text-muted">Generated from Category + Material + Color + GSM.</small>
    </div>
    <div class="col-6 col-md-3">
        <label class="form-label">Regular Price *</label>
        <input type="number" step="0.01" min="0" name="price" class="<?php echo form_class($errors, 'price'); ?>" required value="<?php echo e($old['price']); ?>">
        <?php echo form_error($errors, 'price'); ?>
    </div>
    <div class="col-6 col-md-3">
        <label class="form-label">Sale Price</label>
        <input type="number" step="0.01" min="0" name="sale_price" class="<?php echo form_class($errors, 'sale_price'); ?>" value="<?php echo e($old['sale_price']); ?>">
        <?php echo form_error($errors, 'sale_price'); ?>
    </div>
    <div class="col-6 col-md-3">
        <label class="form-label">Cost Price *</label>
        <input type="number" step="0.01" min="0" name="cost_price" class="<?php echo form_class($errors, 'cost_price'); ?>" required value="<?php echo e($old['cost_price']); ?>">
        <?php echo form_error($errors, 'cost_price'); ?>
    </div>
    <div class="col-6 col-md-3">
        <label class="form-label">Min. Order Qty</label>
        <input type="number" step="0.01" min="0" name="min_order_meters" class="form-control" value="<?php echo e($old['min_order_meters']); ?>" placeholder="e.g. 1">
    </div>
    <div class="col-6 col-md-3">
        <label class="form-label">Quantity Step <small class="text-muted">(0 = auto)</small></label>
        <input type="number" step="0.0001" min="0" name="qty_step" class="form-control" value="<?php echo e($old['qty_step']); ?>" placeholder="e.g. 0.5">
    </div>
    <div class="col-6 col-md-4" id="meter_options_row">
        <label class="form-label">Meter Options <small class="text-muted">(quick quantity options, comma separated: e.g. 1, 1.5, 2, 2.5)</small></label>
        <input type="text" name="meter_options" class="form-control" placeholder="e.g. 1, 1.5, 2, 2.5" value="<?php echo e($old['meter_options']); ?>">
    </div>
    <div class="col-sm-6">
        <label class="form-label">Material / Fabric</label>
        <input type="text" name="material" class="form-control" value="<?php echo e($old['material']); ?>">
    </div>
    <div class="col-6 col-md-4">
        <label class="form-label">Print Style</label>
        <input type="text" name="print_style" class="form-control" placeholder="e.g. Floral, Solid, Block Print" value="<?php echo e($old['print_style']); ?>">
    </div>
    <div class="col-6 col-md-4">
        <label class="form-label">GSM</label>
        <input type="text" name="gsm" class="form-control" value="<?php echo e($old['gsm']); ?>">
    </div>
    <div class="col-6 col-md-4">
        <label class="form-label">Width</label>
        <input type="text" name="width" class="form-control" value="<?php echo e($old['width']); ?>">
    </div>
    <div class="col-sm-4">
        <label class="form-label">MOQ (International Buyers)</label>
        <input type="text" name="moq" class="form-control" value="<?php echo e($old['moq']); ?>">
    </div>
    <div class="col-sm-4">
        <label class="form-label">Lead Time (International Buyers)</label>
        <input type="text" name="lead_time" class="form-control" value="<?php echo e($old['lead_time']); ?>">
    </div>
    <div class="col-sm-4">
        <label class="form-label">Dispatch Time (India Orders)</label>
        <input type="text" name="dispatch_time" class="form-control" value="<?php echo e($old['dispatch_time']); ?>">
    </div>
    <div class="col-12 col-md-4">
        <label class="form-label">Status *</label>
        <select name="status" class="<?php echo form_class($errors, 'status', 'form-select'); ?>" required>
            <option value="active" <?php echo $old['status'] === 'active' ? 'selected' : ''; ?>>Active</option>
            <option value="inactive" <?php echo $old['status'] === 'inactive' ? 'selected' : ''; ?>>Inactive</option>
        </select>
        <?php echo form_error($errors, 'status'); ?>
    </div>
    <div class="col-12">
        <div class="form-check form-check-inline">
            <input class="form-check-input" type="checkbox" name="is_featured" id="is_featured" <?php echo $old['is_featured'] ? 'checked' : ''; ?>>
            <label class="form-check-label" for="is_featured">Featured Product</label>
        </div>
        <?php if (!$hasRealVariants): ?>
        <div class="form-check form-check-inline">
            <input class="form-check-input" type="checkbox" name="is_available" id="is_available" <?php echo $old['is_available'] ? 'checked' : ''; ?>>
            <label class="form-check-label" for="is_available">Available for purchase</label>
        </div>
        <?php else: ?>
        <span class="text-muted small ms-2">Availability is auto-derived from active variants with stock.</span>
        <?php endif; ?>
    </div>
    <div class="col-12">
        <label class="form-label">Wash Care</label>
        <textarea name="wash_care" rows="3" class="form-control"><?php echo e($old['wash_care']); ?></textarea>
    </div>
    <div class="col-12">
        <label class="form-label">Description</label>
        <textarea name="description" rows="4" class="form-control"><?php echo e($old['description']); ?></textarea>
    </div>
    <div class="col-12">
        <button name="submit" class="btn btn-primary">Update Product</button>
        <a href="fabrics.php" class="btn btn-outline-secondary">Back</a>
    </div>
</form>

<script nonce="<?php echo $cspNonce; ?>">
(function () {
    var formEl = document.getElementById('product-editor-form');
    var tabButtons = Array.prototype.slice.call(document.querySelectorAll('.product-editor-tab'));
    var unitSelect = document.querySelector('select[name="unit_type"]');
    var minOrderInput = document.querySelector('input[name="min_order_meters"]');
    var qtyStepInput = document.querySelector('input[name="qty_step"]');
    var categoryInput = document.querySelector('select[name="category"]');
    var materialInput = document.querySelector('input[name="material"]');
    var gsmInput = document.querySelector('input[name="gsm"]');
    var skuPreview = document.getElementById('sku_preview');
    var skuHidden = document.getElementById('sku_hidden');
    if (!unitSelect) return;

    function assignSections() {
        if (!formEl) return;
        Array.prototype.forEach.call(formEl.children, function (col) {
            if (!col || !col.querySelector) return;
            var submitBtn = col.querySelector('button[name=\"submit\"]');
            var labelEl = col.querySelector('label.form-label, .form-check-label');
            if (submitBtn) {
                col.dataset.editorSection = 'actions';
                return;
            }
            var text = (labelEl ? labelEl.textContent : '').toLowerCase();
            var section = 'details';
            if (
                text.indexOf('price') !== -1 ||
                text.indexOf('order qty') !== -1 ||
                text.indexOf('quantity step') !== -1 ||
                text.indexOf('status') !== -1 ||
                text.indexOf('featured') !== -1 ||
                text.indexOf('available') !== -1
            ) {
                section = 'pricing';
            }
            if (text.indexOf('wash care') !== -1 || text.indexOf('description') !== -1) {
                section = 'content';
            }
            col.dataset.editorSection = section;
        });
    }

    function setSection(section) {
        if (!formEl) return;
        Array.prototype.forEach.call(formEl.children, function (col) {
            var colSection = col.dataset.editorSection || 'details';
            var show = colSection === section || colSection === 'actions';
            col.classList.toggle('d-none', !show);
        });
        tabButtons.forEach(function (btn) {
            btn.classList.toggle('active', btn.getAttribute('data-editor-tab') === section);
        });
    }

    function applyUnitRules() {
        var unit = unitSelect.value;
        var isMeter = unit === 'meter';
        var isPiece = unit === 'piece';
        var isWhole = isPiece || unit === 'set';
        var meterOptionsRow = document.getElementById('meter_options_row');
        if (meterOptionsRow) {
            meterOptionsRow.style.display = isMeter ? '' : 'none';
        }
        if (minOrderInput) {
            minOrderInput.step = isWhole ? '1' : '0.01';
        }
        if (qtyStepInput) {
            qtyStepInput.placeholder = isMeter ? 'e.g. 0.5' : '1';
        }
    }

    unitSelect.addEventListener('change', applyUnitRules);
    applyUnitRules();

    function skuPart(value) {
        return String(value || '')
            .trim()
            .toUpperCase()
            .replace(/[^A-Z0-9]+/g, '-')
            .replace(/^-+|-+$/g, '');
    }

    function updateSkuPreview() {
        if (!skuPreview || !skuHidden) return;
        var parts = [
            categoryInput ? skuPart(categoryInput.value) : '',
            materialInput ? skuPart(materialInput.value) : '',
            gsmInput ? skuPart(gsmInput.value) : ''
        ].filter(Boolean);
        var sku = parts.length ? parts.join('-') : 'SKU';
        skuPreview.value = sku;
        skuHidden.value = sku;
    }

    [categoryInput, materialInput, gsmInput].forEach(function (el) {
        if (el) {
            el.addEventListener('input', updateSkuPreview);
            el.addEventListener('change', updateSkuPreview);
        }
    });

    tabButtons.forEach(function (btn) {
        btn.addEventListener('click', function () {
            setSection(btn.getAttribute('data-editor-tab') || 'details');
        });
    });

    assignSections();
    setSection('details');
    updateSkuPreview();
})();
</script>

<!-- ═══════════════════════════════════════════════════════════════════════
     VARIANTS SECTION
═══════════════════════════════════════════════════════════════════════════ -->
<?php
$isNewProduct = !empty($_GET['new_product']);
$variantSizePolicy = get_variant_size_policy_by_category((string) ($fabric['category'] ?? ''));
$variantSizeMode = (string) ($variantSizePolicy['mode'] ?? 'preset_with_custom');
$variantSizePresets = array_values((array) ($variantSizePolicy['sizes'] ?? []));
$isSetUnitType = ((string) ($fabric['unit_type'] ?? '') === 'set');
$variantUnitType = in_array((string) ($fabric['unit_type'] ?? ''), ['meter', 'piece', 'set'], true)
    ? (string) $fabric['unit_type']
    : 'meter';
?>

<?php if ($isNewProduct): ?>
<div class="alert alert-success alert-dismissible fade show mt-3 mx-3" role="alert">
    <strong>Product created!</strong> Now add colour &amp; size variants below to set up per-variant stock.
    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
<?php endif; ?>

<div class="card mt-4 mx-3 mb-4" id="variants-card">
    <div class="card-header d-flex justify-content-between align-items-center">
        <h5 class="mb-0">Colour &amp; Size Variants <small class="text-muted fw-normal">(stock is tracked per variant)</small></h5>
        <button type="button" class="btn btn-sm btn-primary" id="variants-add-btn">
            <i class="bi bi-plus-lg"></i> Add Variant
        </button>
    </div>
    <div class="card-body p-0">
        <!-- Add / Edit inline form (hidden by default) -->
        <div id="variant-form-container" class="border-bottom p-3 d-none bg-light">
            <h6 id="variant-form-title">Add Variant</h6>
            <input type="hidden" id="vf_variant_id" value="0">
            <div class="row g-2">
                <div class="col-sm-3">
                    <label class="form-label form-label-sm">Colour</label>
                    <input type="text" id="vf_color" class="form-control form-control-sm" placeholder="e.g. Red">
                </div>
                <div class="col-sm-3">
                    <label class="form-label form-label-sm">Size</label>
                    <div id="vf_size_group">
                        <select id="vf_size_preset" class="form-select form-select-sm">
                            <option value="">Select size</option>
<?php foreach ($variantSizePresets as $presetSize): ?>
                            <option value="<?php echo e($presetSize); ?>"><?php echo e($presetSize); ?></option>
<?php endforeach; ?>
                            <option value="__custom__">Custom size</option>
                        </select>
                        <input type="text" id="vf_size_custom" class="form-control form-control-sm mt-1 d-none" placeholder="Enter one size only">
                        <input type="hidden" id="vf_size" value="">
                        <small id="vf_size_hint" class="text-muted">
                            <?php echo $variantSizeMode === 'hidden'
                                ? 'Size is not used for Fabric by meter products.'
                                : 'Preset sizes by category; use Custom if needed.'; ?>
                        </small>
                    </div>
                </div>
                <div class="col-sm-2" id="vf_pack_controls" <?php echo $isSetUnitType ? '' : 'style="display:none"'; ?>>
                    <label class="form-label form-label-sm">Units per Set</label>
                    <input type="number" id="vf_units_per_set" class="form-control form-control-sm" value="1" min="1" step="1">
                    <input type="text" id="vf_pack_label" class="form-control form-control-sm mt-1" placeholder="Pack of N">
                </div>
                <div class="col-sm-2">
                    <label class="form-label form-label-sm">SKU <small class="text-muted">(auto)</small></label>
                    <input type="text" id="vf_sku" class="form-control form-control-sm" placeholder="Auto-generated on save" readonly>
                </div>
                <div class="col-sm-3">
                    <label class="form-label form-label-sm">Variant Image 1</label>
                    <input type="hidden" id="vf_image" value="">
                    <input type="file" id="vf_image_file" class="form-control form-control-sm" accept="image/*">
                </div>
                <div class="col-sm-3">
                    <label class="form-label form-label-sm">Variant Image 2</label>
                    <input type="hidden" id="vf_image2" value="">
                    <input type="file" id="vf_image2_file" class="form-control form-control-sm" accept="image/*">
                </div>
                <div class="col-sm-3">
                    <label class="form-label form-label-sm">Variant Image 3</label>
                    <input type="hidden" id="vf_image3" value="">
                    <input type="file" id="vf_image3_file" class="form-control form-control-sm" accept="image/*">
                </div>
                <div class="col-sm-3">
                    <label class="form-label form-label-sm">Variant Image 4</label>
                    <input type="hidden" id="vf_image4" value="">
                    <input type="file" id="vf_image4_file" class="form-control form-control-sm" accept="image/*">
                </div>
                <div class="col-sm-3">
                    <label class="form-label form-label-sm">Variant Video</label>
                    <input type="hidden" id="vf_video" value="">
                    <input type="file" id="vf_video_file" class="form-control form-control-sm" accept="video/mp4,video/webm,video/ogg">
                </div>
                <div class="col-sm-2">
                    <label class="form-label form-label-sm">Price Override <small class="text-muted">(optional)</small></label>
                    <input type="number" id="vf_price_override" class="form-control form-control-sm" placeholder="Leave blank = Base Product Price" min="0" step="0.01">
                </div>
                <div class="col-sm-1" id="vf_stock_pcs_wrap">
                    <label class="form-label form-label-sm">Stock (pcs)</label>
                    <input type="number" id="vf_stock" class="form-control form-control-sm" value="0" min="0" step="1">
                </div>
                <div class="col-sm-1" id="vf_stock_m_wrap">
                    <label class="form-label form-label-sm">Stock (m)</label>
                    <input type="number" id="vf_stock_meters" class="form-control form-control-sm" value="0" min="0" step="0.01">
                </div>
                <div class="col-sm-1 d-flex align-items-end">
                    <div class="form-check ms-1">
                        <input class="form-check-input" type="checkbox" id="vf_is_active" checked>
                        <label class="form-check-label" for="vf_is_active">Active</label>
                    </div>
                </div>
            </div>
            <div class="mt-2">
                <button type="button" class="btn btn-sm btn-success" id="variant-save-btn">
                    <i class="bi bi-check-lg"></i> Save Variant
                </button>
                <button type="button" class="btn btn-sm btn-outline-secondary ms-1" id="variant-cancel-btn">Cancel</button>
                <span id="vf_saving_msg" class="ms-2 text-muted small d-none">Saving…</span>
                <span id="vf_error_msg" class="ms-2 text-danger small"></span>
            </div>
        </div>

        <!-- Variants table -->
        <div class="table-responsive">
            <table class="table table-sm table-hover mb-0" id="variants-table">
                <thead class="table-light">
                    <tr>
                        <th style="width:140px">Colour</th>
                        <th style="width:100px">Size</th>
                        <th style="width:120px">Pack</th>
                        <th>SKU</th>
                        <th style="width:140px">Media</th>
                        <th style="width:110px">Price Override</th>
                        <th style="width:90px">Stock (pcs)</th>
                        <th style="width:90px">Stock (m)</th>
                        <th style="width:70px">Active</th>
                        <th style="width:100px">Actions</th>
                    </tr>
                </thead>
                <tbody id="variants-tbody">
<?php
$displayVariants = [];
foreach ($variants as $vrow) {
    if ($hasRealVariants && is_placeholder_variant_row($vrow)) {
        continue;
    }
    $displayVariants[] = $vrow;
}
?>
<?php foreach ($displayVariants as $v): ?>
                    <tr data-vid="<?php echo (int) $v['id']; ?>">
                        <td><?php echo htmlspecialchars($v['color'] ?: '—'); ?></td>
                        <td><?php echo htmlspecialchars($v['size'] ?: '—'); ?></td>
                        <td>
                            <?php if ($isSetUnitType): ?>
                                <?php
                                $ups = (int) ($v['units_per_set'] ?? 0);
                                $pl = trim((string) ($v['pack_label'] ?? ''));
                                if ($ups <= 0) { $ups = 1; }
                                if ($pl === '') { $pl = 'Pack of ' . $ups; }
                                ?>
                                <span class="small"><?php echo e($pl); ?> (<?php echo (int) $ups; ?>)</span>
                            <?php else: ?>
                                <span class="text-muted">&mdash;</span>
                            <?php endif; ?>
                        </td>
                        <td><code><?php echo htmlspecialchars($v['sku'] ?? ''); ?></code></td>
                        <td>
                            <?php
                            $imgCount = 0;
                            foreach (['image', 'image2', 'image3', 'image4'] as $mkey) {
                                if (!empty($v[$mkey])) { $imgCount++; }
                            }
                            $hasVideo = !empty($v['video']);
                            ?>
                            <span class="small"><?php echo (int) $imgCount; ?> img<?php echo $imgCount === 1 ? '' : 's'; ?><?php echo $hasVideo ? ' + video' : ''; ?></span>
                        </td>
                        <td><?php echo $v['price_override'] !== null ? '₹' . number_format((float)$v['price_override'], 2) : '<span class="text-muted">—</span>'; ?></td>
                        <td><?php echo number_format((float)$v['stock'], 2); ?></td>
                        <td><?php echo number_format((float)$v['stock_meters'], 2); ?></td>
                        <td>
                            <?php if ($v['is_active']): ?>
                                <span class="badge bg-success">Yes</span>
                            <?php else: ?>
                                <span class="badge bg-secondary">No</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <button type="button" class="btn btn-xs btn-outline-primary me-1" data-action="edit"
                                data-variant-id="<?php echo (int)$v['id']; ?>" title="Edit">
                                <i class="bi bi-pencil me-1" aria-hidden="true"></i><span>Edit</span>
                            </button>
                            <button type="button" class="btn btn-xs btn-outline-danger" data-action="delete"
                                data-variant-id="<?php echo (int)$v['id']; ?>" title="Delete">
                                <i class="bi bi-trash me-1" aria-hidden="true"></i><span>Delete</span>
                            </button>
                        </td>
                    </tr>
<?php endforeach; ?>
<?php if (empty($displayVariants)): ?>
                    <tr id="variants-empty-row">
                        <td colspan="10" class="text-center text-muted py-3">No variants yet. Click <strong>Add Variant</strong> to create one.</td>
                    </tr>
<?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<script nonce="<?php echo $cspNonce; ?>">
(function () {
    var FABRIC_ID = <?php echo (int) $id; ?>;
    var CSRF      = <?php echo json_encode(csrf_token()); ?>;
    var ENDPOINT  = 'fabric-variants.php';
    var SIZE_POLICY_MODE = <?php echo json_encode($variantSizeMode); ?>;
    var SIZE_PRESETS = <?php echo json_encode($variantSizePresets); ?>;
    var IS_SET_UNIT = <?php echo $isSetUnitType ? 'true' : 'false'; ?>;
    var VARIANT_UNIT_TYPE = <?php echo json_encode($variantUnitType); ?>;

    // In-memory variant cache from server-rendered data
    var variantCache = {};
    document.querySelectorAll('#variants-tbody tr[data-vid]').forEach(function (tr) {
        var vid = parseInt(tr.dataset.vid);
        variantCache[vid] = {id: vid};
    });

    var variantUI = window.variantUI = {
        syncSizePolicyUI: function () {
            var group = document.getElementById('vf_size_group');
            var preset = document.getElementById('vf_size_preset');
            var custom = document.getElementById('vf_size_custom');
            var hidden = document.getElementById('vf_size');
            if (!group || !preset || !custom || !hidden) return;

            if (SIZE_POLICY_MODE === 'hidden') {
                group.classList.remove('opacity-75');
                preset.value = '';
                custom.value = '';
                custom.classList.add('d-none');
                preset.classList.add('d-none');
                preset.disabled = true;
                custom.disabled = true;
                hidden.value = '';
                return;
            }

            group.classList.remove('opacity-75');
            preset.classList.remove('d-none');
            preset.disabled = false;
            custom.disabled = false;
            var choice = preset.value || '';
            if (choice === '__custom__') {
                custom.classList.remove('d-none');
                hidden.value = custom.value.trim();
            } else {
                custom.classList.add('d-none');
                hidden.value = choice;
            }
        },
        syncStockFieldUI: function () {
            var pcsWrap = document.getElementById('vf_stock_pcs_wrap');
            var mWrap = document.getElementById('vf_stock_m_wrap');
            var pcsInput = document.getElementById('vf_stock');
            var mInput = document.getElementById('vf_stock_meters');
            if (!pcsWrap || !mWrap || !pcsInput || !mInput) return;
            var isMeter = VARIANT_UNIT_TYPE === 'meter';
            pcsWrap.style.display = isMeter ? 'none' : '';
            mWrap.style.display = isMeter ? '' : 'none';
            pcsInput.disabled = isMeter;
            mInput.disabled = !isMeter;
            if (isMeter) {
                pcsInput.value = '0';
            } else {
                mInput.value = '0';
            }
        },
        setSizeValue: function (sizeVal) {
            var preset = document.getElementById('vf_size_preset');
            var custom = document.getElementById('vf_size_custom');
            var hidden = document.getElementById('vf_size');
            var value = String(sizeVal || '').trim();
            if (!preset || !custom || !hidden) return;
            if (SIZE_POLICY_MODE === 'hidden') {
                preset.value = '';
                custom.value = '';
                hidden.value = '';
                variantUI.syncSizePolicyUI();
                return;
            }
            if (value !== '' && SIZE_PRESETS.indexOf(value) !== -1) {
                preset.value = value;
                custom.value = '';
            } else if (value !== '') {
                preset.value = '__custom__';
                custom.value = value;
            } else {
                preset.value = '';
                custom.value = '';
            }
            variantUI.syncSizePolicyUI();
        },
        showAddForm: function () {
            document.getElementById('vf_variant_id').value   = '0';
            document.getElementById('vf_color').value        = '';
            variantUI.setSizeValue('');
            document.getElementById('vf_sku').value          = '';
            document.getElementById('vf_image').value        = '';
            document.getElementById('vf_image_file').value   = '';
            document.getElementById('vf_image2').value       = '';
            document.getElementById('vf_image2_file').value  = '';
            document.getElementById('vf_image3').value       = '';
            document.getElementById('vf_image3_file').value  = '';
            document.getElementById('vf_image4').value       = '';
            document.getElementById('vf_image4_file').value  = '';
            document.getElementById('vf_video').value        = '';
            document.getElementById('vf_video_file').value   = '';
            document.getElementById('vf_price_override').value = '';
            document.getElementById('vf_units_per_set').value = '1';
            document.getElementById('vf_pack_label').value = 'Pack of 1';
            document.getElementById('vf_stock').value        = '0';
            document.getElementById('vf_stock_meters').value = '0';
            document.getElementById('vf_is_active').checked  = true;
            document.getElementById('variant-form-title').textContent = 'Add Variant';
            document.getElementById('vf_error_msg').textContent = '';
            document.getElementById('variant-form-container').classList.remove('d-none');
            document.getElementById('vf_color').focus();
        },

        hideForm: function () {
            document.getElementById('variant-form-container').classList.add('d-none');
        },

        editVariant: function (vid) {
            // Fetch latest data for this variant
            fetch(ENDPOINT + '?action=list&fabric_id=' + FABRIC_ID)
                .then(r => r.json())
                .then(function (data) {
                    if (!data.success) { return; }
                    var v = data.variants.find(function (x) { return x.id == vid; });
                    if (!v) { return; }
                    document.getElementById('vf_variant_id').value      = v.id;
                    document.getElementById('vf_color').value           = v.color;
                    variantUI.setSizeValue(v.size || '');
                    document.getElementById('vf_sku').value             = v.sku || '';
                    document.getElementById('vf_image').value           = v.image || '';
                    document.getElementById('vf_image_file').value      = '';
                    document.getElementById('vf_image2').value          = v.image2 || '';
                    document.getElementById('vf_image2_file').value     = '';
                    document.getElementById('vf_image3').value          = v.image3 || '';
                    document.getElementById('vf_image3_file').value     = '';
                    document.getElementById('vf_image4').value          = v.image4 || '';
                    document.getElementById('vf_image4_file').value     = '';
                    document.getElementById('vf_video').value           = v.video || '';
                    document.getElementById('vf_video_file').value      = '';
                    document.getElementById('vf_price_override').value  = v.price_override !== null ? v.price_override : '';
                    document.getElementById('vf_units_per_set').value   = (parseInt(v.units_per_set || '0', 10) > 0 ? String(parseInt(v.units_per_set, 10)) : '1');
                    document.getElementById('vf_pack_label').value      = (v.pack_label && String(v.pack_label).trim() !== '') ? String(v.pack_label) : ('Pack of ' + document.getElementById('vf_units_per_set').value);
                    document.getElementById('vf_stock').value           = v.stock;
                    document.getElementById('vf_stock_meters').value    = v.stock_meters;
                    document.getElementById('vf_is_active').checked     = parseInt(v.is_active) === 1;
                    document.getElementById('variant-form-title').textContent = 'Edit Variant';
                    document.getElementById('vf_error_msg').textContent = '';
                    document.getElementById('variant-form-container').classList.remove('d-none');
                    document.getElementById('vf_color').focus();
                });
        },

        saveVariant: function () {
            var errEl  = document.getElementById('vf_error_msg');
            var saveEl = document.getElementById('vf_saving_msg');
            errEl.textContent  = '';
            saveEl.classList.remove('d-none');

            var fd = new FormData();
            fd.append('csrf_token',     CSRF);
            fd.append('action',         'save');
            fd.append('fabric_id',      FABRIC_ID);
            fd.append('variant_id',     document.getElementById('vf_variant_id').value);
            fd.append('color',          document.getElementById('vf_color').value);
            variantUI.syncSizePolicyUI();
            fd.append('size',           document.getElementById('vf_size').value);
            fd.append('image',          document.getElementById('vf_image').value);
            fd.append('image2',         document.getElementById('vf_image2').value);
            fd.append('image3',         document.getElementById('vf_image3').value);
            fd.append('image4',         document.getElementById('vf_image4').value);
            fd.append('video',          document.getElementById('vf_video').value);
            var imageFileInput = document.getElementById('vf_image_file');
            if (imageFileInput && imageFileInput.files && imageFileInput.files.length > 0) {
                fd.append('image_file', imageFileInput.files[0]);
            }
            var image2FileInput = document.getElementById('vf_image2_file');
            if (image2FileInput && image2FileInput.files && image2FileInput.files.length > 0) {
                fd.append('image2_file', image2FileInput.files[0]);
            }
            var image3FileInput = document.getElementById('vf_image3_file');
            if (image3FileInput && image3FileInput.files && image3FileInput.files.length > 0) {
                fd.append('image3_file', image3FileInput.files[0]);
            }
            var image4FileInput = document.getElementById('vf_image4_file');
            if (image4FileInput && image4FileInput.files && image4FileInput.files.length > 0) {
                fd.append('image4_file', image4FileInput.files[0]);
            }
            var videoFileInput = document.getElementById('vf_video_file');
            if (videoFileInput && videoFileInput.files && videoFileInput.files.length > 0) {
                fd.append('video_file', videoFileInput.files[0]);
            }
            fd.append('price_override', document.getElementById('vf_price_override').value);
            fd.append('units_per_set',  IS_SET_UNIT ? document.getElementById('vf_units_per_set').value : '');
            fd.append('pack_label',     IS_SET_UNIT ? document.getElementById('vf_pack_label').value : '');
            fd.append('stock',          document.getElementById('vf_stock').value);
            fd.append('stock_meters',   document.getElementById('vf_stock_meters').value);
            fd.append('is_active',      document.getElementById('vf_is_active').checked ? '1' : '0');

            fetch(ENDPOINT, {method: 'POST', body: fd})
                .then(r => r.json())
                .then(function (data) {
                    saveEl.classList.add('d-none');
                    if (!data.success) {
                        errEl.textContent = data.message || 'Error saving variant.';
                        return;
                    }
                    variantUI.hideForm();
                    variantUI.reloadTable();
                    setTimeout(function () { window.location.reload(); }, 250);
                })
                .catch(function () {
                    saveEl.classList.add('d-none');
                    errEl.textContent = 'Network error. Please try again.';
                });
        },

        deleteVariant: function (vid, btn) {
            var confirmer = window.adminConfirm
                ? window.adminConfirm({
                    title: 'Delete Variant',
                    message: 'Delete this variant? If orders reference it, it will be deactivated instead.',
                    okText: 'Delete'
                })
                : Promise.resolve(confirm('Delete this variant? If orders reference it, it will be deactivated instead.'));
            confirmer.then(function (confirmed) {
                if (!confirmed) { return; }
                var fd = new FormData();
                fd.append('csrf_token', CSRF);
                fd.append('action',     'delete');
                fd.append('fabric_id',  FABRIC_ID);
                fd.append('variant_id', vid);
                btn.disabled = true;
                fetch(ENDPOINT, {method: 'POST', body: fd})
                    .then(r => r.json())
                    .then(function (data) {
                        if (!data.success) {
                            alert(data.message || 'Could not delete variant.');
                            btn.disabled = false;
                            return;
                        }
                        variantUI.reloadTable();
                        setTimeout(function () { window.location.reload(); }, 250);
                    });
            });
        },

        reloadTable: function () {
            fetch(ENDPOINT + '?action=list&fabric_id=' + FABRIC_ID)
                .then(r => r.json())
                .then(function (data) {
                    if (!data.success) { return; }
                    var tbody = document.getElementById('variants-tbody');
                    var rows = Array.isArray(data.variants) ? data.variants.slice() : [];
                    var hasReal = rows.some(function (v) {
                        var c = String(v.color || '').trim().toLowerCase();
                        var s = String(v.size || '').trim();
                        return (s !== '' || (c !== '' && c !== 'default'));
                    });
                    if (hasReal) {
                        rows = rows.filter(function (v) {
                            var c = String(v.color || '').trim().toLowerCase();
                            var s = String(v.size || '').trim();
                            return !(s === '' && (c === '' || c === 'default'));
                        });
                    }

                    if (rows.length === 0) {
                        tbody.innerHTML = '<tr id="variants-empty-row"><td colspan="10" class="text-center text-muted py-3">No variants yet. Click <strong>Add Variant</strong> to create one.</td></tr>';
                        return;
                    }
                    var html = rows.map(function (v) {
                        var poBadge = v.price_override !== null
                            ? '&#8377;' + parseFloat(v.price_override).toFixed(2)
                            : '<span class="text-muted">&mdash;</span>';
                        var imageCount = 0;
                        ['image', 'image2', 'image3', 'image4'].forEach(function (k) {
                            if (v[k] && String(v[k]).trim() !== '') imageCount++;
                        });
                        var hasVideo = !!(v.video && String(v.video).trim() !== '');
                        var imageCell = '<span class="small">' + imageCount + ' img' + (imageCount === 1 ? '' : 's') + (hasVideo ? ' + video' : '') + '</span>';
                        var packCell = '<span class="text-muted">&mdash;</span>';
                        if (IS_SET_UNIT) {
                            var ups = parseInt(v.units_per_set || '0', 10);
                            if (!Number.isFinite(ups) || ups <= 0) ups = 1;
                            var pl = (v.pack_label && String(v.pack_label).trim() !== '') ? String(v.pack_label) : ('Pack of ' + ups);
                            packCell = '<span class="small">' + esc(pl) + ' (' + ups + ')</span>';
                        }
                        var activeBadge = parseInt(v.is_active) === 1
                            ? '<span class="badge bg-success">Yes</span>'
                            : '<span class="badge bg-secondary">No</span>';
                        return '<tr data-vid="' + v.id + '">'
                            + '<td>' + esc(v.color || '&mdash;') + '</td>'
                            + '<td>' + esc(v.size  || '&mdash;') + '</td>'
                            + '<td>' + packCell + '</td>'
                            + '<td><code>' + esc(v.sku || '') + '</code></td>'
                            + '<td>' + imageCell + '</td>'
                            + '<td>' + poBadge + '</td>'
                            + '<td>' + parseFloat(v.stock).toFixed(2) + '</td>'
                            + '<td>' + parseFloat(v.stock_meters).toFixed(2) + '</td>'
                            + '<td>' + activeBadge + '</td>'
                            + '<td>'
                            + '<button type="button" class="btn btn-xs btn-outline-primary me-1" data-action="edit" data-variant-id="' + v.id + '" title="Edit"><i class="bi bi-pencil me-1" aria-hidden="true"></i><span>Edit</span></button>'
                            + '<button type="button" class="btn btn-xs btn-outline-danger" data-action="delete" data-variant-id="' + v.id + '" title="Delete"><i class="bi bi-trash me-1" aria-hidden="true"></i><span>Delete</span></button>'
                            + '</td>'
                            + '</tr>';
                    }).join('');
                    tbody.innerHTML = html;
                });
        }
    };

    var addBtn = document.getElementById('variants-add-btn');
    if (addBtn) {
        addBtn.addEventListener('click', function () {
            variantUI.showAddForm();
        });
    }

    var saveBtn = document.getElementById('variant-save-btn');
    if (saveBtn) {
        saveBtn.addEventListener('click', function () {
            variantUI.saveVariant();
        });
    }

    var cancelBtn = document.getElementById('variant-cancel-btn');
    if (cancelBtn) {
        cancelBtn.addEventListener('click', function () {
            variantUI.hideForm();
        });
    }
    var sizePresetEl = document.getElementById('vf_size_preset');
    var sizeCustomEl = document.getElementById('vf_size_custom');
    if (sizePresetEl) {
        sizePresetEl.addEventListener('change', function () {
            variantUI.syncSizePolicyUI();
            if (sizePresetEl.value === '__custom__' && sizeCustomEl) {
                sizeCustomEl.focus();
            }
        });
    }
    if (sizeCustomEl) {
        sizeCustomEl.addEventListener('input', function () {
            variantUI.syncSizePolicyUI();
        });
    }
    var unitsPerSetEl = document.getElementById('vf_units_per_set');
    var packLabelEl = document.getElementById('vf_pack_label');
    if (unitsPerSetEl && packLabelEl) {
        unitsPerSetEl.addEventListener('input', function () {
            var n = parseInt(String(unitsPerSetEl.value || '1'), 10);
            if (!Number.isFinite(n) || n < 1) n = 1;
            if (packLabelEl.value.trim() === '' || /^Pack of \d+$/i.test(packLabelEl.value.trim())) {
                packLabelEl.value = 'Pack of ' + n;
            }
        });
    }
    variantUI.syncSizePolicyUI();
    variantUI.syncStockFieldUI();

    var variantsTbody = document.getElementById('variants-tbody');
    if (variantsTbody) {
        variantsTbody.addEventListener('click', function (event) {
            var target = event.target;
            var button = target && target.closest ? target.closest('button[data-action][data-variant-id]') : null;
            if (!button) {
                return;
            }

            var action = String(button.getAttribute('data-action') || '');
            var vid = parseInt(String(button.getAttribute('data-variant-id') || '0'), 10);
            if (!Number.isFinite(vid) || vid <= 0) {
                return;
            }

            if (action === 'edit') {
                variantUI.editVariant(vid);
                return;
            }
            if (action === 'delete') {
                variantUI.deleteVariant(vid, button);
            }
        });
    }

    function esc(str) {
        return String(str)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;');
    }
})();
</script>

<?php include 'partials/footer.php'; ?>
