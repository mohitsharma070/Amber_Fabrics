<?php

require_once __DIR__ . '/CronService.php';
require_once __DIR__ . '/../integrations/JsonHttpClient.php';

final class PaymentService
{
    public static function orders_structured_financial_columns_ready(mysqli $conn): bool
    {
        static $ready = null;
        if ($ready !== null) {
            return $ready;
        }
        try {
            $stmt = $conn->prepare(
                "SELECT COUNT(*) AS total
                 FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE()
                   AND TABLE_NAME = 'orders'
                   AND COLUMN_NAME IN (
                     'coupon_id','coupon_code','coupon_discount',
                     'shipping_quote_token','shipping_source','courier_id','courier_name',
                     'cod_fee','base_shipping'
                   )"
            );
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc() ?: [];
            $ready = ((int) ($row['total'] ?? 0)) === 9;
        } catch (Throwable $e) {
            $ready = false;
        }
        return $ready;
    }

    public static function resolve_coupon_id_for_order(mysqli $conn, int $orderId, string $orderNotes = ''): int
    {
        if (PaymentService::orders_structured_financial_columns_ready($conn) && $orderId > 0) {
            try {
                $stmt = $conn->prepare("SELECT coupon_id, coupon_code FROM orders WHERE id = ? LIMIT 1");
                $stmt->bind_param('i', $orderId);
                $stmt->execute();
                $row = $stmt->get_result()->fetch_assoc() ?: [];
                $couponId = (int) ($row['coupon_id'] ?? 0);
                if ($couponId > 0) {
                    return $couponId;
                }
                $couponCode = strtoupper(trim((string) ($row['coupon_code'] ?? '')));
                if ($couponCode !== '') {
                    $idStmt = $conn->prepare("SELECT id FROM coupons WHERE code = ? LIMIT 1");
                    $idStmt->bind_param('s', $couponCode);
                    $idStmt->execute();
                    $couponRow = $idStmt->get_result()->fetch_assoc() ?: [];
                    $resolved = (int) ($couponRow['id'] ?? 0);
                    if ($resolved > 0) {
                        return $resolved;
                    }
                }
            } catch (Throwable $e) {
                error_log('[app] structured coupon resolve failed: ' . $e->getMessage());
            }
        }

        $code = order_coupon_code_from_activity($conn, $orderId);
        if ($code === '' && $orderNotes !== '' && preg_match('/Coupon Applied:\s*([A-Z0-9_-]+)/i', $orderNotes, $m)) {
            $code = strtoupper(trim((string) ($m[1] ?? '')));
        }
        if ($code === '') {
            return 0;
        }
        try {
            $stmt = $conn->prepare("SELECT id FROM coupons WHERE code = ? LIMIT 1");
            $stmt->bind_param('s', $code);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            return (int) ($row['id'] ?? 0);
        } catch (Throwable $e) {
            error_log('[app] coupon id resolve failed: ' . $e->getMessage());
            return 0;
        }
    }

    public static function razorpay_mark_order_paid(
        mysqli $conn,
        int $orderId,
        string $previousPaymentStatus,
        string $paymentId = '',
        string $rzpOrderId = '',
        string $signature = ''
    ): void {
        InventoryService::ensure_order_inventory_reserved_for_payment_capture($conn, $orderId);

        $updateOrder = $conn->prepare(
            "UPDATE orders
             SET payment_id = ?, payment_status = 'paid', order_status = 'confirmed', status = 'confirmed'
             WHERE id = ? AND payment_status IN ('pending', 'failed')"
        );
        $updateOrder->bind_param('si', $paymentId, $orderId);
        $updateOrder->execute();
        if ($conn->affected_rows === 0 && strtolower($previousPaymentStatus) !== 'paid') {
            throw new RuntimeException('Order payment state changed unexpectedly during Razorpay capture.');
        }

        $updatePayment = $conn->prepare(
            "UPDATE payments
             SET payment_status = 'paid',
                 transaction_id = CASE WHEN ? <> '' THEN ? ELSE transaction_id END,
                 razorpay_order_id = CASE WHEN ? <> '' THEN ? ELSE razorpay_order_id END,
                 razorpay_payment_id = CASE WHEN ? <> '' THEN ? ELSE razorpay_payment_id END,
                 razorpay_signature = CASE WHEN ? <> '' THEN ? ELSE razorpay_signature END
             WHERE order_id = ? AND payment_method = 'razorpay' AND payment_status IN ('pending', 'failed')"
        );
        $updatePayment->bind_param(
            'ssssssssi',
            $paymentId,
            $paymentId,
            $rzpOrderId,
            $rzpOrderId,
            $paymentId,
            $paymentId,
            $signature,
            $signature,
            $orderId
        );
        $updatePayment->execute();
    }

    public static function razorpay_mark_order_failed(
        mysqli $conn,
        int $orderId,
        string $previousPaymentStatus,
        string $note,
        string $paymentId = '',
        string $rzpOrderId = ''
    ): bool {
        if (strtolower($previousPaymentStatus) === 'paid') {
            return false;
        }

        $updatePayment = $conn->prepare(
            "UPDATE payments
             SET payment_status = 'failed',
                 transaction_id = CASE WHEN ? <> '' THEN ? ELSE transaction_id END,
                 razorpay_payment_id = CASE WHEN ? <> '' THEN ? ELSE razorpay_payment_id END,
                 razorpay_order_id = CASE WHEN ? <> '' THEN ? ELSE razorpay_order_id END
             WHERE order_id = ? AND payment_method = 'razorpay'"
        );
        $updatePayment->bind_param('ssssssi', $paymentId, $paymentId, $paymentId, $paymentId, $rzpOrderId, $rzpOrderId, $orderId);
        $updatePayment->execute();

        $updateOrder = $conn->prepare(
            "UPDATE orders
             SET payment_status = 'failed',
                 " . OrderFieldCompatibilityService::appendNoteAssignments() . ",
                 updated_at = NOW()
             WHERE id = ?"
        );
        $updateOrder->bind_param('ssssi', $note, $note, $note, $note, $orderId);
        $updateOrder->execute();

        return true;
    }

    public static function consume_coupon_after_razorpay_capture(
        mysqli $conn,
        int $orderId,
        int $customerId,
        int $preferredCouponId = 0,
        string $orderNotes = ''
    ): bool {
        $resolvedCouponId = $preferredCouponId > 0 ? $preferredCouponId : PaymentService::resolve_coupon_id_for_order($conn, $orderId, $orderNotes);
        if ($resolvedCouponId <= 0) {
            return false;
        }
        $stmt = $conn->prepare(
            "SELECT 1 FROM coupon_usages WHERE order_id = ? AND coupon_id = ? LIMIT 1"
        );
        $stmt->bind_param('ii', $orderId, $resolvedCouponId);
        $stmt->execute();
        $reserved = (bool) $stmt->get_result()->fetch_assoc();
        if (!$reserved) {
            error_log('[payment] Captured order has no coupon reservation: order_id=' . $orderId . ' coupon_id=' . $resolvedCouponId);
        }
        return $reserved;
    }

    public static function razorpay_validate_remote_capture(string $paymentId, string $rzpOrderId, float $expectedAmountInr): array
    {
        $paymentId = trim($paymentId);
        $rzpOrderId = trim($rzpOrderId);
        $expectedPaise = (int) round(max(0.0, $expectedAmountInr) * 100);
        if ($paymentId === '' || $rzpOrderId === '' || $expectedPaise <= 0) {
            return ['ok' => false, 'error' => 'invalid_validation_inputs'];
        }

        $resp = PaymentService::razorpay_http_json('GET', '/v1/payments/' . rawurlencode($paymentId), null);
        if (empty($resp['ok'])) {
            return ['ok' => false, 'error' => (string) ($resp['error'] ?? 'gateway_call_failed')];
        }
        $payload = (array) ($resp['body'] ?? []);

        $remoteOrderId = trim((string) ($payload['order_id'] ?? ''));
        $remoteCurrency = strtoupper(trim((string) ($payload['currency'] ?? '')));
        $remoteAmount = (int) ($payload['amount'] ?? 0);
        $remoteStatus = strtolower(trim((string) ($payload['status'] ?? '')));
        $remoteCaptured = (int) ($payload['captured'] ?? 0);

        if ($remoteOrderId !== $rzpOrderId) {
            return ['ok' => false, 'error' => 'gateway_order_mismatch'];
        }
        if ($remoteCurrency !== 'INR') {
            return ['ok' => false, 'error' => 'gateway_currency_mismatch'];
        }
        if ($remoteAmount !== $expectedPaise) {
            return ['ok' => false, 'error' => 'gateway_amount_mismatch'];
        }
        if (!in_array($remoteStatus, ['captured', 'authorized'], true) || $remoteCaptured !== 1) {
            return ['ok' => false, 'error' => 'gateway_not_captured'];
        }

        return ['ok' => true];
    }

    public static function razorpay_http_json(string $method, string $path, ?array $payload = null): array
    {
        $keyId = _cfg('RAZORPAY_KEY_ID', '');
        $keySecret = _cfg('RAZORPAY_KEY_SECRET', '');
        if ($keyId === '' || $keySecret === '') {
            return ['ok' => false, 'error' => 'razorpay_credentials_missing', 'status' => 0, 'duration_ms' => 0];
        }
        $timeoutSec = max(5, (int) _cfg('RAZORPAY_HTTP_TIMEOUT_SEC', '15'));
        $connectTimeoutSec = max(2, (int) _cfg('RAZORPAY_HTTP_CONNECT_TIMEOUT_SEC', '5'));
        $baseUrl = 'https://api.razorpay.com';
        $allowedHosts = ['api.razorpay.com'];
        $requireHttps = true;
        $testBaseUrl = rtrim(trim((string) _cfg('RAZORPAY_TEST_BASE_URL', '')), '/');
        if ($testBaseUrl !== '') {
            $parts = parse_url($testBaseUrl);
            $testHost = strtolower((string) ($parts['host'] ?? ''));
            $testAllowed = ($GLOBALS['_app_mode'] ?? '') === 'local'
                && strtolower(trim((string) _cfg('APP_ENV', ''))) === 'test'
                && (string) getenv('E2E_FIXTURE_CONFIRM') === '1'
                && is_array($parts)
                && strtolower((string) ($parts['scheme'] ?? '')) === 'http'
                && in_array($testHost, ['127.0.0.1', 'localhost', '::1'], true)
                && empty($parts['user'])
                && empty($parts['pass'])
                && empty($parts['query'])
                && empty($parts['fragment']);
            if (!$testAllowed) {
                return ['ok' => false, 'error' => 'razorpay_test_base_url_invalid', 'status' => 0, 'duration_ms' => 0];
            }
            $baseUrl = $testBaseUrl;
            $allowedHosts = [$testHost];
            $requireHttps = false;
        }
        $url = $baseUrl . $path;
        $skipTlsVerify = strtolower(trim((string) _cfg('RAZORPAY_HTTP_SKIP_TLS_VERIFY', '0')));
        $response = JsonHttpClient::request($method, $url, [], $payload, [
            'allowed_hosts' => $allowedHosts,
            'require_https' => $requireHttps,
            'timeout_sec' => $timeoutSec,
            'connect_timeout_sec' => $connectTimeoutSec,
            'basic_auth' => $keyId . ':' . $keySecret,
            'ca_bundle' => trim((string) _cfg('RAZORPAY_HTTP_CA_BUNDLE', '')),
            'skip_tls_verify' => in_array($skipTlsVerify, ['1', 'true', 'yes', 'on'], true),
        ]);
        if (!empty($response['ok'])) {
            return $response;
        }

        $errorCode = (string) ($response['error_code'] ?? '');
        $status = (int) ($response['status'] ?? 0);
        if ($errorCode === 'http_error') {
            $response['error'] = 'gateway_http_' . $status;
        } elseif ($errorCode === 'invalid_json') {
            $response['error'] = 'invalid_gateway_json';
        } elseif ($errorCode === 'curl_error') {
            $response['error'] = 'curl_error';
        } else {
            $response['error'] = $errorCode !== '' ? $errorCode : 'gateway_call_failed';
        }
        return $response;
    }

    public static function razorpay_create_order_remote(int $orderId, string $orderNumber, int $amountPaise): array
    {
        if ($orderId <= 0 || $orderNumber === '' || $amountPaise <= 0) {
            return ['ok' => false, 'error' => 'invalid_create_inputs'];
        }
        $resp = PaymentService::razorpay_http_json('POST', '/v1/orders', [
            'amount' => $amountPaise,
            'currency' => 'INR',
            'receipt' => $orderNumber,
            'payment_capture' => 1,
            'notes' => [
                'local_order_id' => (string) $orderId,
                'order_number' => $orderNumber,
            ],
        ]);
        if (empty($resp['ok'])) {
            return $resp;
        }
        $body = (array) ($resp['body'] ?? []);
        $rzpOrderId = trim((string) ($body['id'] ?? ''));
        if ($rzpOrderId === '') {
            return ['ok' => false, 'error' => 'gateway_order_id_missing', 'status' => (int) ($resp['status'] ?? 0), 'duration_ms' => (int) ($resp['duration_ms'] ?? 0)];
        }
        return ['ok' => true, 'id' => $rzpOrderId, 'status' => (int) ($resp['status'] ?? 0), 'duration_ms' => (int) ($resp['duration_ms'] ?? 0)];
    }

    public static function extract_razorpay_refund_id_from_notes(string $notes): string
    {
        if (preg_match('/refund_id:\s*(rfnd_[A-Za-z0-9]+)/i', $notes, $m)) {
            return trim((string) ($m[1] ?? ''));
        }
        return '';
    }

    public static function latest_refund_ledger_gateway_refund_id(mysqli $conn, int $orderId, string $gateway = 'razorpay'): string
    {
        if ($orderId <= 0) {
            return '';
        }
        $gateway = trim(strtolower($gateway));
        if ($gateway === '') {
            return '';
        }
        try {
            $stmt = $conn->prepare(
                "SELECT gateway_refund_id
                 FROM refund_ledger
                 WHERE order_id = ?
                   AND gateway = ?
                   AND gateway_refund_id IS NOT NULL
                   AND gateway_refund_id <> ''
                 ORDER BY id DESC
                 LIMIT 1"
            );
            $stmt->bind_param('is', $orderId, $gateway);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            return trim((string) ($row['gateway_refund_id'] ?? ''));
        } catch (Throwable $e) {
            return '';
        }
    }

    public static function refund_ledger_event_exists(
        mysqli $conn,
        int $orderId,
        int $paymentId,
        string $status,
        string $gateway = '',
        string $gatewayRefundId = ''
    ): bool {
        if ($orderId <= 0 || $paymentId <= 0) {
            return false;
        }
        $status = trim(strtolower($status));
        if ($status === '') {
            return false;
        }
        $gateway = trim(strtolower($gateway));
        $gatewayRefundId = trim($gatewayRefundId);
        try {
            if ($gatewayRefundId !== '') {
                $stmt = $conn->prepare(
                    "SELECT id
                     FROM refund_ledger
                     WHERE order_id = ?
                       AND payment_id = ?
                       AND status = ?
                       AND gateway = ?
                       AND gateway_refund_id = ?
                     LIMIT 1"
                );
                $stmt->bind_param('iisss', $orderId, $paymentId, $status, $gateway, $gatewayRefundId);
            } else {
                $stmt = $conn->prepare(
                    "SELECT id
                     FROM refund_ledger
                     WHERE order_id = ?
                       AND payment_id = ?
                       AND status = ?
                       AND gateway = ?
                     LIMIT 1"
                );
                $stmt->bind_param('iiss', $orderId, $paymentId, $status, $gateway);
            }
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            return !empty($row);
        } catch (Throwable $e) {
            return false;
        }
    }

    public static function payment_webhook_payload_hash(string $payload): string
    {
        return hash('sha256', $payload);
    }

    public static function cancel_stale_pending_razorpay_order(mysqli $conn, int $orderId, int $ttlMinutes = 30): bool
    {
        if ($orderId <= 0 || $ttlMinutes < 1) {
            return false;
        }
        $note = 'System cancelled stale pending Razorpay order after ' . $ttlMinutes . ' minutes.';
        $conn->begin_transaction();
        try {
            $lock = $conn->prepare(
                "SELECT id
                 FROM orders
                 WHERE id = ?
                   AND payment_method = 'razorpay'
                   AND payment_status IN ('pending', 'failed')
                   AND order_status IN ('pending', 'confirmed')
                   AND created_at < (NOW() - INTERVAL ? MINUTE)
                 FOR UPDATE"
            );
            $lock->bind_param('ii', $orderId, $ttlMinutes);
            $lock->execute();
            if (!$lock->get_result()->fetch_assoc()) {
                $conn->rollback();
                return false;
            }

            $upd = $conn->prepare(
                "UPDATE orders
                 SET payment_status = 'failed',
                     order_status = 'cancelled',
                     status = 'cancelled',
                     " . OrderFieldCompatibilityService::appendNoteAssignments() . ",
                     updated_at = NOW()
                 WHERE id = ?"
            );
            $upd->bind_param('ssssi', $note, $note, $note, $note, $orderId);
            $upd->execute();

            $updPay = $conn->prepare(
                "UPDATE payments
                 SET payment_status = 'failed'
                 WHERE order_id = ? AND payment_method = 'razorpay' AND payment_status = 'pending'"
            );
            $updPay->bind_param('i', $orderId);
            $updPay->execute();

            InventoryService::restore_order_inventory($conn, $orderId);
            release_coupon_usage_for_order($conn, $orderId);
            log_order_activity($conn, $orderId, 'payment_expired', 'system', 0, 'system', $note);
            $conn->commit();
            return true;
        } catch (Throwable $e) {
            try {
                $conn->rollback();
            } catch (Throwable $rollbackException) {
                // ignore rollback failure
            }
            throw $e;
        }
    }

    public static function release_stale_pending_razorpay_orders_for_customer(mysqli $conn, int $customerId, int $ttlMinutes = 30): void
    {
        if ($customerId <= 0 || $ttlMinutes < 1) {
            return;
        }
        $stmt = $conn->prepare(
            "SELECT id
             FROM orders
             WHERE customer_id = ?
               AND payment_method = 'razorpay'
               AND payment_status IN ('pending', 'failed')
               AND order_status IN ('pending', 'confirmed')
               AND created_at < (NOW() - INTERVAL ? MINUTE)"
        );
        $stmt->bind_param('ii', $customerId, $ttlMinutes);
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        foreach ($rows as $row) {
            $orderId = (int) ($row['id'] ?? 0);
            if ($orderId <= 0) {
                continue;
            }
            PaymentService::cancel_stale_pending_razorpay_order($conn, $orderId, $ttlMinutes);
        }
    }

    public static function release_stale_pending_razorpay_orders_global(mysqli $conn, int $ttlMinutes = 30, int $limit = 100): int
    {
        $report = self::release_stale_pending_razorpay_orders_global_report($conn, $ttlMinutes, $limit);
        return (int) ($report['succeeded'] ?? 0);
    }

    public static function release_stale_pending_razorpay_orders_global_report(mysqli $conn, int $ttlMinutes = 30, int $limit = 100): array
    {
        if ($ttlMinutes < 1) {
            return CronService::result('skipped', 0, 0, 0, ['reason' => 'invalid_ttl']);
        }
        $limit = max(1, min(500, $limit));
        $stmt = $conn->prepare(
            "SELECT id
             FROM orders
             WHERE payment_method = 'razorpay'
               AND payment_status IN ('pending', 'failed')
               AND order_status IN ('pending', 'confirmed')
               AND created_at < (NOW() - INTERVAL ? MINUTE)
             ORDER BY id ASC
             LIMIT ?"
        );
        $stmt->bind_param('ii', $ttlMinutes, $limit);
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $released = 0;
        $failedOrderIds = [];
        foreach ($rows as $row) {
            $orderId = (int) ($row['id'] ?? 0);
            if ($orderId <= 0) {
                continue;
            }
            try {
                if (PaymentService::cancel_stale_pending_razorpay_order($conn, $orderId, $ttlMinutes)) {
                    $released++;
                }
            } catch (Throwable $e) {
                $failedOrderIds[] = $orderId;
                error_log('[payment] stale Razorpay release failed for order ' . $orderId . ': ' . CronService::sanitizeError($e->getMessage()));
            }
        }
        $failed = count($failedOrderIds);
        return CronService::result($failed > 0 ? 'degraded' : 'success', count($rows), $released, $failed, [
            'released_count' => $released,
            'failed_order_ids' => $failedOrderIds,
            'ttl_minutes' => $ttlMinutes,
            'limit' => $limit,
        ]);
    }

    public static function checkout_shipping_for_order(float $subtotal, string $country, string $pincode, string $paymentMethod): array
    {
        return CartService::checkout_shipping_breakdown($subtotal, $country, $paymentMethod, $paymentMethod === 'cod');
    }

    public static function payment_webhook_mark_processed(
        mysqli $conn,
        string $provider,
        string $eventId,
        string $signature,
        ?string $payloadHash = null,
        ?string $rawPayload = null,
        int $expectedAttempt = 0
    ): void
    {
        if ($provider === '' || $eventId === '') {
            return;
        }
        $hash = trim((string) $payloadHash);
        $payload = $rawPayload;
        $processedAt = date('Y-m-d H:i:s');
        if ($expectedAttempt > 0) {
            $payloadValue = (string) ($payload ?? '');
            $stmt = $conn->prepare(
                "UPDATE payment_webhook_events
                 SET signature = CASE WHEN ? <> '' THEN ? ELSE signature END,
                     payload_hash = CASE WHEN ? <> '' THEN ? ELSE payload_hash END,
                     raw_payload = CASE WHEN ? <> '' THEN ? ELSE raw_payload END,
                     status = 'processed',
                     last_error = NULL,
                     processed_at = ?,
                     updated_at = NOW()
                 WHERE provider = ? AND event_id = ?
                   AND status = 'processing' AND attempts = ?"
            );
            $stmt->bind_param(
                'sssssssssi',
                $signature,
                $signature,
                $hash,
                $hash,
                $payloadValue,
                $payloadValue,
                $processedAt,
                $provider,
                $eventId,
                $expectedAttempt
            );
            $stmt->execute();
            return;
        }
        $stmt = $conn->prepare(
            "INSERT INTO payment_webhook_events (
                provider, event_id, signature, payload_hash, raw_payload, status, attempts, processed_at, created_at, updated_at
            )
             VALUES (?, ?, ?, ?, ?, 'processed', 1, ?, NOW(), NOW())
             ON DUPLICATE KEY UPDATE
                signature = VALUES(signature),
                payload_hash = CASE WHEN VALUES(payload_hash) <> '' THEN VALUES(payload_hash) ELSE payload_hash END,
                raw_payload = CASE WHEN VALUES(raw_payload) IS NOT NULL AND VALUES(raw_payload) <> '' THEN VALUES(raw_payload) ELSE raw_payload END,
                status = 'processed',
                processed_at = VALUES(processed_at),
                updated_at = NOW()"
        );
        $stmt->bind_param('ssssss', $provider, $eventId, $signature, $hash, $payload, $processedAt);
        $stmt->execute();
    }

    /**
     * Atomically moves webhook event into a lifecycle state.
     *
     * Return shape:
     * - state: one of claimed|already_processed|in_progress
     * - attempts: current attempt count
     * - status: current persisted status
     */
    public static function payment_webhook_begin_processing(
        mysqli $conn,
        string $provider,
        string $eventId,
        string $signature,
        string $payload,
        int $processingTtlSeconds = 120
    ): array
    {
        require_once __DIR__ . '/WebhookLifecycleService.php';
        return WebhookLifecycleService::beginProcessing(
            $conn,
            $provider,
            $eventId,
            $signature,
            $payload,
            $processingTtlSeconds
        );
    }

    public static function payment_webhook_mark_failed(
        mysqli $conn,
        string $provider,
        string $eventId,
        string $errorMessage,
        string $signature = '',
        int $expectedAttempt = 0
    ): void
    {
        if ($provider === '' || $eventId === '') {
            return;
        }
        $errorMessage = trim($errorMessage);
        if ($errorMessage === '') {
            $errorMessage = 'Webhook processing failed.';
        }
        if ($expectedAttempt > 0) {
            $stmt = $conn->prepare(
                "UPDATE payment_webhook_events
                 SET status = 'failed',
                     last_error = ?,
                     updated_at = NOW(),
                     signature = CASE WHEN ? <> '' THEN ? ELSE signature END
                 WHERE provider = ? AND event_id = ?
                   AND status = 'processing' AND attempts = ?"
            );
            $stmt->bind_param('sssssi', $errorMessage, $signature, $signature, $provider, $eventId, $expectedAttempt);
            $stmt->execute();
            return;
        }
        $stmt = $conn->prepare(
            "UPDATE payment_webhook_events
             SET status = 'failed',
                 last_error = ?,
                 updated_at = NOW(),
                 signature = CASE WHEN ? <> '' THEN ? ELSE signature END
             WHERE provider = ? AND event_id = ?"
        );
        $stmt->bind_param('sssss', $errorMessage, $signature, $signature, $provider, $eventId);
        $stmt->execute();
    }

    public static function cleanup_payment_webhook_events(
        mysqli $conn,
        int $payloadRetentionDays = 30,
        int $auditRetentionDays = 0,
        int $limit = 500
    ): array {
        $payloadRetentionDays = max(1, min(3650, $payloadRetentionDays));
        $auditRetentionDays = max(0, min(36500, $auditRetentionDays));
        $limit = max(1, min(5000, $limit));

        $scrub = $conn->prepare(
            "UPDATE payment_webhook_events
             SET raw_payload = NULL,
                 updated_at = updated_at
             WHERE status = 'processed'
               AND raw_payload IS NOT NULL
               AND processed_at IS NOT NULL
               AND processed_at < DATE_SUB(NOW(), INTERVAL {$payloadRetentionDays} DAY)
             ORDER BY processed_at ASC, id ASC
             LIMIT {$limit}"
        );
        $scrub->execute();
        $scrubbed = max(0, (int) $scrub->affected_rows);

        $deleted = 0;
        $remaining = max(0, $limit - $scrubbed);
        $deleteEnabled = $auditRetentionDays > $payloadRetentionDays;
        if ($deleteEnabled && $remaining > 0) {
            $delete = $conn->prepare(
                "DELETE FROM payment_webhook_events
                 WHERE status = 'processed'
                   AND raw_payload IS NULL
                   AND processed_at IS NOT NULL
                   AND processed_at < DATE_SUB(NOW(), INTERVAL {$auditRetentionDays} DAY)
                 ORDER BY processed_at ASC, id ASC
                 LIMIT {$remaining}"
            );
            $delete->execute();
            $deleted = max(0, (int) $delete->affected_rows);
        }

        $processed = $scrubbed + $deleted;
        return CronService::result('success', $processed, $processed, 0, [
            'scrubbed_count' => $scrubbed,
            'deleted_count' => $deleted,
            'payload_retention_days' => $payloadRetentionDays,
            'audit_retention_days' => $auditRetentionDays,
            'audit_delete_enabled' => $deleteEnabled,
            'limit' => $limit,
        ]);
    }

    /**
     * Upsert payment attempt audit row by provider + gateway attempt reference.
     * attemptRef should be gateway order id (e.g. Razorpay order id).
     */
    public static function payment_attempt_touch(
        mysqli $conn,
        string $provider,
        string $attemptRef,
        int $orderId = 0,
        int $paymentId = 0,
        string $status = 'created',
        string $source = 'create',
        string $gatewayPaymentId = '',
        string $gatewaySignature = '',
        string $errorCode = '',
        string $errorMessage = '',
        string $webhookEventId = '',
        string $webhookSignature = '',
        ?string $payloadJson = null,
        bool $incrementRetry = false
    ): void {
        $provider = trim($provider);
        $attemptRef = trim($attemptRef);
        if ($provider === '' || $attemptRef === '') {
            return;
        }
        $status = trim($status) !== '' ? trim($status) : 'created';
        $source = trim($source) !== '' ? trim($source) : 'create';
        $gatewayPaymentId = trim($gatewayPaymentId);
        $gatewaySignature = trim($gatewaySignature);
        $errorCode = trim($errorCode);
        $errorMessage = trim($errorMessage);
        $webhookEventId = trim($webhookEventId);
        $webhookSignature = trim($webhookSignature);
        if ($payloadJson === null) {
            $payloadJson = '';
        }

        try {
            $retryBump = $incrementRetry ? 1 : 0;
            $stmt = $conn->prepare(
                "INSERT INTO payment_attempts (
                    order_id, payment_id, provider, attempt_ref, status, source,
                    gateway_payment_id, gateway_signature, error_code, error_message,
                    webhook_event_id, webhook_signature, payload_json,
                    retry_count, first_seen_at, last_seen_at
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())
                ON DUPLICATE KEY UPDATE
                    order_id = CASE WHEN VALUES(order_id) > 0 THEN VALUES(order_id) ELSE order_id END,
                    payment_id = CASE WHEN VALUES(payment_id) > 0 THEN VALUES(payment_id) ELSE payment_id END,
                    status = VALUES(status),
                    source = VALUES(source),
                    gateway_payment_id = CASE WHEN VALUES(gateway_payment_id) <> '' THEN VALUES(gateway_payment_id) ELSE gateway_payment_id END,
                    gateway_signature = CASE WHEN VALUES(gateway_signature) <> '' THEN VALUES(gateway_signature) ELSE gateway_signature END,
                    error_code = CASE WHEN VALUES(error_code) <> '' THEN VALUES(error_code) ELSE error_code END,
                    error_message = CASE WHEN VALUES(error_message) <> '' THEN VALUES(error_message) ELSE error_message END,
                    webhook_event_id = CASE WHEN VALUES(webhook_event_id) <> '' THEN VALUES(webhook_event_id) ELSE webhook_event_id END,
                    webhook_signature = CASE WHEN VALUES(webhook_signature) <> '' THEN VALUES(webhook_signature) ELSE webhook_signature END,
                    payload_json = CASE WHEN VALUES(payload_json) <> '' THEN VALUES(payload_json) ELSE payload_json END,
                    retry_count = retry_count + VALUES(retry_count),
                    last_seen_at = NOW()"
            );
            $stmt->bind_param(
                'iissssssssssis',
                $orderId,
                $paymentId,
                $provider,
                $attemptRef,
                $status,
                $source,
                $gatewayPaymentId,
                $gatewaySignature,
                $errorCode,
                $errorMessage,
                $webhookEventId,
                $webhookSignature,
                $payloadJson,
                $retryBump
            );
            $stmt->execute();
        } catch (Throwable $e) {
            error_log('[app] payment_attempt_touch failed: ' . $e->getMessage());
        }
    }

    /**
     * For cancelled + paid Razorpay orders, create a real gateway refund first.
     * Mark local order/payment as refunded only when gateway reports processed.
     * Returns ['ok' => bool, 'message' => string].
     */
    public static function admin_mark_order_refunded(mysqli $conn, int $orderId): array
    {
        try {
            $conn->begin_transaction();

            $orderStmt = $conn->prepare(
                "SELECT id, order_number, payment_method, payment_status, order_status, order_notes
                 FROM orders
                 WHERE id = ?
                 FOR UPDATE"
            );
            $orderStmt->bind_param('i', $orderId);
            $orderStmt->execute();
            $order = $orderStmt->get_result()->fetch_assoc();
            if (!$order) {
                throw new RuntimeException('Order not found.');
            }

            $method = strtolower((string) ($order['payment_method'] ?? ''));
            $payStatus = strtolower((string) ($order['payment_status'] ?? ''));
            $ordStatus = strtolower((string) ($order['order_status'] ?? ''));

            if ($ordStatus !== 'cancelled' || $payStatus !== 'paid') {
                throw new RuntimeException('Order is not eligible for refund update.');
            }

            $payStmt = $conn->prepare(
                "SELECT id, amount, razorpay_payment_id, transaction_id
                 FROM payments
                 WHERE order_id = ? AND payment_method = ?
                 LIMIT 1
                 FOR UPDATE"
            );
            $payStmt->bind_param('is', $orderId, $method);
            $payStmt->execute();
            $payment = $payStmt->get_result()->fetch_assoc();
            $resolvedGatewayRefundId = '';

            if ($method === 'razorpay') {
                if (!$payment) {
                    throw new RuntimeException('Payment record not found for Razorpay order.');
                }

                $paymentId = trim((string) ($payment['razorpay_payment_id'] ?? ''));
                if ($paymentId === '') {
                    $paymentId = trim((string) ($payment['transaction_id'] ?? ''));
                }
                if ($paymentId === '') {
                    throw new RuntimeException('Missing Razorpay payment id.');
                }

                require_once __DIR__ . '/../vendor/autoload.php';
                $keyId = _cfg('RAZORPAY_KEY_ID', '');
                $keySecret = _cfg('RAZORPAY_KEY_SECRET', '');
                if ($keyId === '' || $keySecret === '') {
                    throw new RuntimeException('Razorpay configuration missing.');
                }

                $amountPaise = 0;
                if (isset($payment['amount']) && is_numeric($payment['amount'])) {
                    $amountPaise = max(0, (int) round(((float) $payment['amount']) * 100));
                }

                $refundStatus = '';
                $existingRefundId = '';
                $existingNotes = (string) ($order['order_notes'] ?? '');
                $existingRefundId = PaymentService::extract_razorpay_refund_id_from_notes($existingNotes);
                if ($existingRefundId === '') {
                    $existingRefundId = PaymentService::latest_refund_ledger_gateway_refund_id($conn, $orderId, 'razorpay');
                }
                try {
                    $api = new Razorpay\Api\Api($keyId, $keySecret);
                    $refundId = '';
                    if ($existingRefundId !== '') {
                        $refund = $api->refund->fetch($existingRefundId);
                        $refundId = $existingRefundId;
                    } else {
                        $payload = ['speed' => 'normal'];
                        if ($amountPaise > 0) {
                            $payload['amount'] = $amountPaise;
                        }
                        $refund = $api->payment->fetch($paymentId)->refund($payload);
                        $refundId = trim((string) ($refund['id'] ?? ''));
                    }
                    $resolvedGatewayRefundId = $refundId;
                    $refundStatus = strtolower(trim((string) ($refund['status'] ?? '')));
                    if ($existingRefundId === '') {
                        $refundNote = '[System] Razorpay refund initiated';
                        if ($refundId !== '') {
                            $refundNote .= ' (refund_id: ' . $refundId . ')';
                        }
                        if ($refundStatus !== '') {
                            $refundNote .= ' [status: ' . $refundStatus . ']';
                        }
                        $refundNote .= ' on ' . date('d M Y, H:i');

                        $updNotes = $conn->prepare(
                            "UPDATE orders
                             SET " . OrderFieldCompatibilityService::appendNoteAssignments() . "
                             WHERE id = ?"
                        );
                        $updNotes->bind_param('ssssi', $refundNote, $refundNote, $refundNote, $refundNote, $orderId);
                        $updNotes->execute();

                        $paymentRowId = (int) ($payment['id'] ?? 0);
                        $refundAmount = isset($payment['amount']) ? (float) $payment['amount'] : 0.0;
                        if (
                            $paymentRowId > 0 &&
                            $refundAmount > 0 &&
                            !PaymentService::refund_ledger_event_exists($conn, $orderId, $paymentRowId, 'initiated', 'razorpay', $refundId)
                        ) {
                            log_refund_ledger(
                                $conn,
                                $orderId,
                                $paymentRowId,
                                $refundAmount,
                                'INR',
                                'initiated',
                                'razorpay',
                                $refundId,
                                'Refund initiated from admin order view.'
                            );
                        }
                        log_order_activity($conn, $orderId, 'refund_initiated', 'admin', (int) ($_SESSION['admin_id'] ?? 0), (string) ($_SESSION['admin_name'] ?? 'admin'), 'Razorpay refund initiated.');
                    }
                } catch (Throwable $e) {
                    throw new RuntimeException('Razorpay refund failed: ' . $e->getMessage());
                }

                if ($refundStatus !== 'processed') {
                    $conn->commit();
                    return [
                        'ok' => true,
                        'message' => 'Refund initiated in Razorpay (status: ' . ($refundStatus !== '' ? $refundStatus : 'processing') . '). Keep payment as Paid until processed.',
                    ];
                }
            }

            $refundedLegacyStatus = OrderFieldCompatibilityService::legacyStatus('refunded');
            $updOrder = $conn->prepare(
                "UPDATE orders
                 SET payment_status = 'refunded',
                     order_status = 'refunded',
                     status = ?,
                     updated_at = NOW()
                 WHERE id = ?"
            );
            $updOrder->bind_param('si', $refundedLegacyStatus, $orderId);
            $updOrder->execute();

            if ($payment) {
                $updPayment = $conn->prepare(
                    "UPDATE payments
                     SET payment_status = 'refunded'
                     WHERE id = ?"
                );
                $paymentRowId = (int) ($payment['id'] ?? 0);
                $updPayment->bind_param('i', $paymentRowId);
                $updPayment->execute();
                $refundAmount = isset($payment['amount']) ? (float) $payment['amount'] : 0.0;
                if (
                    $refundAmount > 0 &&
                    !PaymentService::refund_ledger_event_exists($conn, $orderId, $paymentRowId, 'processed', $method, $resolvedGatewayRefundId)
                ) {
                    log_refund_ledger(
                        $conn,
                        $orderId,
                        $paymentRowId,
                        $refundAmount,
                        'INR',
                        'processed',
                        $method,
                        $resolvedGatewayRefundId,
                        'Refund marked processed from admin flow.'
                    );
                }
            }

            // Restore inventory after successful refund completion for cancelled paid orders.
            InventoryService::restore_order_inventory($conn, $orderId);
            log_order_activity($conn, $orderId, 'refund_completed', 'admin', (int) ($_SESSION['admin_id'] ?? 0), (string) ($_SESSION['admin_name'] ?? 'admin'), 'Order marked refunded.');

            $conn->commit();
            return ['ok' => true, 'message' => 'Order marked as refunded.'];
        } catch (Throwable $e) {
            try {
                $conn->rollback();
            } catch (Throwable $rollbackException) {
                // ignore rollback errors
            }
            return ['ok' => false, 'message' => $e->getMessage() ?: 'Refund failed.'];
        }
    }

    /**
     * Mark an order payment as paid from admin for manual/offline reconciliation.
     * Returns ['ok' => bool, 'message' => string, 'email_sent' => bool].
     */
    public static function admin_mark_order_paid(mysqli $conn, int $orderId): array
    {
        $orderNumber = '';
        $paymentMethod = '';
        $confirmationEventId = 0;
        try {
            $conn->begin_transaction();

            $orderStmt = $conn->prepare(
                "SELECT id, order_number, payment_method, payment_status, order_status
                 FROM orders
                 WHERE id = ?
                 FOR UPDATE"
            );
            $orderStmt->bind_param('i', $orderId);
            $orderStmt->execute();
            $order = $orderStmt->get_result()->fetch_assoc();
            if (!$order) {
                throw new RuntimeException('Order not found.');
            }

            $orderNumber = (string) ($order['order_number'] ?? '');
            $paymentMethod = strtolower((string) ($order['payment_method'] ?? ''));
            $currentPaymentStatus = strtolower((string) ($order['payment_status'] ?? 'pending'));
            $currentOrderStatus = strtolower((string) ($order['order_status'] ?? 'pending'));

            if ($currentPaymentStatus === 'refunded') {
                throw new RuntimeException('Refunded payments cannot be marked paid.');
            }
            if ($currentOrderStatus === 'cancelled' || $currentOrderStatus === 'refunded') {
                throw new RuntimeException('Cancelled/refunded orders cannot be marked paid.');
            }

            if ($currentPaymentStatus === 'paid') {
                $conn->commit();
                return ['ok' => true, 'message' => 'Payment is already marked as paid.', 'email_sent' => false];
            }

            $nextOrderStatus = in_array($currentOrderStatus, ['pending', 'failed'], true) ? 'confirmed' : $currentOrderStatus;
            $nextLegacyStatus = OrderFieldCompatibilityService::legacyStatus($nextOrderStatus);

            $updateOrder = $conn->prepare(
                "UPDATE orders
                 SET payment_status = 'paid',
                     order_status = ?,
                     status = ?,
                     updated_at = NOW()
                 WHERE id = ?"
            );
            $updateOrder->bind_param('ssi', $nextOrderStatus, $nextLegacyStatus, $orderId);
            $updateOrder->execute();

            $paymentRowId = 0;
            $paymentStmt = $conn->prepare(
                "SELECT id
                 FROM payments
                 WHERE order_id = ?
                 ORDER BY id DESC
                 LIMIT 1
                 FOR UPDATE"
            );
            $paymentStmt->bind_param('i', $orderId);
            $paymentStmt->execute();
            $paymentRow = $paymentStmt->get_result()->fetch_assoc();
            if ($paymentRow) {
                $paymentRowId = (int) ($paymentRow['id'] ?? 0);
                $updPayment = $conn->prepare("UPDATE payments SET payment_status = 'paid' WHERE id = ?");
                $updPayment->bind_param('i', $paymentRowId);
                $updPayment->execute();
            } else {
                $insPayment = $conn->prepare(
                    "INSERT INTO payments (order_id, payment_method, payment_status, amount)
                     SELECT id, payment_method, 'paid', total_amount
                     FROM orders
                     WHERE id = ?"
                );
                $insPayment->bind_param('i', $orderId);
                $insPayment->execute();
                $paymentRowId = (int) $conn->insert_id;
            }

            $adminId = (int) ($_SESSION['admin_id'] ?? 0);
            $adminName = (string) ($_SESSION['admin_name'] ?? 'admin');
            $activityDetails = 'Payment marked paid by admin';
            if ($paymentMethod !== '') {
                $activityDetails .= ' (' . strtoupper($paymentMethod) . ')';
            }
            log_order_activity($conn, $orderId, 'payment_marked_paid', 'admin', $adminId, $adminName, $activityDetails);

            $events = OutboxService::enqueuePaidOrderSideEffects($conn, $orderId, [
                'order_id' => $orderId,
                'order_number' => $orderNumber,
                'payment_method' => $paymentMethod,
                'payment_id' => '',
                'source' => 'admin_mark_paid',
                'payment_row_id' => $paymentRowId,
            ]);
            $confirmationEventId = (int) ($events['confirmation_email'] ?? 0);
            $conn->commit();

            OutboxService::safeDrainForOrder($conn, $orderId);
            $emailSent = OutboxService::isCompleted($conn, $confirmationEventId);

            return [
                'ok' => true,
                'message' => $emailSent ? 'Payment marked as paid and confirmation email sent.' : 'Payment marked as paid.',
                'email_sent' => $emailSent,
            ];
        } catch (Throwable $e) {
            try {
                $conn->rollback();
            } catch (Throwable $rollbackException) {
                // ignore rollback errors
            }
            return ['ok' => false, 'message' => $e->getMessage() ?: 'Unable to mark payment as paid.', 'email_sent' => false];
        }
    }

    /**
     * Sync local refund status with Razorpay using stored refund_id in order notes.
     * Useful for previously mismatched orders.
     * Returns ['ok' => bool, 'message' => string].
     */
    public static function admin_sync_razorpay_refund_status(mysqli $conn, int $orderId): array
    {
        try {
            $conn->begin_transaction();

            $orderStmt = $conn->prepare(
                "SELECT id, payment_method, payment_status, order_status, order_notes
                 FROM orders
                 WHERE id = ?
                 FOR UPDATE"
            );
            $orderStmt->bind_param('i', $orderId);
            $orderStmt->execute();
            $order = $orderStmt->get_result()->fetch_assoc();
            if (!$order) {
                throw new RuntimeException('Order not found.');
            }

            $method = strtolower((string) ($order['payment_method'] ?? ''));
            if ($method !== 'razorpay') {
                throw new RuntimeException('Sync is available only for Razorpay orders.');
            }

            $notes = (string) ($order['order_notes'] ?? '');
            $refundId = PaymentService::extract_razorpay_refund_id_from_notes($notes);
            if ($refundId === '') {
                $refundId = PaymentService::latest_refund_ledger_gateway_refund_id($conn, $orderId, 'razorpay');
            }
            if ($refundId === '') {
                throw new RuntimeException('No Razorpay refund_id found in order notes.');
            }

            require_once __DIR__ . '/../vendor/autoload.php';
            $keyId = _cfg('RAZORPAY_KEY_ID', '');
            $keySecret = _cfg('RAZORPAY_KEY_SECRET', '');
            if ($keyId === '' || $keySecret === '') {
                throw new RuntimeException('Razorpay configuration missing.');
            }

            $api = new Razorpay\Api\Api($keyId, $keySecret);
            $refund = $api->refund->fetch($refundId);
            $refundStatus = strtolower(trim((string) ($refund['status'] ?? '')));

            $paymentRowStmt = $conn->prepare(
                "SELECT id FROM payments WHERE order_id = ? AND payment_method = 'razorpay' LIMIT 1 FOR UPDATE"
            );
            $paymentRowStmt->bind_param('i', $orderId);
            $paymentRowStmt->execute();
            $payment = $paymentRowStmt->get_result()->fetch_assoc();

            if ($refundStatus === 'processed') {
                $refundedLegacyStatus = OrderFieldCompatibilityService::legacyStatus('refunded');
                $updOrder = $conn->prepare(
                    "UPDATE orders
                     SET payment_status = 'refunded',
                         order_status = 'refunded',
                         status = ?,
                         updated_at = NOW()
                     WHERE id = ?"
                );
                $updOrder->bind_param('si', $refundedLegacyStatus, $orderId);
                $updOrder->execute();

                if ($payment) {
                    $updPayment = $conn->prepare("UPDATE payments SET payment_status = 'refunded' WHERE id = ?");
                    $paymentId = (int) $payment['id'];
                    $updPayment->bind_param('i', $paymentId);
                    $updPayment->execute();
                    $payAmtStmt = $conn->prepare("SELECT amount FROM payments WHERE id = ? LIMIT 1");
                    $payAmtStmt->bind_param('i', $paymentId);
                    $payAmtStmt->execute();
                    $payAmtRow = $payAmtStmt->get_result()->fetch_assoc() ?: [];
                    $refundAmount = (float) ($payAmtRow['amount'] ?? 0);
                    if (
                        $refundAmount > 0 &&
                        !PaymentService::refund_ledger_event_exists($conn, $orderId, $paymentId, 'processed', 'razorpay', $refundId)
                    ) {
                        log_refund_ledger(
                            $conn,
                            $orderId,
                            $paymentId,
                            $refundAmount,
                            'INR',
                            'processed',
                            'razorpay',
                            $refundId,
                            'Refund processed confirmed by Razorpay sync.'
                        );
                    }
                }

                InventoryService::restore_order_inventory($conn, $orderId);
                log_order_activity($conn, $orderId, 'refund_completed', 'admin', (int) ($_SESSION['admin_id'] ?? 0), (string) ($_SESSION['admin_name'] ?? 'admin'), 'Refund synced as processed from Razorpay.');

                $conn->commit();
                return ['ok' => true, 'message' => 'Refund processed in Razorpay. Local status updated to Refunded.'];
            }

            // Not processed yet: keep "refund initiated" state locally.
            $updOrder = $conn->prepare(
                "UPDATE orders
                 SET payment_status = 'paid',
                     order_status = 'cancelled',
                     status = 'cancelled',
                     updated_at = NOW()
                 WHERE id = ?"
            );
            $updOrder->bind_param('i', $orderId);
            $updOrder->execute();

            if ($payment) {
                $updPayment = $conn->prepare("UPDATE payments SET payment_status = 'paid' WHERE id = ?");
                $paymentId = (int) $payment['id'];
                $updPayment->bind_param('i', $paymentId);
                $updPayment->execute();
            }

            $conn->commit();
            return ['ok' => true, 'message' => 'Refund is still ' . ($refundStatus !== '' ? $refundStatus : 'processing') . ' in Razorpay. Local status corrected to Refund Initiated state.'];
        } catch (Throwable $e) {
            try {
                $conn->rollback();
            } catch (Throwable $rollbackException) {
                // ignore rollback errors
            }
            return ['ok' => false, 'message' => $e->getMessage() ?: 'Refund sync failed.'];
        }
    }
}
