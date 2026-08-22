<?php

final class OutboxService
{
    // One immediate attempt plus five cron retries.
    private const MAX_ATTEMPTS = 6;
    private const STALE_CLAIM_MINUTES = 15;
    private const RETRY_DELAYS_SECONDS = [60, 300, 900, 3600, 14400];

    public static function enqueue(
        mysqli $conn,
        string $topic,
        int $aggregateId,
        array $payload,
        string $dedupeKey,
        string $aggregateType = 'order'
    ): int {
        if ($aggregateId <= 0 || trim($topic) === '' || trim($dedupeKey) === '') {
            throw new InvalidArgumentException('Invalid outbox event.');
        }
        unset($payload['conn']);
        $payloadJson = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
        if ($payloadJson === false) {
            throw new RuntimeException('Unable to encode outbox payload.');
        }
        $stmt = $conn->prepare(
            "INSERT INTO commerce_outbox (topic, aggregate_type, aggregate_id, dedupe_key, payload_json)
             VALUES (?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE id = LAST_INSERT_ID(id)"
        );
        $stmt->bind_param('ssiss', $topic, $aggregateType, $aggregateId, $dedupeKey, $payloadJson);
        $stmt->execute();
        return (int) $conn->insert_id;
    }

    public static function enqueueOrderAfterCommit(mysqli $conn, int $orderId, array $context): int
    {
        return self::enqueue($conn, 'order.after_commit', $orderId, $context, "order:{$orderId}:after_commit:v1");
    }

    public static function enqueuePaymentSuccess(mysqli $conn, int $orderId, array $context): int
    {
        return self::enqueue($conn, 'order.after_payment_success', $orderId, $context, "order:{$orderId}:payment_success:v1");
    }

    public static function enqueueOrderConfirmation(mysqli $conn, int $orderId): int
    {
        return self::enqueue($conn, 'order.confirmation_email', $orderId, [], "order:{$orderId}:confirmation_email:v1");
    }

    public static function enqueueAccountActivation(mysqli $conn, int $orderId): int
    {
        return self::enqueue($conn, 'order.account_activation_email', $orderId, [], "order:{$orderId}:account_activation_email:v1");
    }

    /** @return array{payment_success:int,confirmation_email:int,account_activation_email:int} */
    public static function enqueuePaidOrderSideEffects(mysqli $conn, int $orderId, array $context): array
    {
        return [
            'payment_success' => self::enqueuePaymentSuccess($conn, $orderId, $context),
            'confirmation_email' => self::enqueueOrderConfirmation($conn, $orderId),
            'account_activation_email' => self::enqueueAccountActivation($conn, $orderId),
        ];
    }

    public static function isCompleted(mysqli $conn, int $eventId): bool
    {
        if ($eventId <= 0) {
            return false;
        }
        $stmt = $conn->prepare("SELECT status FROM commerce_outbox WHERE id = ? LIMIT 1");
        $stmt->bind_param('i', $eventId);
        $stmt->execute();
        return (string) ($stmt->get_result()->fetch_assoc()['status'] ?? '') === 'completed';
    }

    public static function drainForOrder(mysqli $conn, int $orderId, int $limit = 20): array
    {
        return self::processBatch($conn, $limit, 'order', $orderId);
    }

    public static function safeDrainForOrder(mysqli $conn, int $orderId, int $limit = 20): array
    {
        try {
            return self::drainForOrder($conn, $orderId, $limit);
        } catch (Throwable $e) {
            error_log('[outbox] immediate delivery failed: ' . CronService::sanitizeError($e->getMessage()));
            return CronService::result('degraded', 0, 0, 1);
        }
    }

    public static function processBatch(
        mysqli $conn,
        int $limit = 50,
        ?string $aggregateType = null,
        ?int $aggregateId = null
    ): array {
        $processed = 0;
        $succeeded = 0;
        $failed = 0;
        $limit = max(1, min(200, $limit));

        while ($processed < $limit) {
            $event = self::claimNext($conn, $aggregateType, $aggregateId);
            if (!$event) {
                break;
            }
            $processed++;
            try {
                self::deliver($conn, $event);
                self::completeEvent($conn, (int) $event['id'], (string) $event['claim_token']);
                $succeeded++;
            } catch (Throwable $e) {
                self::failEvent(
                    $conn,
                    (int) $event['id'],
                    (string) $event['claim_token'],
                    (int) $event['attempts'],
                    $e->getMessage()
                );
                $failed++;
            }
        }

        return CronService::result($failed > 0 ? 'degraded' : 'success', $processed, $succeeded, $failed, [
            'max_attempts' => self::MAX_ATTEMPTS,
        ]);
    }

    private static function claimNext(mysqli $conn, ?string $aggregateType, ?int $aggregateId): ?array
    {
        $whereAggregate = '';
        if ($aggregateType !== null && $aggregateId !== null) {
            $whereAggregate = ' AND aggregate_type = ? AND aggregate_id = ?';
        }
        $select = $conn->prepare(
            "SELECT id
             FROM commerce_outbox
             WHERE attempts < ?
               AND (
                    (status IN ('pending','failed') AND available_at <= NOW())
                    OR (status = 'processing' AND claimed_at < DATE_SUB(NOW(), INTERVAL " . self::STALE_CLAIM_MINUTES . " MINUTE))
               ){$whereAggregate}
             ORDER BY available_at ASC, id ASC
             LIMIT 1"
        );
        $maxAttempts = self::MAX_ATTEMPTS;
        if ($whereAggregate !== '') {
            $select->bind_param('isi', $maxAttempts, $aggregateType, $aggregateId);
        } else {
            $select->bind_param('i', $maxAttempts);
        }
        $select->execute();
        $candidate = $select->get_result()->fetch_assoc();
        if (!$candidate) {
            return null;
        }

        $eventId = (int) $candidate['id'];
        $claimToken = bin2hex(random_bytes(16));
        $claim = $conn->prepare(
            "UPDATE commerce_outbox
             SET status = 'processing', claim_token = ?, claimed_at = NOW(), attempts = attempts + 1, last_error = NULL
             WHERE id = ? AND attempts < ?
               AND (
                    (status IN ('pending','failed') AND available_at <= NOW())
                    OR (status = 'processing' AND claimed_at < DATE_SUB(NOW(), INTERVAL " . self::STALE_CLAIM_MINUTES . " MINUTE))
               )"
        );
        $claim->bind_param('sii', $claimToken, $eventId, $maxAttempts);
        $claim->execute();
        if ($claim->affected_rows !== 1) {
            return null;
        }

        $fetch = $conn->prepare(
            "SELECT id, topic, aggregate_type, aggregate_id, payload_json, attempts, claim_token
             FROM commerce_outbox WHERE id = ? AND claim_token = ? LIMIT 1"
        );
        $fetch->bind_param('is', $eventId, $claimToken);
        $fetch->execute();
        return $fetch->get_result()->fetch_assoc() ?: null;
    }

    private static function deliver(mysqli $conn, array $event): void
    {
        $eventId = (int) ($event['id'] ?? 0);
        $orderId = (int) ($event['aggregate_id'] ?? 0);
        $topic = (string) ($event['topic'] ?? '');
        $payload = json_decode((string) ($event['payload_json'] ?? ''), true);
        $payload = is_array($payload) ? $payload : [];
        $payload['conn'] = $conn;
        $payload['order_id'] = $orderId;

        if ($topic === 'order.after_commit' || $topic === 'order.after_payment_success') {
            self::deliverHook($conn, $eventId, $topic, $payload);
            return;
        }
        if ($topic === 'order.confirmation_email') {
            self::deliverHandler($conn, $eventId, 'email:order_confirmation', static function () use ($conn, $orderId): void {
                if (!EmailService::send_order_confirmation_email($conn, $orderId)) {
                    throw new RuntimeException('Order confirmation email delivery failed.');
                }
            });
            return;
        }
        if ($topic === 'order.account_activation_email') {
            self::deliverHandler($conn, $eventId, 'email:account_activation', static function () use ($conn, $orderId): void {
                $state = self::activationState($conn, $orderId);
                if (!$state || (int) ($state['account_activation_requested'] ?? 0) !== 1
                    || !empty($state['account_activation_sent_at'])) {
                    return;
                }
                if (!EmailService::send_requested_account_activation_email($conn, $orderId)) {
                    $state = self::activationState($conn, $orderId);
                    if (!$state || empty($state['account_activation_sent_at'])) {
                        throw new RuntimeException('Account activation email delivery failed.');
                    }
                }
            });
            return;
        }

        throw new RuntimeException('Unsupported outbox topic: ' . $topic);
    }

    private static function deliverHook(mysqli $conn, int $eventId, string $hook, array $context): void
    {
        $priorities = $GLOBALS['amber_hooks']['actions'][$hook] ?? [];
        if (!is_array($priorities)) {
            return;
        }
        ksort($priorities);
        foreach ($priorities as $priority => $callbacks) {
            foreach ((array) $callbacks as $callback) {
                $callbackName = cron_callback_name($callback);
                $handlerKey = 'hook:' . $hook . ':' . (int) $priority . ':' . $callbackName;
                self::deliverHandler($conn, $eventId, $handlerKey, static function () use ($callback, $context): void {
                    $result = $callback($context);
                    if ($result === false) {
                        throw new RuntimeException('Integration handler returned failure.');
                    }
                    if (is_array($result) && CronService::normalizeStatus((string) ($result['status'] ?? 'success')) === 'failed') {
                        throw new RuntimeException((string) ($result['error'] ?? 'Integration handler failed.'));
                    }
                });
            }
        }
    }

    private static function deliverHandler(mysqli $conn, int $eventId, string $handlerKey, callable $handler): void
    {
        $claimToken = bin2hex(random_bytes(16));
        $seed = $conn->prepare(
            "INSERT IGNORE INTO commerce_outbox_deliveries
                (outbox_id, handler_key, status, attempts, claim_token, claimed_at)
             VALUES (?, ?, 'processing', 1, ?, NOW())"
        );
        $seed->bind_param('iss', $eventId, $handlerKey, $claimToken);
        $seed->execute();
        $claimed = $seed->affected_rows === 1;

        if (!$claimed) {
            $stateStmt = $conn->prepare(
                "SELECT status FROM commerce_outbox_deliveries WHERE outbox_id = ? AND handler_key = ? LIMIT 1"
            );
            $stateStmt->bind_param('is', $eventId, $handlerKey);
            $stateStmt->execute();
            $state = $stateStmt->get_result()->fetch_assoc();
            if (($state['status'] ?? '') === 'completed') {
                return;
            }
            $reclaim = $conn->prepare(
                "UPDATE commerce_outbox_deliveries
                 SET status = 'processing', attempts = attempts + 1, claim_token = ?, claimed_at = NOW(), last_error = NULL
                 WHERE outbox_id = ? AND handler_key = ?
                   AND (status = 'failed' OR (status = 'processing' AND claimed_at < DATE_SUB(NOW(), INTERVAL " . self::STALE_CLAIM_MINUTES . " MINUTE)))"
            );
            $reclaim->bind_param('sis', $claimToken, $eventId, $handlerKey);
            $reclaim->execute();
            $claimed = $reclaim->affected_rows === 1;
        }
        if (!$claimed) {
            throw new RuntimeException('Outbox handler is already processing.');
        }

        try {
            $handler();
            $complete = $conn->prepare(
                "UPDATE commerce_outbox_deliveries
                 SET status = 'completed', completed_at = NOW(), claim_token = NULL, claimed_at = NULL, last_error = NULL
                 WHERE outbox_id = ? AND handler_key = ? AND claim_token = ?"
            );
            $complete->bind_param('iss', $eventId, $handlerKey, $claimToken);
            $complete->execute();
            if ($complete->affected_rows !== 1) {
                throw new RuntimeException('Outbox handler completion lost its claim.');
            }
        } catch (Throwable $e) {
            $error = CronService::sanitizeError($e->getMessage());
            $fail = $conn->prepare(
                "UPDATE commerce_outbox_deliveries
                 SET status = 'failed', claim_token = NULL, claimed_at = NULL, last_error = ?
                 WHERE outbox_id = ? AND handler_key = ? AND claim_token = ?"
            );
            $fail->bind_param('siss', $error, $eventId, $handlerKey, $claimToken);
            $fail->execute();
            throw $e;
        }
    }

    private static function completeEvent(mysqli $conn, int $eventId, string $claimToken): void
    {
        $stmt = $conn->prepare(
            "UPDATE commerce_outbox
             SET status = 'completed', completed_at = NOW(), claim_token = NULL, claimed_at = NULL,
                 last_error = NULL
             WHERE id = ? AND status = 'processing' AND claim_token = ?"
        );
        $stmt->bind_param('is', $eventId, $claimToken);
        $stmt->execute();
    }

    private static function failEvent(
        mysqli $conn,
        int $eventId,
        string $claimToken,
        int $attempt,
        string $message
    ): void {
        $delay = self::RETRY_DELAYS_SECONDS[min(max(1, $attempt), count(self::RETRY_DELAYS_SECONDS)) - 1];
        $error = CronService::sanitizeError($message);
        $stmt = $conn->prepare(
            "UPDATE commerce_outbox
             SET status = 'failed', available_at = DATE_ADD(NOW(), INTERVAL ? SECOND),
                 claim_token = NULL, claimed_at = NULL, last_error = ?
             WHERE id = ? AND status = 'processing' AND claim_token = ?"
        );
        $stmt->bind_param('isis', $delay, $error, $eventId, $claimToken);
        $stmt->execute();
    }

    private static function activationState(mysqli $conn, int $orderId): ?array
    {
        $stmt = $conn->prepare(
            "SELECT account_activation_requested, account_activation_sent_at
             FROM orders WHERE id = ? LIMIT 1"
        );
        $stmt->bind_param('i', $orderId);
        $stmt->execute();
        return $stmt->get_result()->fetch_assoc() ?: null;
    }
}
