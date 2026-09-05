<?php
declare(strict_types=1);

// Standalone loopback provider fixture; never load application credentials.
if (PHP_SAPI !== 'cli-server' || !in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1', '::1'], true)) {
    http_response_code(403);
    exit;
}
header('Content-Type: application/json');
$path = (string) parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$scenario = explode('/', trim($path, '/'))[0];
if (str_ends_with($path, '/login')) {
    if ($scenario === 'login-timeout') sleep(12);
    if ($scenario === 'shared-timeout') sleep(4);
    echo json_encode(['access_token' => 'loopback-only-token', 'expires_in' => 600]);
    return;
}
if (in_array($scenario, ['timeout', 'shared-timeout'], true)) sleep(12);
if ($scenario === 'retry-timeout') {
    sleep(4);
    http_response_code(503);
}
if (in_array($scenario, ['error', '429', '503'], true)) {
    http_response_code($scenario === 'error' ? 400 : (int) $scenario);
    echo json_encode(['message' => 'PRIVATE provider detail must not reach the customer']);
    return;
}
if ($scenario === 'no-rate') {
    echo json_encode(['data' => []]);
    return;
}
echo json_encode(['data' => [['courier_partner_id' => 42, 'courier_name' => 'Loopback Courier', 'totalCharge' => 85, 'cod_charge' => 20]]]);
