<?php

declare(strict_types=1);

final class WebhookLifecycleService
{
    /**
     * Atomically claim a webhook event without extending an existing processing lease
     * before evaluating whether that lease is stale.
     *
     * @return array{state:string,status:string,attempts:int}
     */
    public static function beginProcessing(
        mysqli $conn,
        string $provider,
        string $eventId,
        string $signature,
        string $payload,
        int $processingTtlSeconds = 120
    ): array {
        if ($provider === '' || $eventId === '') {
            return ['state' => 'in_progress', 'status' => '', 'attempts' => 0];
        }

        $payloadHash = PaymentService::payment_webhook_payload_hash($payload);
        $processingTtlSeconds = max(30, $processingTtlSeconds);

        $conn->begin_transaction();
        try {
            $insert = $conn->prepare(
                "INSERT INTO payment_webhook_events (
                    provider, event_id, signature, payload_hash, raw_payload, status, attempts, last_error, processed_at, created_at, updated_at
                )
                 VALUES (?, ?, ?, ?, ?, 'received', 0, NULL, NULL, NOW(), NOW())
                 ON DUPLICATE KEY UPDATE
                    signature = VALUES(signature),
                    payload_hash = VALUES(payload_hash),
                    raw_payload = VALUES(raw_payload)"
            );
            $insert->bind_param('sssss', $provider, $eventId, $signature, $payloadHash, $payload);
            $insert->execute();

            $select = $conn->prepare(
                "SELECT id, status, attempts, UNIX_TIMESTAMP(updated_at) AS updated_ts
                 FROM payment_webhook_events
                 WHERE provider = ? AND event_id = ?
                 LIMIT 1
                 FOR UPDATE"
            );
            $select->bind_param('ss', $provider, $eventId);
            $select->execute();
            $row = $select->get_result()->fetch_assoc();
            if (!$row) {
                throw new RuntimeException('Webhook lifecycle row missing for provider=' . $provider . ' event=' . $eventId);
            }

            $status = strtolower(trim((string) ($row['status'] ?? 'received')));
            $attempts = (int) ($row['attempts'] ?? 0);
            $updatedTs = (int) ($row['updated_ts'] ?? 0);
            $nowTs = time();
            $isStaleProcessing = $status === 'processing'
                && $updatedTs > 0
                && ($nowTs - $updatedTs) > $processingTtlSeconds;

            if ($status === 'processed') {
                $conn->commit();
                return ['state' => 'already_processed', 'status' => 'processed', 'attempts' => $attempts];
            }
            if ($status === 'processing' && !$isStaleProcessing) {
                $conn->commit();
                return ['state' => 'in_progress', 'status' => 'processing', 'attempts' => $attempts];
            }

            $nextAttempts = $attempts + 1;
            $update = $conn->prepare(
                "UPDATE payment_webhook_events
                 SET status = 'processing',
                     attempts = ?,
                     last_error = NULL,
                     processed_at = NULL,
                     updated_at = NOW()
                 WHERE id = ?"
            );
            $id = (int) $row['id'];
            $update->bind_param('ii', $nextAttempts, $id);
            $update->execute();

            $conn->commit();
            return ['state' => 'claimed', 'status' => 'processing', 'attempts' => $nextAttempts];
        } catch (Throwable $e) {
            try {
                $conn->rollback();
            } catch (Throwable $rollbackException) {
                // Ignore rollback errors and preserve the original failure.
            }
            throw $e;
        }
    }
}
