<?php
require_once __DIR__ . '/../includes/init.php';
require_once __DIR__ . '/../includes/customer-auth.php';

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
require_order_access($conn, $orderId);
$orderNumber = (string) ($_SESSION['pending_order_number'] ?? '');

if (!in_array($eventType, ['failed', 'cancelled'], true)) {
    $eventType = 'failed';
}

try {
    $conn->begin_transaction();

    $orderStmt = $conn->prepare(
        "SELECT id, order_number, payment_status
         FROM orders
         WHERE id = ? AND payment_method = 'razorpay'
         FOR UPDATE"
    );
    $orderStmt->bind_param('i', $orderId);
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
                'client_reported_' . $eventType,
                'browser_intent',
                $paymentId,
                '',
                $errorCode,
                $errorDescription !== '' ? $errorDescription : ('Browser reported Razorpay payment ' . $eventType),
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

        $parts = ['Browser reported Razorpay payment ' . $eventType . '; awaiting gateway confirmation'];
        if ($errorCode !== '') {
            $parts[] = 'code: ' . $errorCode;
        }
        if ($errorDescription !== '') {
            $parts[] = 'reason: ' . $errorDescription;
        }
        $note = implode(' | ', $parts);
        // Browser events are intent only. Keep payment and inventory reserved until a signed
        // webhook or gateway API reconciliation authoritatively reports the result.
        log_order_activity($conn, $orderId, 'payment_client_intent', $customerId > 0 ? 'customer' : 'guest', $customerId, $customerId > 0 ? 'customer' : 'guest', $note);
    }

    $conn->commit();

    if ($eventType === 'cancelled') {
        flash('warning', 'Payment was dismissed. We are waiting for Razorpay confirmation before changing your order.');
    } else {
        $msg = 'Payment result was reported by the browser. We are waiting for Razorpay confirmation.';
        if ($errorDescription !== '') {
            $msg .= ' Reason: ' . $errorDescription;
        } elseif ($errorCode !== '') {
            $msg .= ' Reason code: ' . $errorCode;
        }
        flash('error', $msg);
    }
    redirect(order_access_landing_url($orderNumber));
} catch (Throwable $e) {
    try {
        $conn->rollback();
    } catch (Throwable $rollbackException) {
        // ignore rollback errors
    }

    error_log('[razorpay] failure callback failed: ' . $e->getMessage());
    flash('error', 'Unable to process payment status. Please check your order in My Orders.');
    redirect(order_access_landing_url($orderNumber));
}
