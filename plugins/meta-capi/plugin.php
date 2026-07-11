<?php

add_action('app.init', 'meta_capi_capture_browser_ids', 10);
add_action('product.view', 'meta_capi_handle_view_content', 10);
add_action('checkout.view', 'meta_capi_handle_initiate_checkout', 10);
add_action('cart.after_add', 'meta_capi_handle_add_to_cart', 20);
add_action('outbox.process', 'meta_capi_process_outbox_event', 10);

function meta_capi_enabled(): bool
{
    if (function_exists('marketing_consent_granted') && !marketing_consent_granted()) {
        return false;
    }
    $token = trim((string) plugin_setting('meta-capi', 'access_token', ''));
    if ($token === '') {
        return false;
    }
    $pixelId = meta_capi_pixel_id();
    return $pixelId !== '';
}

function meta_capi_pixel_id(): string
{
    $pixelId = trim((string) plugin_setting('meta-capi', 'pixel_id', ''));
    if ($pixelId !== '') {
        return $pixelId;
    }
    return trim((string) plugin_setting('meta-pixel', 'pixel_id', ''));
}

function meta_capi_access_token(): string
{
    return trim((string) plugin_setting('meta-capi', 'access_token', ''));
}

function meta_capi_test_event_code(): string
{
    return trim((string) plugin_setting('meta-capi', 'test_event_code', ''));
}

function meta_capi_hash(?string $value, bool $digitsOnly = false): string
{
    $value = strtolower(trim((string) $value));
    if ($value === '') {
        return '';
    }
    if ($digitsOnly) {
        $value = preg_replace('/\D+/', '', $value) ?? '';
    }
    if ($value === '') {
        return '';
    }
    return hash('sha256', $value);
}

function meta_capi_capture_browser_ids(array $context): void
{
    if (PHP_SAPI === 'cli') {
        return;
    }
    if (function_exists('marketing_consent_granted') && !marketing_consent_granted()) {
        unset($_SESSION['meta_fbp'], $_SESSION['meta_fbc']);
        return;
    }
    $fbp = trim((string) ($_COOKIE['_fbp'] ?? ''));
    $fbc = trim((string) ($_COOKIE['_fbc'] ?? ''));
    if ($fbp !== '') {
        $_SESSION['meta_fbp'] = $fbp;
    }
    if ($fbc !== '') {
        $_SESSION['meta_fbc'] = $fbc;
    }
}

function meta_capi_user_data(array $context = []): array
{
    if (function_exists('marketing_consent_granted') && !marketing_consent_granted()) {
        return [];
    }

    $email = (string) ($context['email'] ?? ($_SESSION['checkout_old']['email'] ?? ''));
    $phone = (string) ($context['phone'] ?? ($_SESSION['checkout_old']['phone'] ?? ''));
    $customerId = (int) ($context['customer_id'] ?? ($_SESSION['customer_id'] ?? 0));
    $userData = [];

    $em = meta_capi_hash($email, false);
    if ($em !== '') {
        $userData['em'] = [$em];
    }
    $ph = meta_capi_hash($phone, true);
    if ($ph !== '') {
        $userData['ph'] = [$ph];
    }
    if ($customerId > 0) {
        $external = meta_capi_hash((string) $customerId, false);
        if ($external !== '') {
            $userData['external_id'] = [$external];
        }
    }

    $fbp = trim((string) ($_SESSION['meta_fbp'] ?? ($_COOKIE['_fbp'] ?? '')));
    $fbc = trim((string) ($_SESSION['meta_fbc'] ?? ($_COOKIE['_fbc'] ?? '')));
    if ($fbp !== '') {
        $userData['fbp'] = $fbp;
    }
    if ($fbc !== '') {
        $userData['fbc'] = $fbc;
    }

    $ip = trim((string) ($_SERVER['REMOTE_ADDR'] ?? ''));
    $ua = trim((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''));
    if ($ip !== '') {
        $userData['client_ip_address'] = $ip;
    }
    if ($ua !== '') {
        $userData['client_user_agent'] = $ua;
    }

    return $userData;
}

function meta_capi_event_source_url(): string
{
    if (PHP_SAPI === 'cli') {
        return '';
    }
    $https = app_request_is_https();
    $scheme = $https ? 'https' : 'http';
    $host = (string) ($_SERVER['HTTP_HOST'] ?? ($_SERVER['SERVER_NAME'] ?? ''));
    $uri = (string) ($_SERVER['REQUEST_URI'] ?? '');
    if ($host === '' || $uri === '') {
        return '';
    }
    return $scheme . '://' . $host . $uri;
}

function meta_capi_post_event(string $eventName, array $customData, array $userData, string $eventId, string $eventSourceUrl = ''): bool
{
    if (!meta_capi_enabled()) {
        return true;
    }
    if ($eventId === '') {
        return true;
    }
    if (empty($userData)) {
        // Meta requires user_data for CAPI events.
        return true;
    }

    $payload = [
        'data' => [[
            'event_name' => $eventName,
            'event_time' => time(),
            'event_id' => $eventId,
            'action_source' => 'website',
            'user_data' => $userData,
            'custom_data' => $customData,
        ]],
    ];
    if ($eventSourceUrl !== '') {
        $payload['data'][0]['event_source_url'] = $eventSourceUrl;
    }
    $testCode = meta_capi_test_event_code();
    if ($testCode !== '') {
        $payload['test_event_code'] = $testCode;
    }

    $endpoint = 'https://graph.facebook.com/v21.0/' . rawurlencode(meta_capi_pixel_id()) . '/events?access_token=' . rawurlencode(meta_capi_access_token());
    $resp = app_http_json('POST', $endpoint, ['Content-Type: application/json'], $payload);
    if (empty($resp['ok'])) {
        $reason = (string) ($resp['error'] ?? $resp['reason'] ?? 'unknown error');
        $body = $resp['body'] ?? [];
        if (is_array($body)) {
            $metaMessage = (string) (($body['error']['message'] ?? $body['message'] ?? ''));
            $metaType = (string) (($body['error']['type'] ?? ''));
            $metaCode = (string) (($body['error']['code'] ?? ''));
            if ($metaMessage !== '') {
                $reason .= ' | meta=' . trim($metaType . ' ' . $metaCode . ' ' . $metaMessage);
            }
        }
        error_log('[meta-capi] event failed (' . $eventName . '): ' . $reason);
        return false;
    }
    return true;
}

function meta_capi_process_outbox_event(array $context): void
{
    $event = (array) ($context['event'] ?? []);
    if (($event['event_type'] ?? '') !== 'meta.purchase') return;
    $conn = $context['conn'] ?? null;
    if (!$conn instanceof mysqli) throw new RuntimeException('Missing database connection for Meta outbox event.');
    $orderId = (int) ($event['order_id'] ?? 0);
    $purchase = meta_capi_purchase_payload($conn, $orderId);
    if (!$purchase) throw new RuntimeException('Order payload unavailable for Meta outbox event.');
    $identityStmt = $conn->prepare("SELECT customer_email, customer_phone, customer_id FROM orders WHERE id = ? LIMIT 1");
    $identityStmt->bind_param('i', $orderId); $identityStmt->execute();
    $identity = $identityStmt->get_result()->fetch_assoc() ?: [];
    $ok = meta_capi_post_event('Purchase', (array) ($purchase['custom_data'] ?? []), meta_capi_user_data(['email' => $identity['customer_email'] ?? '', 'phone' => $identity['customer_phone'] ?? '', 'customer_id' => (int) ($identity['customer_id'] ?? 0)]), (string) ($event['idempotency_key'] ?? ''), '');
    if (!$ok) throw new RuntimeException('Meta CAPI delivery failed.');
}

function meta_capi_event_id(string $eventName, string $seed): string
{
    if (function_exists('meta_pixel_event_id')) {
        return meta_pixel_event_id($eventName, $seed);
    }
    return substr(hash('sha256', $eventName . '|' . $seed), 0, 24);
}

function meta_capi_product_payload(mysqli $conn, int $productId): ?array
{
    if ($productId <= 0) {
        return null;
    }
    $stmt = $conn->prepare("SELECT id, name, price, sale_price, price_inr FROM fabrics WHERE id = ? AND status = 'active' LIMIT 1");
    $stmt->bind_param('i', $productId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    if (!$row) {
        return null;
    }
    $regular = (float) (($row['price'] !== null && $row['price'] !== '') ? $row['price'] : ($row['price_inr'] ?? 0));
    $sale = (float) ($row['sale_price'] ?? 0);
    $price = ($sale > 0 && $sale < $regular) ? $sale : $regular;
    return [
        'content_ids' => [(string) $row['id']],
        'content_name' => (string) $row['name'],
        'content_type' => 'product',
        'currency' => 'INR',
        'value' => round(max(0, $price), 2),
    ];
}

function meta_capi_handle_view_content(array $context): void
{
    $conn = $context['conn'] ?? null;
    if (!$conn instanceof mysqli) {
        return;
    }
    $productId = (int) ($context['product_id'] ?? 0);
    $payload = meta_capi_product_payload($conn, $productId);
    if (!$payload) {
        return;
    }
    meta_capi_post_event(
        'ViewContent',
        $payload,
        meta_capi_user_data($context),
        meta_capi_event_id('ViewContent', (string) $productId),
        meta_capi_event_source_url()
    );
}

function meta_capi_handle_add_to_cart(array $context): void
{
    $conn = $context['conn'] ?? null;
    if (!$conn instanceof mysqli) {
        return;
    }
    $productId = (int) ($context['product_id'] ?? 0);
    $qty = max(1, (float) ($context['quantity'] ?? 1));
    $payload = meta_capi_product_payload($conn, $productId);
    if (!$payload) {
        return;
    }
    $payload['value'] = round((float) ($payload['value'] ?? 0) * $qty, 2);
    $eventId = '';
    if (!empty($GLOBALS['meta_pixel_last_event']['event_id'])) {
        $eventId = (string) $GLOBALS['meta_pixel_last_event']['event_id'];
    }
    if ($eventId === '') {
        $eventId = meta_capi_event_id('AddToCart', $productId . '|' . time());
    }
    meta_capi_post_event('AddToCart', $payload, meta_capi_user_data($context), $eventId, meta_capi_event_source_url());
}

function meta_capi_handle_initiate_checkout(array $context): void
{
    $rawContentIds = (array) ($context['content_ids'] ?? []);
    $contentIds = [];
    foreach ($rawContentIds as $rawId) {
        $id = (int) $rawId;
        if ($id > 0) {
            $contentIds[] = (string) $id;
        }
    }
    $contentIds = array_values(array_unique($contentIds));
    if (empty($contentIds)) {
        return;
    }
    $payload = [
        'content_ids' => $contentIds,
        'content_type' => 'product',
        'num_items' => (int) ($context['num_items'] ?? count($contentIds)),
        'currency' => 'INR',
    ];
    $seed = implode(',', $contentIds);
    meta_capi_post_event('InitiateCheckout', $payload, meta_capi_user_data($context), meta_capi_event_id('InitiateCheckout', $seed), meta_capi_event_source_url());
}

function meta_capi_purchase_payload(mysqli $conn, int $orderId): ?array
{
    if ($orderId <= 0) {
        return null;
    }
    $stmt = $conn->prepare("SELECT id, order_number, total_amount FROM orders WHERE id = ? LIMIT 1");
    $stmt->bind_param('i', $orderId);
    $stmt->execute();
    $order = $stmt->get_result()->fetch_assoc();
    if (!$order) {
        return null;
    }
    $iStmt = $conn->prepare("SELECT product_id, quantity FROM order_items WHERE order_id = ?");
    $iStmt->bind_param('i', $orderId);
    $iStmt->execute();
    $rows = $iStmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $contentIds = [];
    foreach ($rows as $row) {
        $pid = (int) ($row['product_id'] ?? 0);
        if ($pid > 0) {
            $contentIds[] = (string) $pid;
        }
    }
    return [
        'order_number' => (string) ($order['order_number'] ?? ''),
        'custom_data' => [
            'content_ids' => array_values(array_unique($contentIds)),
            'content_type' => 'product',
            'currency' => 'INR',
            'value' => round(max(0, (float) ($order['total_amount'] ?? 0)), 2),
            'order_id' => (string) ($order['order_number'] ?? ''),
        ],
    ];
}

function meta_capi_handle_cod_purchase(array $context): void
{
    if (strtolower((string) ($context['payment_method'] ?? '')) !== 'cod') {
        return;
    }
    $conn = $context['conn'] ?? null;
    if (!$conn instanceof mysqli) {
        return;
    }
    $orderId = (int) ($context['order_id'] ?? 0);
    $purchase = meta_capi_purchase_payload($conn, $orderId);
    if (!$purchase) {
        return;
    }
    $orderNumber = (string) ($purchase['order_number'] ?? '');
    if ($orderNumber === '') {
        return;
    }
    meta_capi_post_event(
        'Purchase',
        (array) ($purchase['custom_data'] ?? []),
        meta_capi_user_data($context),
        meta_capi_event_id('Purchase', $orderNumber),
        meta_capi_event_source_url()
    );
}

function meta_capi_handle_paid_purchase(array $context): void
{
    $conn = $context['conn'] ?? null;
    if (!$conn instanceof mysqli) {
        return;
    }
    $orderId = (int) ($context['order_id'] ?? 0);
    $purchase = meta_capi_purchase_payload($conn, $orderId);
    if (!$purchase) {
        return;
    }
    $orderNumber = (string) ($purchase['order_number'] ?? '');
    if ($orderNumber === '') {
        return;
    }
    meta_capi_post_event(
        'Purchase',
        (array) ($purchase['custom_data'] ?? []),
        meta_capi_user_data($context),
        meta_capi_event_id('Purchase', $orderNumber),
        meta_capi_event_source_url()
    );
}
