<?php

function shipping_courier_reverse_table_ready(mysqli $conn): bool
{
    try {
        $stmt = $conn->prepare(
            "SELECT COUNT(*) AS total
             FROM information_schema.TABLES
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = 'shipping_courier_reverse_pickups'"
        );
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        return ((int) ($row['total'] ?? 0)) > 0;
    } catch (Throwable $e) {
        error_log('[shipping-courier] reverse pickup table check failed: ' . $e->getMessage());
        return false;
    }
}

function shipping_courier_get_reverse_pickup(mysqli $conn, int $returnId, string $provider): ?array
{
    $provider = strtolower(trim($provider));
    if ($returnId <= 0 || $provider === '' || !shipping_courier_reverse_table_ready($conn)) {
        return null;
    }

    $stmt = $conn->prepare(
        "SELECT id, return_id, order_id, provider, initialization_status, claim_token, claimed_at,
                attempt_count, last_attempt_at, last_error, provider_order_id, provider_pickup_id,
                provider_status, tracking_id, tracking_url, label_url,
                raw_response_json, created_at, updated_at
         FROM shipping_courier_reverse_pickups
         WHERE return_id = ? AND provider = ?
         LIMIT 1"
    );
    $stmt->bind_param('is', $returnId, $provider);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    return $row ?: null;
}

function shipping_courier_upsert_reverse_pickup(
    mysqli $conn,
    int $returnId,
    int $orderId,
    array $metadata
): ?array {
    $provider = shipping_courier_provider_name($metadata);
    if ($returnId <= 0 || $orderId <= 0 || $provider === '' || !shipping_courier_reverse_table_ready($conn)) {
        return null;
    }

    $providerOrderId = trim((string) ($metadata['provider_order_id'] ?? ''));
    $providerPickupId = trim((string) ($metadata['provider_pickup_id'] ?? ''));
    $providerStatus = shipping_courier_normalize_provider_status((string) ($metadata['provider_status'] ?? ''));
    $trackingId = trim((string) ($metadata['tracking_id'] ?? ''));
    $trackingUrl = ExternalUrlPolicy::sanitize((string) ($metadata['tracking_url'] ?? ''));
    $labelUrl = ExternalUrlPolicy::sanitize((string) ($metadata['label_url'] ?? ''));
    $rawResponseJson = shipping_courier_json_value($metadata['raw_response_json'] ?? null);

    $stmt = $conn->prepare(
        "INSERT INTO shipping_courier_reverse_pickups
            (return_id, order_id, provider, initialization_status, provider_order_id, provider_pickup_id,
             provider_status, tracking_id, tracking_url, label_url, raw_response_json)
         VALUES (?, ?, ?, 'created', ?, ?, ?, ?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE
            order_id = VALUES(order_id),
            initialization_status = 'created',
            claim_token = NULL,
            claimed_at = NULL,
            last_error = NULL,
            provider_order_id = COALESCE(NULLIF(VALUES(provider_order_id), ''), provider_order_id),
            provider_pickup_id = COALESCE(NULLIF(VALUES(provider_pickup_id), ''), provider_pickup_id),
            provider_status = COALESCE(NULLIF(VALUES(provider_status), ''), provider_status),
            tracking_id = COALESCE(NULLIF(VALUES(tracking_id), ''), tracking_id),
            tracking_url = COALESCE(NULLIF(VALUES(tracking_url), ''), tracking_url),
            label_url = COALESCE(NULLIF(VALUES(label_url), ''), label_url),
            raw_response_json = COALESCE(VALUES(raw_response_json), raw_response_json),
            updated_at = NOW()"
    );
    $stmt->bind_param(
        'iissssssss',
        $returnId,
        $orderId,
        $provider,
        $providerOrderId,
        $providerPickupId,
        $providerStatus,
        $trackingId,
        $trackingUrl,
        $labelUrl,
        $rawResponseJson
    );
    $stmt->execute();

    return shipping_courier_get_reverse_pickup($conn, $returnId, $provider);
}

function shipping_courier_reverse_payload(mysqli $conn, int $returnId): ?array
{
    if ($returnId <= 0) {
        return null;
    }

    $stmt = $conn->prepare(
        "SELECT r.id AS return_id, r.return_number, r.status AS return_status, r.reason, r.customer_note,
                o.id AS order_id, o.order_number, o.customer_name, o.customer_phone, o.customer_email,
                o.address, o.city, o.state, o.pincode, o.country, o.payment_method
         FROM returns r
         JOIN orders o ON o.id = r.order_id
         WHERE r.id = ?
         LIMIT 1"
    );
    $stmt->bind_param('i', $returnId);
    $stmt->execute();
    $return = $stmt->get_result()->fetch_assoc();
    if (!$return) {
        return null;
    }

    $itemStmt = $conn->prepare(
        "SELECT order_item_id, product_name, unit_type, quantity, line_total
         FROM return_items
         WHERE return_id = ?
         ORDER BY id ASC"
    );
    $itemStmt->bind_param('i', $returnId);
    $itemStmt->execute();
    $items = $itemStmt->get_result()->fetch_all(MYSQLI_ASSOC);

    return [
        'return' => $return,
        'items' => is_array($items) ? $items : [],
        'pickup_address' => [
            'name' => (string) ($return['customer_name'] ?? ''),
            'phone' => (string) ($return['customer_phone'] ?? ''),
            'email' => (string) ($return['customer_email'] ?? ''),
            'address' => (string) ($return['address'] ?? ''),
            'city' => (string) ($return['city'] ?? ''),
            'state' => (string) ($return['state'] ?? ''),
            'pincode' => (string) ($return['pincode'] ?? ''),
            'country' => (string) ($return['country'] ?? ''),
        ],
    ];
}

function shipping_courier_reverse_metadata_from_response(array $body): array
{
    return [
        'provider_order_id' => shipping_courier_response_value($body, ['provider_order_id', 'order_id', 'courier_order_id']),
        'provider_pickup_id' => shipping_courier_response_value($body, ['provider_pickup_id', 'reverse_pickup_id', 'pickup_id', 'shipment_id']),
        'provider_status' => shipping_courier_response_value($body, ['provider_status', 'status', 'pickup_status']),
        'tracking_id' => shipping_courier_response_value($body, ['tracking_id', 'tracking_number', 'awb_code', 'awb', 'waybill']),
        'tracking_url' => shipping_courier_response_value($body, ['tracking_url', 'track_url']),
        'label_url' => shipping_courier_response_value($body, ['label_url', 'label', 'shipping_label_url']),
        'raw_response_json' => $body,
    ];
}

function shipping_courier_reverse_capabilities(): array
{
    $provider = shipping_courier_provider_name();
    $capabilities = [
        'create' => false,
        'cancel' => false,
        'sync' => false,
        'label' => false,
        'webhook' => false,
    ];
    if (!in_array($provider, ['bigship', 'bigship-direct'], true)) {
        $filtered = apply_filters('shipping_courier.reverse.capabilities', $capabilities, ['provider' => $provider]);
        if (is_array($filtered)) {
            foreach ($capabilities as $name => $unused) {
                $capabilities[$name] = !empty($filtered[$name]);
            }
        }
    }
    return $capabilities;
}

function shipping_courier_reverse_supports(string $capability): bool
{
    return !empty(shipping_courier_reverse_capabilities()[strtolower(trim($capability))]);
}

function shipping_courier_claim_reverse_pickup(mysqli $conn, int $returnId, int $orderId, string $provider): array
{
    $token = bin2hex(random_bytes(16));
    $conn->begin_transaction();
    try {
        $insert = $conn->prepare(
            "INSERT IGNORE INTO shipping_courier_reverse_pickups
                (return_id, order_id, provider, initialization_status)
             VALUES (?, ?, ?, 'idle')"
        );
        $insert->bind_param('iis', $returnId, $orderId, $provider);
        $insert->execute();

        $select = $conn->prepare(
            'SELECT provider_pickup_id, initialization_status, claimed_at
             FROM shipping_courier_reverse_pickups
             WHERE return_id = ? AND provider = ? FOR UPDATE'
        );
        $select->bind_param('is', $returnId, $provider);
        $select->execute();
        $row = $select->get_result()->fetch_assoc() ?: [];
        if (trim((string) ($row['provider_pickup_id'] ?? '')) !== '') {
            $conn->commit();
            return ['claimed' => false, 'existing' => true, 'token' => ''];
        }
        $claimingAt = strtotime((string) ($row['claimed_at'] ?? '')) ?: 0;
        if (($row['initialization_status'] ?? '') === 'claiming' && $claimingAt > time() - 900) {
            $conn->commit();
            return ['claimed' => false, 'existing' => false, 'token' => ''];
        }

        $update = $conn->prepare(
            "UPDATE shipping_courier_reverse_pickups
             SET initialization_status='claiming', claim_token=?, claimed_at=NOW(),
                 attempt_count=attempt_count+1, last_attempt_at=NOW(), last_error=NULL
             WHERE return_id=? AND provider=?"
        );
        $update->bind_param('sis', $token, $returnId, $provider);
        $update->execute();
        $conn->commit();
        return ['claimed' => true, 'existing' => false, 'token' => $token];
    } catch (Throwable $e) {
        $conn->rollback();
        throw $e;
    }
}

function shipping_courier_fail_reverse_claim(mysqli $conn, int $returnId, string $provider, string $token, string $error): void
{
    $error = class_exists('CronService') ? CronService::sanitizeError($error) : mb_substr(trim($error), 0, 1000);
    $stmt = $conn->prepare(
        "UPDATE shipping_courier_reverse_pickups
         SET initialization_status='failed', claim_token=NULL, claimed_at=NULL, last_error=?
         WHERE return_id=? AND provider=? AND claim_token=?"
    );
    $stmt->bind_param('siss', $error, $returnId, $provider, $token);
    $stmt->execute();
}

function shipping_courier_create_reverse_pickup(mysqli $conn, int $returnId): array
{
    if (!shipping_courier_enabled()) {
        return shipping_courier_result(false, 'Shipping courier plugin is disabled.');
    }
    if (!shipping_courier_provider_configured()) {
        return shipping_courier_result(false, 'Shipping courier provider is not configured.');
    }
    if (!shipping_courier_reverse_supports('create')) {
        return shipping_courier_result(false, 'This provider has no verified reverse-pickup API. Manual pickup is required.');
    }
    if (!shipping_courier_reverse_table_ready($conn)) {
        return shipping_courier_result(false, 'Reverse pickup metadata table is unavailable.');
    }

    $payload = shipping_courier_reverse_payload($conn, $returnId);
    if (!$payload) {
        return shipping_courier_result(false, 'Return request not found.');
    }

    $return = is_array($payload['return'] ?? null) ? $payload['return'] : [];
    if (strtolower((string) ($return['return_status'] ?? '')) !== 'approved') {
        return shipping_courier_result(false, 'Reverse pickup can be created only for approved returns.');
    }

    $provider = shipping_courier_provider_name();
    $claim = shipping_courier_claim_reverse_pickup($conn, $returnId, (int) ($return['order_id'] ?? 0), $provider);
    if (!$claim['claimed']) {
        $existing = shipping_courier_get_reverse_pickup($conn, $returnId, $provider);
        return !empty($claim['existing'])
            ? shipping_courier_result(true, 'Reverse pickup already exists.', ['reverse_pickup' => $existing])
            : shipping_courier_result(false, 'Reverse pickup initialization is already in progress.');
    }

    $response = apply_filters('shipping_courier.reverse.create_result', null, [
        'conn' => $conn,
        'provider' => $provider,
        'return_id' => $returnId,
        'payload' => $payload,
        'claim_token' => $claim['token'],
    ]);
    if (!is_array($response) || empty($response['ok'])) {
        $message = is_array($response) ? (string) ($response['message'] ?? 'Provider adapter did not create the pickup.') : 'Provider adapter did not return a result.';
        shipping_courier_fail_reverse_claim($conn, $returnId, $provider, (string) $claim['token'], $message);
        error_log('[shipping-courier] reverse pickup failed for return ' . $returnId . ': ' . CronService::sanitizeError($message));
        if (!is_array($response)) {
            return shipping_courier_result(false, $message);
        }
        return $response;
    }

    $body = is_array($response['body'] ?? null) ? $response['body'] : [];
    $reversePickup = shipping_courier_upsert_reverse_pickup(
        $conn,
        $returnId,
        (int) ($return['order_id'] ?? 0),
        array_merge(shipping_courier_reverse_metadata_from_response($body), ['provider' => $provider])
    );

    return shipping_courier_result(true, 'Reverse pickup created.', [
        'reverse_pickup' => $reversePickup,
        'provider_response' => $body,
        'order_id' => (int) ($return['order_id'] ?? 0),
    ]);
}

function shipping_courier_maybe_auto_create_reverse_pickup(mysqli $conn, int $returnId): array
{
    if (!shipping_courier_reverse_supports('create')) {
        return shipping_courier_result(false, 'Manual pickup required.', ['manual' => true]);
    }
    return shipping_courier_create_reverse_pickup($conn, $returnId);
}

function shipping_courier_render_return_actions(array $context): void
{
    $conn = $context['conn'] ?? null;
    $return = is_array($context['return'] ?? null) ? $context['return'] : [];
    $returnId = (int) ($return['id'] ?? 0);
    if (!$conn instanceof mysqli || $returnId <= 0) {
        return;
    }

    $provider = shipping_courier_provider_name();
    $reversePickup = $provider !== '' ? shipping_courier_get_reverse_pickup($conn, $returnId, $provider) : null;
    $providerStatus = trim((string) ($reversePickup['provider_status'] ?? ''));
    $trackingId = trim((string) ($reversePickup['tracking_id'] ?? ''));
    $trackingUrl = ExternalUrlPolicy::sanitize((string) ($reversePickup['tracking_url'] ?? ''));
    $labelUrl = ExternalUrlPolicy::sanitize((string) ($reversePickup['label_url'] ?? ''));
    $canCreate = shipping_courier_enabled()
        && shipping_courier_provider_configured()
        && shipping_courier_reverse_supports('create')
        && strtolower((string) ($return['status'] ?? '')) === 'approved'
        && empty($reversePickup['provider_pickup_id']);
    ?>
    <?php if (is_array($reversePickup)): ?>
        <div class="small text-muted mt-2">
            <div>Reverse pickup: <strong><?php echo e($providerStatus !== '' ? $providerStatus : 'Created'); ?></strong></div>
            <?php if ($trackingId !== ''): ?>
                <div>Tracking: <strong><?php echo e($trackingId); ?></strong><?php if ($trackingUrl !== ''): ?> <a href="<?php echo e($trackingUrl); ?>" target="_blank" rel="noopener noreferrer">Track</a><?php endif; ?></div>
            <?php endif; ?>
            <?php if ($labelUrl !== ''): ?>
                <div><a href="<?php echo e($labelUrl); ?>" target="_blank" rel="noopener noreferrer">Open reverse label</a></div>
            <?php endif; ?>
        </div>
    <?php endif; ?>
    <?php if ($canCreate): ?>
        <form method="POST" action="returns.php" class="mt-2" data-confirm-modal data-confirm-title="Create Reverse Pickup" data-confirm-message="Create a courier reverse pickup for this approved return?" data-confirm-ok="Create Pickup">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="action" value="create_courier_reverse_pickup">
            <input type="hidden" name="return_id" value="<?php echo $returnId; ?>">
            <input type="hidden" name="filter_status" value="<?php echo e((string) ($context['filter_status'] ?? '')); ?>">
            <input type="hidden" name="filter_per_page" value="<?php echo (int) ($context['filter_per_page'] ?? 10); ?>">
            <input type="hidden" name="filter_page" value="<?php echo (int) ($context['filter_page'] ?? 1); ?>">
            <button type="submit" class="btn btn-sm btn-outline-secondary w-100">Create Reverse Pickup</button>
        </form>
    <?php endif; ?>
    <?php if (strtolower((string) ($return['status'] ?? '')) === 'approved' && !shipping_courier_reverse_supports('create')): ?>
        <div class="small text-warning mt-2">Manual pickup required: the configured provider has no verified reverse-pickup capability.</div>
    <?php endif; ?>
    <?php
}

function shipping_courier_handle_admin_return_action($handled, array $context)
{
    if ($handled) {
        return true;
    }

    $action = trim((string) ($context['action'] ?? ''));
    if ($action !== 'create_courier_reverse_pickup') {
        return false;
    }

    $conn = $context['conn'] ?? null;
    $returnId = (int) ($context['return_id'] ?? 0);
    if (!$conn instanceof mysqli || $returnId <= 0) {
        flash('error', 'Unable to create reverse pickup for this return.');
        return true;
    }

    try {
        $result = shipping_courier_create_reverse_pickup($conn, $returnId);
        if (!empty($result['ok'])) {
            $orderId = (int) ($result['order_id'] ?? 0);
            if ($orderId <= 0 && is_array($result['reverse_pickup'] ?? null)) {
                $orderId = (int) ($result['reverse_pickup']['order_id'] ?? 0);
            }
            if ($orderId > 0 && function_exists('log_order_activity')) {
                log_order_activity(
                    $conn,
                    $orderId,
                    'shipping_courier_reverse_pickup_created',
                    'admin',
                    (int) ($_SESSION['admin_id'] ?? 0),
                    (string) ($_SESSION['admin_name'] ?? 'admin'),
                    'Reverse pickup created for return #' . $returnId . '.'
                );
            }
            flash('success', (string) ($result['message'] ?? 'Reverse pickup created.'));
        } else {
            flash('error', (string) ($result['message'] ?? 'Unable to create reverse pickup.'));
        }
    } catch (Throwable $e) {
        error_log('[shipping-courier] admin reverse pickup action failed for return ' . $returnId . ': ' . $e->getMessage());
        flash('error', 'Reverse pickup failed safely. Existing return processing is unchanged.');
    }

    return true;
}
