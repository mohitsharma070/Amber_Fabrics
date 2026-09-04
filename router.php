<?php
/**
 * Router for PHP's built-in development server.
 *
 * Start locally with: php -S localhost:8000 router.php
 * Apache production hosting continues to use .htaccess instead.
 */

$requestPath = (string) (parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH) ?? '/');

// Decode repeatedly until stable to defeat multiple-encoding bypasses
$prevPath = '';
while ($requestPath !== $prevPath) {
    $prevPath = $requestPath;
    $requestPath = rawurldecode($requestPath);
}

// Convert backslashes to forward slashes for unified traversal checks
$requestPath = str_replace('\\', '/', $requestPath);

// Reject NUL bytes
if (strpos($requestPath, "\0") !== false) {
    http_response_code(400);
    exit('Bad Request');
}

// Reject any directory traversal segments
$segments = explode('/', $requestPath);
foreach ($segments as $seg) {
    // A segment of '.' or '..' indicates traversal
    if ($seg === '..' || $seg === '.') {
        http_response_code(403);
        header('Content-Type: text/plain; charset=UTF-8');
        exit('Forbidden');
    }
}

$route = trim($requestPath, '/');

$publicRoutes = [
    'about', 'catalog', 'cart', 'checkout', 'contact', 'fabric', 'faq',
    'international-buyers', 'international-orders-policy', 'order-success',
    'privacy-policy', 'return-policy', 'shipping-policy', 'size-guide',
    'terms', 'thank-you', 'delivery-estimate',
];

$customerRoutes = [
    'forgot-password', 'login', 'order-view', 'orders', 'profile',
    'register', 'reset-password', 'support-tickets', 'verify-email',
];
$guestRoutes = ['order-access', 'order-auth', 'order', 'account-activate', 'support'];

// Mirror the private-directory protection from .htaccess.
$firstSegment = strtolower((string) (explode('/', $route, 2)[0] ?? ''));
$blockedSegments = ['.git', 'config', 'database', 'docs', 'includes', 'plugins', 'scripts', 'tests', 'tmp', 'tmp_sessions', 'vendor'];
$basename = basename($requestPath);
$normalizedBasename = rtrim($basename, ". ");
$isFileBlocked = preg_match('/^(\.env(\..*)?|secure-config(\..*)?\.php|app-config(\..*)?\.php|composer\.(json|lock|phar)|phpunit\.xml|\.git.*|\.htaccess.*|README(\.md)?|AGENTS\.md|CLAUDE\.md|openapi\.yaml|CHANGELOG\.md|GO_LIVE_CHECKLIST\.md|SECURITY\.md|query)$/i', $normalizedBasename);

if ($isFileBlocked || in_array($firstSegment, $blockedSegments, true)) {
    http_response_code(403);
    header('Content-Type: text/plain; charset=UTF-8');
    exit('Forbidden');
}

// Canonicalize browser-facing PHP filenames before the built-in server serves
// the real file. Apache performs the equivalent redirects through .htaccess.
$method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
$isReadRequest = in_array($method, ['GET', 'HEAD'], true);
$canonicalPath = '';
if ($isReadRequest && $route === 'index.php') {
    $canonicalPath = '/';
} elseif ($isReadRequest && preg_match('#^([A-Za-z0-9-]+)\.php$#', $route, $publicMatch)
    && in_array($publicMatch[1], $publicRoutes, true)) {
    $canonicalPath = '/' . $publicMatch[1];
} elseif ($isReadRequest && preg_match('#^customer/([A-Za-z0-9-]+)\.php$#', $route, $customerMatch)
    && in_array($customerMatch[1], $customerRoutes, true)) {
    $canonicalPath = '/customer/' . $customerMatch[1];
} elseif ($isReadRequest && preg_match('#^admin/([A-Za-z0-9-]+)\.php$#', $route, $adminPhpMatch)) {
    $canonicalPath = $adminPhpMatch[1] === 'index' ? '/admin/' : '/admin/' . $adminPhpMatch[1];
}

if ($canonicalPath !== '') {
    $query = trim((string) ($_SERVER['QUERY_STRING'] ?? ''));
    if ($query !== '') {
        $canonicalPath .= '?' . $query;
    }
    http_response_code(301);
    header('Location: ' . $canonicalPath);
    exit;
}

// Let the built-in server handle real PHP handlers and static assets.
$requestedFile = __DIR__ . str_replace('/', DIRECTORY_SEPARATOR, $requestPath);
if ($requestPath !== '/' && is_file($requestedFile)) {
    $realRequestedFile = realpath($requestedFile);
    $realBase = realpath(__DIR__);
    if ($realRequestedFile === false || strpos($realRequestedFile, $realBase) !== 0) {
        http_response_code(403);
        exit('Forbidden');
    }
    return false;
}

$relativeFile = '';
if ($route === '') {
    $relativeFile = 'index.php';
} elseif ($route === 'admin') {
    $relativeFile = 'admin/index.php';
} elseif ($route === 'sitemap.xml') {
    $relativeFile = 'sitemap.php';
} elseif (in_array($route, $publicRoutes, true)) {
    $relativeFile = $route . '.php';
} elseif (preg_match('#^fabric/([^/]+)$#', $route, $fabricSlugMatch)) {
    $_GET['slug'] = rawurldecode($fabricSlugMatch[1]);
    $relativeFile = 'fabric.php';
} elseif (preg_match('#^admin/([A-Za-z0-9-]+)$#', $route, $adminMatch)) {
    $adminFile = 'admin/' . $adminMatch[1] . '.php';
    if (is_file(__DIR__ . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $adminFile))) {
        $relativeFile = $adminFile;
    }
} elseif (strpos($route, 'customer/') === 0) {
    $customerRoute = substr($route, strlen('customer/'));
    if (in_array($customerRoute, $customerRoutes, true)) {
        $relativeFile = 'customer/' . $customerRoute . '.php';
    }
} elseif (strpos($route, 'guest/') === 0) {
    $guestRoute = substr($route, strlen('guest/'));
    if (in_array($guestRoute, $guestRoutes, true)) { $relativeFile = 'guest/' . $guestRoute . '.php'; }
}

if ($relativeFile === '') {
    // Preserve the application's existing front-controller fallback.
    $_GET['url'] = $route;
    $relativeFile = 'index.php';
}

// Require at top-level scope so globals such as the database connection have
// the same behavior as direct Apache/PHP execution in production.
$scriptFile = __DIR__ . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relativeFile);
$_SERVER['SCRIPT_NAME'] = '/' . str_replace('\\', '/', $relativeFile);
$_SERVER['PHP_SELF'] = $_SERVER['SCRIPT_NAME'];
$_SERVER['SCRIPT_FILENAME'] = $scriptFile;
require $scriptFile;
return true;
