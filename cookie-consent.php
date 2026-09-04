<?php
require_once __DIR__ . '/includes/init.php';

$isAjax = !empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower((string) $_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';
$method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
if (!$isAjax && $method !== 'POST') {
    api_json(['success' => false, 'message' => 'Invalid request.'], 400);
}
if ($method !== 'POST') {
    api_json(['success' => false, 'message' => 'Method not allowed.'], 405);
}

if (!verify_csrf()) {
    api_json(['success' => false, 'message' => 'Invalid session token.'], 403);
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
    api_json(['success' => false, 'message' => 'Invalid consent choice.'], 422);
}

$ok = marketing_consent_set($status);
if (!$ok) {
    api_json(['success' => false, 'message' => 'Unable to save consent.'], 500);
}

if ($isAjax) {
    api_json([
        'success' => true,
        'status' => $status,
    ]);
}

function cookie_consent_safe_return_path(string $candidate): string
{
    $candidate = trim($candidate);
    if ($candidate === ''
        || !str_starts_with($candidate, '/')
        || str_starts_with($candidate, '//')
        || preg_match('/[\\x00-\\x1F\\x7F\\\\]/', $candidate)
    ) {
        return '/';
    }

    $parts = parse_url($candidate);
    if ($parts === false || isset($parts['scheme']) || isset($parts['host']) || isset($parts['user'])) {
        return '/';
    }

    return $candidate;
}

$returnTo = cookie_consent_safe_return_path((string) ($_POST['return_to'] ?? '/'));
header('Location: ' . $returnTo, true, 303);
exit;
