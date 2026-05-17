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

function cleanup_legacy_default_variants(mysqli $conn, int $fabricId): void
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
    $activeRealCount = (int) (($countStmt->get_result()->fetch_assoc()['cnt'] ?? 0));
    if ($activeRealCount <= 0) {
        return;
    }

    // Remove/deactivate only legacy blank variants.
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

// ── Helper ──────────────────────────────────────────────────────────────────
function variant_slug(string $s): string
{
    $s = mb_strtolower(trim($s));
    $s = preg_replace('/[^a-z0-9]+/u', '-', $s);
    return trim($s, '-');
}

function variant_auto_sku(mysqli $conn, int $fabricId, string $color, string $size): string
{
    $stmt = $conn->prepare("SELECT sku FROM fabrics WHERE id = ? LIMIT 1");
    $stmt->bind_param('i', $fabricId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $base = strtoupper(trim((string) ($row['sku'] ?? '')));
    if ($base === '') {
        $base = 'F' . $fabricId;
    }
    $parts = array_filter([variant_slug($color), variant_slug($size)]);
    if (!empty($parts)) {
        return $base . '-' . strtoupper(implode('-', $parts));
    }
    return $base . '-DEFAULT';
}

function get_fabric_size_policy(mysqli $conn, int $fabricId): array
{
    $stmt = $conn->prepare("SELECT category FROM fabrics WHERE id = ? LIMIT 1");
    $stmt->bind_param('i', $fabricId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $category = (string) ($row['category'] ?? '');
    return get_variant_size_policy_by_category($category);
}

function get_fabric_unit_type(mysqli $conn, int $fabricId): string
{
    $stmt = $conn->prepare("SELECT unit_type FROM fabrics WHERE id = ? LIMIT 1");
    $stmt->bind_param('i', $fabricId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $unitType = (string) ($row['unit_type'] ?? 'meter');
    return in_array($unitType, ['meter', 'piece', 'set'], true) ? $unitType : 'meter';
}

function json_error(string $msg, int $code = 400): never
{
    http_response_code($code);
    echo json_encode(['success' => false, 'message' => $msg]);
    exit;
}

function json_ok(array $data = []): never
{
    echo json_encode(array_merge(['success' => true], $data));
    exit;
}

function sync_fabric_availability_from_variants(mysqli $conn, int $fabricId): void
{
    if ($fabricId <= 0) {
        return;
    }
    $fstmt = $conn->prepare("SELECT unit_type, status FROM fabrics WHERE id = ? LIMIT 1");
    $fstmt->bind_param('i', $fabricId);
    $fstmt->execute();
    $fabric = $fstmt->get_result()->fetch_assoc();
    if (!$fabric) {
        return;
    }
    $unitType = in_array((string) ($fabric['unit_type'] ?? ''), ['meter', 'piece', 'set'], true)
        ? (string) $fabric['unit_type']
        : 'meter';
    $isActiveProduct = ((string) ($fabric['status'] ?? 'inactive') === 'active');

    $vstmt = $conn->prepare("SELECT is_active, stock, stock_meters FROM fabric_variants WHERE fabric_id = ?");
    $vstmt->bind_param('i', $fabricId);
    $vstmt->execute();
    $rows = $vstmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $hasSellable = false;
    foreach ($rows as $row) {
        if ((int) ($row['is_active'] ?? 0) !== 1) {
            continue;
        }
        $qty = ($unitType === 'meter')
            ? (float) ($row['stock_meters'] ?? 0)
            : (float) ($row['stock'] ?? 0);
        if ($qty > 0) {
            $hasSellable = true;
            break;
        }
    }

    $isAvailable = ($isActiveProduct && $hasSellable) ? 1 : 0;
    $up = $conn->prepare("UPDATE fabrics SET is_available = ? WHERE id = ?");
    $up->bind_param('ii', $isAvailable, $fabricId);
    $up->execute();
}

// ── list ────────────────────────────────────────────────────────────────────
if ($action === 'list') {
    if ($fabricId <= 0) {
        json_error('fabric_id required');
    }
    cleanup_legacy_default_variants($conn, $fabricId);
    sync_fabric_availability_from_variants($conn, $fabricId);
    $variants = get_fabric_variants($conn, $fabricId);
    json_ok(['variants' => $variants]);
}

// ── save ────────────────────────────────────────────────────────────────────
if ($action === 'save') {
    if (!verify_csrf()) {
        json_error('Invalid CSRF token.', 403);
    }
    if ($fabricId <= 0) {
        json_error('fabric_id required');
    }

    $variantId     = (int)   ($_POST['variant_id']     ?? 0);
    $color         = trim((string) ($_POST['color']    ?? ''));
    $size          = normalize_variant_size_text((string) ($_POST['size'] ?? ''));
    $image         = trim((string) ($_POST['image']    ?? ''));
    $image2        = trim((string) ($_POST['image2']   ?? ''));
    $image3        = trim((string) ($_POST['image3']   ?? ''));
    $image4        = trim((string) ($_POST['image4']   ?? ''));
    $video         = trim((string) ($_POST['video']    ?? ''));
    $packLabelRaw  = trim((string) ($_POST['pack_label'] ?? ''));
    $unitsPerSetIn = $_POST['units_per_set'] ?? null;
    $priceOverride = ($_POST['price_override'] !== '' && is_numeric($_POST['price_override'] ?? ''))
        ? round((float) $_POST['price_override'], 2)
        : null;
    $stock         = max(0.0, round((float) ($_POST['stock']        ?? 0), 2));
    $stockMeters   = max(0.0, round((float) ($_POST['stock_meters'] ?? 0), 2));
    $isActive      = (int) (bool) ($_POST['is_active'] ?? 1);
    $sortOrder     = (int) ($_POST['sort_order'] ?? 0);

    $sizePolicy = get_fabric_size_policy($conn, $fabricId);
    $unitType = get_fabric_unit_type($conn, $fabricId);
    $mode = (string) ($sizePolicy['mode'] ?? 'preset_with_custom');
    $allowedSizes = array_values(array_filter(array_map(static function ($s) {
        return normalize_variant_size_text((string) $s);
    }, (array) ($sizePolicy['sizes'] ?? [])), static function ($s) {
        return $s !== '';
    }));
    if ($mode === 'hidden') {
        $size = '';
    } else {
        if ($size === '') {
            json_error('Size is required for this category.');
        }
        if ($unitType === 'set' && preg_match('/^pack\s+of\s+\d+$/i', $size)) {
            json_error('Use Pack label / Units per set for pack details; Size should contain actual size only.');
        }
        if (preg_match('/[,\/|]/', $size)) {
            json_error('Enter only one size per variant. Create separate variants for each size option.');
        }
        if (!empty($allowedSizes)) {
            $isPreset = in_array($size, $allowedSizes, true);
            if (!$isPreset && mb_strlen($size) > 100) {
                json_error('Custom size is too long.');
            }
        }
    }
    $unitsPerSet = null;
    $packLabel = '';
    if ($unitType === 'set') {
        if (!is_numeric($unitsPerSetIn) || (float) $unitsPerSetIn <= 0 || floor((float) $unitsPerSetIn) != (float) $unitsPerSetIn) {
            json_error('Units per set must be a whole number greater than 0.');
        }
        $unitsPerSet = normalize_units_per_set($unitsPerSetIn);
        $packLabel = normalize_variant_size_text($packLabelRaw);
        if ($packLabel !== '' && preg_match('/^pack\s+of\s+(\d+)$/i', $packLabel, $m)) {
            $parsed = normalize_units_per_set((int) ($m[1] ?? 1));
            $unitsPerSet = $parsed;
            $packLabel = format_pack_label($parsed);
        }
        if ($packLabel === '') {
            $packLabel = format_pack_label($unitsPerSet);
        }
        if (mb_strlen($packLabel) > 120) {
            json_error('Pack label is too long.');
        }
    }

    // Always auto-generate SKU from product SKU + variant attributes.
    $sku = variant_auto_sku($conn, $fabricId, $color, $size);

    $saveImageUpload = static function (string $key, string $label): ?string {
        if (empty($_FILES[$key]['name'] ?? '')) {
            return null;
        }
        $file = $_FILES[$key];
        try {
            $saved = save_fabric_image_upload($file, $label);
        } catch (Throwable $e) {
            json_error($e->getMessage());
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
            json_error('Variant video upload failed.');
        }
        $ext = strtolower(pathinfo((string) ($file['name'] ?? ''), PATHINFO_EXTENSION));
        $mime = mime_content_type((string) ($file['tmp_name'] ?? '')) ?: '';
        if (($file['size'] ?? 0) > $maxSize) {
            json_error('Variant video must be under 25MB.');
        }
        if (!in_array($ext, $allowedExt, true) || !in_array($mime, $allowedMime, true)) {
            json_error('Variant video must be MP4, WEBM or OGG.');
        }
        $saved = random_filename((string) ($file['name'] ?? 'variant.mp4'));
        $target = __DIR__ . '/../images/fabrics/' . $saved;
        if (!move_uploaded_file((string) ($file['tmp_name'] ?? ''), $target)) {
            json_error('Could not save variant video.');
        }
        return $saved;
    };

    $image = $saveImageUpload('image_file', 'Variant image 1') ?? $image;
    $image2 = $saveImageUpload('image2_file', 'Variant image 2') ?? $image2;
    $image3 = $saveImageUpload('image3_file', 'Variant image 3') ?? $image3;
    $image4 = $saveImageUpload('image4_file', 'Variant image 4') ?? $image4;
    $video = $saveVideoUpload('video_file') ?? $video;

    if ($variantId <= 0) {
        // INSERT
        $stmt = $conn->prepare(
            "INSERT INTO fabric_variants (fabric_id, color, size, sku, image, image2, image3, image4, video, pack_label, units_per_set, price_override, stock, stock_meters, is_active, sort_order)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
        );
        $stmt->bind_param('isssssssssidddii', $fabricId, $color, $size, $sku, $image, $image2, $image3, $image4, $video, $packLabel, $unitsPerSet, $priceOverride, $stock, $stockMeters, $isActive, $sortOrder);
        try {
            $stmt->execute();
        } catch (mysqli_sql_exception $e) {
            if ((int) $e->getCode() === 1062) {
                json_error('A variant with this colour + size already exists.');
            }
            json_error('Database error: ' . $e->getMessage());
        }
        $variantId = (int) $conn->insert_id;
    } else {
        // UPDATE – verify it belongs to this fabric
        $check = $conn->prepare("SELECT id FROM fabric_variants WHERE id = ? AND fabric_id = ? LIMIT 1");
        $check->bind_param('ii', $variantId, $fabricId);
        $check->execute();
        if (!$check->get_result()->fetch_assoc()) {
            json_error('Variant not found.', 404);
        }
        $stmt = $conn->prepare(
            "UPDATE fabric_variants
             SET color = ?, size = ?, sku = ?, image = ?, image2 = ?, image3 = ?, image4 = ?, video = ?, pack_label = ?, units_per_set = ?, price_override = ?, stock = ?, stock_meters = ?, is_active = ?, sort_order = ?
             WHERE id = ? AND fabric_id = ?"
        );
        $stmt->bind_param('sssssssssidddiiii', $color, $size, $sku, $image, $image2, $image3, $image4, $video, $packLabel, $unitsPerSet, $priceOverride, $stock, $stockMeters, $isActive, $sortOrder, $variantId, $fabricId);
        try {
            $stmt->execute();
        } catch (mysqli_sql_exception $e) {
            if ((int) $e->getCode() === 1062) {
                json_error('A variant with this colour + size already exists.');
            }
            json_error('Database error: ' . $e->getMessage());
        }
    }

    cleanup_legacy_default_variants($conn, $fabricId);
    sync_fabric_availability_from_variants($conn, $fabricId);
    $variant = get_variant_by_id($conn, $variantId);
    json_ok(['variant' => $variant]);
}

// ── delete ───────────────────────────────────────────────────────────────────
if ($action === 'delete') {
    if (!verify_csrf()) {
        json_error('Invalid CSRF token.', 403);
    }
    $variantId = (int) ($_POST['variant_id'] ?? 0);
    if ($variantId <= 0) {
        json_error('variant_id required');
    }

    // Check if any order items reference this variant
    $check = $conn->prepare("SELECT COUNT(*) AS cnt FROM order_items WHERE variant_id = ? LIMIT 1");
    $check->bind_param('i', $variantId);
    $check->execute();
    $row = $check->get_result()->fetch_assoc();
    $hasOrders = ((int) ($row['cnt'] ?? 0)) > 0;

    if ($hasOrders) {
        // Soft-delete only – keep record for order history
        $stmt = $conn->prepare("UPDATE fabric_variants SET is_active = 0 WHERE id = ? AND fabric_id = ?");
        $stmt->bind_param('ii', $variantId, $fabricId);
        $stmt->execute();
        sync_fabric_availability_from_variants($conn, $fabricId);
        json_ok(['deleted' => false, 'deactivated' => true]);
    } else {
        $stmt = $conn->prepare("DELETE FROM fabric_variants WHERE id = ? AND fabric_id = ?");
        $stmt->bind_param('ii', $variantId, $fabricId);
        $stmt->execute();
        sync_fabric_availability_from_variants($conn, $fabricId);
        json_ok(['deleted' => true, 'deactivated' => false]);
    }
}

// ── reorder ──────────────────────────────────────────────────────────────────
if ($action === 'reorder') {
    if (!verify_csrf()) {
        json_error('Invalid CSRF token.', 403);
    }
    // Expects POST: order[] = comma-separated variant IDs in desired order
    $orderedIds = array_filter(array_map('intval', (array) ($_POST['order'] ?? [])));
    $i = 0;
    foreach ($orderedIds as $vid) {
        $stmt = $conn->prepare("UPDATE fabric_variants SET sort_order = ? WHERE id = ? AND fabric_id = ?");
        $stmt->bind_param('iii', $i, $vid, $fabricId);
        $stmt->execute();
        $i++;
    }
    json_ok();
}

json_error('Unknown action.', 400);
