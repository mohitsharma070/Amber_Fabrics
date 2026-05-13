<?php
require_once __DIR__ . '/../includes/init.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo 'Method Not Allowed';
    exit;
}

$webhookSecret = _cfg('RAZORPAY_WEBHOOK_SECRET', '');
if ($webhookSecret === '') {
    error_log('[razorpay-webhook] missing RAZORPAY_WEBHOOK_SECRET');
    http_response_code(500);
    echo 'Webhook secret missing';
    exit;
}

$signature = (string) ($_SERVER['HTTP_X_RAZORPAY_SIGNATURE'] ?? '');
$payload = file_get_contents('php://input');
if ($payload === false || $payload === '') {
    http_response_code(400);
    echo 'Empty payload';
    exit;
}
if ($signature === '') {
    http_response_code(400);
    echo 'Missing signature';
    exit;
}

$expected = hash_hmac('sha256', $payload, $webhookSecret);
if (!hash_equals($expected, $signature)) {
    error_log('[razorpay-webhook] signature mismatch');
    http_response_code(400);
    echo 'Invalid signature';
    exit;
}

$event = json_decode($payload, true);
if (!is_array($event)) {
    http_response_code(400);
    echo 'Invalid payload';
    exit;
}

$eventType = (string) ($event['event'] ?? '');
if (!in_array($eventType, ['payment.captured', 'order.paid', 'payment.failed'], true)) {
    http_response_code(200);
    echo 'Ignored';
    exit;
}

$rzpOrderId = '';
$paymentId = '';
$errorCode = '';
$errorDescription = '';
if ($eventType === 'payment.captured') {
    $rzpOrderId = trim((string) ($event['payload']['payment']['entity']['order_id'] ?? ''));
    $paymentId = trim((string) ($event['payload']['payment']['entity']['id'] ?? ''));
} elseif ($eventType === 'payment.failed') {
    $rzpOrderId = trim((string) ($event['payload']['payment']['entity']['order_id'] ?? ''));
    $paymentId = trim((string) ($event['payload']['payment']['entity']['id'] ?? ''));
    $errorCode = trim((string) ($event['payload']['payment']['entity']['error_code'] ?? ''));
    $errorDescription = trim((string) ($event['payload']['payment']['entity']['error_description'] ?? ''));
} else {
    $rzpOrderId = trim((string) ($event['payload']['order']['entity']['id'] ?? ''));
    $paymentId = trim((string) ($event['payload']['payment']['entity']['id'] ?? ''));
}

if ($rzpOrderId === '') {
    http_response_code(400);
    echo 'Missing order id';
    exit;
}

if ($eventType === 'payment.failed') {
    try {
        $conn->begin_transaction();

        $paymentStmt = $conn->prepare(
            "SELECT order_id
             FROM payments
             WHERE payment_method = 'razorpay' AND razorpay_order_id = ?
             LIMIT 1"
        );
        $paymentStmt->bind_param('s', $rzpOrderId);
        $paymentStmt->execute();
        $paymentRow = $paymentStmt->get_result()->fetch_assoc();

        if (!$paymentRow) {
            $conn->commit();
            http_response_code(200);
            echo 'Ignored';
            exit;
        }

        $orderId = (int) ($paymentRow['order_id'] ?? 0);
        if ($orderId <= 0) {
            throw new RuntimeException('Invalid order id mapped to razorpay_order_id=' . $rzpOrderId);
        }

        $orderStmt = $conn->prepare(
            "SELECT id, payment_status, notes
             FROM orders
             WHERE id = ? AND payment_method = 'razorpay'
             FOR UPDATE"
        );
        $orderStmt->bind_param('i', $orderId);
        $orderStmt->execute();
        $order = $orderStmt->get_result()->fetch_assoc();
        if (!$order) {
            throw new RuntimeException('Order not found for order_id=' . $orderId);
        }

        if (strtolower((string) ($order['payment_status'] ?? '')) !== 'paid') {
            $updatePayment = $conn->prepare(
                "UPDATE payments
                 SET payment_status = 'failed',
                     transaction_id = CASE WHEN ? <> '' THEN ? ELSE transaction_id END,
                     razorpay_payment_id = CASE WHEN ? <> '' THEN ? ELSE razorpay_payment_id END
                 WHERE order_id = ? AND payment_method = 'razorpay'"
            );
            $updatePayment->bind_param('ssssi', $paymentId, $paymentId, $paymentId, $paymentId, $orderId);
            $updatePayment->execute();

            $parts = ['Razorpay payment failed (webhook)'];
            if ($errorCode !== '') {
                $parts[] = 'code: ' . $errorCode;
            }
            if ($errorDescription !== '') {
                $parts[] = 'reason: ' . $errorDescription;
            }
            $note = implode(' | ', $parts);
            $updateOrder = $conn->prepare(
                "UPDATE orders
                 SET payment_status = 'failed',
                     notes = CASE WHEN notes IS NULL OR notes = '' THEN ? ELSE CONCAT(notes, '\n', ?) END,
                     updated_at = NOW()
                 WHERE id = ?"
            );
            $updateOrder->bind_param('ssi', $note, $note, $orderId);
            $updateOrder->execute();
        }

        $conn->commit();
        http_response_code(200);
        echo 'OK';
    } catch (Throwable $e) {
        try {
            $conn->rollback();
        } catch (Throwable $rollbackException) {
            // ignore rollback errors
        }
        error_log('[razorpay-webhook] payment.failed handler failed: ' . $e->getMessage());
        http_response_code(500);
        echo 'Error';
    }
    exit;
}

function webhook_increment_coupon_used_count(mysqli $conn, int $customerId, int $orderId, string $orderNotes): void
{
    if (!preg_match('/Coupon Applied:\s*([A-Z0-9_-]+)/i', $orderNotes, $m)) {
        return;
    }
    $code = strtoupper(trim((string) ($m[1] ?? '')));
    if ($code === '') {
        return;
    }
    $couponIdStmt = $conn->prepare("SELECT id FROM coupons WHERE code = ? LIMIT 1");
    $couponIdStmt->bind_param('s', $code);
    $couponIdStmt->execute();
    $couponRow = $couponIdStmt->get_result()->fetch_assoc();
    $couponId = (int) ($couponRow['id'] ?? 0);
    if ($couponId <= 0) {
        return;
    }
    if (has_customer_used_coupon($conn, $couponId, $customerId)) {
        throw new RuntimeException('Coupon already used by this customer.');
    }

    $stmt = $conn->prepare(
        "UPDATE coupons SET used_count = used_count + 1
         WHERE id = ? AND (usage_limit = 0 OR used_count < usage_limit)"
    );
    $stmt->bind_param('i', $couponId);
    $stmt->execute();
    if ($conn->affected_rows <= 0) {
        throw new RuntimeException('Coupon usage limit reached.');
    }
    mark_coupon_used_once($conn, $couponId, $customerId, $orderId);
}

try {
    $conn->begin_transaction();

    $paymentStmt = $conn->prepare(
        "SELECT order_id, payment_status
         FROM payments
         WHERE payment_method = 'razorpay' AND razorpay_order_id = ?
         LIMIT 1"
    );
    $paymentStmt->bind_param('s', $rzpOrderId);
    $paymentStmt->execute();
    $paymentRow = $paymentStmt->get_result()->fetch_assoc();
    if (!$paymentRow) {
        throw new RuntimeException('Payment row not found for razorpay_order_id=' . $rzpOrderId);
    }

    $orderId = (int) ($paymentRow['order_id'] ?? 0);
    if ($orderId <= 0) {
        throw new RuntimeException('Invalid order id mapped to razorpay_order_id=' . $rzpOrderId);
    }

    $orderStmt = $conn->prepare(
        "SELECT id, customer_id, order_number, order_status, payment_status, order_notes
         FROM orders
         WHERE id = ? AND payment_method = 'razorpay'
         FOR UPDATE"
    );
    $orderStmt->bind_param('i', $orderId);
    $orderStmt->execute();
    $order = $orderStmt->get_result()->fetch_assoc();
    if (!$order) {
        throw new RuntimeException('Order not found for order_id=' . $orderId);
    }

    if (($order['payment_status'] ?? '') === 'paid') {
        $conn->commit();
        http_response_code(200);
        echo 'Already processed';
        exit;
    }

    if (!in_array((string) ($order['order_status'] ?? ''), ['pending', 'confirmed'], true)) {
        throw new RuntimeException('Order not in payable state for order_id=' . $orderId);
    }

    $itemsStmt = $conn->prepare(
        "SELECT fabric_id, unit_type, quantity_meters
         FROM order_items
         WHERE order_id = ?"
    );
    $itemsStmt->bind_param('i', $orderId);
    $itemsStmt->execute();
    $items = $itemsStmt->get_result()->fetch_all(MYSQLI_ASSOC);
    if (empty($items)) {
        throw new RuntimeException('No order items for order_id=' . $orderId);
    }

    $stockCheckStmt = $conn->prepare(
        "SELECT id, stock, stock_meters
         FROM fabrics
         WHERE id = ?
         FOR UPDATE"
    );

    foreach ($items as $item) {
        $fabricId = (int) ($item['fabric_id'] ?? 0);
        if ($fabricId <= 0) {
            throw new RuntimeException('Invalid fabric_id in order item');
        }
        $itemUnit = in_array((string) ($item['unit_type'] ?? ''), ['meter', 'piece', 'set'], true)
            ? (string) $item['unit_type']
            : 'meter';
        $qty = normalize_quantity_by_unit($item['quantity_meters'] ?? 1, $itemUnit);

        $stockCheckStmt->bind_param('i', $fabricId);
        $stockCheckStmt->execute();
        $fabric = $stockCheckStmt->get_result()->fetch_assoc();
        if (!$fabric) {
            throw new RuntimeException('Fabric not found for stock update');
        }

        $useMeters = $itemUnit === 'meter';
        $availableStock = $useMeters ? (float) ($fabric['stock_meters'] ?? 0) : (float) ($fabric['stock'] ?? 0);
        if ($availableStock < $qty) {
            throw new RuntimeException('Insufficient stock during webhook confirmation.');
        }

        if ($useMeters) {
            $stockUpdateStmt = $conn->prepare(
                "UPDATE fabrics SET stock_meters = stock_meters - ? WHERE id = ? AND stock_meters >= ?"
            );
            $stockUpdateStmt->bind_param('did', $qty, $fabricId, $qty);
        } else {
            $deductQty = round((float) $qty, 2);
            $stockUpdateStmt = $conn->prepare(
                "UPDATE fabrics SET stock = stock - ? WHERE id = ? AND stock >= ?"
            );
            $stockUpdateStmt->bind_param('did', $deductQty, $fabricId, $deductQty);
        }
        $stockUpdateStmt->execute();
        if ($conn->affected_rows === 0) {
            throw new RuntimeException('Stock update conflict for fabric ' . $fabricId);
        }
    }

    $updateOrder = $conn->prepare(
        "UPDATE orders
         SET payment_id = ?, payment_status = 'paid', order_status = 'confirmed', status = 'confirmed'
         WHERE id = ?"
    );
    $updateOrder->bind_param('si', $paymentId, $orderId);
    $updateOrder->execute();

    $updatePayment = $conn->prepare(
        "UPDATE payments
         SET payment_status = 'paid',
             transaction_id = CASE WHEN ? <> '' THEN ? ELSE transaction_id END,
             razorpay_payment_id = CASE WHEN ? <> '' THEN ? ELSE razorpay_payment_id END,
             razorpay_signature = ?
         WHERE order_id = ? AND payment_method = 'razorpay'"
    );
    $updatePayment->bind_param('sssssi', $paymentId, $paymentId, $paymentId, $paymentId, $signature, $orderId);
    $updatePayment->execute();

    webhook_increment_coupon_used_count(
        $conn,
        (int) ($order['customer_id'] ?? 0),
        $orderId,
        (string) ($order['order_notes'] ?? '')
    );

    $conn->commit();

    send_order_confirmation_email($conn, $orderId);
    http_response_code(200);
    echo 'OK';
} catch (Throwable $e) {
    try {
        $conn->rollback();
    } catch (Throwable $rollbackException) {
        // ignore rollback errors
    }
    error_log('[razorpay-webhook] failed: ' . $e->getMessage());
    http_response_code(500);
    echo 'Error';
}
