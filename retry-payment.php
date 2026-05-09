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

// Only allow retry for the customer's own order that is payment-pending or failed
// and was placed within the last 30 minutes.
$stmt = $conn->prepare(
    "SELECT id, order_number, payment_method
     FROM orders
     WHERE id = ?
       AND customer_id = ?
             AND payment_status IN ('pending', 'failed')
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

$_SESSION['pending_order_id']     = $order['id'];
$_SESSION['pending_order_number'] = $order['order_number'];

if ($order['payment_method'] === 'razorpay') {
    redirect('/payment/razorpay-create.php');
} else {
    flash('error', 'Retry is not supported for this payment method.');
    redirect('/customer/orders.php');
}
