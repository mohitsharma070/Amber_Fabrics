<?php
require_once __DIR__ . '/../includes/init.php';
require_admin();

$metaTitle = 'Shipping Rates | Admin';
include 'partials/header.php';
?>

<div class="admin-page-header u-flex u-justify-between u-items-center u-mb-4">
    <h1 class="u-mb-0">Shipping Rates</h1>
</div>

<div class="ui-card u-mb-4">
    <div class="ui-card__body">
        <h5 class="u-mb-3">Active Shipping Rules</h5>
        <div class="ui-table-wrap">
            <table class="ui-table ui-table--bordered ui-table--compact u-align-middle u-mb-0">
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

<div class="ui-card u-mb-4">
    <div class="ui-card__body">
        <h5 class="u-mb-2">Provider Mode</h5>
        <p class="u-text-muted u-mb-0">
            Manual shipping rules remain the fallback. Enabled courier plugins may provide live rates through the existing checkout quote flow.
        </p>
    </div>
</div>

<div class="ui-card u-mb-4">
    <div class="ui-card__body">
        <h5 class="u-mb-2">Test Live Courier Rate</h5>
        <p class="u-text-muted u-text-small u-mb-3">Enter a delivery pincode and order value to request a real Bigship quote. This check does not create an order or save a shipping quote.</p>
        <form id="live-rate-test-form" class="l-grid l-grid--12 u-gap-3" data-admin-live-rate data-endpoint="shipping-rate-test.php" data-csrf-token="<?php echo e(csrf_token()); ?>" novalidate>
            <div class="l-col-md-third">
                <label for="live_rate_pincode" class="ui-label">Delivery Pincode</label>
                <input type="text" class="ui-input" id="live_rate_pincode" inputmode="numeric" maxlength="6" pattern="[1-9][0-9]{5}" placeholder="e.g. 400001" required>
            </div>
            <div class="l-col-md-quarter">
                <label for="live_rate_subtotal" class="ui-label">Order Subtotal</label>
                <input type="number" class="ui-input" id="live_rate_subtotal" min="1" step="0.01" value="1000" required>
            </div>
            <div class="l-col-md-quarter">
                <label for="live_rate_payment" class="ui-label">Payment Method</label>
                <select class="ui-select" id="live_rate_payment">
                    <option value="razorpay">Prepaid</option>
                    <option value="cod">Cash on Delivery</option>
                </select>
            </div>
            <div class="l-col-md-two u-flex u-items-end">
                <button type="submit" class="ui-button ui-button--primary u-w-full" id="live_rate_test_button">Check Live Rate</button>
            </div>
        </form>
        <div id="live_rate_test_result" class="ui-alert u-hidden u-mt-3 u-mb-0" role="status"></div>
    </div>
</div>

<?php do_action('admin.shipping_rates.after', ['conn' => $conn]); ?>

<?php include 'partials/footer.php'; ?>
