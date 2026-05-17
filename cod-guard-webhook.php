<?php
require_once __DIR__ . '/includes/init.php';

if (!function_exists('cod_guard_handle_webhook_payload')) {
    http_response_code(404);
    echo 'COD Guard is not enabled';
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $mode = trim((string) ($_GET['hub_mode'] ?? $_GET['hub.mode'] ?? ''));
    $token = trim((string) ($_GET['hub_verify_token'] ?? $_GET['hub.verify_token'] ?? ''));
    $challenge = (string) ($_GET['hub_challenge'] ?? $_GET['hub.challenge'] ?? '');
    $verifyToken = cod_guard_webhook_verify_token();

    if ($mode === 'subscribe' && $verifyToken !== '' && hash_equals($verifyToken, $token)) {
        header('Content-Type: text/plain');
        echo $challenge;
        exit;
    }

    http_response_code(403);
    echo 'Forbidden';
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo 'Method Not Allowed';
    exit;
}

$payload = file_get_contents('php://input');
if ($payload === false || $payload === '') {
    http_response_code(400);
    echo 'Empty payload';
    exit;
}

if (!cod_guard_validate_webhook_request($payload)) {
    http_response_code(403);
    echo 'Invalid signature';
    exit;
}

$decoded = json_decode($payload, true);
if (!is_array($decoded)) {
    http_response_code(400);
    echo 'Invalid payload';
    exit;
}

try {
    $result = cod_guard_handle_webhook_payload($conn, $decoded);
    header('Content-Type: application/json');
    echo json_encode(['ok' => true, 'result' => $result], JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    error_log('[cod-guard] webhook failed: ' . $e->getMessage());
    http_response_code(500);
    echo 'Error';
}
