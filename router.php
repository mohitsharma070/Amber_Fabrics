<?php
/**
 * Router for PHP's built-in development server.
 *
 * Start locally with: php -S localhost:8000 router.php
 * Apache production hosting continues to use .htaccess instead.
 */

$requestPath = (string) (parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH) ?? '/');
$requestPath = rawurldecode($requestPath);
$route = trim($requestPath, '/');

// Mirror the private-directory protection from .htaccess. This is especially
// important for tmp/local-mail.log because local messages can contain OTPs.
$firstSegment = strtolower((string) (explode('/', $route, 2)[0] ?? ''));
$blockedSegments = ['.git', 'config', 'database', 'includes', 'plugins', 'scripts', 'tmp', 'tmp_sessions', 'vendor'];
if (in_array($firstSegment, $blockedSegments, true)) {
    http_response_code(403);
    header('Content-Type: text/plain; charset=UTF-8');
    exit('Forbidden');
}

// Let the built-in server handle real PHP handlers and static assets.
$requestedFile = __DIR__ . str_replace('/', DIRECTORY_SEPARATOR, $requestPath);
if ($requestPath !== '/' && is_file($requestedFile)) {
    return false;
}

$publicRoutes = [
    'about', 'catalog', 'cart', 'checkout', 'contact', 'fabric', 'faq',
    'international-buyers', 'international-orders-policy', 'order-success',
    'privacy-policy', 'return-policy', 'shipping-policy', 'size-guide',
    'terms', 'thank-you',
];

$customerRoutes = [
    'forgot-password', 'login', 'order-view', 'orders', 'profile',
    'register', 'reset-password', 'support-tickets', 'verify-email',
];

$relativeFile = '';
if ($route === '') {
    $relativeFile = 'index.php';
} elseif ($route === 'sitemap.xml') {
    $relativeFile = 'sitemap.php';
} elseif (in_array($route, $publicRoutes, true)) {
    $relativeFile = $route . '.php';
} elseif (strpos($route, 'customer/') === 0) {
    $customerRoute = substr($route, strlen('customer/'));
    if (in_array($customerRoute, $customerRoutes, true)) {
        $relativeFile = 'customer/' . $customerRoute . '.php';
    }
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
