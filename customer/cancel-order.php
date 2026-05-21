<?php
require_once __DIR__ . '/../includes/init.php';
require_once __DIR__ . '/../includes/customer-auth.php';
require_once __DIR__ . '/../includes/coupon-functions.php';

require_customer();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('/customer/orders.php');
}
if (!verify_csrf()) {
    flash('error', 'Invalid session token. Please try again.');
    redirect('/customer/orders.php');
}

$orderId = (int) ($_POST['order_id'] ?? 0);
$customerId = (int) ($_SESSION['customer_id'] ?? 0);

if ($orderId <= 0 || $customerId <= 0) {
    flash('error', 'Invalid order request.');
    redirect('/customer/orders.php');
}

try {
    $result = customer_cancel_order($conn, $orderId, $customerId);
    $paymentStatus = (string) ($result['payment_status'] ?? 'pending');

    if ($paymentStatus === 'paid') {
        flash('success', 'Order cancelled. Refund process has been started.');
    } else {
        flash('success', 'Order cancelled successfully.');
    }
} catch (Throwable $e) {
    try {
        $conn->rollback();
    } catch (Throwable $rollbackException) {
        // ignore
    }
    flash('error', $e->getMessage() !== '' ? $e->getMessage() : 'Unable to cancel order right now.');
}

redirect('/customer/orders.php');
