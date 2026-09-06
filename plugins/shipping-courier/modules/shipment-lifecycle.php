<?php

function shipping_courier_maybe_send_tracking_email(mysqli $conn, int $orderId, string $previousTrackingId, array $shipment): void
{
    $trackingId = trim((string) ($shipment['tracking_id'] ?? ''));
    if ($trackingId === '' || $trackingId === trim($previousTrackingId)) {
        return;
    }

    try {
        EmailService::send_order_status_update_email($conn, $orderId, 'shipped');
    } catch (Throwable $e) {
        error_log('[shipping-courier] tracking email failed for order ' . $orderId . ': ' . $e->getMessage());
    }
}

function shipping_courier_bigship_local_order_status(string $providerStatus): string
{
    $status = shipping_courier_normalize_provider_status($providerStatus);
    if (in_array($status, ['delivered', 'delivery_done'], true)) {
        return 'delivered';
    }
    if (in_array($status, ['cancelled', 'canceled'], true)) {
        return 'cancelled';
    }
    if (in_array($status, ['rto', 'returned', 'return_to_origin', 'rto_delivered'], true)) {
        return 'returned';
    }
    if (in_array($status, ['picked_up', 'pickup_done', 'in_transit', 'out_for_delivery', 'shipped'], true)) {
        return 'shipped';
    }

    return '';
}

function shipping_courier_apply_bigship_order_status(mysqli $conn, int $orderId, string $providerStatus): string
{
    $localStatus = shipping_courier_bigship_local_order_status($providerStatus);
    if ($orderId <= 0 || $localStatus === '') {
        return '';
    }
    $legacyStatus = OrderFieldCompatibilityService::legacyStatus($localStatus);

    $stmt = $conn->prepare(
        "UPDATE orders
         SET order_status = ?, status = ?, updated_at = NOW()
         WHERE id = ?
           AND order_status NOT IN ('cancelled', 'refunded')"
    );
    $stmt->bind_param('ssi', $localStatus, $legacyStatus, $orderId);
    $stmt->execute();
    return $localStatus;
}

function shipping_courier_create_shipment(mysqli $conn, int $orderId): array
{
    $lockName = 'shipping_courier_create_' . $orderId;
    $lock = $conn->prepare('SELECT GET_LOCK(?, 5) AS acquired');
    $lock->bind_param('s', $lockName);
    $lock->execute();
    $acquired = (int) (($lock->get_result()->fetch_assoc()['acquired'] ?? 0));
    if ($acquired !== 1) {
        return shipping_courier_result(false, 'Another courier shipment request is already processing for this order.');
    }

    try {
        return shipping_courier_create_shipment_unlocked($conn, $orderId);
    } finally {
        try {
            $release = $conn->prepare('SELECT RELEASE_LOCK(?)');
            $release->bind_param('s', $lockName);
            $release->execute();
        } catch (Throwable $e) {
            error_log('[shipping-courier] unable to release shipment lock for order ' . $orderId . ': ' . $e->getMessage());
        }
    }
}

function shipping_courier_create_shipment_unlocked(mysqli $conn, int $orderId): array
{
    if (!shipping_courier_enabled()) {
        return shipping_courier_result(false, 'Shipping courier plugin is disabled.');
    }
    if (!shipping_courier_provider_configured()) {
        return shipping_courier_result(false, 'Shipping courier provider is not configured.');
    }

    $payload = shipping_courier_order_payload($conn, $orderId);
    if (!$payload) {
        return shipping_courier_result(false, 'Order not found for courier shipment.');
    }
    if (!shipping_courier_order_ready_for_shipment((array) $payload['order'])) {
        return shipping_courier_result(false, 'Order is not ready for courier shipment creation.');
    }

    $provider = shipping_courier_provider_name();
    $order = (array) $payload['order'];
    $existingShipment = shipping_courier_get_shipment($conn, $orderId);
    $previousTrackingId = trim((string) ($existingShipment['tracking_id'] ?? ''));
    $existingShipmentId = (int) ($existingShipment['id'] ?? 0);
    $existingMetadata = null;
    if ($existingShipmentId > 0) {
        $existingMetadata = shipping_courier_get_metadata($conn, $existingShipmentId, $provider);
        if (!empty($existingMetadata['provider_shipment_id'])) {
            return shipping_courier_result(true, 'Courier shipment already exists.', [
                'shipment' => $existingShipment,
                'metadata' => $existingMetadata,
            ]);
        }
    }

    $courierId = max(0, (int) ($order['courier_id'] ?? 0));
    if ($courierId <= 0) {
        return shipping_courier_result(false, 'A Bigship courier must be selected in the shipping quote before shipment creation.');
    }

    $shipment = $existingShipment ?: shipping_courier_upsert_shipment($conn, $orderId, [
        'courier_name' => (string) ($order['courier_name'] ?? $provider),
        'shipping_cost' => (float) ($order['base_shipping'] ?? 0),
    ]);
    $shipmentId = (int) ($shipment['id'] ?? 0);
    if ($shipmentId <= 0) {
        return shipping_courier_result(false, 'Unable to create local shipment metadata.');
    }

    $rawResponses = $existingMetadata['raw_response_json'] ?? null;
    if (is_string($rawResponses)) {
        $rawResponses = json_decode($rawResponses, true);
    }
    $responses = is_array($rawResponses) ? $rawResponses : [];
    $customGlobalOrderId = trim((string) ($existingMetadata['provider_order_id'] ?? ''));
    if ($customGlobalOrderId === '') {
        if (in_array(($responses['create_order_intent']['state'] ?? ''), ['pending', 'outcome_unknown'], true)) {
            return shipping_courier_result(false, 'Bigship create-order outcome is unknown; reconcile the stable order invoice in Bigship before retrying.');
        }
        $createPayload = shipping_courier_bigship_order_request($payload);
        if (empty($createPayload['ok']) || !is_array($createPayload['body'] ?? null)) {
            return shipping_courier_result(false, (string) ($createPayload['message'] ?? 'Unable to prepare Bigship create-order payload.'));
        }
        $responses['create_order_intent'] = [
            'state' => 'pending',
            'business_id' => trim((string) ($order['order_number'] ?? '')),
            'payload_hash' => hash('sha256', (string) json_encode($createPayload['body'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)),
            'started_at' => gmdate('c'),
        ];
        $existingMetadata = shipping_courier_upsert_metadata($conn, $orderId, $shipmentId, [
            'provider' => $provider,
            'raw_response_json' => $responses,
        ]);
        if (!$existingMetadata) {
            return shipping_courier_result(false, 'Unable to persist Bigship create-order intent.');
        }
        $createOrder = shipping_courier_bigship_client()->createOrder((array) $createPayload['body']);
        if (empty($createOrder['ok']) || !is_array($createOrder['body'] ?? null)) {
            $responses['create_order_intent']['state'] = 'outcome_unknown';
            $responses['create_order_intent']['provider_status'] = (int) ($createOrder['status'] ?? 0);
            shipping_courier_upsert_metadata($conn, $orderId, $shipmentId, [
                'provider' => $provider,
                'raw_response_json' => $responses,
            ]);
            $message = shipping_courier_api_failure_message($createOrder, 'create order');
            error_log('[shipping-courier] ' . $message . ' order_id=' . $orderId);
            return shipping_courier_result(false, $message, ['status' => (int) ($createOrder['status'] ?? 0)]);
        }
        $responses['create_order'] = $createOrder['body'];
        $customGlobalOrderId = shipping_courier_response_value($createOrder['body'], ['CustomGlobalOrderId', 'custom_global_order_id']);
        if ($customGlobalOrderId === '') {
            $responses['create_order_intent']['state'] = 'outcome_unknown';
            shipping_courier_upsert_metadata($conn, $orderId, $shipmentId, [
                'provider' => $provider,
                'raw_response_json' => $responses,
            ]);
            return shipping_courier_result(false, 'Bigship create-order did not return CustomGlobalOrderId.');
        }
        if (defined('AMBER_TESTING') && AMBER_TESTING && is_callable($GLOBALS['shipping_courier_bigship_fault_after_success'] ?? null)) {
            $GLOBALS['shipping_courier_bigship_fault_after_success']('create_order');
        }
        $responses['create_order_intent']['state'] = 'completed';
        $responses['create_order_intent']['completed_at'] = gmdate('c');
        $existingMetadata = shipping_courier_upsert_metadata($conn, $orderId, $shipmentId, shipping_courier_bigship_lifecycle_metadata($responses, $customGlobalOrderId));
    }

    if (in_array(($responses['place_order_intent']['state'] ?? ''), ['pending', 'outcome_unknown'], true)) {
        $details = shipping_courier_bigship_client()->shipmentDetails($customGlobalOrderId);
        $detailsBody = !empty($details['ok']) && is_array($details['body'] ?? null) ? $details['body'] : [];
        $detailsMetadata = shipping_courier_metadata_from_response($detailsBody);
        if (trim((string) ($detailsMetadata['provider_shipment_id'] ?? '')) === '') {
            return shipping_courier_result(false, 'Bigship place-order outcome is unknown; reconciliation did not confirm a shipment, so place-order was not repeated.');
        }
        $responses['place_order'] = $detailsBody;
        $responses['place_order_intent']['state'] = 'reconciled';
        $responses['place_order_intent']['completed_at'] = gmdate('c');
        $shipmentData = shipping_courier_shipment_data_from_response($detailsBody);
        $shipmentData = shipping_courier_apply_tracking_milestones($shipmentData, $detailsBody, $shipment);
        $shipmentData['courier_name'] = $shipmentData['courier_name'] ?? (string) ($order['courier_name'] ?? $provider);
        $shipmentData['shipping_cost'] = $shipmentData['shipping_cost'] ?? (float) ($order['base_shipping'] ?? 0);
        $shipment = shipping_courier_upsert_shipment($conn, $orderId, $shipmentData);
        $labelUrl = shipping_courier_bigship_download_document_url($customGlobalOrderId, 'label');
        if ($labelUrl !== '') {
            $responses['download_label'] = ['AttachmentData' => $labelUrl];
        }
        $metadata = shipping_courier_upsert_metadata($conn, $orderId, $shipmentId, shipping_courier_bigship_lifecycle_metadata($responses, $customGlobalOrderId));
        if (function_exists('log_order_activity')) {
            log_order_activity($conn, $orderId, 'shipping_courier_reconciled', 'system', 0, 'shipping-courier', 'Courier shipment reconciled with provider: ' . $provider);
        }
        shipping_courier_maybe_send_tracking_email($conn, $orderId, $previousTrackingId, $shipment);
        return shipping_courier_result(true, 'Courier shipment reconciled.', [
            'shipment' => $shipment,
            'metadata' => $metadata,
            'provider_response' => $responses,
        ]);
    }

    $cost = shipping_courier_bigship_client()->courierCosts([
        'MasterCustomOrderId' => $customGlobalOrderId,
    ]);
    if (empty($cost['ok']) || !is_array($cost['body'] ?? null)) {
        $message = shipping_courier_api_failure_message($cost, 'courier rate selection');
        error_log('[shipping-courier] ' . $message . ' order_id=' . $orderId);
        return shipping_courier_result(false, $message, ['status' => (int) ($cost['status'] ?? 0)]);
    }
    $responses['courier_wise_shipment_cost'] = $cost['body'];

    $settings = shipping_courier_settings();
    $segment = shipping_courier_bigship_segment($settings);
    $placePayload = [
        'MasterCustomOrderId' => $customGlobalOrderId,
        'courierId' => $courierId,
    ];
    if (in_array($segment, ['domestic_b2b', 'domestic_b2c'], true)) {
        $riskTypeId = shipping_courier_bigship_risk_type_id();
        $placePayload['riskTypeId'] = $riskTypeId;
        $invoiceType = trim((string) ($settings['bigship_invoice_type'] ?? ''));
        if ($invoiceType !== '') {
            $placePayload['invoiceType'] = $invoiceType;
        }
        $placePayload = array_merge($placePayload, shipping_courier_bigship_place_documents($orderId));
    }

    $responses['place_order_intent'] = [
        'state' => 'pending',
        'provider_order_id' => $customGlobalOrderId,
        'courier_id' => $courierId,
        'payload_hash' => hash('sha256', (string) json_encode($placePayload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)),
        'started_at' => gmdate('c'),
    ];
    $existingMetadata = shipping_courier_upsert_metadata($conn, $orderId, $shipmentId, shipping_courier_bigship_lifecycle_metadata($responses, $customGlobalOrderId));
    if (!$existingMetadata) {
        return shipping_courier_result(false, 'Unable to persist Bigship place-order intent.');
    }

    $placeOrder = shipping_courier_bigship_client()->placeOrder(
        $placePayload,
        in_array($segment, ['domestic_b2b', 'domestic_b2c'], true)
    );
    if (empty($placeOrder['ok']) || !is_array($placeOrder['body'] ?? null)) {
        $responses['place_order_intent']['state'] = 'outcome_unknown';
        $responses['place_order_intent']['provider_status'] = (int) ($placeOrder['status'] ?? 0);
        shipping_courier_upsert_metadata($conn, $orderId, $shipmentId, shipping_courier_bigship_lifecycle_metadata($responses, $customGlobalOrderId));
        $message = shipping_courier_api_failure_message($placeOrder, 'place order');
        error_log('[shipping-courier] ' . $message . ' order_id=' . $orderId);
        return shipping_courier_result(false, $message, ['status' => (int) ($placeOrder['status'] ?? 0)]);
    }
    $responses['place_order'] = $placeOrder['body'];
    if (defined('AMBER_TESTING') && AMBER_TESTING && is_callable($GLOBALS['shipping_courier_bigship_fault_after_success'] ?? null)) {
        $GLOBALS['shipping_courier_bigship_fault_after_success']('place_order');
    }
    $responses['place_order_intent']['state'] = 'completed';
    $responses['place_order_intent']['completed_at'] = gmdate('c');

    $body = $placeOrder['body'];
    $shipmentData = array_merge(
        shipping_courier_shipment_data_from_response($cost['body']),
        shipping_courier_shipment_data_from_response($body)
    );
    $shipmentData = shipping_courier_apply_tracking_milestones($shipmentData, $body, $shipment);
    $shipmentData['courier_name'] = $shipmentData['courier_name'] ?? (string) ($order['courier_name'] ?? $provider);
    $shipmentData['shipping_cost'] = $shipmentData['shipping_cost'] ?? (float) ($order['base_shipping'] ?? 0);
    $shipment = shipping_courier_upsert_shipment($conn, $orderId, $shipmentData);
    $labelUrl = shipping_courier_bigship_download_document_url($customGlobalOrderId, 'label');
    if ($labelUrl !== '') {
        $responses['download_label'] = ['AttachmentData' => $labelUrl];
    }
    $metadata = shipping_courier_upsert_metadata($conn, $orderId, $shipmentId, shipping_courier_bigship_lifecycle_metadata($responses, $customGlobalOrderId));

    if (function_exists('log_order_activity')) {
        log_order_activity($conn, $orderId, 'shipping_courier_created', 'system', 0, 'shipping-courier', 'Courier shipment created via provider: ' . $provider);
    }
    shipping_courier_maybe_send_tracking_email($conn, $orderId, $previousTrackingId, $shipment);

    return shipping_courier_result(true, 'Courier shipment created.', [
        'shipment' => $shipment,
        'metadata' => $metadata,
        'provider_response' => $responses,
    ]);
}

function shipping_courier_sync_tracking(mysqli $conn, int $orderId): array
{
    if (!shipping_courier_enabled()) {
        return shipping_courier_result(false, 'Shipping courier plugin is disabled.');
    }
    if (!shipping_courier_provider_configured()) {
        return shipping_courier_result(false, 'Shipping courier provider is not configured.');
    }

    $provider = shipping_courier_provider_name();
    $shipment = shipping_courier_get_shipment($conn, $orderId);
    $shipmentId = (int) ($shipment['id'] ?? 0);
    if ($shipmentId <= 0) {
        return shipping_courier_result(false, 'No shipment exists for tracking sync.');
    }
    $previousTrackingId = trim((string) ($shipment['tracking_id'] ?? ''));

    $metadata = shipping_courier_get_metadata($conn, $shipmentId, $provider) ?: [];
    $previousProviderStatus = shipping_courier_normalize_provider_status((string) ($metadata['provider_status'] ?? ''));
    $previousShippedAt = trim((string) ($shipment['shipped_at'] ?? ''));
    $previousDeliveredAt = trim((string) ($shipment['delivered_at'] ?? ''));
    $customGlobalOrderId = trim((string) ($metadata['provider_order_id'] ?? ''));
    if ($customGlobalOrderId === '') {
        return shipping_courier_result(false, 'No Bigship CustomGlobalOrderId is available for tracking sync.');
    }

    $detailsResponse = shipping_courier_bigship_client()->shipmentDetails($customGlobalOrderId);
    $detailsBody = !empty($detailsResponse['ok']) && is_array($detailsResponse['body'] ?? null)
        ? (array) $detailsResponse['body']
        : [];
    $trackSegment = shipping_courier_response_value($detailsBody, ['segment_type']);
    $response = shipping_courier_bigship_client()->trackOrder($customGlobalOrderId, 0, $trackSegment);
    $trackingBody = !empty($response['ok']) && is_array($response['body'] ?? null)
        ? (array) $response['body']
        : [];
    if ($trackingBody === [] && $detailsBody === []) {
        error_log('[shipping-courier] tracking sync failed for order ' . $orderId . ': ' . (string) ($response['message'] ?? 'unknown'));
        return $response;
    }
    $body = $trackingBody !== []
        ? array_replace_recursive($detailsBody, $trackingBody)
        : $detailsBody;
    $shipmentData = shipping_courier_shipment_data_from_response($body);
    $shipmentData = shipping_courier_apply_tracking_milestones($shipmentData, $body, $shipment);
    if (!empty($shipmentData)) {
        $shipment = shipping_courier_upsert_shipment($conn, $orderId, $shipmentData);
    }
    $trackingMetadata = shipping_courier_metadata_from_response($body);
    $trackingMetadata['provider_order_id'] = $customGlobalOrderId;
    $rawResponses = $metadata['raw_response_json'] ?? null;
    if (is_string($rawResponses)) {
        $rawResponses = json_decode($rawResponses, true);
    }
    if (!is_array($rawResponses)) {
        $rawResponses = [];
    }
    if ($trackingBody !== []) {
        $rawResponses['track_order'] = $trackingBody;
    }
    if ($detailsBody !== []) {
        $rawResponses['order_shipment_details'] = $detailsBody;
    }
    $trackingMetadata['raw_response_json'] = $rawResponses;
    $metadata = shipping_courier_upsert_metadata($conn, $orderId, $shipmentId, $trackingMetadata);
    $localOrderStatus = shipping_courier_apply_bigship_order_status(
        $conn,
        $orderId,
        (string) ($trackingMetadata['provider_status'] ?? '')
    );
    if (function_exists('log_order_activity')) {
        $status = is_array($metadata) ? shipping_courier_normalize_provider_status((string) ($metadata['provider_status'] ?? '')) : '';
        $newShippedAt = trim((string) ($shipment['shipped_at'] ?? ''));
        $newDeliveredAt = trim((string) ($shipment['delivered_at'] ?? ''));
        $changes = [];
        if ($status !== '' && $status !== $previousProviderStatus) {
            $changes[] = 'Provider status: ' . ($previousProviderStatus !== '' ? $previousProviderStatus : '-') . ' -> ' . $status;
        }
        if ($previousShippedAt === '' && $newShippedAt !== '') {
            $changes[] = 'Shipped at: ' . $newShippedAt;
        }
        if ($previousDeliveredAt === '' && $newDeliveredAt !== '') {
            $changes[] = 'Delivered at: ' . $newDeliveredAt;
        }
        if ($localOrderStatus !== '') {
            $changes[] = 'Order status: ' . $localOrderStatus;
        }
        if (!empty($changes)) {
            log_order_activity(
                $conn,
                $orderId,
                'shipping_courier_tracking_synced',
                'system',
                0,
                'shipping-courier',
                implode(' | ', $changes)
            );
        }
    }
    shipping_courier_maybe_send_tracking_email($conn, $orderId, $previousTrackingId, $shipment);

    return shipping_courier_result(true, 'Courier tracking synced.', [
        'shipment' => $shipment,
        'metadata' => $metadata,
        'provider_response' => $body,
    ]);
}

function shipping_courier_cancel_shipment(mysqli $conn, int $orderId): array
{
    if (!shipping_courier_enabled()) {
        return shipping_courier_result(false, 'Shipping courier plugin is disabled.');
    }
    if (!shipping_courier_provider_configured()) {
        return shipping_courier_result(false, 'Shipping courier provider is not configured.');
    }

    $provider = shipping_courier_provider_name();
    $shipment = shipping_courier_get_shipment($conn, $orderId);
    $shipmentId = (int) ($shipment['id'] ?? 0);
    if ($shipmentId <= 0) {
        return shipping_courier_result(false, 'No shipment exists to cancel.');
    }

    $metadata = shipping_courier_get_metadata($conn, $shipmentId, $provider) ?: [];
    $customGlobalOrderId = trim((string) ($metadata['provider_order_id'] ?? ''));
    if ($customGlobalOrderId === '') {
        return shipping_courier_result(false, 'No Bigship CustomGlobalOrderId is available for cancellation.');
    }

    $payload = shipping_courier_order_payload($conn, $orderId);
    $order = is_array($payload['order'] ?? null) ? $payload['order'] : [];
    if (!shipping_courier_can_cancel_from_order($order, $metadata)) {
        return shipping_courier_result(false, 'Courier shipment cannot be cancelled for this order state.');
    }

    $response = shipping_courier_bigship_client()->cancelOrder([
        'CustomGlobalOrderId' => $customGlobalOrderId,
    ]);
    if (empty($response['ok'])) {
        error_log('[shipping-courier] cancel shipment failed for order ' . $orderId . ': ' . (string) ($response['message'] ?? 'unknown'));
        return $response;
    }

    $body = is_array($response['body'] ?? null) ? $response['body'] : [];
    $metadata = shipping_courier_upsert_metadata($conn, $orderId, $shipmentId, array_merge(
        shipping_courier_metadata_from_response($body),
        ['provider_status' => shipping_courier_response_value($body, ['provider_status', 'status', 'shipment_status']) ?: 'cancelled']
    ));

    if (function_exists('log_order_activity')) {
        log_order_activity($conn, $orderId, 'shipping_courier_cancelled', 'system', 0, 'shipping-courier', 'Courier shipment cancellation requested via provider: ' . $provider);
    }

    return shipping_courier_result(true, 'Courier shipment cancelled.', [
        'shipment' => $shipment,
        'metadata' => $metadata,
        'provider_response' => $body,
    ]);
}

function shipping_courier_provider_shipment_exists(?array $metadata): bool
{
    return is_array($metadata)
        && trim((string) ($metadata['provider_shipment_id'] ?? '')) !== '';
}

function shipping_courier_provider_status(?array $metadata): string
{
    return strtolower(trim((string) ($metadata['provider_status'] ?? '')));
}

function shipping_courier_can_create_from_order(array $order, ?array $metadata): bool
{
    return shipping_courier_enabled()
        && shipping_courier_provider_configured()
        && shipping_courier_order_ready_for_shipment($order)
        && !shipping_courier_provider_shipment_exists($metadata);
}

function shipping_courier_can_sync_tracking(?array $shipment, ?array $metadata): bool
{
    if (!shipping_courier_enabled() || !shipping_courier_provider_configured() || empty(shipping_courier_settings()['tracking_sync'])) {
        return false;
    }

    $trackingId = trim((string) ($shipment['tracking_id'] ?? ''));
    return shipping_courier_provider_shipment_exists($metadata) || $trackingId !== '';
}

function shipping_courier_can_cancel_from_order(array $order, ?array $metadata): bool
{
    if (!shipping_courier_enabled() || !shipping_courier_provider_configured() || !shipping_courier_provider_shipment_exists($metadata)) {
        return false;
    }

    $orderStatus = strtolower((string) ($order['order_status'] ?? ''));
    $providerStatus = shipping_courier_provider_status($metadata);
    if (in_array($orderStatus, ['delivered', 'cancelled', 'returned', 'refunded'], true)) {
        return false;
    }

    $providerLockedStatuses = [
        'rider_assigned',
        'rider_accepted',
        'pickup_assigned',
        'picked_up',
        'pickup_done',
        'in_transit',
        'out_for_delivery',
        'shipped',
        'delivered',
        'cancelled',
        'canceled',
        'rto',
        'returned',
    ];
    return !in_array($providerStatus, $providerLockedStatuses, true);
}
