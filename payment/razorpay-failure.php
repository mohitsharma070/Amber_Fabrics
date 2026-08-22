<?php
require_once __DIR__ . '/../includes/init.php';
if(!empty($_SESSION['pending_order_id'])){log_ecommerce_event($conn,'payment_failure',(int)($_SESSION['customer_id']??0)?:null,(int)$_SESSION['pending_order_id'],null,null,null,null,['payment_method'=>'razorpay']);}
require_once __DIR__ . '/../includes/security/customer-auth.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('/checkout.php');
}
if (!verify_csrf()) {
    flash('error', 'Invalid payment callback request.');
    redirect('/checkout.php');
}
if (empty($_SESSION['pending_order_id'])) {
    flash('error', 'No pending order found for payment update.');
    redirect('/checkout.php');
}

$orderId = (int) ($_SESSION['pending_order_id'] ?? 0);
$orderNumber = trim((string) ($_SESSION['pending_order_number'] ?? ''));
$customerId = (int) ($_SESSION['customer_id'] ?? 0);
$eventType = trim((string) ($_POST['event_type'] ?? 'failed'));
$paymentId = trim((string) ($_POST['razorpay_payment_id'] ?? ''));
$rzpOrderId = trim((string) ($_POST['razorpay_order_id'] ?? ''));
$errorCode = trim((string) ($_POST['error_code'] ?? ''));
$errorDescription = trim((string) ($_POST['error_description'] ?? ''));

if (!in_array($eventType, ['failed', 'cancelled'], true)) {
    $eventType = 'failed';
}

try {
    $conn->begin_transaction();

    if ($customerId > 0) {
        $orderStmt = $conn->prepare(
            "SELECT id, order_number, payment_status
             FROM orders
             WHERE id = ? AND customer_id = ? AND payment_method = 'razorpay'
             FOR UPDATE"
        );
        $orderStmt->bind_param('ii', $orderId, $customerId);
    } else {
        $orderStmt = $conn->prepare(
            "SELECT id, order_number, payment_status
             FROM orders
             WHERE id = ? AND order_number = ? AND payment_method = 'razorpay'
             FOR UPDATE"
        );
        $orderStmt->bind_param('is', $orderId, $orderNumber);
    }
    $orderStmt->execute();
    $order = $orderStmt->get_result()->fetch_assoc();

    if (!$order) {
        throw new RuntimeException('Order not found for payment failure callback.');
    }

    $payStmt = $conn->prepare(
        "SELECT id, razorpay_order_id
         FROM payments
         WHERE order_id = ? AND payment_method = 'razorpay'
         LIMIT 1"
    );
    $payStmt->bind_param('i', $orderId);
    $payStmt->execute();
    $paymentRow = $payStmt->get_result()->fetch_assoc();
    $paymentRowId = (int) ($paymentRow['id'] ?? 0);

    if ($paymentRow && $rzpOrderId !== '' && (string) ($paymentRow['razorpay_order_id'] ?? '') !== '' && (string) $paymentRow['razorpay_order_id'] !== $rzpOrderId) {
        PaymentService::payment_attempt_touch(
            $conn,
            'razorpay',
            $rzpOrderId,
            $orderId,
            $paymentRowId,
            'failure_rejected',
            'failure_callback',
            $paymentId,
            '',
            'order_id_mismatch',
            'Razorpay order ID mismatch on failure callback',
            '',
            '',
            '',
            false
        );
        throw new RuntimeException('Razorpay order ID mismatch on failure callback.');
    }

    if ((string) ($order['payment_status'] ?? '') !== 'paid') {
        $attemptRef = $rzpOrderId !== '' ? $rzpOrderId : (string) ($paymentRow['razorpay_order_id'] ?? '');
        if ($attemptRef !== '') {
            PaymentService::payment_attempt_touch(
                $conn,
                'razorpay',
                $attemptRef,
                $orderId,
                $paymentRowId,
                $eventType === 'cancelled' ? 'cancelled' : 'failed',
                'failure_callback',
                $paymentId,
                '',
                $errorCode,
                $errorDescription !== '' ? $errorDescription : ('Razorpay payment ' . $eventType),
                '',
                '',
                json_encode([
                    'event_type' => $eventType,
                    'error_code' => $errorCode,
                    'error_description' => $errorDescription,
                ], JSON_UNESCAPED_UNICODE),
                false
            );
        }

        $parts = ['Razorpay payment ' . $eventType];
        if ($errorCode !== '') {
            $parts[] = 'code: ' . $errorCode;
        }
        if ($errorDescription !== '') {
            $parts[] = 'reason: ' . $errorDescription;
        }
        $note = implode(' | ', $parts);
        // A browser callback is not authoritative: Razorpay may still deliver
        // a capture webhook after the modal closes or reports a transient error.
        // Keep the order pending and inventory/coupon reservations intact.
        log_order_activity(
            $conn,
            $orderId,
            $eventType === 'cancelled' ? 'payment_browser_cancelled' : 'payment_browser_failed',
            $customerId > 0 ? 'customer' : 'guest',
            $customerId,
            $customerId > 0 ? 'customer' : 'guest',
            $note
        );
    }

    $conn->commit();

    if ($eventType === 'cancelled') {
        flash('error', $customerId > 0
            ? 'Payment was cancelled. You can retry payment from your orders.'
            : 'Payment was cancelled. Your order and reserved items are still available; retry payment below.');
    } else {
        $msg = $customerId > 0
            ? 'Payment failed. You can retry payment from your orders.'
            : 'Payment failed. Your order and reserved items are still available; retry payment below.';
        if ($errorDescription !== '') {
            $msg .= ' Reason: ' . $errorDescription;
        } elseif ($errorCode !== '') {
            $msg .= ' Reason code: ' . $errorCode;
        }
        flash('error', $msg);
    }
    redirect($customerId > 0 ? '/customer/orders.php' : '/order-success.php?order=' . urlencode($orderNumber));
} catch (Throwable $e) {
    try {
        $conn->rollback();
    } catch (Throwable $rollbackException) {
        // ignore rollback errors
    }

    error_log('[razorpay] failure callback failed: ' . $e->getMessage());
    flash('error', $customerId > 0
        ? 'Unable to process payment status. Please check your order in My Orders.'
        : 'Unable to process payment status. If money was debited, contact support with your order number.');
    redirect($customerId > 0 ? '/customer/orders.php' : '/order-success.php?order=' . urlencode($orderNumber));
}
