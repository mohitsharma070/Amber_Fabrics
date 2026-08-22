<?php

function shipping_courier_webhook_table_ready(mysqli $conn): bool
{
    try {
        $stmt = $conn->prepare(
            "SELECT COUNT(*) AS total
             FROM information_schema.TABLES
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = 'shipping_courier_webhook_events'"
        );
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        return ((int) ($row['total'] ?? 0)) > 0;
    } catch (Throwable $e) {
        error_log('[shipping-courier] webhook table check failed: ' . $e->getMessage());
        return false;
    }
}

function shipping_courier_webhook_signature(): string
{
    return trim((string) (
        $_SERVER['HTTP_X_SHIPPING_COURIER_SIGNATURE']
        ?? $_SERVER['HTTP_X_WEBHOOK_SIGNATURE']
        ?? $_SERVER['HTTP_X_SIGNATURE']
        ?? ''
    ));
}

function shipping_courier_validate_webhook_request(string $payload): bool
{
    $settings = shipping_courier_settings();
    $secret = trim((string) ($settings['webhook_secret'] ?? ''));
    $signature = shipping_courier_webhook_signature();
    if (!shipping_courier_enabled() || $secret === '' || $signature === '') {
        return false;
    }

    $mode = strtolower(trim((string) ($settings['webhook_signature_mode'] ?? 'hmac_sha256')));
    if ($mode === 'shared_secret') {
        return hash_equals($secret, $signature);
    }
    if (stripos($signature, 'sha256=') === 0) {
        $signature = substr($signature, 7);
    }
    $expected = hash_hmac('sha256', $payload, $secret);
    return hash_equals($expected, strtolower($signature));
}

function shipping_courier_webhook_event_id(array $payload, string $rawPayload): string
{
    $headerId = trim((string) (
        $_SERVER['HTTP_X_SHIPPING_COURIER_EVENT_ID']
        ?? $_SERVER['HTTP_X_WEBHOOK_EVENT_ID']
        ?? ''
    ));
    if ($headerId !== '') {
        return substr($headerId, 0, 191);
    }

    $payloadId = shipping_courier_response_value($payload, ['event_id', 'webhook_id']);
    return substr($payloadId !== '' ? $payloadId : hash('sha256', $rawPayload), 0, 191);
}

function shipping_courier_webhook_begin_processing(
    mysqli $conn,
    string $provider,
    string $eventId,
    string $signature,
    string $rawPayload
): array {
    if (!shipping_courier_webhook_table_ready($conn)) {
        throw new RuntimeException('Shipping courier webhook event table is unavailable.');
    }

    $payloadHash = hash('sha256', $rawPayload);
    $insert = $conn->prepare(
        "INSERT IGNORE INTO shipping_courier_webhook_events
            (provider, event_id, signature, payload_hash, raw_payload, status, attempts)
         VALUES (?, ?, ?, ?, ?, 'processing', 1)"
    );
    $insert->bind_param('sssss', $provider, $eventId, $signature, $payloadHash, $rawPayload);
    $insert->execute();
    if ((int) $insert->affected_rows > 0) {
        return ['state' => 'claimed', 'payload_hash' => $payloadHash];
    }

    $stmt = $conn->prepare(
        "SELECT status
         FROM shipping_courier_webhook_events
         WHERE provider = ? AND event_id = ?
         LIMIT 1"
    );
    $stmt->bind_param('ss', $provider, $eventId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc() ?: [];
    if (($row['status'] ?? '') === 'processed') {
        return ['state' => 'already_processed', 'payload_hash' => $payloadHash];
    }

    $claim = $conn->prepare(
        "UPDATE shipping_courier_webhook_events
         SET signature = ?,
             payload_hash = ?,
             raw_payload = ?,
             status = 'processing',
             attempts = attempts + 1,
             last_error = NULL,
             updated_at = NOW()
         WHERE provider = ?
           AND event_id = ?
           AND (
                status IN ('received', 'failed')
                OR (status = 'processing' AND updated_at < (NOW() - INTERVAL 10 MINUTE))
           )"
    );
    $claim->bind_param('sssss', $signature, $payloadHash, $rawPayload, $provider, $eventId);
    $claim->execute();
    return [
        'state' => (int) $claim->affected_rows > 0 ? 'claimed' : 'in_progress',
        'payload_hash' => $payloadHash,
    ];
}

function shipping_courier_webhook_mark_processed(
    mysqli $conn,
    string $provider,
    string $eventId,
    string $signature,
    string $rawPayload
): void {
    $payloadHash = hash('sha256', $rawPayload);
    $stmt = $conn->prepare(
        "UPDATE shipping_courier_webhook_events
         SET signature = ?,
             payload_hash = ?,
             raw_payload = ?,
             status = 'processed',
             last_error = NULL,
             processed_at = NOW(),
             updated_at = NOW()
         WHERE provider = ? AND event_id = ?"
    );
    $stmt->bind_param('sssss', $signature, $payloadHash, $rawPayload, $provider, $eventId);
    $stmt->execute();
}

function shipping_courier_webhook_mark_failed(
    mysqli $conn,
    string $provider,
    string $eventId,
    string $error,
    string $signature
): void {
    $error = substr($error, 0, 2000);
    $stmt = $conn->prepare(
        "UPDATE shipping_courier_webhook_events
         SET signature = ?,
             status = 'failed',
             last_error = ?,
             updated_at = NOW()
         WHERE provider = ? AND event_id = ?"
    );
    $stmt->bind_param('ssss', $signature, $error, $provider, $eventId);
    $stmt->execute();
}

function shipping_courier_find_webhook_shipment(mysqli $conn, array $payload): ?array
{
    $provider = shipping_courier_provider_name();
    $providerShipmentId = shipping_courier_response_value($payload, ['provider_shipment_id', 'shipment_id', 'courier_shipment_id']);
    $providerOrderId = shipping_courier_response_value($payload, ['CustomGlobalOrderId', 'custom_global_order_id', 'provider_order_id', 'order_id', 'courier_order_id']);
    $trackingId = shipping_courier_response_value($payload, ['tracking_id', 'tracking_number', 'awb_code', 'awb', 'waybill']);
    if ($provider === '' || ($providerShipmentId === '' && $providerOrderId === '' && $trackingId === '')) {
        return null;
    }

    $stmt = $conn->prepare(
        "SELECT scs.order_id, scs.shipment_id, scs.provider_status,
                s.awb_code, s.courier_name, s.tracking_id, s.tracking_url,
                s.shipping_cost, s.shipped_at, s.delivered_at
         FROM shipping_courier_shipments scs
         JOIN shipments s ON s.id = scs.shipment_id
         WHERE scs.provider = ?
           AND (
                (? <> '' AND scs.provider_shipment_id = ?)
                OR (? <> '' AND scs.provider_order_id = ?)
                OR (? <> '' AND (s.tracking_id = ? OR s.awb_code = ?))
           )
         LIMIT 1"
    );
    $stmt->bind_param(
        'ssssssss',
        $provider,
        $providerShipmentId,
        $providerShipmentId,
        $providerOrderId,
        $providerOrderId,
        $trackingId,
        $trackingId,
        $trackingId
    );
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    return $row ?: null;
}

function shipping_courier_handle_webhook_payload(mysqli $conn, array $payload): array
{
    if (shipping_courier_reverse_supports('webhook')) {
        $reverseEvent = apply_filters('shipping_courier.reverse.webhook_metadata', null, [
            'conn' => $conn,
            'provider' => shipping_courier_provider_name(),
            'payload' => $payload,
        ]);
        if (is_array($reverseEvent) && (int) ($reverseEvent['return_id'] ?? 0) > 0 && (int) ($reverseEvent['order_id'] ?? 0) > 0) {
            $reversePickup = shipping_courier_upsert_reverse_pickup(
                $conn,
                (int) $reverseEvent['return_id'],
                (int) $reverseEvent['order_id'],
                array_merge($reverseEvent, ['provider' => shipping_courier_provider_name()])
            );
            return [
                'processed' => true,
                'ignored' => false,
                'order_id' => (int) $reverseEvent['order_id'],
                'reverse_pickup' => true,
                'provider_status' => (string) ($reversePickup['provider_status'] ?? ''),
            ];
        }
    }

    $matched = shipping_courier_find_webhook_shipment($conn, $payload);
    if (!$matched) {
        return ['processed' => false, 'ignored' => true, 'reason' => 'shipment_not_found'];
    }

    $orderId = (int) ($matched['order_id'] ?? 0);
    $shipmentId = (int) ($matched['shipment_id'] ?? 0);
    if ($orderId <= 0 || $shipmentId <= 0) {
        throw new RuntimeException('Courier webhook matched an invalid shipment.');
    }

    $previousTrackingId = trim((string) ($matched['tracking_id'] ?? ''));
    $previousProviderStatus = shipping_courier_normalize_provider_status((string) ($matched['provider_status'] ?? ''));
    $previousShippedAt = trim((string) ($matched['shipped_at'] ?? ''));
    $previousDeliveredAt = trim((string) ($matched['delivered_at'] ?? ''));

    $shipmentData = shipping_courier_shipment_data_from_response($payload);
    $shipmentData = shipping_courier_apply_tracking_milestones($shipmentData, $payload, $matched);
    $shipment = !empty($shipmentData)
        ? shipping_courier_upsert_shipment($conn, $orderId, $shipmentData)
        : (shipping_courier_get_shipment($conn, $orderId) ?: $matched);

    $metadata = shipping_courier_upsert_metadata(
        $conn,
        $orderId,
        $shipmentId,
        array_merge(shipping_courier_metadata_from_response($payload), [
            'provider' => shipping_courier_provider_name(),
        ])
    );

    $providerStatus = is_array($metadata)
        ? shipping_courier_normalize_provider_status((string) ($metadata['provider_status'] ?? ''))
        : $previousProviderStatus;
    $newShippedAt = trim((string) ($shipment['shipped_at'] ?? ''));
    $newDeliveredAt = trim((string) ($shipment['delivered_at'] ?? ''));
    $changes = [];
    if ($providerStatus !== '' && $providerStatus !== $previousProviderStatus) {
        $changes[] = 'Provider status: ' . ($previousProviderStatus !== '' ? $previousProviderStatus : '-') . ' -> ' . $providerStatus;
    }
    if ($previousTrackingId !== trim((string) ($shipment['tracking_id'] ?? ''))) {
        $changes[] = 'Tracking ID updated';
    }
    if ($previousShippedAt === '' && $newShippedAt !== '') {
        $changes[] = 'Shipped at: ' . $newShippedAt;
    }
    if ($previousDeliveredAt === '' && $newDeliveredAt !== '') {
        $changes[] = 'Delivered at: ' . $newDeliveredAt;
    }
    if (!empty($changes) && function_exists('log_order_activity')) {
        log_order_activity(
            $conn,
            $orderId,
            'shipping_courier_webhook_update',
            'webhook',
            0,
            'shipping-courier',
            implode(' | ', $changes)
        );
    }

    $trackingChanged = $previousTrackingId !== trim((string) ($shipment['tracking_id'] ?? ''))
        && trim((string) ($shipment['tracking_id'] ?? '')) !== '';
    return [
        'processed' => true,
        'ignored' => false,
        'order_id' => $orderId,
        'shipment_id' => $shipmentId,
        'changes' => $changes,
        'tracking_changed' => $trackingChanged,
        'previous_tracking_id' => $previousTrackingId,
    ];
}
