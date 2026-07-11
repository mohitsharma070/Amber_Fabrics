<?php
require_once __DIR__ . '/../includes/init.php';
require_once __DIR__ . '/../includes/coupon-functions.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo 'Method Not Allowed';
    exit;
}

$webhookSecret = _cfg('RAZORPAY_WEBHOOK_SECRET', '');
if ($webhookSecret === '') {
    error_log('[razorpay-webhook] missing RAZORPAY_WEBHOOK_SECRET');
    http_response_code(500);
    echo 'Webhook secret missing';
    exit;
}

$signature = (string) ($_SERVER['HTTP_X_RAZORPAY_SIGNATURE'] ?? '');
$payload = file_get_contents('php://input');
if ($payload === false || $payload === '') {
    http_response_code(400);
    echo 'Empty payload';
    exit;
}
if ($signature === '') {
    http_response_code(400);
    echo 'Missing signature';
    exit;
}

$expected = hash_hmac('sha256', $payload, $webhookSecret);
if (!hash_equals($expected, $signature)) {
    error_log('[razorpay-webhook] signature mismatch');
    http_response_code(400);
    echo 'Invalid signature';
    exit;
}

$event = json_decode($payload, true);
if (!is_array($event)) {
    http_response_code(400);
    echo 'Invalid payload';
    exit;
}

$eventId = trim((string) ($_SERVER['HTTP_X_RAZORPAY_EVENT_ID'] ?? ''));
if ($eventId === '') {
    $eventId = hash('sha256', $payload);
}

$payloadHash = PaymentService::payment_webhook_payload_hash($payload);
// Webhook lifecycle:
// 1) claim -> status=processing (atomic row lock)
// 2) run business transaction once
// 3) mark processed only after commit
// 4) on business failure -> mark failed so later retries are accepted
try {
    $lifecycle = PaymentService::payment_webhook_begin_processing($conn, 'razorpay', $eventId, $signature, $payload);
    if (($lifecycle['state'] ?? '') === 'already_processed') {
        error_log('[razorpay-webhook] replay processed event_id=' . $eventId . ' payload_hash=' . $payloadHash);
        http_response_code(200);
        echo 'Already processed';
        exit;
    }
    if (($lifecycle['state'] ?? '') === 'in_progress') {
        error_log('[razorpay-webhook] duplicate in-progress event_id=' . $eventId . ' payload_hash=' . $payloadHash);
        http_response_code(200);
        echo 'Already processing';
        exit;
    }
    error_log('[razorpay-webhook] claimed event_id=' . $eventId . ' attempt=' . (int) ($lifecycle['attempts'] ?? 0) . ' payload_hash=' . $payloadHash);
} catch (Throwable $e) {
    error_log('[razorpay-webhook] lifecycle claim failed: ' . $e->getMessage());
    http_response_code(500);
    echo 'Error';
    exit;
}

$eventType = (string) ($event['event'] ?? '');
if (!in_array($eventType, ['payment.captured', 'order.paid', 'payment.failed'], true)) {
    PaymentService::payment_webhook_mark_processed($conn, 'razorpay', $eventId, $signature, $payloadHash, $payload);
    error_log('[razorpay-webhook] ignored unsupported event type=' . $eventType . ' event_id=' . $eventId);
    http_response_code(200);
    echo 'Ignored';
    exit;
}

$rzpOrderId = '';
$paymentId = '';
$errorCode = '';
$errorDescription = '';
if ($eventType === 'payment.captured') {
    $rzpOrderId = trim((string) ($event['payload']['payment']['entity']['order_id'] ?? ''));
    $paymentId = trim((string) ($event['payload']['payment']['entity']['id'] ?? ''));
} elseif ($eventType === 'payment.failed') {
    $rzpOrderId = trim((string) ($event['payload']['payment']['entity']['order_id'] ?? ''));
    $paymentId = trim((string) ($event['payload']['payment']['entity']['id'] ?? ''));
    $errorCode = trim((string) ($event['payload']['payment']['entity']['error_code'] ?? ''));
    $errorDescription = trim((string) ($event['payload']['payment']['entity']['error_description'] ?? ''));
} else {
    $rzpOrderId = trim((string) ($event['payload']['order']['entity']['id'] ?? ''));
    $paymentId = trim((string) ($event['payload']['payment']['entity']['id'] ?? ''));
}

if ($rzpOrderId === '') {
    http_response_code(400);
    echo 'Missing order id';
    exit;
}

if ($eventType === 'payment.failed') {
    try {
        $conn->begin_transaction();

        $paymentStmt = $conn->prepare(
            "SELECT id, order_id
             FROM payments
             WHERE payment_method = 'razorpay' AND razorpay_order_id = ?
             LIMIT 1"
        );
        $paymentStmt->bind_param('s', $rzpOrderId);
        $paymentStmt->execute();
        $paymentRow = $paymentStmt->get_result()->fetch_assoc();

        if (!$paymentRow) {
            PaymentService::payment_attempt_touch(
                $conn,
                'razorpay',
                $rzpOrderId,
                0,
                0,
                'webhook_unmapped',
                'webhook',
                $paymentId,
                '',
                'payment_row_missing',
                'payment row not found for webhook',
                $eventId,
                $signature,
                $payload,
                false
            );
            PaymentService::payment_webhook_mark_processed($conn, 'razorpay', $eventId, $signature, $payloadHash, $payload);
            $conn->commit();
            http_response_code(200);
            echo 'Ignored';
            exit;
        }

        $paymentRowId = (int) ($paymentRow['id'] ?? 0);
        $orderId = (int) ($paymentRow['order_id'] ?? 0);
        if ($orderId <= 0) {
            throw new RuntimeException('Invalid order id mapped to razorpay_order_id=' . $rzpOrderId);
        }

        $orderStmt = $conn->prepare(
            "SELECT id, payment_status, notes
             FROM orders
             WHERE id = ? AND payment_method = 'razorpay'
             FOR UPDATE"
        );
        $orderStmt->bind_param('i', $orderId);
        $orderStmt->execute();
        $order = $orderStmt->get_result()->fetch_assoc();
        if (!$order) {
            throw new RuntimeException('Order not found for order_id=' . $orderId);
        }

        if (strtolower((string) ($order['payment_status'] ?? '')) !== 'paid') {
            PaymentService::payment_attempt_touch(
                $conn,
                'razorpay',
                $rzpOrderId,
                $orderId,
                $paymentRowId,
                'webhook_failed',
                'webhook',
                $paymentId,
                '',
                $errorCode,
                $errorDescription !== '' ? $errorDescription : 'Razorpay payment failed webhook',
                $eventId,
                $signature,
                $payload,
                false
            );

            $parts = ['Razorpay payment failed (webhook)'];
            if ($errorCode !== '') {
                $parts[] = 'code: ' . $errorCode;
            }
            if ($errorDescription !== '') {
                $parts[] = 'reason: ' . $errorDescription;
            }
            $note = implode(' | ', $parts);
            PaymentService::razorpay_mark_order_failed(
                $conn,
                $orderId,
                (string) ($order['payment_status'] ?? ''),
                $note,
                $paymentId,
                $rzpOrderId
            );
            InventoryService::restore_order_inventory($conn, $orderId);
            log_order_activity($conn, $orderId, 'payment_failed', 'webhook', 0, 'razorpay', $note);
        }

        PaymentService::payment_webhook_mark_processed($conn, 'razorpay', $eventId, $signature, $payloadHash, $payload);
        $conn->commit();
        error_log('[razorpay-webhook] processed failure event_id=' . $eventId . ' order_id=' . $orderId . ' payment_id=' . $paymentId);
        http_response_code(200);
        echo 'OK';
    } catch (Throwable $e) {
        try {
            $conn->rollback();
        } catch (Throwable $rollbackException) {
            // ignore rollback errors
        }
        try {
            PaymentService::payment_webhook_mark_failed($conn, 'razorpay', $eventId, $e->getMessage(), $signature);
        } catch (Throwable $markFailedException) {
            error_log('[razorpay-webhook] failed to persist webhook failure state: ' . $markFailedException->getMessage());
        }
        error_log('[razorpay-webhook] payment.failed handler failed: ' . $e->getMessage());
        http_response_code(500);
        echo 'Error';
    }
    exit;
}

try {
    $businessCommitted = false;
    $conn->begin_transaction();
    //throw new RuntimeException('manual test failure after claim');

    $paymentStmt = $conn->prepare(
        "SELECT id, order_id, payment_status
         FROM payments
         WHERE payment_method = 'razorpay' AND razorpay_order_id = ?
         LIMIT 1"
    );
    $paymentStmt->bind_param('s', $rzpOrderId);
    $paymentStmt->execute();
    $paymentRow = $paymentStmt->get_result()->fetch_assoc();
    if (!$paymentRow) {
        PaymentService::payment_attempt_touch(
            $conn,
            'razorpay',
            $rzpOrderId,
            0,
            0,
            'webhook_unmapped',
            'webhook',
            $paymentId,
            '',
            'payment_row_missing',
            'payment row not found for webhook',
            $eventId,
            $signature,
            $payload,
            false
        );
        throw new RuntimeException('Payment row not found for razorpay_order_id=' . $rzpOrderId);
    }

    $paymentRowId = (int) ($paymentRow['id'] ?? 0);
    $orderId = (int) ($paymentRow['order_id'] ?? 0);
    if ($orderId <= 0) {
        throw new RuntimeException('Invalid order id mapped to razorpay_order_id=' . $rzpOrderId);
    }

    $orderStmt = $conn->prepare(
        "SELECT id, customer_id, order_number, order_status, payment_status, order_notes, total_amount
         FROM orders
         WHERE id = ? AND payment_method = 'razorpay'
         FOR UPDATE"
    );
    $orderStmt->bind_param('i', $orderId);
    $orderStmt->execute();
    $order = $orderStmt->get_result()->fetch_assoc();
    if (!$order) {
        throw new RuntimeException('Order not found for order_id=' . $orderId);
    }

    if (($order['payment_status'] ?? '') === 'paid') {
        PaymentService::payment_webhook_mark_processed($conn, 'razorpay', $eventId, $signature, $payloadHash, $payload);
        $conn->commit();
        error_log('[razorpay-webhook] replay business-idempotent paid event_id=' . $eventId . ' order_id=' . $orderId);
        http_response_code(200);
        echo 'Already processed';
        exit;
    }

    if (!in_array((string) ($order['order_status'] ?? ''), ['pending', 'confirmed'], true)) {
        throw new RuntimeException('Order not in payable state for order_id=' . $orderId);
    }
    if ($paymentId === '') {
        throw new RuntimeException('Missing razorpay payment id for capture event.');
    }

    $remoteValidation = PaymentService::razorpay_validate_remote_capture(
        $paymentId,
        $rzpOrderId,
        (float) ($order['total_amount'] ?? 0)
    );
    if (empty($remoteValidation['ok'])) {
        PaymentService::payment_attempt_touch(
            $conn,
            'razorpay',
            $rzpOrderId,
            $orderId,
            $paymentRowId,
            'webhook_rejected',
            'webhook',
            $paymentId,
            $signature,
            'gateway_validation_failed',
            (string) ($remoteValidation['error'] ?? 'unknown'),
            $eventId,
            $signature,
            $payload,
            false
        );
        throw new RuntimeException('Razorpay gateway validation failed.');
    }

    PaymentService::razorpay_mark_order_paid(
        $conn,
        $orderId,
        (string) ($order['payment_status'] ?? ''),
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
        'webhook_captured',
        'webhook',
        $paymentId,
        $signature,
        '',
        '',
        $eventId,
        $signature,
        $payload,
        false
    );

    PaymentService::consume_coupon_after_razorpay_capture(
        $conn,
        $orderId,
        (int) ($order['customer_id'] ?? 0),
        0,
        (string) ($order['order_notes'] ?? '')
    );
    $orderCustomerId = (int) ($order['customer_id'] ?? 0);
    if ($orderCustomerId > 0) {
        CartService::cart_clear_db($conn, $orderCustomerId);
    }
    log_order_activity(
        $conn,
        $orderId,
        'payment_captured',
        'webhook',
        0,
        'razorpay',
        'Event: ' . $eventType . ' | Payment: ' . $paymentId
    );
    PaymentService::payment_webhook_mark_processed($conn, 'razorpay', $eventId, $signature, $payloadHash, $payload);

    $conn->commit();
    $businessCommitted = true;
    error_log('[razorpay-webhook] processed capture event_id=' . $eventId . ' order_id=' . $orderId . ' payment_id=' . $paymentId);

    do_action('order.after_payment_success', [
        'conn' => $conn,
        'order_id' => $orderId,
        'order_number' => (string) ($order['order_number'] ?? ''),
        'customer_id' => (int) ($order['customer_id'] ?? 0),
        'payment_method' => 'razorpay',
        'payment_status' => 'paid',
    ]);

    EmailService::send_order_confirmation_email($conn, $orderId);
    http_response_code(200);
    echo 'OK';
} catch (Throwable $e) {
    try {
        $conn->rollback();
    } catch (Throwable $rollbackException) {
        // ignore rollback errors
    }
    if (empty($businessCommitted)) {
        try {
            PaymentService::payment_webhook_mark_failed($conn, 'razorpay', $eventId, $e->getMessage(), $signature);
        } catch (Throwable $markFailedException) {
            error_log('[razorpay-webhook] failed to persist webhook failure state: ' . $markFailedException->getMessage());
        }
    } else {
        // Core transaction is already committed. Keep webhook as processed and do not retry capture.
        error_log('[razorpay-webhook] post-commit side effect failed for event_id=' . $eventId . ': ' . $e->getMessage());
        http_response_code(200);
        echo 'OK';
        exit;
    }
    error_log('[razorpay-webhook] failed: ' . $e->getMessage());
    http_response_code(500);
    echo 'Error';
}
