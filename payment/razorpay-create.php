<?php
require_once __DIR__ . '/../includes/init.php';
require_once __DIR__ . '/../includes/customer-auth.php';
require_once __DIR__ . '/../vendor/autoload.php';

require_customer();

if (empty($_SESSION['pending_order_id'])) {
    flash('error', 'No pending Razorpay order found.');
    redirect('/checkout.php');
}

$orderId = (int) $_SESSION['pending_order_id'];
$orderNumber = (string) ($_SESSION['pending_order_number'] ?? '');
$customerId = (int) ($_SESSION['customer_id'] ?? 0);

$stmt = $conn->prepare(
    "SELECT id, order_number, customer_name, customer_email, customer_phone, total_amount, payment_method, payment_status
     FROM orders
     WHERE id = ? AND customer_id = ? AND payment_method = 'razorpay' AND payment_status = 'pending'
     LIMIT 1"
);
$stmt->bind_param('ii', $orderId, $customerId);
$stmt->execute();
$order = $stmt->get_result()->fetch_assoc();

if (!$order) {
    flash('error', 'Order not available for Razorpay payment.');
    redirect('/checkout.php');
}

$keyId = (string) (getenv('RAZORPAY_KEY_ID') ?: '');
$keySecret = (string) (getenv('RAZORPAY_KEY_SECRET') ?: '');
if ($keyId === '' || $keySecret === '') {
    flash('error', 'Razorpay configuration is missing. Please contact support.');
    redirect('/checkout.php');
}

$orderAmount = (float) $order['total_amount'];
if ($orderAmount <= 0 || $orderAmount > 999999.99) {
    error_log('[razorpay] invalid order amount ' . $orderAmount . ' for order_id=' . $orderId);
    flash('error', 'Invalid order amount. Please contact support.');
    redirect('/checkout.php');
}
$amountPaise = (int) round($orderAmount * 100);
if ($amountPaise <= 0) {
    flash('error', 'Invalid order amount. Please contact support.');
    redirect('/checkout.php');
}

try {
    $api = new Razorpay\Api\Api($keyId, $keySecret);
    $rzpOrder = $api->order->create([
        'amount' => $amountPaise,
        'currency' => 'INR',
        'receipt' => $order['order_number'],
        'payment_capture' => 1,
        'notes' => [
            'local_order_id' => (string) $orderId,
            'order_number' => (string) $order['order_number'],
        ],
    ]);
    $rzpOrderId = (string) $rzpOrder['id'];

    $payStmt = $conn->prepare(
        "UPDATE payments
         SET razorpay_order_id = ?, transaction_id = ?
         WHERE order_id = ? AND payment_method = 'razorpay'"
    );
    $payStmt->bind_param('ssi', $rzpOrderId, $rzpOrderId, $orderId);
    $payStmt->execute();
} catch (Throwable $e) {
    error_log('[razorpay] create failed: ' . $e->getMessage());
    flash('error', 'Unable to initialize Razorpay payment. Please try again.');
    redirect('/checkout.php');
}

$metaTitle = 'Razorpay Payment | Amber Fabrics';
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
                    <p class="fs-4 fw-bold mb-4">Rs <?php echo number_format((float) $order['total_amount'], 2); ?></p>
                    <button id="rzpPayBtn" class="btn btn-primary btn-lg w-100">Pay with Razorpay</button>
                    <p class="text-muted small mt-3">Your order will be marked paid only after secure verification.</p>
                </div>
            </div>
        </div>
    </div>
</section>

<script src="https://checkout.razorpay.com/v1/checkout.js"></script>
<script nonce="<?php echo $cspNonce; ?>">
function postTo(url, payload) {
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
    name: 'Amber Fabrics',
    description: 'Order #<?php echo e((string) $order['order_number']); ?>',
    order_id: <?php echo json_encode($rzpOrderId); ?>,
    prefill: {
        name: <?php echo json_encode((string) ($order['customer_name'] ?? '')); ?>,
        email: <?php echo json_encode((string) ($order['customer_email'] ?? '')); ?>,
        contact: <?php echo json_encode((string) ($order['customer_phone'] ?? '')); ?>
    },
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
            postTo('/payment/razorpay-failure.php', {
                event_type: 'cancelled',
                razorpay_order_id: <?php echo json_encode($rzpOrderId); ?>
            });
        }
    }
};

document.getElementById('rzpPayBtn').addEventListener('click', function () {
    var rzp = new Razorpay(options);
    rzp.on('payment.failed', function (response) {
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
});
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>
