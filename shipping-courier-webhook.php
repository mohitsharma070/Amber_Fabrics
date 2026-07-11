<?php
require_once __DIR__ . '/includes/init.php';

if (!function_exists('shipping_courier_handle_webhook_payload')) {
    http_response_code(404);
    echo 'Shipping Courier plugin is not enabled';
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo 'Method Not Allowed';
    exit;
}

$rawPayload = file_get_contents('php://input');
if ($rawPayload === false || $rawPayload === '') {
    http_response_code(400);
    echo 'Empty payload';
    exit;
}

if (!shipping_courier_validate_webhook_request($rawPayload)) {
    http_response_code(403);
    echo 'Invalid signature';
    exit;
}

$payload = json_decode($rawPayload, true);
if (!is_array($payload)) {
    http_response_code(400);
    echo 'Invalid payload';
    exit;
}

$provider = shipping_courier_provider_name();
$signature = shipping_courier_webhook_signature();
$eventId = shipping_courier_webhook_event_id($payload, $rawPayload);
if ($provider === '' || $eventId === '') {
    http_response_code(400);
    echo 'Missing provider or event id';
    exit;
}

try {
    $lifecycle = shipping_courier_webhook_begin_processing(
        $conn,
        $provider,
        $eventId,
        $signature,
        $rawPayload
    );
    if (($lifecycle['state'] ?? '') === 'already_processed') {
        header('Content-Type: application/json');
        echo json_encode(['ok' => true, 'state' => 'already_processed'], JSON_UNESCAPED_SLASHES);
        exit;
    }
    if (($lifecycle['state'] ?? '') === 'in_progress') {
        header('Content-Type: application/json');
        echo json_encode(['ok' => true, 'state' => 'in_progress'], JSON_UNESCAPED_SLASHES);
        exit;
    }
} catch (Throwable $e) {
    error_log('[shipping-courier] webhook claim failed: ' . $e->getMessage());
    http_response_code(503);
    echo 'Webhook storage unavailable';
    exit;
}

try {
    $conn->begin_transaction();
    $result = shipping_courier_handle_webhook_payload($conn, $payload);
    shipping_courier_webhook_mark_processed($conn, $provider, $eventId, $signature, $rawPayload);
    $conn->commit();

    if (!empty($result['tracking_changed'])) {
        $orderId = (int) ($result['order_id'] ?? 0);
        $shipment = $orderId > 0 ? shipping_courier_get_shipment($conn, $orderId) : null;
        if ($orderId > 0 && is_array($shipment)) {
            shipping_courier_maybe_send_tracking_email(
                $conn,
                $orderId,
                (string) ($result['previous_tracking_id'] ?? ''),
                $shipment
            );
        }
    }

    header('Content-Type: application/json');
    echo json_encode(['ok' => true, 'result' => $result], JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    try {
        $conn->rollback();
    } catch (Throwable $rollbackException) {
    }
    try {
        shipping_courier_webhook_mark_failed($conn, $provider, $eventId, $e->getMessage(), $signature);
    } catch (Throwable $markFailedException) {
        error_log('[shipping-courier] webhook failure state could not be saved: ' . $markFailedException->getMessage());
    }
    error_log('[shipping-courier] webhook failed: ' . $e->getMessage());
    http_response_code(500);
    echo 'Error';
}
