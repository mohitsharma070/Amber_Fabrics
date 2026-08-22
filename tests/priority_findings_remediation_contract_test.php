<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$failures = [];
$assert = static function (bool $condition, string $message) use (&$failures): void {
    if (!$condition) {
        $failures[] = $message;
    }
};
$read = static function (string $path) use ($root, $assert): string {
    $contents = @file_get_contents($root . '/' . $path);
    $assert($contents !== false, $path . ' must exist.');
    return $contents === false ? '' : $contents;
};

$config = $read('config/db.php');
$secretPolicy = $read('config/production-secret-policy.php');
$privateEnvLoader = $read('config/private-env-loader.php');
$example = $read('config/secure-config.production.example.php');
$adminService = $read('includes/services/AdminOtpService.php');
$adminLogin = $read('admin/login.php');
$adminVerify = $read('admin/verify-otp.php');
$coupon = $read('includes/helpers/coupon-functions.php');
$couponService = $read('includes/services/CouponService.php');
$placeOrder = $read('place-order.php');
$guestRetry = $read('guest/retry-payment.php');
$outbox = $read('includes/services/OutboxService.php');
$verify = $read('payment/razorpay-verify.php');
$webhook = $read('payment/razorpay-webhook.php');
$cron = $read('cron/run-plugins.php');
$schema = $read('database/schema.sql');
$setup = $read('database/setup.php');
$migrationPath = 'database/migrations/2026-08-24-priority-findings-remediation.sql';
$migration = $read($migrationPath);
$architecture = $read('docs/repo-architecture.md');

$assert(str_contains($config, "'ADMIN_LOGIN_PASSPHRASE'") && str_contains($config, "'APP_IDENTITY_HASH_KEY'"), 'Production bootstrap must require both new secrets.');
$assert(str_contains($config, 'app_config_production_secret_issues') && str_contains($secretPolicy, "'ADMIN_LOGIN_PASSPHRASE' => 16") && str_contains($secretPolicy, "'APP_IDENTITY_HASH_KEY' => 32"), 'Production secrets must reject short and placeholder values.');
$assert(str_contains($config, 'app_private_env_load') && str_contains($config, 'app_config_env($secretKey)'), 'New production secrets must be sourced from the server environment or protected runtime secret file.');
$assert(str_contains($privateEnvLoader, "'.app-secrets'") && str_contains($privateEnvLoader, '($permissions & 0077)') && str_contains($privateEnvLoader, 'outside the application root'), 'Shared-hosting secret loader must enforce an outside-root path and Unix 0600 permissions.');
$assert(str_contains($config, "['ADMIN_LOGIN_PASSPHRASE', 'APP_IDENTITY_HASH_KEY']"), 'Private secret loading must use an explicit bootstrap allowlist.');
$assert(str_contains($example, 'replace-with-at-least-16') && str_contains($example, 'replace-with-at-least-32'), 'Tracked production example must contain placeholders only.');
$assert(!preg_match('/\b(?:live|prod)_[A-Za-z0-9]{20,}\b/', $example), 'Production example must not contain credential-like values.');
require_once $root . '/config/production-secret-policy.php';
$assert(count(app_config_production_secret_issues([])) === 2, 'Missing production secrets must fail policy.');
$assert(count(app_config_production_secret_issues(['ADMIN_LOGIN_PASSPHRASE' => 'short', 'APP_IDENTITY_HASH_KEY' => 'also-short'])) === 2, 'Short production secrets must fail policy.');
$assert(count(app_config_production_secret_issues(['ADMIN_LOGIN_PASSPHRASE' => 'replace-with-passphrase-value', 'APP_IDENTITY_HASH_KEY' => 'replace-with-identity-hash-key-value'])) === 2, 'Placeholder production secrets must fail policy.');
$assert(app_config_production_secret_issues(['ADMIN_LOGIN_PASSPHRASE' => 'valid-admin-passphrase-value', 'APP_IDENTITY_HASH_KEY' => 'valid-stable-identity-hash-key-value-123']) === [], 'Valid production secrets must pass policy.');

require_once $root . '/config/private-env-loader.php';
$previousSecretsPath = getenv('APP_SECRETS_FILE');
$previousAdminPassphrase = getenv('ADMIN_LOGIN_PASSPHRASE');
$previousIdentityKey = getenv('APP_IDENTITY_HASH_KEY');
$fixtureBase = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'amber-secret-loader-' . bin2hex(random_bytes(6));
$fixtureRoot = $fixtureBase . DIRECTORY_SEPARATOR . 'public_html';
mkdir($fixtureRoot, 0700, true);
$fixtureFile = $fixtureBase . DIRECTORY_SEPARATOR . '.app-secrets';
file_put_contents($fixtureFile, "ADMIN_LOGIN_PASSPHRASE=file-admin-passphrase-value\nAPP_IDENTITY_HASH_KEY=file-identity-hash-key-value-32-characters\nIGNORED_KEY=ignored\n");
if (PHP_OS_FAMILY !== 'Windows') {
    chmod($fixtureFile, 0600);
}
putenv('APP_SECRETS_FILE=' . $fixtureFile);
putenv('ADMIN_LOGIN_PASSPHRASE=existing-environment-passphrase');
putenv('APP_IDENTITY_HASH_KEY');
unset($_SERVER['APP_IDENTITY_HASH_KEY']);
app_private_env_load(['ADMIN_LOGIN_PASSPHRASE', 'APP_IDENTITY_HASH_KEY'], $fixtureRoot);
$assert(getenv('ADMIN_LOGIN_PASSPHRASE') === 'existing-environment-passphrase', 'Existing environment variables must take precedence over the private secret file.');
$assert(getenv('APP_IDENTITY_HASH_KEY') === 'file-identity-hash-key-value-32-characters', 'Missing allowlisted secrets must load from the private file.');
$assert(getenv('IGNORED_KEY') === false, 'Unknown private-file keys must not enter the process environment.');
$insideFile = $fixtureRoot . DIRECTORY_SEPARATOR . '.app-secrets';
file_put_contents($insideFile, "ADMIN_LOGIN_PASSPHRASE=inside-root-passphrase\nAPP_IDENTITY_HASH_KEY=inside-root-identity-key-32-characters\n");
if (PHP_OS_FAMILY !== 'Windows') {
    chmod($insideFile, 0600);
}
putenv('APP_SECRETS_FILE=' . $insideFile);
putenv('ADMIN_LOGIN_PASSPHRASE');
putenv('APP_IDENTITY_HASH_KEY');
unset($_SERVER['ADMIN_LOGIN_PASSPHRASE'], $_SERVER['APP_IDENTITY_HASH_KEY']);
$insideRootRejected = false;
try {
    app_private_env_load(['ADMIN_LOGIN_PASSPHRASE', 'APP_IDENTITY_HASH_KEY'], $fixtureRoot);
} catch (RuntimeException $e) {
    $insideRootRejected = str_contains($e->getMessage(), 'outside the application root');
}
$assert($insideRootRejected, 'Private secret files inside the application root must be rejected.');
unlink($insideFile);
unlink($fixtureFile);
rmdir($fixtureRoot);
rmdir($fixtureBase);
foreach ([
    'APP_SECRETS_FILE' => $previousSecretsPath,
    'ADMIN_LOGIN_PASSPHRASE' => $previousAdminPassphrase,
    'APP_IDENTITY_HASH_KEY' => $previousIdentityKey,
] as $key => $previousValue) {
    if ($previousValue === false) {
        putenv($key);
        unset($_SERVER[$key], $_ENV[$key]);
    } else {
        putenv($key . '=' . $previousValue);
        $_SERVER[$key] = $previousValue;
        $_ENV[$key] = $previousValue;
    }
}

$assert(str_contains($adminService, 'FOR UPDATE') && str_contains($adminService, '$conn->begin_transaction()'), 'OTP and limiter rows must be serialized transactionally.');
$assert(str_contains($adminService, 'self::deleteOtpRow($conn, $adminId)') && str_contains($adminService, "'status' => 'success'"), 'Valid OTP consumption must be atomic with verification success.');
$assert(str_contains($adminService, 'incrementAttemptRow') && str_contains($adminService, '$newAttempts >= $maxOtpAttempts'), 'Invalid OTP and passphrase attempts must be atomically limited.');
$assert(str_contains($adminLogin, 'AdminOtpService::issue') && str_contains($adminVerify, 'AdminOtpService::verify'), 'Admin endpoints must delegate OTP state transitions to the focused service.');
$assert(str_contains($adminVerify, "=== 'production'") && str_contains($adminVerify, '$appMfaPassphraseRequired'), 'Production verification must require passphrase plus OTP.');

require_once $root . '/includes/coupon-functions.php';
putenv('APP_IDENTITY_HASH_KEY=contract-test-identity-key-32-characters-long');
$normalizedA = coupon_guest_identity_hash('  GUEST@Example.COM ', '+91 (98765) 43210');
$normalizedB = coupon_guest_identity_hash('guest@example.com', '09876543210');
$assert(hash_equals($normalizedA, $normalizedB), 'Guest identity HMAC must normalize email case/whitespace and canonical Indian phone digits.');
putenv('APP_IDENTITY_HASH_KEY');
$assert(str_contains($couponService, "hash_hmac('sha256'") && str_contains($couponService, 'guest_identity_hash = ?'), 'Guest reservations must use keyed HMAC identity and duplicate lookup.');
$assert(str_contains($placeOrder, 'CouponService::guestIdentityHash($email, $phone)') && str_contains($guestRetry, 'customer_email') && str_contains($guestRetry, 'customer_phone'), 'Initial and guest retry reservation paths must pass the same guest identity.');

foreach (['guest_identity_hash', 'uq_coupon_usages_coupon_guest', 'commerce_outbox', 'commerce_outbox_deliveries', 'uq_commerce_outbox_dedupe'] as $needle) {
    $assert(str_contains($migration, $needle) && str_contains($schema, $needle) && str_contains($setup, $needle), 'Migration/schema/setup must align for ' . $needle . '.');
}
$checksum = hash_file('sha256', $root . '/' . $migrationPath);
$assert(is_string($checksum) && str_contains($schema, $checksum), 'Fresh schema must baseline the current remediation migration checksum.');

foreach (['60, 300, 900, 3600, 14400', 'STALE_CLAIM_MINUTES = 15', 'MAX_ATTEMPTS = 6', 'CronService::sanitizeError', 'commerce_outbox_deliveries'] as $needle) {
    $assert(str_contains($outbox, $needle), 'Outbox implementation is missing: ' . $needle . '.');
}
$assert(str_contains($placeOrder, 'OutboxService::enqueueOrderAfterCommit') && strpos($placeOrder, 'OutboxService::enqueueOrderAfterCommit') < strpos($placeOrder, '$conn->commit();'), 'Order side effects must be enqueued before the order commit.');
$assert(str_contains($verify, 'OutboxService::enqueuePaidOrderSideEffects') && str_contains($webhook, 'OutboxService::enqueuePaidOrderSideEffects'), 'Browser and webhook payment success paths must enqueue the same deduplicated work.');
$assert(str_contains($cron, "'commerce_outbox'") && str_contains($cron, "'commerce_outbox_deliveries'") && str_contains($cron, "'commerce_outbox', false"), 'Cron readiness and execution must include the outbox.');
$assert(str_contains($cron, 'outboxHealth') && !str_contains($cron, "'value' => _cfg"), 'Readiness must expose redacted outbox health without secret values.');

foreach (['configuration.php', 'reference-and-rates.php', 'bigship-payloads.php', 'shipment-lifecycle.php', 'webhook-handling.php', 'returns.php', 'admin-presentation.php', 'lifecycle-callbacks.php', 'cron-callbacks.php', 'registration.php'] as $module) {
    $assert(is_file($root . '/plugins/shipping-courier/modules/' . $module), 'Shipping module is missing: ' . $module . '.');
}
$shippingEntry = $read('plugins/shipping-courier/plugin.php');
$assert(str_contains($shippingEntry, "modules/registration.php") && !str_contains($shippingEntry, "add_action('order.after_commit'"), 'Shipping entry must delegate hook registration while preserving compatibility.');
$assert(str_contains($architecture, 'transactional outbox') && str_contains($architecture, 'guest_identity_hash'), 'Architecture documentation must describe outbox and guest coupon identity guarantees.');

if ($failures !== []) {
    fwrite(STDERR, "Priority remediation contract failures:\n- " . implode("\n- ", $failures) . "\n");
    exit(1);
}

echo "Priority findings remediation contracts passed.\n";
