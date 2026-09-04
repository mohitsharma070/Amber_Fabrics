<?php

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only.\n");
    exit(1);
}
if ((string) getenv('AMBER_RUN_DISPOSABLE_MYSQL_TESTS') !== '1') {
    echo "Courier webhook MySQL integration test skipped; set AMBER_RUN_DISPOSABLE_MYSQL_TESTS=1 for an authorized disposable database.\n";
    exit(0);
}

$root = dirname(__DIR__);
require $root . '/config/db.php';
require_once $root . '/plugins/shipping-courier/modules/webhook-handling.php';

$failures = [];
$assert = static function (bool $condition, string $message) use (&$failures): void {
    if (!$condition) {
        $failures[] = $message;
    }
};

$provider = 'integration-test';
$eventId = 'courier-lease-' . bin2hex(random_bytes(8));
$signature = 'test-signature';
$payload = json_encode(['event_id' => $eventId, 'status' => 'in_transit'], JSON_UNESCAPED_SLASHES);
if (!is_string($payload)) {
    throw new RuntimeException('Unable to encode disposable webhook payload.');
}

try {
    $first = shipping_courier_webhook_begin_processing($conn, $provider, $eventId, $signature, $payload);
    $assert(
        ($first['state'] ?? '') === 'claimed' && (int) ($first['attempts'] ?? 0) === 1,
        'A new courier event must receive processing lease attempt 1.'
    );

    $activeDuplicate = shipping_courier_webhook_begin_processing($conn, $provider, $eventId, $signature, $payload);
    $assert(
        ($activeDuplicate['state'] ?? '') === 'in_progress' && (int) ($activeDuplicate['attempts'] ?? 0) === 1,
        'An active duplicate must not steal the current courier processing lease.'
    );

    shipping_courier_webhook_mark_failed($conn, $provider, $eventId, 'disposable retry', $signature, 1);
    $second = shipping_courier_webhook_begin_processing($conn, $provider, $eventId, $signature, $payload);
    $assert(
        ($second['state'] ?? '') === 'claimed' && (int) ($second['attempts'] ?? 0) === 2,
        'A failed courier event must be reclaimed with a new attempt number.'
    );

    $staleCompletionRejected = false;
    try {
        shipping_courier_webhook_mark_processed($conn, $provider, $eventId, $signature, $payload, 1);
    } catch (RuntimeException $e) {
        $staleCompletionRejected = true;
    }
    $assert($staleCompletionRejected, 'A stale worker must not complete a newer courier lease.');

    $rowStmt = $conn->prepare(
        "SELECT status, attempts
         FROM shipping_courier_webhook_events
         WHERE provider = ? AND event_id = ?"
    );
    $rowStmt->bind_param('ss', $provider, $eventId);
    $rowStmt->execute();
    $row = $rowStmt->get_result()->fetch_assoc() ?: [];
    $assert(
        ($row['status'] ?? '') === 'processing' && (int) ($row['attempts'] ?? 0) === 2,
        'A rejected stale completion must leave the current lease processing.'
    );

    shipping_courier_webhook_mark_processed($conn, $provider, $eventId, $signature, $payload, 2);
    $processedDuplicate = shipping_courier_webhook_begin_processing($conn, $provider, $eventId, $signature, $payload);
    $assert(
        ($processedDuplicate['state'] ?? '') === 'already_processed' && (int) ($processedDuplicate['attempts'] ?? 0) === 2,
        'A processed duplicate must be acknowledged without starting another attempt.'
    );
} finally {
    $cleanup = $conn->prepare(
        "DELETE FROM shipping_courier_webhook_events
         WHERE provider = ? AND event_id = ?"
    );
    $cleanup->bind_param('ss', $provider, $eventId);
    $cleanup->execute();
}

if ($failures !== []) {
    fwrite(STDERR, implode(PHP_EOL, $failures) . PHP_EOL);
    exit(1);
}

echo "Courier webhook MySQL integration test passed.\n";
