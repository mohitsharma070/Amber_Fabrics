<?php

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
        "SELECT oi.product_name, oi.fabric_name_snapshot, oi.fabric_sku_snapshot, oi.size, oi.color,
                oi.unit_type, oi.quantity, oi.quantity_meters, oi.price, oi.price_per_meter, oi.total, oi.line_total,
                f.shipping_weight_kg, f.parcel_length_cm, f.parcel_width_cm, f.parcel_height_cm
         FROM order_items oi
         LEFT JOIN fabrics f ON f.id = COALESCE(oi.product_id, oi.fabric_id)
         WHERE oi.order_id = ?
         ORDER BY oi.id ASC"
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
        'awb_code' => shipping_courier_response_value($body, ['awb_assigned', 'awb_code', 'awb', 'awb_number', 'awbNo', 'AwbNumber', 'waybill']),
        'courier_name' => shipping_courier_response_value($body, ['courier_name', 'courierName', 'courier_partner_name', 'courierPartnerName', 'courier', 'carrier_name', 'provider']),
        'tracking_id' => shipping_courier_response_value($body, ['awb_assigned', 'tracking_id', 'tracking_number', 'awb_code', 'awb', 'awb_number', 'awbNo', 'AwbNumber', 'waybill']),
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
    $conn = $GLOBALS['conn'] ?? null;
    if ($conn instanceof mysqli) {
        $segment = shipping_courier_bigship_segment(shipping_courier_settings());
        $cached = shipping_courier_reference_cache_get($conn, 'payment_modes', $segment);
        $hints = $paymentMethod === 'cod' ? ['cod', 'cash on delivery'] : ['prepaid', 'online'];
        $id = shipping_courier_bigship_reference_id($cached, $hints, ['id', 'paymentModeId', 'PaymentModeId', 'payment_mode_id']);
        if ($id > 0) {
            return $id;
        }
    }
    return $paymentMethod === 'cod' ? 2 : 1;
}

function shipping_courier_bigship_risk_type_id(): int
{
    $settings = shipping_courier_settings();
    $configured = max(0, (int) ($settings['bigship_risk_type_id'] ?? 0));
    $conn = $GLOBALS['conn'] ?? null;
    if ($conn instanceof mysqli) {
        $segment = shipping_courier_bigship_segment($settings);
        $cached = shipping_courier_reference_cache_get($conn, 'risk_types', $segment);
        $hint = trim((string) ($settings['bigship_risk_type'] ?? 'owner'));
        $id = shipping_courier_bigship_reference_id($cached, [$hint], ['id', 'riskTypeId', 'risk_type_id']);
        if ($id > 0) {
            return $id;
        }
    }
    return $configured > 0 ? $configured : 2;
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

/**
 * Allocate an order-level invoice value across line items in integer paise.
 * Using the final line for the rounding remainder guarantees the Bigship B2C
 * invariant: sum(products[].totalAmount) === MasterOrderInvoiceAmount.
 */
function shipping_courier_bigship_allocate_product_totals(array $items, float $invoiceAmount): array
{
    $count = count($items);
    if ($count === 0) {
        return [];
    }

    $targetPaise = max(0, (int) round($invoiceAmount * 100));
    $weights = [];
    $weightTotal = 0.0;
    foreach ($items as $item) {
        $weight = max(0.0, (float) ($item['line_total'] ?? $item['total'] ?? $item['subtotal'] ?? 0));
        $weights[] = $weight;
        $weightTotal += $weight;
    }
    if ($weightTotal <= 0) {
        $weights = array_fill(0, $count, 1.0);
        $weightTotal = (float) $count;
    }

    $allocated = [];
    $usedPaise = 0;
    foreach ($weights as $index => $weight) {
        $paise = $index === $count - 1
            ? $targetPaise - $usedPaise
            : (int) round($targetPaise * ($weight / $weightTotal));
        $paise = max(0, min($paise, $targetPaise - $usedPaise));
        $allocated[] = $paise / 100;
        $usedPaise += $paise;
    }

    return $allocated;
}

/**
 * Estimate one parcel from the actual order/cart quantities. The configured
 * parcel values remain minimum/fallback dimensions, while weight and height
 * grow with metres, pieces, and sets in the order.
 */
function shipping_courier_bigship_parcel(array $items, ?array $settings = null): array
{
    $settings = $settings ?? shipping_courier_settings();
    $minimumWeight = max(0.01, (float) ($settings['bigship_parcel_weight_kg'] ?? 0));
    $length = max(1.0, (float) ($settings['bigship_parcel_length_cm'] ?? 0));
    $width = max(1.0, (float) ($settings['bigship_parcel_width_cm'] ?? 0));
    $baseHeight = max(1.0, (float) ($settings['bigship_parcel_height_cm'] ?? 0));
    $weight = max(0.0, (float) ($settings['bigship_packaging_weight_kg'] ?? 0.10));
    $equivalentUnits = 0.0;

    foreach ($items as $item) {
        $unitType = strtolower(trim((string) ($item['unit_type'] ?? 'piece')));
        $quantity = $unitType === 'meter'
            ? (float) ($item['quantity_meters'] ?? $item['quantity'] ?? 0)
            : (float) ($item['quantity'] ?? $item['bundle_quantity'] ?? 0);
        $quantity = max(0.0, $quantity);
        $productWeight = max(0.0, (float) ($item['shipping_weight_kg'] ?? 0));
        $length = max($length, (float) ($item['parcel_length_cm'] ?? 0));
        $width = max($width, (float) ($item['parcel_width_cm'] ?? 0));
        $baseHeight = max($baseHeight, (float) ($item['parcel_height_cm'] ?? 0));
        if ($productWeight > 0) {
            $weight += $quantity * $productWeight;
        } elseif ($unitType === 'meter') {
            $weight += $quantity * max(0.01, (float) ($settings['bigship_weight_per_meter_kg'] ?? 0.25));
        } elseif (in_array($unitType, ['set', 'bundle'], true)) {
            $weight += $quantity * max(0.01, (float) ($settings['bigship_weight_per_set_kg'] ?? 0.75));
        } else {
            $weight += $quantity * max(0.01, (float) ($settings['bigship_weight_per_piece_kg'] ?? 0.35));
        }
        $equivalentUnits += $quantity;
    }

    if ($items === []) {
        $weight = $minimumWeight;
        $equivalentUnits = 1.0;
    }
    $heightPerUnit = max(0.0, (float) ($settings['bigship_parcel_height_per_unit_cm'] ?? 1.5));
    $maxHeight = max($baseHeight, (float) ($settings['bigship_parcel_max_height_cm'] ?? 60));
    $height = min($maxHeight, $baseHeight + max(0, (int) ceil($equivalentUnits) - 1) * $heightPerUnit);

    return [
        'weight' => ceil(max($minimumWeight, $weight) * 10) / 10,
        'length' => round($length, 2),
        'width' => round($width, 2),
        'height' => round($height, 2),
    ];
}

function shipping_courier_bigship_mobile_number(string $value): string
{
    $digits = preg_replace('/\D+/', '', $value) ?: '';
    $digits = ltrim($digits, '0');
    if (strlen($digits) === 12 && str_starts_with($digits, '91')) {
        $digits = substr($digits, 2);
    }
    return preg_match('/^[6-9][0-9]{9}$/', $digits) ? $digits : '';
}

function shipping_courier_bigship_order_request(array $payload): array
{
    $order = (array) ($payload['order'] ?? []);
    $items = (array) ($payload['items'] ?? []);
    $settings = shipping_courier_settings();
    $segment = shipping_courier_bigship_segment($settings);

    $warehouse = shipping_courier_bigship_warehouse();
    $warehouseId = (int) ($warehouse['id'] ?? 0);
    $parcel = shipping_courier_bigship_parcel($items, $settings);
    $weight = (float) $parcel['weight'];
    $length = (float) $parcel['length'];
    $width = (float) $parcel['width'];
    $height = (float) $parcel['height'];
    if ($warehouseId <= 0) {
        return shipping_courier_result(false, 'Bigship warehouse id is not configured.');
    }
    if ($weight <= 0) {
        return shipping_courier_result(false, 'Bigship parcel weight must be greater than zero.');
    }

    $paymentModeId = shipping_courier_bigship_payment_mode_id($order);
    $invoiceAmount = shipping_courier_bigship_invoice_amount($order);
    $shippingName = trim((string) ($order['customer_name'] ?? ''));
    $shippingMobile = shipping_courier_bigship_mobile_number((string) ($order['customer_phone'] ?? ''));
    $shippingAddress = trim((string) ($order['address'] ?? ''));
    $shippingZip = trim((string) ($order['pincode'] ?? ''));
    $shippingCity = trim((string) ($order['city'] ?? ''));
    $shippingState = trim((string) ($order['state'] ?? ''));
    $shippingCountry = trim((string) ($order['country'] ?? 'India'));

    if ($shippingName === '' || $shippingAddress === '' || $shippingZip === '' || $shippingCity === '' || $shippingState === '') {
        return shipping_courier_result(false, 'Order shipping address is incomplete for Bigship order creation.');
    }
    if ($shippingMobile === '') {
        return shipping_courier_result(false, 'Order shipping phone must be a valid 10-digit Indian mobile number.');
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
    $allocatedTotals = shipping_courier_bigship_allocate_product_totals($items, $invoiceAmount);
    foreach ($items as $index => $item) {
        $name = trim((string) ($item['product_name'] ?? $item['fabric_name_snapshot'] ?? 'Item'));
        $qty = max(1, (int) round((float) ($item['quantity'] ?? $item['quantity_meters'] ?? 1)));
        $lineAmount = round(max(0.0, (float) ($allocatedTotals[$index] ?? 0)), 2);
        $unitAmount = round($lineAmount / $qty, 2);
        $products[] = [
            'productName' => $name !== '' ? $name : 'Fabric Item',
            'qty' => $qty,
            'amount' => $unitAmount,
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

    $response = shipping_courier_bigship_client()->downloadDocuments($customGlobalOrderId, $documentType);
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
