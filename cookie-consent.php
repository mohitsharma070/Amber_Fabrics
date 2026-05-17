<?php
require_once __DIR__ . '/includes/init.php';

header('Content-Type: application/json');

$isAjax = !empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower((string) $_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';
if (!$isAjax) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid request.']);
    exit;
}

$method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
if ($method !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed.']);
    exit;
}

if (!verify_csrf()) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Invalid session token.']);
    exit;
}

$choiceRaw = strtolower(trim((string) ($_POST['choice'] ?? '')));
$statusMap = [
    'accept' => 'granted',
    'allow' => 'granted',
    'granted' => 'granted',
    'reject' => 'denied',
    'deny' => 'denied',
    'denied' => 'denied',
];
$status = $statusMap[$choiceRaw] ?? '';

if ($status === '') {
    http_response_code(422);
    echo json_encode(['success' => false, 'message' => 'Invalid consent choice.']);
    exit;
}

$ok = marketing_consent_set($status);
if (!$ok) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Unable to save consent.']);
    exit;
}

echo json_encode([
    'success' => true,
    'status' => $status,
]);
