<?php
/**
 * Admin AJAX endpoint: Manage fabric_variants
 * Actions (POST param "action"):
 *   list   – GET  – returns JSON array of variants for a fabric_id
 *   save   – POST – INSERT or UPDATE a single variant (id=0 → insert)
 *   delete – POST – soft-delete (is_active = 0) unless no orders reference it (then hard-delete)
 *   reorder– POST – bulk update sort_order
 */

require_once __DIR__ . '/../includes/init.php';
require_admin();

header('Content-Type: application/json; charset=utf-8');

$action   = trim((string) ($_REQUEST['action'] ?? ''));
$fabricId = (int) ($_REQUEST['fabric_id'] ?? 0);

// ── Helper ──────────────────────────────────────────────────────────────────
function json_error(string $msg, int $code = 400, array $errors = []): never
{
    http_response_code($code);
    echo json_encode(['success' => false, 'message' => $msg, 'errors' => $errors]);
    exit;
}

function json_ok(array $data = []): never
{
    echo json_encode(array_merge(['success' => true], $data));
    exit;
}

// ── list ────────────────────────────────────────────────────────────────────
if ($action === 'list') {
    if ($fabricId <= 0) {
        json_error('fabric_id required');
    }
    $product = ProductVariantService::productContext($conn, $fabricId);
    if (!$product) json_error('Product not found.', 404);
    $variants = ProductVariantService::enrich(InventoryService::get_fabric_variants($conn, $fabricId), $product);
    json_ok(['variants' => $variants, 'product' => $product]);
}

// ── save ────────────────────────────────────────────────────────────────────
if ($action === 'save') {
    if (!verify_csrf()) {
        json_error('Invalid CSRF token.', 403);
    }
    if ($fabricId <= 0) {
        json_error('fabric_id required');
    }

    $variantId = (int) ($_POST['variant_id'] ?? 0);
    $product = ProductVariantService::productContext($conn, $fabricId);
    if (!$product) json_error('Product not found.', 404);
    $currentVariant = null;
    if ($variantId > 0) {
        $currentVariant = InventoryService::get_variant_by_id($conn, $variantId);
        if (!$currentVariant || (int) $currentVariant['fabric_id'] !== $fabricId) json_error('Variant not found.', 404);
    }
    $validation = ProductVariantService::validate($conn, $product, $_POST, $variantId);
    if ($validation['errors']) json_error('Please correct the variant fields.', 422, $validation['errors']);
    $values = $validation['values'];
    $color=$values['color'];$size=$values['size'];$sku=$values['sku'];$priceOverride=$values['price_override'];
    $stock=$values['stock'];$stockMeters=$values['stock_meters'];$isActive=$values['is_active'];$sortOrder=$values['sort_order'];
    $unitsPerSet=$values['units_per_set'];$packLabel=$values['pack_label'];
    $image=(string)($currentVariant['image']??'');$image2=(string)($currentVariant['image2']??'');
    $image3=(string)($currentVariant['image3']??'');$image4=(string)($currentVariant['image4']??'');$video=(string)($currentVariant['video']??'');

    $saveImageUpload = static function (string $key, string $label): ?string {
        if (empty($_FILES[$key]['name'] ?? '')) {
            return null;
        }
        $file = $_FILES[$key];
        try {
            $saved = save_fabric_image_upload($file, $label);
        } catch (Throwable $e) {
            throw new RuntimeException($e->getMessage(), 0, $e);
        }
        return $saved;
    };
    $saveVideoUpload = static function (string $key): ?string {
        if (empty($_FILES[$key]['name'] ?? '')) {
            return null;
        }
        $file = $_FILES[$key];
        $allowedExt = ['mp4', 'webm', 'ogg'];
        $allowedMime = ['video/mp4', 'video/webm', 'video/ogg'];
        $maxSize = 25 * 1024 * 1024;
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            throw new RuntimeException('Variant video upload failed.');
        }
        $ext = strtolower(pathinfo((string) ($file['name'] ?? ''), PATHINFO_EXTENSION));
        $mime = mime_content_type((string) ($file['tmp_name'] ?? '')) ?: '';
        if (($file['size'] ?? 0) > $maxSize) {
            throw new RuntimeException('Variant video must be under 25MB.');
        }
        if (!in_array($ext, $allowedExt, true) || !in_array($mime, $allowedMime, true)) {
            throw new RuntimeException('Variant video must be MP4, WEBM or OGG.');
        }
        $saved = random_filename((string) ($file['name'] ?? 'variant.mp4'));
        try {
            $target = fabric_upload_path($saved);
        } catch (Throwable $e) {
            throw new RuntimeException($e->getMessage(), 0, $e);
        }
        if (!move_uploaded_file((string) ($file['tmp_name'] ?? ''), $target)) {
            throw new RuntimeException('Could not save variant video.');
        }
        return $saved;
    };

    $previousMedia = [$image, $image2, $image3, $image4, $video];
    if (!empty($_POST['remove_image'])) $image='';
    if (!empty($_POST['remove_image2'])) $image2='';
    if (!empty($_POST['remove_image3'])) $image3='';
    if (!empty($_POST['remove_image4'])) $image4='';
    if (!empty($_POST['remove_video'])) $video='';
    $uploadedFiles = [];
    $newImage=$newImage2=$newImage3=$newImage4=$newVideo=null;
    try {
        $newImage=$saveImageUpload('image_file','Variant image 1');if($newImage!==null)$uploadedFiles[]=$newImage;
        $newImage2=$saveImageUpload('image2_file','Variant image 2');if($newImage2!==null)$uploadedFiles[]=$newImage2;
        $newImage3=$saveImageUpload('image3_file','Variant image 3');if($newImage3!==null)$uploadedFiles[]=$newImage3;
        $newImage4=$saveImageUpload('image4_file','Variant image 4');if($newImage4!==null)$uploadedFiles[]=$newImage4;
        $newVideo=$saveVideoUpload('video_file');if($newVideo!==null)$uploadedFiles[]=$newVideo;
    } catch (Throwable $e) {
        foreach($uploadedFiles as $filename){if(preg_match('/\.(mp4|webm|ogg)$/i',$filename))@unlink(fabric_upload_path($filename));else image_pipeline_delete_files(dirname(fabric_upload_path($filename)),$filename);}
        json_error($e->getMessage(), 422);
    }
    $image=$newImage??$image;$image2=$newImage2??$image2;$image3=$newImage3??$image3;$image4=$newImage4??$image4;$video=$newVideo??$video;

    $conn->begin_transaction();
    try {
    if ($variantId <= 0) {
        // INSERT
        $stmt = $conn->prepare(
            "INSERT INTO fabric_variants (fabric_id, color, size, sku, image, image2, image3, image4, video, pack_label, units_per_set, price_override, stock, stock_meters, is_active, sort_order)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
        );
        $stmt->bind_param('isssssssssidddii', $fabricId, $color, $size, $sku, $image, $image2, $image3, $image4, $video, $packLabel, $unitsPerSet, $priceOverride, $stock, $stockMeters, $isActive, $sortOrder);
        $stmt->execute();
        $variantId = (int) $conn->insert_id;
    } else {
        // UPDATE – verify it belongs to this fabric
        $stmt = $conn->prepare(
            "UPDATE fabric_variants
             SET color = ?, size = ?, sku = ?, image = ?, image2 = ?, image3 = ?, image4 = ?, video = ?, pack_label = ?, units_per_set = ?, price_override = ?, stock = ?, stock_meters = ?, is_active = ?, sort_order = ?
             WHERE id = ? AND fabric_id = ?"
        );
        $stmt->bind_param('sssssssssidddiiii', $color, $size, $sku, $image, $image2, $image3, $image4, $video, $packLabel, $unitsPerSet, $priceOverride, $stock, $stockMeters, $isActive, $sortOrder, $variantId, $fabricId);
        $stmt->execute();
    }

    ProductVariantService::syncAvailability($conn, $fabricId);
    log_admin_activity($conn,(int)$_SESSION['admin_id'],'product_variant_saved','product',$fabricId,'Product variant saved.','ok');
    $conn->commit();
    } catch (Throwable $e) {
        $conn->rollback();
        foreach($uploadedFiles as $filename){if(preg_match('/\.(mp4|webm|ogg)$/i',$filename))@unlink(fabric_upload_path($filename));else image_pipeline_delete_files(dirname(fabric_upload_path($filename)),$filename);}
        error_log('[variant-save] '.$e->getMessage());
        json_error((int)$e->getCode()===1062?'This colour, size, or SKU already exists.':'Unable to save the variant.',422);
    }
    $currentMedia=[$image,$image2,$image3,$image4,$video];
    foreach($previousMedia as $oldFile){if($oldFile===''||in_array($oldFile,$currentMedia,true))continue;if(preg_match('/\.(mp4|webm|ogg)$/i',$oldFile))@unlink(fabric_upload_path($oldFile));else image_pipeline_delete_files(dirname(fabric_upload_path($oldFile)),$oldFile);}
    $variant=ProductVariantService::enrich([InventoryService::get_variant_by_id($conn,$variantId)],$product)[0]??null;
    if(function_exists('product_feed_refresh_files'))product_feed_refresh_files(['conn'=>$conn]);
    json_ok(['variant'=>$variant,'product'=>ProductVariantService::productContext($conn,$fabricId)]);
}

// ── delete ───────────────────────────────────────────────────────────────────
if ($action === 'delete') {
    if (!verify_csrf()) {
        json_error('Invalid CSRF token.', 403);
    }
    $variantId = (int) ($_POST['variant_id'] ?? 0);
    if ($variantId <= 0 || $fabricId <= 0) {
        json_error('variant_id required');
    }
    $currentVariant = InventoryService::get_variant_by_id($conn, $variantId);
    if (!$currentVariant || (int) ($currentVariant['fabric_id'] ?? 0) !== $fabricId) {
        json_error('Variant not found.', 404);
    }
    $variantMedia = array_values(array_filter(array_map('strval', [
        $currentVariant['image'] ?? '', $currentVariant['image2'] ?? '',
        $currentVariant['image3'] ?? '', $currentVariant['image4'] ?? '',
        $currentVariant['video'] ?? '',
    ])));

    $conn->begin_transaction();
    try {
        $detach = $conn->prepare("UPDATE order_items SET variant_id = NULL WHERE variant_id = ?");
        $detach->bind_param('i', $variantId);
        $detach->execute();
        $stmt = $conn->prepare("DELETE FROM fabric_variants WHERE id = ? AND fabric_id = ?");
        $stmt->bind_param('ii', $variantId, $fabricId);
        $stmt->execute();
        ProductVariantService::syncAvailability($conn, $fabricId);
        log_admin_activity($conn,(int)$_SESSION['admin_id'],'product_variant_removed','product',$fabricId,'Product variant permanently deleted.','ok');
        $conn->commit();
    } catch (Throwable $e) {
        $conn->rollback();
        error_log('[variant-delete] ' . $e->getMessage());
        json_error('Unable to remove the variant.', 500);
    }
    foreach ($variantMedia as $filename) {
        if (preg_match('/\.(mp4|webm|ogg)$/i', $filename)) @unlink(fabric_upload_path($filename));
        else image_pipeline_delete_files(dirname(fabric_upload_path($filename)), $filename);
    }
    if(function_exists('product_feed_refresh_files'))product_feed_refresh_files(['conn'=>$conn]);
    json_ok(['deleted' => true]);
}

// ── reorder ──────────────────────────────────────────────────────────────────
if ($action === 'reorder') {
    if (!verify_csrf()) {
        json_error('Invalid CSRF token.', 403);
    }
    // Expects POST: order[] = comma-separated variant IDs in desired order
    $orderedIds = array_filter(array_map('intval', (array) ($_POST['order'] ?? [])));
    $i = 0;
    $conn->begin_transaction();
    foreach ($orderedIds as $vid) {
        $stmt = $conn->prepare("UPDATE fabric_variants SET sort_order = ? WHERE id = ? AND fabric_id = ?");
        $stmt->bind_param('iii', $i, $vid, $fabricId);
        $stmt->execute();
        $i++;
    }
    log_admin_activity($conn,(int)$_SESSION['admin_id'],'product_variants_reordered','product',$fabricId,'Product variants reordered.','ok');
    $conn->commit();
    json_ok();
}

json_error('Unknown action.', 400);
