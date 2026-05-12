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

    $itemsStmt = $conn->prepare(
        "SELECT fabric_id, unit_type, quantity_meters
         FROM order_items
         WHERE order_id = ?"
    );
    $itemsStmt->bind_param('i', $orderId);
    $itemsStmt->execute();
    $items = $itemsStmt->get_result()->fetch_all(MYSQLI_ASSOC);

    $paymentMethod = strtolower((string) ($order['payment_method'] ?? ''));
    $paymentStatus = strtolower((string) ($order['payment_status'] ?? 'pending'));
    $shouldRestoreStock = ($paymentMethod === 'cod') || ($paymentMethod === 'razorpay' && $paymentStatus === 'paid');

    if ($shouldRestoreStock && !empty($items)) {
        $stockCheckStmt = $conn->prepare(
            "SELECT id, stock, stock_meters
             FROM fabrics
             WHERE id = ?
             FOR UPDATE"
        );

        foreach ($items as $item) {
            $fabricId = (int) ($item['fabric_id'] ?? 0);
            $itemUnit = in_array((string) ($item['unit_type'] ?? ''), ['meter', 'piece', 'set'], true)
                ? (string) $item['unit_type']
                : 'meter';
            $qty = normalize_quantity_by_unit($item['quantity_meters'] ?? 1, $itemUnit);
            if ($fabricId <= 0) {
                continue;
            }

            $stockCheckStmt->bind_param('i', $fabricId);
            $stockCheckStmt->execute();
            $fabric = $stockCheckStmt->get_result()->fetch_assoc();
            if (!$fabric) {
                throw new RuntimeException('Unable to restore inventory: fabric not found.');
            }

            // Mirror single-source stock behavior used during order deduction.
            $useMeters = $itemUnit === 'meter';
            if ($useMeters) {
                $stockStmt = $conn->prepare(
                    "UPDATE fabrics SET stock_meters = stock_meters + ? WHERE id = ?"
                );
                $stockStmt->bind_param('di', $qty, $fabricId);
            } else {
                $stockStmt = $conn->prepare(
                    "UPDATE fabrics SET stock = stock + ? WHERE id = ?"
                );
                $wholeQty = (int) ceil($qty);
                $stockStmt->bind_param('ii', $wholeQty, $fabricId);
            }
            $stockStmt->execute();
            if ($conn->affected_rows === 0) {
                throw new RuntimeException('Unable to restore inventory for fabric ' . $fabricId . '.');
            }
        }
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
