<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$path = (string) (parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH) ?: '/');
if (in_array($path, ['/checkout', '/checkout.php'], true)) {
    require_once $root . '/includes/init.php';
    add_filter('shipping.quote', static function (): array {
        throw new RuntimeException('E2E courier timeout containing provider-only detail');
    }, 1);
    require $root . '/checkout.php';
    return true;
}

return require $root . '/router.php';
