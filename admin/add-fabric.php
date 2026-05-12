<?php
require_once __DIR__ . '/../includes/init.php';
require_admin();

$errors = [];
$categories = [];

try {
    $catStmt = $conn->prepare("SELECT name, slug FROM categories WHERE status = 'active' ORDER BY name ASC");
    $catStmt->execute();
    $categories = $catStmt->get_result()->fetch_all(MYSQLI_ASSOC);
} catch (Throwable $e) {
    // Keep form usable even if categories table is unavailable.
    $categories = [];
}

$old = [
    'name' => '',
    'category' => '',
    'unit_type' => 'meter',
    'print_style' => '',
    'price' => '',
    'sale_price' => '',
    'cost_price' => '',
    'stock' => '0',
    'sku' => '',
    'size' => '',
    'meter_options' => '',
    'color' => '',
    'material' => '',
    'gsm' => '',
    'width' => '',
    'moq' => '',
    'lead_time' => '',
    'dispatch_time' => '',
    'wash_care' => '',
    'description' => '',
    'status' => 'active',
    'is_featured' => 0,
    'is_available' => 1,
    'min_order_meters' => '1',
    'qty_step' => '',
];

if(isset($_POST['submit'])){
    if (!verify_csrf()) {
        flash('error', 'Invalid session token. Please try again.');
        redirect('add-fabric.php');
    }
    $name          = trim($_POST['name']          ?? '');
    $category      = trim($_POST['category']      ?? '');
    $unitType      = trim((string) ($_POST['unit_type'] ?? 'meter'));
    $price         = trim($_POST['price']         ?? '');
    $salePrice     = trim($_POST['sale_price']    ?? '');
    $costPrice     = trim($_POST['cost_price']    ?? '');
    $stock         = trim($_POST['stock']         ?? '0');
    $size          = trim($_POST['size']          ?? '');
    $meterOptions  = trim($_POST['meter_options']  ?? '');
    $color         = trim($_POST['color']         ?? '');
    $printStyle    = trim($_POST['print_style']    ?? '');
    $material      = trim($_POST['material']      ?? '');
    $gsm           = trim($_POST['gsm']           ?? '');
    $sku           = generate_unique_fabric_sku($conn, $category, $material, $color, $gsm);
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
        'color' => $color,
        'print_style' => $printStyle,
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
    if (!is_numeric($stock) || (float) $stock < 0) {
        $errors['stock'] = 'Stock must be a non-negative number.';
    } elseif (($unitType === 'piece' || $unitType === 'set') && floor((float) $stock) != (float) $stock) {
        $errors['stock'] = 'Piece/Set products require whole-number stock.';
    }
    if (!in_array($status, ['active', 'inactive'], true)) {
        $errors['status'] = 'Invalid status selected.';
    }

    $imageName = null;
    $image2Name = null;
    $image3Name = null;
    $image4Name = null;
    $videoName = null;
    $maxImageSize = 5 * 1024 * 1024;
    $allowedImageExt = ['jpg','jpeg','png','webp'];
    $allowedImageMime = ['image/jpeg','image/png','image/webp'];

    $imageFields = [
        'image' => 'Main image',
        'image2' => 'Image 2',
        'image3' => 'Image 3',
        'image4' => 'Image 4',
    ];
    foreach ($imageFields as $field => $label) {
        if (empty($_FILES[$field]['name'])) {
            continue;
        }
        $file = $_FILES[$field];
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            $errors[$field] = $label . ' upload failed. Please try again.';
            continue;
        }
        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        $mime = mime_content_type($file['tmp_name']) ?: '';
        if ($file['size'] > $maxImageSize) {
            $errors[$field] = $label . ' must be under 5MB.';
            continue;
        }
        if (!in_array($ext, $allowedImageExt, true) || !in_array($mime, $allowedImageMime, true) || !@getimagesize($file['tmp_name'])) {
            $errors[$field] = $label . ' must be JPG, PNG or WEBP.';
            continue;
        }
        $saved = random_filename($file['name']);
        $target = __DIR__ . "/../images/fabrics/{$saved}";
        if (!move_uploaded_file($file['tmp_name'], $target)) {
            $errors[$field] = $label . ' upload failed.';
            continue;
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
            $videoName = random_filename($file['name']);
            $target = __DIR__ . "/../images/fabrics/{$videoName}";
            if (!move_uploaded_file($file['tmp_name'], $target)) {
                $errors['video'] = 'Video upload failed.';
            }
        }
    }

    if (empty($errors)) {
        $priceVal      = (float) $price;
        $salePriceVal  = ($salePrice !== '') ? (float) $salePrice : null;
        $costPriceVal  = (float) $costPrice;
        if ($unitType === 'piece' || $unitType === 'set') {
            $stockVal = normalize_piece_quantity($stock, 0);
            $stockMeters = 0.00;
            $minOrderVal = 1.00;
        } else {
            $stockMeters = round((float) $stock, 2);
            $stockVal = 0;
            $minOrderVal = round($minOrder, 2);
        }
        $isAvailable   = ($status === 'active' && $isAvailInput === 1) ? 1 : 0;
        $priceInrVal   = $priceVal; // map regular price for existing listing compatibility
        $priceUsdVal   = null;

        $stmt = $conn->prepare(
            "INSERT INTO fabrics (
                name, sku, category, unit_type, meter_options, print_style, material, gsm, width, moq, lead_time, dispatch_time,
                size, color, description, wash_care, image,
                image2, image3, image4, video,
                price, sale_price, cost_price, price_inr, price_usd,
                stock, stock_meters, min_order_meters, qty_step,
                is_featured, status, is_available
            ) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)"
        );
        $stmt->bind_param(
            'sssssssssssssssssssssdddddddddisi',
            $name, $sku, $category, $unitType, $meterOptions, $printStyle, $material, $gsm, $width, $moq, $lead, $dispatchTime,
            $size, $color, $description, $washCare, $imageName, $image2Name, $image3Name, $image4Name, $videoName,
            $priceVal, $salePriceVal, $costPriceVal, $priceInrVal, $priceUsdVal,
            $stockVal, $stockMeters, $minOrderVal, $qtyStep,
            $isFeatured, $status, $isAvailable
        );
        $stmt->execute();
        flash('success', 'Product added.');
        redirect('fabrics.php');
    }
}
?>

<?php
$metaTitle = 'Add Product | Amber Fabrics';
$metaDescription = 'Admin page to add new product details to Amber Fabrics shop.';
$metaKeywords = 'admin, add product, catalog, Amber Fabrics';
include 'partials/header.php'; ?>

<h1 class="mb-4">Add Product</h1>

<?php if (!empty($errors)): ?>
    <div class="alert alert-warning">Please fix the errors below.</div>
<?php endif; ?>

<form method="POST" enctype="multipart/form-data" class="row g-3">
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
        <input type="number" step="0.01" min="0" name="price" class="<?php echo form_class($errors, 'price'); ?>" placeholder="0.00" required value="<?php echo e($old['price']); ?>">
        <?php echo form_error($errors, 'price'); ?>
    </div>
    <div class="col-6 col-md-3">
        <label class="form-label">Sale Price</label>
        <input type="number" step="0.01" min="0" name="sale_price" class="<?php echo form_class($errors, 'sale_price'); ?>" placeholder="0.00" value="<?php echo e($old['sale_price']); ?>">
        <?php echo form_error($errors, 'sale_price'); ?>
    </div>
    <div class="col-6 col-md-3">
        <label class="form-label">Cost Price *</label>
        <input type="number" step="0.01" min="0" name="cost_price" class="<?php echo form_class($errors, 'cost_price'); ?>" placeholder="0.00" required value="<?php echo e($old['cost_price']); ?>">
        <?php echo form_error($errors, 'cost_price'); ?>
    </div>
    <div class="col-6 col-md-3">
        <label class="form-label">Stock * <small class="text-muted">(decimal supported)</small></label>
        <input type="number" step="0.01" min="0" name="stock" class="<?php echo form_class($errors, 'stock'); ?>" required placeholder="e.g. 20 or 97.5" value="<?php echo e($old['stock']); ?>">
        <?php echo form_error($errors, 'stock'); ?>
    </div>
    <div class="col-6 col-md-3">
        <label class="form-label">Min. Order Qty</label>
        <input type="number" step="0.01" min="0" name="min_order_meters" class="form-control" value="<?php echo e($old['min_order_meters']); ?>" placeholder="e.g. 1">
    </div>
    <div class="col-6 col-md-3">
        <label class="form-label">Quantity Step <small class="text-muted">(0 = auto)</small></label>
        <input type="number" step="0.0001" min="0" name="qty_step" class="form-control" value="<?php echo e($old['qty_step']); ?>" placeholder="e.g. 0.5">
    </div>
    <div class="col-6 col-md-4" id="size_row">
        <label class="form-label">Sizes <small class="text-muted">(comma separated, e.g. S, M, L, XL)</small></label>
        <input type="text" name="size" class="form-control" value="<?php echo e($old['size']); ?>">
    </div>
    <div class="col-6 col-md-4" id="meter_options_row">
        <label class="form-label">Meter Options <small class="text-muted">(quick quantity options, comma separated: e.g. 1, 1.5, 2, 2.5)</small></label>
        <input type="text" name="meter_options" class="form-control" placeholder="e.g. 1, 1.5, 2, 2.5" value="<?php echo e($old['meter_options']); ?>">
    </div>
    <div class="col-6 col-md-4">
        <label class="form-label">Color</label>
        <input type="text" name="color" class="form-control" value="<?php echo e($old['color']); ?>">
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
        <label class="form-label">GSM <small class="text-muted">(optional)</small></label>
        <input type="text" name="gsm" class="form-control" value="<?php echo e($old['gsm']); ?>">
    </div>
    <div class="col-6 col-md-4">
        <label class="form-label">Width <small class="text-muted">(optional)</small></label>
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
    <div class="col-12 col-md-8">
        <label class="form-label">Main Product Image</label>
        <input type="file" name="image" accept="image/*" class="<?php echo form_class($errors, 'image'); ?>">
        <?php echo form_error($errors, 'image'); ?>
    </div>
    <div class="col-12 col-md-4">
        <label class="form-label">Image 2</label>
        <input type="file" name="image2" accept="image/*" class="<?php echo form_class($errors, 'image2'); ?>">
        <?php echo form_error($errors, 'image2'); ?>
    </div>
    <div class="col-12 col-md-4">
        <label class="form-label">Image 3</label>
        <input type="file" name="image3" accept="image/*" class="<?php echo form_class($errors, 'image3'); ?>">
        <?php echo form_error($errors, 'image3'); ?>
    </div>
    <div class="col-12 col-md-4">
        <label class="form-label">Image 4</label>
        <input type="file" name="image4" accept="image/*" class="<?php echo form_class($errors, 'image4'); ?>">
        <?php echo form_error($errors, 'image4'); ?>
    </div>
    <div class="col-12 col-md-4">
        <label class="form-label">Product Video (optional)</label>
        <input type="file" name="video" accept="video/mp4,video/webm,video/ogg" class="<?php echo form_class($errors, 'video'); ?>">
        <?php echo form_error($errors, 'video'); ?>
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
        <div class="form-check form-check-inline">
            <input class="form-check-input" type="checkbox" name="is_available" id="is_available" <?php echo $old['is_available'] ? 'checked' : ''; ?>>
            <label class="form-check-label" for="is_available">Available for purchase</label>
        </div>
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
        <button name="submit" class="btn btn-primary">Save Product</button>
        <a href="fabrics.php" class="btn btn-outline-secondary">Cancel</a>
    </div>
</form>

<script nonce="<?php echo $cspNonce; ?>">
(function () {
    var unitSelect = document.querySelector('select[name="unit_type"]');
    var stockInput = document.querySelector('input[name="stock"]');
    var minOrderInput = document.querySelector('input[name="min_order_meters"]');
    var qtyStepInput = document.querySelector('input[name="qty_step"]');
    var categoryInput = document.querySelector('select[name="category"]');
    var materialInput = document.querySelector('input[name="material"]');
    var colorInput = document.querySelector('input[name="color"]');
    var gsmInput = document.querySelector('input[name="gsm"]');
    var skuPreview = document.getElementById('sku_preview');
    var skuHidden = document.getElementById('sku_hidden');
    if (!unitSelect || !stockInput) return;

    function applyUnitRules() {
        var unit = unitSelect.value;
        var isMeter = unit === 'meter';
        var isPiece = unit === 'piece';
        var isWhole = isPiece || unit === 'set';
        stockInput.step = isWhole ? '1' : '0.01';
        var meterOptionsRow = document.getElementById('meter_options_row');
        var sizeRow = document.getElementById('size_row');
        if (meterOptionsRow) {
            meterOptionsRow.style.display = isMeter ? '' : 'none';
        }
        if (sizeRow) {
            sizeRow.style.display = isPiece ? '' : 'none';
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
            colorInput ? skuPart(colorInput.value) : '',
            gsmInput ? skuPart(gsmInput.value) : ''
        ].filter(Boolean);
        var sku = parts.length ? parts.join('-') : 'SKU';
        skuPreview.value = sku;
        skuHidden.value = sku;
    }

    [categoryInput, materialInput, colorInput, gsmInput].forEach(function (el) {
        if (el) {
            el.addEventListener('input', updateSkuPreview);
            el.addEventListener('change', updateSkuPreview);
        }
    });
    updateSkuPreview();
})();
</script>

<?php include 'partials/footer.php'; ?>
