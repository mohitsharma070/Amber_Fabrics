<?php

add_action('app.init', 'utm_attribution_capture_request', 5);
add_action('order.after_create', 'utm_attribution_save_order', 20);
add_action('admin.order_view.sidebar', 'utm_attribution_render_admin_panel', 20);

function utm_attribution_keys(): array
{
    return ['utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content', 'fbclid', 'gclid'];
}

function utm_attribution_cookie_name(): string
{
    return 'amber_utm';
}

function utm_attribution_table_ready(mysqli $conn): bool
{
    try {
        $stmt = $conn->prepare(
            "SELECT COUNT(*) AS total
             FROM information_schema.TABLES
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = 'marketing_attributions'"
        );
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        return ((int) ($row['total'] ?? 0)) > 0;
    } catch (Throwable $e) {
        error_log('[utm-attribution] table check failed: ' . $e->getMessage());
        return false;
    }
}

function utm_attribution_clean_value($value, int $maxLength = 255): string
{
    $value = trim((string) $value);
    if ($value === '') {
        return '';
    }
    $value = preg_replace('/[\x00-\x1F\x7F]/u', '', $value);
    if (!is_string($value)) {
        return '';
    }
    if (function_exists('mb_substr')) {
        return mb_substr($value, 0, $maxLength, 'UTF-8');
    }
    return substr($value, 0, $maxLength);
}

function utm_attribution_current_url(): string
{
    if (PHP_SAPI === 'cli') {
        return '';
    }
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
    $scheme = $https ? 'https' : 'http';
    $host = (string) ($_SERVER['HTTP_HOST'] ?? ($_SERVER['SERVER_NAME'] ?? ''));
    $uri = (string) ($_SERVER['REQUEST_URI'] ?? '');
    if ($host === '' || $uri === '') {
        return '';
    }
    return utm_attribution_clean_value($scheme . '://' . $host . $uri, 1000);
}

function utm_attribution_has_request_data(): bool
{
    foreach (utm_attribution_keys() as $key) {
        if (isset($_GET[$key]) && trim((string) $_GET[$key]) !== '') {
            return true;
        }
    }
    return false;
}

function utm_attribution_from_request(): array
{
    $data = [];
    foreach (utm_attribution_keys() as $key) {
        $data[$key] = utm_attribution_clean_value($_GET[$key] ?? '');
    }
    $data['landing_url'] = utm_attribution_current_url();
    $data['referrer'] = utm_attribution_clean_value($_SERVER['HTTP_REFERER'] ?? '', 1000);
    $data['captured_at'] = date('Y-m-d H:i:s');
    return $data;
}

function utm_attribution_from_cookie(): array
{
    $raw = (string) ($_COOKIE[utm_attribution_cookie_name()] ?? '');
    if ($raw === '') {
        return [];
    }
    $decoded = json_decode(base64_decode($raw, true) ?: '', true);
    return is_array($decoded) ? $decoded : [];
}

function utm_attribution_capture_request(array $context): void
{
    if (PHP_SAPI === 'cli') {
        return;
    }

    if (function_exists('marketing_consent_granted') && !marketing_consent_granted()) {
        if (function_exists('marketing_consent_denied') && marketing_consent_denied()) {
            unset($_SESSION['utm_attribution']);
        }
        return;
    }

    if (utm_attribution_has_request_data()) {
        $data = utm_attribution_from_request();
        $_SESSION['utm_attribution'] = $data;

        $cookieDays = max(1, (int) plugin_setting('utm-attribution', 'cookie_days', 30));
        $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
        $cookiePayload = base64_encode(json_encode($data));
        setcookie(utm_attribution_cookie_name(), $cookiePayload, [
            'expires' => time() + ($cookieDays * 86400),
            'path' => '/',
            'secure' => $secure,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        return;
    }

    if (empty($_SESSION['utm_attribution'])) {
        $cookieData = utm_attribution_from_cookie();
        if (!empty($cookieData)) {
            $_SESSION['utm_attribution'] = $cookieData;
        }
    }
}

function utm_attribution_active_data(): array
{
    if (function_exists('marketing_consent_granted') && !marketing_consent_granted()) {
        return [];
    }
    $data = $_SESSION['utm_attribution'] ?? [];
    return is_array($data) ? $data : [];
}

function utm_attribution_save_order(array $context): void
{
    $conn = $context['conn'] ?? null;
    if (!$conn instanceof mysqli || !utm_attribution_table_ready($conn)) {
        return;
    }

    $orderId = (int) ($context['order_id'] ?? 0);
    if ($orderId <= 0) {
        return;
    }

    $data = utm_attribution_active_data();
    if (empty($data)) {
        return;
    }

    $source = utm_attribution_clean_value($data['utm_source'] ?? '');
    $medium = utm_attribution_clean_value($data['utm_medium'] ?? '');
    $campaign = utm_attribution_clean_value($data['utm_campaign'] ?? '');
    $term = utm_attribution_clean_value($data['utm_term'] ?? '');
    $content = utm_attribution_clean_value($data['utm_content'] ?? '');
    $fbclid = utm_attribution_clean_value($data['fbclid'] ?? '', 500);
    $gclid = utm_attribution_clean_value($data['gclid'] ?? '', 500);
    $landingUrl = utm_attribution_clean_value($data['landing_url'] ?? '', 1000);
    $referrer = utm_attribution_clean_value($data['referrer'] ?? '', 1000);

    if ($source === '' && $medium === '' && $campaign === '' && $fbclid === '' && $gclid === '') {
        return;
    }

    $stmt = $conn->prepare(
        "INSERT INTO marketing_attributions
            (order_id, customer_id, utm_source, utm_medium, utm_campaign, utm_term, utm_content, fbclid, gclid, landing_url, referrer)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE
            customer_id = VALUES(customer_id),
            utm_source = VALUES(utm_source),
            utm_medium = VALUES(utm_medium),
            utm_campaign = VALUES(utm_campaign),
            utm_term = VALUES(utm_term),
            utm_content = VALUES(utm_content),
            fbclid = VALUES(fbclid),
            gclid = VALUES(gclid),
            landing_url = VALUES(landing_url),
            referrer = VALUES(referrer)"
    );
    $customerId = (int) ($context['customer_id'] ?? ($_SESSION['customer_id'] ?? 0));
    $stmt->bind_param(
        'iisssssssss',
        $orderId,
        $customerId,
        $source,
        $medium,
        $campaign,
        $term,
        $content,
        $fbclid,
        $gclid,
        $landingUrl,
        $referrer
    );
    $stmt->execute();

    if (function_exists('log_order_activity')) {
        $label = $source !== '' ? $source : ($fbclid !== '' ? 'facebook' : 'google');
        log_order_activity($conn, $orderId, 'marketing_attribution_saved', 'system', 0, 'utm-attribution', 'Source: ' . $label);
    }
}

function utm_attribution_get_order(mysqli $conn, int $orderId): ?array
{
    if ($orderId <= 0 || !utm_attribution_table_ready($conn)) {
        return null;
    }
    $stmt = $conn->prepare("SELECT * FROM marketing_attributions WHERE order_id = ? LIMIT 1");
    $stmt->bind_param('i', $orderId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    return $row ?: null;
}

function utm_attribution_render_admin_panel(array $context): void
{
    $conn = $context['conn'] ?? null;
    $order = $context['order'] ?? [];
    if (!$conn instanceof mysqli) {
        return;
    }

    $row = utm_attribution_get_order($conn, (int) ($order['id'] ?? 0));
    if (!$row) {
        return;
    }
    ?>
    <div class="card mb-4 border-info">
        <div class="card-body">
            <h6 class="card-title">Marketing Source</h6>
            <div class="small text-muted">
                <div>Source: <strong><?php echo e((string) ($row['utm_source'] ?: '-')); ?></strong></div>
                <div>Medium: <strong><?php echo e((string) ($row['utm_medium'] ?: '-')); ?></strong></div>
                <div>Campaign: <strong><?php echo e((string) ($row['utm_campaign'] ?: '-')); ?></strong></div>
                <?php if (!empty($row['utm_content'])): ?>
                    <div>Content: <strong><?php echo e((string) $row['utm_content']); ?></strong></div>
                <?php endif; ?>
                <?php if (!empty($row['utm_term'])): ?>
                    <div>Term: <strong><?php echo e((string) $row['utm_term']); ?></strong></div>
                <?php endif; ?>
                <?php if (!empty($row['fbclid'])): ?>
                    <div>Facebook Click: <strong>Captured</strong></div>
                <?php endif; ?>
                <?php if (!empty($row['gclid'])): ?>
                    <div>Google Click: <strong>Captured</strong></div>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <?php
}
