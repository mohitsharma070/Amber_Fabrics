<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli-server') {
    http_response_code(404);
    exit;
}

$host = strtolower((string) ($_SERVER['HTTP_HOST'] ?? ''));
$remote = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
$guarded = (string) getenv('APP_MODE') === 'local'
    && strtolower((string) getenv('APP_ENV')) === 'test'
    && (string) getenv('E2E_FIXTURE_CONFIRM') === '1'
    && in_array($remote, ['127.0.0.1', '::1'], true)
    && preg_match('/^(127\.0\.0\.1|localhost|\[::1\]):8001$/', $host) === 1;
if (!$guarded) {
    http_response_code(403);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'guard_failed']);
    exit;
}

$path = (string) (parse_url((string) ($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH) ?: '');
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST' || $path !== '/v1/orders') {
    http_response_code(404);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'not_found']);
    exit;
}

$expectedAuth = 'Basic ' . base64_encode('rzp_test_e2e:rzp_secret_e2e');
if (!hash_equals($expectedAuth, (string) ($_SERVER['HTTP_AUTHORIZATION'] ?? ''))) {
    http_response_code(401);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'unauthorized']);
    exit;
}

$payload = json_decode((string) file_get_contents('php://input'), true);
if (!is_array($payload) || (int) ($payload['amount'] ?? 0) <= 0 || ($payload['currency'] ?? '') !== 'INR') {
    http_response_code(422);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'invalid_order']);
    exit;
}

$receipt = preg_replace('/[^A-Za-z0-9_-]/', '', (string) ($payload['receipt'] ?? '')) ?: 'receipt';
header('Content-Type: application/json');
echo json_encode([
    'id' => 'order_e2e_' . substr(hash('sha256', $receipt), 0, 16),
    'entity' => 'order',
    'amount' => (int) $payload['amount'],
    'currency' => 'INR',
    'receipt' => $receipt,
    'status' => 'created',
], JSON_THROW_ON_ERROR);
