<?php
require_once __DIR__ . '/../includes/init.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !verify_csrf()) {
    http_response_code(403);
    exit('Forbidden');
}

$orderId = (int) ($_POST['order_id'] ?? 0);
if (!OrderAccessService::canAccess($orderId)) {
    http_response_code(403);
    exit('Forbidden');
}

$conn->begin_transaction();
try {
    $stmt = $conn->prepare(
        "SELECT id, order_number, order_notes
         FROM orders
         WHERE id = ?
           AND payment_status IN ('pending', 'failed')
           AND order_status IN ('pending', 'confirmed')
           AND payment_method = 'razorpay'
           AND created_at >= (NOW() - INTERVAL 30 MINUTE)
         LIMIT 1 FOR UPDATE"
    );
    $stmt->bind_param('i', $orderId);
    $stmt->execute();
    $order = $stmt->get_result()->fetch_assoc();
    if (!$order) {
        throw new RuntimeException('This payment is no longer eligible for retry.');
    }

    InventoryService::reserve_order_inventory($conn, $orderId);
    $resolvedCouponId = PaymentService::resolve_coupon_id_for_order($conn, $orderId, (string) ($order['order_notes'] ?? ''));
    if ($resolvedCouponId > 0) {
        reserve_coupon_for_order($conn, $resolvedCouponId, 0, $orderId);
    }
    $stmt = $conn->prepare(
        "UPDATE orders SET payment_status = 'pending', updated_at = NOW()
         WHERE id = ? AND order_status IN ('pending', 'confirmed')"
    );
    $stmt->bind_param('i', $orderId);
    $stmt->execute();

    $stmt = $conn->prepare(
        "UPDATE payments SET payment_status = 'pending'
         WHERE order_id = ? AND payment_method = 'razorpay'"
    );
    $stmt->bind_param('i', $orderId);
    $stmt->execute();

    log_order_activity($conn, $orderId, 'payment_retry_started', 'guest', 0, 'guest', 'Guest retried Razorpay payment.');
    log_ecommerce_event($conn, 'payment_retry', null, $orderId, null, null, null, null, ['payment_method' => 'razorpay']);
    $conn->commit();
} catch (Throwable $e) {
    $conn->rollback();
    error_log('[guest-retry-payment] ' . $e->getMessage());
    flash('error', 'This payment is no longer eligible for retry.');
    redirect('/guest/order?id=' . $orderId);
}

$_SESSION['pending_order_id'] = $orderId;
$_SESSION['pending_order_number'] = (string) $order['order_number'];
$_SESSION['pending_coupon_id'] = $resolvedCouponId;
redirect('/payment/razorpay-create.php');
