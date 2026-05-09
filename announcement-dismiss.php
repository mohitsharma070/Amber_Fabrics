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
$announceKey = trim((string) ($_REQUEST['key'] ?? ''));

if ($announceKey === '' || !preg_match('/^[a-f0-9]{32}$/', $announceKey)) {
    http_response_code(422);
    echo json_encode(['success' => false, 'message' => 'Invalid announcement key.']);
    exit;
}

try {
    if ($method === 'GET') {
        $dismissed = announcement_is_dismissed($conn, $announceKey);
        echo json_encode(['success' => true, 'dismissed' => $dismissed]);
        exit;
    }

    if ($method === 'POST') {
        if (!verify_csrf()) {
            http_response_code(403);
            echo json_encode(['success' => false, 'message' => 'Invalid session token.']);
            exit;
        }
        $ok = announcement_mark_dismissed($conn, $announceKey);
        echo json_encode(['success' => $ok]);
        exit;
    }

    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed.']);
} catch (Throwable $e) {
    error_log('[amberfabrics] announcement-dismiss failed: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Server error.']);
}
