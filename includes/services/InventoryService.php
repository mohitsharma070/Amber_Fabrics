<?php
require_once __DIR__ . '/../coupon-functions.php';

final class InventoryService
{
    /**
     * Adjust stock for a single fabric row with in-transaction row lock.
     * $direction must be "decrease" or "increase".
     */
    public static function adjust_fabric_stock(mysqli $conn, int $fabricId, string $unitType, float $qty, string $direction = 'decrease'): void
    {
        if ($fabricId <= 0) {
            throw new RuntimeException('Invalid fabric id for stock update.');
        }
        if ($qty <= 0) {
            return;
        }
        $unitType = in_array($unitType, ['meter', 'piece', 'set'], true) ? $unitType : 'meter';
        $direction = strtolower($direction) === 'increase' ? 'increase' : 'decrease';

        $lock = $conn->prepare("SELECT id, stock, stock_meters FROM fabrics WHERE id = ? FOR UPDATE");
        $lock->bind_param('i', $fabricId);
        $lock->execute();
        $fabric = $lock->get_result()->fetch_assoc();
        if (!$fabric) {
            throw new RuntimeException('Fabric not found for stock update.');
        }

        $useMeters = $unitType === 'meter';
        if ($useMeters) {
            $amount = round($qty, 2);
            if ($direction === 'decrease') {
                $available = (float) ($fabric['stock_meters'] ?? 0);
                if ($available < $amount) {
                    throw new RuntimeException('Insufficient stock during order confirmation.');
                }
                $stmt = $conn->prepare("UPDATE fabrics SET stock_meters = stock_meters - ? WHERE id = ? AND stock_meters >= ?");
                $stmt->bind_param('did', $amount, $fabricId, $amount);
            } else {
                $stmt = $conn->prepare("UPDATE fabrics SET stock_meters = stock_meters + ? WHERE id = ?");
                $stmt->bind_param('di', $amount, $fabricId);
            }
        } else {
            $amount = (int) round($qty);
            if ($amount <= 0) {
                return;
            }
            if ($direction === 'decrease') {
                $available = (int) round((float) ($fabric['stock'] ?? 0));
                if ($available < $amount) {
                    throw new RuntimeException('Insufficient stock during order confirmation.');
                }
                $stmt = $conn->prepare("UPDATE fabrics SET stock = stock - ? WHERE id = ? AND stock >= ?");
                $stmt->bind_param('iii', $amount, $fabricId, $amount);
            } else {
                $stmt = $conn->prepare("UPDATE fabrics SET stock = stock + ? WHERE id = ?");
                $stmt->bind_param('ii', $amount, $fabricId);
            }
        }
        $stmt->execute();
        if ($conn->affected_rows === 0) {
            throw new RuntimeException('Stock update conflict for fabric ' . $fabricId . '. Please try again.');
        }
    }

    /**
     * Adjust stock for a single variant row with in-transaction row lock.
     * $direction must be "decrease" or "increase".
     */
    public static function adjust_variant_stock(mysqli $conn, int $variantId, string $unitType, float $qty, string $direction = 'decrease'): void
    {
        if ($variantId <= 0) {
            throw new RuntimeException('Invalid variant id for stock update.');
        }
        if ($qty <= 0) {
            return;
        }
        $unitType = in_array($unitType, ['meter', 'piece', 'set'], true) ? $unitType : 'meter';
        $direction = strtolower($direction) === 'increase' ? 'increase' : 'decrease';

        $lock = $conn->prepare("SELECT id, stock, stock_meters FROM fabric_variants WHERE id = ? FOR UPDATE");
        $lock->bind_param('i', $variantId);
        $lock->execute();
        $variant = $lock->get_result()->fetch_assoc();
        if (!$variant) {
            throw new RuntimeException('Variant not found for stock update.');
        }

        $useMeters = $unitType === 'meter';
        if ($useMeters) {
            $amount = round($qty, 2);
            if ($direction === 'decrease') {
                $available = (float) ($variant['stock_meters'] ?? 0);
                if ($available < $amount) {
                    throw new RuntimeException('Insufficient variant stock during order confirmation.');
                }
                $stmt = $conn->prepare("UPDATE fabric_variants SET stock_meters = stock_meters - ? WHERE id = ? AND stock_meters >= ?");
                $stmt->bind_param('did', $amount, $variantId, $amount);
            } else {
                $stmt = $conn->prepare("UPDATE fabric_variants SET stock_meters = stock_meters + ? WHERE id = ?");
                $stmt->bind_param('di', $amount, $variantId);
            }
        } else {
            $amount = (int) round($qty);
            if ($amount <= 0) {
                return;
            }
            if ($direction === 'decrease') {
                $available = (int) round((float) ($variant['stock'] ?? 0));
                if ($available < $amount) {
                    throw new RuntimeException('Insufficient variant stock during order confirmation.');
                }
                $stmt = $conn->prepare("UPDATE fabric_variants SET stock = stock - ? WHERE id = ? AND stock >= ?");
                $stmt->bind_param('iii', $amount, $variantId, $amount);
            } else {
                $stmt = $conn->prepare("UPDATE fabric_variants SET stock = stock + ? WHERE id = ?");
                $stmt->bind_param('ii', $amount, $variantId);
            }
        }
        $stmt->execute();
        if ($conn->affected_rows === 0) {
            throw new RuntimeException('Stock update conflict for variant ' . $variantId . '. Please try again.');
        }
    }

    /**
     * Return all variants for a fabric ordered by sort_order then id.
     */
    public static function get_fabric_variants(mysqli $conn, int $fabricId): array
    {
        $stmt = $conn->prepare(
            "SELECT id, fabric_id, color, size, sku, image, image2, image3, image4, video, pack_label, units_per_set, price_override, stock, stock_meters, is_active, sort_order
             FROM fabric_variants
             WHERE fabric_id = ?
             ORDER BY sort_order ASC, id ASC"
        );
        $stmt->bind_param('i', $fabricId);
        $stmt->execute();
        return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    }

    /**
     * Return a single variant by primary key, or null if not found.
     */
    public static function get_variant_by_id(mysqli $conn, int $variantId): ?array
    {
        if ($variantId <= 0) {
            return null;
        }
        $stmt = $conn->prepare(
            "SELECT id, fabric_id, color, size, sku, image, image2, image3, image4, video, pack_label, units_per_set, price_override, stock, stock_meters, is_active, sort_order
             FROM fabric_variants
             WHERE id = ?
             LIMIT 1"
        );
        $stmt->bind_param('i', $variantId);
        $stmt->execute();
        return $stmt->get_result()->fetch_assoc() ?: null;
    }

    public static function shipping_quote_store(
        float $subtotal,
        string $country,
        string $pincode,
        string $paymentMethod,
        float $baseShipping,
        float $codFee,
        float $shippingTotal,
        string $source = 'manual',
        string $courierName = '',
        int $courierId = 0
    ): string {
        if (!isset($_SESSION['shipping_quotes']) || !is_array($_SESSION['shipping_quotes'])) {
            $_SESSION['shipping_quotes'] = [];
        }
        $now = time();
        foreach ($_SESSION['shipping_quotes'] as $k => $v) {
            $created = (int) (($v['created_at'] ?? 0));
            if ($created <= 0 || ($now - $created) > 1800) {
                unset($_SESSION['shipping_quotes'][$k]);
            }
        }
        $token = bin2hex(random_bytes(16));
        $_SESSION['shipping_quotes'][$token] = [
            'subtotal' => round($subtotal, 2),
            'country' => strtolower(trim($country)),
            'pincode' => trim($pincode),
            'payment_method' => strtolower(trim($paymentMethod)),
            'base_shipping' => round($baseShipping, 2),
            'cod_fee' => round($codFee, 2),
            'shipping_total' => round($shippingTotal, 2),
            'source' => $source,
            'courier_name' => $courierName,
            'courier_id' => max(0, (int) $courierId),
            'created_at' => $now,
        ];
        try {
            $customerId = (int) ($_SESSION['customer_id'] ?? 0);
            $expiresAt = date('Y-m-d H:i:s', $now + 1800);
            $stmt = $conn = $GLOBALS['conn'] ?? null;
            if ($stmt instanceof mysqli) {
                $ins = $stmt->prepare(
                    "INSERT INTO shipping_quotes (
                        quote_token, customer_id, subtotal, country, pincode, payment_method,
                        base_shipping, cod_fee, shipping_total, source, courier_name, courier_id, expires_at
                     ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                     ON DUPLICATE KEY UPDATE
                        customer_id = VALUES(customer_id),
                        subtotal = VALUES(subtotal),
                        country = VALUES(country),
                        pincode = VALUES(pincode),
                        payment_method = VALUES(payment_method),
                        base_shipping = VALUES(base_shipping),
                        cod_fee = VALUES(cod_fee),
                        shipping_total = VALUES(shipping_total),
                        source = VALUES(source),
                        courier_name = VALUES(courier_name),
                        courier_id = VALUES(courier_id),
                        expires_at = VALUES(expires_at)"
                );
                $countryNorm = strtolower(trim($country));
                $pincodeNorm = trim($pincode);
                $methodNorm = strtolower(trim($paymentMethod));
                $baseShipping = round($baseShipping, 2);
                $codFee = round($codFee, 2);
                $shippingTotal = round($shippingTotal, 2);
                $courierId = max(0, (int) $courierId);
                $ins->bind_param(
                    'sidsssdddssis',
                    $token,
                    $customerId,
                    $subtotal,
                    $countryNorm,
                    $pincodeNorm,
                    $methodNorm,
                    $baseShipping,
                    $codFee,
                    $shippingTotal,
                    $source,
                    $courierName,
                    $courierId,
                    $expiresAt
                );
                $ins->execute();
            }
        } catch (Throwable $e) {
            error_log('[app] shipping quote persist failed: ' . $e->getMessage());
        }
        return $token;
    }

    public static function shipping_quote_get(string $token): ?array
    {
        $token = trim($token);
        if ($token === '') {
            return null;
        }
        $row = null;
        if (!empty($_SESSION['shipping_quotes']) && is_array($_SESSION['shipping_quotes'])) {
            $row = $_SESSION['shipping_quotes'][$token] ?? null;
        }
        if (!is_array($row)) {
            try {
                $conn = $GLOBALS['conn'] ?? null;
                if ($conn instanceof mysqli) {
                    $stmt = $conn->prepare(
                        "SELECT subtotal, country, pincode, payment_method, base_shipping, cod_fee, shipping_total, source, courier_name, courier_id, expires_at
                         FROM shipping_quotes
                         WHERE quote_token = ?
                         LIMIT 1"
                    );
                    $stmt->bind_param('s', $token);
                    $stmt->execute();
                    $dbRow = $stmt->get_result()->fetch_assoc();
                    if (is_array($dbRow)) {
                        $exp = strtotime((string) ($dbRow['expires_at'] ?? ''));
                        if ($exp !== false && $exp > time()) {
                            $row = [
                                'subtotal' => (float) ($dbRow['subtotal'] ?? 0),
                                'country' => (string) ($dbRow['country'] ?? ''),
                                'pincode' => (string) ($dbRow['pincode'] ?? ''),
                                'payment_method' => (string) ($dbRow['payment_method'] ?? ''),
                                'base_shipping' => (float) ($dbRow['base_shipping'] ?? 0),
                                'cod_fee' => (float) ($dbRow['cod_fee'] ?? 0),
                                'shipping_total' => (float) ($dbRow['shipping_total'] ?? 0),
                                'source' => (string) ($dbRow['source'] ?? 'manual'),
                                'courier_name' => (string) ($dbRow['courier_name'] ?? ''),
                                'courier_id' => (int) ($dbRow['courier_id'] ?? 0),
                                'created_at' => time(),
                            ];
                        }
                    }
                }
            } catch (Throwable $e) {
                error_log('[app] shipping quote read failed: ' . $e->getMessage());
            }
            if (!is_array($row)) {
                return null;
            }
        }
        $created = (int) ($row['created_at'] ?? 0);
        if ($created <= 0 || (time() - $created) > 1800) {
            unset($_SESSION['shipping_quotes'][$token]);
            return null;
        }
        return $row;
    }

    /**
     * Return the first active variant for a fabric (fallback for legacy quick-add flows).
     */
    public static function get_first_active_variant(mysqli $conn, int $fabricId): ?array
    {
        if ($fabricId <= 0) {
            return null;
        }
        $stmt = $conn->prepare(
            "SELECT id, fabric_id, color, size, sku, image, image2, image3, image4, video, pack_label, units_per_set, price_override, stock, stock_meters, is_active, sort_order
             FROM fabric_variants
             WHERE fabric_id = ? AND is_active = 1
             ORDER BY sort_order ASC, id ASC
             LIMIT 1"
        );
        $stmt->bind_param('i', $fabricId);
        $stmt->execute();
        return $stmt->get_result()->fetch_assoc() ?: null;
    }

    /**
     * Return the first active in-stock variant for a fabric.
     * Falls back to first active variant when all are out of stock.
     */
    public static function get_first_active_in_stock_variant(mysqli $conn, int $fabricId, string $unitType): ?array
    {
        if ($fabricId <= 0) {
            return null;
        }
        $isWhole = in_array($unitType, ['piece', 'set'], true);
        $stockColumn = $isWhole ? 'stock' : 'stock_meters';
        $stmt = $conn->prepare(
            "SELECT id, fabric_id, color, size, sku, image, image2, image3, image4, video, pack_label, units_per_set, price_override, stock, stock_meters, is_active, sort_order
             FROM fabric_variants
             WHERE fabric_id = ? AND is_active = 1
             ORDER BY CASE WHEN COALESCE($stockColumn, 0) > 0 THEN 0 ELSE 1 END, sort_order ASC, id ASC
             LIMIT 1"
        );
        $stmt->bind_param('i', $fabricId);
        $stmt->execute();
        return $stmt->get_result()->fetch_assoc() ?: null;
    }

    /**
     * Find an active variant by fabric, color and size. Returns null if not found.
     */
    public static function find_variant(mysqli $conn, int $fabricId, string $color, string $size): ?array
    {
        $stmt = $conn->prepare(
            "SELECT id, fabric_id, color, size, sku, image, image2, image3, image4, video, pack_label, units_per_set, price_override, stock, stock_meters, is_active, sort_order
             FROM fabric_variants
             WHERE fabric_id = ? AND color = ? AND size = ? AND is_active = 1
             LIMIT 1"
        );
        $stmt->bind_param('iss', $fabricId, $color, $size);
        $stmt->execute();
        return $stmt->get_result()->fetch_assoc() ?: null;
    }

    /**
     * Batch-fetch active variants by a list of variant IDs.
     * Returns array keyed by variant id.
     */
    public static function get_variants_by_ids(mysqli $conn, array $variantIds): array
    {
        $ids = array_values(array_filter(array_map('intval', $variantIds)));
        if (empty($ids)) {
            return [];
        }
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $types = str_repeat('i', count($ids));
        $stmt = $conn->prepare(
            "SELECT id, fabric_id, color, size, sku, image, image2, image3, image4, video, pack_label, units_per_set, price_override, wastage_percent_override, stock, stock_meters, is_active
             FROM fabric_variants
             WHERE id IN ($placeholders)"
        );
        $stmt->bind_param($types, ...$ids);
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $map = [];
        foreach ($rows as $row) {
            $map[(int) $row['id']] = $row;
        }
        return $map;
    }

    /**
     * Restore all order item quantities back into inventory.
     */
    public static function orders_supports_inventory_tracking(mysqli $conn): bool
    {
        static $checked = false;
        static $supported = false;
        if ($checked) {
            return $supported;
        }
        $checked = true;
        try {
            $stmt = $conn->prepare(
                "SELECT SUM(CASE WHEN COLUMN_NAME IN ('inventory_reserved_at', 'inventory_restored_at') THEN 1 ELSE 0 END) AS total
                 FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE()
                   AND TABLE_NAME = 'orders'
                   AND COLUMN_NAME IN ('inventory_reserved_at', 'inventory_restored_at')"
            );
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            $supported = ((int) ($row['total'] ?? 0)) === 2;
        } catch (Throwable $e) {
            $supported = false;
        }
        return $supported;
    }

    public static function mark_order_inventory_reserved(mysqli $conn, int $orderId): void
    {
        if ($orderId <= 0 || !InventoryService::orders_supports_inventory_tracking($conn)) {
            return;
        }
        $stmt = $conn->prepare(
            "UPDATE orders
             SET inventory_reserved_at = COALESCE(inventory_reserved_at, NOW()),
                 inventory_restored_at = NULL
             WHERE id = ?"
        );
        $stmt->bind_param('i', $orderId);
        $stmt->execute();
    }

    public static function reserve_order_inventory(mysqli $conn, int $orderId): void
    {
        if ($orderId <= 0) {
            return;
        }

        $supportsTracking = InventoryService::orders_supports_inventory_tracking($conn);
        if ($supportsTracking) {
            $orderStmt = $conn->prepare(
                "SELECT inventory_reserved_at, inventory_restored_at
                 FROM orders
                 WHERE id = ?
                 FOR UPDATE"
            );
            $orderStmt->bind_param('i', $orderId);
            $orderStmt->execute();
            $order = $orderStmt->get_result()->fetch_assoc();
            if (!$order) {
                throw new RuntimeException('Order not found for inventory reservation.');
            }
            if (!empty($order['inventory_reserved_at']) && empty($order['inventory_restored_at'])) {
                return;
            }
        }

        $itemsStmt = $conn->prepare(
            "SELECT id, fabric_id, variant_id, unit_type, quantity_meters
             FROM order_items
             WHERE order_id = ?"
        );
        $itemsStmt->bind_param('i', $orderId);
        $itemsStmt->execute();
        $items = $itemsStmt->get_result()->fetch_all(MYSQLI_ASSOC);
        foreach ($items as $item) {
            $fabricId = (int) ($item['fabric_id'] ?? 0);
            if ($fabricId <= 0) {
                continue;
            }
            $itemUnit = in_array((string) ($item['unit_type'] ?? ''), ['meter', 'piece', 'set'], true)
                ? (string) $item['unit_type']
                : 'meter';
            $qty = normalize_quantity_by_unit($item['quantity_meters'] ?? 1, $itemUnit);
            $variantId = (int) ($item['variant_id'] ?? 0);
            if ($variantId > 0) {
                InventoryService::adjust_variant_stock($conn, $variantId, $itemUnit, (float) $qty, 'decrease');
            } else {
                InventoryService::adjust_fabric_stock($conn, $fabricId, $itemUnit, (float) $qty, 'decrease');
            }
            InventoryService::log_stock_ledger(
                $conn,
                $orderId,
                (int) ($item['id'] ?? 0),
                0,
                0,
                $fabricId,
                $variantId,
                $itemUnit,
                (float) $qty,
                'reserve',
                'out',
                'order_flow',
                'Order inventory reserved'
            );
        }

        InventoryService::mark_order_inventory_reserved($conn, $orderId);
    }

    public static function ensure_order_inventory_reserved_for_payment_capture(mysqli $conn, int $orderId): void
    {
        if ($orderId <= 0) {
            throw new RuntimeException('Invalid order id for payment capture inventory reservation.');
        }

        InventoryService::reserve_order_inventory($conn, $orderId);
    }

    public static function restore_order_inventory(mysqli $conn, int $orderId): void
    {
        $supportsTracking = InventoryService::orders_supports_inventory_tracking($conn);
        if ($supportsTracking) {
            $orderStmt = $conn->prepare(
                "SELECT inventory_reserved_at, inventory_restored_at
                 FROM orders
                 WHERE id = ?
                 FOR UPDATE"
            );
            $orderStmt->bind_param('i', $orderId);
            $orderStmt->execute();
            $order = $orderStmt->get_result()->fetch_assoc();
            if (!$order || empty($order['inventory_reserved_at']) || !empty($order['inventory_restored_at'])) {
                return;
            }
        }

        $itemsStmt = $conn->prepare(
            "SELECT id, fabric_id, variant_id, unit_type, quantity_meters
             FROM order_items
             WHERE order_id = ?"
        );
        $itemsStmt->bind_param('i', $orderId);
        $itemsStmt->execute();
        $items = $itemsStmt->get_result()->fetch_all(MYSQLI_ASSOC);
        foreach ($items as $item) {
            $fabricId = (int) ($item['fabric_id'] ?? 0);
            if ($fabricId <= 0) {
                continue;
            }
            $itemUnit = in_array((string) ($item['unit_type'] ?? ''), ['meter', 'piece', 'set'], true)
                ? (string) $item['unit_type']
                : 'meter';
            $qty = normalize_quantity_by_unit($item['quantity_meters'] ?? 1, $itemUnit);
            $variantId = (int) ($item['variant_id'] ?? 0);
            if ($variantId > 0) {
                InventoryService::adjust_variant_stock($conn, $variantId, $itemUnit, (float) $qty, 'increase');
            } else {
                InventoryService::adjust_fabric_stock($conn, $fabricId, $itemUnit, (float) $qty, 'increase');
            }
            InventoryService::log_stock_ledger(
                $conn,
                $orderId,
                (int) ($item['id'] ?? 0),
                0,
                0,
                $fabricId,
                $variantId,
                $itemUnit,
                (float) $qty,
                'release',
                'in',
                'order_flow',
                'Order inventory released'
            );
        }

        if ($supportsTracking) {
            $upd = $conn->prepare("UPDATE orders SET inventory_restored_at = NOW() WHERE id = ?");
            $upd->bind_param('i', $orderId);
            $upd->execute();
        }
    }

    public static function order_cancel_should_restore_inventory(string $paymentMethod, string $paymentStatus): bool
    {
        $paymentMethod = strtolower(trim($paymentMethod));
        $paymentStatus = strtolower(trim($paymentStatus));

        if ($paymentMethod === 'cod') {
            return in_array($paymentStatus, ['pending', 'failed', 'paid'], true);
        }

        if ($paymentMethod === 'razorpay') {
            return in_array($paymentStatus, ['pending', 'failed', 'paid'], true);
        }

        return false;
    }

    public static function customer_cancel_order(mysqli $conn, int $orderId, int $customerId, bool $manageTransaction = true): array
    {
        if ($orderId <= 0 || $customerId <= 0) {
            throw new RuntimeException('Invalid order request.');
        }

        if ($manageTransaction) {
            $conn->begin_transaction();
        }
        try {
            $orderStmt = $conn->prepare(
                "SELECT id, order_number, order_status, status, payment_status, payment_method, notes
                 FROM orders
                 WHERE id = ? AND customer_id = ?
                 FOR UPDATE"
            );
            $orderStmt->bind_param('ii', $orderId, $customerId);
            $orderStmt->execute();
            $order = $orderStmt->get_result()->fetch_assoc();

            if (!$order) {
                throw new RuntimeException('Order not found.');
            }

            $currentOrderStatus = (string) ($order['order_status'] ?? '');
            if (!in_array($currentOrderStatus, ['pending', 'confirmed'], true)) {
                throw new RuntimeException('This order can no longer be cancelled.');
            }

            $paymentMethod = strtolower((string) ($order['payment_method'] ?? ''));
            $paymentStatus = strtolower((string) ($order['payment_status'] ?? 'pending'));
            $paymentRowId = 0;
            $paymentAmount = 0.0;
            $paymentRowStmt = $conn->prepare("SELECT id, amount FROM payments WHERE order_id = ? AND payment_method = ? LIMIT 1");
            $paymentRowStmt->bind_param('is', $orderId, $paymentMethod);
            $paymentRowStmt->execute();
            $paymentRow = $paymentRowStmt->get_result()->fetch_assoc() ?: [];
            $paymentRowId = (int) ($paymentRow['id'] ?? 0);
            $paymentAmount = (float) ($paymentRow['amount'] ?? 0);

            if (InventoryService::order_cancel_should_restore_inventory($paymentMethod, $paymentStatus)) {
                InventoryService::restore_order_inventory($conn, $orderId);
            }

            $refundNote = '';
            if ($paymentStatus === 'paid') {
                $refundNote = "\n[System] Refund process initiated on " . date('d M Y, H:i');
            }

            $existingNotes = trim((string) ($order['notes'] ?? ''));
            $newNotes = trim($existingNotes . $refundNote);

            $updateStmt = $conn->prepare(
                "UPDATE orders
                 SET order_status = 'cancelled',
                     status = 'cancelled',
                     notes = ?,
                     updated_at = NOW()
                 WHERE id = ?"
            );
            $updateStmt->bind_param('si', $newNotes, $orderId);
            $updateStmt->execute();
            release_coupon_usage_for_order($conn, $orderId);
            release_coupon_reservation_for_order($conn, $orderId, 'order_cancelled');

            log_order_activity(
                $conn,
                $orderId,
                'order_cancelled',
                'customer',
                $customerId,
                'customer',
                'Payment status at cancel: ' . $paymentStatus
            );
            if ($paymentStatus === 'paid' && $paymentRowId > 0 && $paymentAmount > 0) {
                log_refund_ledger(
                    $conn,
                    $orderId,
                    $paymentRowId,
                    $paymentAmount,
                    'INR',
                    'initiated',
                    $paymentMethod,
                    '',
                    'Customer cancelled paid order; refund initiation pending processing.'
                );
                log_order_activity($conn, $orderId, 'refund_initiated', 'system', 0, 'system', 'Refund ledger entry created.');
            }

            if ($manageTransaction) {
                $conn->commit();
            }
            return [
                'order_id' => $orderId,
                'payment_method' => $paymentMethod,
                'payment_status' => $paymentStatus,
            ];
        } catch (Throwable $e) {
            if ($manageTransaction) {
                try {
                    $conn->rollback();
                } catch (Throwable $rollbackException) {
                    // ignore
                }
            }
            throw $e;
        }
    }

    /**
     * Guard admin order status edits with allowed state transitions.
     */
    public static function can_transition_order_status(string $currentStatus, string $nextStatus): bool
    {
        $current = strtolower(trim($currentStatus));
        $next = strtolower(trim($nextStatus));
        $map = [
            'pending' => ['pending', 'confirmed', 'packed', 'cancelled'],
            'confirmed' => ['confirmed', 'packed', 'shipped', 'cancelled'],
            'packed' => ['packed', 'shipped', 'cancelled'],
            'shipped' => ['shipped', 'delivered', 'returned'],
            'delivered' => ['delivered', 'returned'],
            'cancelled' => ['cancelled', 'refunded'],
            'returned' => ['returned', 'refunded'],
            'refunded' => ['refunded'],
        ];
        $allowed = $map[$current] ?? [$current];
        return in_array($next, $allowed, true);
    }

    /**
     * Shared UI metadata for order status badges.
     */
    public static function order_status_meta(string $status): array
    {
        $status = strtolower(trim($status));
        $map = [
            'pending' => ['label' => 'Pending', 'class' => 'warning'],
            'confirmed' => ['label' => 'Confirmed', 'class' => 'info'],
            'processing' => ['label' => 'Processing', 'class' => 'primary'],
            'packed' => ['label' => 'Packed', 'class' => 'primary'],
            'shipped' => ['label' => 'Shipped', 'class' => 'primary'],
            'delivered' => ['label' => 'Delivered', 'class' => 'success'],
            'cancelled' => ['label' => 'Cancelled', 'class' => 'danger'],
            'returned' => ['label' => 'Returned', 'class' => 'secondary'],
            'refunded' => ['label' => 'Refunded', 'class' => 'dark'],
        ];
        return $map[$status] ?? ['label' => ucfirst($status), 'class' => 'secondary'];
    }

    /**
     * Shared UI metadata for payment status badges.
     */
    public static function payment_status_meta(string $status): array
    {
        $status = strtolower(trim($status));
        $map = [
            'pending' => ['label' => 'Pending', 'class' => 'secondary'],
            'paid' => ['label' => 'Paid', 'class' => 'success'],
            'failed' => ['label' => 'Failed', 'class' => 'danger'],
            'refunded' => ['label' => 'Refunded', 'class' => 'dark'],
        ];
        return $map[$status] ?? ['label' => ucfirst($status), 'class' => 'secondary'];
    }

    /**
     * Allow only supported online payment preference values.
     */
    public static function sanitize_online_payment_method(?string $value): string
    {
        $method = strtolower(trim((string) $value));
        return in_array($method, ['upi', 'card', 'emi'], true) ? $method : '';
    }

    /**
     * Unit suffix for display.
     */
    public static function quantity_unit_suffix(string $unitType): string
    {
        if ($unitType === 'piece') return ' pc';
        if ($unitType === 'set') return ' set';
        return 'm';
    }

    /**
     * Allow only absolute http/https URLs for outbound links.
     */
    public static function safe_external_url(?string $value): string
    {
        $url = trim((string) $value);
        if ($url === '' || !filter_var($url, FILTER_VALIDATE_URL)) {
            return '';
        }
        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
        if (!in_array($scheme, ['http', 'https'], true)) {
            return '';
        }
        return $url;
    }

    public static function log_stock_ledger(
        mysqli $conn,
        int $orderId,
        int $orderItemId,
        int $returnId,
        int $returnItemId,
        int $fabricId,
        int $variantId,
        string $unitType,
        float $quantity,
        string $movement,
        string $direction,
        string $source = '',
        string $notes = ''
    ): void {
        try {
            $unitType = in_array($unitType, ['meter', 'piece', 'set'], true) ? $unitType : 'meter';
            $movement = in_array($movement, ['reserve', 'release', 'return_restock', 'adjustment'], true) ? $movement : 'adjustment';
            $direction = in_array($direction, ['in', 'out'], true) ? $direction : 'in';
            if ($quantity <= 0) {
                return;
            }
            $stmt = $conn->prepare(
                "INSERT INTO stock_ledger (
                    order_id, order_item_id, return_id, return_item_id, fabric_id, variant_id,
                    unit_type, quantity, movement, direction, source, notes
                 ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
            );
            $stmt->bind_param(
                'iiiiiisdssss',
                $orderId,
                $orderItemId,
                $returnId,
                $returnItemId,
                $fabricId,
                $variantId,
                $unitType,
                $quantity,
                $movement,
                $direction,
                $source,
                $notes
            );
            $stmt->execute();
        } catch (Throwable $e) {
            error_log('[app] stock ledger log failed: ' . $e->getMessage());
        }
    }

    public static function restock_return_items_inventory(mysqli $conn, int $returnId): float
    {
        if ($returnId <= 0) {
            return 0.0;
        }
        $stmt = $conn->prepare(
            "SELECT ri.id, ri.return_id, ri.order_item_id, ri.fabric_id, ri.variant_id, ri.unit_type, ri.quantity, ri.restocked_qty,
                    r.order_id
             FROM return_items ri
             JOIN returns r ON r.id = ri.return_id
             WHERE ri.return_id = ?
             FOR UPDATE"
        );
        $stmt->bind_param('i', $returnId);
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $totalRestocked = 0.0;
        foreach ($rows as $row) {
            $unitType = in_array((string) ($row['unit_type'] ?? ''), ['meter', 'piece', 'set'], true) ? (string) $row['unit_type'] : 'meter';
            $qtyRequested = (float) ($row['quantity'] ?? 0);
            $qtyRestocked = (float) ($row['restocked_qty'] ?? 0);
            $qtyToRestock = round(max(0.0, $qtyRequested - $qtyRestocked), 2);
            if ($qtyToRestock <= 0) {
                continue;
            }
            $fabricId = (int) ($row['fabric_id'] ?? 0);
            $variantId = (int) ($row['variant_id'] ?? 0);
            if ($variantId > 0) {
                InventoryService::adjust_variant_stock($conn, $variantId, $unitType, $qtyToRestock, 'increase');
            } elseif ($fabricId > 0) {
                InventoryService::adjust_fabric_stock($conn, $fabricId, $unitType, $qtyToRestock, 'increase');
            } else {
                continue;
            }
            $update = $conn->prepare(
                "UPDATE return_items
                 SET restocked_qty = restocked_qty + ?,
                     restocked_at = CASE WHEN restocked_at IS NULL THEN NOW() ELSE restocked_at END
                 WHERE id = ?"
            );
            $returnItemId = (int) ($row['id'] ?? 0);
            $update->bind_param('di', $qtyToRestock, $returnItemId);
            $update->execute();
            InventoryService::log_stock_ledger(
                $conn,
                (int) ($row['order_id'] ?? 0),
                (int) ($row['order_item_id'] ?? 0),
                $returnId,
                $returnItemId,
                $fabricId,
                $variantId,
                $unitType,
                $qtyToRestock,
                'return_restock',
                'in',
                'returns_module',
                'Restocked from return'
            );
            $totalRestocked += $qtyToRestock;
        }
        return round($totalRestocked, 2);
    }
}
