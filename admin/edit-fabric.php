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
        $publishChecks = [];
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
                $publishChecks = (array) ($publishResult['checks'] ?? []);
                throw new RuntimeException('Product does not meet the publishing requirements.');
            }
        }
        log_admin_activity($conn,(int)$_SESSION['admin_id'],'product_draft_saved','product',$id,'Product editor changes saved.','ok');
        $conn->commit();
        } catch (Throwable $e) {
            $conn->rollback();
            if ($publishChecks) {
                $errors = array_merge($errors, $publishChecks);
            } else {
                $errors['save'] = $e->getMessage();
            }
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

<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-4">
    <div><h1 class="mb-1">Product Editor</h1><div class="text-muted">Draft-first editing with publish-readiness checks.</div></div>
    <div class="d-flex gap-2">
        <a class="btn btn-outline-secondary" target="_blank" rel="noopener" href="product-preview.php?id=<?php echo (int)$id; ?>">Preview</a>
        <button type="button" class="btn btn-outline-primary" id="check-readiness-btn">Check saved draft</button>
        <button type="submit" form="product-editor-form" data-submit-intent="publish" class="btn btn-success" id="publish-product-btn">Save &amp; Publish</button>
    </div>
</div>
<div id="product-action-message" class="alert d-none" role="status"></div>

<?php if (!empty($errors)): ?>
    <div class="alert alert-warning">
        <?php if (!empty($errors['save'])): ?><?php echo e((string)$errors['save']); ?>
        <?php else: ?>
            <strong>Please fix the highlighted fields below.</strong>
            <ul class="mb-0 mt-2">
                <?php foreach ($errors as $message): ?>
                    <li><?php echo e((string)$message); ?></li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </div>
<?php endif; ?>
<?php if ($isVariableInventory): ?>
    <div class="alert alert-info">
        This product has variants. Variant stock, colour and sizes are managed in the variants section below.
    </div>
<?php endif; ?>

<?php
$isEdit = true;
$submitLabel = 'Save Changes';
$cancelHref = 'fabrics.php';
$cancelLabel = 'Back';
include __DIR__ . '/partials/fabric-product-form.php';
include __DIR__ . '/partials/fabric-product-form-script.php';
?>

<div class="card mt-4 mx-3<?php echo !empty($errors['media']) ? ' border-danger' : ''; ?>" id="product-media-card">
  <div class="card-header"><strong>Image 1–10 / Video 1–2</strong> <span class="text-muted">— catalogue media fields</span></div>
  <div class="card-body">
    <form id="product-media-upload" class="row g-2 align-items-end" enctype="multipart/form-data">
      <div class="col-md-3"><label class="form-label">Type</label><select class="form-select" name="media_type"><option value="image">Image</option><option value="video">Video</option></select></div>
      <div class="col-md-5"><label class="form-label">File</label><input class="form-control" type="file" name="file" accept="image/*,video/mp4,video/webm,video/ogg" required></div>
      <div class="col-md-4"><button class="btn btn-primary w-100" type="submit">Upload media</button></div>
    </form>
    <?php if (!empty($errors['media'])): ?>
      <div class="invalid-feedback d-block mt-2"><?php echo e((string)$errors['media']); ?></div>
    <?php endif; ?>
    <div class="small text-muted mt-2">Drag images to reorder. Changes save immediately.</div>
    <div id="product-media-message" class="mt-2" aria-live="polite"></div>
    <div id="product-media-list" class="row g-3 mt-1"></div>
  </div>
</div>

<script nonce="<?php echo $cspNonce; ?>">
(function(){
var pid=<?php echo (int)$id; ?>,token=<?php echo json_encode(csrf_token()); ?>,list=document.getElementById('product-media-list'),msg=document.getElementById('product-media-message');
function call(data){data.set('product_id',pid);data.set('csrf_token',token);return fetch('product-media.php',{method:'POST',body:data,credentials:'same-origin'}).then(function(r){return r.json().then(function(j){if(!r.ok||!j.ok)throw new Error(j.message||'Request failed.');return j;});});}
function esc(v){var d=document.createElement('div');d.textContent=String(v||'');return d.innerHTML;}
function render(items){list.innerHTML='';items.forEach(function(m){var c=document.createElement('div');c.className='col-md-4 col-xl-3';c.dataset.mediaId=m.id;c.draggable=m.media_type==='image';var src='../images/fabrics/'+encodeURIComponent(m.filename),preview=m.media_type==='image'?'<img src="'+src+'" class="img-fluid rounded mb-2" alt="">':'<video src="'+src+'" class="w-100 rounded mb-2" controls></video>';c.innerHTML='<div class="border rounded p-2 h-100">'+preview+'<input class="form-control form-control-sm media-alt" maxlength="255" value="'+esc(m.alt_text)+'" placeholder="Alt text"><div class="d-flex gap-1 mt-2">'+(m.media_type==='image'?'<button type="button" class="btn btn-sm '+(Number(m.is_primary)?'btn-success':'btn-outline-secondary')+' media-primary">'+(Number(m.is_primary)?'Primary':'Make primary')+'</button>':'')+'<button type="button" class="btn btn-sm btn-outline-danger ms-auto media-delete">Remove</button></div></div>';list.appendChild(c);});}
function load(){fetch('product-media.php?product_id='+pid,{credentials:'same-origin'}).then(function(r){return r.json().catch(function(){throw new Error('Invalid server response.');}).then(function(j){if(!r.ok||!j.ok)throw new Error(j.message||'Unable to load media.');return j;});}).then(function(j){render(j.media);}).catch(function(x){msg.textContent=x.message||'Unable to load media.';});}
document.getElementById('product-media-upload').addEventListener('submit',function(e){e.preventDefault();var f=this,b=f.querySelector('button'),d=new FormData(f);b.disabled=true;d.set('action','upload');call(d).then(function(j){render(j.media);f.reset();msg.textContent='Media uploaded.';}).catch(function(x){msg.textContent=x.message;}).finally(function(){b.disabled=false;});});
list.addEventListener('change',function(e){if(!e.target.classList.contains('media-alt'))return;var d=new FormData();d.set('action','update');d.set('media_id',e.target.closest('[data-media-id]').dataset.mediaId);d.set('alt_text',e.target.value);call(d).then(function(j){render(j.media);}).catch(function(x){msg.textContent=x.message;});});
list.addEventListener('click',function(e){var c=e.target.closest('[data-media-id]');if(!c)return;var run=function(action){var d=new FormData();d.set('action',action);if(action==='update'){d.set('set_primary','1');d.set('alt_text',c.querySelector('.media-alt').value);}d.set('media_id',c.dataset.mediaId);call(d).then(function(j){render(j.media);}).catch(function(x){msg.textContent=x.message;});};if(e.target.classList.contains('media-primary')){run('update');}else if(e.target.classList.contains('media-delete')){window.adminConfirm({title:'Remove Product Media',message:'Permanently remove this media file?',okText:'Remove Media'}).then(function(ok){if(ok)run('delete');});}});
var dragged;list.addEventListener('dragstart',function(e){dragged=e.target.closest('[data-media-id]');});list.addEventListener('dragover',function(e){e.preventDefault();var over=e.target.closest('[data-media-id]');if(dragged&&over&&dragged!==over)list.insertBefore(dragged,over);});list.addEventListener('drop',function(e){e.preventDefault();var d=new FormData();d.set('action','reorder');list.querySelectorAll('[data-media-id]').forEach(function(el){d.append('media_ids[]',el.dataset.mediaId);});call(d).then(function(j){render(j.media);}).catch(function(x){msg.textContent=x.message;});});
function action(name){var d=new FormData(),box=document.getElementById('product-action-message'),button=document.getElementById('check-readiness-btn'),form=document.getElementById('product-editor-form');if(form&&form.dataset.dirty==='1'){box.className='alert alert-warning';box.textContent='Save your changes before checking the saved draft.';return;}d.set('product_id',pid);d.set('action',name);d.set('csrf_token',token);if(button)button.disabled=true;fetch('product-actions.php',{method:'POST',body:d,credentials:'same-origin'}).then(function(r){return r.json().catch(function(){throw new Error('Invalid server response.');}).then(function(j){if(!r.ok||!j.ok)throw new Error(j.message||'Request failed.');return j;});}).then(function(j){box.className='alert '+(j.ready?'alert-success':'alert-warning');var checks=j.checks||{};box.innerHTML=esc(j.message||(j.ready?'Saved draft is ready to publish.':'Publishing checklist incomplete.'))+(Object.keys(checks).length?'<ul class="mb-0 mt-2">'+Object.keys(checks).map(function(k){return '<li>'+esc(checks[k])+'</li>';}).join('')+'</ul>':'');}).catch(function(error){box.className='alert alert-danger';box.textContent=error.message||'Unable to check the saved draft.';}).finally(function(){if(button)button.disabled=false;});}
document.getElementById('check-readiness-btn').addEventListener('click',function(){action('readiness');});load();
})();
</script>

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
<div class="alert alert-success alert-dismissible fade show mt-3 mx-3" role="alert">
    <strong>Product created!</strong> Now add colour &amp; size variants below to set up per-variant stock.
    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
<?php endif; ?>

<div class="card mt-4 mx-3 mb-4 d-none" id="variants-card">
    <div class="card-header d-flex flex-wrap justify-content-between align-items-center gap-2">
        <div><h5 class="mb-1">Product Variants</h5><div class="small text-muted">Each row inherits the product price, tax, shipping and base gallery unless an override is provided.</div></div>
        <div class="d-flex flex-wrap gap-2">
            <?php if ($isVariableProduct): ?>
            <button type="button" class="btn btn-outline-secondary" id="disable-variants-btn">Switch to simple</button>
            <?php endif; ?>
            <button type="button" class="btn btn-primary" id="variants-add-btn" <?php echo $isVariableProduct ? '' : 'disabled'; ?>>
                <i class="bi bi-plus-lg"></i> Add Variant
            </button>
        </div>
    </div>
    <div class="card-body p-0">
        <?php if (!empty($errors['variants'])): ?>
        <div class="alert alert-danger m-3 mb-0" role="alert"><?php echo e((string)$errors['variants']); ?></div>
        <?php endif; ?>
        <div class="p-3 border-bottom bg-body-tertiary">
            <div class="row g-2 small">
                <div class="col-6 col-lg"><span class="text-muted d-block">Product</span><strong><?php echo e((string)$fabric['name']); ?></strong></div>
                <div class="col-6 col-lg"><span class="text-muted d-block">Base SKU</span><code><?php echo e((string)$fabric['sku']); ?></code></div>
                <div class="col-6 col-lg"><span class="text-muted d-block">Inventory mode</span><strong id="variant-product-mode"><?php echo $isVariableProduct ? 'Variable' : 'Simple'; ?></strong></div>
                <div class="col-6 col-lg"><span class="text-muted d-block">Sold by</span><strong><?php echo e(ucfirst($variantUnitType)); ?></strong></div>
                <div class="col-6 col-lg"><span class="text-muted d-block">Inherited price</span><strong><?php echo e(money($baseVariantPrice)); ?></strong></div>
                <div class="col-6 col-lg"><span class="text-muted d-block">Base images</span><strong><?php echo (int)($variantProductContext['base_media_count']??0); ?></strong></div>
            </div>
        </div>
        <?php if (!$isVariableProduct): ?>
        <div class="alert alert-warning m-3" id="variant-mode-warning">
            <div class="d-flex flex-wrap justify-content-between align-items-center gap-2">
                <div><strong>This is a simple product.</strong><div class="small">Enabling variants will move inventory control to variant rows, clear base stock, and keep the product in draft until a sellable variant exists.</div></div>
                <button type="button" class="btn btn-warning" id="enable-variants-btn">Enable variable inventory</button>
            </div>
        </div>
        <?php endif; ?>
        <div class="modal fade" id="variant-editor-modal" tabindex="-1" aria-labelledby="variant-form-title" aria-hidden="true">
            <div class="modal-dialog modal-xl">
                <div class="modal-content">
                    <div class="modal-header">
                        <div>
                            <h5 id="variant-form-title" class="modal-title">Add Variant</h5>
                            <small id="vf_editing_note" class="text-muted d-none"></small>
                        </div>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body">
            <div class="alert alert-light border py-2 mb-3">
                <div class="row g-2 small">
                    <div class="col-6 col-lg-3"><span class="text-muted d-block">Parent product</span><strong><?php echo e((string)$fabric['name']); ?></strong></div>
                    <div class="col-6 col-lg-3"><span class="text-muted d-block">Product SKU</span><code><?php echo e((string)$fabric['sku']); ?></code></div>
                    <div class="col-6 col-lg-3"><span class="text-muted d-block">Unit and inventory</span><strong><?php echo e(ucfirst($variantUnitType)); ?> · Variable</strong></div>
                    <div class="col-6 col-lg-3"><span class="text-muted d-block">Inherited price</span><strong><?php echo e(money($baseVariantPrice)); ?></strong></div>
                </div>
                <div class="small text-muted mt-2">Tax, shipping details and the base gallery are inherited from the parent product unless this variant provides an override.</div>
            </div>
            <input type="hidden" id="vf_variant_id" value="0">
            <div class="row g-2">
                <div class="col-md-6 col-xl-3">
                    <label class="form-label form-label-sm">Colour</label>
                    <input type="text" id="vf_color" class="form-control form-control-sm" placeholder="e.g. Red">
                </div>
                <div class="col-md-6 col-xl-3">
                    <label class="form-label form-label-sm">Size</label>
                    <div id="vf_size_group">
                        <select id="vf_size_preset" class="form-select form-select-sm <?php echo $variantHasPresetSizes ? '' : 'd-none'; ?>">
                            <option value="">Select size</option>
<?php foreach ($variantSizePresets as $presetSize): ?>
                            <option value="<?php echo e($presetSize); ?>"><?php echo e($presetSize); ?></option>
<?php endforeach; ?>
                            <option value="__custom__">Custom size</option>
                        </select>
                        <input type="text" id="vf_size_custom" class="form-control form-control-sm mt-1 <?php echo $variantHasPresetSizes ? 'd-none' : ''; ?>" placeholder="Enter one size only">
                        <input type="hidden" id="vf_size" value="">
                        <small id="vf_size_hint" class="text-muted">
                            <?php echo $variantSizeMode === 'hidden'
                                ? 'Size is not used for meter products.'
                                : 'Size is required for piece/set variants.'; ?>
                        </small>
                    </div>
                </div>
                <div class="col-md-6 col-xl-3" id="vf_pack_controls" <?php echo $isSetUnitType ? '' : 'style="display:none"'; ?>>
                    <label class="form-label form-label-sm">Units per Set</label>
                    <input type="number" id="vf_units_per_set" class="form-control form-control-sm" value="1" min="1" step="1">
                    <input type="text" id="vf_pack_label" class="form-control form-control-sm mt-1" placeholder="Pack of N">
                    <small class="text-muted d-block mt-1">For set products, 1 quantity means 1 full set.</small>
                </div>
                <div class="col-md-6 col-xl-3">
                    <label class="form-label form-label-sm">Variant SKU <small class="text-muted">(inherits product prefix)</small></label>
                    <input type="text" id="vf_sku" class="form-control form-control-sm" maxlength="100" pattern="[A-Z0-9_-]+" placeholder="Suggested on save; editable">
                </div>
                <div class="col-md-6 col-xl-3">
                    <label class="form-label form-label-sm">Variant Image 1</label>
                    <input type="hidden" id="vf_image" value="">
                    <input type="file" id="vf_image_file" class="form-control form-control-sm" accept="image/*">
                    <small id="vf_image_current" class="text-muted d-none"></small>
                    <label id="vf_image_remove_wrap" class="form-check small mt-1 d-none"><input class="form-check-input" type="checkbox" id="vf_remove_image"> Remove current override</label>
                </div>
                <div class="col-md-6 col-xl-3">
                    <label class="form-label form-label-sm">Variant Image 2</label>
                    <input type="hidden" id="vf_image2" value="">
                    <input type="file" id="vf_image2_file" class="form-control form-control-sm" accept="image/*">
                    <small id="vf_image2_current" class="text-muted d-none"></small>
                    <label id="vf_image2_remove_wrap" class="form-check small mt-1 d-none"><input class="form-check-input" type="checkbox" id="vf_remove_image2"> Remove current override</label>
                </div>
                <div class="col-md-6 col-xl-3">
                    <label class="form-label form-label-sm">Variant Image 3</label>
                    <input type="hidden" id="vf_image3" value="">
                    <input type="file" id="vf_image3_file" class="form-control form-control-sm" accept="image/*">
                    <small id="vf_image3_current" class="text-muted d-none"></small>
                    <label id="vf_image3_remove_wrap" class="form-check small mt-1 d-none"><input class="form-check-input" type="checkbox" id="vf_remove_image3"> Remove current override</label>
                </div>
                <div class="col-md-6 col-xl-3">
                    <label class="form-label form-label-sm">Variant Image 4</label>
                    <input type="hidden" id="vf_image4" value="">
                    <input type="file" id="vf_image4_file" class="form-control form-control-sm" accept="image/*">
                    <small id="vf_image4_current" class="text-muted d-none"></small>
                    <label id="vf_image4_remove_wrap" class="form-check small mt-1 d-none"><input class="form-check-input" type="checkbox" id="vf_remove_image4"> Remove current override</label>
                </div>
                <div class="col-md-6 col-xl-4">
                    <label class="form-label form-label-sm">Variant Video</label>
                    <input type="hidden" id="vf_video" value="">
                    <input type="file" id="vf_video_file" class="form-control form-control-sm" accept="video/mp4,video/webm,video/ogg">
                    <small id="vf_video_current" class="text-muted d-none"></small>
                    <label id="vf_video_remove_wrap" class="form-check small mt-1 d-none"><input class="form-check-input" type="checkbox" id="vf_remove_video"> Remove current override</label>
                </div>
                <div class="col-md-6 col-xl-4">
                    <label class="form-label form-label-sm">Selling Price Override <small class="text-muted">(optional)</small></label>
                    <input type="number" id="vf_price_override" class="form-control form-control-sm" placeholder="Blank = <?php echo e(money($baseVariantPrice)); ?>" min="0.01" max="<?php echo e((string)($fabric['price']??'')); ?>" step="0.01">
                    <small class="text-muted">Must not exceed product MRP <?php echo e(money((float)($fabric['price']??0))); ?>.</small>
                </div>
                <div class="col-md-6 col-xl-3" id="vf_stock_pcs_wrap">
                    <label class="form-label form-label-sm" id="vf_stock_label">Stock (pcs)</label>
                    <input type="number" id="vf_stock" class="form-control form-control-sm" value="0" min="0" step="1">
                    <small id="vf_stock_unit_hint" class="text-muted d-block mt-1"></small>
                </div>
                <div class="col-md-6 col-xl-3" id="vf_stock_m_wrap">
                    <label class="form-label form-label-sm">Stock (m)</label>
                    <input type="number" id="vf_stock_meters" class="form-control form-control-sm" value="0" min="0" step="0.01">
                </div>
                <div class="col-md-6 col-xl-2">
                    <label class="form-label form-label-sm">Display Order</label>
                    <input type="number" id="vf_sort_order" class="form-control form-control-sm" value="0" min="0" step="1">
                </div>
                <div class="col-md-6 col-xl-2 d-flex align-items-center">
                    <div class="form-check ms-1">
                        <input class="form-check-input" type="checkbox" id="vf_is_active" checked>
                        <label class="form-check-label" for="vf_is_active">Active</label>
                    </div>
                </div>
            </div>
                    </div>
                    <div class="modal-footer">
                <button type="button" class="btn btn-success" id="variant-save-btn">
                    <i class="bi bi-check-lg"></i> Save Variant
                </button>
                <button type="button" class="btn btn-outline-secondary ms-1" id="variant-cancel-btn" data-bs-dismiss="modal">Cancel</button>
                <span id="vf_saving_msg" class="ms-2 text-muted small d-none">Saving…</span>
                <span id="vf_error_msg" class="ms-2 text-danger small"></span>
                    </div>
                </div>
            </div>
        </div>

        <div class="modal fade" id="simple-inventory-modal" tabindex="-1" aria-labelledby="simple-inventory-title" aria-hidden="true">
            <div class="modal-dialog modal-dialog-centered">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title" id="simple-inventory-title">Switch to Simple Inventory</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body">
                        <p class="mb-3">All variants will be permanently deleted and the product will return to draft. Enter the new base stock.</p>
                        <label class="form-label" for="simple-base-stock">Base stock (<?php echo e($variantStockLabel); ?>)</label>
                        <input type="number" class="form-control" id="simple-base-stock" min="0" step="<?php echo $variantUnitType === 'meter' ? '0.01' : '1'; ?>" value="0">
                        <div class="text-danger small mt-2 d-none" id="simple-inventory-error"></div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="button" class="btn btn-danger" id="confirm-simple-inventory">Delete Variants and Switch</button>
                    </div>
                </div>
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
                        <th style="width:150px">Media source</th>
                        <th style="width:150px">Selling price</th>
                        <th style="width:110px">Inventory (<?php echo e($variantStockLabel); ?>)</th>
                        <th style="width:70px">Active</th>
                        <th style="width:100px">Actions</th>
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
                            <?php if ($imgCount > 0 || $hasVideo): ?>
                                <span class="small"><?php echo (int) $imgCount; ?> image<?php echo $imgCount === 1 ? '' : 's'; ?><?php echo $hasVideo ? ' + video' : ''; ?><span class="d-block text-muted">Variant override</span></span>
                            <?php else: ?>
                                <span class="small">Base gallery<span class="d-block text-muted">Inherited</span></span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php $effectiveVariantPrice = $v['price_override'] !== null ? (float)$v['price_override'] : $baseVariantPrice; ?>
                            <?php echo e(money($effectiveVariantPrice)); ?>
                            <span class="d-block text-muted small"><?php echo $v['price_override'] !== null ? 'Override' : 'Inherited'; ?></span>
                        </td>
                        <td><?php echo e(format_meter_quantity($variantUnitType === 'meter' ? (float)$v['stock_meters'] : (float)$v['stock'])); ?></td>
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
                        <td colspan="9" class="text-center text-muted py-4">No variants yet. Enable variable inventory, then select <strong>Add Variant</strong>.</td>
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
    var SIZE_HAS_PRESETS = <?php echo $variantHasPresetSizes ? 'true' : 'false'; ?>;
    var VARIANT_UNIT_TYPE = <?php echo json_encode($variantUnitType); ?>;
    var PRODUCT_IS_VARIABLE = <?php echo $isVariableProduct ? 'true' : 'false'; ?>;
    var productUnitSelect = document.querySelector('select[name="unit_type"]');

    // In-memory variant cache from server-rendered data
    var variantCache = {};
    document.querySelectorAll('#variants-tbody tr[data-vid]').forEach(function (tr) {
        var vid = parseInt(tr.dataset.vid);
        variantCache[vid] = {id: vid};
    });

    function getCurrentUnitType() {
        var val = productUnitSelect ? String(productUnitSelect.value || '') : String(VARIANT_UNIT_TYPE || '');
        if (val !== 'meter' && val !== 'piece' && val !== 'set') return 'meter';
        return val;
    }

    function getEffectiveSizeMode() {
        var currentUnit = getCurrentUnitType();
        return currentUnit === 'meter' ? 'hidden' : 'preset_with_custom';
    }

    function getVariantModal() {
        var modalElement = document.getElementById('variant-editor-modal');
        if (!modalElement || !window.bootstrap || !window.bootstrap.Modal) return null;
        if (modalElement.parentElement !== document.body) document.body.appendChild(modalElement);
        return window.bootstrap.Modal.getOrCreateInstance(modalElement, {backdrop: 'static'});
    }

    function showVariantModal() {
        var modal = getVariantModal();
        if (modal) modal.show();
    }

    function showVariantPageMessage(message, alertType) {
        var box = document.getElementById('product-action-message');
        if (!box) return;
        box.className = 'alert alert-' + (alertType || 'info');
        box.textContent = message;
        box.scrollIntoView({behavior: 'smooth', block: 'center'});
    }

    function showVariantPageError(message) {
        showVariantPageMessage(message, 'danger');
    }

    var variantUI = window.variantUI = {
        syncSizePolicyUI: function () {
            var group = document.getElementById('vf_size_group');
            var preset = document.getElementById('vf_size_preset');
            var custom = document.getElementById('vf_size_custom');
            var hidden = document.getElementById('vf_size');
            var hint = document.getElementById('vf_size_hint');
            if (!group || !preset || !custom || !hidden) return;
            var effectiveMode = getEffectiveSizeMode();

            if (effectiveMode === 'hidden') {
                group.classList.remove('opacity-75');
                preset.value = '';
                custom.value = '';
                custom.classList.add('d-none');
                preset.classList.add('d-none');
                preset.disabled = true;
                custom.disabled = true;
                hidden.value = '';
                if (hint) {
                    hint.textContent = 'Size is not used for meter products.';
                }
                return;
            }

            group.classList.remove('opacity-75');
            if (hint) {
                hint.textContent = 'Enter one size for this variant.';
            }
            if (!SIZE_HAS_PRESETS) {
                preset.classList.add('d-none');
                preset.value = '__custom__';
                preset.disabled = true;
                custom.classList.remove('d-none');
                custom.disabled = false;
                hidden.value = custom.value.trim();
                return;
            }
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
            var stockLabel = document.getElementById('vf_stock_label');
            var stockHint = document.getElementById('vf_stock_unit_hint');
            var packControls = document.getElementById('vf_pack_controls');
            if (!pcsWrap || !mWrap || !pcsInput || !mInput) return;
            var currentUnit = getCurrentUnitType();
            var isMeter = currentUnit === 'meter';
            var isSet = currentUnit === 'set';
            pcsWrap.style.display = isMeter ? 'none' : '';
            mWrap.style.display = isMeter ? '' : 'none';
            pcsInput.disabled = isMeter;
            mInput.disabled = !isMeter;
            if (packControls) {
                packControls.style.display = isSet ? '' : 'none';
            }
            if (stockLabel) {
                stockLabel.textContent = isSet ? 'Stock (sets)' : 'Stock (pcs)';
            }
            if (stockHint) {
                stockHint.textContent = isSet
                    ? 'Enter number of sets available.'
                    : 'Enter number of pieces available.';
            }
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
            if (getEffectiveSizeMode() === 'hidden') {
                preset.value = '';
                custom.value = '';
                hidden.value = '';
                variantUI.syncSizePolicyUI();
                return;
            }
            if (!SIZE_HAS_PRESETS) {
                preset.value = '__custom__';
                custom.value = value;
                hidden.value = value;
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
        setExistingMediaStatus: function (field, hasExistingMedia) {
            var status = document.getElementById('vf_' + field + '_current');
            var removeWrap = document.getElementById('vf_' + field + '_remove_wrap');
            var removeInput = document.getElementById('vf_remove_' + field);
            var currentInput = document.getElementById('vf_' + field);
            if (!status) return;
            status.textContent = hasExistingMedia
                ? 'Current: ' + String(currentInput ? currentInput.value : '') + '. Choose a file only to replace it.'
                : '';
            status.classList.toggle('d-none', !hasExistingMedia);
            if (removeWrap) removeWrap.classList.toggle('d-none', !hasExistingMedia);
            if (removeInput) removeInput.checked = false;
        },
        showAddForm: function () {
            if (!PRODUCT_IS_VARIABLE) {
                var warning = document.getElementById('variant-mode-warning');
                if (warning) warning.scrollIntoView({behavior: 'smooth', block: 'center'});
                return;
            }
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
            document.getElementById('vf_sort_order').value   = '0';
            document.getElementById('vf_is_active').checked  = true;
            document.getElementById('variant-form-title').textContent = 'Add Variant';
            document.getElementById('variant-save-btn').disabled = false;
            document.getElementById('vf_editing_note').classList.add('d-none');
            ['image', 'image2', 'image3', 'image4', 'video'].forEach(function (field) {
                variantUI.setExistingMediaStatus(field, false);
            });
            document.getElementById('vf_error_msg').textContent = '';
            showVariantModal();
        },

        hideForm: function () {
            var modal = getVariantModal();
            if (modal) modal.hide();
        },

        editVariant: function (vid) {
            var saveButton = document.getElementById('variant-save-btn');
            var errorElement = document.getElementById('vf_error_msg');
            document.getElementById('variant-form-title').textContent = 'Loading Variant…';
            if (saveButton) saveButton.disabled = true;
            if (errorElement) errorElement.textContent = 'Loading all variant details…';
            showVariantModal();
            fetch(ENDPOINT + '?action=list&fabric_id=' + FABRIC_ID)
                .then(function (response) {
                    return response.json().catch(function () { throw new Error('Invalid server response.'); });
                })
                .then(function (data) {
                    if (!data.success) throw new Error(data.message || 'Could not load variant details.');
                    var v = data.variants.find(function (x) { return x.id == vid; });
                    if (!v) throw new Error('Variant not found.');
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
                    document.getElementById('vf_sort_order').value      = v.sort_order || 0;
                    document.getElementById('vf_is_active').checked     = parseInt(v.is_active) === 1;
                    document.getElementById('variant-form-title').textContent = 'Edit Variant';
                    var editingNote = document.getElementById('vf_editing_note');
                    editingNote.textContent = 'Editing existing variant #' + v.id + '.';
                    editingNote.classList.remove('d-none');
                    ['image', 'image2', 'image3', 'image4', 'video'].forEach(function (field) {
                        variantUI.setExistingMediaStatus(field, !!(v[field] && String(v[field]).trim()));
                    });
                    document.getElementById('vf_error_msg').textContent = '';
                    if (saveButton) saveButton.disabled = false;
                })
                .catch(function (error) {
                    document.getElementById('variant-form-title').textContent = 'Unable to Load Variant';
                    if (errorElement) errorElement.textContent = error.message || 'Could not load variant details.';
                });
        },

        saveVariant: function () {
            var errEl  = document.getElementById('vf_error_msg');
            var saveEl = document.getElementById('vf_saving_msg');
            var saveBtn = document.getElementById('variant-save-btn');
            errEl.textContent  = '';
            saveEl.classList.remove('d-none');
            if (saveBtn) saveBtn.disabled = true;

            var fd = new FormData();
            fd.append('csrf_token',     CSRF);
            fd.append('action',         'save');
            fd.append('sku',            document.getElementById('vf_sku').value.trim());
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
            ['image', 'image2', 'image3', 'image4', 'video'].forEach(function (field) {
                var removeInput = document.getElementById('vf_remove_' + field);
                fd.append('remove_' + field, removeInput && removeInput.checked ? '1' : '0');
            });
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
            var currentUnit = getCurrentUnitType();
            fd.append('units_per_set',  currentUnit === 'set' ? document.getElementById('vf_units_per_set').value : '');
            fd.append('pack_label',     currentUnit === 'set' ? document.getElementById('vf_pack_label').value : '');
            fd.append('stock',          document.getElementById('vf_stock').value);
            fd.append('stock_meters',   document.getElementById('vf_stock_meters').value);
            fd.append('sort_order',     document.getElementById('vf_sort_order').value);
            fd.append('is_active',      document.getElementById('vf_is_active').checked ? '1' : '0');

            fetch(ENDPOINT, {method: 'POST', body: fd})
                .then(function (response) {
                    return response.json().catch(function () { throw new Error('Invalid server response.'); });
                })
                .then(function (data) {
                    if (!data.success) {
                        var fieldErrors = data.errors && typeof data.errors === 'object'
                            ? Object.values(data.errors).filter(Boolean) : [];
                        errEl.textContent = fieldErrors.length ? fieldErrors[0] : (data.message || 'Error saving variant.');
                        return;
                    }
                    variantUI.hideForm();
                    variantUI.reloadTable();
                })
                .catch(function () {
                    errEl.textContent = 'Network error. Please try again.';
                })
                .finally(function () {
                    saveEl.classList.add('d-none');
                    if (saveBtn) saveBtn.disabled = false;
                });
        },

        deleteVariant: function (vid, btn) {
            var confirmer = window.adminConfirm({
                title: 'Remove Variant',
                message: 'Remove this variant? Variants used in business records will be archived so history remains intact.',
                okText: 'Remove Variant'
            });
            confirmer.then(function (confirmed) {
                if (!confirmed) { return; }
                var fd = new FormData();
                fd.append('csrf_token', CSRF);
                fd.append('action',     'delete');
                fd.append('fabric_id',  FABRIC_ID);
                fd.append('variant_id', vid);
                btn.disabled = true;
                fetch(ENDPOINT, {method: 'POST', body: fd})
                    .then(function (response) {
                        return response.json().catch(function () { throw new Error('Invalid server response.'); });
                    })
                    .then(function (data) {
                        if (!data.success) {
                            throw new Error(data.message || 'Could not remove variant.');
                        }
                        showVariantPageMessage(
                            data.message || (data.archived ? 'Variant archived.' : 'Variant permanently removed.'),
                            data.archived ? 'info' : 'success'
                        );
                        variantUI.reloadTable();
                    })
                    .catch(function (error) {
                        showVariantPageError(error.message || 'Could not remove variant.');
                    })
                    .finally(function () {
                        btn.disabled = false;
                    });
            });
        },

        reloadTable: function () {
            fetch(ENDPOINT + '?action=list&fabric_id=' + FABRIC_ID)
                .then(function (response) {
                    return response.json().catch(function () { throw new Error('Invalid server response.'); });
                })
                .then(function (data) {
                    if (!data.success) throw new Error(data.message || 'Could not reload variants.');
                    var tbody = document.getElementById('variants-tbody');
                    var rows = Array.isArray(data.variants) ? data.variants.slice() : [];

                    if (rows.length === 0) {
                        tbody.innerHTML = '<tr id="variants-empty-row"><td colspan="9" class="text-center text-muted py-4">No variants yet. Select <strong>Add Variant</strong> to create one.</td></tr>';
                        return;
                    }
                    var html = rows.map(function (v) {
                        var effectivePrice = Number.parseFloat(v.effective_price || 0);
                        var priceCell = '&#8377;' + effectivePrice.toFixed(2)
                            + '<span class="d-block text-muted small">' + (v.inherits_price ? 'Inherited' : 'Override') + '</span>';
                        var imageCount = 0;
                        ['image', 'image2', 'image3', 'image4'].forEach(function (k) {
                            if (v[k] && String(v[k]).trim() !== '') imageCount++;
                        });
                        var hasVideo = !!(v.video && String(v.video).trim() !== '');
                        var imageCell = imageCount > 0 || hasVideo
                            ? '<span class="small">' + imageCount + ' image' + (imageCount === 1 ? '' : 's') + (hasVideo ? ' + video' : '') + '<span class="d-block text-muted">Variant override</span></span>'
                            : '<span class="small">Base gallery<span class="d-block text-muted">Inherited</span></span>';
                        var packCell = '<span class="text-muted">&mdash;</span>';
                        if (VARIANT_UNIT_TYPE === 'set') {
                            var ups = parseInt(v.units_per_set || '0', 10);
                            if (!Number.isFinite(ups) || ups <= 0) ups = 1;
                            var pl = (v.pack_label && String(v.pack_label).trim() !== '') ? String(v.pack_label) : ('Pack of ' + ups);
                            packCell = '<span class="small">' + esc(pl) + ' (' + ups + ')</span>';
                        }
                        var activeBadge = parseInt(v.is_active) === 1
                            ? '<span class="badge bg-success">Yes</span>'
                            : '<span class="badge bg-secondary">No</span>';
                        return '<tr data-vid="' + v.id + '">'
                            + '<td>' + esc(v.color || '\u2014') + '</td>'
                            + '<td>' + esc(v.size  || '\u2014') + '</td>'
                            + '<td>' + packCell + '</td>'
                            + '<td><code>' + esc(v.sku || '') + '</code></td>'
                            + '<td>' + imageCell + '</td>'
                            + '<td>' + priceCell + '</td>'
                            + '<td>' + Number.parseFloat(v.effective_stock || 0).toFixed(VARIANT_UNIT_TYPE === 'meter' ? 2 : 0) + '</td>'
                            + '<td>' + activeBadge + '</td>'
                            + '<td>'
                            + '<button type="button" class="btn btn-xs btn-outline-primary me-1" data-action="edit" data-variant-id="' + v.id + '" title="Edit"><i class="bi bi-pencil me-1" aria-hidden="true"></i><span>Edit</span></button>'
                            + '<button type="button" class="btn btn-xs btn-outline-danger" data-action="delete" data-variant-id="' + v.id + '" title="Delete"><i class="bi bi-trash me-1" aria-hidden="true"></i><span>Delete</span></button>'
                            + '</td>'
                            + '</tr>';
                    }).join('');
                    tbody.innerHTML = html;
                })
                .catch(function (error) {
                    showVariantPageError(error.message || 'Could not reload variants.');
                });
        }
    };

    var addBtn = document.getElementById('variants-add-btn');
    if (addBtn) {
        addBtn.addEventListener('click', function () {
            variantUI.showAddForm();
        });
    }

    var enableVariantsBtn = document.getElementById('enable-variants-btn');
    if (enableVariantsBtn) {
        enableVariantsBtn.addEventListener('click', function () {
            var confirmer = window.adminConfirm({
                title: 'Enable Variable Inventory',
                message: 'Base stock will be cleared and inventory control will move to variants. The product will remain in draft until a sellable variant is added.',
                okText: 'Enable Variants'
            });
            confirmer.then(function (confirmed) {
                if (!confirmed) return;
                var fd = new FormData();
                fd.append('csrf_token', CSRF);
                fd.append('action', 'enable-variants');
                fd.append('product_id', FABRIC_ID);
                enableVariantsBtn.disabled = true;
                enableVariantsBtn.textContent = 'Enabling…';
                fetch('product-actions.php', {method: 'POST', body: fd})
                    .then(function (response) { return response.json(); })
                    .then(function (data) {
                        if (!data.ok) throw new Error(data.message || 'Could not enable variants.');
                        window.location.reload();
                    })
                    .catch(function (error) {
                        enableVariantsBtn.disabled = false;
                        enableVariantsBtn.textContent = 'Enable variable inventory';
                        showVariantPageError(error.message || 'Could not enable variants.');
                    });
            });
        });
    }

    var disableVariantsBtn = document.getElementById('disable-variants-btn');
    if (disableVariantsBtn) {
        disableVariantsBtn.addEventListener('click', function () {
            var modalElement = document.getElementById('simple-inventory-modal');
            var baseStockInput = document.getElementById('simple-base-stock');
            var errorElement = document.getElementById('simple-inventory-error');
            if (baseStockInput) baseStockInput.value = '0';
            if (errorElement) { errorElement.textContent = ''; errorElement.classList.add('d-none'); }
            if (modalElement.parentElement !== document.body) document.body.appendChild(modalElement);
            bootstrap.Modal.getOrCreateInstance(modalElement, {backdrop: 'static'}).show();
        });
    }

    var confirmSimpleInventoryBtn = document.getElementById('confirm-simple-inventory');
    if (confirmSimpleInventoryBtn) {
        confirmSimpleInventoryBtn.addEventListener('click', function () {
            var modalElement = document.getElementById('simple-inventory-modal');
            var baseStockInput = document.getElementById('simple-base-stock');
            var errorElement = document.getElementById('simple-inventory-error');
            var baseStock = String(baseStockInput ? baseStockInput.value : '').trim();
            var showError = function (message) {
                if (!errorElement) return;
                errorElement.textContent = message;
                errorElement.classList.remove('d-none');
            };
            if (baseStock === '' || !Number.isFinite(Number(baseStock)) || Number(baseStock) < 0) {
                showError('Enter valid non-negative base stock.');
                return;
            }
            if (VARIANT_UNIT_TYPE !== 'meter' && !Number.isInteger(Number(baseStock))) {
                showError('Piece and set stock must be a whole number.');
                return;
            }
            var fd = new FormData();
            fd.append('csrf_token', CSRF);
            fd.append('action', 'disable-variants');
            fd.append('product_id', FABRIC_ID);
            fd.append('base_stock', baseStock);
            disableVariantsBtn.disabled = true;
            confirmSimpleInventoryBtn.disabled = true;
            disableVariantsBtn.textContent = 'Switching…';
            fetch('product-actions.php', {method: 'POST', body: fd})
                .then(function (response) { return response.json(); })
                .then(function (data) {
                    if (!data.ok) throw new Error(data.message || 'Could not change inventory mode.');
                    window.location.reload();
                })
                .catch(function (error) {
                    disableVariantsBtn.disabled = false;
                    confirmSimpleInventoryBtn.disabled = false;
                    disableVariantsBtn.textContent = 'Switch to simple';
                    showError(error.message || 'Could not change inventory mode.');
                });
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
    if (productUnitSelect) {
        productUnitSelect.addEventListener('change', function () {
            variantUI.syncSizePolicyUI();
            variantUI.syncStockFieldUI();
        });
    }

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
