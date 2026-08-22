<?php

final class OrderItemSnapshotService
{
    public static function cartReferences(array $cart): array
    {
        $productIds = [];
        $variantIds = [];
        foreach (array_keys($cart) as $key) {
            [$productId, $variantId] = CartService::cart_parse_key((string) $key);
            if ($productId > 0) {
                $productIds[] = $productId;
            }
            if ($variantId > 0) {
                $variantIds[] = $variantId;
            }
        }
        return [
            'product_ids' => array_values(array_unique($productIds)),
            'variant_ids' => array_values(array_unique($variantIds)),
        ];
    }

    public static function lockProducts(mysqli $conn, array $productIds): array
    {
        $productIds = array_values(array_unique(array_filter(array_map('intval', $productIds))));
        if ($productIds === []) {
            return [];
        }
        $placeholders = implode(',', array_fill(0, count($productIds), '?'));
        $types = str_repeat('i', count($productIds));
        $stmt = $conn->prepare(
            "SELECT id, name, sku, product_type, unit_type, meter_options, min_order_meters, qty_step, stock, stock_meters, is_available, status, price, sale_price, cost_price, size, color,
                    gst_rate, hsn_code, shipping_weight_kg, parcel_length_cm, parcel_width_cm, parcel_height_cm
             FROM fabrics
             WHERE id IN ($placeholders)
             FOR UPDATE"
        );
        $stmt->bind_param($types, ...$productIds);
        $stmt->execute();
        $productMap = [];
        foreach ($stmt->get_result()->fetch_all(MYSQLI_ASSOC) as $row) {
            $productMap[(int) $row['id']] = $row;
        }
        return $productMap;
    }

    public static function build(
        array $cart,
        array $cartSizes,
        array $cartMeterMap,
        array $productMap,
        array $variantMap,
        float $defaultGstRate,
        string $defaultHsnCode
    ): array {
        $orderItems = [];
        $subtotal = 0.00;

        foreach ($cart as $cartKey => $quantityRaw) {
            [$productId, $variantId] = CartService::cart_parse_key((string) $cartKey);
            if (!isset($productMap[$productId])) {
                throw new RuntimeException('One of the products is no longer available.');
            }

            $product = $productMap[$productId];
            $variant = ($variantId > 0 && isset($variantMap[$variantId])) ? $variantMap[$variantId] : null;
            $requiresVariant = (($product['product_type'] ?? 'simple') === 'variable');
            if (($requiresVariant && $variantId <= 0) || (!$requiresVariant && $variantId > 0)) {
                throw new RuntimeException('Your product selection changed. Please review ' . ($product['name'] ?? 'the product') . ' in your cart.');
            }
            if ($variantId > 0 && (!$variant || (int) ($variant['fabric_id'] ?? 0) !== $productId || (int) ($variant['is_active'] ?? 0) !== 1)) {
                throw new RuntimeException('Selected variant is unavailable for ' . ($product['name'] ?? 'product'));
            }

            $unitType = in_array((string) ($product['unit_type'] ?? ''), ['meter', 'piece', 'set'], true)
                ? (string) $product['unit_type']
                : 'meter';
            $minimumQuantity = $unitType === 'meter'
                ? normalize_meter_quantity($product['min_order_meters'] ?? 1, 1.0)
                : (float) max(1, (int) round((float) ($product['min_order_meters'] ?? 1)));
            $quantity = normalize_quantity_by_unit($quantityRaw, $unitType, (float) $minimumQuantity);
            if ($unitType === 'meter') {
                $quantityStep = is_numeric($product['qty_step'] ?? null) ? (float) $product['qty_step'] : 0.0;
                if (!meter_qty_respects_step((float) $quantity, (float) $minimumQuantity, (float) $quantityStep)) {
                    throw new RuntimeException('Invalid meter quantity step for ' . ($product['name'] ?? 'product'));
                }
            }
            if (($unitType === 'piece' || $unitType === 'set') && abs($quantity - round($quantity)) > 0.0001) {
                throw new RuntimeException('Invalid quantity for ' . ($product['name'] ?? 'product') . '. Whole units only.');
            }
            if (($product['status'] ?? '') !== 'active' || empty($product['is_available'])) {
                throw new RuntimeException('Product unavailable: ' . ($product['name'] ?? 'Unknown'));
            }

            if ($variant) {
                $selectedColor = (string) ($variant['color'] ?? '');
                $selectedSize = CartService::variant_size_display($variant, $unitType);
            } else {
                $selectedColor = (string) ($product['color'] ?? '');
                $selectedSize = trim((string) ($cartSizes[$cartKey] ?? ''));
            }
            $unitsPerSet = ($variant && $unitType === 'set') ? normalize_units_per_set($variant['units_per_set'] ?? 1) : null;
            $packLabel = ($variant && $unitType === 'set')
                ? trim((string) (($variant['pack_label'] ?? '') ?: format_pack_label((int) $unitsPerSet)))
                : null;

            if ($variant) {
                $availableStock = ($unitType === 'piece' || $unitType === 'set')
                    ? (float) ($variant['stock'] ?? 0)
                    : (float) ($variant['stock_meters'] ?? 0);
            } else {
                $availableStock = ($unitType === 'piece' || $unitType === 'set')
                    ? (float) ($product['stock'] ?? 0)
                    : (float) ($product['stock_meters'] ?? 0);
            }
            if ($availableStock < $quantity) {
                throw new RuntimeException('Insufficient stock for ' . ($product['name'] ?? 'product'));
            }

            $regularPrice = (float) ($product['price'] ?? 0);
            $salePrice = (float) ($product['sale_price'] ?? 0);
            if ($variant && $variant['price_override'] !== null && (float) $variant['price_override'] > 0) {
                $unitPrice = (float) $variant['price_override'];
            } else {
                $unitPrice = ($salePrice > 0 && $salePrice < $regularPrice) ? $salePrice : $regularPrice;
            }
            $lineTotal = round($unitPrice * $quantity, 2);
            $subtotal = round($subtotal + $lineTotal, 2);

            $bundleMeterLength = null;
            $bundleQuantity = null;
            if (
                $unitType === 'meter'
                && isset($cartMeterMap[$cartKey])
                && is_numeric($cartMeterMap[$cartKey])
                && (float) $cartMeterMap[$cartKey] > 0
            ) {
                $bundleMeterLength = round((float) $cartMeterMap[$cartKey], 2);
                $allowedMeterOptions = CartService::parse_meter_options(
                    (string) ($product['meter_options'] ?? ''),
                    (float) $minimumQuantity
                );
                if (!CartService::meter_length_is_allowed($bundleMeterLength, $allowedMeterOptions)) {
                    throw new RuntimeException('Selected meter option is unavailable for ' . ($product['name'] ?? 'product'));
                }
                $bundleRatio = $bundleMeterLength > 0 ? ($quantity / $bundleMeterLength) : 0;
                if ($bundleRatio <= 0 || abs($bundleRatio - round($bundleRatio)) > 0.0001) {
                    throw new RuntimeException('Invalid meter bundle quantity for ' . ($product['name'] ?? 'product'));
                }
                $bundleQuantity = max(1, (int) round($bundleRatio));
            } elseif ($unitType === 'meter') {
                throw new RuntimeException('Missing meter length for ' . ($product['name'] ?? 'product'));
            }

            $orderItems[] = [
                'product_id' => $productId,
                'product_name' => (string) ($product['name'] ?? ''),
                'size' => $selectedSize,
                'color' => $selectedColor,
                'unit_type' => $unitType,
                'quantity' => $quantity,
                'price' => $unitPrice,
                'total' => $lineTotal,
                'sku' => (string) ($product['sku'] ?? ''),
                'variant_id' => $variantId,
                'bundle_quantity' => $bundleQuantity,
                'meter_length' => $bundleMeterLength,
                'pack_label' => $packLabel,
                'units_per_set' => $unitsPerSet,
                'cost_price_snapshot' => max(0.0, (float) ($product['cost_price'] ?? 0.0)),
                'effective_gst_rate' => ($product['gst_rate'] !== null && $product['gst_rate'] !== '')
                    ? max(0.0, (float) $product['gst_rate'])
                    : $defaultGstRate,
                'effective_hsn_code' => trim((string) ($product['hsn_code'] ?? '')) ?: $defaultHsnCode,
                'shipping_weight_kg' => $product['shipping_weight_kg'] ?? null,
                'parcel_length_cm' => $product['parcel_length_cm'] ?? null,
                'parcel_width_cm' => $product['parcel_width_cm'] ?? null,
                'parcel_height_cm' => $product['parcel_height_cm'] ?? null,
            ];
        }

        return ['items' => $orderItems, 'subtotal' => $subtotal];
    }
}
