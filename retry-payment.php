<?php
require_once __DIR__ . '/includes/init.php';
require_once __DIR__ . '/includes/customer-auth.php';
require_once __DIR__ . '/includes/coupon-functions.php';

if (!verify_csrf()) {
    flash('error', 'Invalid session. Please try again.');
    redirect('/customer/orders.php');
}

$orderId    = (int) ($_POST['order_id'] ?? 0);
$customerId = (int) ($_SESSION['customer_id'] ?? 0);

if ($orderId <= 0) {
    flash('error', 'Invalid order.');
    redirect('/customer/orders.php');
}
require_order_access($conn, $orderId, '/index.php');

// Normalize failed retry back to pending so the same order can continue payment.
$conn->begin_transaction();
try {
    // Only allow retry for the customer's own payable order (not cancelled/delivered/etc.)
    // that is payment-pending/failed and placed within the last 30 minutes.
    $stmt = $conn->prepare(
        "SELECT id, order_number, payment_method, order_status, order_notes, coupon_id
         FROM orders
         WHERE id = ?
           AND payment_status IN ('pending', 'failed')
           AND order_status IN ('pending', 'confirmed')
           AND payment_method = 'razorpay'
           AND created_at >= (NOW() - INTERVAL 30 MINUTE)
         LIMIT 1
         FOR UPDATE"
    );
    $stmt->bind_param('i', $orderId);
    $stmt->execute();
    $order = $stmt->get_result()->fetch_assoc();
    if (!$order) {
        throw new RuntimeException('This order is no longer eligible for retry. Please place a new order.');
    }

    $resolvedCouponId = (int) ($order['coupon_id'] ?? 0);
    if ($resolvedCouponId <= 0) $resolvedCouponId = PaymentService::resolve_coupon_id_for_order($conn, $orderId, (string) ($order['order_notes'] ?? ''));
    if ($resolvedCouponId > 0) {
        // A failed/cancelled attempt releases capacity. Retry must atomically claim it again.
        reserve_coupon_for_order($conn, $resolvedCouponId, $customerId, $orderId);
    }

    InventoryService::reserve_order_inventory($conn, $orderId);

    $resetOrder = $conn->prepare(
        "UPDATE orders
         SET payment_status = 'pending',
             updated_at = NOW()
         WHERE id = ?
           AND order_status IN ('pending', 'confirmed')"
    );
    $resetOrder->bind_param('i', $orderId);
    $resetOrder->execute();

    $resetPayment = $conn->prepare(
        "UPDATE payments
         SET payment_status = 'pending'
         WHERE order_id = ? AND payment_method = 'razorpay'"
    );
    $resetPayment->bind_param('i', $orderId);
    $resetPayment->execute();
    log_order_activity($conn, $orderId, 'payment_retry_started', $customerId > 0 ? 'customer' : 'guest', $customerId, $customerId > 0 ? 'customer' : 'guest', 'Customer retried Razorpay payment.');
    $conn->commit();
} catch (Throwable $e) {
    $conn->rollback();
    error_log('[retry-payment] failed to reset order/payment status: ' . $e->getMessage());
    $msg = $e->getMessage();
    flash('error', $msg !== '' ? $msg : 'Unable to retry payment right now. Please try again.');
    redirect('/customer/orders.php');
}

$_SESSION['pending_order_id']     = $order['id'];
$_SESSION['pending_order_number'] = $order['order_number'];
$_SESSION['pending_coupon_id']    = $resolvedCouponId;

if ($order['payment_method'] === 'razorpay') {
    redirect('/payment/razorpay-create.php');
} else {
    flash('error', 'Retry is not supported for this payment method.');
    redirect('/customer/orders.php');
}
