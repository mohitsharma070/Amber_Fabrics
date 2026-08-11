<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../includes/services/BigshipService.php';

$options = getopt('', ['order-id::', 'check-documents']);
$orderId = trim((string) ($options['order-id'] ?? ''));
$checkDocuments = array_key_exists('check-documents', $options);
$mode = strtolower(trim((string) (getenv('APP_MODE') ?: 'production')));
$mode = $mode === 'prod' ? 'production' : $mode;
if (!in_array($mode, ['local', 'production'], true)) {
    fwrite(STDERR, "APP_MODE must be local or production.\n");
    exit(2);
}
$configPath = __DIR__ . '/../config/secure-config.' . $mode . '.php';
if (!is_file($configPath)) {
    fwrite(STDERR, "Secure config file is missing for APP_MODE={$mode}.\n");
    exit(2);
}
$config = require $configPath;
$config = is_array($config[$mode] ?? null) ? $config[$mode] : $config;
$keys = [
    'SHIPPING_COURIER_ENABLED', 'SHIPPING_COURIER_PROVIDER', 'SHIPPING_COURIER_TEST_MODE',
    'BIGSHIP_BASE_URL', 'BIGSHIP_TEST_BASE_URL', 'BIGSHIP_USERNAME', 'BIGSHIP_PASSWORD',
    'BIGSHIP_ACCESS_KEY', 'BIGSHIP_SEGMENT', 'BIGSHIP_WAREHOUSE_SEGMENT', 'BIGSHIP_HTTP_SKIP_TLS_VERIFY',
];
foreach ($keys as $key) {
    $envValue = getenv($key);
    if ($envValue !== false) {
        $config[$key] = (string) $envValue;
    }
}

$provider = strtolower(trim((string) ($config['SHIPPING_COURIER_PROVIDER'] ?? '')));
if ((int) ($config['SHIPPING_COURIER_ENABLED'] ?? 0) !== 1 || !in_array($provider, ['bigship', 'big_ship', 'big-ship'], true)) {
    fwrite(STDERR, "Bigship is not the enabled shipping provider.\n");
    exit(2);
}

$testMode = (int) ($config['SHIPPING_COURIER_TEST_MODE'] ?? 0) === 1;
$testBaseUrl = trim((string) ($config['BIGSHIP_TEST_BASE_URL'] ?? ''));
$baseUrl = $testMode && $testBaseUrl !== ''
    ? $testBaseUrl
    : trim((string) ($config['BIGSHIP_BASE_URL'] ?? ''));
$required = [
    'BIGSHIP_BASE_URL' => $baseUrl,
    'BIGSHIP_USERNAME' => trim((string) ($config['BIGSHIP_USERNAME'] ?? '')),
    'BIGSHIP_PASSWORD' => trim((string) ($config['BIGSHIP_PASSWORD'] ?? '')),
    'BIGSHIP_ACCESS_KEY' => trim((string) ($config['BIGSHIP_ACCESS_KEY'] ?? '')),
];
$missing = array_keys(array_filter($required, static function (string $value): bool {
    $lower = strtolower($value);
    return $value === '' || str_contains($lower, 'replace-with') || str_contains($lower, 'your-') || str_contains($lower, 'your_');
}));
if ($missing !== []) {
    fwrite(STDERR, 'Missing Bigship settings: ' . implode(', ', $missing) . PHP_EOL);
    exit(2);
}

$GLOBALS['_app_mode'] = $mode;
$client = new BigshipService([
    'api_base_url' => $baseUrl,
    'bigship_username' => $required['BIGSHIP_USERNAME'],
    'bigship_password' => $required['BIGSHIP_PASSWORD'],
    'bigship_access_key' => $required['BIGSHIP_ACCESS_KEY'],
    'bigship_segment' => trim((string) ($config['BIGSHIP_SEGMENT'] ?? 'domestic_b2c')),
    'bigship_warehouse_segment' => trim((string) ($config['BIGSHIP_WAREHOUSE_SEGMENT'] ?? '')),
    'bigship_http_skip_tls_verify' => (int) ($config['BIGSHIP_HTTP_SKIP_TLS_VERIFY'] ?? 0),
]);
$failed = false;
$check = static function (string $name, array $response) use (&$failed): void {
    $ok = !empty($response['ok']);
    $status = (int) ($response['status'] ?? 0);
    $body = is_array($response['body'] ?? null) ? $response['body'] : [];
    $detail = '';
    foreach (['message', 'error', 'detail', 'description'] as $key) {
        if (isset($body[$key]) && is_scalar($body[$key])) {
            $detail = trim((string) $body[$key]);
            if ($detail !== '') {
                break;
            }
        }
    }
    printf("%-30s %s (HTTP %d)%s\n", $name, $ok ? 'OK' : 'FAILED', $status, $detail !== '' ? ': ' . substr($detail, 0, 200) : '');
    $failed = $failed || !$ok;
};

// These calls authenticate and read provider data only; they do not create,
// place, cancel, or mutate a shipment.
$check('Authentication / profile', $client->profile());
$check('Warehouse connectivity', $client->warehouses());

if ($orderId !== '') {
    $check('Track Order (GET query)', $client->trackOrder($orderId));
    $check('Order Detail (GET query)', $client->shipmentDetails($orderId));
    if ($checkDocuments) {
        $check('Documents (GET query)', $client->downloadDocuments($orderId, 'label'));
    }
} elseif ($checkDocuments) {
    fwrite(STDERR, "--check-documents requires --order-id=<CustomGlobalOrderId>.\n");
    exit(2);
}

exit($failed ? 1 : 0);
