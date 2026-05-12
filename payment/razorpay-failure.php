<?php
require_once __DIR__ . '/../includes/init.php';
require_once __DIR__ . '/../includes/customer-auth.php';

require_customer();

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

    $orderStmt = $conn->prepare(
        "SELECT id, order_number, payment_status
         FROM orders
         WHERE id = ? AND customer_id = ? AND payment_method = 'razorpay'
         FOR UPDATE"
    );
    $orderStmt->bind_param('ii', $orderId, $customerId);
    $orderStmt->execute();
    $order = $orderStmt->get_result()->fetch_assoc();

    if (!$order) {
        throw new RuntimeException('Order not found for payment failure callback.');
    }

    $payStmt = $conn->prepare(
        "SELECT razorpay_order_id
         FROM payments
         WHERE order_id = ? AND payment_method = 'razorpay'
         LIMIT 1"
    );
    $payStmt->bind_param('i', $orderId);
    $payStmt->execute();
    $paymentRow = $payStmt->get_result()->fetch_assoc();

    if ($paymentRow && $rzpOrderId !== '' && (string) ($paymentRow['razorpay_order_id'] ?? '') !== '' && (string) $paymentRow['razorpay_order_id'] !== $rzpOrderId) {
        throw new RuntimeException('Razorpay order ID mismatch on failure callback.');
    }

    if ((string) ($order['payment_status'] ?? '') !== 'paid') {
        $updatePayment = $conn->prepare(
            "UPDATE payments
             SET payment_status = 'failed',
                 transaction_id = CASE WHEN ? <> '' THEN ? ELSE transaction_id END,
                 razorpay_payment_id = CASE WHEN ? <> '' THEN ? ELSE razorpay_payment_id END,
                 razorpay_order_id = CASE WHEN ? <> '' THEN ? ELSE razorpay_order_id END
             WHERE order_id = ? AND payment_method = 'razorpay'"
        );
        $updatePayment->bind_param(
            'ssssssi',
            $paymentId,
            $paymentId,
            $paymentId,
            $paymentId,
            $rzpOrderId,
            $rzpOrderId,
            $orderId
        );
        $updatePayment->execute();

        $parts = ['Razorpay payment ' . $eventType];
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
                 notes = CASE WHEN notes IS NULL OR notes = '' THEN ? ELSE CONCAT(notes, '\\n', ?) END,
                 updated_at = NOW()
             WHERE id = ?"
        );
        $updateOrder->bind_param('ssi', $note, $note, $orderId);
        $updateOrder->execute();
    }

    $conn->commit();

    if ($eventType === 'cancelled') {
        flash('error', 'Payment was cancelled. You can retry payment from your orders.');
    } else {
        $msg = 'Payment failed. You can retry payment from your orders.';
        if ($errorDescription !== '') {
            $msg .= ' Reason: ' . $errorDescription;
        } elseif ($errorCode !== '') {
            $msg .= ' Reason code: ' . $errorCode;
        }
        flash('error', $msg);
    }
    redirect('/customer/orders.php');
} catch (Throwable $e) {
    try {
        $conn->rollback();
    } catch (Throwable $rollbackException) {
        // ignore rollback errors
    }

    error_log('[razorpay] failure callback failed: ' . $e->getMessage());
    flash('error', 'Unable to process payment status. Please check your order in My Orders.');
    redirect('/customer/orders.php');
}
