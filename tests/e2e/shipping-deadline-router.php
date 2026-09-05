<?php
declare(strict_types=1);

require_once __DIR__ . '/fixture-policy.php';
$errors = e2e_fixture_policy_errors((string) getenv('APP_MODE'), (string) getenv('E2E_FIXTURE_CONFIRM'),
    (string) getenv('APP_ENV'), (string) getenv('DB_NAME'));
$stub = (string) getenv('E2E_BIGSHIP_STUB_URL');
if (PHP_SAPI !== 'cli-server' || $errors !== [] || !preg_match('~^http://127\.0\.0\.1:[0-9]+$~D', $stub)) {
    http_response_code(403);
    exit('Guarded local shipping fixture only.');
}
$root = dirname(__DIR__, 2);
$path = (string) parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
if (in_array($path, ['/shipping-rate.php', '/__e2e/change-cart'], true)) {
    require_once $root . '/includes/init.php';
    $database = (string) $conn->query('SELECT DATABASE()')->fetch_row()[0];
    if (e2e_fixture_policy_errors((string) $GLOBALS['_app_mode'], (string) getenv('E2E_FIXTURE_CONFIRM'),
        (string) $GLOBALS['_app_config']['APP_ENV'], $database) !== []) {
        http_response_code(403);
        exit;
    }
    if ($path === '/__e2e/change-cart') {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !verify_csrf()) api_json(['ok' => false], 403);
        foreach ($_SESSION['cart'] as &$quantity) $quantity = 100000;
        unset($quantity);
        api_json(['ok' => true]);
    }
    $scenarios = ['302001' => 'success', '302002' => 'shared-timeout', '302003' => 'error',
        '302004' => '429', '302005' => '503', '302006' => 'no-rate'];
    $scenario = $scenarios[$_POST['pincode'] ?? ''] ?? 'success';
    // Only quote requests enable the real plugin/HTTP client, pinned to our stub.
    // Order creation and all other requests retain disabled courier operations.
    $GLOBALS['amber_plugin_settings']['shipping-courier'] = array_replace(
        $GLOBALS['amber_plugin_settings']['shipping-courier'], [
            'enabled' => ($_POST['pincode'] ?? '') === '302009' ? 0 : 1,
            'provider' => 'bigship', 'api_base_url' => $stub . '/' . $scenario,
            'bigship_username' => 'loopback', 'bigship_password' => 'loopback', 'bigship_access_key' => 'loopback',
            'bigship_warehouse_id' => '1', 'bigship_warehouse_pincode' => '302001',
            'bigship_parcel_weight_kg' => 0.5, 'bigship_parcel_length_cm' => 20,
            'bigship_parcel_width_cm' => 15, 'bigship_parcel_height_cm' => 5,
        ]);
    require $root . '/shipping-rate.php';
    return true;
}
return require $root . '/router.php';
