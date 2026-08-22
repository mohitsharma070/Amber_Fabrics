<?php

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../includes/init.php';

$apply = in_array('--apply', $argv, true);
$confirmProduction = in_array('--confirm-production', $argv, true);
$isProduction = strtolower((string) ($GLOBALS['_app_mode'] ?? 'local')) === 'production';

if ($apply && $isProduction && !$confirmProduction) {
    fwrite(STDERR, "Production apply requires --confirm-production after operator authorization.\n");
    exit(2);
}

$result = $conn->query(
    "SELECT cu.id, cu.coupon_id, cu.guest_identity_hash, o.customer_email, o.customer_phone
     FROM coupon_usages cu
     INNER JOIN orders o ON o.id = cu.order_id
     WHERE cu.customer_id IS NULL
     ORDER BY cu.id ASC"
);

$updates = [];
$owners = [];
$collisions = [];
$historicalGuestRows = 0;
while ($row = $result->fetch_assoc()) {
    $historicalGuestRows++;
    $usageId = (int) ($row['id'] ?? 0);
    $couponId = (int) ($row['coupon_id'] ?? 0);
    $existingHash = strtolower(trim((string) ($row['guest_identity_hash'] ?? '')));
    $identityHash = $existingHash !== ''
        ? $existingHash
        : coupon_guest_identity_hash((string) ($row['customer_email'] ?? ''), (string) ($row['customer_phone'] ?? ''));
    $dedupe = $couponId . ':' . $identityHash;
    if (isset($owners[$dedupe]) && $owners[$dedupe] !== $usageId) {
        $collisions[$dedupe] = true;
    } else {
        $owners[$dedupe] = $usageId;
    }
    if ($existingHash === '') {
        $updates[$usageId] = $identityHash;
    }
}

$summary = [
    'mode' => $apply ? 'apply' : 'dry-run',
    'historical_guest_rows' => $historicalGuestRows,
    'rows_to_backfill' => count($updates),
    'identity_collisions' => count($collisions),
];

if ($collisions !== []) {
    $summary['status'] = 'blocked';
    $summary['message'] = 'Resolve duplicate historical guest identities before applying; no rows were changed.';
    echo json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
    exit($apply ? 3 : 0);
}

if ($apply && $updates !== []) {
    $conn->begin_transaction();
    try {
        $stmt = $conn->prepare(
            "UPDATE coupon_usages
             SET guest_identity_hash = ?
             WHERE id = ? AND customer_id IS NULL AND guest_identity_hash IS NULL"
        );
        foreach ($updates as $usageId => $identityHash) {
            $stmt->bind_param('si', $identityHash, $usageId);
            $stmt->execute();
            if ($stmt->affected_rows !== 1) {
                throw new RuntimeException('Backfill row changed concurrently; retry the dry-run.');
            }
        }
        $conn->commit();
    } catch (Throwable $e) {
        $conn->rollback();
        fwrite(STDERR, "Guest coupon backfill failed without partial changes.\n");
        exit(4);
    }
}

$summary['status'] = $apply ? 'applied' : 'ready';
echo json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
