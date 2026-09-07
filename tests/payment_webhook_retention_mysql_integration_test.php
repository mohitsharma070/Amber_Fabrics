<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit(2);
}
if (getenv('AMBER_RUN_DISPOSABLE_MYSQL_TESTS') !== '1') {
    echo "Payment webhook retention MySQL tests skipped; set AMBER_RUN_DISPOSABLE_MYSQL_TESTS=1.\n";
    exit(0);
}
if (getenv('APP_MODE') !== 'local'
    || !preg_match('/_(test|e2e)$/', (string) getenv('DB_NAME'))
    || !in_array(getenv('DB_HOST'), ['127.0.0.1', 'localhost', '::1'], true)) {
    throw new RuntimeException('An explicitly configured loopback disposable _test/_e2e database is required.');
}

$root = dirname(__DIR__);
require $root . '/config/db.php';
require_once $root . '/includes/services/PaymentService.php';

$failures = [];
$assert = static function (bool $condition, string $message) use (&$failures): void {
    if (!$condition) {
        $failures[] = $message;
    }
};

$provider = 'retention-' . bin2hex(random_bytes(6));
$insert = $conn->prepare(
    'INSERT INTO payment_webhook_events
        (provider, event_id, signature, payload_hash, raw_payload, status, attempts, last_error, processed_at, created_at, updated_at)
     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
);
$add = static function (string $eventId, string $status, int $ageDays, ?string $rawPayload, int $attempts = 1) use ($insert, $provider): void {
    $signature = 'signature-' . $eventId;
    $payloadHash = hash('sha256', (string) $rawPayload . '|' . $eventId);
    $lastError = $status === 'failed' ? 'retryable test failure' : null;
    $timestamp = (new DateTimeImmutable('now'))->modify('-' . $ageDays . ' days')->format('Y-m-d H:i:s');
    $processedAt = $status === 'processed' ? $timestamp : null;
    $insert->bind_param(
        'ssssssissss',
        $provider,
        $eventId,
        $signature,
        $payloadHash,
        $rawPayload,
        $status,
        $attempts,
        $lastError,
        $processedAt,
        $timestamp,
        $timestamp
    );
    $insert->execute();
};
$row = static function (string $eventId) use ($conn, $provider): ?array {
    $stmt = $conn->prepare(
        'SELECT provider, event_id, signature, payload_hash, raw_payload, status, attempts,
                last_error, processed_at, created_at, updated_at
         FROM payment_webhook_events WHERE provider = ? AND event_id = ?'
    );
    $stmt->bind_param('ss', $provider, $eventId);
    $stmt->execute();
    $result = $stmt->get_result()->fetch_assoc();
    return is_array($result) ? $result : null;
};

try {
    $add('recent', 'processed', 1, '{"recent":true}');
    $add('old-a', 'processed', 120, '{"old":"a"}', 2);
    $add('old-b', 'processed', 110, '{"old":"b"}', 3);
    $add('old-c', 'processed', 100, '{"old":"c"}', 4);
    $add('processing', 'processing', 200, '{"processing":true}', 2);
    $add('failed', 'failed', 200, '{"failed":true}', 5);
    $add('received', 'received', 200, '{"received":true}', 0);
    $add('audit-a', 'processed', 500, null, 1);
    $add('audit-b', 'processed', 490, null, 1);

    $metadataBefore = $row('old-a');
    unset($metadataBefore['raw_payload']);

    $first = PaymentService::cleanup_payment_webhook_events($conn, 90, 0, 2);
    $assert(($first['scrubbed_count'] ?? -1) === 2 && ($first['deleted_count'] ?? -1) === 0, 'First cleanup must scrub at most the two oldest eligible payloads.');
    $assert(($first['processed'] ?? -1) === 2, 'The combined cleanup batch must remain bounded.');
    $assert(($row('recent')['raw_payload'] ?? null) === '{"recent":true}', 'Recent processed payloads must be retained.');
    foreach (['processing', 'failed', 'received'] as $eventId) {
        $assert(($row($eventId)['raw_payload'] ?? null) !== null, ucfirst($eventId) . ' retryable payload must be retained.');
    }
    $oldAfter = $row('old-a');
    $assert($oldAfter !== null && $oldAfter['raw_payload'] === null, 'An old processed payload must be scrubbed.');
    unset($oldAfter['raw_payload']);
    $assert($oldAfter === $metadataBefore, 'Payload scrubbing must preserve identity, hash, status, attempts, timestamps, and audit metadata.');

    $second = PaymentService::cleanup_payment_webhook_events($conn, 90, 0, 2);
    $third = PaymentService::cleanup_payment_webhook_events($conn, 90, 0, 2);
    $assert(($second['scrubbed_count'] ?? -1) === 1, 'A second bounded batch must scrub the remaining eligible payload.');
    $assert(($third['processed'] ?? -1) === 0, 'Repeated payload cleanup must be idempotent.');

    $deleteFirst = PaymentService::cleanup_payment_webhook_events($conn, 90, 365, 1);
    $deleteSecond = PaymentService::cleanup_payment_webhook_events($conn, 90, 365, 1);
    $deleteThird = PaymentService::cleanup_payment_webhook_events($conn, 90, 365, 1);
    $assert(($deleteFirst['deleted_count'] ?? -1) === 1 && ($deleteFirst['processed'] ?? -1) === 1, 'Optional audit deletion must use the shared batch bound.');
    $assert(($deleteSecond['deleted_count'] ?? -1) === 1 && ($deleteSecond['processed'] ?? -1) === 1, 'A second audit batch must delete only one row.');
    $assert(($deleteThird['processed'] ?? -1) === 0, 'Repeated audit cleanup must be idempotent.');
    $assert($row('failed') !== null, 'Retryable failed events must never be deleted.');
} finally {
    $delete = $conn->prepare('DELETE FROM payment_webhook_events WHERE provider = ?');
    $delete->bind_param('s', $provider);
    $delete->execute();
}

if ($failures !== []) {
    fwrite(STDERR, "Payment webhook retention MySQL failures:\n- " . implode("\n- ", $failures) . "\n");
    exit(1);
}

echo "Payment webhook retention MySQL tests passed.\n";
