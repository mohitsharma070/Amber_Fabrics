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

<div class="admin-page-header u-flex u-justify-between u-items-center u-mb-4">
    <div>
        <h1 class="u-mb-1">Bigship Service</h1>
        <p class="u-text-muted u-mb-0">Connection, reference data and warehouse operations for the Unified Outbound API.</p>
    </div>
    <a href="shipping-rates.php" class="ui-button ui-button--secondary">Back to Shipping</a>
</div>

<?php if (is_array($safeResult)): ?>
    <div class="ui-alert <?php echo !empty($safeResult['ok']) ? 'ui-alert--success' : 'ui-alert--error'; ?>">
        <strong><?php echo !empty($safeResult['ok']) ? 'Success' : 'Failed'; ?>:</strong>
        <?php echo e((string) ($safeResult['message'] ?? 'Bigship operation completed.')); ?>
        <?php if (isset($safeResult['status']) && (int) $safeResult['status'] > 0): ?>
            <span class="u-ms-2">HTTP <?php echo (int) $safeResult['status']; ?></span>
        <?php endif; ?>
    </div>
    <?php if (is_array($safeResult['body'] ?? null) || is_array($safeResult['responses'] ?? null)): ?>
        <div class="ui-card u-mb-4">
            <div class="ui-card__body">
                <h5>Provider response</h5>
                <?php if (is_array($safeResult['responses'] ?? null)): ?>
                    <?php
                    $orderedResponses = [];
                    foreach ([false, true] as $wantedOk) {
                        foreach ((array) $safeResult['responses'] as $responseName => $responseData) {
                            $responseOk = is_array($responseData) && !empty($responseData['ok']);
                            if ($responseOk === $wantedOk) {
                                $orderedResponses[(string) $responseName] = $responseData;
                            }
                        }
                    }
                    ?>
                    <div class="u-flex u-flex-column u-gap-2">
                        <?php foreach ($orderedResponses as $responseName => $responseData): ?>
                            <?php
                            $responseData = is_array($responseData) ? $responseData : [];
                            $responseOk = !empty($responseData['ok']);
                            $responseStatus = max(0, (int) ($responseData['status'] ?? 0));
                            $responseBody = is_array($responseData['body'] ?? null) ? (array) $responseData['body'] : [];
                            $responseMessage = trim((string) ($responseBody['message'] ?? $responseData['message'] ?? ''));
                            ?>
                            <details class="u-border u-rounded u-p-3"<?php echo $responseOk ? '' : ' open'; ?>>
                                <summary class="admin-disclosure-summary u-flex u-flex-wrap u-items-center u-gap-2">
                                    <strong><?php echo e(ucwords(str_replace('_', ' ', $responseName))); ?></strong>
                                    <span class="ui-badge <?php echo $responseOk ? 'ui-badge--success' : 'ui-badge--error'; ?>"><?php echo $responseOk ? 'Success' : 'Failed'; ?></span>
                                    <?php if ($responseStatus > 0): ?><span class="u-text-muted u-text-small">HTTP <?php echo $responseStatus; ?></span><?php endif; ?>
                                    <?php if ($responseMessage !== ''): ?><span class="u-text-small"><?php echo e($responseMessage); ?></span><?php endif; ?>
                                </summary>
                                <pre class="admin-code-output admin-code-output--compact u-text-small u-bg-soft u-border u-rounded u-p-3 u-mt-3 u-mb-0"><?php
                                    echo e((string) json_encode($responseData, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
                                ?></pre>
                            </details>
                        <?php endforeach; ?>
                    </div>
                <?php else: ?>
                    <pre class="admin-code-output u-text-small u-bg-soft u-border u-rounded u-p-3 u-mb-0"><?php
                        echo e((string) json_encode($safeResult['body'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
                    ?></pre>
                <?php endif; ?>
            </div>
        </div>
    <?php endif; ?>
<?php endif; ?>

<div class="l-grid l-grid--12 u-gap-4">
    <div class="l-col-lg-half">
        <div class="ui-card u-h-full">
            <div class="ui-card__body">
                <h5>Connection and master data</h5>
                <p class="u-text-muted u-text-small">Active segment: <strong><?php echo e($segment); ?></strong>. Reference responses are cached for 12 hours.</p>
                <div class="u-flex u-flex-wrap u-gap-2">
                    <form method="post"><?php echo csrf_field(); ?><input type="hidden" name="action" value="test_connection"><button class="ui-button ui-button--primary" type="submit">Test Profile</button></form>
                    <form method="post"><?php echo csrf_field(); ?><input type="hidden" name="action" value="sync_reference_data"><button class="ui-button ui-button--outline" type="submit">Sync All Reference Data</button></form>
                    <form method="post"><?php echo csrf_field(); ?><input type="hidden" name="action" value="list_warehouses"><button class="ui-button ui-button--secondary" type="submit">List Warehouses</button></form>
                    <?php if ($segment === 'hyperlocal'): ?>
                        <form method="post"><?php echo csrf_field(); ?><input type="hidden" name="action" value="list_packages"><button class="ui-button ui-button--secondary" type="submit">List Packages</button></form>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <div class="l-col-lg-half">
        <div class="ui-card u-h-full">
            <div class="ui-card__body">
                <h5>Create or edit warehouse</h5>
                <p class="u-text-muted u-text-small">Paste the warehouse JSON object supplied by Bigship. Edit requests must include the provider warehouse identifier.</p>
                <form method="post">
                    <?php echo csrf_field(); ?>
                    <label for="warehouse_payload" class="ui-label">Warehouse payload</label>
                    <textarea class="ui-input u-font-mono" id="warehouse_payload" name="warehouse_payload" rows="9" required><?php echo e((string) ($_POST['warehouse_payload'] ?? "{\n  \"name\": \"Main Warehouse\"\n}")); ?></textarea>
                    <div class="u-flex u-gap-2 u-mt-3">
                        <button class="ui-button ui-button--success" type="submit" name="action" value="save_warehouse">Create Warehouse</button>
                        <button class="ui-button ui-button--outline" type="submit" name="action" value="edit_warehouse">Update Warehouse</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<?php include 'partials/footer.php'; ?>
