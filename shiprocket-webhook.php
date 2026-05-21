<?php
require_once __DIR__ . '/includes/init.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo 'Method Not Allowed';
    exit;
}

$secret = trim(_cfg('SHIPROCKET_WEBHOOK_SECRET', ''));
$payload = file_get_contents('php://input');
$signature = trim((string) ($_SERVER['HTTP_X_SHIPROCKET_SIGNATURE'] ?? ''));
if ($secret === '') {
    error_log('[shiprocket-webhook] missing SHIPROCKET_WEBHOOK_SECRET');
    http_response_code(500);
    echo 'Webhook secret missing';
    exit;
}
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
$expected = hash_hmac('sha256', $payload, $secret);
if (!hash_equals($expected, $signature)) {
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

$eventId = trim((string) ($_SERVER['HTTP_X_SHIPROCKET_EVENT_ID'] ?? ''));
if ($eventId === '') {
    $eventId = hash('sha256', $payload);
}
try {
    if (payment_webhook_is_duplicate($conn, 'shiprocket', $eventId, $signature)) {
        http_response_code(200);
        echo 'Duplicate ignored';
        exit;
    }
} catch (Throwable $e) {
    error_log('[shiprocket-webhook] dedupe failed: ' . $e->getMessage());
    http_response_code(500);
    echo 'Error';
    exit;
}

$awb = trim((string) ($event['awb'] ?? $event['tracking_id'] ?? ''));
$shipmentStatus = strtolower(trim((string) ($event['current_status'] ?? $event['status'] ?? '')));
$orderRef = trim((string) ($event['order_id'] ?? ''));
$returnRef = trim((string) ($event['return_number'] ?? ''));

try {
    $conn->begin_transaction();

    if ($awb !== '') {
        $shipStmt = $conn->prepare("SELECT id, order_id FROM shipments WHERE tracking_id = ? LIMIT 1 FOR UPDATE");
        $shipStmt->bind_param('s', $awb);
        $shipStmt->execute();
        $ship = $shipStmt->get_result()->fetch_assoc() ?: [];
        $orderId = (int) ($ship['order_id'] ?? 0);
        if ($orderId > 0) {
            $orderLock = $conn->prepare("SELECT order_status FROM orders WHERE id = ? LIMIT 1 FOR UPDATE");
            $orderLock->bind_param('i', $orderId);
            $orderLock->execute();
            $orderRow = $orderLock->get_result()->fetch_assoc() ?: [];
            $currentOrderStatus = strtolower(trim((string) ($orderRow['order_status'] ?? 'pending')));
            $orderStatus = '';
            $deliveredAt = null;
            if (in_array($shipmentStatus, ['shipped', 'in_transit', 'out for delivery', 'out_for_delivery'], true)) {
                $orderStatus = 'shipped';
            } elseif (in_array($shipmentStatus, ['delivered'], true)) {
                $orderStatus = 'delivered';
                $deliveredAt = date('Y-m-d H:i:s');
            }
            if ($orderStatus !== '') {
                $isTerminal = in_array($currentOrderStatus, ['cancelled', 'returned', 'refunded'], true);
                $isRegression = ($currentOrderStatus === 'delivered' && $orderStatus === 'shipped');
                if (!$isTerminal && !$isRegression && $currentOrderStatus !== $orderStatus) {
                    $updOrder = $conn->prepare("UPDATE orders SET order_status = ?, status = ?, updated_at = NOW() WHERE id = ?");
                    $legacy = $orderStatus === 'delivered' ? 'delivered' : 'shipped';
                    $updOrder->bind_param('ssi', $orderStatus, $legacy, $orderId);
                    $updOrder->execute();
                    log_order_activity($conn, $orderId, 'courier_webhook_sync', 'webhook', 0, 'shiprocket', 'AWB ' . $awb . ' -> ' . $shipmentStatus);
                }
            }
            if ($deliveredAt !== null) {
                $updShip = $conn->prepare("UPDATE shipments SET delivered_at = COALESCE(delivered_at, ?) WHERE id = ?");
                $shipmentId = (int) ($ship['id'] ?? 0);
                $updShip->bind_param('si', $deliveredAt, $shipmentId);
                $updShip->execute();
            }
        }
    } elseif ($orderRef !== '') {
        $orderStmt = $conn->prepare("SELECT id FROM orders WHERE order_number = ? LIMIT 1 FOR UPDATE");
        $orderStmt->bind_param('s', $orderRef);
        $orderStmt->execute();
        $order = $orderStmt->get_result()->fetch_assoc() ?: [];
        $orderId = (int) ($order['id'] ?? 0);
        if ($orderId > 0) {
            log_order_activity($conn, $orderId, 'courier_webhook_sync', 'webhook', 0, 'shiprocket', 'Status: ' . $shipmentStatus);
        }
    }

    if ($returnRef !== '') {
        $retStatusMap = [
            'pickup_scheduled' => 'pickup_scheduled',
            'in_transit' => 'in_transit',
            'delivered' => 'received',
            'received' => 'received',
            'cancelled' => 'cancelled',
        ];
        $next = $retStatusMap[$shipmentStatus] ?? '';
        if ($next !== '') {
            $retStmt = $conn->prepare("UPDATE returns SET status = ?, updated_at = NOW() WHERE return_number = ?");
            $retStmt->bind_param('ss', $next, $returnRef);
            $retStmt->execute();
        }
    }

    payment_webhook_mark_processed($conn, 'shiprocket', $eventId, $signature);
    $conn->commit();
    http_response_code(200);
    echo 'OK';
} catch (Throwable $e) {
    try {
        $conn->rollback();
    } catch (Throwable $rollbackException) {
    }
    error_log('[shiprocket-webhook] failed: ' . $e->getMessage());
    http_response_code(500);
    echo 'Error';
}
