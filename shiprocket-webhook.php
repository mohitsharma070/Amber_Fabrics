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
$payloadHash = payment_webhook_payload_hash($payload);
// Webhook lifecycle:
// claim atomically -> process once -> mark processed only on successful commit.
// failed processing is persisted as status=failed so provider retries are not ignored.
try {
    $lifecycle = payment_webhook_begin_processing($conn, 'shiprocket', $eventId, $signature, $payload);
    if (($lifecycle['state'] ?? '') === 'already_processed') {
        error_log('[shiprocket-webhook] replay processed event_id=' . $eventId . ' payload_hash=' . $payloadHash);
        http_response_code(200);
        echo 'Already processed';
        exit;
    }
    if (($lifecycle['state'] ?? '') === 'in_progress') {
        error_log('[shiprocket-webhook] duplicate in-progress event_id=' . $eventId . ' payload_hash=' . $payloadHash);
        http_response_code(200);
        echo 'Already processing';
        exit;
    }
    error_log('[shiprocket-webhook] claimed event_id=' . $eventId . ' attempt=' . (int) ($lifecycle['attempts'] ?? 0) . ' payload_hash=' . $payloadHash);
} catch (Throwable $e) {
    error_log('[shiprocket-webhook] lifecycle claim failed: ' . $e->getMessage());
    http_response_code(500);
    echo 'Error';
    exit;
}

$awb = trim((string) ($event['awb'] ?? $event['tracking_id'] ?? $event['awb_code'] ?? ''));
$shipmentStatus = shiprocket_normalize_status((string) ($event['current_status'] ?? $event['status'] ?? ''));
$orderRef = trim((string) ($event['order_id'] ?? $event['channel_order_id'] ?? ''));
$shiprocketOrderId = trim((string) ($event['shiprocket_order_id'] ?? $event['sr_order_id'] ?? ''));
$shiprocketShipmentId = trim((string) ($event['shipment_id'] ?? ''));
$courierName = trim((string) ($event['courier_name'] ?? $event['courier'] ?? ''));
$trackingUrl = trim((string) ($event['tracking_url'] ?? ''));
$returnRef = trim((string) ($event['return_number'] ?? ''));

try {
    $conn->begin_transaction();

    $ship = [];
    if ($awb !== '') {
        if (shipments_support_shiprocket_refs($conn)) {
            $shipStmt = $conn->prepare(
                "SELECT id, order_id, shiprocket_order_id, shiprocket_shipment_id, awb_code, tracking_id
                 FROM shipments
                 WHERE tracking_id = ? OR awb_code = ?
                 LIMIT 1 FOR UPDATE"
            );
            $shipStmt->bind_param('ss', $awb, $awb);
        } else {
            $shipStmt = $conn->prepare("SELECT id, order_id, tracking_id FROM shipments WHERE tracking_id = ? LIMIT 1 FOR UPDATE");
            $shipStmt->bind_param('s', $awb);
        }
        $shipStmt->execute();
        $ship = $shipStmt->get_result()->fetch_assoc() ?: [];
    }
    if (empty($ship) && $shiprocketShipmentId !== '' && shipments_support_shiprocket_refs($conn)) {
        $shipStmt = $conn->prepare(
            "SELECT id, order_id, shiprocket_order_id, shiprocket_shipment_id, awb_code, tracking_id
             FROM shipments
             WHERE shiprocket_shipment_id = ?
             LIMIT 1 FOR UPDATE"
        );
        $shipStmt->bind_param('s', $shiprocketShipmentId);
        $shipStmt->execute();
        $ship = $shipStmt->get_result()->fetch_assoc() ?: [];
    }
    if (empty($ship) && $shiprocketOrderId !== '' && shipments_support_shiprocket_refs($conn)) {
        $shipStmt = $conn->prepare(
            "SELECT id, order_id, shiprocket_order_id, shiprocket_shipment_id, awb_code, tracking_id
             FROM shipments
             WHERE shiprocket_order_id = ?
             LIMIT 1 FOR UPDATE"
        );
        $shipStmt->bind_param('s', $shiprocketOrderId);
        $shipStmt->execute();
        $ship = $shipStmt->get_result()->fetch_assoc() ?: [];
    }

    $orderId = (int) ($ship['order_id'] ?? 0);
    if ($orderId <= 0 && $orderRef !== '') {
        $orderStmt = $conn->prepare("SELECT id FROM orders WHERE order_number = ? LIMIT 1 FOR UPDATE");
        $orderStmt->bind_param('s', $orderRef);
        $orderStmt->execute();
        $order = $orderStmt->get_result()->fetch_assoc() ?: [];
        $orderId = (int) ($order['id'] ?? 0);
    }

    if ($orderId > 0) {
        if (empty($ship)) {
            $shipSelect = shipments_support_shiprocket_refs($conn)
                ? "SELECT id, order_id, shiprocket_order_id, shiprocket_shipment_id, awb_code, tracking_id FROM shipments WHERE order_id = ? LIMIT 1 FOR UPDATE"
                : "SELECT id, order_id, tracking_id FROM shipments WHERE order_id = ? LIMIT 1 FOR UPDATE";
            $shipStmt = $conn->prepare($shipSelect);
            $shipStmt->bind_param('i', $orderId);
            $shipStmt->execute();
            $ship = $shipStmt->get_result()->fetch_assoc() ?: [];
        }

        shiprocket_store_shipment_snapshot(
            $conn,
            $orderId,
            $ship,
            $shiprocketOrderId !== '' ? $shiprocketOrderId : $orderRef,
            $shiprocketShipmentId,
            $awb,
            $courierName,
            $trackingUrl !== '' ? $trackingUrl : shiprocket_tracking_url_for_awb($awb),
            0.0,
            in_array($shipmentStatus, ['shipped', 'in transit', 'out for delivery', 'out for pickup', 'picked up', 'pickup booked', 'reached at destination hub', 'delivered', 'partial_delivered'], true)
        );

        $orderLock = $conn->prepare("SELECT order_status FROM orders WHERE id = ? LIMIT 1 FOR UPDATE");
        $orderLock->bind_param('i', $orderId);
        $orderLock->execute();
        $orderRow = $orderLock->get_result()->fetch_assoc() ?: [];
        $currentOrderStatus = strtolower(trim((string) ($orderRow['order_status'] ?? 'pending')));
        $nextOrderStatus = shiprocket_map_order_status($shipmentStatus);

        if ($nextOrderStatus !== '') {
            $isTerminal = in_array($currentOrderStatus, ['cancelled', 'refunded'], true);
            $isRegression = ($currentOrderStatus === 'delivered' && $nextOrderStatus !== 'delivered');
            if (!$isTerminal && !$isRegression && $currentOrderStatus !== $nextOrderStatus) {
                $legacy = $nextOrderStatus;
                if ($nextOrderStatus === 'returned') {
                    $legacy = 'returned';
                } elseif ($nextOrderStatus === 'shipped') {
                    $legacy = 'shipped';
                } elseif ($nextOrderStatus === 'cancelled') {
                    $legacy = 'cancelled';
                }
                $updOrder = $conn->prepare("UPDATE orders SET order_status = ?, status = ?, updated_at = NOW() WHERE id = ?");
                $updOrder->bind_param('ssi', $nextOrderStatus, $legacy, $orderId);
                $updOrder->execute();
            }
        }

        if ($shipmentStatus === 'delivered') {
            $shipRefresh = $conn->prepare("UPDATE shipments SET delivered_at = COALESCE(delivered_at, ?) WHERE order_id = ?");
            $deliveredAt = date('Y-m-d H:i:s');
            $shipRefresh->bind_param('si', $deliveredAt, $orderId);
            $shipRefresh->execute();
        }

        log_shiprocket_tracking_event(
            $conn,
            $orderId,
            (int) ($ship['id'] ?? 0),
            'webhook',
            $eventId,
            $shiprocketOrderId !== '' ? $shiprocketOrderId : $orderRef,
            $shiprocketShipmentId,
            $awb,
            $shipmentStatus,
            $courierName,
            $trackingUrl !== '' ? $trackingUrl : shiprocket_tracking_url_for_awb($awb),
            json_encode($event, JSON_UNESCAPED_UNICODE)
        );

        $detailParts = ['Status: ' . $shipmentStatus];
        if ($awb !== '') {
            $detailParts[] = 'AWB: ' . $awb;
        }
        if ($shiprocketShipmentId !== '') {
            $detailParts[] = 'Shipment: ' . $shiprocketShipmentId;
        }
        log_order_activity($conn, $orderId, 'courier_webhook_sync', 'webhook', 0, 'shiprocket', implode(' | ', $detailParts));
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

    payment_webhook_mark_processed($conn, 'shiprocket', $eventId, $signature, $payloadHash, $payload);
    $conn->commit();
    error_log('[shiprocket-webhook] processed event_id=' . $eventId . ' order_id=' . $orderId . ' status=' . $shipmentStatus);
    http_response_code(200);
    echo 'OK';
} catch (Throwable $e) {
    try {
        $conn->rollback();
    } catch (Throwable $rollbackException) {
    }
    try {
        payment_webhook_mark_failed($conn, 'shiprocket', $eventId, $e->getMessage(), $signature);
    } catch (Throwable $markFailedException) {
        error_log('[shiprocket-webhook] failed to persist webhook failure state: ' . $markFailedException->getMessage());
    }
    error_log('[shiprocket-webhook] failed: ' . $e->getMessage());
    http_response_code(500);
    echo 'Error';
}
