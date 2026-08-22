<?php
require_once __DIR__ . '/../includes/init.php';
require_once __DIR__ . '/../includes/security/customer-auth.php';
$customerId=(int)($_SESSION['customer_id']??0);$orderId=(int)($_POST['order_id']??0);$returnUrl=$customerId>0?'/customer/order-view.php?id='.$orderId:'/guest/order?id='.$orderId;

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect($customerId>0?'/customer/orders.php':'/guest/order-access');
}
if (!verify_csrf()) {
    flash('error', 'Invalid session token. Please try again.');
    redirect($returnUrl);
}

$reason = trim((string) ($_POST['reason'] ?? ''));
$customerNote = trim((string) ($_POST['customer_note'] ?? ''));
$returnQtyMap = (isset($_POST['return_qty']) && is_array($_POST['return_qty'])) ? $_POST['return_qty'] : [];
$saved = [];

if ($orderId <= 0 || $reason === '' || !OrderAccessService::canAccess($orderId)) {
    flash('error', 'Please provide required return details.');
    redirect($returnUrl);
}

$ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
if (!public_form_rate_limit_allow('return_request_' . $ip, 5, 900)) {
    flash('error', 'Too many return requests. Please wait a few minutes and try again.');
        redirect($returnUrl);
}

try {
    $conn->begin_transaction();

    $ownerSql = $customerId > 0 ? 'AND o.customer_id = ?' : '';
    $orderStmt = $conn->prepare(
        "SELECT o.id, o.order_number, o.order_status, o.pincode, s.delivered_at
         FROM orders o
         LEFT JOIN shipments s ON s.order_id = o.id
         WHERE o.id = ? {$ownerSql}
         FOR UPDATE"
    );
    if($customerId>0){$orderStmt->bind_param('ii',$orderId,$customerId);}else{$orderStmt->bind_param('i',$orderId);}
    $orderStmt->execute();
    $order = $orderStmt->get_result()->fetch_assoc();
    if (!$order) {
        throw new RuntimeException('Order not found.');
    }

    if (strtolower((string) ($order['order_status'] ?? '')) !== 'delivered') {
        throw new RuntimeException('Return is allowed only for delivered orders.');
    }
    $deliveredAt = trim((string) ($order['delivered_at'] ?? ''));
    if ($deliveredAt === '') {
        throw new RuntimeException('Return can be requested only after delivery is confirmed with delivered date.');
    }
    if (!return_request_is_eligible($deliveredAt)) {
        throw new RuntimeException('Return window is closed. You can request a refund return only within ' . return_request_window_days() . ' calendar days of delivery.');
    }

    $existsStmt = $conn->prepare("SELECT id FROM returns WHERE order_id = ? LIMIT 1");
    $existsStmt->bind_param('i', $orderId);
    $existsStmt->execute();
    if ($existsStmt->get_result()->fetch_assoc()) {
        throw new RuntimeException('A return request already exists for this order.');
    }

    if (
        !isset($_FILES['image_1'], $_FILES['image_2']) ||
        (int) ($_FILES['image_1']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK ||
        (int) ($_FILES['image_2']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK
    ) {
        throw new RuntimeException('Please upload both required return images.');
    }

    $uploadDir = dirname(__DIR__) . '/images/returns';
    UploadPolicy::ensureDirectory($uploadDir);

    $allowedMimes = [
        'image/jpeg' => 'jpg',
        'image/pjpeg' => 'jpg',
        'image/jpg' => 'jpg',
        'image/png' => 'png',
        'image/x-png' => 'png',
        'image/webp' => 'webp',
    ];
    $allowedExts = ['jpg', 'jpeg', 'png', 'webp'];
    $maxBytes = 5 * 1024 * 1024;
    foreach (['image_1', 'image_2'] as $field) {
        try {
            $validated = UploadPolicy::validate($_FILES[$field], $allowedExts, $allowedMimes, $maxBytes, true);
        } catch (Throwable $e) {
            throw new RuntimeException('Only JPG, PNG, or WEBP images are allowed.');
        }
        $targetExt = (string) $validated['storage_extension'];

        $filename = 'return_' . date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.' . $targetExt;
        try {
            UploadPolicy::move($_FILES[$field], $uploadDir, $filename);
        } catch (Throwable $e) {
            throw new RuntimeException('Failed to upload return image.');
        }
        $saved[$field] = 'images/returns/' . $filename;
    }

    $returnNumber = 'RET' . date('Ymd') . strtoupper(substr(bin2hex(random_bytes(3)), 0, 6));
    $insertReturn = $conn->prepare(
        "INSERT INTO returns (return_number, order_id, customer_id, status, reason, customer_note, image_1, image_2)
         VALUES (?, ?, ?, 'requested', ?, ?, ?, ?)"
    );
    $img1 = (string) ($saved['image_1'] ?? '');
    $img2 = (string) ($saved['image_2'] ?? '');
    $returnCustomerId=$customerId>0?$customerId:null;
    $insertReturn->bind_param('siissss', $returnNumber, $orderId, $returnCustomerId, $reason, $customerNote, $img1, $img2);
    $insertReturn->execute();
    $returnId = (int) $conn->insert_id;
    $reverseNote = 'Reverse pickup will be automated only when the configured courier supports it; otherwise arrange pickup manually.';
    $noteStmt = $conn->prepare("UPDATE returns SET admin_note = ? WHERE id = ?");
    $noteStmt->bind_param('si', $reverseNote, $returnId);
    $noteStmt->execute();

    $itemStmt = $conn->prepare(
        "SELECT id, fabric_id, variant_id, product_name, fabric_name_snapshot, unit_type, quantity, quantity_meters, total, line_total
         FROM order_items
         WHERE order_id = ?"
    );
    $itemStmt->bind_param('i', $orderId);
    $itemStmt->execute();
    $items = $itemStmt->get_result()->fetch_all(MYSQLI_ASSOC);

    $selectedCount = 0;
    $insertItem = $conn->prepare(
        "INSERT INTO return_items (return_id, order_item_id, fabric_id, variant_id, product_name, unit_type, quantity, line_total)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?)"
    );
    foreach ($items as $item) {
        $orderItemId = (int) ($item['id'] ?? 0);
        $fabricId = (int) ($item['fabric_id'] ?? 0);
        $variantId = (int) ($item['variant_id'] ?? 0);
        $productName = trim((string) ($item['product_name'] ?? ''));
        if ($productName === '') {
            $productName = trim((string) ($item['fabric_name_snapshot'] ?? 'Product'));
        }
        $unitType = in_array((string) ($item['unit_type'] ?? ''), ['meter', 'piece', 'set'], true) ? (string) $item['unit_type'] : 'meter';
        $maxQuantity = (($item['quantity'] ?? 0) > 0) ? (float) $item['quantity'] : (float) ($item['quantity_meters'] ?? 0);
        $requestedRaw = $returnQtyMap[(string) $orderItemId] ?? 0;
        $quantity = is_numeric($requestedRaw) ? (float) $requestedRaw : 0.0;
        if ($unitType === 'meter') {
            $quantity = round($quantity, 2);
        } else {
            $quantity = (float) max(0, (int) round($quantity));
        }
        if ($quantity <= 0) {
            continue;
        }
        if ($quantity > $maxQuantity) {
            $quantity = $maxQuantity;
        }
        $lineTotal = (($item['total'] ?? 0) > 0) ? (float) $item['total'] : (float) ($item['line_total'] ?? 0);
        $lineTotal = round(($maxQuantity > 0) ? (($lineTotal * $quantity) / $maxQuantity) : 0.0, 2);
        $insertItem->bind_param('iiiissdd', $returnId, $orderItemId, $fabricId, $variantId, $productName, $unitType, $quantity, $lineTotal);
        $insertItem->execute();
        $selectedCount++;
    }
    if ($selectedCount <= 0) {
        throw new RuntimeException('Select at least one item quantity to return.');
    }

    $conn->commit();
    flash('success', 'Return request submitted successfully.');
} catch (Throwable $e) {
    try {
        $conn->rollback();
    } catch (Throwable $rollbackException) {
    }
    if (!empty($saved)) {
        foreach ($saved as $path) {
            UploadPolicy::deleteStoredFile(dirname(__DIR__) . '/images/returns', basename((string) $path));
        }
    }
    error_log('[return-request] submit failed: ' . $e->getMessage());
    flash('error', 'Unable to submit return request right now. Please check your details and try again.');
}

redirect($returnUrl);
