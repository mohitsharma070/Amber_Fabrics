<?php
require_once __DIR__ . '/includes/init.php';
require_once __DIR__ . '/includes/customer-auth.php';

require_customer();

if (!verify_csrf()) {
    flash('error', 'Invalid session. Please try again.');
    redirect('/customer/orders.php');
}

$orderId    = (int) ($_POST['order_id'] ?? 0);
$customerId = (int) $_SESSION['customer_id'];

if ($orderId <= 0) {
    flash('error', 'Invalid order.');
    redirect('/customer/orders.php');
}

// Only allow retry for the customer's own payable order (not cancelled/delivered/etc.)
// that is payment-pending/failed and placed within the last 30 minutes.
$stmt = $conn->prepare(
    "SELECT id, order_number, payment_method, order_status, order_notes
     FROM orders
     WHERE id = ?
       AND customer_id = ?
       AND payment_status IN ('pending', 'failed')
       AND order_status IN ('pending', 'confirmed')
       AND payment_method = 'razorpay'
       AND created_at >= (NOW() - INTERVAL 30 MINUTE)
     LIMIT 1"
);
$stmt->bind_param('ii', $orderId, $customerId);
$stmt->execute();
$order = $stmt->get_result()->fetch_assoc();

if (!$order) {
    flash('error', 'This order is no longer eligible for retry. Please place a new order.');
    redirect('/customer/orders.php');
}

// Normalize failed retry back to pending so the same order can continue payment.
$conn->begin_transaction();
try {
    $resetOrder = $conn->prepare(
        "UPDATE orders
         SET payment_status = 'pending',
             updated_at = NOW()
         WHERE id = ? AND customer_id = ?
           AND order_status IN ('pending', 'confirmed')"
    );
    $resetOrder->bind_param('ii', $orderId, $customerId);
    $resetOrder->execute();

    $resetPayment = $conn->prepare(
        "UPDATE payments
         SET payment_status = 'pending'
         WHERE order_id = ? AND payment_method = 'razorpay'"
    );
    $resetPayment->bind_param('i', $orderId);
    $resetPayment->execute();
    $conn->commit();
} catch (Throwable $e) {
    $conn->rollback();
    error_log('[retry-payment] failed to reset order/payment status: ' . $e->getMessage());
    flash('error', 'Unable to retry payment right now. Please try again.');
    redirect('/customer/orders.php');
}

$_SESSION['pending_order_id']     = $order['id'];
$_SESSION['pending_order_number'] = $order['order_number'];
$_SESSION['pending_coupon_id']    = 0;

$orderNotes = (string) ($order['order_notes'] ?? '');
if ($orderNotes !== '' && preg_match('/Coupon Applied:\s*([A-Z0-9_-]+)/i', $orderNotes, $m)) {
    $couponCode = strtoupper(trim((string) ($m[1] ?? '')));
    if ($couponCode !== '') {
        $couponStmt = $conn->prepare("SELECT id FROM coupons WHERE code = ? LIMIT 1");
        $couponStmt->bind_param('s', $couponCode);
        $couponStmt->execute();
        $coupon = $couponStmt->get_result()->fetch_assoc();
        if ($coupon && (int) ($coupon['id'] ?? 0) > 0) {
            $_SESSION['pending_coupon_id'] = (int) $coupon['id'];
        }
    }
}

if ($order['payment_method'] === 'razorpay') {
    redirect('/payment/razorpay-create.php');
} else {
    flash('error', 'Retry is not supported for this payment method.');
    redirect('/customer/orders.php');
}
