<?php

function shipping_courier_render_admin_panel(array $context): void
{
    $settings = shipping_courier_settings();
    $provider = (string) ($settings['provider'] ?? '');
    $isEnabled = (int) ($settings['enabled'] ?? 0) === 1;
    $isConfigured = shipping_courier_provider_configured();
    $shipment = null;
    $metadata = null;
    $conn = $context['conn'] ?? null;
    $order = is_array($context['order'] ?? null) ? $context['order'] : [];
    $orderId = (int) ($order['id'] ?? 0);
    if ($conn instanceof mysqli && $orderId > 0 && $provider !== '') {
        $shipment = shipping_courier_get_shipment($conn, $orderId);
        $shipmentId = (int) ($shipment['id'] ?? 0);
        if ($shipmentId > 0) {
            $metadata = shipping_courier_get_metadata($conn, $shipmentId, $provider);
        }
    }
    $awbCode = trim((string) ($shipment['awb_code'] ?? ''));
    $trackingId = trim((string) ($shipment['tracking_id'] ?? ''));
    $trackingUrl = ExternalUrlPolicy::sanitize((string) ($shipment['tracking_url'] ?? ''));
    $labelUrl = ExternalUrlPolicy::sanitize((string) ($metadata['label_url'] ?? ''));
    $providerStatus = trim((string) ($metadata['provider_status'] ?? ''));
    $lastSync = trim((string) ($metadata['updated_at'] ?? ''));
    $canCreate = $conn instanceof mysqli && $orderId > 0 && shipping_courier_can_create_from_order($order, $metadata);
    $canSync = $conn instanceof mysqli && $orderId > 0 && shipping_courier_can_sync_tracking($shipment, $metadata);
    $canCancel = $conn instanceof mysqli && $orderId > 0 && shipping_courier_can_cancel_from_order($order, $metadata);
    ?>
    <section class="ui-card u-mb-4" aria-label="Shipping courier">
        <div class="ui-card__body">
            <h2 class="u-heading-6">Shipping Courier</h2>
            <div class="u-text-small u-text-muted">
                <div>Status: <strong><?php echo $isEnabled ? 'Enabled' : 'Disabled'; ?></strong></div>
                <div>Provider: <strong><?php echo e($provider !== '' ? $provider : '-'); ?></strong></div>
                <div>Mode: <strong><?php echo !empty($settings['test_mode']) ? 'Test' : 'Live'; ?></strong></div>
                <div>API: <strong><?php echo $isConfigured ? 'Configured' : 'Not configured'; ?></strong></div>
                <div>Auto Create: <strong><?php echo !empty($settings['auto_create']) ? 'On' : 'Off'; ?></strong></div>
                <div>Tracking Sync: <strong><?php echo !empty($settings['tracking_sync']) ? 'On' : 'Off'; ?></strong></div>
                <div>AWB: <strong><?php echo e($awbCode !== '' ? $awbCode : '-'); ?></strong></div>
                <div>
                    Tracking:
                    <strong><?php echo e($trackingId !== '' ? $trackingId : '-'); ?></strong>
                    <?php if ($trackingUrl !== ''): ?>
                        <a href="<?php echo e($trackingUrl); ?>" target="_blank" rel="noopener noreferrer">Track</a>
                    <?php endif; ?>
                </div>
                <?php if ($labelUrl !== ''): ?>
                    <div>Label: <a href="<?php echo e($labelUrl); ?>" target="_blank" rel="noopener noreferrer">Open</a></div>
                <?php endif; ?>
                <div>Last Sync: <strong><?php echo e($providerStatus !== '' ? $providerStatus : 'Not synced'); ?></strong><?php echo $lastSync !== '' ? ' <span>(' . e($lastSync) . ')</span>' : ''; ?></div>
            </div>
            <?php if ($canCreate || $canSync || $canCancel): ?>
                <div class="u-grid u-gap-2 u-mt-3">
                    <?php if ($canCreate): ?>
                    <form method="POST" action="order-view.php?id=<?php echo $orderId; ?>" enctype="multipart/form-data" class="u-border u-rounded u-p-2">
                        <?php echo csrf_field(); ?>
                        <input type="hidden" name="action" value="upload_courier_document">
                        <select class="ui-select ui-select--small u-mb-2" name="document_type" aria-label="Courier document type">
                            <option value="invoice">Invoice PDF</option>
                            <option value="eway_bill">E-way bill PDF</option>
                        </select>
                        <input class="ui-input ui-input--small u-mb-2" type="file" name="courier_document" accept="application/pdf,.pdf" required>
                        <button class="ui-button ui-button--secondary ui-button--small u-w-full" type="submit">Upload Courier Document</button>
                    </form>
                    <form method="POST" action="order-view.php?id=<?php echo $orderId; ?>">
                        <?php echo csrf_field(); ?>
                        <input type="hidden" name="action" value="create_courier_shipment">
                        <button class="ui-button ui-button--outline ui-button--small u-w-full" type="submit">Create Courier Shipment</button>
                    </form>
                    <?php endif; ?>
                    <?php if ($canSync): ?>
                    <form method="POST" action="order-view.php?id=<?php echo $orderId; ?>">
                        <?php echo csrf_field(); ?>
                        <input type="hidden" name="action" value="sync_courier_tracking">
                        <button class="ui-button ui-button--secondary ui-button--small u-w-full" type="submit">Sync Tracking</button>
                    </form>
                    <?php endif; ?>
                    <?php if ($canCancel): ?>
                    <form method="POST" action="order-view.php?id=<?php echo $orderId; ?>" data-confirm-modal data-confirm-title="Cancel Courier Shipment" data-confirm-message="Cancel this shipment with the courier provider?" data-confirm-ok="Cancel Shipment" data-confirm-variant="danger">
                        <?php echo csrf_field(); ?>
                        <input type="hidden" name="action" value="cancel_courier_shipment">
                        <button class="ui-button ui-button--danger-outline ui-button--small u-w-full" type="submit">Cancel Shipment</button>
                    </form>
                    <?php endif; ?>
                    <?php if (shipping_courier_provider_shipment_exists($metadata)): ?>
                        <?php foreach (['label' => 'Label', 'invoice' => 'Invoice', 'manifest' => 'Manifest'] as $documentType => $documentLabel): ?>
                        <form method="POST" action="order-view.php?id=<?php echo $orderId; ?>" target="_blank">
                            <?php echo csrf_field(); ?>
                            <input type="hidden" name="action" value="download_courier_document">
                            <input type="hidden" name="document_type" value="<?php echo e($documentType); ?>">
                            <button class="ui-button ui-button--secondary ui-button--small u-w-full" type="submit">Open <?php echo e($documentLabel); ?></button>
                        </form>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </div>
    </section>
    <?php
}

function shipping_courier_render_shipping_rates_status(array $context): void
{
    $settings = shipping_courier_settings();
    $provider = (string) ($settings['provider'] ?? '');
    $readiness = shipping_courier_live_rate_readiness();
    ?>
    <section class="ui-card u-mb-4" aria-label="Courier rate quotes">
        <div class="ui-card__body">
            <h2 class="u-heading-5 u-mb-2">Courier Rate Quotes</h2>
            <div class="u-text-small u-text-muted">
                <div>Status: <strong><?php echo shipping_courier_enabled() ? 'Enabled' : 'Disabled'; ?></strong></div>
                <div>Provider: <strong><?php echo e($provider !== '' ? $provider : '-'); ?></strong></div>
                <div>API credentials: <strong><?php echo shipping_courier_provider_configured() ? 'Configured' : 'Not configured'; ?></strong></div>
                <div>Live quote readiness: <strong class="<?php echo !empty($readiness['ready']) ? 'u-text-success' : 'u-text-warning'; ?>"><?php echo !empty($readiness['ready']) ? 'Ready' : 'Needs setup'; ?></strong></div>
                <?php foreach ((array) ($readiness['issues'] ?? []) as $issue): ?>
                    <div class="u-text-warning"><?php echo e((string) $issue); ?></div>
                <?php endforeach; ?>
                <div>Fallback: <strong>Manual shipping rules</strong></div>
                <?php if (shipping_courier_provider_configured()): ?>
                    <div class="u-mt-3"><a class="ui-button ui-button--outline ui-button--small" href="bigship-service.php">Manage Bigship Service</a></div>
                <?php endif; ?>
            </div>
        </div>
    </section>
    <?php
}

function shipping_courier_handle_admin_action($handled, array $context)
{
    if ($handled) {
        return $handled;
    }

    $action = (string) ($context['action'] ?? '');
    if (!in_array($action, ['create_courier_shipment', 'sync_courier_tracking', 'cancel_courier_shipment', 'download_courier_document', 'upload_courier_document'], true)) {
        return false;
    }

    $conn = $context['conn'] ?? null;
    $orderId = (int) ($context['order_id'] ?? 0);
    if (!$conn instanceof mysqli || $orderId <= 0) {
        flash('error', 'Unable to run courier action for this order.');
        return true;
    }

    if (!shipping_courier_enabled()) {
        flash('error', 'Shipping courier plugin is disabled.');
        return true;
    }

    if ($action === 'upload_courier_document') {
        $documentType = strtolower(trim((string) (($context['post']['document_type'] ?? ''))));
        $result = shipping_courier_bigship_save_document_upload($orderId, $documentType, (array) ($_FILES['courier_document'] ?? []));
        flash(!empty($result['ok']) ? 'success' : 'error', (string) ($result['message'] ?? 'Courier document upload failed.'));
        return true;
    }

    if ($action === 'download_courier_document') {
        $allowedTypes = ['label', 'invoice', 'manifest'];
        $documentType = strtolower(trim((string) (($context['post']['document_type'] ?? 'label'))));
        if (!in_array($documentType, $allowedTypes, true)) {
            flash('error', 'Invalid courier document type.');
            return true;
        }
        $shipment = shipping_courier_get_shipment($conn, $orderId);
        $metadata = is_array($shipment)
            ? shipping_courier_get_metadata($conn, (int) ($shipment['id'] ?? 0), shipping_courier_provider_name())
            : null;
        $customGlobalOrderId = trim((string) ($metadata['provider_order_id'] ?? ''));
        $url = shipping_courier_bigship_download_document_url($customGlobalOrderId, $documentType);
        if ($url === '') {
            flash('error', 'Bigship did not return the requested document.');
            return true;
        }
        redirect($url);
    }

    try {
        if ($action === 'create_courier_shipment') {
            $result = shipping_courier_create_shipment($conn, $orderId);
        } elseif ($action === 'sync_courier_tracking') {
            $result = shipping_courier_sync_tracking($conn, $orderId);
        } else {
            $result = shipping_courier_cancel_shipment($conn, $orderId);
        }
    } catch (Throwable $e) {
        error_log('[shipping-courier] admin action failed for order ' . $orderId . ': ' . $e->getMessage());
        $result = shipping_courier_result(false, 'Courier action failed safely. Manual shipment flow is still available.');
    }

    if (!empty($result['ok'])) {
        if (function_exists('log_order_activity')) {
            $adminId = (int) ($_SESSION['admin_id'] ?? 0);
            $adminName = (string) ($_SESSION['admin_name'] ?? 'admin');
            log_order_activity(
                $conn,
                $orderId,
                $action,
                'admin',
                $adminId,
                $adminName,
                (string) ($result['message'] ?? 'Courier action completed.')
            );
        }
        flash('success', (string) ($result['message'] ?? 'Courier action completed.'));
    } else {
        flash('error', (string) ($result['message'] ?? 'Courier action failed safely. Manual shipment flow is still available.'));
    }

    return true;
}
