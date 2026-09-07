<?php
declare(strict_types=1);

$GLOBALS['_app_config'] = [
    'APP_ENV' => 'local',
    'APP_URL' => 'http://localhost:8000',
    'APP_FORCE_HTTPS' => '0',
    'APP_TRUSTED_PROXY_IPS' => '',
];
$_SERVER = [];
require_once __DIR__ . '/../includes/helpers/core.php';

$failures = [];
$assert = static function (bool $condition, string $message) use (&$failures): void {
    if (!$condition) {
        $failures[] = $message;
    }
};
$detect = static function (array $server, array $config = []): bool {
    $_SERVER = $server;
    $GLOBALS['_app_config'] = array_merge([
        'APP_ENV' => 'local',
        'APP_URL' => 'http://localhost:8000',
        'APP_FORCE_HTTPS' => '0',
        'APP_TRUSTED_PROXY_IPS' => '',
    ], $config);
    return app_request_is_https();
};

$assert($detect(['HTTPS' => 'on', 'REMOTE_ADDR' => '198.51.100.10']), 'Direct native HTTPS must be detected.');
$assert(!$detect(['REMOTE_ADDR' => '127.0.0.1']), 'Direct local HTTP must remain HTTP.');
$assert($detect(
    ['REMOTE_ADDR' => '10.0.0.5', 'HTTP_X_FORWARDED_PROTO' => 'https'],
    ['APP_TRUSTED_PROXY_IPS' => '10.0.0.5, 10.0.0.6']
), 'A configured trusted proxy must be able to communicate HTTPS.');
$assert(!$detect(
    ['REMOTE_ADDR' => '10.0.0.5', 'HTTP_X_FORWARDED_PROTO' => 'http'],
    ['APP_TRUSTED_PROXY_IPS' => '10.0.0.5, 10.0.0.6']
), 'A configured trusted proxy must be able to communicate HTTP.');
$assert(!$detect(
    ['REMOTE_ADDR' => '10.0.0.5', 'HTTP_X_FORWARDED_PROTO' => 'http', 'HTTP_X_FORWARDED_SSL' => 'on'],
    ['APP_TRUSTED_PROXY_IPS' => '10.0.0.5']
), 'An explicit forwarded HTTP value must take precedence over the legacy SSL header.');
$assert($detect(
    ['REMOTE_ADDR' => '10.0.0.6', 'HTTP_X_FORWARDED_SSL' => 'on'],
    ['APP_TRUSTED_PROXY_IPS' => '10.0.0.5, 10.0.0.6']
), 'A trusted proxy may use the established X-Forwarded-SSL header.');
$assert(!$detect([
    'REMOTE_ADDR' => '198.51.100.10',
    'HTTP_X_FORWARDED_PROTO' => 'https',
    'HTTP_X_FORWARDED_SSL' => 'on',
]), 'Forwarded HTTPS headers from an untrusted client must be ignored.');
$assert($detect(
    ['REMOTE_ADDR' => '198.51.100.10'],
    ['APP_FORCE_HTTPS' => '1']
), 'APP_FORCE_HTTPS must continue to force HTTPS.');
$assert($detect(
    ['REMOTE_ADDR' => '198.51.100.10'],
    ['APP_ENV' => 'production', 'APP_URL' => 'https://shop.example.test']
), 'A production HTTPS APP_URL must continue to force HTTPS semantics.');

$untrustedSecure = $detect([
    'REMOTE_ADDR' => '198.51.100.10',
    'HTTP_X_FORWARDED_PROTO' => 'https',
]);
session_set_cookie_params(['secure' => $untrustedSecure]);
$assert(session_get_cookie_params()['secure'] === false, 'Forged headers must not enable the session Secure flag.');

$trustedSecure = $detect(
    ['REMOTE_ADDR' => '10.0.0.5', 'HTTP_X_FORWARDED_PROTO' => 'https'],
    ['APP_TRUSTED_PROXY_IPS' => '10.0.0.5']
);
session_set_cookie_params(['secure' => $trustedSecure]);
$assert(session_get_cookie_params()['secure'] === true, 'Trusted forwarded HTTPS must enable the session Secure flag.');

if ($failures !== []) {
    foreach ($failures as $failure) {
        fwrite(STDERR, "FAIL: {$failure}" . PHP_EOL);
    }
    exit(1);
}

echo "trusted_proxy_https_test: OK" . PHP_EOL;
