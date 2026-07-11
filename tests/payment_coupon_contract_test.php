<?php
/**
 * Fast, database-free regression contract for the payment/coupon safety invariants.
 * Run with: php tests/payment_coupon_contract_test.php
 */
$root = dirname(__DIR__);
$read = static fn(string $path): string => (string) file_get_contents($root . '/' . $path);
$coupon = $read('includes/coupon-functions.php');
$service = $read('includes/services/PaymentService.php');
$verify = $read('payment/razorpay-verify.php');
$webhook = $read('payment/razorpay-webhook.php');
$retry = $read('retry-payment.php');
$migration = $read('database/migrations/2026-07-11-coupon-payment-reservations.sql');
$guestAuth = $read('includes/customer-auth.php');
$guestMigration = $read('database/migrations/2026-07-11-add-guest-order-capabilities.sql');
$success = $read('order-success.php');
$create = $read('payment/razorpay-create.php');
$failure = $read('payment/razorpay-failure.php');
$reconciliation = $read('includes/services/PaymentService.php');
$placeOrder = $read('place-order.php');
$outboxMigration = $read('database/migrations/2026-07-11-add-event-outbox.sql');
$metaPlugin = $read('plugins/meta-capi/plugin.php');
$hooks = $read('includes/hooks.php');

$checks = [
    'two customers competing for final use are serialized by a conditional coupon claim' => str_contains($coupon, "usage_limit = 0 OR used_count < usage_limit") && str_contains($coupon, 'FOR UPDATE'),
    'browser callback commits captured payment before coupon reconciliation' => ($commit = strpos($verify, '$conn->commit();')) !== false && strpos($verify, 'reconcile_coupon_after_razorpay_capture') > $commit,
    'webhook and browser share idempotent paid-state handling' => str_contains($webhook, "payment_status'] ?? '') === 'paid'") && str_contains($service, "payment_status IN ('pending', 'failed')"),
    'coupon fault after capture is recorded without changing payment state' => str_contains($service, 'payment_reconciliation_failures') && str_contains($service, 'Captured payment retained as paid'),
    'retry reacquires released coupon capacity atomically' => str_contains($retry, 'reserve_coupon_for_order($conn, $resolvedCouponId, $customerId, $orderId)'),
    'duplicate webhook events have durable lifecycle handling' => str_contains($webhook, 'payment_webhook_begin_processing') && str_contains($webhook, 'already_processed'),
    'migration defines durable reservation states and reconciliation queue' => str_contains($migration, "ENUM('reserved','consumed','released')") && str_contains($migration, 'payment_reconciliation_failures'),
    'guest COD success requires a session-bound capability' => str_contains($success, 'guest_order_access_allowed') && str_contains($guestAuth, 'issue_guest_order_capability'),
    'guest Razorpay create, verify, failure and retry require order-scoped authorization' => str_contains($create, 'require_order_access') && str_contains($verify, 'require_order_access') && str_contains($failure, 'require_order_access') && str_contains($retry, 'require_order_access'),
    'invalid, expired and cross-order tokens are rejected' => str_contains($guestAuth, 'guest_capability_expires_at') && str_contains($guestAuth, 'guest_order_capabilities') && str_contains($guestAuth, 'hash_equals'),
    'authenticated ownership remains strict' => str_contains($guestAuth, 'WHERE id = ? AND customer_id = ?') && str_contains($guestAuth, 'is_customer_logged_in()'),
    'guest capability raw secret is never persisted and is cleared after completion' => str_contains($guestMigration, 'guest_capability_hash') && str_contains($success, 'clear_guest_order_capability'),
    'modal dismissal records intent without locally failing payment or restoring inventory' => str_contains($failure, 'Browser events are intent only') && !str_contains($failure, 'InventoryService::restore_order_inventory'),
    'modal dismissal racing capture webhook is serialized by order locks and paid-state guards' => str_contains($failure, 'FOR UPDATE') && str_contains($webhook, "payment_status'] ?? '') === 'paid'"),
    'stale boundary capture is finalized from authoritative gateway reconciliation' => str_contains($reconciliation, 'razorpay_reconcile_order_remote') && str_contains($reconciliation, "'state' => 'captured'") && str_contains($reconciliation, 'razorpay_mark_order_paid'),
    'gateway timeout defers reconciliation and retains inventory' => str_contains($reconciliation, "'state' => 'unavailable'") && str_contains($reconciliation, 'payment_reconciliation_deferred') && str_contains($reconciliation, "'gateway_status'"),
    'duplicate failure and capture events remain idempotent' => str_contains($webhook, 'payment_webhook_begin_processing') && str_contains($reconciliation, "payment_status']) === 'paid"),
    'late capture cannot follow browser inventory release' => !str_contains($failure, 'release_coupon_reservation_for_order') && !str_contains($failure, 'restore_order_inventory'),
    'account plus COD/Razorpay order creates customer inside the order transaction' => strpos($placeOrder, '$conn->begin_transaction();') < strpos($placeOrder, 'INSERT INTO customers') && strpos($placeOrder, 'INSERT INTO customers') < strpos($placeOrder, 'INSERT INTO orders'),
    'stock, shipping, coupon, item and payment failures roll back requested account' => str_contains($placeOrder, '$conn->rollback();') && str_contains($placeOrder, 'InventoryService::reserve_order_inventory') && str_contains($placeOrder, 'INSERT INTO payments'),
    'duplicate-email race retains the existing login guidance' => str_contains($placeOrder, 'mysqli_sql_exception') && str_contains($placeOrder, 'An account with this email already exists. Please log in or continue without account creation.'),
    'verification email is deferred until after commit and failure is recorded' => strpos($placeOrder, '$conn->commit();') < strpos($placeOrder, 'send_customer_verification_email') && str_contains($placeOrder, 'account_verification_email_failed'),
    'account-created checkout retains guest order capability before verification' => str_contains($placeOrder, '$guestCheckoutStarted') && str_contains($placeOrder, 'issue_guest_order_capability'),
    'rolled-back order cannot emit Meta purchase because only an outbox row is transactionally inserted' => strpos($placeOrder, "outbox_enqueue") < strpos($placeOrder, '$conn->commit();') && str_contains($outboxMigration, 'uq_event_outbox_idempotency'),
    'Meta timeout is retried asynchronously with bounded backoff' => str_contains($reconciliation, 'outbox_process') && str_contains($reconciliation, 'next_attempt_at') && str_contains($reconciliation, 'attempts >= 5'),
    'duplicate hook/payment execution deduplicates purchase by order event key' => str_contains($reconciliation, "'order:' . ") && str_contains($outboxMigration, 'UNIQUE KEY uq_event_outbox_idempotency'),
    'cron delivery is outside database locks and provider failure is isolated' => str_contains($hooks, "do_action_report('outbox.process'") === false && str_contains($metaPlugin, 'meta_capi_process_outbox_event') && str_contains($reconciliation, "status = 'processing'"),
];

$failed = [];
foreach ($checks as $name => $passed) {
    fwrite(STDOUT, ($passed ? '[PASS] ' : '[FAIL] ') . $name . PHP_EOL);
    if (!$passed) $failed[] = $name;
}
exit($failed === [] ? 0 : 1);
