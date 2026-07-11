<?php

add_action('app.init', 'shipping_courier_on_app_init', 30);
add_action('admin.order_view.sidebar', 'shipping_courier_render_admin_panel', 30);
add_filter('admin.order_action.handled', 'shipping_courier_handle_admin_action', 20);
add_action('shipment.after_save', 'shipping_courier_after_shipment_save', 10);
add_action('order.after_commit', 'shipping_courier_after_order_commit', 30);
add_action('order.after_payment_success', 'shipping_courier_after_payment_success', 30);
add_action('cron.tick', 'shipping_courier_cron_tracking_sync', 35);
add_filter('admin.return_action.handled', 'shipping_courier_handle_admin_return_action', 20);
add_action('admin.return_row.actions', 'shipping_courier_render_return_actions', 20);
add_filter('shipping.quote', 'shipping_courier_filter_shipping_quote', 20);
add_action('admin.shipping_rates.after', 'shipping_courier_render_shipping_rates_status', 20);

function shipping_courier_settings(): array
{
    return [
        'enabled' => (int) plugin_setting('shipping-courier', 'enabled', 0),
        'provider' => strtolower(trim((string) plugin_setting('shipping-courier', 'provider', ''))),
        'test_mode' => (int) plugin_setting('shipping-courier', 'test_mode', 1),
        'auto_create' => (int) plugin_setting('shipping-courier', 'auto_create', 0),
        'tracking_sync' => (int) plugin_setting('shipping-courier', 'tracking_sync', 1),
        'webhook_secret' => trim((string) plugin_setting('shipping-courier', 'webhook_secret', '')),
        'api_base_url' => rtrim(trim((string) plugin_setting('shipping-courier', 'api_base_url', '')), '/'),
        'api_key' => trim((string) plugin_setting('shipping-courier', 'api_key', '')),
        'api_secret' => trim((string) plugin_setting('shipping-courier', 'api_secret', '')),
    ];
}

function shipping_courier_enabled(): bool
{
    $settings = shipping_courier_settings();
    return (int) ($settings['enabled'] ?? 0) === 1;
}

function shipping_courier_provider_configured(): bool
{
    $settings = shipping_courier_settings();
    return (string) ($settings['provider'] ?? '') !== ''
        && (string) ($settings['api_base_url'] ?? '') !== ''
        && (string) ($settings['api_key'] ?? '') !== '';
}

function shipping_courier_result(bool $ok, string $message = '', array $data = []): array
{
    return array_merge([
        'ok' => $ok,
        'message' => $message,
    ], $data);
}

function shipping_courier_filter_shipping_quote($quote, array $context)
{
    if (!is_array($quote) || !shipping_courier_enabled() || !shipping_courier_provider_configured()) {
        return $quote;
    }

    $country = trim((string) ($context['country'] ?? 'India'));
    $pincode = trim((string) ($context['pincode'] ?? ''));
    $subtotal = max(0.0, (float) ($context['subtotal'] ?? 0));
    $paymentMethod = strtolower(trim((string) ($context['payment_method'] ?? 'cod')));
    if (strcasecmp($country, 'India') !== 0 || !preg_match('/^[1-9][0-9]{5}$/', $pincode) || $subtotal <= 0) {
        return $quote;
    }

    $response = shipping_courier_http_json('POST', '/rates', [
        'country' => 'India',
        'pincode' => $pincode,
        'subtotal' => round($subtotal, 2),
        'payment_method' => $paymentMethod,
        'manual_quote' => [
            'base_shipping' => (float) ($quote['base_shipping'] ?? 0),
            'cod_fee' => (float) ($quote['cod_fee'] ?? 0),
            'shipping_total' => (float) ($quote['shipping_total'] ?? 0),
        ],
    ]);
    if (empty($response['ok']) || !is_array($response['body'] ?? null)) {
        return $quote;
    }

    $body = $response['body'];
    $baseShippingRaw = shipping_courier_response_value($body, ['base_shipping', 'shipping_cost', 'freight_charge', 'rate']);
    if ($baseShippingRaw === '' || !is_numeric($baseShippingRaw)) {
        return $quote;
    }

    $baseShipping = max(0.0, round((float) $baseShippingRaw, 2));
    $codFee = max(0.0, round((float) ($quote['cod_fee'] ?? 0), 2));
    $courierName = shipping_courier_response_value($body, ['courier_name', 'courier', 'carrier_name', 'provider']);
    $courierIdRaw = shipping_courier_response_value($body, ['courier_id', 'carrier_id', 'service_id']);
    $provider = shipping_courier_provider_name();

    return [
        'base_shipping' => $baseShipping,
        'cod_fee' => $codFee,
        'shipping_total' => round($baseShipping + $codFee, 2),
        'source' => substr($provider !== '' ? $provider : 'courier', 0, 32),
        'courier_name' => $courierName !== '' ? $courierName : $provider,
        'courier_id' => is_numeric($courierIdRaw) ? max(0, (int) $courierIdRaw) : 0,
    ];
}

function shipping_courier_shipment_columns(): array
{
    return [
        'order_id',
        'awb_code',
        'courier_name',
        'tracking_id',
        'tracking_url',
        'shipping_cost',
        'shipped_at',
        'delivered_at',
    ];
}

function shipping_courier_empty_shipment(int $orderId): array
{
    return [
        'order_id' => $orderId,
        'awb_code' => '',
        'courier_name' => '',
        'tracking_id' => '',
        'tracking_url' => '',
        'shipping_cost' => 0.0,
        'shipped_at' => null,
        'delivered_at' => null,
    ];
}

function shipping_courier_get_shipment(mysqli $conn, int $orderId): ?array
{
    if ($orderId <= 0) {
        return null;
    }

    $stmt = $conn->prepare(
        "SELECT id, order_id, awb_code, courier_name, tracking_id, tracking_url, shipping_cost, shipped_at, delivered_at
         FROM shipments
         WHERE order_id = ?
         LIMIT 1"
    );
    $stmt->bind_param('i', $orderId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    return $row ?: null;
}

function shipping_courier_normalize_shipment_value(string $key, $value)
{
    if (in_array($key, ['awb_code', 'courier_name', 'tracking_id'], true)) {
        return trim((string) $value);
    }

    if ($key === 'tracking_url') {
        return InventoryService::safe_external_url((string) $value);
    }

    if ($key === 'shipping_cost') {
        return max(0.0, round((float) $value, 2));
    }

    if (in_array($key, ['shipped_at', 'delivered_at'], true)) {
        $value = trim((string) $value);
        return $value !== '' ? $value : null;
    }

    return $value;
}

function shipping_courier_upsert_shipment(mysqli $conn, int $orderId, array $shipmentData): array
{
    if ($orderId <= 0) {
        throw new RuntimeException('Invalid order id for courier shipment update.');
    }

    $existing = shipping_courier_get_shipment($conn, $orderId) ?: shipping_courier_empty_shipment($orderId);
    $shipment = $existing;
    foreach (shipping_courier_shipment_columns() as $column) {
        if ($column === 'order_id') {
            continue;
        }
        if (array_key_exists($column, $shipmentData)) {
            $shipment[$column] = shipping_courier_normalize_shipment_value($column, $shipmentData[$column]);
        }
    }

    $stmt = $conn->prepare(
        "INSERT INTO shipments
            (order_id, awb_code, courier_name, tracking_id, tracking_url, shipping_cost, shipped_at, delivered_at)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE
            awb_code = VALUES(awb_code),
            courier_name = VALUES(courier_name),
            tracking_id = VALUES(tracking_id),
            tracking_url = VALUES(tracking_url),
            shipping_cost = VALUES(shipping_cost),
            shipped_at = VALUES(shipped_at),
            delivered_at = VALUES(delivered_at)"
    );
    $awbCode = (string) ($shipment['awb_code'] ?? '');
    $courierName = (string) ($shipment['courier_name'] ?? '');
    $trackingId = (string) ($shipment['tracking_id'] ?? '');
    $trackingUrl = (string) ($shipment['tracking_url'] ?? '');
    $shippingCost = (float) ($shipment['shipping_cost'] ?? 0);
    $shippedAt = $shipment['shipped_at'] ?? null;
    $deliveredAt = $shipment['delivered_at'] ?? null;
    $stmt->bind_param('issssdss', $orderId, $awbCode, $courierName, $trackingId, $trackingUrl, $shippingCost, $shippedAt, $deliveredAt);
    $stmt->execute();

    return shipping_courier_get_shipment($conn, $orderId) ?: $shipment;
}

function shipping_courier_metadata_table_ready(mysqli $conn): bool
{
    try {
        $stmt = $conn->prepare(
            "SELECT COUNT(*) AS total
             FROM information_schema.TABLES
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = 'shipping_courier_shipments'"
        );
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        return ((int) ($row['total'] ?? 0)) > 0;
    } catch (Throwable $e) {
        error_log('[shipping-courier] metadata table check failed: ' . $e->getMessage());
        return false;
    }
}

function shipping_courier_provider_name(array $metadata = []): string
{
    $provider = strtolower(trim((string) ($metadata['provider'] ?? '')));
    if ($provider !== '') {
        return $provider;
    }

    $settings = shipping_courier_settings();
    return strtolower(trim((string) ($settings['provider'] ?? '')));
}

function shipping_courier_json_value($value): ?string
{
    if ($value === null || $value === '') {
        return null;
    }

    if (is_string($value)) {
        json_decode($value, true);
        return json_last_error() === JSON_ERROR_NONE ? $value : json_encode(['raw' => $value], JSON_UNESCAPED_SLASHES);
    }

    $json = json_encode($value, JSON_UNESCAPED_SLASHES);
    return is_string($json) ? $json : null;
}

function shipping_courier_get_metadata(mysqli $conn, int $shipmentId, string $provider): ?array
{
    $provider = strtolower(trim($provider));
    if ($shipmentId <= 0 || $provider === '' || !shipping_courier_metadata_table_ready($conn)) {
        return null;
    }

    $stmt = $conn->prepare(
        "SELECT id, order_id, shipment_id, provider, provider_order_id, provider_shipment_id,
                provider_status, label_url, raw_response_json, created_at, updated_at
         FROM shipping_courier_shipments
         WHERE shipment_id = ? AND provider = ?
         LIMIT 1"
    );
    $stmt->bind_param('is', $shipmentId, $provider);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    return $row ?: null;
}

function shipping_courier_upsert_metadata(mysqli $conn, int $orderId, int $shipmentId, array $metadata): ?array
{
    $provider = shipping_courier_provider_name($metadata);
    if ($orderId <= 0 || $shipmentId <= 0 || $provider === '' || !shipping_courier_metadata_table_ready($conn)) {
        return null;
    }

    $providerOrderId = trim((string) ($metadata['provider_order_id'] ?? ''));
    $providerShipmentId = trim((string) ($metadata['provider_shipment_id'] ?? ''));
    $providerStatus = trim((string) ($metadata['provider_status'] ?? ''));
    $labelUrl = InventoryService::safe_external_url((string) ($metadata['label_url'] ?? ''));
    $rawResponseJson = shipping_courier_json_value($metadata['raw_response_json'] ?? null);

    $stmt = $conn->prepare(
        "INSERT INTO shipping_courier_shipments
            (order_id, shipment_id, provider, provider_order_id, provider_shipment_id, provider_status, label_url, raw_response_json)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE
            order_id = VALUES(order_id),
            provider_order_id = COALESCE(NULLIF(VALUES(provider_order_id), ''), provider_order_id),
            provider_shipment_id = COALESCE(NULLIF(VALUES(provider_shipment_id), ''), provider_shipment_id),
            provider_status = COALESCE(NULLIF(VALUES(provider_status), ''), provider_status),
            label_url = COALESCE(NULLIF(VALUES(label_url), ''), label_url),
            raw_response_json = COALESCE(VALUES(raw_response_json), raw_response_json),
            updated_at = NOW()"
    );
    $stmt->bind_param(
        'iissssss',
        $orderId,
        $shipmentId,
        $provider,
        $providerOrderId,
        $providerShipmentId,
        $providerStatus,
        $labelUrl,
        $rawResponseJson
    );
    $stmt->execute();

    return shipping_courier_get_metadata($conn, $shipmentId, $provider);
}

function shipping_courier_http_json(string $method, string $path, array $payload = [], array $headers = []): array
{
    if (!shipping_courier_enabled()) {
        return shipping_courier_result(false, 'Shipping courier plugin is disabled.', ['status' => 0, 'body' => null]);
    }
    if (!shipping_courier_provider_configured()) {
        return shipping_courier_result(false, 'Shipping courier provider is not configured.', ['status' => 0, 'body' => null]);
    }
    if (!function_exists('curl_init')) {
        return shipping_courier_result(false, 'cURL is unavailable.', ['status' => 0, 'body' => null]);
    }

    $settings = shipping_courier_settings();
    $baseUrl = (string) ($settings['api_base_url'] ?? '');
    $url = preg_match('/^https?:\/\//i', $path) ? $path : ($baseUrl . '/' . ltrim($path, '/'));
    if (InventoryService::safe_external_url($url) === '') {
        return shipping_courier_result(false, 'Shipping courier API URL is invalid.', ['status' => 0, 'body' => null]);
    }

    $method = strtoupper(trim($method));
    if (!in_array($method, ['GET', 'POST', 'PUT', 'PATCH', 'DELETE'], true)) {
        return shipping_courier_result(false, 'Shipping courier HTTP method is invalid.', ['status' => 0, 'body' => null]);
    }

    $requestHeaders = array_merge([
        'Accept: application/json',
        'Content-Type: application/json',
        'X-API-Key: ' . (string) ($settings['api_key'] ?? ''),
    ], $headers);
    if ((string) ($settings['api_secret'] ?? '') !== '') {
        $requestHeaders[] = 'X-API-Secret: ' . (string) ($settings['api_secret'] ?? '');
    }
    if (!empty($settings['test_mode'])) {
        $requestHeaders[] = 'X-Test-Mode: 1';
    }

    $ch = curl_init($url);
    if ($ch === false) {
        return shipping_courier_result(false, 'Unable to initialize courier API request.', ['status' => 0, 'body' => null]);
    }

    $options = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT => 25,
        CURLOPT_HTTPHEADER => $requestHeaders,
        CURLOPT_CUSTOMREQUEST => $method,
    ];
    if ($method !== 'GET') {
        $json = json_encode($payload, JSON_UNESCAPED_SLASHES);
        if (!is_string($json)) {
            curl_close($ch);
            return shipping_courier_result(false, 'Unable to encode courier API payload.', ['status' => 0, 'body' => null]);
        }
        $options[CURLOPT_POSTFIELDS] = $json;
    }
    curl_setopt_array($ch, $options);

    $raw = curl_exec($ch);
    $errno = curl_errno($ch);
    $error = curl_error($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);

    if ($errno !== 0) {
        return shipping_courier_result(false, 'Courier API request failed: ' . $error, [
            'status' => $status,
            'body' => null,
        ]);
    }

    $body = json_decode((string) $raw, true);
    $decodedBody = is_array($body) ? $body : null;
    $ok = $status >= 200 && $status < 300;
    return shipping_courier_result($ok, $ok ? 'Courier API request successful.' : ('Courier API returned HTTP ' . $status), [
        'status' => $status,
        'body' => $decodedBody,
        'raw_body' => is_string($raw) ? $raw : '',
    ]);
}

function shipping_courier_order_ready_for_shipment(array $order): bool
{
    $orderStatus = strtolower((string) ($order['order_status'] ?? ''));
    $paymentMethod = strtolower((string) ($order['payment_method'] ?? ''));
    $paymentStatus = strtolower((string) ($order['payment_status'] ?? 'pending'));
    if (!in_array($orderStatus, ['confirmed', 'packed', 'shipped'], true)) {
        return false;
    }
    if (in_array($paymentMethod, ['razorpay', 'upi'], true) && $paymentStatus !== 'paid') {
        return false;
    }
    return true;
}

function shipping_courier_auto_create_enabled(): bool
{
    return shipping_courier_enabled() && !empty(shipping_courier_settings()['auto_create']);
}

function shipping_courier_is_prepaid_method(string $paymentMethod): bool
{
    return in_array(strtolower(trim($paymentMethod)), ['razorpay', 'upi'], true);
}

function shipping_courier_order_confirmed_for_auto_create(array $order): bool
{
    $orderStatus = strtolower((string) ($order['order_status'] ?? ''));
    return in_array($orderStatus, ['confirmed', 'packed', 'shipped'], true);
}

function shipping_courier_can_auto_create_after_commit(array $order): bool
{
    if (!shipping_courier_order_confirmed_for_auto_create($order)) {
        return false;
    }

    $paymentMethod = strtolower((string) ($order['payment_method'] ?? ''));
    if (shipping_courier_is_prepaid_method($paymentMethod)) {
        return false;
    }

    return shipping_courier_order_ready_for_shipment($order);
}

function shipping_courier_can_auto_create_after_payment_success(array $order): bool
{
    $paymentMethod = strtolower((string) ($order['payment_method'] ?? ''));
    if (!shipping_courier_is_prepaid_method($paymentMethod)) {
        return false;
    }

    return shipping_courier_order_ready_for_shipment($order);
}

function shipping_courier_order_payload(mysqli $conn, int $orderId): ?array
{
    if ($orderId <= 0) {
        return null;
    }

    $orderStmt = $conn->prepare(
        "SELECT id, order_number, customer_name, customer_phone, customer_email,
                address, city, state, pincode, country,
                subtotal, shipping_amount, discount_amount, total_amount,
                payment_method, payment_status, order_status, created_at
         FROM orders
         WHERE id = ?
         LIMIT 1"
    );
    $orderStmt->bind_param('i', $orderId);
    $orderStmt->execute();
    $order = $orderStmt->get_result()->fetch_assoc();
    if (!$order) {
        return null;
    }

    $itemStmt = $conn->prepare(
        "SELECT product_name, fabric_name_snapshot, fabric_sku_snapshot, size, color,
                unit_type, quantity, quantity_meters, price, price_per_meter, total, line_total
         FROM order_items
         WHERE order_id = ?
         ORDER BY id ASC"
    );
    $itemStmt->bind_param('i', $orderId);
    $itemStmt->execute();
    $items = $itemStmt->get_result()->fetch_all(MYSQLI_ASSOC);

    return [
        'order' => $order,
        'items' => is_array($items) ? $items : [],
        'shipment' => shipping_courier_get_shipment($conn, $orderId) ?: shipping_courier_empty_shipment($orderId),
    ];
}

function shipping_courier_response_value(array $body, array $keys): string
{
    foreach ($keys as $key) {
        if (isset($body[$key]) && is_scalar($body[$key]) && trim((string) $body[$key]) !== '') {
            return trim((string) $body[$key]);
        }
    }

    foreach (['data', 'shipment', 'order'] as $container) {
        if (is_array($body[$container] ?? null)) {
            $value = shipping_courier_response_value($body[$container], $keys);
            if ($value !== '') {
                return $value;
            }
        }
    }

    return '';
}

function shipping_courier_normalize_provider_status(string $status): string
{
    $status = strtolower(trim($status));
    $status = str_replace([' ', '-'], '_', $status);
    return preg_replace('/_+/', '_', $status) ?: '';
}

function shipping_courier_response_timestamp(array $body, array $keys): ?string
{
    $value = shipping_courier_response_value($body, $keys);
    if ($value === '') {
        return null;
    }

    $time = strtotime($value);
    return $time !== false ? date('Y-m-d H:i:s', $time) : null;
}

function shipping_courier_status_confirms_shipped(string $providerStatus): bool
{
    $status = shipping_courier_normalize_provider_status($providerStatus);
    return in_array($status, [
        'picked_up',
        'pickup_done',
        'in_transit',
        'out_for_delivery',
        'shipped',
        'delivered',
    ], true);
}

function shipping_courier_status_confirms_delivered(string $providerStatus): bool
{
    return in_array(shipping_courier_normalize_provider_status($providerStatus), ['delivered', 'delivery_done'], true);
}

function shipping_courier_shipment_data_from_response(array $body): array
{
    $shippingCost = shipping_courier_response_value($body, ['shipping_cost', 'freight_charge', 'rate']);
    return array_filter([
        'awb_code' => shipping_courier_response_value($body, ['awb_code', 'awb', 'waybill']),
        'courier_name' => shipping_courier_response_value($body, ['courier_name', 'courier', 'carrier_name', 'provider']),
        'tracking_id' => shipping_courier_response_value($body, ['tracking_id', 'tracking_number', 'awb_code', 'awb', 'waybill']),
        'tracking_url' => shipping_courier_response_value($body, ['tracking_url', 'track_url']),
        'shipping_cost' => $shippingCost !== '' ? (float) $shippingCost : null,
        'shipped_at' => shipping_courier_response_timestamp($body, ['shipped_at', 'pickup_at', 'picked_up_at', 'shipped_on']),
        'delivered_at' => shipping_courier_response_timestamp($body, ['delivered_at', 'delivered_on', 'delivery_at']),
    ], static fn($value) => $value !== null && $value !== '');
}

function shipping_courier_apply_tracking_milestones(array $shipmentData, array $body, array $currentShipment): array
{
    $metadata = shipping_courier_metadata_from_response($body);
    $providerStatus = shipping_courier_normalize_provider_status((string) ($metadata['provider_status'] ?? ''));
    $now = date('Y-m-d H:i:s');
    $providerShippedAt = shipping_courier_response_timestamp($body, ['shipped_at', 'pickup_at', 'picked_up_at', 'shipped_on']);
    $providerDeliveredAt = shipping_courier_response_timestamp($body, ['delivered_at', 'delivered_on', 'delivery_at']);
    $currentShippedAt = trim((string) ($currentShipment['shipped_at'] ?? ''));
    $currentDeliveredAt = trim((string) ($currentShipment['delivered_at'] ?? ''));

    if (shipping_courier_status_confirms_shipped($providerStatus) && $currentShippedAt === '' && empty($shipmentData['shipped_at'])) {
        $shipmentData['shipped_at'] = $providerShippedAt ?: ($providerDeliveredAt ?: $now);
    }

    if (shipping_courier_status_confirms_delivered($providerStatus) && $currentDeliveredAt === '' && empty($shipmentData['delivered_at'])) {
        $deliveredAt = $providerDeliveredAt ?: $now;
        $shipmentData['delivered_at'] = $deliveredAt;
        if ($currentShippedAt === '' && empty($shipmentData['shipped_at'])) {
            $shipmentData['shipped_at'] = $providerShippedAt ?: $deliveredAt;
        }
    }

    return $shipmentData;
}

function shipping_courier_metadata_from_response(array $body): array
{
    return [
        'provider_order_id' => shipping_courier_response_value($body, ['provider_order_id', 'order_id', 'courier_order_id']),
        'provider_shipment_id' => shipping_courier_response_value($body, ['provider_shipment_id', 'shipment_id', 'courier_shipment_id']),
        'provider_status' => shipping_courier_normalize_provider_status(shipping_courier_response_value($body, ['provider_status', 'status', 'shipment_status'])),
        'label_url' => shipping_courier_response_value($body, ['label_url', 'label', 'shipping_label_url']),
        'raw_response_json' => $body,
    ];
}

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

function shipping_courier_create_shipment(mysqli $conn, int $orderId, array $options = []): array
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
    $existingShipment = shipping_courier_get_shipment($conn, $orderId);
    $previousTrackingId = trim((string) ($existingShipment['tracking_id'] ?? ''));
    $existingShipmentId = (int) ($existingShipment['id'] ?? 0);
    if ($existingShipmentId > 0) {
        $existingMetadata = shipping_courier_get_metadata($conn, $existingShipmentId, $provider);
        if (!empty($existingMetadata['provider_shipment_id'])) {
            return shipping_courier_result(true, 'Courier shipment already exists.', [
                'shipment' => $existingShipment,
                'metadata' => $existingMetadata,
            ]);
        }
    }

    $response = shipping_courier_http_json('POST', '/shipments', array_merge($payload, ['options' => $options]));
    if (empty($response['ok'])) {
        error_log('[shipping-courier] create shipment skipped/failed for order ' . $orderId . ': ' . (string) ($response['message'] ?? 'unknown'));
        return $response;
    }

    $body = is_array($response['body'] ?? null) ? $response['body'] : [];
    $shipmentData = shipping_courier_shipment_data_from_response($body);
    $shipmentData = shipping_courier_apply_tracking_milestones($shipmentData, $body, $existingShipment ?: []);
    if (empty($shipmentData['courier_name'])) {
        $shipmentData['courier_name'] = $provider;
    }
    $shipment = shipping_courier_upsert_shipment($conn, $orderId, $shipmentData);
    $shipmentId = (int) ($shipment['id'] ?? 0);
    $metadata = $shipmentId > 0
        ? shipping_courier_upsert_metadata($conn, $orderId, $shipmentId, shipping_courier_metadata_from_response($body))
        : null;

    if (function_exists('log_order_activity')) {
        log_order_activity($conn, $orderId, 'shipping_courier_created', 'system', 0, 'shipping-courier', 'Courier shipment created via provider: ' . $provider);
    }
    shipping_courier_maybe_send_tracking_email($conn, $orderId, $previousTrackingId, $shipment);

    return shipping_courier_result(true, 'Courier shipment created.', [
        'shipment' => $shipment,
        'metadata' => $metadata,
        'provider_response' => $body,
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
    $providerShipmentId = trim((string) ($metadata['provider_shipment_id'] ?? ''));
    $trackingId = trim((string) ($shipment['tracking_id'] ?? ''));
    $ref = $providerShipmentId !== '' ? $providerShipmentId : $trackingId;
    if ($ref === '') {
        return shipping_courier_result(false, 'No provider shipment id or tracking id is available for sync.');
    }

    $response = shipping_courier_http_json('GET', '/shipments/' . rawurlencode($ref) . '/tracking');
    if (empty($response['ok'])) {
        error_log('[shipping-courier] tracking sync failed for order ' . $orderId . ': ' . (string) ($response['message'] ?? 'unknown'));
        return $response;
    }

    $body = is_array($response['body'] ?? null) ? $response['body'] : [];
    $shipmentData = shipping_courier_shipment_data_from_response($body);
    $shipmentData = shipping_courier_apply_tracking_milestones($shipmentData, $body, $shipment);
    if (!empty($shipmentData)) {
        $shipment = shipping_courier_upsert_shipment($conn, $orderId, $shipmentData);
    }
    $metadata = shipping_courier_upsert_metadata($conn, $orderId, $shipmentId, shipping_courier_metadata_from_response($body));
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
    $providerShipmentId = trim((string) ($metadata['provider_shipment_id'] ?? ''));
    if ($providerShipmentId === '') {
        return shipping_courier_result(false, 'No provider shipment id is available for cancellation.');
    }

    $payload = shipping_courier_order_payload($conn, $orderId);
    $order = is_array($payload['order'] ?? null) ? $payload['order'] : [];
    if (!shipping_courier_can_cancel_from_order($order, $metadata)) {
        return shipping_courier_result(false, 'Courier shipment cannot be cancelled for this order state.');
    }

    $response = shipping_courier_http_json('POST', '/shipments/' . rawurlencode($providerShipmentId) . '/cancel');
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
    return is_array($metadata) && trim((string) ($metadata['provider_shipment_id'] ?? '')) !== '';
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

    return !in_array($providerStatus, ['delivered', 'cancelled', 'canceled'], true);
}

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
    $providerOrderId = shipping_courier_response_value($payload, ['provider_order_id', 'order_id', 'courier_order_id']);
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

function shipping_courier_reverse_table_ready(mysqli $conn): bool
{
    try {
        $stmt = $conn->prepare(
            "SELECT COUNT(*) AS total
             FROM information_schema.TABLES
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = 'shipping_courier_reverse_pickups'"
        );
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        return ((int) ($row['total'] ?? 0)) > 0;
    } catch (Throwable $e) {
        error_log('[shipping-courier] reverse pickup table check failed: ' . $e->getMessage());
        return false;
    }
}

function shipping_courier_get_reverse_pickup(mysqli $conn, int $returnId, string $provider): ?array
{
    $provider = strtolower(trim($provider));
    if ($returnId <= 0 || $provider === '' || !shipping_courier_reverse_table_ready($conn)) {
        return null;
    }

    $stmt = $conn->prepare(
        "SELECT id, return_id, order_id, provider, provider_order_id, provider_pickup_id,
                provider_status, tracking_id, tracking_url, label_url,
                raw_response_json, created_at, updated_at
         FROM shipping_courier_reverse_pickups
         WHERE return_id = ? AND provider = ?
         LIMIT 1"
    );
    $stmt->bind_param('is', $returnId, $provider);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    return $row ?: null;
}

function shipping_courier_upsert_reverse_pickup(
    mysqli $conn,
    int $returnId,
    int $orderId,
    array $metadata
): ?array {
    $provider = shipping_courier_provider_name($metadata);
    if ($returnId <= 0 || $orderId <= 0 || $provider === '' || !shipping_courier_reverse_table_ready($conn)) {
        return null;
    }

    $providerOrderId = trim((string) ($metadata['provider_order_id'] ?? ''));
    $providerPickupId = trim((string) ($metadata['provider_pickup_id'] ?? ''));
    $providerStatus = shipping_courier_normalize_provider_status((string) ($metadata['provider_status'] ?? ''));
    $trackingId = trim((string) ($metadata['tracking_id'] ?? ''));
    $trackingUrl = InventoryService::safe_external_url((string) ($metadata['tracking_url'] ?? ''));
    $labelUrl = InventoryService::safe_external_url((string) ($metadata['label_url'] ?? ''));
    $rawResponseJson = shipping_courier_json_value($metadata['raw_response_json'] ?? null);

    $stmt = $conn->prepare(
        "INSERT INTO shipping_courier_reverse_pickups
            (return_id, order_id, provider, provider_order_id, provider_pickup_id,
             provider_status, tracking_id, tracking_url, label_url, raw_response_json)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE
            order_id = VALUES(order_id),
            provider_order_id = COALESCE(NULLIF(VALUES(provider_order_id), ''), provider_order_id),
            provider_pickup_id = COALESCE(NULLIF(VALUES(provider_pickup_id), ''), provider_pickup_id),
            provider_status = COALESCE(NULLIF(VALUES(provider_status), ''), provider_status),
            tracking_id = COALESCE(NULLIF(VALUES(tracking_id), ''), tracking_id),
            tracking_url = COALESCE(NULLIF(VALUES(tracking_url), ''), tracking_url),
            label_url = COALESCE(NULLIF(VALUES(label_url), ''), label_url),
            raw_response_json = COALESCE(VALUES(raw_response_json), raw_response_json),
            updated_at = NOW()"
    );
    $stmt->bind_param(
        'iissssssss',
        $returnId,
        $orderId,
        $provider,
        $providerOrderId,
        $providerPickupId,
        $providerStatus,
        $trackingId,
        $trackingUrl,
        $labelUrl,
        $rawResponseJson
    );
    $stmt->execute();

    return shipping_courier_get_reverse_pickup($conn, $returnId, $provider);
}

function shipping_courier_reverse_payload(mysqli $conn, int $returnId): ?array
{
    if ($returnId <= 0) {
        return null;
    }

    $stmt = $conn->prepare(
        "SELECT r.id AS return_id, r.return_number, r.status AS return_status, r.reason, r.customer_note,
                o.id AS order_id, o.order_number, o.customer_name, o.customer_phone, o.customer_email,
                o.address, o.city, o.state, o.pincode, o.country, o.payment_method
         FROM returns r
         JOIN orders o ON o.id = r.order_id
         WHERE r.id = ?
         LIMIT 1"
    );
    $stmt->bind_param('i', $returnId);
    $stmt->execute();
    $return = $stmt->get_result()->fetch_assoc();
    if (!$return) {
        return null;
    }

    $itemStmt = $conn->prepare(
        "SELECT order_item_id, product_name, unit_type, quantity, line_total
         FROM return_items
         WHERE return_id = ?
         ORDER BY id ASC"
    );
    $itemStmt->bind_param('i', $returnId);
    $itemStmt->execute();
    $items = $itemStmt->get_result()->fetch_all(MYSQLI_ASSOC);

    return [
        'return' => $return,
        'items' => is_array($items) ? $items : [],
        'pickup_address' => [
            'name' => (string) ($return['customer_name'] ?? ''),
            'phone' => (string) ($return['customer_phone'] ?? ''),
            'email' => (string) ($return['customer_email'] ?? ''),
            'address' => (string) ($return['address'] ?? ''),
            'city' => (string) ($return['city'] ?? ''),
            'state' => (string) ($return['state'] ?? ''),
            'pincode' => (string) ($return['pincode'] ?? ''),
            'country' => (string) ($return['country'] ?? ''),
        ],
    ];
}

function shipping_courier_reverse_metadata_from_response(array $body): array
{
    return [
        'provider_order_id' => shipping_courier_response_value($body, ['provider_order_id', 'order_id', 'courier_order_id']),
        'provider_pickup_id' => shipping_courier_response_value($body, ['provider_pickup_id', 'reverse_pickup_id', 'pickup_id', 'shipment_id']),
        'provider_status' => shipping_courier_response_value($body, ['provider_status', 'status', 'pickup_status']),
        'tracking_id' => shipping_courier_response_value($body, ['tracking_id', 'tracking_number', 'awb_code', 'awb', 'waybill']),
        'tracking_url' => shipping_courier_response_value($body, ['tracking_url', 'track_url']),
        'label_url' => shipping_courier_response_value($body, ['label_url', 'label', 'shipping_label_url']),
        'raw_response_json' => $body,
    ];
}

function shipping_courier_create_reverse_pickup(mysqli $conn, int $returnId): array
{
    if (!shipping_courier_enabled()) {
        return shipping_courier_result(false, 'Shipping courier plugin is disabled.');
    }
    if (!shipping_courier_provider_configured()) {
        return shipping_courier_result(false, 'Shipping courier provider is not configured.');
    }
    if (!shipping_courier_reverse_table_ready($conn)) {
        return shipping_courier_result(false, 'Reverse pickup metadata table is unavailable.');
    }

    $payload = shipping_courier_reverse_payload($conn, $returnId);
    if (!$payload) {
        return shipping_courier_result(false, 'Return request not found.');
    }

    $return = is_array($payload['return'] ?? null) ? $payload['return'] : [];
    if (strtolower((string) ($return['return_status'] ?? '')) !== 'approved') {
        return shipping_courier_result(false, 'Reverse pickup can be created only for approved returns.');
    }

    $provider = shipping_courier_provider_name();
    $existing = shipping_courier_get_reverse_pickup($conn, $returnId, $provider);
    if (!empty($existing['provider_pickup_id'])) {
        return shipping_courier_result(true, 'Reverse pickup already exists.', ['reverse_pickup' => $existing]);
    }

    $response = shipping_courier_http_json('POST', '/reverse-pickups', $payload);
    if (empty($response['ok'])) {
        error_log('[shipping-courier] reverse pickup failed for return ' . $returnId . ': ' . (string) ($response['message'] ?? 'unknown'));
        return $response;
    }

    $body = is_array($response['body'] ?? null) ? $response['body'] : [];
    $reversePickup = shipping_courier_upsert_reverse_pickup(
        $conn,
        $returnId,
        (int) ($return['order_id'] ?? 0),
        array_merge(shipping_courier_reverse_metadata_from_response($body), ['provider' => $provider])
    );

    return shipping_courier_result(true, 'Reverse pickup created.', [
        'reverse_pickup' => $reversePickup,
        'provider_response' => $body,
        'order_id' => (int) ($return['order_id'] ?? 0),
    ]);
}

function shipping_courier_render_return_actions(array $context): void
{
    $conn = $context['conn'] ?? null;
    $return = is_array($context['return'] ?? null) ? $context['return'] : [];
    $returnId = (int) ($return['id'] ?? 0);
    if (!$conn instanceof mysqli || $returnId <= 0) {
        return;
    }

    $provider = shipping_courier_provider_name();
    $reversePickup = $provider !== '' ? shipping_courier_get_reverse_pickup($conn, $returnId, $provider) : null;
    $providerStatus = trim((string) ($reversePickup['provider_status'] ?? ''));
    $trackingId = trim((string) ($reversePickup['tracking_id'] ?? ''));
    $trackingUrl = InventoryService::safe_external_url((string) ($reversePickup['tracking_url'] ?? ''));
    $labelUrl = InventoryService::safe_external_url((string) ($reversePickup['label_url'] ?? ''));
    $canCreate = shipping_courier_enabled()
        && shipping_courier_provider_configured()
        && strtolower((string) ($return['status'] ?? '')) === 'approved'
        && empty($reversePickup['provider_pickup_id']);
    ?>
    <?php if (is_array($reversePickup)): ?>
        <div class="small text-muted mt-2">
            <div>Reverse pickup: <strong><?php echo e($providerStatus !== '' ? $providerStatus : 'Created'); ?></strong></div>
            <?php if ($trackingId !== ''): ?>
                <div>Tracking: <strong><?php echo e($trackingId); ?></strong><?php if ($trackingUrl !== ''): ?> <a href="<?php echo e($trackingUrl); ?>" target="_blank" rel="noopener noreferrer">Track</a><?php endif; ?></div>
            <?php endif; ?>
            <?php if ($labelUrl !== ''): ?>
                <div><a href="<?php echo e($labelUrl); ?>" target="_blank" rel="noopener noreferrer">Open reverse label</a></div>
            <?php endif; ?>
        </div>
    <?php endif; ?>
    <?php if ($canCreate): ?>
        <form method="POST" action="returns.php" class="mt-2" data-confirm-modal data-confirm-title="Create Reverse Pickup" data-confirm-message="Create a courier reverse pickup for this approved return?" data-confirm-ok="Create Pickup">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="action" value="create_courier_reverse_pickup">
            <input type="hidden" name="return_id" value="<?php echo $returnId; ?>">
            <input type="hidden" name="filter_status" value="<?php echo e((string) ($context['filter_status'] ?? '')); ?>">
            <input type="hidden" name="filter_per_page" value="<?php echo (int) ($context['filter_per_page'] ?? 10); ?>">
            <input type="hidden" name="filter_page" value="<?php echo (int) ($context['filter_page'] ?? 1); ?>">
            <button type="submit" class="btn btn-sm btn-outline-secondary w-100">Create Reverse Pickup</button>
        </form>
    <?php endif; ?>
    <?php
}

function shipping_courier_handle_admin_return_action($handled, array $context)
{
    if ($handled) {
        return true;
    }

    $action = trim((string) ($context['action'] ?? ''));
    if ($action !== 'create_courier_reverse_pickup') {
        return false;
    }

    $conn = $context['conn'] ?? null;
    $returnId = (int) ($context['return_id'] ?? 0);
    if (!$conn instanceof mysqli || $returnId <= 0) {
        flash('error', 'Unable to create reverse pickup for this return.');
        return true;
    }

    try {
        $result = shipping_courier_create_reverse_pickup($conn, $returnId);
        if (!empty($result['ok'])) {
            $orderId = (int) ($result['order_id'] ?? 0);
            if ($orderId <= 0 && is_array($result['reverse_pickup'] ?? null)) {
                $orderId = (int) ($result['reverse_pickup']['order_id'] ?? 0);
            }
            if ($orderId > 0 && function_exists('log_order_activity')) {
                log_order_activity(
                    $conn,
                    $orderId,
                    'shipping_courier_reverse_pickup_created',
                    'admin',
                    (int) ($_SESSION['admin_id'] ?? 0),
                    (string) ($_SESSION['admin_name'] ?? 'admin'),
                    'Reverse pickup created for return #' . $returnId . '.'
                );
            }
            flash('success', (string) ($result['message'] ?? 'Reverse pickup created.'));
        } else {
            flash('error', (string) ($result['message'] ?? 'Unable to create reverse pickup.'));
        }
    } catch (Throwable $e) {
        error_log('[shipping-courier] admin reverse pickup action failed for return ' . $returnId . ': ' . $e->getMessage());
        flash('error', 'Reverse pickup failed safely. Existing return processing is unchanged.');
    }

    return true;
}

function shipping_courier_on_app_init(array $context): void
{
    if (!shipping_courier_enabled()) {
        return;
    }
}

function shipping_courier_render_admin_panel(array $context): void
{
    $settings = shipping_courier_settings();
    $provider = (string) ($settings['provider'] ?? '');
    $isEnabled = (int) ($settings['enabled'] ?? 0) === 1;
    $isConfigured = shipping_courier_provider_configured();
    $shipment = null;
    $metadata = null;
    $conn = $context['conn'] ?? null;
    $order = is_array($context['order'] ?? null) ? $context['order'] : [];
    $orderId = (int) ($order['id'] ?? 0);
    if ($conn instanceof mysqli && $orderId > 0 && $provider !== '') {
        $shipment = shipping_courier_get_shipment($conn, $orderId);
        $shipmentId = (int) ($shipment['id'] ?? 0);
        if ($shipmentId > 0) {
            $metadata = shipping_courier_get_metadata($conn, $shipmentId, $provider);
        }
    }
    $awbCode = trim((string) ($shipment['awb_code'] ?? ''));
    $trackingId = trim((string) ($shipment['tracking_id'] ?? ''));
    $trackingUrl = InventoryService::safe_external_url((string) ($shipment['tracking_url'] ?? ''));
    $labelUrl = InventoryService::safe_external_url((string) ($metadata['label_url'] ?? ''));
    $providerStatus = trim((string) ($metadata['provider_status'] ?? ''));
    $lastSync = trim((string) ($metadata['updated_at'] ?? ''));
    $canCreate = $conn instanceof mysqli && $orderId > 0 && shipping_courier_can_create_from_order($order, $metadata);
    $canSync = $conn instanceof mysqli && $orderId > 0 && shipping_courier_can_sync_tracking($shipment, $metadata);
    $canCancel = $conn instanceof mysqli && $orderId > 0 && shipping_courier_can_cancel_from_order($order, $metadata);
    ?>
    <div class="card mb-4">
        <div class="card-body">
            <h6 class="card-title">Shipping Courier</h6>
            <div class="small text-muted">
                <div>Status: <strong><?php echo $isEnabled ? 'Enabled' : 'Disabled'; ?></strong></div>
                <div>Provider: <strong><?php echo e($provider !== '' ? $provider : '-'); ?></strong></div>
                <div>Mode: <strong><?php echo !empty($settings['test_mode']) ? 'Test' : 'Live'; ?></strong></div>
                <div>API: <strong><?php echo $isConfigured ? 'Configured' : 'Not configured'; ?></strong></div>
                <div>Auto Create: <strong><?php echo !empty($settings['auto_create']) ? 'On' : 'Off'; ?></strong></div>
                <div>Tracking Sync: <strong><?php echo !empty($settings['tracking_sync']) ? 'On' : 'Off'; ?></strong></div>
                <div>AWB: <strong><?php echo e($awbCode !== '' ? $awbCode : '-'); ?></strong></div>
                <div>
                    Tracking:
                    <strong><?php echo e($trackingId !== '' ? $trackingId : '-'); ?></strong>
                    <?php if ($trackingUrl !== ''): ?>
                        <a href="<?php echo e($trackingUrl); ?>" target="_blank" rel="noopener noreferrer">Track</a>
                    <?php endif; ?>
                </div>
                <?php if ($labelUrl !== ''): ?>
                    <div>Label: <a href="<?php echo e($labelUrl); ?>" target="_blank" rel="noopener noreferrer">Open</a></div>
                <?php endif; ?>
                <div>Last Sync: <strong><?php echo e($providerStatus !== '' ? $providerStatus : 'Not synced'); ?></strong><?php echo $lastSync !== '' ? ' <span>(' . e($lastSync) . ')</span>' : ''; ?></div>
            </div>
            <?php if ($canCreate || $canSync || $canCancel): ?>
                <div class="d-grid gap-2 mt-3">
                    <?php if ($canCreate): ?>
                    <form method="POST" action="order-view.php?id=<?php echo $orderId; ?>">
                        <?php echo csrf_field(); ?>
                        <input type="hidden" name="action" value="create_courier_shipment">
                        <button class="btn btn-sm btn-outline-primary w-100" type="submit">Create Courier Shipment</button>
                    </form>
                    <?php endif; ?>
                    <?php if ($canSync): ?>
                    <form method="POST" action="order-view.php?id=<?php echo $orderId; ?>">
                        <?php echo csrf_field(); ?>
                        <input type="hidden" name="action" value="sync_courier_tracking">
                        <button class="btn btn-sm btn-outline-secondary w-100" type="submit">Sync Tracking</button>
                    </form>
                    <?php endif; ?>
                    <?php if ($canCancel): ?>
                    <form method="POST" action="order-view.php?id=<?php echo $orderId; ?>" data-confirm-modal data-confirm-title="Cancel Courier Shipment" data-confirm-message="Cancel this shipment with the courier provider?" data-confirm-ok="Cancel Shipment">
                        <?php echo csrf_field(); ?>
                        <input type="hidden" name="action" value="cancel_courier_shipment">
                        <button class="btn btn-sm btn-outline-danger w-100" type="submit">Cancel Shipment</button>
                    </form>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>
    <?php
}

function shipping_courier_render_shipping_rates_status(array $context): void
{
    $settings = shipping_courier_settings();
    $provider = (string) ($settings['provider'] ?? '');
    ?>
    <div class="card mb-4">
        <div class="card-body">
            <h5 class="mb-2">Courier Rate Quotes</h5>
            <div class="small text-muted">
                <div>Status: <strong><?php echo shipping_courier_enabled() ? 'Enabled' : 'Disabled'; ?></strong></div>
                <div>Provider: <strong><?php echo e($provider !== '' ? $provider : '-'); ?></strong></div>
                <div>API: <strong><?php echo shipping_courier_provider_configured() ? 'Configured' : 'Not configured'; ?></strong></div>
                <div>Fallback: <strong>Manual shipping rules</strong></div>
            </div>
        </div>
    </div>
    <?php
}

function shipping_courier_handle_admin_action($handled, array $context)
{
    if ($handled) {
        return $handled;
    }

    $action = (string) ($context['action'] ?? '');
    if (!in_array($action, ['create_courier_shipment', 'sync_courier_tracking', 'cancel_courier_shipment'], true)) {
        return false;
    }

    $conn = $context['conn'] ?? null;
    $orderId = (int) ($context['order_id'] ?? 0);
    if (!$conn instanceof mysqli || $orderId <= 0) {
        flash('error', 'Unable to run courier action for this order.');
        return true;
    }

    if (!shipping_courier_enabled()) {
        flash('error', 'Shipping courier plugin is disabled.');
        return true;
    }

    try {
        if ($action === 'create_courier_shipment') {
            $result = shipping_courier_create_shipment($conn, $orderId);
        } elseif ($action === 'sync_courier_tracking') {
            $result = shipping_courier_sync_tracking($conn, $orderId);
        } else {
            $result = shipping_courier_cancel_shipment($conn, $orderId);
        }
    } catch (Throwable $e) {
        error_log('[shipping-courier] admin action failed for order ' . $orderId . ': ' . $e->getMessage());
        $result = shipping_courier_result(false, 'Courier action failed safely. Manual shipment flow is still available.');
    }

    if (!empty($result['ok'])) {
        if (function_exists('log_order_activity')) {
            $adminId = (int) ($_SESSION['admin_id'] ?? 0);
            $adminName = (string) ($_SESSION['admin_name'] ?? 'admin');
            log_order_activity(
                $conn,
                $orderId,
                $action,
                'admin',
                $adminId,
                $adminName,
                (string) ($result['message'] ?? 'Courier action completed.')
            );
        }
        flash('success', (string) ($result['message'] ?? 'Courier action completed.'));
    } else {
        flash('error', (string) ($result['message'] ?? 'Courier action failed safely. Manual shipment flow is still available.'));
    }

    return true;
}

function shipping_courier_after_shipment_save(array $context): void
{
    if (!shipping_courier_enabled()) {
        return;
    }

    $conn = $context['conn'] ?? null;
    $orderId = (int) ($context['order_id'] ?? 0);
    if (!$conn instanceof mysqli || $orderId <= 0) {
        return;
    }

    shipping_courier_get_shipment($conn, $orderId);
}

function shipping_courier_after_order_commit(array $context): void
{
    if (!shipping_courier_auto_create_enabled()) {
        return;
    }

    $conn = $context['conn'] ?? null;
    $orderId = (int) ($context['order_id'] ?? 0);
    if (!$conn instanceof mysqli || $orderId <= 0) {
        return;
    }

    $payload = shipping_courier_order_payload($conn, $orderId);
    $order = is_array($payload['order'] ?? null) ? $payload['order'] : [];
    if (!shipping_courier_can_auto_create_after_commit($order)) {
        return;
    }

    $result = shipping_courier_create_shipment($conn, $orderId);
    if (empty($result['ok'])) {
        error_log('[shipping-courier] auto-create after commit skipped for order ' . $orderId . ': ' . (string) ($result['message'] ?? 'unknown'));
    }
}

function shipping_courier_after_payment_success(array $context): void
{
    if (!shipping_courier_auto_create_enabled()) {
        return;
    }

    $conn = $context['conn'] ?? null;
    $orderId = (int) ($context['order_id'] ?? 0);
    if (!$conn instanceof mysqli || $orderId <= 0) {
        return;
    }

    $payload = shipping_courier_order_payload($conn, $orderId);
    $order = is_array($payload['order'] ?? null) ? $payload['order'] : [];
    if (!shipping_courier_can_auto_create_after_payment_success($order)) {
        return;
    }

    $result = shipping_courier_create_shipment($conn, $orderId);
    if (empty($result['ok'])) {
        error_log('[shipping-courier] auto-create after payment skipped for order ' . $orderId . ': ' . (string) ($result['message'] ?? 'unknown'));
    }
}

function shipping_courier_cron_tracking_sync(array $context): void
{
    if (!shipping_courier_enabled() || empty(shipping_courier_settings()['tracking_sync'])) {
        return;
    }

    $conn = $context['conn'] ?? ($GLOBALS['conn'] ?? null);
    if (!$conn instanceof mysqli || !shipping_courier_provider_configured() || !shipping_courier_metadata_table_ready($conn)) {
        return;
    }

    $provider = shipping_courier_provider_name();
    if ($provider === '') {
        return;
    }

    $stmt = $conn->prepare(
        "SELECT scs.order_id
         FROM shipping_courier_shipments scs
         JOIN shipments s ON s.id = scs.shipment_id
         JOIN orders o ON o.id = scs.order_id
         WHERE scs.provider = ?
           AND COALESCE(s.delivered_at, '') = ''
           AND COALESCE(NULLIF(scs.provider_shipment_id, ''), NULLIF(s.tracking_id, '')) IS NOT NULL
           AND o.order_status NOT IN ('cancelled', 'returned', 'refunded')
           AND COALESCE(scs.provider_status, '') NOT IN ('delivered', 'cancelled', 'canceled')
         ORDER BY scs.updated_at ASC
         LIMIT 25"
    );
    $stmt->bind_param('s', $provider);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    foreach ($rows as $row) {
        $orderId = (int) ($row['order_id'] ?? 0);
        if ($orderId <= 0) {
            continue;
        }
        $result = shipping_courier_sync_tracking($conn, $orderId);
        if (empty($result['ok'])) {
            error_log('[shipping-courier] cron tracking sync skipped for order ' . $orderId . ': ' . (string) ($result['message'] ?? 'unknown'));
        }
    }
}
