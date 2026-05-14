<?php
require_once __DIR__ . '/../includes/init.php';
require_once __DIR__ . '/../includes/customer-auth.php';

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
    $conn->begin_transaction();

    $orderStmt = $conn->prepare(
        "SELECT id, order_number, order_status, status, payment_status, payment_method, notes
         FROM orders
         WHERE id = ? AND customer_id = ?
         FOR UPDATE"
    );
    $orderStmt->bind_param('ii', $orderId, $customerId);
    $orderStmt->execute();
    $order = $orderStmt->get_result()->fetch_assoc();

    if (!$order) {
        throw new RuntimeException('Order not found.');
    }

    $currentOrderStatus = (string) ($order['order_status'] ?? '');
    if (!in_array($currentOrderStatus, ['pending', 'confirmed'], true)) {
        throw new RuntimeException('This order can no longer be cancelled.');
    }

    $paymentMethod = strtolower((string) ($order['payment_method'] ?? ''));
    $paymentStatus = strtolower((string) ($order['payment_status'] ?? 'pending'));
    $paymentRowId = 0;
    $paymentAmount = 0.0;
    $paymentRowStmt = $conn->prepare("SELECT id, amount FROM payments WHERE order_id = ? AND payment_method = ? LIMIT 1");
    $paymentRowStmt->bind_param('is', $orderId, $paymentMethod);
    $paymentRowStmt->execute();
    $paymentRow = $paymentRowStmt->get_result()->fetch_assoc() ?: [];
    $paymentRowId = (int) ($paymentRow['id'] ?? 0);
    $paymentAmount = (float) ($paymentRow['amount'] ?? 0);
    $shouldRestoreStock = ($paymentMethod === 'cod' && in_array($paymentStatus, ['pending', 'failed', 'paid'], true))
        || ($paymentMethod === 'razorpay' && in_array($paymentStatus, ['pending', 'failed'], true));

    if ($shouldRestoreStock) {
        restore_order_inventory($conn, $orderId);
    }

    $refundNote = '';
    if ($paymentStatus === 'paid') {
        $refundNote = "\n[System] Refund process initiated on " . date('d M Y, H:i');
    }

    $existingNotes = trim((string) ($order['notes'] ?? ''));
    $newNotes = trim($existingNotes . $refundNote);

    $updateStmt = $conn->prepare(
        "UPDATE orders
         SET order_status = 'cancelled',
             status = 'cancelled',
             notes = ?,
             updated_at = NOW()
         WHERE id = ?"
    );
    $updateStmt->bind_param('si', $newNotes, $orderId);
    $updateStmt->execute();

    log_order_activity(
        $conn,
        $orderId,
        'order_cancelled',
        'customer',
        $customerId,
        'customer',
        'Payment status at cancel: ' . $paymentStatus
    );
    if ($paymentStatus === 'paid' && $paymentRowId > 0 && $paymentAmount > 0) {
        log_refund_ledger(
            $conn,
            $orderId,
            $paymentRowId,
            $paymentAmount,
            'INR',
            'initiated',
            $paymentMethod,
            '',
            'Customer cancelled paid order; refund initiation pending processing.'
        );
        log_order_activity($conn, $orderId, 'refund_initiated', 'system', 0, 'system', 'Refund ledger entry created.');
    }

    $conn->commit();

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
