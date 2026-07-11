<?php
require_once __DIR__ . '/includes/init.php';

$isAjax = !empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower((string) $_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';
if (!$isAjax) {
    api_json(['success' => false, 'message' => 'Invalid request.'], 400);
}

$method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
$announceKey = '';
if ($method === 'GET') {
    $announceKey = trim((string) ($_GET['key'] ?? ''));
} elseif ($method === 'POST') {
    $announceKey = trim((string) ($_POST['key'] ?? ''));
}

if ($announceKey === '' || !preg_match('/^[a-f0-9]{32}$/', $announceKey)) {
    api_json(['success' => false, 'message' => 'Invalid announcement key.'], 422);
}

try {
    if ($method === 'GET') {
        $dismissed = announcement_is_dismissed($conn, $announceKey);
        api_json(['success' => true, 'dismissed' => $dismissed]);
    }

    if ($method === 'POST') {
        if (!verify_csrf()) {
            api_json(['success' => false, 'message' => 'Invalid session token.'], 403);
        }
        $ok = announcement_mark_dismissed($conn, $announceKey);
        api_json(['success' => $ok]);
    }

    api_json(['success' => false, 'message' => 'Method not allowed.'], 405);
} catch (Throwable $e) {
    error_log('[app] announcement-dismiss failed: ' . $e->getMessage());
    api_json(['success' => false, 'message' => 'Server error.'], 500);
}
