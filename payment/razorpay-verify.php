<?php
require_once __DIR__ . '/../includes/init.php';
require_once __DIR__ . '/../includes/customer-auth.php';
require_once __DIR__ . '/../includes/coupon-functions.php';

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
require_order_access($conn, $orderId);

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
    $orderStmt = $conn->prepare(
        "SELECT id, payment_status, order_status, order_notes, total_amount
         FROM orders
         WHERE id = ? AND payment_method = 'razorpay'
         LIMIT 1"
    );
    $orderStmt->bind_param('i', $orderId);
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
    $payRowStmt = $conn->prepare("SELECT id, razorpay_order_id FROM payments WHERE order_id = ? AND payment_method = 'razorpay' LIMIT 1");
    $payRowStmt->bind_param('i', $orderId);
    $payRowStmt->execute();
    $payRow = $payRowStmt->get_result()->fetch_assoc();

    $paymentRowId = (int) ($payRow['id'] ?? 0);
    if (!$payRow || (string) $payRow['razorpay_order_id'] !== $rzpOrderId) {
        PaymentService::payment_attempt_touch(
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
        throw new RuntimeException('Razorpay order ID mismatch - possible tampering attempt.');
    }

    if (($order['payment_status'] ?? '') === 'paid') {
        CartService::checkout_session_clear_after_order($conn, $customerId);
        redirect('/order-success.php?order=' . urlencode($orderNumber));
    }

    $remoteValidation = ['ok' => false, 'error' => 'validation_not_attempted'];
    for ($attempt = 1; $attempt <= 3; $attempt++) {
        $remoteValidation = PaymentService::razorpay_validate_remote_capture(
            $paymentId,
            $rzpOrderId,
            (float) ($order['total_amount'] ?? 0)
        );
        if (!empty($remoteValidation['ok'])) {
            break;
        }

        $validationError = (string) ($remoteValidation['error'] ?? '');
        $isTransientValidationError =
            $validationError === 'gateway_not_captured' ||
            strpos($validationError, 'curl_error:') === 0 ||
            preg_match('/^gateway_http_5\d{2}$/', $validationError) === 1;
        if (!$isTransientValidationError || $attempt === 3) {
            break;
        }

        usleep(750000);
    }
    if (!empty($remoteValidation['ok'])) {
        error_log('[razorpay-verify] provider validation success order_id=' . $orderId . ' payment_id=' . $paymentId);
    } else {
        error_log('[razorpay-verify] provider validation failed order_id=' . $orderId . ' payment_id=' . $paymentId . ' error=' . (string) ($remoteValidation['error'] ?? 'unknown'));
    }

    $conn->begin_transaction();
    $orderLockStmt = $conn->prepare(
        "SELECT id, payment_status, order_status, order_notes
         FROM orders
         WHERE id = ? AND payment_method = 'razorpay'
         LIMIT 1 FOR UPDATE"
    );
    $orderLockStmt->bind_param('i', $orderId);
    $orderLockStmt->execute();
    $lockedOrder = $orderLockStmt->get_result()->fetch_assoc();
    if (!$lockedOrder) {
        throw new RuntimeException('Order not found during verification finalize.');
    }

    $payLockStmt = $conn->prepare(
        "SELECT id, razorpay_order_id
         FROM payments
         WHERE order_id = ? AND payment_method = 'razorpay'
         LIMIT 1 FOR UPDATE"
    );
    $payLockStmt->bind_param('i', $orderId);
    $payLockStmt->execute();
    $lockedPayment = $payLockStmt->get_result()->fetch_assoc();
    $paymentRowId = (int) ($lockedPayment['id'] ?? $paymentRowId);
    if (!$lockedPayment || trim((string) ($lockedPayment['razorpay_order_id'] ?? '')) !== $rzpOrderId) {
        throw new RuntimeException('Razorpay order ID mismatch during finalize.');
    }
    if (($lockedOrder['payment_status'] ?? '') === 'paid') {
        $conn->commit();
        CartService::checkout_session_clear_after_order($conn, $customerId);
        redirect('/order-success.php?order=' . urlencode($orderNumber));
    }
    if (!in_array((string) ($lockedOrder['order_status'] ?? ''), ['pending', 'confirmed'], true)) {
        throw new RuntimeException('Order is not eligible for payment finalize (status: ' . ($lockedOrder['order_status'] ?? 'unknown') . ').');
    }

    if (empty($remoteValidation['ok'])) {
        PaymentService::payment_attempt_touch(
            $conn,
            'razorpay',
            $rzpOrderId,
            $orderId,
            $paymentRowId,
            'verify_deferred',
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
        log_order_activity(
            $conn,
            $orderId,
            'payment_validation_deferred',
            'system',
            0,
            'razorpay',
            'Gateway re-validation failed during verify callback; order was not marked paid. Reason: ' . (string) ($remoteValidation['error'] ?? 'unknown')
        );

        $conn->commit();
        flash('warning', 'Payment is being verified by the gateway. If money was debited, your order will update automatically after webhook confirmation.');
        redirect(order_access_landing_url($orderNumber));
    }

    PaymentService::razorpay_mark_order_paid(
        $conn,
        $orderId,
        (string) ($lockedOrder['payment_status'] ?? ''),
        $paymentId,
        $rzpOrderId,
        $signature
    );
    PaymentService::payment_attempt_touch(
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

    $conn->commit();

    // Capture is authoritative. Coupon reconciliation is intentionally outside that transaction:
    // an accounting fault is visible to admins but can never revert a captured payment to unpaid.
    PaymentService::reconcile_coupon_after_razorpay_capture(
        $conn, $orderId, $customerId, (int) ($_SESSION['pending_coupon_id'] ?? 0), (string) ($lockedOrder['order_notes'] ?? '')
    );

    CartService::checkout_session_clear_after_order($conn, $customerId);

    do_action('order.after_payment_success', [
        'conn' => $conn,
        'order_id' => $orderId,
        'order_number' => $orderNumber,
        'customer_id' => $customerId,
        'payment_method' => 'razorpay',
        'payment_status' => 'paid',
    ]);

    EmailService::send_order_confirmation_email($conn, $orderId);
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
