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

<?php do_action('admin.shipping_rates.after', ['conn' => $conn]); ?>

<?php include 'partials/footer.php'; ?>
