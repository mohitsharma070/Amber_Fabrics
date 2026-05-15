<?php
require_once __DIR__ . '/../includes/init.php';
require_once __DIR__ . '/../includes/customer-auth.php';

require_customer();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('/customer/orders.php');
}
if (!verify_csrf()) {
    flash('error', 'Invalid session token. Please try again.');
    redirect('/customer/orders.php');
}

$customerId = (int) ($_SESSION['customer_id'] ?? 0);
$orderId = (int) ($_POST['order_id'] ?? 0);
$reason = trim((string) ($_POST['reason'] ?? ''));
$customerNote = trim((string) ($_POST['customer_note'] ?? ''));
$saved = [];

if ($customerId <= 0 || $orderId <= 0 || $reason === '') {
    flash('error', 'Please provide required return details.');
    redirect('/customer/order-view.php?id=' . $orderId);
}

$ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
if (!public_form_rate_limit_allow('return_request_' . $ip, 5, 900)) {
    flash('error', 'Too many return requests. Please wait a few minutes and try again.');
    redirect('/customer/order-view.php?id=' . $orderId);
}

try {
    $conn->begin_transaction();

    $orderStmt = $conn->prepare(
        "SELECT o.id, o.order_number, o.order_status, o.pincode, s.delivered_at
         FROM orders o
         LEFT JOIN shipments s ON s.order_id = o.id
         WHERE o.id = ? AND o.customer_id = ?
         FOR UPDATE"
    );
    $orderStmt->bind_param('ii', $orderId, $customerId);
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
    if (strtotime($deliveredAt) < strtotime('-7 days')) {
        throw new RuntimeException('Return window is closed. You can request return only within 7 days of delivery.');
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
    if (!is_dir($uploadDir)) {
        @mkdir($uploadDir, 0755, true);
    }
    if (!is_dir($uploadDir)) {
        throw new RuntimeException('Unable to create return image directory.');
    }

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
        $tmp = (string) ($_FILES[$field]['tmp_name'] ?? '');
        $originalName = (string) ($_FILES[$field]['name'] ?? '');
        $size = (int) ($_FILES[$field]['size'] ?? 0);
        if ($tmp === '' || $size <= 0 || $size > $maxBytes) {
            throw new RuntimeException('Each image must be valid and up to 5MB.');
        }
        $mime = '';
        if (function_exists('finfo_open')) {
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            if ($finfo !== false) {
                $mime = (string) (finfo_file($finfo, $tmp) ?: '');
                finfo_close($finfo);
            }
        }
        if ($mime === '' && function_exists('mime_content_type')) {
            $mime = (string) (mime_content_type($tmp) ?: '');
        }

        $detectedExt = strtolower((string) pathinfo($originalName, PATHINFO_EXTENSION));
        $extFromMime = $allowedMimes[strtolower($mime)] ?? '';
        $imageInfo = @getimagesize($tmp);
        if ($imageInfo === false) {
            throw new RuntimeException('Only valid image files are allowed.');
        }
        if ($extFromMime === '' || !in_array($detectedExt, $allowedExts, true)) {
            throw new RuntimeException('Only JPG, PNG, or WEBP images are allowed.');
        }

        // Use MIME-based extension for normalized storage.
        $targetExt = $extFromMime;
        if ($targetExt === '') {
            throw new RuntimeException('Only JPG, PNG, or WEBP images are allowed.');
        }

        $filename = 'return_' . date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.' . $targetExt;
        $target = $uploadDir . '/' . $filename;
        if (!move_uploaded_file($tmp, $target)) {
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
    $insertReturn->bind_param('siissss', $returnNumber, $orderId, $customerId, $reason, $customerNote, $img1, $img2);
    $insertReturn->execute();
    $returnId = (int) $conn->insert_id;
    $reverseRate = shiprocket_calculate_reverse_rate(
        trim((string) ($order['pincode'] ?? '')),
        trim(_cfg('SHIPROCKET_PICKUP_PINCODE', ''))
    );
    $reverseNote = '';
    if (!empty($reverseRate['ok'])) {
        $reverseNote = 'Live reverse option: ' . (string) ($reverseRate['courier_name'] ?? 'Courier')
            . ' | Est. cost: Rs ' . number_format((float) ($reverseRate['rate'] ?? 0), 2);
    } else {
        $reverseNote = 'Manual reverse fallback required: ' . (string) ($reverseRate['reason'] ?? 'Reverse API unavailable');
    }
    $noteStmt = $conn->prepare("UPDATE returns SET admin_note = ? WHERE id = ?");
    $noteStmt->bind_param('si', $reverseNote, $returnId);
    $noteStmt->execute();

    $itemStmt = $conn->prepare(
        "SELECT id, fabric_id, product_name, fabric_name_snapshot, unit_type, quantity, quantity_meters, total, line_total
         FROM order_items
         WHERE order_id = ?"
    );
    $itemStmt->bind_param('i', $orderId);
    $itemStmt->execute();
    $items = $itemStmt->get_result()->fetch_all(MYSQLI_ASSOC);

    $insertItem = $conn->prepare(
        "INSERT INTO return_items (return_id, order_item_id, fabric_id, product_name, unit_type, quantity, line_total)
         VALUES (?, ?, ?, ?, ?, ?, ?)"
    );
    foreach ($items as $item) {
        $orderItemId = (int) ($item['id'] ?? 0);
        $fabricId = (int) ($item['fabric_id'] ?? 0);
        $productName = trim((string) ($item['product_name'] ?? ''));
        if ($productName === '') {
            $productName = trim((string) ($item['fabric_name_snapshot'] ?? 'Product'));
        }
        $unitType = in_array((string) ($item['unit_type'] ?? ''), ['meter', 'piece', 'set'], true) ? (string) $item['unit_type'] : 'meter';
        $quantity = (($item['quantity'] ?? 0) > 0) ? (float) $item['quantity'] : (float) ($item['quantity_meters'] ?? 0);
        $lineTotal = (($item['total'] ?? 0) > 0) ? (float) $item['total'] : (float) ($item['line_total'] ?? 0);
        $insertItem->bind_param('iiissdd', $returnId, $orderItemId, $fabricId, $productName, $unitType, $quantity, $lineTotal);
        $insertItem->execute();
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
            $rel = ltrim((string) $path, '/\\');
            if ($rel !== '') {
                @unlink(dirname(__DIR__) . DIRECTORY_SEPARATOR . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $rel));
            }
        }
    }
    flash('error', $e->getMessage() !== '' ? $e->getMessage() : 'Unable to submit return request.');
}

redirect('/customer/order-view.php?id=' . $orderId);
