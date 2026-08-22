<?php

final class OrderReadService
{
    public static function customerOrders(mysqli $conn, int $customerId): array
    {
        if ($customerId <= 0) {
            return [];
        }

        $stmt = $conn->prepare(
            "SELECT
                o.id,
                o.order_number,
                o.status,
                o.order_status,
                o.payment_status,
                o.payment_method,
                o.currency,
                o.total,
                o.notes,
                o.created_at,
                (
                    SELECT r.status
                    FROM returns r
                    WHERE r.order_id = o.id
                    ORDER BY r.id DESC
                    LIMIT 1
                ) AS return_status,
                (
                    SELECT r.return_number
                    FROM returns r
                    WHERE r.order_id = o.id
                    ORDER BY r.id DESC
                    LIMIT 1
                ) AS return_number,
                COALESCE(SUM(CASE
                    WHEN oi.quantity IS NOT NULL AND oi.quantity > 0 THEN oi.quantity
                    WHEN oi.quantity_meters IS NOT NULL AND oi.quantity_meters > 0 THEN oi.quantity_meters
                    ELSE 0
                END), 0) AS total_qty,
                (
                    o.payment_status IN ('pending', 'failed')
                    AND o.order_status IN ('pending', 'confirmed')
                    AND o.payment_method IN ('razorpay', 'upi')
                    AND o.created_at >= (NOW() - INTERVAL 30 MINUTE)
                ) AS retry_allowed
             FROM orders o
             LEFT JOIN order_items oi ON oi.order_id = o.id
             WHERE o.customer_id = ?
               AND NOT (
                    o.order_status = 'pending'
                    AND o.payment_status = 'pending'
                    AND o.payment_method IN ('razorpay', 'upi')
                    AND o.created_at < (NOW() - INTERVAL 30 MINUTE)
               )
             GROUP BY o.id, o.order_number, o.status, o.order_status, o.payment_status, o.payment_method, o.currency, o.total, o.notes, o.created_at
             ORDER BY o.created_at DESC"
        );
        $stmt->bind_param('i', $customerId);
        $stmt->execute();
        return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    }

    public static function customerOrder(mysqli $conn, int $orderId, int $customerId): ?array
    {
        if ($orderId <= 0 || $customerId <= 0) {
            return null;
        }

        $stmt = $conn->prepare(
            "SELECT
                o.*,
                c.name AS customer_name,
                c.email AS customer_email,
                (
                    o.payment_status IN ('pending', 'failed')
                    AND o.order_status IN ('pending', 'confirmed')
                    AND o.payment_method IN ('razorpay', 'upi')
                    AND o.created_at >= (NOW() - INTERVAL 30 MINUTE)
                ) AS retry_allowed
             FROM orders o
             JOIN customers c ON c.id = o.customer_id
             WHERE o.id = ? AND o.customer_id = ?"
        );
        $stmt->bind_param('ii', $orderId, $customerId);
        $stmt->execute();
        return $stmt->get_result()->fetch_assoc() ?: null;
    }

    public static function orderById(mysqli $conn, int $orderId): ?array
    {
        if ($orderId <= 0) {
            return null;
        }
        $stmt = $conn->prepare("SELECT * FROM orders WHERE id = ? LIMIT 1");
        $stmt->bind_param('i', $orderId);
        $stmt->execute();
        return $stmt->get_result()->fetch_assoc() ?: null;
    }

    public static function adminOrder(mysqli $conn, int $orderId): ?array
    {
        if ($orderId <= 0) {
            return null;
        }
        $financialSelect = PaymentService::orders_structured_financial_columns_ready($conn)
            ? "coupon_id, coupon_code, coupon_discount, shipping_quote_token, shipping_source, courier_id, courier_name, cod_fee, base_shipping"
            : "NULL AS coupon_id, NULL AS coupon_code, 0.00 AS coupon_discount, NULL AS shipping_quote_token, NULL AS shipping_source, NULL AS courier_id, NULL AS courier_name, 0.00 AS cod_fee, 0.00 AS base_shipping";
        $stmt = $conn->prepare(
            "SELECT id, order_number, customer_name, customer_phone, customer_email,
                    address, city, state, pincode, country,
                    subtotal, shipping_amount, discount_amount, total_amount,
                    payment_method, payment_status, order_status, order_notes, notes, admin_notes, created_at,
                    {$financialSelect}
             FROM orders
             WHERE id = ?
             LIMIT 1"
        );
        $stmt->bind_param('i', $orderId);
        $stmt->execute();
        return $stmt->get_result()->fetch_assoc() ?: null;
    }

    public static function items(mysqli $conn, int $orderId): array
    {
        if ($orderId <= 0) {
            return [];
        }
        $stmt = $conn->prepare("SELECT * FROM order_items WHERE order_id = ? ORDER BY id");
        $stmt->bind_param('i', $orderId);
        $stmt->execute();
        return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    }

    public static function itemsWithImages(mysqli $conn, int $orderId, bool $ordered = true): array
    {
        if ($orderId <= 0) {
            return [];
        }
        $variantImageJoin = order_items_supports_variant($conn)
            ? "LEFT JOIN fabric_variants fv ON fv.id = oi.variant_id"
            : "LEFT JOIN fabric_variants fv ON fv.fabric_id = COALESCE(oi.fabric_id, oi.product_id)
                AND fv.color = oi.color
                AND fv.size = oi.size
                AND fv.is_active = 1";
        $orderBy = $ordered ? ' ORDER BY oi.id ASC' : '';
        $stmt = $conn->prepare(
            "SELECT oi.*,
                    COALESCE(NULLIF(fv.image, ''), (SELECT fm.filename FROM fabric_media fm WHERE fm.fabric_id=f.id AND fm.media_type='image' ORDER BY fm.is_primary DESC, fm.sort_order, fm.id LIMIT 1)) AS product_image
             FROM order_items oi
             LEFT JOIN fabrics f ON f.id = COALESCE(oi.fabric_id, oi.product_id)
             {$variantImageJoin}
             WHERE oi.order_id = ?{$orderBy}"
        );
        $stmt->bind_param('i', $orderId);
        $stmt->execute();
        return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    }

    public static function customerShipment(mysqli $conn, int $orderId): array
    {
        return self::shipment(
            $conn,
            $orderId,
            "SELECT courier_name,
                    COALESCE(NULLIF(tracking_id, ''), NULLIF(awb_code, ''), '') AS tracking_id,
                    tracking_url, shipping_cost, shipped_at, delivered_at
             FROM shipments WHERE order_id = ? LIMIT 1"
        );
    }

    public static function guestShipment(mysqli $conn, int $orderId): array
    {
        return self::shipment(
            $conn,
            $orderId,
            "SELECT courier_name,
                    COALESCE(NULLIF(tracking_id, ''), NULLIF(awb_code, ''), '') AS tracking_id,
                    tracking_url, delivered_at
             FROM shipments WHERE order_id = ? LIMIT 1"
        );
    }

    public static function adminShipment(mysqli $conn, int $orderId): array
    {
        $fallback = [
            'courier_name' => '',
            'tracking_id' => '',
            'tracking_url' => '',
            'shipping_cost' => '0.00',
            'shipped_at' => null,
            'delivered_at' => null,
        ];
        return self::shipment(
            $conn,
            $orderId,
            "SELECT id, courier_name, tracking_id, tracking_url, shipping_cost, shipped_at, delivered_at
             FROM shipments WHERE order_id = ? LIMIT 1",
            $fallback
        );
    }

    public static function latestCustomerReturn(mysqli $conn, int $orderId): ?array
    {
        return self::latestReturn(
            $conn,
            $orderId,
            'id, return_number, status, reason, customer_note, image_1, image_2, admin_note, requested_at, updated_at'
        );
    }

    public static function latestGuestReturn(mysqli $conn, int $orderId): ?array
    {
        return self::latestReturn(
            $conn,
            $orderId,
            'id, return_number, status, reason, customer_note, admin_note, refund_amount, requested_at, updated_at'
        );
    }

    public static function returnItems(mysqli $conn, int $returnId): array
    {
        if ($returnId <= 0) {
            return [];
        }
        $stmt = $conn->prepare(
            "SELECT product_name, unit_type, quantity, line_total, refund_amount
             FROM return_items
             WHERE return_id = ?
             ORDER BY id ASC"
        );
        $stmt->bind_param('i', $returnId);
        $stmt->execute();
        return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    }

    public static function activity(mysqli $conn, int $orderId, int $limit): array
    {
        if ($orderId <= 0) {
            return [];
        }
        $limit = max(1, min(100, $limit));
        $stmt = $conn->prepare(
            "SELECT action, actor_type, actor_name, details, created_at
             FROM order_activity_logs
             WHERE order_id = ?
             ORDER BY id DESC
             LIMIT ?"
        );
        $stmt->bind_param('ii', $orderId, $limit);
        $stmt->execute();
        return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    }

    public static function latestReversePickup(mysqli $conn, int $returnId): ?array
    {
        if ($returnId <= 0) {
            return null;
        }
        try {
            $stmt = $conn->prepare(
                "SELECT provider, provider_status, tracking_id, tracking_url, label_url, initialization_status, last_error
                 FROM shipping_courier_reverse_pickups
                 WHERE return_id = ?
                 ORDER BY id DESC
                 LIMIT 1"
            );
            $stmt->bind_param('i', $returnId);
            $stmt->execute();
            return $stmt->get_result()->fetch_assoc() ?: null;
        } catch (Throwable $e) {
            return null;
        }
    }

    private static function shipment(mysqli $conn, int $orderId, string $sql, array $fallback = []): array
    {
        if ($orderId <= 0) {
            return $fallback;
        }
        $stmt = $conn->prepare($sql);
        $stmt->bind_param('i', $orderId);
        $stmt->execute();
        return $stmt->get_result()->fetch_assoc() ?: $fallback;
    }

    private static function latestReturn(mysqli $conn, int $orderId, string $columns): ?array
    {
        if ($orderId <= 0) {
            return null;
        }
        $stmt = $conn->prepare(
            "SELECT {$columns}
             FROM returns
             WHERE order_id = ?
             ORDER BY id DESC
             LIMIT 1"
        );
        $stmt->bind_param('i', $orderId);
        $stmt->execute();
        return $stmt->get_result()->fetch_assoc() ?: null;
    }
}
