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

$variants = InventoryService::get_fabric_variants($conn, $id);
$isVariableInventory = (($fabric['product_type'] ?? 'simple') === 'variable');

$categories = [];
$categorySlugMap = [];
try {
    $categories = storefront_categories_fetch($conn);
    foreach ($categories as $catRow) {
        $slugKey = trim((string) ($catRow['slug'] ?? ''));
        if ($slugKey !== '') {
            $categorySlugMap[$slugKey] = true;
        }
    }
} catch (Throwable $e) {
    $categories = [];
    $categorySlugMap = [];
}
// Backward-safe defaults for older records.
$old = [
    'product_code' => (string)($fabric['product_code']??''),
    'amazon_asin' => (string)($fabric['amazon_asin']??''),
    'name' => (string) ($fabric['name'] ?? ''),
    'category' => (string) ($fabric['category'] ?? ''),
    'product_type' => in_array((string)($fabric['product_type']??''),['simple','variable'],true)?(string)$fabric['product_type']:'simple',
    'slug' => (string)($fabric['slug']??''),
    'unit_type' => in_array((string) ($fabric['unit_type'] ?? ''), ['meter', 'piece', 'set'], true) ? (string) $fabric['unit_type'] : 'meter',
    'price' => (string) ($fabric['price'] ?? ''),
    'mrp' => (string) ($fabric['price'] ?? ''),
    'selling_price' => ((float)($fabric['sale_price']??0)>0)?(string)$fabric['sale_price']:(string)($fabric['price']??''),
    'sale_price' => (string) ($fabric['sale_price'] ?? ''),
    'cost_price' => (string) ($fabric['cost_price'] ?? ''),
    'stock' => format_meter_quantity(((float) ($fabric['stock_meters'] ?? 0) > 0) ? (float) $fabric['stock_meters'] : (float) ($fabric['stock'] ?? 0)),
    'quantity' => format_meter_quantity(((string)($fabric['unit_type']??'piece')==='meter')?(float)($fabric['stock_meters']??0):(float)($fabric['stock']??0)),
    'sku' => (string) ($fabric['sku'] ?? ''),
    'size' => (string) ($fabric['size'] ?? ''),
    'meter_options' => (string) ($fabric['meter_options'] ?? ''),
    'color' => (string) ($fabric['color'] ?? ''),
    'dispatch_min_days' => (string) ($fabric['dispatch_min_days'] ?? ''),
    'dispatch_max_days' => (string) ($fabric['dispatch_max_days'] ?? ''),
    'description' => (string) ($fabric['description'] ?? ''),
    'hsn_code' => (string)($fabric['hsn_code']??''),
    'gst_rate' => ($fabric['gst_rate']??null)!==null?(string)$fabric['gst_rate']:'',
    'shipping_weight_kg' => ($fabric['shipping_weight_kg']??null)!==null?(string)$fabric['shipping_weight_kg']:'',
    'parcel_length_cm' => ($fabric['parcel_length_cm']??null)!==null?(string)$fabric['parcel_length_cm']:'',
    'parcel_width_cm' => ($fabric['parcel_width_cm']??null)!==null?(string)$fabric['parcel_width_cm']:'',
    'parcel_height_cm' => ($fabric['parcel_height_cm']??null)!==null?(string)$fabric['parcel_height_cm']:'',
    'status' => (string) ($fabric['status'] ?? 'active'),
    'visibility' => (string)($fabric['status']??'draft'),
    'is_available' => !empty($fabric['is_available']) ? 1 : 0,
    'min_order_meters' => format_meter_quantity((float) ($fabric['min_order_meters'] ?? 1)),
    'qty_step' => ((float) ($fabric['qty_step'] ?? 0) > 0) ? rtrim(rtrim((string) ($fabric['qty_step'] ?? ''), '0'), '.') : '',
    'low_stock_threshold_units' => ($fabric['low_stock_threshold_units'] ?? null) !== null ? (string) $fabric['low_stock_threshold_units'] : '',
    'low_stock_threshold_meters' => ($fabric['low_stock_threshold_meters'] ?? null) !== null ? format_meter_quantity((float) $fabric['low_stock_threshold_meters']) : '',
];
$old=array_merge($old,ProductAdminService::catalogData($fabric));

$errors = [];

if (isset($_POST['submit'])) {
    if (!verify_csrf()) {
        flash('error', 'Invalid session token. Please try again.');
        redirect("edit-fabric.php?id={$id}");
    }

    $name          = trim($_POST['name']          ?? '');
    $category      = trim($_POST['category']      ?? '');
    $unitType      = trim((string) ($_POST['unit_type'] ?? 'meter'));
    $price         = trim($_POST['mrp'] ?? '');
    $sellingPrice  = trim($_POST['selling_price'] ?? '');
    $salePrice     = (is_numeric($sellingPrice)&&is_numeric($price)&&(float)$sellingPrice<(float)$price)?$sellingPrice:'';
    $costPrice     = trim($_POST['cost_price'] ?? '0');if($costPrice==='')$costPrice='0';
    $stock         = trim($_POST['quantity'] ?? '0');
    $size          = trim($_POST['size']          ?? ((string) ($fabric['size'] ?? '')));
    $meterOptions  = (string)($fabric['meter_options']??'');
    $color         = trim($_POST['color']         ?? ((string) ($fabric['color'] ?? '')));
    $sku           = ProductAdminService::normalizeSku((string)($_POST['sku'] ?? ($fabric['sku']??'')));
    $dispatchMinDays=max(0,(int)($fabric['dispatch_min_days']??0));$dispatchMaxDays=max($dispatchMinDays,(int)($fabric['dispatch_max_days']??0));
    $description   = trim($_POST['description']   ?? '');
    $status        = (string)($_POST['submit'] ?? '') === 'publish'
        ? 'active'
        : trim($_POST['visibility'] ?? 'draft');
    $productType   = in_array(($_POST['product_type']??''),['simple','variable'],true)?(string)$_POST['product_type']:'simple';
    $isAvailInput  = $status==='active'?1:0;
    $minOrderInput = (string)($fabric['min_order_meters']??'1');
    $minOrder      = is_numeric($minOrderInput) ? (float) $minOrderInput : 1.0;
    if ($unitType === 'piece' || $unitType === 'set') {
        $minOrder = (float) max(1, (int) round($minOrder));
    } else {
        $minOrder = normalize_meter_quantity($minOrder, 1.0);
    }
    $qtyStepRaw    = (string)($fabric['qty_step']??'');
    $qtyStep       = ($qtyStepRaw !== '' && is_numeric($qtyStepRaw) && (float) $qtyStepRaw > 0) ? round((float) $qtyStepRaw, 4) : 0.0;
    $lowStockUnitsRaw = (string)($fabric['low_stock_threshold_units']??'');
    $lowStockMetersRaw = (string)($fabric['low_stock_threshold_meters']??'');
    $lowStockUnits = ($lowStockUnitsRaw !== '' && is_numeric($lowStockUnitsRaw) && (float) $lowStockUnitsRaw >= 0)
        ? (int) round((float) $lowStockUnitsRaw)
        : null;
    $lowStockMeters = ($lowStockMetersRaw !== '' && is_numeric($lowStockMetersRaw) && (float) $lowStockMetersRaw >= 0)
        ? round((float) $lowStockMetersRaw, 2)
        : null;
    $parsedMeterOptions = CartService::parse_meter_options($meterOptions, (float) $minOrder);
    $normalizedMeterOptions = implode(', ', array_map(static function ($val): string {
        return format_meter_quantity((float) $val);
    }, $parsedMeterOptions));

    $old = [
        'product_code' => trim((string)($_POST['product_code']??'')),
        'amazon_asin' => trim((string)($_POST['amazon_asin']??'')),
        'name' => $name,
        'category' => $category,
        'product_type' => $productType,
        'slug' => trim((string)($_POST['slug']??'')),
        'unit_type' => $unitType,
        'price' => $price,
        'mrp' => $price,
        'selling_price' => $sellingPrice,
        'sale_price' => $salePrice,
        'cost_price' => $costPrice,
        'stock' => $stock,
        'quantity' => $stock,
        'sku' => $sku,
        'size' => $size,
        'meter_options' => $normalizedMeterOptions,
        'color' => $color,
        'dispatch_min_days' => $dispatchMinDays > 0 ? (string) $dispatchMinDays : '',
        'dispatch_max_days' => $dispatchMaxDays > 0 ? (string) $dispatchMaxDays : '',
        'description' => $description,
        'hsn_code' => trim((string)($_POST['hsn_code']??'')),
        'gst_rate' => trim((string)($_POST['gst_rate']??'')),
        'shipping_weight_kg' => trim((string)($_POST['shipping_weight_kg']??'')),
        'parcel_length_cm' => trim((string)($_POST['parcel_length_cm']??'')),
        'parcel_width_cm' => trim((string)($_POST['parcel_width_cm']??'')),
        'parcel_height_cm' => trim((string)($_POST['parcel_height_cm']??'')),
        'status' => $status,
        'visibility' => $status,
        'is_available' => $isAvailInput,
        'min_order_meters' => format_meter_quantity($minOrder),
        'qty_step' => $qtyStepRaw,
        'low_stock_threshold_units' => $lowStockUnitsRaw,
        'low_stock_threshold_meters' => $lowStockMetersRaw,
    ];
    foreach(ProductAdminService::CATALOG_ATTRIBUTE_FIELDS as $catalogField)$old[$catalogField]=trim((string)($_POST[$catalogField]??''));

    if ($name === '') {
        $errors['name'] = 'Product name is required.';
    }
    if ($category === '') {
        $errors['category'] = 'Category is required.';
    } elseif (!isset($categorySlugMap[$category])) {
        $errors['category'] = 'Select a valid storefront category.';
    }
    if (!in_array($unitType, ['meter', 'piece', 'set'], true)) {
        $errors['unit_type'] = 'Select a valid unit type.';
    }
    if ($price === '' || !is_numeric($price) || (float) $price <= 0) {
        $errors['price'] = 'MRP is required and must be greater than zero.';
    }
    if ($sellingPrice === '' || !is_numeric($sellingPrice) || (float)$sellingPrice <= 0 || (is_numeric($price)&&(float)$sellingPrice>(float)$price)) {
        $errors['sale_price'] = 'Selling Price must be greater than zero and cannot exceed MRP.';
    }
    if ($costPrice === '' || !is_numeric($costPrice) || (float) $costPrice < 0) {
        $errors['cost_price'] = 'Cost price is required and must be 0 or more.';
    }
    if (!in_array($status, ['draft', 'active', 'inactive'], true)) {
        $errors['visibility'] = 'Invalid visibility selected.';
    }
    if(!is_numeric($stock)||(float)$stock<0||($unitType!=='meter'&&floor((float)$stock)!=(float)$stock))$errors['stock']='Quantity must be a non-negative whole number.';
    if(mb_strlen($description)<20)$errors['description']='Description must contain at least 20 characters.';
    $catalogValidation=ProductAdminService::validateCatalog($conn,$old,$id);$errors=array_merge($errors,$catalogValidation['errors']);
    $extendedValidation=ProductAdminService::validateExtended($conn,$old,$id,false);
    $errors=array_merge($errors,$extendedValidation['errors']);
    if ($minOrder <= 0) {
        $errors['min_order_meters'] = 'Min. order qty must be greater than 0.';
    } elseif (($unitType === 'piece' || $unitType === 'set') && floor($minOrder) != $minOrder) {
        $errors['min_order_meters'] = 'Piece/Set products require whole-number min. order qty.';
    }
    if ($lowStockUnitsRaw !== '' && ($lowStockUnits === null || $lowStockUnits < 0)) {
        $errors['low_stock_threshold_units'] = 'Low stock threshold (units) must be 0 or more.';
    }
    if ($lowStockMetersRaw !== '' && ($lowStockMeters === null || $lowStockMeters < 0)) {
        $errors['low_stock_threshold_meters'] = 'Low stock threshold (meters) must be 0 or more.';
    }
    if ($unitType === 'meter') {
        if (empty($parsedMeterOptions)) {
            $errors['meter_options'] = 'Provide at least one valid meter option (e.g. 1, 2, 2.5).';
        } elseif (!in_array(round((float) $minOrder, 2), array_map(static function ($val) {
            return round((float) $val, 2);
        }, $parsedMeterOptions), true)) {
            $errors['meter_options'] = 'Meter options must include the minimum order qty.';
        }
    } else {
        $normalizedMeterOptions = '';
        $lowStockMeters = null;
    }
    if ($unitType === 'meter') {
        $lowStockUnits = null;
    }

    if (empty($errors)) {
        $priceVal      = (float) $price;
        $salePriceVal  = ($salePrice !== '') ? (float) $salePrice : null;
        $costPriceVal  = (float) $costPrice;
        $stockVal = $unitType === 'meter' ? 0.0 : max(0.0,(float)$stock);
        $stockMeters = $unitType === 'meter' ? max(0.0,(float)$stock) : 0.0;
        $minOrderVal = ($unitType === 'meter')
            ? round($minOrder, 2)
            : (float) max(1, (int) round($minOrder));
        $isAvailable   = ($status === 'active' && $isAvailInput === 1) ? 1 : 0;
        $requestedStatus = $status;
        $status = $requestedStatus === 'active' ? 'draft' : $requestedStatus;
        $conn->begin_transaction();
        try {
        $upd = $conn->prepare(
            "UPDATE fabrics SET
                name = ?, sku = ?, category = ?, unit_type = ?, meter_options = ?,
                size = ?, color = ?, description = ?,
                price = ?, sale_price = ?, cost_price = ?,
                stock = ?, stock_meters = ?, low_stock_threshold_units = ?, low_stock_threshold_meters = ?, min_order_meters = ?, qty_step = ?,
                status = ?, is_available = ?
             WHERE id = ?"
        );
        $upd->bind_param(
            'ssssssssdddddidddsii',
            $name, $sku, $category, $unitType, $normalizedMeterOptions,
            $size, $color, $description,
            $priceVal, $salePriceVal, $costPriceVal,
            $stockVal, $stockMeters, $lowStockUnits, $lowStockMeters, $minOrderVal, $qtyStep,
            $status, $isAvailable,
            $id
        );
        $upd->execute();
        $extendedResult=ProductAdminService::saveExtended($conn,$id,$old);
        if(!empty($extendedResult['errors'])){throw new RuntimeException(implode(' ',array_values($extendedResult['errors'])));}
        $catalogResult=ProductAdminService::saveCatalog($conn,$id,$old);
        if(!empty($catalogResult['errors'])){throw new RuntimeException(implode(' ',array_values($catalogResult['errors'])));}
        $range=$conn->prepare("UPDATE fabrics SET dispatch_min_days=NULLIF(?,0),dispatch_max_days=NULLIF(?,0) WHERE id=?");$range->bind_param('iii',$dispatchMinDays,$dispatchMaxDays,$id);$range->execute();

        if ($requestedStatus === 'active') {
            $publishResult = ProductAdminService::publish($conn, $id, (int) $_SESSION['admin_id']);
            if (empty($publishResult['ready'])) {
                throw new RuntimeException('Cannot publish: ' . implode(' ', array_values((array) ($publishResult['checks'] ?? []))));
            }
        }
        log_admin_activity($conn,(int)$_SESSION['admin_id'],'product_draft_saved','product',$id,'Product editor changes saved.','ok');
        $conn->commit();
        } catch (Throwable $e) {
            $conn->rollback();
            $errors['save'] = $e->getMessage();
        }

        if (empty($errors)) {
            if(function_exists('product_feed_refresh_files'))product_feed_refresh_files(['conn'=>$conn]);
            flash('success', $requestedStatus === 'active' ? 'Product saved and published.' : 'Draft saved.');
            redirect('edit-fabric.php?id=' . $id);
        }
    }
}
?>

<?php
$metaTitle = SiteContext::title('Edit Product');
$metaDescription = 'Admin page to edit product details in ' . SiteContext::name() . ' shop.';
$metaKeywords = 'admin, edit product, catalog, ' . SiteContext::name();
include 'partials/header.php'; ?>

<div class="u-flex u-flex-wrap u-justify-between u-items-center u-gap-2 u-mb-4">
    <div><h1 class="u-mb-1">Product Editor</h1><div class="u-text-muted">Draft-first editing with publish-readiness checks.</div></div>
    <div class="u-flex u-gap-2">
        <a class="ui-button ui-button--secondary" target="_blank" rel="noopener" href="product-preview.php?id=<?php echo (int)$id; ?>">Preview</a>
        <button type="button" class="ui-button ui-button--outline" id="check-readiness-btn">Check saved draft</button>
        <button type="submit" form="product-editor-form" data-submit-intent="publish" class="ui-button ui-button--success" id="publish-product-btn">Save &amp; Publish</button>
    </div>
</div>
<div id="product-action-message" class="ui-alert u-hidden" role="status"></div>

<?php if (!empty($errors)): ?>
    <div class="ui-alert ui-alert--warning">
        <?php if (!empty($errors['save'])): ?><?php echo e((string)$errors['save']); ?>
        <?php else: ?>Please fix the highlighted fields below.<?php endif; ?>
    </div>
<?php endif; ?>
<?php if ($isVariableInventory): ?>
    <div class="ui-alert ui-alert--info">
        This product has variants. Variant stock, colour and sizes are managed in the variants section below.
    </div>
<?php endif; ?>

<?php
$isEdit = true;
$submitLabel = 'Save Changes';
$cancelHref = 'fabrics.php';
$cancelLabel = 'Back';
include __DIR__ . '/partials/fabric-product-form.php';
?>

<div class="ui-card u-mt-4 u-mx-3" id="product-media-card" data-admin-product-media data-product-id="<?php echo (int) $id; ?>" data-endpoint="product-media.php" data-actions-endpoint="product-actions.php" data-csrf-token="<?php echo e(csrf_token()); ?>">
  <div class="ui-card__header"><strong>Image 1–10 / Video 1–2</strong> <span class="u-text-muted">— catalogue media fields</span></div>
  <div class="ui-card__body">
    <form id="product-media-upload" class="l-grid l-grid--12 u-gap-2 u-items-end" enctype="multipart/form-data">
      <div class="l-col-md-quarter"><label for="media_type" class="ui-label">Type</label><select id="media_type" class="ui-select" name="media_type"><option value="image">Image</option><option value="video">Video</option></select></div>
      <div class="l-col-md-five"><label for="file" class="ui-label">File</label><input id="file" class="ui-input" type="file" name="file" accept="image/*,video/mp4,video/webm,video/ogg" required></div>
      <div class="l-col-md-third"><button class="ui-button ui-button--primary u-w-full" type="submit">Upload media</button></div>
    </form>
    <div class="u-text-small u-text-muted u-mt-2">Drag images to reorder. Changes save immediately.</div>
    <div id="product-media-message" class="u-mt-2" aria-live="polite"></div>
    <div id="product-media-list" class="l-grid l-grid--12 u-gap-3 u-mt-1"></div>
  </div>
</div>

<!-- ═══════════════════════════════════════════════════════════════════════
     VARIANTS SECTION
═══════════════════════════════════════════════════════════════════════════ -->
<?php
$isNewProduct = !empty($_GET['new_product']);
$isSetUnitType = ((string) ($fabric['unit_type'] ?? '') === 'set');
$variantSizePolicy = get_variant_size_policy_by_unit_type((string) ($fabric['unit_type'] ?? 'meter'));
$variantSizeMode = (string) ($variantSizePolicy['mode'] ?? 'preset_with_custom');
$variantSizePresets = array_values((array) ($variantSizePolicy['sizes'] ?? []));
$variantHasPresetSizes = !empty($variantSizePresets);
$variantUnitType = in_array((string) ($fabric['unit_type'] ?? ''), ['meter', 'piece', 'set'], true)
    ? (string) $fabric['unit_type']
    : 'meter';
$variantProductContext = ProductVariantService::productContext($conn, $id) ?? [];
$isVariableProduct = (($variantProductContext['product_type'] ?? 'simple') === 'variable');
$baseVariantPrice = (float) ($variantProductContext['effective_price'] ?? 0);
$variantStockLabel = $variantUnitType === 'meter' ? 'metres' : ($variantUnitType === 'set' ? 'sets' : 'pieces');
$variants = ProductVariantService::enrich($variants, $variantProductContext);
?>

<?php if ($isNewProduct): ?>
<div class="ui-alert ui-alert--success u-mt-3 u-mx-3" data-ui-dismissible role="status">
    <strong>Product created!</strong> Now add colour &amp; size variants below to set up per-variant stock.
    <button type="button" class="ui-button ui-button--secondary ui-button--icon" data-ui-dismiss aria-label="Dismiss">&times;</button>
</div>
<?php endif; ?>

<div class="ui-card u-mt-4 u-mx-3 u-mb-4 u-hidden" id="variants-card" data-admin-variants data-product-id="<?php echo (int) $id; ?>" data-endpoint="fabric-variants.php" data-actions-endpoint="product-actions.php" data-csrf-token="<?php echo e(csrf_token()); ?>" data-size-policy="<?php echo e($variantSizeMode); ?>" data-size-presets="<?php echo ui_data_json($variantSizePresets); ?>" data-unit-type="<?php echo e($variantUnitType); ?>" data-variable="<?php echo $isVariableProduct ? '1' : '0'; ?>">
    <div class="ui-card__header u-flex u-flex-wrap u-justify-between u-items-center u-gap-2">
        <div><h5 class="u-mb-1">Product Variants</h5><div class="u-text-small u-text-muted">Each row inherits the product price, tax, shipping and base gallery unless an override is provided.</div></div>
        <div class="u-flex u-flex-wrap u-gap-2">
            <?php if ($isVariableProduct): ?>
            <button type="button" class="ui-button ui-button--secondary" id="disable-variants-btn">Switch to simple</button>
            <?php endif; ?>
            <button type="button" class="ui-button ui-button--primary" id="variants-add-btn" <?php echo $isVariableProduct ? '' : 'disabled'; ?>>
                <?php echo ui_icon('plus-lg'); ?> Add Variant
            </button>
        </div>
    </div>
    <div class="ui-card__body u-p-0">
        <div class="u-p-3 u-border-bottom u-bg-soft">
            <div class="l-grid l-grid--12 u-gap-2 u-text-small">
                <div class="l-col-half l-col-auto"><span class="u-text-muted u-block">Product</span><strong><?php echo e((string)$fabric['name']); ?></strong></div>
                <div class="l-col-half l-col-auto"><span class="u-text-muted u-block">Base SKU</span><code><?php echo e((string)$fabric['sku']); ?></code></div>
                <div class="l-col-half l-col-auto"><span class="u-text-muted u-block">Inventory mode</span><strong id="variant-product-mode"><?php echo $isVariableProduct ? 'Variable' : 'Simple'; ?></strong></div>
                <div class="l-col-half l-col-auto"><span class="u-text-muted u-block">Sold by</span><strong><?php echo e(ucfirst($variantUnitType)); ?></strong></div>
                <div class="l-col-half l-col-auto"><span class="u-text-muted u-block">Inherited price</span><strong><?php echo e(money($baseVariantPrice)); ?></strong></div>
                <div class="l-col-half l-col-auto"><span class="u-text-muted u-block">Base images</span><strong><?php echo (int)($variantProductContext['base_media_count']??0); ?></strong></div>
            </div>
        </div>
        <?php if (!$isVariableProduct): ?>
        <div class="ui-alert ui-alert--warning u-m-3" id="variant-mode-warning">
            <div class="u-flex u-flex-wrap u-justify-between u-items-center u-gap-2">
                <div><strong>This is a simple product.</strong><div class="u-text-small">Enabling variants will move inventory control to variant rows, clear base stock, and keep the product in draft until a sellable variant exists.</div></div>
                <button type="button" class="ui-button ui-button--warning" id="enable-variants-btn">Enable variable inventory</button>
            </div>
        </div>
        <?php endif; ?>
        <div class="ui-dialog-backdrop" id="variant-editor-modal" data-ui-dialog data-ui-dialog-static hidden>
            <div class="ui-dialog admin-dialog admin-dialog--wide" role="dialog" aria-modal="true" aria-labelledby="variant-form-title" tabindex="-1">
                <div class="admin-dialog__content">
                    <div class="ui-dialog__header">
                        <div>
                            <h2 id="variant-form-title" class="ui-dialog__title">Add Variant</h2>
                            <small id="vf_editing_note" class="u-text-muted u-hidden"></small>
                        </div>
                        <button type="button" class="ui-button ui-button--secondary ui-button--icon" data-ui-dialog-close aria-label="Close">&times;</button>
                    </div>
                    <div class="ui-dialog__body">
            <div class="ui-alert ui-alert--neutral u-border u-py-2 u-mb-3">
                <div class="l-grid l-grid--12 u-gap-2 u-text-small">
                    <div class="l-col-half l-col-lg-quarter"><span class="u-text-muted u-block">Parent product</span><strong><?php echo e((string)$fabric['name']); ?></strong></div>
                    <div class="l-col-half l-col-lg-quarter"><span class="u-text-muted u-block">Product SKU</span><code><?php echo e((string)$fabric['sku']); ?></code></div>
                    <div class="l-col-half l-col-lg-quarter"><span class="u-text-muted u-block">Unit and inventory</span><strong><?php echo e(ucfirst($variantUnitType)); ?> · Variable</strong></div>
                    <div class="l-col-half l-col-lg-quarter"><span class="u-text-muted u-block">Inherited price</span><strong><?php echo e(money($baseVariantPrice)); ?></strong></div>
                </div>
                <div class="u-text-small u-text-muted u-mt-2">Tax, shipping details and the base gallery are inherited from the parent product unless this variant provides an override.</div>
            </div>
            <input type="hidden" id="vf_variant_id" value="0">
            <div class="l-grid l-grid--12 u-gap-2">
                <div class="l-col-md-half l-col-xl-quarter">
                    <label class="ui-label ui-label--small" for="vf_color">Colour</label>
                    <input type="text" id="vf_color" class="ui-input ui-input--small" placeholder="e.g. Red">
                </div>
                <div class="l-col-md-half l-col-xl-quarter">
                    <?php /* Points at the preset <select>; the sibling custom-size input carries
                             its own aria-label because only one of the two is visible at a time. */ ?>
                    <label class="ui-label ui-label--small" for="vf_size_preset">Size</label>
                    <div id="vf_size_group">
                        <select id="vf_size_preset" class="ui-select ui-select--small <?php echo $variantHasPresetSizes ? '' : 'u-hidden'; ?>">
                            <option value="">Select size</option>
<?php foreach ($variantSizePresets as $presetSize): ?>
                            <option value="<?php echo e($presetSize); ?>"><?php echo e($presetSize); ?></option>
<?php endforeach; ?>
                            <option value="__custom__">Custom size</option>
                        </select>
                        <input type="text" id="vf_size_custom" aria-label="Custom size" class="ui-input ui-input--small u-mt-1 <?php echo $variantHasPresetSizes ? 'u-hidden' : ''; ?>" placeholder="Enter one size only">
                        <input type="hidden" id="vf_size" value="">
                        <small id="vf_size_hint" class="u-text-muted">
                            <?php echo $variantSizeMode === 'hidden'
                                ? 'Size is not used for meter products.'
                                : 'Size is required for piece/set variants.'; ?>
                        </small>
                    </div>
                </div>
                <div class="l-col-md-half l-col-xl-quarter<?php echo $isSetUnitType ? '' : ' u-hidden'; ?>" id="vf_pack_controls">
                    <label class="ui-label ui-label--small" for="vf_units_per_set">Units per Set</label>
                    <input type="number" id="vf_units_per_set" class="ui-input ui-input--small" value="1" min="1" step="1">
                    <input type="text" id="vf_pack_label" aria-label="Pack label" class="ui-input ui-input--small u-mt-1" placeholder="Pack of N">
                    <small class="u-text-muted u-block u-mt-1">For set products, 1 quantity means 1 full set.</small>
                </div>
                <div class="l-col-md-half l-col-xl-quarter">
                    <label class="ui-label ui-label--small" for="vf_sku">Variant SKU <small class="u-text-muted">(inherits product prefix)</small></label>
                    <input type="text" id="vf_sku" class="ui-input ui-input--small" maxlength="100" pattern="[A-Z0-9_-]+" placeholder="Suggested on save; editable">
                </div>
                <div class="l-col-md-half l-col-xl-quarter">
                    <?php /* Each of these points at the file input, not the hidden field that
                             carries the already-stored path - the file input is the one an
                             operator interacts with. */ ?>
                    <label class="ui-label ui-label--small" for="vf_image_file">Variant Image 1</label>
                    <input type="hidden" id="vf_image" value="">
                    <input type="file" id="vf_image_file" class="ui-input ui-input--small" accept="image/*">
                    <small id="vf_image_current" class="u-text-muted u-hidden"></small>
                    <label id="vf_image_remove_wrap" class="ui-check u-text-small u-mt-1 u-hidden"><input class="ui-check__input" type="checkbox" id="vf_remove_image"> Remove current override</label>
                </div>
                <div class="l-col-md-half l-col-xl-quarter">
                    <label class="ui-label ui-label--small" for="vf_image2_file">Variant Image 2</label>
                    <input type="hidden" id="vf_image2" value="">
                    <input type="file" id="vf_image2_file" class="ui-input ui-input--small" accept="image/*">
                    <small id="vf_image2_current" class="u-text-muted u-hidden"></small>
                    <label id="vf_image2_remove_wrap" class="ui-check u-text-small u-mt-1 u-hidden"><input class="ui-check__input" type="checkbox" id="vf_remove_image2"> Remove current override</label>
                </div>
                <div class="l-col-md-half l-col-xl-quarter">
                    <label class="ui-label ui-label--small" for="vf_image3_file">Variant Image 3</label>
                    <input type="hidden" id="vf_image3" value="">
                    <input type="file" id="vf_image3_file" class="ui-input ui-input--small" accept="image/*">
                    <small id="vf_image3_current" class="u-text-muted u-hidden"></small>
                    <label id="vf_image3_remove_wrap" class="ui-check u-text-small u-mt-1 u-hidden"><input class="ui-check__input" type="checkbox" id="vf_remove_image3"> Remove current override</label>
                </div>
                <div class="l-col-md-half l-col-xl-quarter">
                    <label class="ui-label ui-label--small" for="vf_image4_file">Variant Image 4</label>
                    <input type="hidden" id="vf_image4" value="">
                    <input type="file" id="vf_image4_file" class="ui-input ui-input--small" accept="image/*">
                    <small id="vf_image4_current" class="u-text-muted u-hidden"></small>
                    <label id="vf_image4_remove_wrap" class="ui-check u-text-small u-mt-1 u-hidden"><input class="ui-check__input" type="checkbox" id="vf_remove_image4"> Remove current override</label>
                </div>
                <div class="l-col-md-half l-col-xl-third">
                    <label class="ui-label ui-label--small" for="vf_video_file">Variant Video</label>
                    <input type="hidden" id="vf_video" value="">
                    <input type="file" id="vf_video_file" class="ui-input ui-input--small" accept="video/mp4,video/webm,video/ogg">
                    <small id="vf_video_current" class="u-text-muted u-hidden"></small>
                    <label id="vf_video_remove_wrap" class="ui-check u-text-small u-mt-1 u-hidden"><input class="ui-check__input" type="checkbox" id="vf_remove_video"> Remove current override</label>
                </div>
                <div class="l-col-md-half l-col-xl-third">
                    <label class="ui-label ui-label--small" for="vf_price_override">Selling Price Override <small class="u-text-muted">(optional)</small></label>
                    <input type="number" id="vf_price_override" class="ui-input ui-input--small" placeholder="Blank = <?php echo e(money($baseVariantPrice)); ?>" min="0.01" max="<?php echo e((string)($fabric['price']??'')); ?>" step="0.01">
                    <small class="u-text-muted">Must not exceed product MRP <?php echo e(money((float)($fabric['price']??0))); ?>.</small>
                </div>
                <div class="l-col-md-half l-col-xl-quarter" id="vf_stock_pcs_wrap">
                    <label class="ui-label ui-label--small" id="vf_stock_label" for="vf_stock">Stock (pcs)</label>
                    <input type="number" id="vf_stock" class="ui-input ui-input--small" value="0" min="0" step="1">
                    <small id="vf_stock_unit_hint" class="u-text-muted u-block u-mt-1"></small>
                </div>
                <div class="l-col-md-half l-col-xl-quarter" id="vf_stock_m_wrap">
                    <label class="ui-label ui-label--small" for="vf_stock_meters">Stock (m)</label>
                    <input type="number" id="vf_stock_meters" class="ui-input ui-input--small" value="0" min="0" step="0.01">
                </div>
                <div class="l-col-md-half l-col-xl-two">
                    <label class="ui-label ui-label--small" for="vf_sort_order">Display Order</label>
                    <input type="number" id="vf_sort_order" class="ui-input ui-input--small" value="0" min="0" step="1">
                </div>
                <div class="l-col-md-half l-col-xl-two u-flex u-items-center">
                    <div class="ui-check u-ms-1">
                        <input class="ui-check__input" type="checkbox" id="vf_is_active" checked>
                        <label class="ui-check__label" for="vf_is_active">Active</label>
                    </div>
                </div>
            </div>
                    </div>
                    <div class="ui-dialog__footer">
                <button type="button" class="ui-button ui-button--success" id="variant-save-btn">
                    <?php echo ui_icon('check-lg'); ?> Save Variant
                </button>
                <button type="button" class="ui-button ui-button--secondary u-ms-1" id="variant-cancel-btn" data-ui-dialog-close>Cancel</button>
                <span id="vf_saving_msg" class="u-ms-2 u-text-muted u-text-small u-hidden">Saving…</span>
                <span id="vf_error_msg" class="u-ms-2 u-text-danger u-text-small"></span>
                    </div>
                </div>
            </div>
        </div>

        <div class="ui-dialog-backdrop" id="simple-inventory-modal" data-ui-dialog data-ui-dialog-static hidden>
            <div class="ui-dialog admin-dialog" role="dialog" aria-modal="true" aria-labelledby="simple-inventory-title" tabindex="-1">
                <div class="admin-dialog__content">
                    <div class="ui-dialog__header">
                        <h2 class="ui-dialog__title" id="simple-inventory-title">Switch to Simple Inventory</h2>
                        <button type="button" class="ui-button ui-button--secondary ui-button--icon" data-ui-dialog-close aria-label="Close">&times;</button>
                    </div>
                    <div class="ui-dialog__body">
                        <p class="u-mb-3">All variants will be permanently deleted and the product will return to draft. Enter the new base stock.</p>
                        <label class="ui-label" for="simple-base-stock">Base stock (<?php echo e($variantStockLabel); ?>)</label>
                        <input type="number" class="ui-input" id="simple-base-stock" min="0" step="<?php echo $variantUnitType === 'meter' ? '0.01' : '1'; ?>" value="0">
                        <div class="u-text-danger u-text-small u-mt-2 u-hidden" id="simple-inventory-error"></div>
                    </div>
                    <div class="ui-dialog__footer">
                        <button type="button" class="ui-button ui-button--secondary" data-ui-dialog-close>Cancel</button>
                        <button type="button" class="ui-button ui-button--danger" id="confirm-simple-inventory">Delete Variants and Switch</button>
                    </div>
                </div>
            </div>
        </div>

        <!-- Variants table -->
        <div class="ui-table-wrap">
            <table class="ui-table ui-table--compact ui-table--hover u-mb-0" id="variants-table">
                <thead class="ui-table__head--light">
                    <tr>
                        <th class="admin-col--colour">Colour</th>
                        <th class="admin-col--size">Size</th>
                        <th class="admin-col--pack">Pack</th>
                        <th>SKU</th>
                        <th class="admin-col--media">Media source</th>
                        <th class="admin-col--price">Selling price</th>
                        <th class="admin-col--inventory">Inventory (<?php echo e($variantStockLabel); ?>)</th>
                        <th class="admin-col--active">Active</th>
                        <th class="admin-col--actions">Actions</th>
                    </tr>
                </thead>
                <tbody id="variants-tbody">
<?php
$displayVariants = $variants;
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
                                <span class="u-text-small"><?php echo e($pl); ?> (<?php echo (int) $ups; ?>)</span>
                            <?php else: ?>
                                <span class="u-text-muted">&mdash;</span>
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
                            <?php if ($imgCount > 0 || $hasVideo): ?>
                                <span class="u-text-small"><?php echo (int) $imgCount; ?> image<?php echo $imgCount === 1 ? '' : 's'; ?><?php echo $hasVideo ? ' + video' : ''; ?><span class="u-block u-text-muted">Variant override</span></span>
                            <?php else: ?>
                                <span class="u-text-small">Base gallery<span class="u-block u-text-muted">Inherited</span></span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php $effectiveVariantPrice = $v['price_override'] !== null ? (float)$v['price_override'] : $baseVariantPrice; ?>
                            <?php echo e(money($effectiveVariantPrice)); ?>
                            <span class="u-block u-text-muted u-text-small"><?php echo $v['price_override'] !== null ? 'Override' : 'Inherited'; ?></span>
                        </td>
                        <td><?php echo e(format_meter_quantity($variantUnitType === 'meter' ? (float)$v['stock_meters'] : (float)$v['stock'])); ?></td>
                        <td>
                            <?php if ($v['is_active']): ?>
                                <span class="ui-badge ui-badge--success">Yes</span>
                            <?php else: ?>
                                <span class="ui-badge ui-badge--neutral">No</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <button type="button" class="ui-button ui-button--xsmall ui-button--outline u-me-1" data-action="edit"
                                data-variant-id="<?php echo (int)$v['id']; ?>" title="Edit">
                                <?php echo ui_icon('pencil'); ?><span>Edit</span>
                            </button>
                            <button type="button" class="ui-button ui-button--xsmall ui-button--danger-outline" data-action="delete"
                                data-variant-id="<?php echo (int)$v['id']; ?>" title="Delete">
                                <?php echo ui_icon('trash'); ?><span>Delete</span>
                            </button>
                        </td>
                    </tr>
<?php endforeach; ?>
<?php if (empty($displayVariants)): ?>
                    <tr id="variants-empty-row">
                        <td colspan="9" class="u-text-center u-text-muted u-py-4">No variants yet. Enable variable inventory, then select <strong>Add Variant</strong>.</td>
                    </tr>
<?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php include 'partials/footer.php'; ?>
