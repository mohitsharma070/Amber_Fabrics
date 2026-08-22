<?php

function shipping_courier_cron_tracking_sync(array $context): array
{
    if (!shipping_courier_enabled() || empty(shipping_courier_settings()['tracking_sync'])) {
        return CronService::result('skipped', 0, 0, 0, ['reason' => 'disabled']);
    }
    $conn = $context['conn'] ?? ($GLOBALS['conn'] ?? null);
    if (!$conn instanceof mysqli || !shipping_courier_metadata_table_ready($conn)) {
        return CronService::result('failed', 0, 0, 1, ['reason' => 'schema_or_database_unavailable']);
    }
    if (!shipping_courier_provider_configured()) {
        return CronService::result('failed', 0, 0, 1, ['reason' => 'provider_not_configured']);
    }
    $referenceFailures = 0;
    $segment = shipping_courier_bigship_segment(shipping_courier_settings());
    if (shipping_courier_reference_cache_get($conn, 'payment_modes', $segment) === null) {
        $referenceResult = shipping_courier_bigship_sync_reference_data($conn);
        if (empty($referenceResult['ok'])) {
            $referenceFailures++;
            error_log('[shipping-courier] reference sync skipped: ' . CronService::sanitizeError((string) ($referenceResult['message'] ?? 'unknown')));
        }
    }
    $provider = shipping_courier_provider_name();
    if ($provider === '') {
        return CronService::result('failed', 0, 0, 1, ['reason' => 'provider_name_unavailable']);
    }
    $stmt = $conn->prepare(
        "SELECT scs.order_id
         FROM shipping_courier_shipments scs
         JOIN shipments s ON s.id = scs.shipment_id
         JOIN orders o ON o.id = scs.order_id
         WHERE scs.provider = ?
           AND COALESCE(s.delivered_at, '') = ''
           AND COALESCE(NULLIF(scs.provider_shipment_id, ''), NULLIF(s.tracking_id, '')) IS NOT NULL
           AND o.order_status NOT IN ('cancelled', 'returned', 'refunded')
           AND COALESCE(scs.provider_status, '') NOT IN ('delivered', 'cancelled', 'canceled')
         ORDER BY scs.updated_at ASC
         LIMIT 25"
    );
    $stmt->bind_param('s', $provider);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $succeeded = 0;
    $failed = $referenceFailures;
    foreach ($rows as $row) {
        $orderId = (int) ($row['order_id'] ?? 0);
        if ($orderId <= 0) {
            continue;
        }
        $result = shipping_courier_sync_tracking($conn, $orderId);
        if (empty($result['ok'])) {
            $failed++;
            error_log('[shipping-courier] cron tracking sync skipped for order ' . $orderId . ': ' . CronService::sanitizeError((string) ($result['message'] ?? 'unknown')));
        } else {
            $succeeded++;
        }
    }
    return CronService::result($failed > 0 ? 'degraded' : 'success', count($rows) + $referenceFailures, $succeeded, $failed);
}

function shipping_courier_cron_reverse_sync(array $context): array
{
    if (!shipping_courier_enabled() || !shipping_courier_reverse_supports('sync')) {
        return CronService::result('skipped', 0, 0, 0, ['reason' => 'provider_sync_capability_unavailable']);
    }
    $conn = $context['conn'] ?? ($GLOBALS['conn'] ?? null);
    if (!$conn instanceof mysqli || !shipping_courier_reverse_table_ready($conn)) {
        return CronService::result('failed', 0, 0, 1, ['reason' => 'schema_or_database_unavailable']);
    }
    $provider = shipping_courier_provider_name();
    $stmt = $conn->prepare(
        "SELECT id, return_id, order_id, provider_pickup_id, provider_status
         FROM shipping_courier_reverse_pickups
         WHERE provider=? AND provider_pickup_id IS NOT NULL AND provider_pickup_id<>''
           AND COALESCE(provider_status, '') NOT IN ('delivered','completed','cancelled','canceled','failed')
         ORDER BY updated_at ASC LIMIT 25"
    );
    $stmt->bind_param('s', $provider);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $succeeded = 0;
    $failed = 0;
    foreach ($rows as $row) {
        $result = apply_filters('shipping_courier.reverse.sync_result', null, [
            'conn' => $conn,
            'provider' => $provider,
            'reverse_pickup' => $row,
        ]);
        if (!is_array($result) || empty($result['ok']) || !is_array($result['body'] ?? null)) {
            $failed++;
            continue;
        }
        shipping_courier_upsert_reverse_pickup(
            $conn,
            (int) $row['return_id'],
            (int) $row['order_id'],
            array_merge(shipping_courier_reverse_metadata_from_response($result['body']), ['provider' => $provider])
        );
        $succeeded++;
    }
    return CronService::result($failed > 0 ? 'degraded' : 'success', count($rows), $succeeded, $failed);
}
