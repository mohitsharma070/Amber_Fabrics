<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$failures = [];
$assert = static function (bool $condition, string $message) use (&$failures): void {
    if (!$condition) {
        $failures[] = $message;
    }
};
$read = static fn(string $path): string => (string) file_get_contents($root . '/' . $path);

$metaCapi = $read('plugins/meta-capi/plugin.php');
$lifecycleService = $read('includes/services/WebhookLifecycleService.php');
$paymentService = $read('includes/services/PaymentService.php');
$paymentHelpers = $read('includes/helpers/payments.php');
$functionsBootstrap = $read('includes/functions.php');
$webhook = $read('payment/razorpay-webhook.php');
$placeOrder = $read('place-order.php');
$openApi = $read('openapi.yaml');
$architecture = $read('docs/repo-architecture.md');
$databaseSetup = $read('database/setup.php');

$paymentWebhookSetupStart = strpos($databaseSetup, 'CREATE TABLE IF NOT EXISTS payment_webhook_events');
$paymentWebhookSetupEnd = $paymentWebhookSetupStart === false
    ? false
    : strpos($databaseSetup, 'CREATE TABLE IF NOT EXISTS order_activity_logs', $paymentWebhookSetupStart);
$paymentWebhookSetupBlock = ($paymentWebhookSetupStart !== false && $paymentWebhookSetupEnd !== false)
    ? substr($databaseSetup, $paymentWebhookSetupStart, $paymentWebhookSetupEnd - $paymentWebhookSetupStart)
    : '';
$requiredPaymentWebhookSetupFragments = [
    'payload_hash CHAR(64)',
    'raw_payload LONGTEXT',
    "status ENUM('received','processing','processed','failed')",
    'attempts INT',
    'last_error TEXT',
    'processed_at DATETIME',
    'updated_at TIMESTAMP',
    'INDEX idx_payment_webhook_status (provider, status)',
    'INDEX idx_payment_webhook_processed_at (processed_at)',
    'INDEX idx_payment_webhook_created_at (created_at)',
];
$paymentWebhookSetupComplete = $paymentWebhookSetupBlock !== '';
foreach ($requiredPaymentWebhookSetupFragments as $fragment) {
    $paymentWebhookSetupComplete = $paymentWebhookSetupComplete
        && str_contains($paymentWebhookSetupBlock, $fragment);
}
$assert(
    $paymentWebhookSetupComplete,
    'Fresh database setup must create the complete payment webhook lifecycle schema required by runtime processing.'
);

$adminsSetupStart = strpos($databaseSetup, 'CREATE TABLE IF NOT EXISTS admins');
$adminsSetupEnd = $adminsSetupStart === false
    ? false
    : strpos($databaseSetup, 'CREATE TABLE IF NOT EXISTS inquiry_activity_logs', $adminsSetupStart);
$adminsSetupBlock = ($adminsSetupStart !== false && $adminsSetupEnd !== false)
    ? substr($databaseSetup, $adminsSetupStart, $adminsSetupEnd - $adminsSetupStart)
    : '';
$requiredAdminsSetupFragments = [
    "role ENUM('viewer','catalog_manager','operations_manager','super_admin')",
    'is_active TINYINT(1)',
    'last_login_at DATETIME',
    'last_login_ip VARCHAR(45)',
    'last_login_user_agent VARCHAR(500)',
    'INDEX idx_admins_role_active (role, is_active)',
];
$adminsSetupComplete = $adminsSetupBlock !== '';
foreach ($requiredAdminsSetupFragments as $fragment) {
    $adminsSetupComplete = $adminsSetupComplete
        && str_contains($adminsSetupBlock, $fragment);
}
$assert(
    $adminsSetupComplete,
    'Fresh database setup must create the complete administrator schema required by OTP verification.'
);

$assert(
    str_contains($metaCapi, "add_action('order.after_commit', 'meta_capi_handle_cod_purchase', 30);")
        && !str_contains($metaCapi, "add_action('order.after_create', 'meta_capi_handle_cod_purchase', 30);"),
    'Meta CAPI COD Purchase delivery must run through the durable post-commit order hook, never inside order.after_create.'
);
$assert(
    str_contains($metaCapi, 'customer_email')
        && str_contains($metaCapi, 'customer_phone')
        && str_contains($metaCapi, "'email' =>")
        && str_contains($metaCapi, "'phone' =>"),
    'Meta CAPI durable COD retries must rebuild customer matching data from the persisted order.'
);
$assert(
    str_contains($placeOrder, "'marketing_consent' => marketing_consent_status()")
        && str_contains($metaCapi, 'commerce_outbox')
        && str_contains($metaCapi, 'marketing_consent'),
    'Meta CAPI durable retries must restore the order-time consent decision instead of relying on the retry request session.'
);

$claimStart = strpos($lifecycleService, 'public static function beginProcessing');
$selectStart = $claimStart === false ? false : strpos($lifecycleService, '$select = $conn->prepare(', $claimStart);
$claimPrefix = ($claimStart !== false && $selectStart !== false)
    ? substr($lifecycleService, $claimStart, $selectStart - $claimStart)
    : '';
$assert(
    $claimPrefix !== ''
        && str_contains($claimPrefix, 'id = LAST_INSERT_ID(id)')
        && !str_contains($claimPrefix, 'signature = VALUES(signature)')
        && !str_contains($claimPrefix, 'payload_hash = VALUES(payload_hash)')
        && !str_contains($claimPrefix, 'raw_payload = VALUES(raw_payload)')
        && !str_contains($claimPrefix, 'updated_at = NOW()'),
    'Webhook duplicate upsert must not refresh updated_at before the stale-processing lease check.'
);
$assert(
    str_contains($webhook, 'WebhookLifecycleService::beginProcessing('),
    'Razorpay webhook endpoint must use the lease-safe webhook lifecycle service.'
);
$paymentClaimStart = strpos($paymentService, 'public static function payment_webhook_begin_processing(');
$paymentClaimEnd = $paymentClaimStart === false
    ? false
    : strpos($paymentService, 'public static function payment_webhook_mark_failed(', $paymentClaimStart);
$paymentClaimBlock = ($paymentClaimStart !== false && $paymentClaimEnd !== false)
    ? substr($paymentService, $paymentClaimStart, $paymentClaimEnd - $paymentClaimStart)
    : '';
$assert(
    $paymentClaimBlock !== ''
        && str_contains($paymentClaimBlock, "require_once __DIR__ . '/WebhookLifecycleService.php'")
        && str_contains($paymentClaimBlock, 'WebhookLifecycleService::beginProcessing(')
        && !str_contains($paymentClaimBlock, 'ON DUPLICATE KEY UPDATE')
        && str_contains($paymentHelpers, 'PaymentService::payment_webhook_begin_processing(')
        && str_contains($functionsBootstrap, "'/services/WebhookLifecycleService.php'"),
    'The existing PaymentService and helper APIs must delegate to the single lease-safe lifecycle implementation.'
);
$markProcessedStart = strpos($paymentService, 'public static function payment_webhook_mark_processed(');
$markFailedStart = strpos($paymentService, 'public static function payment_webhook_mark_failed(');
$attemptTouchStart = $markFailedStart === false
    ? false
    : strpos($paymentService, 'public static function payment_attempt_touch(', $markFailedStart);
$markProcessedBlock = ($markProcessedStart !== false && $paymentClaimStart !== false)
    ? substr($paymentService, $markProcessedStart, $paymentClaimStart - $markProcessedStart)
    : '';
$markFailedBlock = ($markFailedStart !== false && $attemptTouchStart !== false)
    ? substr($paymentService, $markFailedStart, $attemptTouchStart - $markFailedStart)
    : '';
$assert(
    str_contains($webhook, '$lifecycleAttempt')
        && str_contains($markProcessedBlock, "status = 'processing'")
        && str_contains($markProcessedBlock, 'attempts = ?')
        && str_contains($markFailedBlock, "status = 'processing'")
        && str_contains($markFailedBlock, 'attempts = ?'),
    'Webhook completion and failure must be fenced by the claimed attempt so a stale owner cannot overwrite a newer lease.'
);

require_once $root . '/includes/services/WebhookLifecycleService.php';
$leasePolicyWorks = method_exists(WebhookLifecycleService::class, 'processingLeaseIsStale')
    && !WebhookLifecycleService::processingLeaseIsStale('received', 100, 500, 120)
    && !WebhookLifecycleService::processingLeaseIsStale('processing', 400, 500, 120)
    && WebhookLifecycleService::processingLeaseIsStale('processing', 300, 500, 120);
$assert(
    $leasePolicyWorks,
    'Webhook processing lease expiry must be executable policy: only processing rows older than the TTL are stale.'
);
$providerRetryPolicyWorks = method_exists(WebhookLifecycleService::class, 'requiresProviderRetry')
    && WebhookLifecycleService::requiresProviderRetry('in_progress')
    && !WebhookLifecycleService::requiresProviderRetry('claimed')
    && !WebhookLifecycleService::requiresProviderRetry('already_processed');
$assert(
    $providerRetryPolicyWorks,
    'Only an active in-progress webhook claim must require a provider retry response.'
);

$inProgressStart = strpos($webhook, "if ((\$lifecycle['state'] ?? '') === 'in_progress') {");
$inProgressStart = $inProgressStart === false
    ? strpos($webhook, 'if (WebhookLifecycleService::requiresProviderRetry(')
    : $inProgressStart;
$claimedLogStart = $inProgressStart === false ? false : strpos($webhook, "error_log('[razorpay-webhook] claimed", $inProgressStart);
$inProgressBlock = ($inProgressStart !== false && $claimedLogStart !== false)
    ? substr($webhook, $inProgressStart, $claimedLogStart - $inProgressStart)
    : '';
$assert(
    $inProgressBlock !== ''
        && str_contains($inProgressBlock, 'http_response_code(503)')
        && !str_contains($inProgressBlock, 'http_response_code(200)'),
    'An active webhook claim must return a retriable HTTP response so a crashed owner cannot strand the payment event.'
);
$razorpaySpecStart = strpos($openApi, '  /payment/razorpay-webhook.php:');
$nextSpecStart = $razorpaySpecStart === false ? false : strpos($openApi, "\n  /", $razorpaySpecStart + 3);
$razorpaySpec = ($razorpaySpecStart !== false && $nextSpecStart !== false)
    ? substr($openApi, $razorpaySpecStart, $nextSpecStart - $razorpaySpecStart)
    : '';
$assert(
    $razorpaySpec !== ''
        && str_contains($razorpaySpec, "'503':")
        && str_contains($razorpaySpec, 'Retry-After:')
        && str_contains($architecture, 'active duplicate'),
    'The Razorpay endpoint contract and architecture documentation must describe retryable active-duplicate responses.'
);

$captureHandlerStart = strpos($webhook, '$businessCommitted = false;');
$captureTransactionStart = $captureHandlerStart === false
    ? false
    : strpos($webhook, '$conn->begin_transaction();', $captureHandlerStart);
$remoteValidationStart = $captureHandlerStart === false
    ? false
    : strpos($webhook, 'PaymentService::razorpay_validate_remote_capture(', $captureHandlerStart);
$assert(
    $captureHandlerStart !== false
        && $captureTransactionStart !== false
        && $remoteValidationStart !== false
        && $remoteValidationStart < $captureTransactionStart,
    'Razorpay remote capture validation must finish before the database mutation transaction and order lock begin.'
);

$missingOrderStart = strpos($webhook, "if (\$rzpOrderId === '') {");
$paymentFailedStart = $missingOrderStart === false ? false : strpos($webhook, "if (\$eventType === 'payment.failed')", $missingOrderStart);
$missingOrderBlock = ($missingOrderStart !== false && $paymentFailedStart !== false)
    ? substr($webhook, $missingOrderStart, $paymentFailedStart - $missingOrderStart)
    : '';
$assert(
    $missingOrderBlock !== ''
        && str_contains($missingOrderBlock, 'PaymentService::payment_webhook_mark_failed(')
        && str_contains($missingOrderBlock, 'http_response_code(400)'),
    'A claimed supported webhook with no Razorpay order id must mark the lifecycle failed before returning HTTP 400.'
);

if (!function_exists('add_action')) {
    function add_action(...$args): void
    {
    }
}
if (!function_exists('plugin_setting')) {
    function plugin_setting(string $plugin, string $key, mixed $default = null): mixed
    {
        if ($plugin === 'meta-capi' && $key === 'access_token') {
            return 'test-access-token';
        }
        if ($plugin === 'meta-capi' && $key === 'pixel_id') {
            return 'test-pixel-id';
        }
        return $default;
    }
}
if (!function_exists('marketing_consent_granted')) {
    function marketing_consent_granted(): bool
    {
        return (bool) ($GLOBALS['reliability_current_marketing_consent'] ?? false);
    }
}
if (!function_exists('app_http_json')) {
    function app_http_json(...$args): array
    {
        $GLOBALS['reliability_meta_http_calls'][] = $args;
        return ['ok' => true, 'body' => []];
    }
}

require_once $root . '/plugins/meta-capi/plugin.php';

$GLOBALS['reliability_current_marketing_consent'] = false;
$_SESSION['meta_fbp'] = 'scheduler-fbp';
$_SESSION['meta_fbc'] = 'scheduler-fbc';
$_SERVER['REMOTE_ADDR'] = '203.0.113.10';
$_SERVER['HTTP_USER_AGENT'] = 'Cron Scheduler';
$durableConsentContext = [
    'marketing_consent' => 'granted',
    'durable_delivery' => true,
    'email' => 'retry@example.test',
    'phone' => '+91 98765 43210',
    'customer_id' => 42,
];
$durableUserData = meta_capi_user_data($durableConsentContext);
$assert(
    meta_capi_enabled($durableConsentContext)
        && ($durableUserData['em'][0] ?? '') === hash('sha256', 'retry@example.test')
        && ($durableUserData['ph'][0] ?? '') === hash('sha256', '919876543210')
        && ($durableUserData['external_id'][0] ?? '') === hash('sha256', '42')
        && !isset($durableUserData['fbp'], $durableUserData['fbc'])
        && !isset($durableUserData['client_ip_address'], $durableUserData['client_user_agent']),
    'A durable granted-consent snapshot must enable retry-safe Meta matching even when the current request has no consent session.'
);
$GLOBALS['reliability_meta_http_calls'] = [];
meta_capi_post_event(
    'Purchase',
    ['currency' => 'INR', 'value' => 100],
    $durableUserData,
    'retry-event-id',
    '',
    $durableConsentContext
);
$assert(
    count($GLOBALS['reliability_meta_http_calls']) === 1,
    'A durable granted-consent retry must attempt Meta delivery instead of being silently marked complete.'
);
$GLOBALS['reliability_current_marketing_consent'] = true;
$durableNormalizationWorks = function_exists('meta_capi_normalize_durable_consent')
    && meta_capi_normalize_durable_consent('granted') === 'granted'
    && meta_capi_normalize_durable_consent('denied') === 'denied'
    && meta_capi_normalize_durable_consent('unknown') === 'unknown'
    && meta_capi_normalize_durable_consent('invalid') === 'unknown'
    && meta_capi_normalize_durable_consent(null) === 'unknown';
$assert(
    $durableNormalizationWorks
        && meta_capi_user_data(['marketing_consent' => 'unknown', 'email' => 'unknown@example.test']) === [],
    'Missing, malformed, or unknown durable consent must fail closed even when the current browser session is granted.'
);
$assert(
    meta_capi_user_data(['marketing_consent' => 'denied', 'email' => 'denied@example.test']) === [],
    'A durable denied-consent snapshot must continue to block Meta delivery.'
);

if ($failures !== []) {
    fwrite(STDERR, "Reliability release blocker contract failures:\n- " . implode("\n- ", $failures) . "\n");
    exit(1);
}

echo "Reliability release blocker contracts passed.\n";
