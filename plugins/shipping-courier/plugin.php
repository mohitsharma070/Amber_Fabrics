<?php

add_action('admin.order_view.sidebar', 'shipping_courier_render_admin_panel', 30);
add_filter('admin.order_action.handled', 'shipping_courier_handle_admin_action', 20);
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
        // These values originate only from server configuration/environment and
        // are never rendered into browser responses.
        'bigship_username' => trim((string) plugin_setting('shipping-courier', 'bigship_username', '')),
        'bigship_password' => trim((string) plugin_setting('shipping-courier', 'bigship_password', '')),
        'bigship_access_key' => trim((string) plugin_setting('shipping-courier', 'bigship_access_key', '')),
        'bigship_warehouse_id' => trim((string) plugin_setting('shipping-courier', 'bigship_warehouse_id', '')),
        'bigship_warehouse_pincode' => trim((string) plugin_setting('shipping-courier', 'bigship_warehouse_pincode', '')),
        'bigship_segment' => strtolower(trim((string) plugin_setting('shipping-courier', 'bigship_segment', 'domestic_b2c'))),
        'bigship_risk_type_id' => (int) plugin_setting('shipping-courier', 'bigship_risk_type_id', 2),
        'bigship_product_category_id' => (int) plugin_setting('shipping-courier', 'bigship_product_category_id', 1),
        'bigship_http_skip_tls_verify' => (int) plugin_setting('shipping-courier', 'bigship_http_skip_tls_verify', 0),
        'bigship_parcel_weight_kg' => (float) plugin_setting('shipping-courier', 'bigship_parcel_weight_kg', 0),
        'bigship_parcel_length_cm' => (float) plugin_setting('shipping-courier', 'bigship_parcel_length_cm', 0),
        'bigship_parcel_width_cm' => (float) plugin_setting('shipping-courier', 'bigship_parcel_width_cm', 0),
        'bigship_parcel_height_cm' => (float) plugin_setting('shipping-courier', 'bigship_parcel_height_cm', 0),
    ];
}

function shipping_courier_bigship_segment(array $settings): string
{
    $segment = strtolower(trim((string) ($settings['bigship_segment'] ?? 'domestic_b2c')));
    return in_array($segment, ['hyperlocal', 'domestic_b2b', 'domestic_b2c'], true)
        ? $segment
        : 'domestic_b2c';
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
        && (string) ($settings['bigship_username'] ?? '') !== ''
        && (string) ($settings['bigship_password'] ?? '') !== ''
        && (string) ($settings['bigship_access_key'] ?? '') !== '';
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
    if (!is_array($quote)) {
        return $quote;
    }

    $debugEnabled = (($GLOBALS['_app_mode'] ?? '') === 'local');
    $fallback = static function (array $base, string $reason, string $message = '') use ($debugEnabled): array {
        if (!$debugEnabled) {
            return $base;
        }
        $base['debug_reason'] = $reason;
        if ($message !== '') {
            $base['debug_message'] = $message;
        }
        return $base;
    };

    if (!shipping_courier_enabled()) {
        return $fallback($quote, 'shipping_courier_disabled', 'SHIPPING_COURIER_ENABLED is not active.');
    }
    if (!shipping_courier_provider_configured()) {
        return $fallback($quote, 'shipping_courier_not_configured', 'Bigship provider settings are incomplete.');
    }

    $country = trim((string) ($context['country'] ?? 'India'));
    $pincode = trim((string) ($context['pincode'] ?? ''));
    $subtotal = max(0.0, (float) ($context['subtotal'] ?? 0));
    $paymentMethod = strtolower(trim((string) ($context['payment_method'] ?? 'cod')));
    if (strcasecmp($country, 'India') !== 0 || !preg_match('/^[1-9][0-9]{5}$/', $pincode) || $subtotal <= 0) {
        return $fallback($quote, 'shipping_quote_context_invalid', 'Country/pincode/subtotal is not eligible for live courier quote.');
    }

    $settings = shipping_courier_settings();
    $sourcePincode = (string) ($settings['bigship_warehouse_pincode'] ?? '');
    $weight = max(0.0, (float) ($settings['bigship_parcel_weight_kg'] ?? 0));
    $length = max(0.0, (float) ($settings['bigship_parcel_length_cm'] ?? 0));
    $width = max(0.0, (float) ($settings['bigship_parcel_width_cm'] ?? 0));
    $height = max(0.0, (float) ($settings['bigship_parcel_height_cm'] ?? 0));
    if (!preg_match('/^[1-9][0-9]{5}$/', $sourcePincode) || min($weight, $length, $width, $height) <= 0) {
        return $fallback($quote, 'bigship_origin_or_parcel_invalid', 'Warehouse pincode or parcel dimensions are invalid.');
    }

    $paymentModeId = $paymentMethod === 'cod' ? 2 : 1;
    $invoiceValue = round($subtotal, 2);
    $riskTypeId = (int) ($context['risk_type_id'] ?? ($settings['bigship_risk_type_id'] ?? 2));
    $riskTypeId = $riskTypeId > 0 ? $riskTypeId : 2;

    $ratePayload = [
        'segment_type' => shipping_courier_bigship_segment($settings),
        'sourcePincode' => (string) $sourcePincode,
        'destPincode' => (string) $pincode,
        // Keep both variants for compatibility with provider-side validators.
        'invoiceValue' => $invoiceValue,
        'invoicevalue' => $invoiceValue,
        'PaymentModeId' => $paymentModeId,
        'paymentModeId' => $paymentModeId,
        'riskTypeId' => $riskTypeId,
        'boxes' => [[
            'no_of_box' => '1',
            'box_length' => (string) $length,
            'box_width' => (string) $width,
            'box_height' => (string) $height,
            'box_dead_weight' => (string) $weight,
        ]],
    ];

    if ($paymentMethod === 'cod') {
        $ratePayload['codAmount'] = $invoiceValue;
        $ratePayload['codamount'] = $invoiceValue;
    }

    $response = shipping_courier_http_json('POST', '/api/outbound/user-rate-calculator', $ratePayload);
    $rate = !empty($response['ok']) && is_array($response['body'] ?? null)
        ? shipping_courier_bigship_selected_rate($response['body'])
        : null;
    if (empty($response['ok'])) {
        $status = (int) ($response['status'] ?? 0);
        $body = is_array($response['body'] ?? null) ? (array) $response['body'] : [];
        $message = trim((string) ($body['message'] ?? ''));
        if ($message === '') {
            $message = trim((string) ($response['message'] ?? 'Bigship rate API failed.'));
        }
        if (!empty($body['errors']) && is_array($body['errors'])) {
            foreach ((array) $body['errors'] as $field => $messages) {
                if (is_array($messages) && !empty($messages)) {
                    $first = trim((string) $messages[0]);
                    if ($first !== '') {
                        $message .= ($message !== '' ? ' ' : '') . '[' . (string) $field . '] ' . $first;
                        break;
                    }
                }
            }
        }
        if ($message === '') {
            $message = 'Bigship rate API failed.';
        }
        if ($status > 0 && stripos($message, 'http ') === false) {
            $message .= ' HTTP ' . $status . '.';
        }
        return $fallback($quote, 'bigship_rate_api_failed', $message);
    }
    if ($rate === null) {
        return $fallback($quote, 'bigship_rate_unavailable', 'Bigship returned no valid courier rate for this shipment.');
    }

    $totalCharge = round((float) $rate['total_charge'], 2);
    $courierName = (string) $rate['courier_name'];
    $courierId = (int) $rate['courier_id'];
    $provider = shipping_courier_provider_name();

    return [
        // Bigship's totalCharge already includes the applicable COD charge.
        'base_shipping' => $totalCharge,
        'cod_fee' => 0.0,
        'shipping_total' => $totalCharge,
        'source' => substr($provider !== '' ? $provider : 'courier', 0, 32),
        'courier_name' => $courierName !== '' ? $courierName : $provider,
        'courier_id' => $courierId,
    ];
}

function shipping_courier_bigship_selected_rate(array $body): ?array
{
    $candidates = [];
    foreach (['data', 'rates', 'courier_partners', 'results'] as $key) {
        if (is_array($body[$key] ?? null)) {
            $candidates = $body[$key];
            break;
        }
    }
    if ($candidates === [] && array_is_list($body)) {
        $candidates = $body;
    }
    if (!array_is_list($candidates)) {
        $candidates = [$candidates];
    }

    $selected = null;
    foreach ($candidates as $candidate) {
        if (!is_array($candidate)) {
            continue;
        }
        $courierId = shipping_courier_response_value($candidate, ['courier_partner_id', 'courierPartnerId']);
        $totalCharge = shipping_courier_response_value($candidate, ['totalCharge', 'total_charge']);
        if (!is_numeric($courierId) || (int) $courierId <= 0 || !is_numeric($totalCharge) || (float) $totalCharge < 0) {
            continue;
        }
        $rate = [
            'courier_id' => (int) $courierId,
            'courier_name' => shipping_courier_response_value($candidate, ['courier_name', 'courierName', 'courier_partner_name', 'courierPartnerName', 'name']),
            'total_charge' => (float) $totalCharge,
        ];
        if ($selected === null || $rate['total_charge'] < $selected['total_charge']) {
            $selected = $rate;
        }
    }

    return $selected;
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

function shipping_courier_bigship_token_cache_key(array $settings): string
{
    return 'shipping_courier_bigship_token_' . hash('sha256', implode("\0", [
        (string) ($settings['api_base_url'] ?? ''),
        (string) ($settings['bigship_username'] ?? ''),
        (string) ($settings['bigship_password'] ?? ''),
        (string) ($settings['bigship_access_key'] ?? ''),
    ]));
}

function shipping_courier_bigship_token_expiry(array $body): int
{
    $expiry = shipping_courier_response_value($body, ['expires_at', 'expiresAt', 'expiry', 'expiration', 'tokenExpiringAt', 'token_expiring_at']);
    if ($expiry !== '') {
        $timestamp = is_numeric($expiry) ? (int) $expiry : (int) strtotime($expiry);
        if ($timestamp > 100000000000) {
            $timestamp = (int) floor($timestamp / 1000);
        }
        if ($timestamp > time()) {
            return $timestamp;
        }
    }

    $ttl = shipping_courier_response_value($body, ['expires_in', 'expiresIn', 'ttl']);
    if (is_numeric($ttl) && (int) $ttl > 0) {
        return time() + (int) $ttl;
    }

    return time();
}

function shipping_courier_bigship_access_token(array $settings): array
{
    static $tokens = [];

    $cacheKey = shipping_courier_bigship_token_cache_key($settings);
    $now = time();
    $cached = $tokens[$cacheKey] ?? null;
    if (!is_array($cached) && function_exists('apcu_fetch')) {
        $success = false;
        $cached = apcu_fetch($cacheKey, $success);
        if (!$success) {
            $cached = null;
        }
    }
    if (is_array($cached) && !empty($cached['token']) && (int) ($cached['expires_at'] ?? 0) > $now + 30) {
        $tokens[$cacheKey] = $cached;
        return shipping_courier_result(true, '', ['token' => (string) $cached['token']]);
    }

    $login = shipping_courier_http_json_request('POST', (string) $settings['api_base_url'] . '/api/outbound/login', [
        'username' => (string) $settings['bigship_username'],
        'password' => (string) $settings['bigship_password'],
        'access_key' => (string) $settings['bigship_access_key'],
    ]);
    if (empty($login['ok']) || !is_array($login['body'] ?? null)) {
        return shipping_courier_result(false, 'Unable to authenticate with Bigship Direct.', ['status' => (int) ($login['status'] ?? 0)]);
    }

    $body = $login['body'];
    $token = shipping_courier_response_value($body, ['access_token', 'accessToken', 'token']);
    $expiresAt = shipping_courier_bigship_token_expiry($body);
    if ($token === '' || $expiresAt <= $now) {
        return shipping_courier_result(false, 'Bigship Direct returned an invalid access token.', ['status' => (int) ($login['status'] ?? 0)]);
    }

    $cached = ['token' => $token, 'expires_at' => $expiresAt];
    $tokens[$cacheKey] = $cached;
    if (function_exists('apcu_store')) {
        apcu_store($cacheKey, $cached, max(1, $expiresAt - $now));
    }

    return shipping_courier_result(true, '', ['token' => $token]);
}

function shipping_courier_http_json_request(string $method, string $url, array $payload = [], array $headers = []): array
{
    if (!function_exists('curl_init')) {
        return shipping_courier_result(false, 'cURL is unavailable.', ['status' => 0, 'body' => null]);
    }
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
    ], $headers);

    $settings = shipping_courier_settings();
    $skipTlsVerify = ((int) ($settings['bigship_http_skip_tls_verify'] ?? 0) === 1)
        && (($GLOBALS['_app_mode'] ?? '') === 'local');

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
        CURLOPT_SSL_VERIFYPEER => !$skipTlsVerify,
        CURLOPT_SSL_VERIFYHOST => $skipTlsVerify ? 0 : 2,
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

function shipping_courier_http_json(string $method, string $path, array $payload = [], array $headers = []): array
{
    if (!shipping_courier_enabled()) {
        return shipping_courier_result(false, 'Shipping courier plugin is disabled.', ['status' => 0, 'body' => null]);
    }
    if (!shipping_courier_provider_configured()) {
        return shipping_courier_result(false, 'Shipping courier provider is not configured.', ['status' => 0, 'body' => null]);
    }

    $settings = shipping_courier_settings();
    $baseUrl = (string) ($settings['api_base_url'] ?? '');
    $url = preg_match('/^https?:\/\//i', $path) ? $path : ($baseUrl . '/' . ltrim($path, '/'));
    $token = shipping_courier_bigship_access_token($settings);
    if (empty($token['ok'])) {
        return shipping_courier_result(false, (string) ($token['message'] ?? 'Unable to authenticate with Bigship Direct.'), ['status' => (int) ($token['status'] ?? 0), 'body' => null]);
    }

    $headers = array_values(array_filter($headers, static function ($header): bool {
        return stripos((string) $header, 'Authorization:') !== 0;
    }));
    $headers[] = 'Authorization: Bearer ' . (string) $token['token'];

    return shipping_courier_http_json_request($method, $url, $payload, $headers);
}

function shipping_courier_http_post_multipart(string $path, array $payload): array
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

    $token = shipping_courier_bigship_access_token($settings);
    if (empty($token['ok'])) {
        return shipping_courier_result(false, (string) ($token['message'] ?? 'Unable to authenticate with Bigship Direct.'), ['status' => (int) ($token['status'] ?? 0), 'body' => null]);
    }

    $fields = [];
    foreach ($payload as $key => $value) {
        if (is_scalar($value) || $value === null) {
            $fields[(string) $key] = $value === null ? '' : (string) $value;
        }
    }

    $ch = curl_init($url);
    if ($ch === false) {
        return shipping_courier_result(false, 'Unable to initialize courier API request.', ['status' => 0, 'body' => null]);
    }

    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT => 25,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $fields,
        CURLOPT_HTTPHEADER => [
            'Accept: application/json',
            'Authorization: Bearer ' . (string) $token['token'],
        ],
    ]);

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
                payment_method, payment_status, order_status, created_at,
                courier_id, courier_name, base_shipping
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

    foreach (['data', 'shipment', 'order', 'tracking_current_status', 'getOrderDetails'] as $container) {
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
    $shippingCost = shipping_courier_response_value($body, ['shipping_cost', 'freight_charge', 'rate', 'totalCharge', 'total_charge']);
    return array_filter([
        'awb_code' => shipping_courier_response_value($body, ['awb_code', 'awb', 'awb_number', 'awbNo', 'waybill']),
        'courier_name' => shipping_courier_response_value($body, ['courier_name', 'courierName', 'courier_partner_name', 'courierPartnerName', 'courier', 'carrier_name', 'provider']),
        'tracking_id' => shipping_courier_response_value($body, ['tracking_id', 'tracking_number', 'awb_code', 'awb', 'awb_number', 'awbNo', 'waybill']),
        'tracking_url' => shipping_courier_response_value($body, ['tracking_url', 'track_url']),
        'shipping_cost' => $shippingCost !== '' ? (float) $shippingCost : null,
        'shipped_at' => shipping_courier_response_timestamp($body, ['shipped_at', 'pickup_at', 'picked_up_at', 'shipped_on']),
        'delivered_at' => shipping_courier_response_timestamp($body, ['delivered_at', 'delivered_on', 'delivery_at', 'deliveredAt', 'deliveryDate']),
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
        'provider_order_id' => shipping_courier_response_value($body, ['CustomGlobalOrderId', 'custom_global_order_id', 'provider_order_id', 'order_id', 'courier_order_id']),
        'provider_shipment_id' => shipping_courier_response_value($body, ['BigshipOrderId', 'BigshipOrderID', 'bigship_order_id', 'provider_shipment_id', 'shipment_id', 'courier_shipment_id', 'reference_number', 'awb_assigned', 'tracking_number', 'awb_code', 'awb', 'AwbNumber']),
        'provider_status' => shipping_courier_normalize_provider_status(shipping_courier_response_value($body, ['provider_status', 'status', 'shipment_status', 'current_status', 'tracking_status', 'orderStatus'])),
        'label_url' => shipping_courier_response_value($body, ['AttachmentData', 'label_url', 'label', 'shipping_label_url']),
        'raw_response_json' => $body,
    ];
}

function shipping_courier_bigship_payment_mode_id(array $order): int
{
    $paymentMethod = strtolower(trim((string) ($order['payment_method'] ?? '')));
    if ($paymentMethod === 'cod') {
        return 2;
    }

    return 1;
}

function shipping_courier_bigship_invoice_amount(array $order): float
{
    $subtotal = max(0.0, (float) ($order['subtotal'] ?? 0));
    $discount = max(0.0, (float) ($order['discount_amount'] ?? 0));
    $invoiceAmount = round(max(0.0, $subtotal - $discount), 2);
    if ($invoiceAmount <= 0) {
        $invoiceAmount = round(max(0.0, (float) ($order['total_amount'] ?? 0)), 2);
    }

    return $invoiceAmount;
}

function shipping_courier_bigship_order_request(array $payload): array
{
    $order = (array) ($payload['order'] ?? []);
    $items = (array) ($payload['items'] ?? []);
    $settings = shipping_courier_settings();
    $segment = shipping_courier_bigship_segment($settings);

    $warehouseId = (int) ($settings['bigship_warehouse_id'] ?? 0);
    $weight = max(0.0, (float) ($settings['bigship_parcel_weight_kg'] ?? 0));
    $length = max(1.0, (float) ($settings['bigship_parcel_length_cm'] ?? 0));
    $width = max(1.0, (float) ($settings['bigship_parcel_width_cm'] ?? 0));
    $height = max(1.0, (float) ($settings['bigship_parcel_height_cm'] ?? 0));
    if ($warehouseId <= 0) {
        return shipping_courier_result(false, 'Bigship warehouse id is not configured.');
    }
    if ($weight <= 0) {
        return shipping_courier_result(false, 'Bigship parcel weight must be greater than zero.');
    }

    $paymentModeId = shipping_courier_bigship_payment_mode_id($order);
    $invoiceAmount = shipping_courier_bigship_invoice_amount($order);
    $shippingName = trim((string) ($order['customer_name'] ?? ''));
    $shippingMobile = trim((string) ($order['customer_phone'] ?? ''));
    $shippingAddress = trim((string) ($order['address'] ?? ''));
    $shippingZip = trim((string) ($order['pincode'] ?? ''));
    $shippingCity = trim((string) ($order['city'] ?? ''));
    $shippingState = trim((string) ($order['state'] ?? ''));
    $shippingCountry = trim((string) ($order['country'] ?? 'India'));

    if ($shippingName === '' || $shippingMobile === '' || $shippingAddress === '' || $shippingZip === '' || $shippingCity === '' || $shippingState === '') {
        return shipping_courier_result(false, 'Order shipping address is incomplete for Bigship order creation.');
    }

    $basePayload = [
        'segment_type' => $segment,
        'MasterOrderPickUpLocation' => $warehouseId,
        'MasterOrderPaymentMode' => $paymentModeId,
        'MasterOrderShippingName' => $shippingName,
        'MasterOrderShippingEmail' => (string) ($order['customer_email'] ?? ''),
        'MasterOrderShippingMobileNo' => $shippingMobile,
        'MasterOrderShippingZipCode' => $shippingZip,
        'MasterOrderShippingCity' => $shippingCity,
        'MasterOrderShippingState' => $shippingState,
        'MasterOrderShippingCountry' => $shippingCountry !== '' ? $shippingCountry : 'India',
        'MasterOrderShippingAddress' => $shippingAddress,
        'MasterOrderShippingAddress2' => '',
        'MasterOrderShippingLandmark' => '',
    ];

    $orderDate = gmdate('Y-m-d H:i:s');
    $invoiceNo = (string) ($order['order_number'] ?? ('ORD-' . (int) ($order['id'] ?? 0)));
    $codAmount = $paymentModeId === 2 ? round(max(0.0, (float) ($order['total_amount'] ?? $invoiceAmount)), 2) : 0.0;

    if ($segment === 'domestic_b2b') {
        $productNames = [];
        foreach ($items as $item) {
            $name = trim((string) ($item['product_name'] ?? $item['fabric_name_snapshot'] ?? 'Item'));
            if ($name !== '') {
                $productNames[] = $name;
            }
        }
        $productName = trim(implode(', ', array_slice(array_values(array_unique($productNames)), 0, 5)));
        if ($productName === '') {
            $productName = 'Fabric Order';
        }

        $body = array_merge($basePayload, [
            'MasterOrderReturnLocation' => $warehouseId,
            'MasterOrderDate' => $orderDate,
            'OrderInvoiceNo' => $invoiceNo,
            'MasterOrderInvoiceAmount' => $invoiceAmount,
            'MasterOrderCollectableAmount' => $paymentModeId === 2 ? $codAmount : 0,
            'totalNumOfBoxes' => 1,
            'ProductName' => $productName,
            'boxes' => [[
                'weight_unit' => 'kg',
                'dimension_unit' => 'cm',
                'noOfBoxes' => 1,
                'dimensions' => [[
                    'length' => $length,
                    'breadth' => $width,
                    'height' => $height,
                    'weight' => $weight,
                ]],
            ]],
        ]);

        return shipping_courier_result(true, '', ['body' => $body]);
    }

    $categoryId = max(1, (int) ($settings['bigship_product_category_id'] ?? 1));
    $products = [];
    foreach ($items as $item) {
        $name = trim((string) ($item['product_name'] ?? $item['fabric_name_snapshot'] ?? 'Item'));
        $qty = max(1, (int) round((float) ($item['quantity'] ?? $item['quantity_meters'] ?? 1)));
        $lineAmount = round(max(0.0, (float) ($item['line_total'] ?? $item['total'] ?? 0)), 2);
        if ($lineAmount <= 0) {
            $lineAmount = round(max(0.0, $invoiceAmount / max(1, count($items))), 2);
        }
        $products[] = [
            'productName' => $name !== '' ? $name : 'Fabric Item',
            'qty' => $qty,
            'amount' => $lineAmount,
            'totalAmount' => $lineAmount,
            'collectableAmount' => $paymentModeId === 2 ? $lineAmount : 0,
            'categoryId' => (string) $categoryId,
        ];
    }
    if (empty($products)) {
        $products[] = [
            'productName' => 'Fabric Item',
            'qty' => 1,
            'amount' => $invoiceAmount,
            'totalAmount' => $invoiceAmount,
            'collectableAmount' => $paymentModeId === 2 ? $invoiceAmount : 0,
            'categoryId' => (string) $categoryId,
        ];
    }

    $body = array_merge($basePayload, [
        'MasterOrderReturnLocation' => $warehouseId,
        'MasterOrderDate' => $orderDate,
        'OrderInvoiceNo' => $invoiceNo,
        'MasterOrderInvoiceAmount' => $invoiceAmount,
        'totalNumOfBoxes' => 1,
        'boxes' => [[
            'weight_unit' => 'kg',
            'dimension_unit' => 'cm',
            'noOfBoxes' => 1,
            'dimensions' => [[
                'length' => $length,
                'breadth' => $width,
                'height' => $height,
                'weight' => $weight,
            ]],
            'products' => $products,
        ]],
    ]);

    return shipping_courier_result(true, '', ['body' => $body]);
}

function shipping_courier_bigship_download_document_url(string $customGlobalOrderId, string $documentType = 'label'): string
{
    $customGlobalOrderId = trim($customGlobalOrderId);
    if ($customGlobalOrderId === '') {
        return '';
    }

    $path = '/api/outbound/download-shipment-documents?CustomGlobalOrderId=' . rawurlencode($customGlobalOrderId)
        . '&document_type=' . rawurlencode($documentType);
    $response = shipping_courier_http_json('GET', $path);
    if (empty($response['ok']) || !is_array($response['body'] ?? null)) {
        return '';
    }

    return InventoryService::safe_external_url(
        shipping_courier_response_value((array) $response['body'], ['AttachmentData', 'attachment_data'])
    );
}

function shipping_courier_bigship_lifecycle_metadata(array $responses, string $customGlobalOrderId = ''): array
{
    $placeOrder = is_array($responses['place_order'] ?? null) ? $responses['place_order'] : [];
    $metadata = shipping_courier_metadata_from_response($placeOrder);
    foreach (['download_label', 'courier_wise_shipment_cost', 'create_order'] as $step) {
        if (!is_array($responses[$step] ?? null)) {
            continue;
        }
        $stepMetadata = shipping_courier_metadata_from_response($responses[$step]);
        foreach (['provider_order_id', 'provider_shipment_id', 'provider_status', 'label_url'] as $key) {
            if (empty($metadata[$key]) && !empty($stepMetadata[$key])) {
                $metadata[$key] = $stepMetadata[$key];
            }
        }
    }
    if ($customGlobalOrderId !== '') {
        $metadata['provider_order_id'] = $customGlobalOrderId;
    }
    $metadata['raw_response_json'] = $responses;
    return $metadata;
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

    $stmt = $conn->prepare(
        "UPDATE orders
         SET order_status = ?, status = ?, updated_at = NOW()
         WHERE id = ?
           AND order_status NOT IN ('cancelled', 'refunded')"
    );
    $stmt->bind_param('ssi', $localStatus, $localStatus, $orderId);
    $stmt->execute();
    return $localStatus;
}

function shipping_courier_create_shipment(mysqli $conn, int $orderId): array
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
        $createPayload = shipping_courier_bigship_order_request($payload);
        if (empty($createPayload['ok']) || !is_array($createPayload['body'] ?? null)) {
            return shipping_courier_result(false, (string) ($createPayload['message'] ?? 'Unable to prepare Bigship create-order payload.'));
        }
        $createOrder = shipping_courier_http_json('POST', '/api/outbound/create-order', (array) $createPayload['body']);
        if (empty($createOrder['ok']) || !is_array($createOrder['body'] ?? null)) {
            error_log('[shipping-courier] Bigship create-order failed for order ' . $orderId . ': ' . (string) ($createOrder['message'] ?? 'unknown'));
            return $createOrder;
        }
        $responses['create_order'] = $createOrder['body'];
        $customGlobalOrderId = shipping_courier_response_value($createOrder['body'], ['CustomGlobalOrderId', 'custom_global_order_id']);
        if ($customGlobalOrderId === '') {
            return shipping_courier_result(false, 'Bigship create-order did not return CustomGlobalOrderId.');
        }
        $existingMetadata = shipping_courier_upsert_metadata($conn, $orderId, $shipmentId, shipping_courier_bigship_lifecycle_metadata($responses, $customGlobalOrderId));
    }

    $cost = shipping_courier_http_json('POST', '/api/outbound/courier-wise-shipment-cost', [
        'MasterCustomOrderId' => $customGlobalOrderId,
    ]);
    if (empty($cost['ok']) || !is_array($cost['body'] ?? null)) {
        error_log('[shipping-courier] Bigship courier-wise-shipment-cost failed for order ' . $orderId . ': ' . (string) ($cost['message'] ?? 'unknown'));
        return $cost;
    }
    $responses['courier_wise_shipment_cost'] = $cost['body'];

    $settings = shipping_courier_settings();
    $segment = shipping_courier_bigship_segment($settings);
    $placePayload = [
        'MasterCustomOrderId' => $customGlobalOrderId,
        'courierId' => $courierId,
    ];
    if (in_array($segment, ['domestic_b2b', 'domestic_b2c'], true)) {
        $riskTypeId = max(1, (int) ($settings['bigship_risk_type_id'] ?? 2));
        $placePayload['riskTypeId'] = $riskTypeId;
        $placeOrder = shipping_courier_http_post_multipart('/api/outbound/place-order', $placePayload);
    } else {
        $placeOrder = shipping_courier_http_json('POST', '/api/outbound/place-order', $placePayload);
    }
    if (empty($placeOrder['ok']) || !is_array($placeOrder['body'] ?? null)) {
        error_log('[shipping-courier] Bigship place-order failed for order ' . $orderId . ': ' . (string) ($placeOrder['message'] ?? 'unknown'));
        return $placeOrder;
    }
    $responses['place_order'] = $placeOrder['body'];

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

    $response = shipping_courier_http_json('GET', '/api/outbound/track-order?CustomGlobalOrderId=' . rawurlencode($customGlobalOrderId));
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
    $trackingMetadata = shipping_courier_metadata_from_response($body);
    $trackingMetadata['provider_order_id'] = $customGlobalOrderId;
    $rawResponses = $metadata['raw_response_json'] ?? null;
    if (is_string($rawResponses)) {
        $rawResponses = json_decode($rawResponses, true);
    }
    if (!is_array($rawResponses)) {
        $rawResponses = [];
    }
    $rawResponses['track_order'] = $body;
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

    $response = shipping_courier_http_json('POST', '/api/outbound/cancel-order', [
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
        && (
            trim((string) ($metadata['provider_order_id'] ?? '')) !== ''
            || trim((string) ($metadata['provider_shipment_id'] ?? '')) !== ''
        );
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
    if (in_array(shipping_courier_provider_name(), ['bigship', 'bigship-direct'], true)) {
        // Bigship signature verification details have not been configured.
        return false;
    }
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
           AND COALESCE(NULLIF(scs.provider_order_id, ''), NULLIF(scs.provider_shipment_id, ''), NULLIF(s.tracking_id, '')) IS NOT NULL
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
