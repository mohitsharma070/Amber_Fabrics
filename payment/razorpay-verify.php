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
        "SELECT id, payment_status, order_status, order_notes, total_amount
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
        "SELECT id, razorpay_order_id FROM payments WHERE order_id = ? AND payment_method = 'razorpay' LIMIT 1"
    );
    $payRowStmt->bind_param('i', $orderId);
    $payRowStmt->execute();
    $payRow = $payRowStmt->get_result()->fetch_assoc();

    $paymentRowId = (int) ($payRow['id'] ?? 0);
    if (!$payRow || (string) $payRow['razorpay_order_id'] !== $rzpOrderId) {
        payment_attempt_touch(
            $conn,
            'razorpay',
            $rzpOrderId,
            $orderId,
            $paymentRowId,
            'verify_rejected',
            'verify',
            $paymentId,
            $signature,
            'order_id_mismatch',
            'Razorpay order ID mismatch during verify',
            '',
            '',
            '',
            false
        );
        throw new RuntimeException('Razorpay order ID mismatch — possible tampering attempt.');
    }

    $remoteValidation = razorpay_validate_remote_capture(
        $paymentId,
        $rzpOrderId,
        (float) ($order['total_amount'] ?? 0)
    );
    if (empty($remoteValidation['ok'])) {
        payment_attempt_touch(
            $conn,
            'razorpay',
            $rzpOrderId,
            $orderId,
            $paymentRowId,
            'verify_rejected',
            'verify',
            $paymentId,
            $signature,
            'gateway_validation_failed',
            (string) ($remoteValidation['error'] ?? 'unknown'),
            '',
            '',
            '',
            false
        );
        throw new RuntimeException('Razorpay gateway validation failed.');
    }

    if (($order['payment_status'] ?? '') === 'paid') {
        $conn->commit();
        unset($_SESSION['pending_order_id'], $_SESSION['pending_order_number'], $_SESSION['pending_coupon_id'], $_SESSION['pending_online_method']);
        unset($_SESSION['cart'], $_SESSION['cart_size'], $_SESSION['cart_meter_length'], $_SESSION['checkout_old'], $_SESSION['checkout_errors'], $_SESSION['applied_coupon_code']);
        if ($customerId > 0) {
            cart_clear_db($conn, $customerId);
        }
        redirect('/order-success.php?order=' . urlencode($orderNumber));
    }

    razorpay_mark_order_paid(
        $conn,
        $orderId,
        (string) ($order['payment_status'] ?? ''),
        $paymentId,
        $rzpOrderId,
        $signature
    );
    payment_attempt_touch(
        $conn,
        'razorpay',
        $rzpOrderId,
        $orderId,
        $paymentRowId,
        'verify_captured',
        'verify',
        $paymentId,
        $signature,
        '',
        '',
        '',
        '',
        '',
        false
    );

    log_order_activity(
        $conn,
        $orderId,
        'payment_captured',
        'customer',
        $customerId,
        'customer',
        'Razorpay payment id: ' . $paymentId
    );

    consume_coupon_after_razorpay_capture(
        $conn,
        $orderId,
        $customerId,
        (int) ($_SESSION['pending_coupon_id'] ?? 0),
        (string) ($order['order_notes'] ?? '')
    );

    $conn->commit();

    $awbResult = shiprocket_auto_create_awb_for_order($conn, $orderId);
    if (empty($awbResult['ok'])) {
        log_order_activity(
            $conn,
            $orderId,
            'shipment_manual_fallback',
            'system',
            0,
            'shiprocket',
            (string) ($awbResult['reason'] ?? 'Auto AWB failed')
        );
    }

    unset($_SESSION['pending_order_id'], $_SESSION['pending_order_number'], $_SESSION['pending_coupon_id'], $_SESSION['pending_online_method']);
    unset($_SESSION['cart'], $_SESSION['cart_size'], $_SESSION['cart_meter_length'], $_SESSION['checkout_old'], $_SESSION['checkout_errors'], $_SESSION['applied_coupon_code']);
    if ($customerId > 0) {
        cart_clear_db($conn, $customerId);
    }

    do_action('order.after_payment_success', [
        'conn' => $conn,
        'order_id' => $orderId,
        'order_number' => $orderNumber,
        'customer_id' => $customerId,
        'payment_method' => 'razorpay',
        'payment_status' => 'paid',
    ]);

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
