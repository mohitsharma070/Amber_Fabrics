<?php
require_once __DIR__ . '/../includes/init.php';
require_admin();

$metaTitle = 'Shipping Rates | Admin';
include 'partials/header.php';
?>

<div class="admin-page-header d-flex justify-content-between align-items-center mb-4">
    <h1 class="mb-0">Shipping Rates</h1>
</div>

<div class="card mb-4">
    <div class="card-body">
        <h5 class="mb-3">Active Shipping Rules</h5>
        <div class="table-responsive">
            <table class="table table-bordered table-sm align-middle mb-0">
                <thead>
                    <tr>
                        <th>Condition</th>
                        <th>Base Shipping</th>
                        <th>COD Fee</th>
                        <th>Notes</th>
                    </tr>
                </thead>
                <tbody>
                    <tr>
                        <td>India order subtotal below Rs 999</td>
                        <td>Rs 70.00</td>
                        <td>Rs 50.00 (only for COD)</td>
                        <td>Applied automatically at checkout.</td>
                    </tr>
                    <tr>
                        <td>India order subtotal Rs 999 and above</td>
                        <td>Rs 0.00</td>
                        <td>Rs 50.00 (only for COD)</td>
                        <td>Free shipping threshold.</td>
                    </tr>
                    <tr>
                        <td>Non-India checkout</td>
                        <td>Manual quote flow</td>
                        <td>Not applicable</td>
                        <td>Handled through inquiry workflow.</td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>
</div>

<div class="card mb-4">
    <div class="card-body">
        <h5 class="mb-2">Provider Mode</h5>
        <p class="text-muted mb-0">
            Manual shipping rules remain the fallback. Enabled courier plugins may provide live rates through the existing checkout quote flow.
        </p>
    </div>
</div>

<div class="card mb-4">
    <div class="card-body">
        <h5 class="mb-2">Test Live Courier Rate</h5>
        <p class="text-muted small mb-3">Enter a delivery pincode and order value to request a real Bigship quote. This check does not create an order or save a shipping quote.</p>
        <form id="live-rate-test-form" class="row g-3" novalidate>
            <div class="col-md-4">
                <label for="live_rate_pincode" class="form-label">Delivery Pincode</label>
                <input type="text" class="form-control" id="live_rate_pincode" inputmode="numeric" maxlength="6" pattern="[1-9][0-9]{5}" placeholder="e.g. 400001" required>
            </div>
            <div class="col-md-3">
                <label for="live_rate_subtotal" class="form-label">Order Subtotal</label>
                <input type="number" class="form-control" id="live_rate_subtotal" min="1" step="0.01" value="1000" required>
            </div>
            <div class="col-md-3">
                <label for="live_rate_payment" class="form-label">Payment Method</label>
                <select class="form-select" id="live_rate_payment">
                    <option value="razorpay">Prepaid</option>
                    <option value="cod">Cash on Delivery</option>
                </select>
            </div>
            <div class="col-md-2 d-flex align-items-end">
                <button type="submit" class="btn btn-primary w-100" id="live_rate_test_button">Check Live Rate</button>
            </div>
        </form>
        <div id="live_rate_test_result" class="alert d-none mt-3 mb-0" role="status"></div>
    </div>
</div>

<?php do_action('admin.shipping_rates.after', ['conn' => $conn]); ?>

<script nonce="<?php echo $cspNonce; ?>">
(function () {
    var form = document.getElementById('live-rate-test-form');
    var result = document.getElementById('live_rate_test_result');
    var button = document.getElementById('live_rate_test_button');
    if (!form || !result || !button) return;

    function showResult(kind, message) {
        result.className = 'alert alert-' + kind + ' mt-3 mb-0';
        result.textContent = message;
    }

    form.addEventListener('submit', function (event) {
        event.preventDefault();
        var pincode = String(document.getElementById('live_rate_pincode').value || '').trim();
        var subtotal = String(document.getElementById('live_rate_subtotal').value || '').trim();
        var paymentMethod = String(document.getElementById('live_rate_payment').value || 'razorpay');
        if (!/^[1-9][0-9]{5}$/.test(pincode) || Number(subtotal) <= 0) {
            showResult('warning', 'Enter a valid 6-digit pincode and an order subtotal above zero.');
            return;
        }
        var body = new URLSearchParams();
        body.set('csrf_token', <?php echo json_encode(csrf_token()); ?>);
        body.set('pincode', pincode);
        body.set('subtotal', subtotal);
        body.set('payment_method', paymentMethod);
        button.disabled = true;
        button.textContent = 'Checking…';
        fetch('shipping-rate-test.php', {
            method: 'POST',
            headers: {'Accept': 'application/json', 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8'},
            body: body.toString()
        }).then(function (response) {
            return response.json().catch(function () { return null; });
        }).then(function (data) {
            if (!data || !data.ok) {
                showResult('danger', (data && data.message) ? data.message : 'Unable to check the courier rate.');
                return;
            }
            if (data.live) {
                var courier = data.courier_name ? ' via ' + data.courier_name : '';
                showResult('success', 'Live ' + data.source + ' quote' + courier + ': Rs ' + Number(data.shipping_total).toFixed(2) + '.');
                return;
            }
            var detail = data.debug_message || data.debug_reason || 'No live courier rate was returned.';
            showResult('warning', 'Manual fallback: Rs ' + Number(data.shipping_total).toFixed(2) + '. ' + detail);
        }).catch(function () {
            showResult('danger', 'Unable to check the courier rate. Please try again.');
        }).finally(function () {
            button.disabled = false;
            button.textContent = 'Check Live Rate';
        });
    });
}());
</script>

<?php include 'partials/footer.php'; ?>
