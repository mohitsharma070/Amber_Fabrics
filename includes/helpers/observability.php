<?php
function app_request_id(): string
{
    $existing = (string) ($GLOBALS['app_request_id'] ?? '');
    if ($existing !== '') {
        return $existing;
    }

    $candidate = trim((string) ($_SERVER['HTTP_X_REQUEST_ID'] ?? ''));
    if (!preg_match('/\A[A-Za-z0-9][A-Za-z0-9._-]{7,63}\z/D', $candidate)) {
        $candidate = bin2hex(random_bytes(16));
    }

    $GLOBALS['app_request_id'] = $candidate;
    return $candidate;
}

function app_log_context_value(mixed $value, string $key = '', int $depth = 0): mixed
{
    if ($key !== '' && preg_match('/(?:password|passphrase|secret|token|authorization|cookie|otp|signature|card|cvv)/i', $key)) {
        return '[redacted]';
    }
    if ($depth >= 4) {
        return '[depth-limited]';
    }
    if (is_array($value)) {
        $safe = [];
        $count = 0;
        foreach ($value as $childKey => $childValue) {
            if ($count >= 50) {
                $safe['_truncated'] = true;
                break;
            }
            $safe[$childKey] = app_log_context_value($childValue, (string) $childKey, $depth + 1);
            $count++;
        }
        return $safe;
    }
    if (is_string($value)) {
        $value = (string) preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]+/', ' ', $value);
        return function_exists('mb_substr') ? mb_substr($value, 0, 1000) : substr($value, 0, 1000);
    }
    if (is_int($value) || is_float($value) || is_bool($value) || $value === null) {
        return $value;
    }

    return '[' . get_debug_type($value) . ']';
}

function app_log(string $level, string $event, array $context = []): void
{
    $level = strtolower(trim($level));
    if (!in_array($level, ['debug', 'info', 'warning', 'error', 'critical'], true)) {
        $level = 'error';
    }
    $event = strtolower(trim((string) preg_replace('/[^a-zA-Z0-9._-]+/', '_', $event)), '_');
    if ($event === '') {
        $event = 'application_event';
    }

    $payload = [
        'ts' => gmdate('c'),
        'level' => $level,
        'event' => $event,
        'request_id' => app_request_id(),
        'context' => app_log_context_value($context),
    ];
    $encoded = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    error_log('[amber] ' . (is_string($encoded) ? $encoded : '{"level":"error","event":"log_encoding_failed"}'));
}

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
        app_log('error', 'ecommerce_event_log_failed', [
            'exception_type' => get_class($e),
        ]);
    }
}
