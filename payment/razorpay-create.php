<?php
require_once __DIR__ . '/../includes/init.php';
require_once __DIR__ . '/../includes/security/customer-auth.php';

$cancelInvalidRazorpayOrder = static function (mysqli $conn, int $orderId, string $reason): void {
    if ($orderId <= 0) {
        return;
    }
    try {
        $conn->begin_transaction();
        $orderStmt = $conn->prepare(
            "SELECT payment_status
             FROM orders
             WHERE id = ? AND payment_method = 'razorpay'
             FOR UPDATE"
        );
        $orderStmt->bind_param('i', $orderId);
        $orderStmt->execute();
        $order = $orderStmt->get_result()->fetch_assoc();
        if (!$order) {
            $conn->rollback();
            return;
        }
        if (strtolower((string) ($order['payment_status'] ?? '')) === 'paid') {
            $conn->rollback();
            return;
        }

        $orderUpdate = $conn->prepare(
            "UPDATE orders
             SET payment_status = 'failed',
                 order_status = 'cancelled',
                 status = 'cancelled',
                 notes = CASE WHEN notes IS NULL OR notes = '' THEN ? ELSE CONCAT(notes, '\n', ?) END,
                 updated_at = NOW()
             WHERE id = ?"
        );
        $orderUpdate->bind_param('ssi', $reason, $reason, $orderId);
        $orderUpdate->execute();

        $paymentUpdate = $conn->prepare(
            "UPDATE payments
             SET payment_status = 'failed'
             WHERE order_id = ? AND payment_method = 'razorpay' AND payment_status = 'pending'"
        );
        $paymentUpdate->bind_param('i', $orderId);
        $paymentUpdate->execute();

        InventoryService::restore_order_inventory($conn, $orderId);
        release_coupon_usage_for_order($conn, $orderId);
        log_order_activity($conn, $orderId, 'payment_invalid_amount', 'system', 0, 'system', $reason);
        $conn->commit();
    } catch (Throwable $cleanupException) {
        try {
            $conn->rollback();
        } catch (Throwable $rollbackException) {
            // ignore rollback errors during cleanup
        }
        error_log('[razorpay] invalid amount cleanup failed: ' . $cleanupException->getMessage());
    }
};

if (empty($_SESSION['pending_order_id'])) {
    flash('error', 'No pending Razorpay order found.');
    redirect('/checkout.php');
}

$orderId = (int) $_SESSION['pending_order_id'];
$pendingOrderNumber = trim((string) ($_SESSION['pending_order_number'] ?? ''));
$customerId = (int) ($_SESSION['customer_id'] ?? 0);
PaymentService::release_stale_pending_razorpay_orders_for_customer($conn, $customerId, 30);
$preferredOnlineMethod = InventoryService::sanitize_online_payment_method((string) ($_SESSION['pending_online_method'] ?? ''));
$ordersFallback = $customerId > 0 ? '/customer/orders.php' : '/checkout.php';

if ($customerId > 0) {
    $stmt = $conn->prepare(
        "SELECT id, order_number, customer_name, customer_email, customer_phone, total_amount, payment_method, payment_status, order_status, created_at
         FROM orders
         WHERE id = ? AND customer_id = ? AND payment_method = 'razorpay' AND payment_status IN ('pending','failed')
         LIMIT 1"
    );
    $stmt->bind_param('ii', $orderId, $customerId);
} else {
    $stmt = $conn->prepare(
        "SELECT id, order_number, customer_name, customer_email, customer_phone, total_amount, payment_method, payment_status, order_status, created_at
         FROM orders
         WHERE id = ? AND order_number = ? AND payment_method = 'razorpay' AND payment_status IN ('pending','failed')
         LIMIT 1"
    );
    $stmt->bind_param('is', $orderId, $pendingOrderNumber);
}
$stmt->execute();
$order = $stmt->get_result()->fetch_assoc();

if (!$order) {
    flash('error', 'Order not available for Razorpay payment.');
    redirect('/checkout.php');
}
if (!in_array((string) ($order['order_status'] ?? ''), ['pending', 'confirmed'], true)) {
    flash('error', 'Order is not in a payable state.');
    redirect($ordersFallback);
}
if (strtotime((string) ($order['created_at'] ?? 'now')) < strtotime('-30 minutes')) {
    flash('error', 'This payment session has expired. Please place a new order.');
    redirect($ordersFallback);
}

$keyId = _cfg('RAZORPAY_KEY_ID', '');
$keySecret = _cfg('RAZORPAY_KEY_SECRET', '');
if ($keyId === '' || $keySecret === '') {
    flash('error', 'Razorpay configuration is missing. Please contact support.');
    redirect('/checkout.php');
}

$orderAmount = (float) $order['total_amount'];
if ($orderAmount <= 0 || $orderAmount > 999999.99) {
    error_log('[razorpay] invalid order amount ' . $orderAmount . ' for order_id=' . $orderId);
    $cancelInvalidRazorpayOrder($conn, $orderId, 'Razorpay order cancelled because total amount was invalid for gateway checkout.');
    flash('error', 'Invalid order amount. Please contact support.');
    redirect('/checkout.php');
}
$amountPaise = (int) round($orderAmount * 100);
if ($amountPaise <= 0) {
    $cancelInvalidRazorpayOrder($conn, $orderId, 'Razorpay order cancelled because payable amount rounded to zero paise.');
    flash('error', 'Invalid order amount. Please contact support.');
    redirect('/checkout.php');
}

try {
    $claimToken = bin2hex(random_bytes(16));
    $ownsCreateClaim = false;
    $conn->begin_transaction();
    $paymentRowStmt = $conn->prepare(
        "SELECT id, razorpay_order_id, razorpay_create_claim_token, razorpay_create_claimed_at
         FROM payments
         WHERE order_id = ? AND payment_method = 'razorpay'
         LIMIT 1 FOR UPDATE"
    );
    $paymentRowStmt->bind_param('i', $orderId);
    $paymentRowStmt->execute();
    $payRow = $paymentRowStmt->get_result()->fetch_assoc();
    if (!$payRow) {
        throw new RuntimeException('Payment row not found for this order.');
    }
    $paymentRowId = (int) ($payRow['id'] ?? 0);
    $existingRzpOrderId = trim((string) ($payRow['razorpay_order_id'] ?? ''));
    $remoteRzpOrderId = '';
    $activeClaim = trim((string) ($payRow['razorpay_create_claim_token'] ?? '')) !== ''
        && strtotime((string) ($payRow['razorpay_create_claimed_at'] ?? '')) >= strtotime('-30 seconds');

    if ($existingRzpOrderId !== '') {
        $rzpOrderId = $existingRzpOrderId;
        $conn->commit();
    } elseif (!$activeClaim) {
        $claimStmt = $conn->prepare(
            "UPDATE payments
             SET razorpay_create_claim_token = ?, razorpay_create_claimed_at = NOW()
             WHERE id = ? AND razorpay_order_id IS NULL"
        );
        $claimStmt->bind_param('si', $claimToken, $paymentRowId);
        $claimStmt->execute();
        if ($claimStmt->affected_rows !== 1) {
            throw new RuntimeException('Unable to claim Razorpay order initialization.');
        }
        $ownsCreateClaim = true;
        $conn->commit();

        $createResp = PaymentService::razorpay_create_order_remote($orderId, (string) $order['order_number'], $amountPaise);
        if (empty($createResp['ok'])) {
            $providerError = (string) ($createResp['error'] ?? 'gateway_create_failed');
            $durationMs = (int) ($createResp['duration_ms'] ?? 0);
            error_log('[razorpay-create] provider create failed order_id=' . $orderId . ' error=' . $providerError . ' duration_ms=' . $durationMs);
            throw new RuntimeException('Razorpay create failed: ' . $providerError);
        }
        $remoteRzpOrderId = trim((string) ($createResp['id'] ?? ''));
        error_log('[razorpay-create] provider create success order_id=' . $orderId . ' rzp_order_id=' . $remoteRzpOrderId . ' duration_ms=' . (int) ($createResp['duration_ms'] ?? 0));
        if ($remoteRzpOrderId === '') {
            throw new RuntimeException('Razorpay order id missing after provider create.');
        }

        $conn->begin_transaction();
        $payLockStmt = $conn->prepare(
            "SELECT razorpay_order_id, razorpay_create_claim_token
             FROM payments WHERE id = ? LIMIT 1 FOR UPDATE"
        );
        $payLockStmt->bind_param('i', $paymentRowId);
        $payLockStmt->execute();
        $lockedPayRow = $payLockStmt->get_result()->fetch_assoc();
        if (!$lockedPayRow || !hash_equals($claimToken, (string) ($lockedPayRow['razorpay_create_claim_token'] ?? ''))) {
            throw new RuntimeException('Razorpay order initialization claim changed unexpectedly.');
        }
        $rzpOrderId = $remoteRzpOrderId;
        $payStmt = $conn->prepare(
            "UPDATE payments
             SET razorpay_order_id = ?, transaction_id = ?,
                 razorpay_create_claim_token = NULL, razorpay_create_claimed_at = NULL
             WHERE id = ?"
        );
        $payStmt->bind_param('ssi', $rzpOrderId, $rzpOrderId, $paymentRowId);
        $payStmt->execute();
        log_order_activity($conn, $orderId, 'payment_session_created', 'system', 0, 'system', 'Razorpay order id: ' . $rzpOrderId);
        $conn->commit();
    } else {
        $conn->commit();
        // Another request owns the short-lived provider call. Wait for its
        // stored result rather than creating a duplicate Razorpay order.
        $rzpOrderId = '';
        for ($waitAttempt = 0; $waitAttempt < 20 && $rzpOrderId === ''; $waitAttempt++) {
            usleep(500000);
            $waitStmt = $conn->prepare("SELECT razorpay_order_id FROM payments WHERE id = ? LIMIT 1");
            $waitStmt->bind_param('i', $paymentRowId);
            $waitStmt->execute();
            $waitRow = $waitStmt->get_result()->fetch_assoc();
            $rzpOrderId = trim((string) ($waitRow['razorpay_order_id'] ?? ''));
        }
        if ($rzpOrderId === '') {
            throw new RuntimeException('Razorpay order initialization is still in progress.');
        }
    }

    $conn->begin_transaction();
    PaymentService::payment_attempt_touch(
        $conn,
        'razorpay',
        $rzpOrderId,
        $orderId,
        $paymentRowId,
        $existingRzpOrderId !== '' || !$ownsCreateClaim ? 'checkout_opened' : 'created',
        'create',
        '',
        '',
        '',
        '',
        '',
        '',
        json_encode(['order_number' => (string) $order['order_number'], 'amount_paise' => $amountPaise], JSON_UNESCAPED_UNICODE),
        $existingRzpOrderId !== '' || !$ownsCreateClaim
    );
    $conn->commit();
} catch (Throwable $e) {
    try {
        $conn->rollback();
    } catch (Throwable $rollbackException) {
        // ignore rollback errors
    }
    if (!empty($ownsCreateClaim) && !empty($claimToken)) {
        try {
            $clearClaim = $conn->prepare(
                "UPDATE payments
                 SET razorpay_create_claim_token = NULL, razorpay_create_claimed_at = NULL
                 WHERE id = ? AND razorpay_create_claim_token = ? AND razorpay_order_id IS NULL"
            );
            $clearClaim->bind_param('is', $paymentRowId, $claimToken);
            $clearClaim->execute();
        } catch (Throwable $clearException) {
            error_log('[razorpay-create] could not clear create claim: ' . $clearException->getMessage());
        }
    }
    error_log('[razorpay] create failed: ' . $e->getMessage());
    flash('error', 'Unable to initialize Razorpay payment. Please try again.');
    redirect('/checkout.php');
}

$metaTitle = SiteContext::title('Razorpay Payment');
include __DIR__ . '/../includes/header.php';
?>

<section class="page-hero"><div class="l-container"><h1>Complete Payment</h1></div></section>

<section class="section-block">
    <div class="l-container">
        <div class="l-grid l-grid--12 u-justify-center">
            <div class="l-col-md-half">
                <div
                    class="surface-panel u-p-4 u-text-center"
                    data-ui-razorpay
                    data-payment-config="<?php echo ui_data_json([
                        'key' => $keyId,
                        'amount' => $amountPaise,
                        'currency' => 'INR',
                        'name' => SiteContext::name(),
                        'description' => 'Order #' . (string) $order['order_number'],
                        'orderId' => $rzpOrderId,
                        'prefill' => [
                            'name' => (string) ($order['customer_name'] ?? ''),
                            'email' => (string) ($order['customer_email'] ?? ''),
                            'contact' => (string) ($order['customer_phone'] ?? ''),
                        ],
                        'preferredMethod' => $preferredOnlineMethod,
                        'csrfToken' => csrf_token(),
                        'verifyUrl' => '/payment/razorpay-verify.php',
                        'failureUrl' => '/payment/razorpay-failure.php',
                        'themeColor' => '#0f766e',
                    ]); ?>"
                >
                    <p class="u-mb-1 u-text-muted">Order</p>
                    <h5 class="u-mb-3"><?php echo e((string) $order['order_number']); ?></h5>
                    <p class="u-text-large u-font-bold u-mb-4"><?php echo e(money((float) $order['total_amount'])); ?></p>
                    <button id="rzpPayBtn" class="ui-button ui-button--primary ui-button--large u-w-full">Pay with Razorpay</button>
                    <p id="rzpPayHint" class="u-text-muted u-text-small u-mt-3">Your order will be marked paid only after secure verification.</p>
                    <div id="rzpPayLoading" class="u-hidden u-mt-3">
                        <div class="ui-spinner ui-spinner--small u-text-primary u-me-2" role="status" aria-hidden="true"></div>
                        <span class="u-text-small u-text-muted">Verifying payment, please wait...</span>
                    </div>
                </div>
            </div>
        </div>
    </div>
</section>

<script src="https://checkout.razorpay.com/v1/checkout.js" defer data-razorpay-sdk></script>

<?php include __DIR__ . '/../includes/footer.php'; ?>
