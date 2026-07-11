<?php
function ecommerce_event_logs_table_ready(mysqli $conn): bool
{
    static $checked = false;
    static $ready = false;
    if ($checked) {
        return $ready;
    }
    $checked = true;
    try {
        $stmt = $conn->prepare(
            "SELECT COUNT(*) AS total
             FROM information_schema.TABLES
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = 'ecommerce_event_logs'"
        );
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $ready = ((int) ($row['total'] ?? 0)) > 0;
    } catch (Throwable $e) {
        $ready = false;
    }
    return $ready;
}

/**
 * Persist high-value commerce events for analytics/observability.
 */
function log_ecommerce_event(
    mysqli $conn,
    string $eventType,
    ?int $customerId = null,
    ?int $orderId = null,
    ?int $productId = null,
    ?string $unitType = null,
    ?float $quantity = null,
    ?float $amount = null,
    ?array $payload = null
): void {
    $eventType = trim($eventType);
    if ($eventType === '' || !ecommerce_event_logs_table_ready($conn)) {
        return;
    }

    $safeUnit = in_array((string) $unitType, ['meter', 'piece', 'set'], true) ? (string) $unitType : null;
    $payloadJson = null;
    if ($payload !== null) {
        $encoded = json_encode($payload, JSON_UNESCAPED_UNICODE);
        if (is_string($encoded) && $encoded !== '') {
            $payloadJson = $encoded;
        }
    }

    try {
        $stmt = $conn->prepare(
            "INSERT INTO ecommerce_event_logs
             (event_type, customer_id, order_id, product_id, unit_type, quantity, amount, payload_json)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)"
        );
        $stmt->bind_param(
            'siiisdds',
            $eventType,
            $customerId,
            $orderId,
            $productId,
            $safeUnit,
            $quantity,
            $amount,
            $payloadJson
        );
        $stmt->execute();
    } catch (Throwable $e) {
        error_log('[app] ecommerce event log failed: ' . $e->getMessage());
    }
}
