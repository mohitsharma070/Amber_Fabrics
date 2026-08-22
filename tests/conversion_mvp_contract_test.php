<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$fail = [];
$assert = static function (bool $ok, string $message) use (&$fail): void {
    if (!$ok) $fail[] = $message;
};

$migration = (string) file_get_contents($root . '/database/migrations/2026-08-12-conversion-mvp.sql');
$backendFixMigration = (string) file_get_contents($root . '/database/migrations/2026-08-13-conversion-mvp-backend-fixes.sql');
$access = (string) file_get_contents($root . '/includes/services/OrderAccessService.php');
$estimate = (string) file_get_contents($root . '/includes/services/DeliveryEstimateService.php');
$deliveryEndpoint = (string) file_get_contents($root . '/delivery-estimate.php');
$rate = (string) file_get_contents($root . '/shipping-rate.php');
$email = (string) file_get_contents($root . '/includes/services/EmailService.php');
$checkout = (string) file_get_contents($root . '/checkout.php');
$cartPage = (string) file_get_contents($root . '/cart.php');
$frontendScript = (string) file_get_contents($root . '/js/app.js');
$commerceScript = (string) file_get_contents($root . '/js/commerce.js');
$activationPage = (string) file_get_contents($root . '/guest/account-activate.php');
$placeOrder = (string) file_get_contents($root . '/place-order.php');
$orderPersistence = (string) file_get_contents($root . '/includes/services/OrderPersistenceService.php');
$guestAccess = (string) file_get_contents($root . '/guest/order-access.php');
$guestRetry = (string) file_get_contents($root . '/guest/retry-payment.php');
$razorpayVerify = (string) file_get_contents($root . '/payment/razorpay-verify.php');
$razorpayWebhook = (string) file_get_contents($root . '/payment/razorpay-webhook.php');
$outbox = (string) file_get_contents($root . '/includes/services/OutboxService.php');
$adminHelpers = (string) file_get_contents($root . '/includes/helpers/admin.php');
$fabric = (string) file_get_contents($root . '/fabric.php');

$assert(str_contains($migration, 'guest_order_access_tokens'), 'Guest token migration missing.');
$assert(str_contains($migration, 'token_hash CHAR(64)'), 'Token hashes are not declared.');
$assert(!str_contains($access, "'token' =>"), 'Raw tokens must not be stored in sessions.');
$assert(str_contains($access, 'consumed_at IS NULL'), 'Token replay protection missing.');
$assert(str_contains($access, 'purposeEnabled') && str_contains($access, 'account_activation_token_ttl_seconds') && str_contains($access, 'guest_order_token_ttl_seconds'), 'Token purposes and configurable lifetimes must remain independent.');
$assert(str_contains($access, 'IDLE_SECONDS = 1800') && str_contains($access, 'ABSOLUTE_SECONDS = 7200'), 'Guest session timeouts missing.');
$assert(str_contains($estimate, 'addBusinessDays'), 'Business-day estimate missing.');
$assert(str_contains($deliveryEndpoint, "\$_POST['payment_method'] ?? 'cod'") && str_contains($deliveryEndpoint, '$paymentInput'), 'PDP delivery estimate must default a missing payment method to COD.');
$assert(str_contains($deliveryEndpoint, 'Whole units are required') && str_contains($deliveryEndpoint, 'meter_qty_respects_step'), 'Delivery endpoint must enforce unit quantity rules server-side.');
$assert(str_contains($fabric, 'data-ui-delivery-estimate') && str_contains($commerceScript, '.finally(function ()') && str_contains($commerceScript, 'AmberUI.setButtonLoading(button, false)'), 'PDP delivery checker must restore its AJAX loading state.');
$assert(!str_contains($cartPage, 'cart_delivery_form') && !str_contains($cartPage, '<span>Shipping</span>'), 'Cart must omit shipping calculation and presentation.');
$assert(str_contains($frontendScript, 'if (event.defaultPrevented)') && str_contains($frontendScript, 'form.hasAttribute("data-ui-async")'), 'Prevented and managed asynchronous forms must not enter the global loading state.');
$assert(str_contains($activationPage, 'activation_token_fingerprint') && !str_contains($activationPage, 'name="token"'), 'Activation refresh must reuse only the scoped server session.');
$assert(str_contains($activationPage, 'begin_transaction()') && str_contains($activationPage, 'FOR UPDATE'), 'Account creation and guest-order claiming must be transactional.');
$assert(!str_contains($checkout, 'Login is required before the order is created.'), 'Guest Razorpay copy incorrectly requires login.');
$assert(str_contains($checkout, '$hasCompleteDelivery') && str_contains($checkout, 'checkout_continue_payment') && str_contains($commerceScript, 'function setUnlocked(unlocked)') && str_contains($commerceScript, 'quoteValid = deliveryUnlocked'), 'Checkout must gate payment and review behind a validated delivery quote.');
$assert(str_contains($rate, "'serviceability_status'"), 'Shipping response lacks serviceability.');
$assert(str_contains($email, 'send_guest_manage_link') && str_contains($email, 'send_account_activation_email'), 'Transactional guest email methods missing.');
$assert(str_contains($backendFixMigration, 'account_activation_requested') && str_contains($email, 'send_requested_account_activation_email'), 'Asynchronous activation intent persistence missing.');
$assert(str_contains($razorpayVerify, 'enqueuePaidOrderSideEffects') && str_contains($razorpayWebhook, 'enqueuePaidOrderSideEffects') && str_contains($outbox, 'send_requested_account_activation_email'), 'Razorpay completion must durably deliver requested activation email.');
$estimateUpdatePosition = strpos($placeOrder, 'OrderPersistenceService::saveDeliveryEstimate');
$razorpayRedirectPosition = strpos($placeOrder, "redirect('/payment/razorpay-create.php')");
$assert(str_contains($orderPersistence, 'estimated_delivery_start') && str_contains($orderPersistence, 'estimated_delivery_end'), 'Order persistence must retain the immutable delivery estimate fields.');
$assert($estimateUpdatePosition !== false && $razorpayRedirectPosition !== false && $estimateUpdatePosition < $razorpayRedirectPosition, 'Order estimate must be delegated before the Razorpay redirect.');
$assert(str_contains($guestAccess, 'guest_order_link_ip_') && str_contains($guestAccess, 'guest_order_link_identifier_') && str_contains($guestAccess, '900, false'), 'Guest link requests must be limited independently by IP and identifier.');
$assert(str_contains($adminHelpers, 'bool $bindClient = true'), 'Public rate limiting must support privacy-safe identifier scopes.');
$assert(str_contains($guestRetry, 'begin_transaction()') && str_contains($guestRetry, 'LIMIT 1 FOR UPDATE') && str_contains($guestRetry, "'guest'"), 'Guest payment retry must be transactional and logged as a guest action.');
$assert(str_contains($checkout, 'Step 1 of 3') && str_contains($checkout, 'checkout_submit'), 'Three-step checkout contract missing.');

if ($fail !== []) {
    foreach ($fail as $message) {
        fwrite(STDERR, "FAIL: {$message}\n");
    }
    exit(1);
}

echo "conversion_mvp_contract_test: OK\n";
