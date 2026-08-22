<?php

final class OrderPersistenceService
{
    public static function insertOrder(mysqli $conn, array $order): int
    {
        $orderNumber = (string) $order['order_number'];
        $fullName = (string) $order['customer_name'];
        $phone = (string) $order['customer_phone'];
        $email = (string) $order['customer_email'];
        $address = (string) $order['address'];
        $city = (string) $order['city'];
        $state = (string) $order['state'];
        $pincode = (string) $order['pincode'];
        $country = (string) $order['country'];
        $subtotal = (float) $order['subtotal'];
        $shippingAmount = (float) $order['shipping_amount'];
        $discountAmount = (float) $order['discount_amount'];
        $totalAmount = (float) $order['total_amount'];
        $paymentMethod = (string) $order['payment_method'];
        $notes = (string) $order['notes'];
        $shippingAddressJson = $order['shipping_address_json'] ?? null;
        $customerId = isset($order['customer_id']) ? (int) $order['customer_id'] : null;
        $couponId = (int) ($order['coupon_id'] ?? 0);
        $couponCode = (string) ($order['coupon_code'] ?? '');
        $shippingQuoteToken = (string) ($order['shipping_quote_token'] ?? '');
        $shippingSource = (string) ($order['shipping_source'] ?? 'manual');
        $courierId = (int) ($order['courier_id'] ?? 0);
        $courierName = (string) ($order['courier_name'] ?? '');
        $codFee = (float) ($order['cod_fee'] ?? 0.0);
        $baseShipping = (float) ($order['base_shipping'] ?? 0.0);

        if (PaymentService::orders_structured_financial_columns_ready($conn)) {
            $stmt = $conn->prepare(
                "INSERT INTO orders (
                    order_number, customer_name, customer_phone, customer_email,
                    address, city, state, pincode, country,
                    subtotal, shipping_amount, discount_amount, total_amount,
                    payment_method, payment_status, order_status, order_notes, shipping_address,
                    customer_id, currency, shipping_cost, total, status, notes,
                    coupon_id, coupon_code, coupon_discount,
                    shipping_quote_token, shipping_source, courier_id, courier_name, cod_fee, base_shipping
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending', 'pending', ?, ?, ?, 'INR', ?, ?, 'pending', ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
            );
            $stmt->bind_param(
                'sssssssssddddsssiddsisdssisdd',
                $orderNumber,
                $fullName,
                $phone,
                $email,
                $address,
                $city,
                $state,
                $pincode,
                $country,
                $subtotal,
                $shippingAmount,
                $discountAmount,
                $totalAmount,
                $paymentMethod,
                $notes,
                $shippingAddressJson,
                $customerId,
                $shippingAmount,
                $totalAmount,
                $notes,
                $couponId,
                $couponCode,
                $discountAmount,
                $shippingQuoteToken,
                $shippingSource,
                $courierId,
                $courierName,
                $codFee,
                $baseShipping
            );
        } else {
            $stmt = $conn->prepare(
                "INSERT INTO orders (
                    order_number, customer_name, customer_phone, customer_email,
                    address, city, state, pincode, country,
                    subtotal, shipping_amount, discount_amount, total_amount,
                    payment_method, payment_status, order_status, order_notes, shipping_address,
                    customer_id, currency, shipping_cost, total, status, notes
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending', 'pending', ?, ?, ?, 'INR', ?, ?, 'pending', ?)"
            );
            $stmt->bind_param(
                'sssssssssddddsssidds',
                $orderNumber,
                $fullName,
                $phone,
                $email,
                $address,
                $city,
                $state,
                $pincode,
                $country,
                $subtotal,
                $shippingAmount,
                $discountAmount,
                $totalAmount,
                $paymentMethod,
                $notes,
                $shippingAddressJson,
                $customerId,
                $shippingAmount,
                $totalAmount,
                $notes
            );
        }
        $stmt->execute();
        return (int) $conn->insert_id;
    }

    public static function saveDeliveryEstimate(mysqli $conn, int $orderId, array $estimate): void
    {
        if ($orderId <= 0 || $estimate === []) {
            return;
        }
        $stmt = $conn->prepare(
            "UPDATE orders
             SET serviceability_status = ?, estimated_dispatch_start = ?, estimated_dispatch_end = ?,
                 estimated_delivery_start = ?, estimated_delivery_end = ?
             WHERE id = ?"
        );
        $serviceability = (string) ($estimate['serviceability_status'] ?? 'estimated');
        $estimatedDispatchStart = $estimate['estimated_dispatch_start'] ?? null;
        $estimatedDispatchEnd = $estimate['estimated_dispatch_end'] ?? null;
        $estimatedDeliveryStart = $estimate['estimated_delivery_start'] ?? null;
        $estimatedDeliveryEnd = $estimate['estimated_delivery_end'] ?? null;
        $stmt->bind_param(
            'sssssi',
            $serviceability,
            $estimatedDispatchStart,
            $estimatedDispatchEnd,
            $estimatedDeliveryStart,
            $estimatedDeliveryEnd,
            $orderId
        );
        $stmt->execute();
    }

    public static function insertItems(mysqli $conn, int $orderId, array $items): void
    {
        $supportsVariant = order_items_supports_variant($conn);
        $supportsTax = order_items_supports_tax_snapshot($conn);
        $supportsCost = order_items_supports_cost_snapshot($conn);
        $stmt = self::prepareItemInsert($conn, $supportsVariant, $supportsTax, $supportsCost);

        foreach ($items as $item) {
            $productId = (int) $item['product_id'];
            $productName = (string) $item['product_name'];
            $size = (string) $item['size'];
            $color = (string) $item['color'];
            $unitType = (string) $item['unit_type'];
            $quantity = (float) $item['quantity'];
            $price = (float) $item['price'];
            $total = (float) $item['total'];
            $sku = (string) $item['sku'];
            $bundleQuantity = isset($item['bundle_quantity']) ? (int) $item['bundle_quantity'] : null;
            $meterLength = isset($item['meter_length']) ? (float) $item['meter_length'] : null;
            $packLabel = isset($item['pack_label']) ? (string) $item['pack_label'] : null;
            $unitsPerSet = isset($item['units_per_set']) ? (int) $item['units_per_set'] : null;
            $variantId = ($supportsVariant && ($item['variant_id'] ?? 0) > 0) ? (int) $item['variant_id'] : null;
            $costSnapshot = (float) ($item['cost_price_snapshot'] ?? 0.0);
            $taxableAmount = (float) ($item['taxable_amount'] ?? $total);
            $itemDiscount = (float) ($item['discount_amount'] ?? 0.0);
            $gstRate = (float) ($item['gst_rate_snapshot'] ?? 0.0);
            $gstAmount = (float) ($item['gst_amount'] ?? 0.0);
            $cgstAmount = (float) ($item['cgst_amount'] ?? 0.0);
            $sgstAmount = (float) ($item['sgst_amount'] ?? 0.0);
            $igstAmount = (float) ($item['igst_amount'] ?? 0.0);
            $taxType = (string) ($item['tax_type'] ?? 'none');
            $hsnCode = (string) ($item['hsn_code_snapshot'] ?? '');

            if ($supportsVariant && $supportsTax && $supportsCost) {
                $stmt->bind_param(
                    'iissssdddissddddidsiidddddddss',
                    $orderId, $productId, $productName, $size, $color, $unitType,
                    $quantity, $price, $total,
                    $productId, $productName, $sku,
                    $quantity, $price, $total, $costSnapshot,
                    $bundleQuantity, $meterLength, $packLabel, $unitsPerSet, $variantId,
                    $taxableAmount, $itemDiscount, $gstRate, $gstAmount, $cgstAmount, $sgstAmount, $igstAmount, $taxType, $hsnCode
                );
            } elseif ($supportsVariant && $supportsTax) {
                $stmt->bind_param(
                    'iissssdddissdddidsiidddddddss',
                    $orderId, $productId, $productName, $size, $color, $unitType,
                    $quantity, $price, $total,
                    $productId, $productName, $sku,
                    $quantity, $price, $total,
                    $bundleQuantity, $meterLength, $packLabel, $unitsPerSet, $variantId,
                    $taxableAmount, $itemDiscount, $gstRate, $gstAmount, $cgstAmount, $sgstAmount, $igstAmount, $taxType, $hsnCode
                );
            } elseif ($supportsVariant && $supportsCost) {
                $stmt->bind_param(
                    'iissssdddissddddidsii',
                    $orderId, $productId, $productName, $size, $color, $unitType,
                    $quantity, $price, $total,
                    $productId, $productName, $sku,
                    $quantity, $price, $total, $costSnapshot,
                    $bundleQuantity, $meterLength, $packLabel, $unitsPerSet, $variantId
                );
            } elseif ($supportsVariant) {
                $stmt->bind_param(
                    'iissssdddissdddidsii',
                    $orderId, $productId, $productName, $size, $color, $unitType,
                    $quantity, $price, $total,
                    $productId, $productName, $sku,
                    $quantity, $price, $total,
                    $bundleQuantity, $meterLength, $packLabel, $unitsPerSet, $variantId
                );
            } elseif ($supportsTax && $supportsCost) {
                $stmt->bind_param(
                    'iissssdddissddddidsidddddddss',
                    $orderId, $productId, $productName, $size, $color, $unitType,
                    $quantity, $price, $total,
                    $productId, $productName, $sku,
                    $quantity, $price, $total, $costSnapshot,
                    $bundleQuantity, $meterLength, $packLabel, $unitsPerSet,
                    $taxableAmount, $itemDiscount, $gstRate, $gstAmount, $cgstAmount, $sgstAmount, $igstAmount, $taxType, $hsnCode
                );
            } elseif ($supportsTax) {
                $stmt->bind_param(
                    'iissssdddissdddidsidddddddss',
                    $orderId, $productId, $productName, $size, $color, $unitType,
                    $quantity, $price, $total,
                    $productId, $productName, $sku,
                    $quantity, $price, $total,
                    $bundleQuantity, $meterLength, $packLabel, $unitsPerSet,
                    $taxableAmount, $itemDiscount, $gstRate, $gstAmount, $cgstAmount, $sgstAmount, $igstAmount, $taxType, $hsnCode
                );
            } elseif ($supportsCost) {
                $stmt->bind_param(
                    'iissssdddissddddidsi',
                    $orderId, $productId, $productName, $size, $color, $unitType,
                    $quantity, $price, $total,
                    $productId, $productName, $sku,
                    $quantity, $price, $total, $costSnapshot,
                    $bundleQuantity, $meterLength, $packLabel, $unitsPerSet
                );
            } else {
                $stmt->bind_param(
                    'iissssdddissdddidsi',
                    $orderId, $productId, $productName, $size, $color, $unitType,
                    $quantity, $price, $total,
                    $productId, $productName, $sku,
                    $quantity, $price, $total,
                    $bundleQuantity, $meterLength, $packLabel, $unitsPerSet
                );
            }
            $stmt->execute();
        }
    }

    public static function insertPendingPayment(
        mysqli $conn,
        int $orderId,
        string $paymentMethod,
        float $totalAmount
    ): void {
        $stmt = $conn->prepare(
            "INSERT INTO payments (order_id, payment_method, payment_status, transaction_id, amount)
             VALUES (?, ?, 'pending', NULL, ?)"
        );
        $stmt->bind_param('isd', $orderId, $paymentMethod, $totalAmount);
        $stmt->execute();
    }

    public static function markZeroAmountPaid(mysqli $conn, int $orderId, string $paymentMethod): void
    {
        $note = 'Zero-amount order auto-confirmed. No payment collection required.';
        $order = $conn->prepare(
            "UPDATE orders
             SET payment_status = 'paid',
                 order_status = 'confirmed',
                 status = 'confirmed',
                 notes = CASE WHEN notes IS NULL OR notes = '' THEN ? ELSE CONCAT(notes, '\n', ?) END,
                 updated_at = NOW()
             WHERE id = ?"
        );
        $order->bind_param('ssi', $note, $note, $orderId);
        $order->execute();

        $payment = $conn->prepare(
            "UPDATE payments
             SET payment_status = 'paid'
             WHERE order_id = ? AND payment_method = ?"
        );
        $payment->bind_param('is', $orderId, $paymentMethod);
        $payment->execute();
    }

    public static function upsertQuotedShipment(
        mysqli $conn,
        int $orderId,
        string $courierName,
        float $shippingCost
    ): void {
        if ($orderId <= 0 || trim($courierName) === '') {
            return;
        }
        $stmt = $conn->prepare(
            "INSERT INTO shipments (order_id, courier_name, tracking_id, tracking_url, shipping_cost, shipped_at, delivered_at)
             VALUES (?, ?, '', '', ?, NULL, NULL)
             ON DUPLICATE KEY UPDATE
                courier_name = VALUES(courier_name),
                shipping_cost = VALUES(shipping_cost)"
        );
        $stmt->bind_param('isd', $orderId, $courierName, $shippingCost);
        $stmt->execute();
    }

    private static function prepareItemInsert(
        mysqli $conn,
        bool $supportsVariant,
        bool $supportsTax,
        bool $supportsCost
    ): mysqli_stmt {
        if ($supportsVariant && $supportsTax && $supportsCost) {
            return $conn->prepare(
                "INSERT INTO order_items (
                    order_id, product_id, product_name, size, color, unit_type, quantity, price, total,
                    fabric_id, fabric_name_snapshot, fabric_sku_snapshot, quantity_meters, price_per_meter, line_total, cost_price_snapshot,
                    bundle_quantity, meter_length, pack_label, units_per_set, variant_id,
                    taxable_amount, discount_amount, gst_rate_snapshot, gst_amount, cgst_amount, sgst_amount, igst_amount, tax_type, hsn_code_snapshot
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
            );
        }
        if ($supportsVariant && $supportsTax) {
            return $conn->prepare(
                "INSERT INTO order_items (
                    order_id, product_id, product_name, size, color, unit_type, quantity, price, total,
                    fabric_id, fabric_name_snapshot, fabric_sku_snapshot, quantity_meters, price_per_meter, line_total,
                    bundle_quantity, meter_length, pack_label, units_per_set, variant_id,
                    taxable_amount, discount_amount, gst_rate_snapshot, gst_amount, cgst_amount, sgst_amount, igst_amount, tax_type, hsn_code_snapshot
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
            );
        }
        if ($supportsVariant && $supportsCost) {
            return $conn->prepare(
                "INSERT INTO order_items (
                    order_id, product_id, product_name, size, color, unit_type, quantity, price, total,
                    fabric_id, fabric_name_snapshot, fabric_sku_snapshot, quantity_meters, price_per_meter, line_total, cost_price_snapshot,
                    bundle_quantity, meter_length, pack_label, units_per_set, variant_id
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
            );
        }
        if ($supportsVariant) {
            return $conn->prepare(
                "INSERT INTO order_items (
                    order_id, product_id, product_name, size, color, unit_type, quantity, price, total,
                    fabric_id, fabric_name_snapshot, fabric_sku_snapshot, quantity_meters, price_per_meter, line_total,
                    bundle_quantity, meter_length, pack_label, units_per_set, variant_id
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
            );
        }
        if ($supportsTax && $supportsCost) {
            return $conn->prepare(
                "INSERT INTO order_items (
                    order_id, product_id, product_name, size, color, unit_type, quantity, price, total,
                    fabric_id, fabric_name_snapshot, fabric_sku_snapshot, quantity_meters, price_per_meter, line_total, cost_price_snapshot,
                    bundle_quantity, meter_length, pack_label, units_per_set,
                    taxable_amount, discount_amount, gst_rate_snapshot, gst_amount, cgst_amount, sgst_amount, igst_amount, tax_type, hsn_code_snapshot
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
            );
        }
        if ($supportsTax) {
            return $conn->prepare(
                "INSERT INTO order_items (
                    order_id, product_id, product_name, size, color, unit_type, quantity, price, total,
                    fabric_id, fabric_name_snapshot, fabric_sku_snapshot, quantity_meters, price_per_meter, line_total,
                    bundle_quantity, meter_length, pack_label, units_per_set,
                    taxable_amount, discount_amount, gst_rate_snapshot, gst_amount, cgst_amount, sgst_amount, igst_amount, tax_type, hsn_code_snapshot
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
            );
        }
        if ($supportsCost) {
            return $conn->prepare(
                "INSERT INTO order_items (
                    order_id, product_id, product_name, size, color, unit_type, quantity, price, total,
                    fabric_id, fabric_name_snapshot, fabric_sku_snapshot, quantity_meters, price_per_meter, line_total, cost_price_snapshot,
                    bundle_quantity, meter_length, pack_label, units_per_set
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
            );
        }
        return $conn->prepare(
            "INSERT INTO order_items (
                order_id, product_id, product_name, size, color, unit_type, quantity, price, total,
                fabric_id, fabric_name_snapshot, fabric_sku_snapshot, quantity_meters, price_per_meter, line_total,
                bundle_quantity, meter_length, pack_label, units_per_set
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
        );
    }
}
