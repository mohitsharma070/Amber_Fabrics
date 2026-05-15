<?php

add_action('order.after_create', 'shipping_rto_risk_on_order_create', 25);
add_action('admin.order_view.sidebar', 'shipping_rto_risk_render_admin_panel', 25);
add_action('cron.tick', 'shipping_rto_risk_cron_backfill', 45);

function shipping_rto_risk_settings(): array
{
    return [
        'enabled' => (int) plugin_setting('shipping-rto-risk', 'enabled', 1) === 1,
        'high_threshold' => max(10, (int) plugin_setting('shipping-rto-risk', 'high_threshold', 70)),
        'medium_threshold' => max(5, (int) plugin_setting('shipping-rto-risk', 'medium_threshold', 40)),
    ];
}

function shipping_rto_risk_table_ready(mysqli $conn): bool
{
    try {
        $stmt = $conn->prepare(
            "SELECT COUNT(*) AS total
             FROM information_schema.TABLES
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = 'shipping_rto_risks'"
        );
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        return ((int) ($row['total'] ?? 0)) > 0;
    } catch (Throwable $e) {
        error_log('[shipping-rto-risk] table check failed: ' . $e->getMessage());
        return false;
    }
}

function shipping_rto_risk_band(int $score, array $settings): string
{
    if ($score >= (int) $settings['high_threshold']) {
        return 'high';
    }
    if ($score >= (int) $settings['medium_threshold']) {
        return 'medium';
    }
    return 'low';
}

function shipping_rto_risk_order_snapshot(mysqli $conn, int $orderId): ?array
{
    $stmt = $conn->prepare(
        "SELECT id, customer_id, customer_name, customer_phone, customer_email, address, city, state, pincode, country,
                total_amount, payment_method, order_status, created_at
         FROM orders
         WHERE id = ?
         LIMIT 1"
    );
    $stmt->bind_param('i', $orderId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    return $row ?: null;
}

function shipping_rto_risk_customer_history(mysqli $conn, int $customerId, string $phone): array
{
    $completed = 0;
    $cancelled = 0;
    $total = 0;

    if ($customerId > 0) {
        $stmt = $conn->prepare(
            "SELECT
                COUNT(*) AS total_orders,
                SUM(CASE WHEN order_status IN ('delivered','shipped') THEN 1 ELSE 0 END) AS completed_orders,
                SUM(CASE WHEN order_status IN ('cancelled','returned') THEN 1 ELSE 0 END) AS cancelled_orders
             FROM orders
             WHERE customer_id = ?"
        );
        $stmt->bind_param('i', $customerId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc() ?: [];
        $total = (int) ($row['total_orders'] ?? 0);
        $completed = (int) ($row['completed_orders'] ?? 0);
        $cancelled = (int) ($row['cancelled_orders'] ?? 0);
        return ['total' => $total, 'completed' => $completed, 'cancelled' => $cancelled];
    }

    $phone = trim($phone);
    $phoneDigits = preg_replace('/\D+/', '', $phone) ?? '';
    if ($phoneDigits !== '') {
        $lastTen = strlen($phoneDigits) > 10 ? substr($phoneDigits, -10) : $phoneDigits;
        $stmt = $conn->prepare(
            "SELECT
                COUNT(*) AS total_orders,
                SUM(CASE WHEN order_status IN ('delivered','shipped') THEN 1 ELSE 0 END) AS completed_orders,
                SUM(CASE WHEN order_status IN ('cancelled','returned') THEN 1 ELSE 0 END) AS cancelled_orders
             FROM orders
             WHERE customer_phone = ?
                OR REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(customer_phone, ' ', ''), '-', ''), '+', ''), '(', ''), ')', '') = ?
                OR RIGHT(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(customer_phone, ' ', ''), '-', ''), '+', ''), '(', ''), ')', ''), 10) = ?"
        );
        $stmt->bind_param('sss', $phone, $phoneDigits, $lastTen);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc() ?: [];
        $total = (int) ($row['total_orders'] ?? 0);
        $completed = (int) ($row['completed_orders'] ?? 0);
        $cancelled = (int) ($row['cancelled_orders'] ?? 0);
    }
    return ['total' => $total, 'completed' => $completed, 'cancelled' => $cancelled];
}

function shipping_rto_risk_compute(mysqli $conn, array $order): array
{
    $score = 0;
    $reasons = [];
    $paymentMethod = strtolower((string) ($order['payment_method'] ?? ''));
    $totalAmount = (float) ($order['total_amount'] ?? 0);
    $country = strtolower(trim((string) ($order['country'] ?? '')));
    $address = trim((string) ($order['address'] ?? ''));
    $pincode = trim((string) ($order['pincode'] ?? ''));
    $customerId = (int) ($order['customer_id'] ?? 0);
    $phone = trim((string) ($order['customer_phone'] ?? ''));

    if ($paymentMethod === 'cod') {
        $score += 25;
        $reasons[] = 'COD order';
    }
    if ($totalAmount >= 3000) {
        $score += 25;
        $reasons[] = 'High order value (>= 3000)';
    } elseif ($totalAmount >= 1500) {
        $score += 15;
        $reasons[] = 'Medium-high order value (>= 1500)';
    }
    if ($country !== 'india') {
        $score += 20;
        $reasons[] = 'Non-India shipping country';
    }
    if (!preg_match('/^[1-9][0-9]{5}$/', $pincode)) {
        $score += 10;
        $reasons[] = 'Pincode format unusual';
    }
    if (strlen($address) < 15) {
        $score += 12;
        $reasons[] = 'Address appears short/incomplete';
    }

    $history = shipping_rto_risk_customer_history($conn, $customerId, $phone);
    $totalOrders = (int) ($history['total'] ?? 0);
    $cancelled = (int) ($history['cancelled'] ?? 0);
    if ($totalOrders <= 1) {
        $score += 12;
        $reasons[] = 'New customer history';
    }
    if ($cancelled >= 2) {
        $score += 25;
        $reasons[] = 'Multiple past cancelled/returned orders';
    } elseif ($cancelled === 1) {
        $score += 10;
        $reasons[] = 'One past cancelled/returned order';
    }

    if ($score > 100) {
        $score = 100;
    }
    $settings = shipping_rto_risk_settings();
    $band = shipping_rto_risk_band($score, $settings);
    return [
        'score' => $score,
        'band' => $band,
        'reasons' => $reasons,
        'signals' => [
            'payment_method' => $paymentMethod,
            'total_amount' => $totalAmount,
            'pincode' => $pincode,
            'customer_orders' => $totalOrders,
            'customer_cancelled_orders' => $cancelled,
        ],
    ];
}

function shipping_rto_risk_save(mysqli $conn, int $orderId, array $risk): void
{
    $score = (int) ($risk['score'] ?? 0);
    $band = (string) ($risk['band'] ?? 'low');
    $reasonsJson = json_encode((array) ($risk['reasons'] ?? []), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $signalsJson = json_encode((array) ($risk['signals'] ?? []), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if (!is_string($reasonsJson)) {
        $reasonsJson = '[]';
    }
    if (!is_string($signalsJson)) {
        $signalsJson = '{}';
    }

    $stmt = $conn->prepare(
        "INSERT INTO shipping_rto_risks (order_id, risk_score, risk_band, reasons_json, signals_json, assessed_at)
         VALUES (?, ?, ?, ?, ?, NOW())
         ON DUPLICATE KEY UPDATE
            risk_score = VALUES(risk_score),
            risk_band = VALUES(risk_band),
            reasons_json = VALUES(reasons_json),
            signals_json = VALUES(signals_json),
            assessed_at = NOW(),
            updated_at = NOW()"
    );
    $stmt->bind_param('iisss', $orderId, $score, $band, $reasonsJson, $signalsJson);
    $stmt->execute();
}

function shipping_rto_risk_assess_order(mysqli $conn, int $orderId): void
{
    if ($orderId <= 0) {
        return;
    }
    $order = shipping_rto_risk_order_snapshot($conn, $orderId);
    if (!$order) {
        return;
    }
    $risk = shipping_rto_risk_compute($conn, $order);
    shipping_rto_risk_save($conn, $orderId, $risk);
    if (function_exists('log_order_activity')) {
        log_order_activity($conn, $orderId, 'shipping_rto_risk_assessed', 'system', 0, 'shipping-rto-risk', 'Risk: ' . strtoupper((string) ($risk['band'] ?? 'low')) . ' (' . (int) ($risk['score'] ?? 0) . ')');
    }
}

function shipping_rto_risk_on_order_create(array $context): void
{
    $settings = shipping_rto_risk_settings();
    if (!$settings['enabled']) {
        return;
    }
    $conn = $context['conn'] ?? null;
    if (!$conn instanceof mysqli || !shipping_rto_risk_table_ready($conn)) {
        return;
    }
    $orderId = (int) ($context['order_id'] ?? 0);
    shipping_rto_risk_assess_order($conn, $orderId);
}

function shipping_rto_risk_get(mysqli $conn, int $orderId): ?array
{
    if ($orderId <= 0 || !shipping_rto_risk_table_ready($conn)) {
        return null;
    }
    $stmt = $conn->prepare("SELECT * FROM shipping_rto_risks WHERE order_id = ? LIMIT 1");
    $stmt->bind_param('i', $orderId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    return $row ?: null;
}

function shipping_rto_risk_render_admin_panel(array $context): void
{
    $settings = shipping_rto_risk_settings();
    if (!$settings['enabled']) {
        return;
    }
    $conn = $context['conn'] ?? null;
    $order = $context['order'] ?? [];
    if (!$conn instanceof mysqli) {
        return;
    }
    $orderId = (int) ($order['id'] ?? 0);
    $row = shipping_rto_risk_get($conn, $orderId);
    if (!$row) {
        return;
    }
    $band = strtolower((string) ($row['risk_band'] ?? 'low'));
    $score = (int) ($row['risk_score'] ?? 0);
    $badge = 'secondary';
    if ($band === 'high') {
        $badge = 'danger';
    } elseif ($band === 'medium') {
        $badge = 'warning';
    } else {
        $badge = 'success';
    }
    $reasons = json_decode((string) ($row['reasons_json'] ?? '[]'), true);
    if (!is_array($reasons)) {
        $reasons = [];
    }
    ?>
    <div class="card mb-4 border-<?php echo e($badge); ?>">
        <div class="card-body">
            <h6 class="card-title">Shipping / RTO Risk</h6>
            <div class="small text-muted mb-2">
                <div>Risk Score: <strong><?php echo $score; ?>/100</strong></div>
                <div>Band: <span class="badge bg-<?php echo e($badge); ?>"><?php echo strtoupper(e($band)); ?></span></div>
                <div>Assessed At: <strong><?php echo e((string) ($row['assessed_at'] ?? '')); ?></strong></div>
            </div>
            <?php if (!empty($reasons)): ?>
                <div class="small">
                    <?php foreach ($reasons as $reason): ?>
                        <div>- <?php echo e((string) $reason); ?></div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>
    <?php
}

function shipping_rto_risk_cron_backfill(array $context): void
{
    $settings = shipping_rto_risk_settings();
    if (!$settings['enabled']) {
        return;
    }
    $conn = $context['conn'] ?? ($GLOBALS['conn'] ?? null);
    if (!$conn instanceof mysqli || !shipping_rto_risk_table_ready($conn)) {
        return;
    }
    $stmt = $conn->prepare(
        "SELECT o.id
         FROM orders o
         LEFT JOIN shipping_rto_risks r ON r.order_id = o.id
         WHERE r.id IS NULL
           AND o.created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
         ORDER BY o.id DESC
         LIMIT 100"
    );
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    foreach ($rows as $row) {
        $orderId = (int) ($row['id'] ?? 0);
        if ($orderId > 0) {
            shipping_rto_risk_assess_order($conn, $orderId);
        }
    }
}
