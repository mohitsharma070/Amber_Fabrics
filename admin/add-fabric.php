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
    $size          = '';
    $meterOptions  = trim($_POST['meter_options']  ?? '');
    $color         = '';
    $printStyle    = trim($_POST['print_style']    ?? '');
    $material      = trim($_POST['material']      ?? '');
    $gsm           = trim($_POST['gsm']           ?? '');
    $sku           = generate_unique_fabric_sku($conn, $category, $material, '', $gsm);
    $width         = trim($_POST['width']         ?? '');
    $moq           = trim($_POST['moq']           ?? '');
    $lead          = trim($_POST['lead_time']     ?? '');
    $dispatchTime  = trim($_POST['dispatch_time'] ?? '');
    $washCare      = trim($_POST['wash_care']     ?? '');
    $description   = trim($_POST['description']   ?? '');
    $status        = trim($_POST['status']        ?? 'active');
    $isFeatured    = isset($_POST['is_featured']) ? 1 : 0;
    $isAvailInput  = isset($_POST['is_available']) ? 1 : 0;
    $minOrderInput = trim((string) ($_POST['min_order_meters'] ?? '1'));
    $minOrder      = is_numeric($minOrderInput) ? (float) $minOrderInput : 1.0;
    if ($unitType === 'piece' || $unitType === 'set') {
        $minOrder = (float) max(1, (int) round($minOrder));
    } else {
        $minOrder = normalize_meter_quantity($minOrder, 1.0);
    }
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
    if ($minOrder <= 0) {
        $errors['min_order_meters'] = 'Min. order qty must be greater than 0.';
    } elseif (($unitType === 'piece' || $unitType === 'set') && floor($minOrder) != $minOrder) {
        $errors['min_order_meters'] = 'Piece/Set products require whole-number min. order qty.';
    }

    $imageName = null;
    $image2Name = null;
    $image3Name = null;
    $image4Name = null;
    $videoName = null;

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
        try {
            $saved = save_fabric_image_upload($file, $label);
        } catch (Throwable $e) {
            $errors[$field] = $e->getMessage();
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
            $minOrderVal = (float) max(1, (int) round($minOrder));
        } else {
            $stockMeters = round((float) $stock, 2);
            $stockVal = 0;
            $minOrderVal = round($minOrder, 2);
        }
        $hasInitialStock = ($unitType === 'meter')
            ? ((float) $stockMeters > 0)
            : ((float) $stockVal > 0);
        $isAvailable   = ($status === 'active' && $hasInitialStock) ? 1 : 0;
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
        $newId = (int) $conn->insert_id;
        flash('success', 'Product created. Now add colour &amp; size variants below.');
        redirect('edit-fabric.php?id=' . $newId . '&new_product=1');
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

<?php
$isEdit = false;
$submitLabel = 'Save Product';
$cancelHref = 'fabrics.php';
$cancelLabel = 'Cancel';
include __DIR__ . '/partials/fabric-product-form.php';
include __DIR__ . '/partials/fabric-product-form-script.php';
?>

<?php include 'partials/footer.php'; ?>
