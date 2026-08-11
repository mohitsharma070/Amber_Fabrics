<?php
require_once __DIR__ . '/../includes/init.php';
require_admin();

if (!function_exists('shipping_courier_bigship_client')) {
    flash('error', 'The shipping courier plugin is unavailable.');
    redirect('shipping-rates.php');
}

$result = null;
$action = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf()) {
        flash('error', 'Invalid token. Please try again.');
        redirect('bigship-service.php');
    }

    $action = trim((string) ($_POST['action'] ?? ''));
    $client = shipping_courier_bigship_client();
    if ($action === 'test_connection') {
        $result = $client->profile();
    } elseif ($action === 'sync_reference_data') {
        $result = shipping_courier_bigship_sync_reference_data($conn);
    } elseif (in_array($action, ['save_warehouse', 'edit_warehouse'], true)) {
        $json = trim((string) ($_POST['warehouse_payload'] ?? ''));
        $payload = json_decode($json, true);
        if (!is_array($payload) || array_is_list($payload)) {
            $result = shipping_courier_result(false, 'Warehouse payload must be a valid JSON object.');
        } else {
            $result = $action === 'save_warehouse'
                ? $client->saveWarehouse($payload)
                : $client->editWarehouse($payload);
        }
    } elseif ($action === 'list_warehouses') {
        $result = $client->warehouses();
    } elseif ($action === 'list_packages') {
        $result = $client->packages();
    } else {
        $result = shipping_courier_result(false, 'Unsupported Bigship action.');
    }
}

function bigship_admin_redact_response($value, string $key = '')
{
    $normalizedKey = strtolower(trim($key));
    if ($normalizedKey === 'raw_body') {
        return '[hidden]';
    }
    if (preg_match('/(?:password|secret|access[_-]?key|token|authorization)/i', $normalizedKey)) {
        return '[hidden]';
    }
    if (!is_array($value)) {
        return $value;
    }
    $safe = [];
    foreach ($value as $childKey => $childValue) {
        $safe[$childKey] = bigship_admin_redact_response($childValue, (string) $childKey);
    }
    return $safe;
}

$metaTitle = 'Bigship Service | Admin';
include 'partials/header.php';
$settings = shipping_courier_settings();
$segment = shipping_courier_bigship_segment($settings);
$safeResult = is_array($result) ? bigship_admin_redact_response($result) : null;
?>

<div class="admin-page-header d-flex justify-content-between align-items-center mb-4">
    <div>
        <h1 class="mb-1">Bigship Service</h1>
        <p class="text-muted mb-0">Connection, reference data and warehouse operations for the Unified Outbound API.</p>
    </div>
    <a href="shipping-rates.php" class="btn btn-outline-secondary">Back to Shipping</a>
</div>

<?php if (is_array($safeResult)): ?>
    <div class="alert <?php echo !empty($safeResult['ok']) ? 'alert-success' : 'alert-danger'; ?>">
        <strong><?php echo !empty($safeResult['ok']) ? 'Success' : 'Failed'; ?>:</strong>
        <?php echo e((string) ($safeResult['message'] ?? 'Bigship operation completed.')); ?>
        <?php if (isset($safeResult['status']) && (int) $safeResult['status'] > 0): ?>
            <span class="ms-2">HTTP <?php echo (int) $safeResult['status']; ?></span>
        <?php endif; ?>
    </div>
    <?php if (is_array($safeResult['body'] ?? null) || is_array($safeResult['responses'] ?? null)): ?>
        <div class="card mb-4">
            <div class="card-body">
                <h5>Provider response</h5>
                <pre class="small bg-light border rounded p-3 mb-0" style="max-height: 28rem; overflow:auto"><?php
                    echo e((string) json_encode($safeResult['body'] ?? $safeResult['responses'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
                ?></pre>
            </div>
        </div>
    <?php endif; ?>
<?php endif; ?>

<div class="row g-4">
    <div class="col-lg-6">
        <div class="card h-100">
            <div class="card-body">
                <h5>Connection and master data</h5>
                <p class="text-muted small">Active segment: <strong><?php echo e($segment); ?></strong>. Reference responses are cached for 12 hours.</p>
                <div class="d-flex flex-wrap gap-2">
                    <form method="post"><?php echo csrf_field(); ?><input type="hidden" name="action" value="test_connection"><button class="btn btn-primary" type="submit">Test Profile</button></form>
                    <form method="post"><?php echo csrf_field(); ?><input type="hidden" name="action" value="sync_reference_data"><button class="btn btn-outline-primary" type="submit">Sync All Reference Data</button></form>
                    <form method="post"><?php echo csrf_field(); ?><input type="hidden" name="action" value="list_warehouses"><button class="btn btn-outline-secondary" type="submit">List Warehouses</button></form>
                    <?php if ($segment === 'hyperlocal'): ?>
                        <form method="post"><?php echo csrf_field(); ?><input type="hidden" name="action" value="list_packages"><button class="btn btn-outline-secondary" type="submit">List Packages</button></form>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <div class="col-lg-6">
        <div class="card h-100">
            <div class="card-body">
                <h5>Create or edit warehouse</h5>
                <p class="text-muted small">Paste the warehouse JSON object supplied by Bigship. Edit requests must include the provider warehouse identifier.</p>
                <form method="post">
                    <?php echo csrf_field(); ?>
                    <label for="warehouse_payload" class="form-label">Warehouse payload</label>
                    <textarea class="form-control font-monospace" id="warehouse_payload" name="warehouse_payload" rows="9" required><?php echo e((string) ($_POST['warehouse_payload'] ?? "{\n  \"name\": \"Main Warehouse\"\n}")); ?></textarea>
                    <div class="d-flex gap-2 mt-3">
                        <button class="btn btn-success" type="submit" name="action" value="save_warehouse">Create Warehouse</button>
                        <button class="btn btn-outline-primary" type="submit" name="action" value="edit_warehouse">Update Warehouse</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<?php include 'partials/footer.php'; ?>
