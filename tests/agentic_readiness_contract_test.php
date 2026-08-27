<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$failures = [];

$assert = static function (bool $condition, string $message) use (&$failures): void {
    if (!$condition) {
        $failures[] = $message;
    }
};

$read = static function (string $relative) use ($root, $assert): string {
    $path = $root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);
    $assert(is_file($path), $relative . ' must exist.');
    if (!is_file($path)) {
        return '';
    }
    $contents = file_get_contents($path);
    $assert($contents !== false && trim((string) $contents) !== '', $relative . ' must not be empty.');
    return $contents === false ? '' : $contents;
};

$requiredFiles = [
    'README.md',
    'AGENTS.md',
    'CLAUDE.md',
    'docs/repo-architecture.md',
    'docs/agentic-ready.md',
    'openapi.yaml',
];
foreach ($requiredFiles as $requiredFile) {
    $read($requiredFile);
}

$readme = $read('README.md');
foreach (['composer install', 'composer test', 'database/migrate.php', 'openapi.yaml'] as $expected) {
    $assert(str_contains($readme, $expected), 'README.md must document ' . $expected . '.');
}

$agents = $read('AGENTS.md');
foreach (['business logic', 'composer test', 'CSRF', 'dated migration'] as $expected) {
    $assert(str_contains($agents, $expected), 'AGENTS.md must preserve guidance for ' . $expected . '.');
}

$architecture = $read('docs/repo-architecture.md');
foreach (['PHP 8.2', 'MySQL', 'includes/init.php', 'order access', 'openapi.yaml'] as $expected) {
    $assert(str_contains($architecture, $expected), 'Architecture documentation must cover ' . $expected . '.');
}

$openapi = $read('openapi.yaml');
$canonicalOpenapi = str_replace("\r\n", "\n", $openapi);
$assert(str_starts_with($canonicalOpenapi, "openapi: 3.0.3\n"), 'openapi.yaml must declare OpenAPI 3.0.3.');
$assert(!str_contains($openapi, 'X-CSRF-Token'), 'OpenAPI must not claim unsupported header-based CSRF.');
foreach ([
    '/catalog.php:',
    '/add-to-cart.php:',
    '/shipping-rate.php:',
    '/place-order.php:',
    '/payment/razorpay-webhook.php:',
    '/customer/login.php:',
    '/customer/orders.php:',
    '/guest/order-access.php:',
    '/guest/order.php:',
    '/admin/login.php:',
    '/admin/product-actions.php:',
    '/admin/product-import.php:',
    '/cron/run-plugins.php:',
] as $endpoint) {
    $assert(str_contains($openapi, '  ' . $endpoint), 'OpenAPI must cover ' . $endpoint);
}
foreach ([
    'sessionCookieAuth:',
    'customerSessionAuth:',
    'adminSessionAuth:',
    'guestOrderSessionAuth:',
    'razorpaySignatureHeader:',
    'cronTokenHeader:',
    'csrf_token:',
] as $contract) {
    $assert(str_contains($openapi, $contract), 'OpenAPI must describe ' . $contract);
}

if ($failures !== []) {
    fwrite(STDERR, "Agentic readiness contract failures:\n- " . implode("\n- ", $failures) . "\n");
    exit(1);
}

echo "Agentic readiness contract passed.\n";
