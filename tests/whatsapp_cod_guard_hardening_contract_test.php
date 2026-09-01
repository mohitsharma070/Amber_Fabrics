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
    $value = @file_get_contents($root . '/' . $path);
    $assert($value !== false, $path . ' must exist.');
    return $value === false ? '' : $value;
};

$checkout = $read('checkout.php');
$checkoutScript = $read('js/checkout.js');
$placeOrder = $read('place-order.php');
$coupon = $read('includes/helpers/coupon-functions.php');
$plugin = $read('plugins/cod-guard/plugin.php');
$endpoint = $read('cod-guard-webhook.php');
$cron = $read('cron/run-plugins.php');
$configBootstrap = $read('config/db.php');
$migrationPath = 'database/migrations/2026-08-23-whatsapp-consent-webhook-idempotency.sql';
$migration = $read($migrationPath);
$schema = $read('database/schema.sql');
$setup = $read('database/setup.php');
$openapi = $read('openapi.yaml');
$architecture = $read('docs/repo-architecture.md');

$assert(str_contains($checkout, 'name="cod_whatsapp_consent"') && str_contains($checkout, 'This does not include marketing messages.'), 'Checkout must obtain explicit transactional-only WhatsApp consent.');
$assert(str_contains($checkoutScript, 'checkoutCurrentTotal >= codWhatsappThreshold') && str_contains($checkoutScript, 'codRequiresWhatsappConsent()') && str_contains($checkoutScript, 'okWhatsappConsent'), 'Checkout JavaScript must require consent only when the COD amount selects message confirmation.');
$assert(str_contains($placeOrder, "'cod_whatsapp_consent' => 'Agree to receive transactional WhatsApp messages") && str_contains($placeOrder, "'whatsapp_transactional_consent' => \$codWhatsappConsent"), 'Order placement must enforce and pass consent server-side.');
$assert(str_contains($placeOrder, "['whatsapp', 'call']") && str_contains($placeOrder, 'cod_guard_plan_for_amount($totalAmount)'), 'Canonical order placement must not require WhatsApp consent for auto-confirmed low-value COD orders.');
$assert(str_contains($coupon, "\$state['cod_whatsapp_consent']"), 'Coupon refreshes must preserve the consent choice.');

$assert(str_contains($plugin, 'whatsapp_consent_at') && str_contains($plugin, 'whatsapp_consent_version') && str_contains($plugin, 'cod_guard_message_consent_missing'), 'COD Guard must snapshot consent and suppress unconsented outbound messages.');
$assert(substr_count($plugin, "'sub_type' => 'quick_reply'") === 2 && str_contains($plugin, "'payload' => 'cod_yes:'") && str_contains($plugin, "'payload' => 'cod_no:'"), 'The approved COD template must send Confirm and Cancel quick-reply payloads.');
$assert(str_contains($plugin, "hash_equals((string) (\$row['response_token'] ?? ''), \$responseToken)") && str_contains($plugin, 'cod_guard_phone_key($from)'), 'Interactive replies must match the order response token and originating customer phone.');
$assert(str_contains($plugin, 'cod_guard_inbound_text_for_admin') && str_contains($plugin, 'Last customer reply:') && str_contains($plugin, 'Reply received:'), 'The COD Guard order panel must show the latest matched customer reply and timestamp.');
$assert(str_contains($plugin, "preg_replace('/\\bcod_") && str_contains($plugin, "mb_substr(\$text, 0, 500)"), 'The admin reply display must remove internal button payloads and bound displayed text.');
$assert(str_contains($plugin, 'cod_guard_claim_webhook_event') && str_contains($plugin, "? 'duplicate'") && str_contains($plugin, ": 'busy'") && str_contains($plugin, 'INTERVAL 15 MINUTE'), 'Inbound webhook handling must distinguish terminal duplicates from active or recoverable provider message IDs.');
$assert(str_contains($plugin, "'duplicates' => 0") && str_contains($plugin, 'cod_guard_finish_webhook_event'), 'Duplicate webhook deliveries must be counted and finalized without reapplying an order action.');
$assert(str_contains($plugin, 'cod_guard_cleanup_webhook_events') && str_contains($plugin, 'INTERVAL 90 DAY') && str_contains($plugin, 'LIMIT 5000'), 'Webhook event retention must be bounded through cron cleanup.');
$assert(str_contains($plugin, "\$settings['whatsapp_template_name']") && str_contains($plugin, "\$settings['whatsapp_template_language']"), 'Outbound WhatsApp readiness must require an approved template name and language.');
$assert(substr_count($configBootstrap, "\$required[] = 'COD_GUARD_WHATSAPP_TEMPLATE_NAME';") === 1 && str_contains($configBootstrap, "\$required[] = 'COD_GUARD_WHATSAPP_TEMPLATE_LANGUAGE';"), 'Production bootstrap must reject incomplete WhatsApp template configuration.');
$assert(str_contains($plugin, 'throw $e;') && str_contains($endpoint, 'CronService::sanitizeError'), 'Webhook database failures must remain retryable and logs must be sanitized.');
$assert(str_contains($endpoint, 'cod_guard_validate_webhook_request') && str_contains($plugin, 'HTTP_X_HUB_SIGNATURE_256'), 'Webhook processing must remain signature protected.');

foreach (['cod_guard_webhook_events', 'whatsapp_consent_at', 'whatsapp_consent_version', 'uq_cod_guard_webhook_message'] as $needle) {
    $assert(str_contains($migration, $needle) && str_contains($schema, $needle) && str_contains($setup, $needle), 'Migration, schema, and setup must align for ' . $needle . '.');
}
$checksum = hash_file('sha256', $root . '/' . $migrationPath);
$assert(is_string($checksum) && str_contains($schema, $checksum), 'Fresh schema must contain the WhatsApp hardening migration checksum.');
$assert(str_contains($cron, "'cod_guard_webhook_events'") && str_contains($cron, "'whatsapp_consent_at'"), 'Cron readiness must detect a missing WhatsApp hardening migration.');
$assert(str_contains($openapi, 'cod_whatsapp_consent') && str_contains($openapi, 'duplicate provider message IDs'), 'OpenAPI must document conditional consent and duplicate webhook acknowledgement.');
$assert(str_contains($architecture, 'never authorizes marketing messages') && str_contains($architecture, '`cod_guard_webhook_events`'), 'Architecture documentation must explain consent scope and webhook idempotency.');

if ($failures !== []) {
    fwrite(STDERR, "WhatsApp COD Guard hardening failures:\n- " . implode("\n- ", $failures) . "\n");
    exit(1);
}

echo "whatsapp_cod_guard_hardening_contract_test: OK\n";
