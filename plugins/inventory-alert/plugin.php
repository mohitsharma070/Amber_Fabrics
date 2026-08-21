<?php

function_exists('add_cron_action') ? add_cron_action('inventory_alert_run', 40, false) : add_action('cron.tick', 'inventory_alert_run', 40);

function inventory_alert_settings(): array
{
    return [
        'enabled' => (int) plugin_setting('inventory-alert', 'enabled', 1) === 1,
        'piece_threshold' => max(0, (float) plugin_setting('inventory-alert', 'piece_threshold', 5)),
        'meter_threshold' => max(0, (float) plugin_setting('inventory-alert', 'meter_threshold', 10)),
        'cooldown_hours' => max(1, (int) plugin_setting('inventory-alert', 'cooldown_hours', 24)),
    ];
}

function inventory_alert_table_ready(mysqli $conn): bool
{
    try {
        $stmt = $conn->prepare(
            "SELECT COUNT(*) AS total
             FROM information_schema.TABLES
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = 'inventory_alert_logs'"
        );
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        return ((int) ($row['total'] ?? 0)) > 0;
    } catch (Throwable $e) {
        error_log('[inventory-alert] table check failed: ' . $e->getMessage());
        return false;
    }
}

function inventory_alert_fetch_candidates(mysqli $conn, array $settings): array
{
    $piece = (float) $settings['piece_threshold'];
    $meter = (float) $settings['meter_threshold'];
    $stmt = $conn->prepare(
        "SELECT f.id product_id, NULL variant_id, f.name, f.sku, '' color, '' size,
                f.unit_type, f.stock, f.stock_meters
         FROM fabrics f
         WHERE f.status='active' AND f.is_available=1 AND f.product_type='simple'
           AND ((f.unit_type IN ('piece','set') AND f.stock <= COALESCE(f.low_stock_threshold_units, ?))
             OR (f.unit_type='meter' AND f.stock_meters <= COALESCE(f.low_stock_threshold_meters, ?)))
         UNION ALL
         SELECT f.id product_id, fv.id variant_id, f.name, COALESCE(NULLIF(fv.sku,''), f.sku),
                fv.color, fv.size, f.unit_type, fv.stock, fv.stock_meters
         FROM fabrics f JOIN fabric_variants fv ON fv.fabric_id=f.id AND fv.is_active=1
         WHERE f.status='active' AND f.is_available=1 AND f.product_type='variable'
           AND ((f.unit_type IN ('piece','set') AND fv.stock <= COALESCE(f.low_stock_threshold_units, ?))
             OR (f.unit_type='meter' AND fv.stock_meters <= COALESCE(f.low_stock_threshold_meters, ?)))
         ORDER BY product_id, variant_id"
    );
    $stmt->bind_param('dddd', $piece, $meter, $piece, $meter);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    return is_array($rows) ? $rows : [];
}

function inventory_alert_recently_sent(mysqli $conn, int $productId, ?int $variantId, int $cooldownHours): bool
{
    $stmt = $conn->prepare(
        "SELECT id
         FROM inventory_alert_logs
         WHERE product_id = ?
           AND variant_id <=> ?
           AND sent_at >= DATE_SUB(NOW(), INTERVAL ? HOUR)
         ORDER BY sent_at DESC
         LIMIT 1"
    );
    $stmt->bind_param('iii', $productId, $variantId, $cooldownHours);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    return (bool) $row;
}

function inventory_alert_log_sent(mysqli $conn, int $productId, ?int $variantId, string $unitType, float $stockValue): void
{
    $stmt = $conn->prepare(
        "INSERT INTO inventory_alert_logs (product_id, variant_id, unit_type, stock_value, sent_at)
         VALUES (?, ?, ?, ?, NOW())"
    );
    $stmt->bind_param('iisd', $productId, $variantId, $unitType, $stockValue);
    $stmt->execute();
}

function inventory_alert_send_email(array $lines): bool
{
    $to = admin_notification_email();
    if ($to === '') {
        return false;
    }
    try {
        $mail = EmailService::_mailer_base();
        $mail->addAddress($to, 'Admin');
        $mail->Subject = 'Low Inventory Alert - ' . SiteContext::name();
        $mail->Body = implode("\r\n", $lines);
        $mail->send();
        return true;
    } catch (Throwable $e) {
        error_log('[inventory-alert] email failed: ' . CronService::sanitizeError($e->getMessage()));
        return false;
    }
}

function inventory_alert_run(array $context): array
{
    $settings = inventory_alert_settings();
    if (!$settings['enabled']) {
        return CronService::result('skipped', 0, 0, 0, ['reason' => 'disabled']);
    }
    $conn = $context['conn'] ?? ($GLOBALS['conn'] ?? null);
    if (!$conn instanceof mysqli || !inventory_alert_table_ready($conn)) {
        return CronService::result('failed', 0, 0, 1, ['reason' => 'schema_or_database_unavailable']);
    }

    $rows = inventory_alert_fetch_candidates($conn, $settings);
    if (empty($rows)) {
        return CronService::result('success');
    }

    $cooldown = (int) $settings['cooldown_hours'];
    $alertRows = [];
    foreach ($rows as $row) {
        $pid = (int) ($row['product_id'] ?? 0);
        $variantId = (int) ($row['variant_id'] ?? 0);
        $variantId = $variantId > 0 ? $variantId : null;
        if ($pid <= 0 || inventory_alert_recently_sent($conn, $pid, $variantId, $cooldown)) {
            continue;
        }
        $unitType = in_array((string) ($row['unit_type'] ?? ''), ['meter', 'piece', 'set'], true) ? (string) $row['unit_type'] : 'piece';
        $stockValue = $unitType === 'meter' ? (float) ($row['stock_meters'] ?? 0) : (float) ($row['stock'] ?? 0);
        $alertRows[] = [
            'id' => $pid,
            'variant_id' => $variantId,
            'name' => (string) ($row['name'] ?? ''),
            'sku' => (string) ($row['sku'] ?? ''),
            'color' => (string) ($row['color'] ?? ''),
            'size' => (string) ($row['size'] ?? ''),
            'unit_type' => $unitType,
            'stock' => $stockValue,
        ];
    }

    if (empty($alertRows)) {
        return CronService::result('success', count($rows));
    }

    $lines = [
        'Low stock products detected:',
        '',
    ];
    foreach ($alertRows as $item) {
        $unitLabel = $item['unit_type'] === 'meter' ? 'meters' : ($item['unit_type'] === 'set' ? 'sets' : 'pieces');
        $lines[] = '- #' . (int) $item['id']
            . ' | ' . $item['name']
            . (!empty($item['variant_id']) ? ' | Variant: ' . trim((string) $item['color'] . ' / ' . (string) $item['size'], ' /') : '')
            . ' | SKU: ' . ($item['sku'] !== '' ? $item['sku'] : '-')
            . ' | Stock: ' . format_quantity_by_unit((float) $item['stock'], (string) $item['unit_type']) . ' ' . $unitLabel;
    }
    $lines[] = '';
    $lines[] = 'Please restock these products.';

    if (!inventory_alert_send_email($lines)) {
        return CronService::result('degraded', count($alertRows), 0, count($alertRows), ['reason' => admin_notification_email() === '' ? 'recipient_not_configured' : 'email_delivery_failed']);
    }

    foreach ($alertRows as $item) {
        $pid = (int) ($item['id'] ?? 0);
        if ($pid <= 0) {
            continue;
        }
        inventory_alert_log_sent($conn, $pid, isset($item['variant_id']) ? (int) $item['variant_id'] : null, (string) ($item['unit_type'] ?? 'piece'), (float) ($item['stock'] ?? 0));
    }
    return CronService::result('success', count($alertRows), count($alertRows), 0);
}
