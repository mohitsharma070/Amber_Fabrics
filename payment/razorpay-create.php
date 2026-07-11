<?php
require_once __DIR__ . '/../includes/init.php';
require_once __DIR__ . '/../includes/customer-auth.php';

require_customer();

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
$customerId = (int) ($_SESSION['customer_id'] ?? 0);
PaymentService::release_stale_pending_razorpay_orders_for_customer($conn, $customerId, 30);
$preferredOnlineMethod = InventoryService::sanitize_online_payment_method((string) ($_SESSION['pending_online_method'] ?? ''));

$stmt = $conn->prepare(
    "SELECT id, order_number, customer_name, customer_email, customer_phone, total_amount, payment_method, payment_status, order_status, created_at
     FROM orders
     WHERE id = ? AND customer_id = ? AND payment_method = 'razorpay' AND payment_status IN ('pending','failed')
     LIMIT 1"
);
$stmt->bind_param('ii', $orderId, $customerId);
$stmt->execute();
$order = $stmt->get_result()->fetch_assoc();

if (!$order) {
    flash('error', 'Order not available for Razorpay payment.');
    redirect('/checkout.php');
}
if (!in_array((string) ($order['order_status'] ?? ''), ['pending', 'confirmed'], true)) {
    flash('error', 'Order is not in a payable state.');
    redirect('/customer/orders.php');
}
if (strtotime((string) ($order['created_at'] ?? 'now')) < strtotime('-30 minutes')) {
    flash('error', 'This payment session has expired. Please place a new order.');
    redirect('/customer/orders.php');
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
    $paymentRowStmt = $conn->prepare(
        "SELECT id, razorpay_order_id
         FROM payments
         WHERE order_id = ? AND payment_method = 'razorpay'
         LIMIT 1"
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

    if ($existingRzpOrderId === '') {
        $createResp = PaymentService::razorpay_create_order_remote($orderId, (string) $order['order_number'], $amountPaise);
        if (empty($createResp['ok'])) {
            $providerError = (string) ($createResp['error'] ?? 'gateway_create_failed');
            $durationMs = (int) ($createResp['duration_ms'] ?? 0);
            error_log('[razorpay-create] provider create failed order_id=' . $orderId . ' error=' . $providerError . ' duration_ms=' . $durationMs);
            throw new RuntimeException('Razorpay create failed: ' . $providerError);
        }
        $remoteRzpOrderId = trim((string) ($createResp['id'] ?? ''));
        error_log('[razorpay-create] provider create success order_id=' . $orderId . ' rzp_order_id=' . $remoteRzpOrderId . ' duration_ms=' . (int) ($createResp['duration_ms'] ?? 0));
    }

    $conn->begin_transaction();
    $payLockStmt = $conn->prepare(
        "SELECT id, razorpay_order_id
         FROM payments
         WHERE order_id = ? AND payment_method = 'razorpay'
         LIMIT 1 FOR UPDATE"
    );
    $payLockStmt->bind_param('i', $orderId);
    $payLockStmt->execute();
    $lockedPayRow = $payLockStmt->get_result()->fetch_assoc();
    if (!$lockedPayRow) {
        throw new RuntimeException('Payment row not found during payment create finalize.');
    }

    $lockedRzpOrderId = trim((string) ($lockedPayRow['razorpay_order_id'] ?? ''));
    if ($lockedRzpOrderId !== '') {
        $rzpOrderId = $lockedRzpOrderId;
    } else {
        if ($remoteRzpOrderId === '') {
            throw new RuntimeException('Razorpay order id missing after provider create.');
        }
        $rzpOrderId = $remoteRzpOrderId;
        $payStmt = $conn->prepare(
            "UPDATE payments
             SET razorpay_order_id = ?, transaction_id = ?
             WHERE order_id = ? AND payment_method = 'razorpay'"
        );
        $payStmt->bind_param('ssi', $rzpOrderId, $rzpOrderId, $orderId);
        $payStmt->execute();
        log_order_activity($conn, $orderId, 'payment_session_created', 'system', 0, 'system', 'Razorpay order id: ' . $rzpOrderId);
    }

    PaymentService::payment_attempt_touch(
        $conn,
        'razorpay',
        $rzpOrderId,
        $orderId,
        $paymentRowId,
        $existingRzpOrderId !== '' ? 'checkout_opened' : 'created',
        'create',
        '',
        '',
        '',
        '',
        '',
        '',
        json_encode(['order_number' => (string) $order['order_number'], 'amount_paise' => $amountPaise], JSON_UNESCAPED_UNICODE),
        $existingRzpOrderId !== ''
    );
    $conn->commit();
} catch (Throwable $e) {
    try {
        $conn->rollback();
    } catch (Throwable $rollbackException) {
        // ignore rollback errors
    }
    error_log('[razorpay] create failed: ' . $e->getMessage());
    flash('error', 'Unable to initialize Razorpay payment. Please try again.');
    redirect('/checkout.php');
}

$metaTitle = SiteContext::title('Razorpay Payment');
include __DIR__ . '/../includes/header.php';
?>

<section class="page-hero"><div class="container"><h1>Complete Payment</h1></div></section>

<section class="section-block">
    <div class="container">
        <div class="row justify-content-center">
            <div class="col-md-6">
                <div class="surface-panel p-4 text-center">
                    <p class="mb-1 text-muted">Order</p>
                    <h5 class="mb-3"><?php echo e((string) $order['order_number']); ?></h5>
                    <p class="fs-4 fw-bold mb-4"><?php echo e(money((float) $order['total_amount'])); ?></p>
                    <button id="rzpPayBtn" class="btn btn-primary btn-lg w-100">Pay with Razorpay</button>
                    <p id="rzpPayHint" class="text-muted small mt-3">Your order will be marked paid only after secure verification.</p>
                    <div id="rzpPayLoading" class="d-none mt-3">
                        <div class="spinner-border spinner-border-sm text-primary me-2" role="status" aria-hidden="true"></div>
                        <span class="small text-muted">Verifying payment, please wait...</span>
                    </div>
                </div>
            </div>
        </div>
    </div>
</section>

<script src="https://checkout.razorpay.com/v1/checkout.js"></script>
<script nonce="<?php echo $cspNonce; ?>">
var isSubmitting = false;

function setPayLoadingState(on) {
    var btn = document.getElementById('rzpPayBtn');
    var hint = document.getElementById('rzpPayHint');
    var loading = document.getElementById('rzpPayLoading');
    if (!btn || !hint || !loading) {
        return;
    }
    btn.disabled = !!on;
    btn.textContent = on ? 'Processing Payment...' : 'Pay with Razorpay';
    hint.classList.toggle('d-none', !!on);
    loading.classList.toggle('d-none', !on);
}

function postTo(url, payload) {
    if (isSubmitting) {
        return;
    }
    isSubmitting = true;
    setPayLoadingState(true);

    var form = document.createElement('form');
    form.method = 'POST';
    form.action = url;

    Object.keys(payload).forEach(function (key) {
        var input = document.createElement('input');
        input.type = 'hidden';
        input.name = key;
        input.value = payload[key] == null ? '' : String(payload[key]);
        form.appendChild(input);
    });

    var csrf = document.createElement('input');
    csrf.type = 'hidden';
    csrf.name = 'csrf_token';
    csrf.value = <?php echo json_encode(csrf_token()); ?>;
    form.appendChild(csrf);

    document.body.appendChild(form);
    form.submit();
}

var options = {
    key: <?php echo json_encode($keyId); ?>,
    amount: <?php echo $amountPaise; ?>,
    currency: 'INR',
    name: <?php echo json_encode(SiteContext::name()); ?>,
    description: 'Order #<?php echo e((string) $order['order_number']); ?>',
    order_id: <?php echo json_encode($rzpOrderId); ?>,
    prefill: {
        name: <?php echo json_encode((string) ($order['customer_name'] ?? '')); ?>,
        email: <?php echo json_encode((string) ($order['customer_email'] ?? '')); ?>,
        contact: <?php echo json_encode((string) ($order['customer_phone'] ?? '')); ?>
    },
    method: (function () {
        var pref = <?php echo json_encode($preferredOnlineMethod); ?>;
        if (pref === 'upi') {
            return { upi: true, card: false, netbanking: false, wallet: false, emi: false, paylater: false };
        }
        if (pref === 'card') {
            return { upi: false, card: true, netbanking: false, wallet: false, emi: false, paylater: false };
        }
        if (pref === 'emi') {
            return { upi: false, card: false, netbanking: false, wallet: false, emi: true, paylater: false };
        }
        return undefined;
    })(),
    theme: { color: '#0f766e' },
    handler: function (response) {
        postTo('/payment/razorpay-verify.php', {
            razorpay_payment_id: response.razorpay_payment_id || '',
            razorpay_order_id: response.razorpay_order_id || '',
            razorpay_signature: response.razorpay_signature || ''
        });
    },
    modal: {
        ondismiss: function () {
            if (isSubmitting) {
                return;
            }
            postTo('/payment/razorpay-failure.php', {
                event_type: 'cancelled',
                razorpay_order_id: <?php echo json_encode($rzpOrderId); ?>
            });
        }
    }
};

function openRazorpayCheckout() {
    if (isSubmitting) {
        return;
    }
    var rzp = new Razorpay(options);
    rzp.on('payment.failed', function (response) {
        if (isSubmitting) {
            return;
        }
        var err = response && response.error ? response.error : {};
        postTo('/payment/razorpay-failure.php', {
            event_type: 'failed',
            razorpay_payment_id: err.metadata && err.metadata.payment_id ? err.metadata.payment_id : '',
            razorpay_order_id: err.metadata && err.metadata.order_id ? err.metadata.order_id : <?php echo json_encode($rzpOrderId); ?>,
            error_code: err.code || '',
            error_description: err.description || ''
        });
    });
    rzp.open();
}

document.getElementById('rzpPayBtn').addEventListener('click', function () {
    openRazorpayCheckout();
});

window.addEventListener('load', function () {
    // Auto-open for smoother flow after redirect from checkout.
    setTimeout(openRazorpayCheckout, 250);
});
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>
