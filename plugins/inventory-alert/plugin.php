<?php

add_action('cron.tick', 'inventory_alert_run', 40);

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
        "SELECT id, name, sku, unit_type, stock, stock_meters, status, is_available,
                low_stock_threshold_units, low_stock_threshold_meters
         FROM fabrics
         WHERE status = 'active'
           AND is_available = 1
           AND (
                (unit_type IN ('piece','set') AND stock <= COALESCE(low_stock_threshold_units, ?))
                OR
                (unit_type = 'meter' AND stock_meters <= COALESCE(low_stock_threshold_meters, ?))
           )
         ORDER BY id ASC"
    );
    $stmt->bind_param('dd', $piece, $meter);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    return is_array($rows) ? $rows : [];
}

function inventory_alert_recently_sent(mysqli $conn, int $productId, int $cooldownHours): bool
{
    $stmt = $conn->prepare(
        "SELECT id
         FROM inventory_alert_logs
         WHERE product_id = ?
           AND sent_at >= DATE_SUB(NOW(), INTERVAL ? HOUR)
         ORDER BY sent_at DESC
         LIMIT 1"
    );
    $stmt->bind_param('ii', $productId, $cooldownHours);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    return (bool) $row;
}

function inventory_alert_log_sent(mysqli $conn, int $productId, string $unitType, float $stockValue): void
{
    $stmt = $conn->prepare(
        "INSERT INTO inventory_alert_logs (product_id, unit_type, stock_value, sent_at)
         VALUES (?, ?, ?, NOW())"
    );
    $stmt->bind_param('isd', $productId, $unitType, $stockValue);
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
        error_log('[inventory-alert] email failed: ' . $e->getMessage());
        return false;
    }
}

function inventory_alert_run(array $context): void
{
    $settings = inventory_alert_settings();
    if (!$settings['enabled']) {
        return;
    }
    $conn = $context['conn'] ?? ($GLOBALS['conn'] ?? null);
    if (!$conn instanceof mysqli || !inventory_alert_table_ready($conn)) {
        return;
    }

    $rows = inventory_alert_fetch_candidates($conn, $settings);
    if (empty($rows)) {
        return;
    }

    $cooldown = (int) $settings['cooldown_hours'];
    $alertRows = [];
    foreach ($rows as $row) {
        $pid = (int) ($row['id'] ?? 0);
        if ($pid <= 0 || inventory_alert_recently_sent($conn, $pid, $cooldown)) {
            continue;
        }
        $unitType = in_array((string) ($row['unit_type'] ?? ''), ['meter', 'piece', 'set'], true) ? (string) $row['unit_type'] : 'piece';
        $stockValue = $unitType === 'meter' ? (float) ($row['stock_meters'] ?? 0) : (float) ($row['stock'] ?? 0);
        $alertRows[] = [
            'id' => $pid,
            'name' => (string) ($row['name'] ?? ''),
            'sku' => (string) ($row['sku'] ?? ''),
            'unit_type' => $unitType,
            'stock' => $stockValue,
        ];
    }

    if (empty($alertRows)) {
        return;
    }

    $lines = [
        'Low stock products detected:',
        '',
    ];
    foreach ($alertRows as $item) {
        $unitLabel = $item['unit_type'] === 'meter' ? 'meters' : ($item['unit_type'] === 'set' ? 'sets' : 'pieces');
        $lines[] = '- #' . (int) $item['id']
            . ' | ' . $item['name']
            . ' | SKU: ' . ($item['sku'] !== '' ? $item['sku'] : '-')
            . ' | Stock: ' . format_quantity_by_unit((float) $item['stock'], (string) $item['unit_type']) . ' ' . $unitLabel;
    }
    $lines[] = '';
    $lines[] = 'Please restock these products.';

    if (!inventory_alert_send_email($lines)) {
        return;
    }

    foreach ($alertRows as $item) {
        $pid = (int) ($item['id'] ?? 0);
        if ($pid <= 0) {
            continue;
        }
        inventory_alert_log_sent($conn, $pid, (string) ($item['unit_type'] ?? 'piece'), (float) ($item['stock'] ?? 0));
    }
}
