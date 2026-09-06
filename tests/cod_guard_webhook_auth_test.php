<?php
declare(strict_types=1);

function add_action(...$args): void {}
function add_filter(...$args): void {}
function add_cron_action(...$args): void {}
function plugin_setting(string $plugin, string $key, $default = null)
{
    return $GLOBALS['test_plugin_settings'][$key] ?? $default;
}
function _cfg(string $key, $default = null)
{
    return $GLOBALS['test_plugin_settings'][$key] ?? $default;
}

// Mock other needed functions and classes
class SiteContext {
    public static function name(): string { return 'Amber Fabrics'; }
}

$pluginPath = getenv('COD_GUARD_PLUGIN_PATH');
require_once is_string($pluginPath) && $pluginPath !== ''
    ? $pluginPath
    : __DIR__ . '/../plugins/cod-guard/plugin.php';

$failures = [];
$assert = static function (bool $condition, string $message) use (&$failures): void {
    if (!$condition) {
        $failures[] = $message;
    }
};

$payload = '{"id":"test1"}';

// Test 1: missing secret and signature fails
$GLOBALS['test_plugin_settings'] = [];
$_SERVER = [];
$_GET = [];
$assert(cod_guard_validate_webhook_request($payload) === false, 'Missing secret/signature must fail.');

// Test 2: valid signature succeeds
$secret = 'test-secret';
$GLOBALS['test_plugin_settings'] = ['whatsapp_app_secret' => $secret];
$_SERVER['HTTP_X_HUB_SIGNATURE_256'] = 'sha256=' . hash_hmac('sha256', $payload, $secret);
$assert(cod_guard_validate_webhook_request($payload) === true, 'Valid signature must succeed.');

// Test 3: tampered body fails
$tamperedPayload = '{"id":"test2"}';
$assert(cod_guard_validate_webhook_request($tamperedPayload) === false, 'Tampered body must fail.');

// Test 4: bad signature fails
$_SERVER['HTTP_X_HUB_SIGNATURE_256'] = 'sha256=badsig';
$assert(cod_guard_validate_webhook_request($payload) === false, 'Bad signature must fail.');

// Test 5: missing signature fails
unset($_SERVER['HTTP_X_HUB_SIGNATURE_256']);
$assert(cod_guard_validate_webhook_request($payload) === false, 'Missing signature must fail.');

// Test 6: missing app secret fails even when a signature is supplied
$GLOBALS['test_plugin_settings'] = [];
$_SERVER['HTTP_X_HUB_SIGNATURE_256'] = 'sha256=' . hash_hmac('sha256', $payload, $secret);
$assert(cod_guard_validate_webhook_request($payload) === false, 'Missing app secret must fail.');

// Test 7: the obsolete query bearer token cannot authenticate POST
$GLOBALS['test_plugin_settings'] = ['webhook_auth_token' => 'test-token'];
unset($_SERVER['HTTP_X_HUB_SIGNATURE_256']);
$_GET['token'] = 'test-token';
$assert(cod_guard_validate_webhook_request($payload) === false, 'Query token alone must fail.');

// Test 8: the obsolete header bearer token cannot authenticate POST
unset($_GET['token']);
$_SERVER['HTTP_X_COD_GUARD_TOKEN'] = 'test-token';
$assert(cod_guard_validate_webhook_request($payload) === false, 'X-COD-GUARD-TOKEN alone must fail.');

// Tests 9-10: GET provider verification remains token-protected
$GLOBALS['test_plugin_settings'] = ['webhook_verify_token' => 'verify-token'];
$hasGetValidator = function_exists('cod_guard_validate_webhook_verification');
$assert($hasGetValidator, 'GET verification must expose executable validation behavior.');
if ($hasGetValidator) {
    $assert(cod_guard_validate_webhook_verification('subscribe', 'verify-token') === true, 'Valid GET verification must succeed.');
    $assert(cod_guard_validate_webhook_verification('subscribe', 'wrong-token') === false, 'Invalid GET verification token must fail.');
    $assert(cod_guard_validate_webhook_verification('not-subscribe', 'verify-token') === false, 'Invalid GET verification mode must fail.');
}

$endpointSource = (string) file_get_contents(__DIR__ . '/../cod-guard-webhook.php');
$pluginConfigSource = (string) file_get_contents(__DIR__ . '/../config/plugins.php');
$databaseConfigSource = (string) file_get_contents(__DIR__ . '/../config/db.php');
$openApiSource = (string) file_get_contents(__DIR__ . '/../openapi.yaml');
$assert(str_contains($endpointSource, "\$_GET['hub.mode']") && str_contains($endpointSource, "\$_GET['hub.verify_token']") && str_contains($endpointSource, "\$_GET['hub.challenge']"), 'GET provider verification parameters must remain wired.');
$assert(!str_contains($pluginConfigSource, 'COD_GUARD_WEBHOOK_TOKEN') && !str_contains($databaseConfigSource, 'COD_GUARD_WEBHOOK_TOKEN'), 'Obsolete POST bearer configuration must be removed.');
$assert(str_contains($openApiSource, 'codGuardSignatureHeader:') && str_contains($openApiSource, 'name: X-Hub-Signature-256'), 'OpenAPI must document the required COD Guard signature header.');

if ($failures !== []) {
    fwrite(STDERR, "Webhook auth failures:\n- " . implode("\n- ", $failures) . "\n");
    exit(1);
}

echo "cod_guard_webhook_auth_test: OK\n";
