<?php
require_once __DIR__ . '/../includes/init.php';
require_once __DIR__ . '/../includes/customer-auth.php';
require_once __DIR__ . '/../includes/coupon-functions.php';

require_customer();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('/checkout.php');
}
if (!verify_csrf()) {
    flash('error', 'Invalid payment verification request.');
    redirect('/checkout.php');
}
if (empty($_SESSION['pending_order_id'])) {
    flash('error', 'No pending order found for verification.');
    redirect('/checkout.php');
}

$orderId = (int) $_SESSION['pending_order_id'];
$orderNumber = (string) ($_SESSION['pending_order_number'] ?? '');
$customerId = (int) ($_SESSION['customer_id'] ?? 0);
$paymentId = trim((string) ($_POST['razorpay_payment_id'] ?? ''));
$rzpOrderId = trim((string) ($_POST['razorpay_order_id'] ?? ''));
$signature = trim((string) ($_POST['razorpay_signature'] ?? ''));

if ($paymentId === '' || $rzpOrderId === '' || $signature === '') {
    flash('error', 'Payment verification failed.');
    redirect('/checkout.php');
}

$keySecret = _cfg('RAZORPAY_KEY_SECRET', '');
if ($keySecret === '') {
    flash('error', 'Razorpay verification configuration missing.');
    redirect('/checkout.php');
}

$expected = hash_hmac('sha256', $rzpOrderId . '|' . $paymentId, $keySecret);
if (!hash_equals($expected, $signature)) {
    error_log('[razorpay] signature mismatch for order_id=' . $orderId);
    flash('error', 'Payment signature verification failed.');
    redirect('/checkout.php');
}

try {
    $conn->begin_transaction();

    $orderStmt = $conn->prepare(
        "SELECT id, payment_status, order_status, order_notes
         FROM orders
         WHERE id = ? AND customer_id = ? AND payment_method = 'razorpay'
         FOR UPDATE"
    );
    $orderStmt->bind_param('ii', $orderId, $customerId);
    $orderStmt->execute();
    $order = $orderStmt->get_result()->fetch_assoc();

    if (!$order) {
        throw new RuntimeException('Order not found for razorpay verification.');
    }

    // Reject payment for orders that are not in a payable state
    if (!in_array((string) ($order['order_status'] ?? ''), ['pending', 'confirmed'], true)) {
        throw new RuntimeException('Order is not eligible for payment (status: ' . ($order['order_status'] ?? 'unknown') . ').');
    }

    // Cross-check: the razorpay_order_id submitted by the client must match
    // the one we created server-side and stored in our payments table.
    $payRowStmt = $conn->prepare(
        "SELECT razorpay_order_id FROM payments WHERE order_id = ? AND payment_method = 'razorpay' LIMIT 1"
    );
    $payRowStmt->bind_param('i', $orderId);
    $payRowStmt->execute();
    $payRow = $payRowStmt->get_result()->fetch_assoc();

    if (!$payRow || (string) $payRow['razorpay_order_id'] !== $rzpOrderId) {
        throw new RuntimeException('Razorpay order ID mismatch — possible tampering attempt.');
    }

    if (($order['payment_status'] ?? '') === 'paid') {
        $conn->commit();
        unset($_SESSION['pending_order_id'], $_SESSION['pending_order_number'], $_SESSION['pending_coupon_id'], $_SESSION['pending_online_method']);
        unset($_SESSION['cart'], $_SESSION['cart_size'], $_SESSION['checkout_old'], $_SESSION['checkout_errors'], $_SESSION['applied_coupon_code']);
        redirect('/order-success.php?order=' . urlencode($orderNumber));
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
        throw new RuntimeException('No order items found for stock update.');
    }

    $stockCheckStmt = $conn->prepare(
        "SELECT id, name, stock, stock_meters
         FROM fabrics
         WHERE id = ?
         FOR UPDATE"
    );

    foreach ($items as $item) {
        $fabricId = (int) ($item['fabric_id'] ?? 0);
        $itemUnit = in_array((string) ($item['unit_type'] ?? ''), ['meter', 'piece', 'set'], true)
            ? (string) $item['unit_type']
            : 'meter';
        $qty = normalize_quantity_by_unit($item['quantity_meters'] ?? 1, $itemUnit);
        if ($fabricId <= 0) {
            throw new RuntimeException('Invalid fabric in order item.');
        }

        $stockCheckStmt->bind_param('i', $fabricId);
        $stockCheckStmt->execute();
        $fabric = $stockCheckStmt->get_result()->fetch_assoc();
        if (!$fabric) {
            throw new RuntimeException('Fabric not found for stock update.');
        }

        // Only decrement the column that actually has stock to prevent double-deduction
        $useMeters = $itemUnit === 'meter';
        $availableStock = $useMeters ? (float) ($fabric['stock_meters'] ?? 0) : (float) $fabric['stock'];
        if ($availableStock < $qty) {
            throw new RuntimeException('Insufficient stock during payment confirmation.');
        }

        if ($useMeters) {
            $stockUpdateStmt = $conn->prepare(
                "UPDATE fabrics SET stock_meters = stock_meters - ? WHERE id = ? AND stock_meters >= ?"
            );
            $stockUpdateStmt->bind_param('did', $qty, $fabricId, $qty);
        } else {
            $stockUpdateStmt = $conn->prepare(
                "UPDATE fabrics SET stock = stock - ? WHERE id = ? AND stock >= ?"
            );
            $deductQty = round((float) $qty, 2);
            $stockUpdateStmt->bind_param('did', $deductQty, $fabricId, $deductQty);
        }
        $stockUpdateStmt->execute();
        if ($conn->affected_rows === 0) {
            throw new RuntimeException('Stock update conflict for fabric ' . $fabricId . '. Please try again.');
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
         SET payment_status = 'paid', transaction_id = ?, razorpay_order_id = ?, razorpay_payment_id = ?, razorpay_signature = ?
         WHERE order_id = ? AND payment_method = 'razorpay'"
    );
    $updatePayment->bind_param('ssssi', $paymentId, $rzpOrderId, $paymentId, $signature, $orderId);
    $updatePayment->execute();

    $pendingCouponId = (int) ($_SESSION['pending_coupon_id'] ?? 0);
    $resolvedCouponId = $pendingCouponId;
    if ($resolvedCouponId <= 0) {
        $orderNotes = (string) ($order['order_notes'] ?? '');
        if ($orderNotes !== '' && preg_match('/Coupon Applied:\s*([A-Z0-9_-]+)/i', $orderNotes, $m)) {
            $couponCode = strtoupper(trim((string) ($m[1] ?? '')));
            if ($couponCode !== '') {
                $couponIdStmt = $conn->prepare("SELECT id FROM coupons WHERE code = ? LIMIT 1");
                $couponIdStmt->bind_param('s', $couponCode);
                $couponIdStmt->execute();
                $couponRow = $couponIdStmt->get_result()->fetch_assoc();
                $resolvedCouponId = (int) ($couponRow['id'] ?? 0);
            }
        }
    }

    $couponUpdated = false;
    if ($resolvedCouponId > 0) {
        if (has_customer_used_coupon($conn, $resolvedCouponId, $customerId)) {
            throw new RuntimeException('Coupon already used by this customer.');
        }
        // Only increment if the coupon still has capacity (usage_limit = 0 means unlimited)
        $couponStmt = $conn->prepare(
            "UPDATE coupons SET used_count = used_count + 1
             WHERE id = ? AND (usage_limit = 0 OR used_count < usage_limit)"
        );
        $couponStmt->bind_param('i', $resolvedCouponId);
        $couponStmt->execute();
        if ($conn->affected_rows > 0) {
            mark_coupon_used_once($conn, $resolvedCouponId, $customerId, $orderId);
            $couponUpdated = true;
        }
    }

    if ($resolvedCouponId > 0 && !$couponUpdated) {
        throw new RuntimeException('Coupon usage limit reached.');
    }

    $conn->commit();

    unset($_SESSION['pending_order_id'], $_SESSION['pending_order_number'], $_SESSION['pending_coupon_id'], $_SESSION['pending_online_method']);
    unset($_SESSION['cart'], $_SESSION['cart_size'], $_SESSION['checkout_old'], $_SESSION['checkout_errors'], $_SESSION['applied_coupon_code']);
    if ($customerId > 0) {
        cart_clear_db($conn, $customerId);
    }

    send_order_confirmation_email($conn, $orderId);
    redirect('/order-success.php?order=' . urlencode($orderNumber));
} catch (Throwable $e) {
    try {
        $conn->rollback();
    } catch (Throwable $rollbackException) {
        // ignore rollback errors
    }

    error_log('[razorpay] verify failed: ' . $e->getMessage());
    flash('error', 'Payment verification failed. If money was debited, contact support with your order number.');
    redirect('/checkout.php');
}
