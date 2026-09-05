<?php

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

function shipping_courier_live_rate_readiness(): array
{
    $settings = shipping_courier_settings();
    $issues = [];
    if (!shipping_courier_enabled()) {
        $issues[] = 'Courier plugin is disabled.';
    }
    if (!shipping_courier_provider_configured()) {
        $issues[] = 'Bigship API credentials are incomplete.';
    }
    $warehouse = shipping_courier_bigship_warehouse();
    if ((int) ($warehouse['id'] ?? 0) <= 0) {
        $issues[] = 'A Bigship warehouse must be configured or synchronized.';
    }
    if (!preg_match('/^[1-9][0-9]{5}$/', (string) ($warehouse['pincode'] ?? ''))) {
        $issues[] = 'A valid 6-digit warehouse pincode is required.';
    }
    $dimensions = [
        (float) ($settings['bigship_parcel_weight_kg'] ?? 0),
        (float) ($settings['bigship_parcel_length_cm'] ?? 0),
        (float) ($settings['bigship_parcel_width_cm'] ?? 0),
        (float) ($settings['bigship_parcel_height_cm'] ?? 0),
    ];
    if (min($dimensions) <= 0) {
        $issues[] = 'Parcel weight and dimensions are required.';
    }

    return ['ready' => empty($issues), 'issues' => $issues];
}

function shipping_courier_result(bool $ok, string $message = '', array $data = []): array
{
    return array_merge([
        'ok' => $ok,
        'message' => $message,
    ], $data);
}

/**
 * Return a provider validation message suitable for an authenticated admin.
 * The raw API response is intentionally never sent to the browser.
 */
function shipping_courier_api_failure_message(array $response, string $operation): string
{
    $status = max(0, (int) ($response['status'] ?? 0));
    $body = is_array($response['body'] ?? null) ? (array) $response['body'] : [];
    $detail = shipping_courier_response_value($body, ['message', 'error', 'detail', 'description']);

    if ($detail === '' && !empty($body['errors']) && is_array($body['errors'])) {
        foreach ((array) $body['errors'] as $field => $errors) {
            $first = is_array($errors) ? reset($errors) : $errors;
            if (is_scalar($first) && trim((string) $first) !== '') {
                $detail = trim((string) $field) . ': ' . trim((string) $first);
                break;
            }
        }
    }
    if ($detail === '') {
        $detail = trim((string) ($response['message'] ?? ''));
    }

    $message = 'Bigship ' . $operation . ' failed';
    if ($status > 0) {
        $message .= ' (HTTP ' . $status . ')';
    }
    if ($detail !== '' && !preg_match('/^Courier API returned HTTP \d+$/i', $detail)) {
        $message .= ': ' . substr($detail, 0, 500);
    }
    return $message . '.';
}

function shipping_courier_filter_shipping_quote($quote, array $context)
{
    $deadlineNs = !empty($context['storefront_rate_request'])
        ? hrtime(true) + BigshipService::STOREFRONT_QUOTE_TIMEOUT_MS * 1000000
        : null;
    if (!is_array($quote)) {
        return $quote;
    }

    $debugEnabled = (($GLOBALS['_app_mode'] ?? '') === 'local') || !empty($context['admin_debug']);
    $fallback = static function (array $base, string $reason, string $message = '') use ($debugEnabled): array {
        $base['debug_reason'] = $reason;
        if ($debugEnabled && $message !== '') {
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
    $warehouse = shipping_courier_bigship_warehouse();
    $sourcePincode = (string) ($warehouse['pincode'] ?? '');
    $parcel = shipping_courier_bigship_parcel((array) ($context['items'] ?? []), $settings);
    $weight = (float) $parcel['weight'];
    $length = (float) $parcel['length'];
    $width = (float) $parcel['width'];
    $height = (float) $parcel['height'];
    if (!preg_match('/^[1-9][0-9]{5}$/', $sourcePincode) || min($weight, $length, $width, $height) <= 0) {
        return $fallback($quote, 'bigship_origin_or_parcel_invalid', 'Warehouse pincode or parcel dimensions are invalid.');
    }

    $paymentModeId = shipping_courier_bigship_payment_mode_id(['payment_method' => $paymentMethod]);
    $invoiceValue = round(max(0.0, (float) ($context['invoice_value'] ?? $subtotal)), 2);
    $riskTypeId = (int) ($context['risk_type_id'] ?? shipping_courier_bigship_risk_type_id());

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

    $response = shipping_courier_bigship_client()->rates($ratePayload, $deadlineNs);
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

    $charges = shipping_courier_bigship_split_rate_charges(
        $rate,
        (float) ($quote['cod_fee'] ?? 0),
        $paymentMethod === 'cod'
    );
    $totalCharge = $charges['shipping_total'];
    $courierName = (string) $rate['courier_name'];
    $courierId = (int) $rate['courier_id'];
    $provider = shipping_courier_provider_name();

    return [
        // Bigship totalCharge includes COD. Split the component for checkout display
        // while keeping shipping_total exactly equal to the provider's quoted total.
        'base_shipping' => $charges['base_shipping'],
        'cod_fee' => $charges['cod_fee'],
        'shipping_total' => $totalCharge,
        'source' => substr($provider !== '' ? $provider : 'courier', 0, 32),
        'courier_name' => $courierName !== '' ? $courierName : $provider,
        'courier_id' => $courierId,
    ];
}

function shipping_courier_bigship_rate_component(array $candidate, array $keys): ?float
{
    $normalizedKeys = array_map(
        static fn(string $key): string => strtolower(preg_replace('/[^a-z0-9]/i', '', $key) ?: ''),
        $keys
    );

    foreach ($candidate as $key => $value) {
        $normalizedKey = strtolower(preg_replace('/[^a-z0-9]/i', '', (string) $key) ?: '');
        if (in_array($normalizedKey, $normalizedKeys, true) && is_numeric($value)) {
            return (float) $value;
        }
    }

    foreach ($candidate as $value) {
        if (!is_array($value)) {
            continue;
        }
        $component = shipping_courier_bigship_rate_component($value, $keys);
        if ($component !== null) {
            return $component;
        }
    }

    return null;
}

function shipping_courier_bigship_split_rate_charges(array $rate, float $fallbackCodFee, bool $isCod): array
{
    $totalCharge = max(0.0, round((float) ($rate['total_charge'] ?? 0), 2));
    $codFee = 0.0;

    if ($isCod) {
        $providerCodFee = $rate['cod_charge'] ?? null;
        $codFee = $providerCodFee !== null && is_numeric($providerCodFee)
            ? (float) $providerCodFee
            : $fallbackCodFee;
        $codFee = min($totalCharge, max(0.0, round($codFee, 2)));
    }

    return [
        'base_shipping' => round(max(0.0, $totalCharge - $codFee), 2),
        'cod_fee' => $codFee,
        'shipping_total' => $totalCharge,
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
            'cod_charge' => shipping_courier_bigship_rate_component($candidate, [
                'codCharge',
                'cod_charge',
                'codCharges',
                'cod_charges',
                'codFee',
                'cod_fee',
            ]),
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
        return ExternalUrlPolicy::sanitize((string) $value);
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
    $labelUrl = ExternalUrlPolicy::sanitize((string) ($metadata['label_url'] ?? ''));
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

function shipping_courier_bigship_client(): BigshipService
{
    static $client = null;
    if (!$client instanceof BigshipService) {
        $client = new BigshipService(shipping_courier_settings());
    }
    return $client;
}

function shipping_courier_reference_table_ready(mysqli $conn): bool
{
    try {
        $stmt = $conn->prepare(
            "SELECT COUNT(*) AS total
             FROM information_schema.TABLES
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = 'shipping_courier_reference_cache'"
        );
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        return (int) ($row['total'] ?? 0) > 0;
    } catch (Throwable $e) {
        return false;
    }
}

function shipping_courier_reference_cache_get(mysqli $conn, string $type, string $segment = ''): ?array
{
    if (!shipping_courier_reference_table_ready($conn)) {
        return null;
    }
    $provider = shipping_courier_provider_name();
    $stmt = $conn->prepare(
        "SELECT payload_json
         FROM shipping_courier_reference_cache
         WHERE provider = ? AND reference_type = ? AND segment_type = ?
           AND expires_at > NOW()
         LIMIT 1"
    );
    $stmt->bind_param('sss', $provider, $type, $segment);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $decoded = !empty($row['payload_json']) ? json_decode((string) $row['payload_json'], true) : null;
    return is_array($decoded) ? $decoded : null;
}

function shipping_courier_reference_cache_put(mysqli $conn, string $type, array $payload, string $segment = ''): void
{
    if (!shipping_courier_reference_table_ready($conn)) {
        return;
    }
    $provider = shipping_courier_provider_name();
    $json = json_encode($payload, JSON_UNESCAPED_SLASHES);
    if (!is_string($json)) {
        return;
    }
    $stmt = $conn->prepare(
        "INSERT INTO shipping_courier_reference_cache
            (provider, reference_type, segment_type, payload_json, fetched_at, expires_at)
         VALUES (?, ?, ?, ?, NOW(), DATE_ADD(NOW(), INTERVAL 12 HOUR))
         ON DUPLICATE KEY UPDATE
            payload_json = VALUES(payload_json),
            fetched_at = NOW(),
            expires_at = VALUES(expires_at)"
    );
    $stmt->bind_param('ssss', $provider, $type, $segment, $json);
    $stmt->execute();
}

function shipping_courier_reference_cache_forget(mysqli $conn, string $type): void
{
    if (!shipping_courier_reference_table_ready($conn)) {
        return;
    }
    $provider = shipping_courier_provider_name();
    $stmt = $conn->prepare(
        'DELETE FROM shipping_courier_reference_cache WHERE provider = ? AND reference_type = ?'
    );
    $stmt->bind_param('ss', $provider, $type);
    $stmt->execute();
}

function shipping_courier_bigship_sync_reference_data(mysqli $conn): array
{
    if (!shipping_courier_enabled() || !shipping_courier_provider_configured()) {
        return shipping_courier_result(false, 'Bigship provider is disabled or incomplete.');
    }
    $client = shipping_courier_bigship_client();
    $segment = shipping_courier_bigship_segment(shipping_courier_settings());
    // Remove credentials that an older version may have cached with profile data.
    shipping_courier_reference_cache_forget($conn, 'profile');
    $calls = [
        'profile' => $client->profile(),
        'warehouses' => $client->warehouses(),
        'payment_modes' => $client->paymentModes($segment),
        'risk_types' => $client->riskTypes(['segment_type' => $segment]),
    ];
    if ($segment === 'hyperlocal') {
        $calls['packages'] = $client->packages();
    }

    $failed = [];
    $failureMessages = [];
    foreach ($calls as $type => $response) {
        if (!empty($response['ok']) && is_array($response['body'] ?? null)) {
            // Profile responses can contain account credentials and are only
            // used as a connection check; never persist them in the cache.
            if ($type !== 'profile') {
                shipping_courier_reference_cache_put($conn, $type, (array) $response['body'], $type === 'warehouses' ? '' : $segment);
            }
        } else {
            $failed[] = $type;
            $failureMessages[] = shipping_courier_api_failure_message(
                is_array($response) ? $response : [],
                str_replace('_', ' ', $type)
            );
        }
    }
    return shipping_courier_result(empty($failed), empty($failed)
        ? 'Bigship profile and reference data synchronized.'
        : 'Bigship reference synchronization failed for: ' . implode(', ', $failed) . '. '
            . implode(' ', $failureMessages), [
            'responses' => $calls,
            'failed_references' => $failed,
        ]);
}

function shipping_courier_bigship_reference_rows(array $payload): array
{
    foreach (['data', 'result', 'list', 'items', 'records', 'warehouses'] as $container) {
        if (is_array($payload[$container] ?? null)) {
            return shipping_courier_bigship_reference_rows((array) $payload[$container]);
        }
    }
    if (array_is_list($payload)) {
        return array_values(array_filter($payload, 'is_array'));
    }
    return $payload !== [] ? [$payload] : [];
}

function shipping_courier_bigship_reference_id(?array $payload, array $nameHints, array $idKeys): int
{
    if (!is_array($payload)) {
        return 0;
    }
    foreach (shipping_courier_bigship_reference_rows($payload) as $row) {
        $name = strtolower(shipping_courier_response_value($row, [
            'name', 'title', 'label', 'mode', 'risk_type', 'payment_mode',
            'paymentModeName', 'riskName', 'slug',
        ]));
        $matched = false;
        foreach ($nameHints as $hint) {
            if ($name !== '' && str_contains($name, strtolower((string) $hint))) {
                $matched = true;
                break;
            }
        }
        if (!$matched) {
            continue;
        }
        $id = shipping_courier_response_value($row, $idKeys);
        if (is_numeric($id) && (int) $id > 0) {
            return (int) $id;
        }
    }
    return 0;
}

function shipping_courier_bigship_warehouse(): array
{
    $settings = shipping_courier_settings();
    $configuredId = max(0, (int) ($settings['bigship_warehouse_id'] ?? 0));
    $configuredPincode = trim((string) ($settings['bigship_warehouse_pincode'] ?? ''));
    $conn = $GLOBALS['conn'] ?? null;
    if ($conn instanceof mysqli) {
        $cached = shipping_courier_reference_cache_get($conn, 'warehouses');
        foreach (shipping_courier_bigship_reference_rows($cached ?? []) as $row) {
            $idValue = shipping_courier_response_value($row, ['id', 'warehouseId', 'WarehouseId', 'warehouse_id', 'pickup_location_id']);
            $id = is_numeric($idValue) ? (int) $idValue : 0;
            if ($id <= 0 || ($configuredId > 0 && $configuredId !== $id)) {
                continue;
            }
            $pincode = shipping_courier_response_value($row, ['pincode', 'pinCode', 'zipCode', 'zipcode', 'postal_code']);
            return [
                'id' => $id,
                'pincode' => preg_match('/^[1-9][0-9]{5}$/', $pincode) ? $pincode : $configuredPincode,
                'data' => $row,
            ];
        }
    }
    return ['id' => $configuredId, 'pincode' => $configuredPincode, 'data' => []];
}

function shipping_courier_bigship_document_path(int $orderId, string $type): string
{
    $type = $type === 'eway_bill' ? 'eway-bill' : 'invoice';
    return dirname(__DIR__, 2) . '/storage/private/shipping-documents/' . $orderId . '-' . $type . '.pdf';
}

function shipping_courier_bigship_save_document_upload(int $orderId, string $type, array $file): array
{
    if ($orderId <= 0 || !in_array($type, ['invoice', 'eway_bill'], true)) {
        return shipping_courier_result(false, 'Invalid courier document request.');
    }
    try {
        UploadPolicy::validate($file, ['pdf'], ['application/pdf'], 5 * 1024 * 1024);
    } catch (Throwable $e) {
        return shipping_courier_result(false, 'Courier documents must be valid PDF files.');
    }
    $target = shipping_courier_bigship_document_path($orderId, $type);
    $directory = dirname($target);
    try {
        UploadPolicy::ensureDirectory($directory, 0750);
    } catch (Throwable $e) {
        return shipping_courier_result(false, 'Unable to create private courier document storage.');
    }
    try {
        UploadPolicy::move($file, $directory, basename($target));
    } catch (Throwable $e) {
        return shipping_courier_result(false, 'Unable to save the courier document.');
    }
    return shipping_courier_result(true, ucfirst(str_replace('_', ' ', $type)) . ' uploaded.');
}

function shipping_courier_bigship_place_documents(int $orderId): array
{
    $settings = shipping_courier_settings();
    $documents = [];
    foreach ([
        'invoice' => (string) ($settings['bigship_invoice_field'] ?? 'invoice_file'),
        'eway_bill' => (string) ($settings['bigship_eway_bill_field'] ?? 'eway_bill_file'),
    ] as $type => $field) {
        $path = shipping_courier_bigship_document_path($orderId, $type);
        if ($field !== '' && is_file($path)) {
            $documents[$field] = $path;
        }
    }
    return $documents;
}

function shipping_courier_http_json(string $method, string $path, array $payload = [], array $headers = []): array
{
    if (!shipping_courier_enabled()) {
        return shipping_courier_result(false, 'Shipping courier plugin is disabled.', ['status' => 0, 'body' => null]);
    }
    if (!shipping_courier_provider_configured()) {
        return shipping_courier_result(false, 'Shipping courier provider is not configured.', ['status' => 0, 'body' => null]);
    }

    return shipping_courier_bigship_client()->request($method, $path, $payload, $headers);
}
