<?php

add_filter('security.csp_directives', 'google_analytics_csp_directives', 10);
add_action('page.head', 'google_analytics_render_base', 20);
add_action('page.footer', 'google_analytics_render_page_events', 20);
add_action('product.view', 'google_analytics_handle_product_view', 20);
add_action('checkout.view', 'google_analytics_handle_checkout_view', 20);
add_action('cart.after_add', 'google_analytics_handle_add_to_cart', 30);
add_action('order.after_commit', 'google_analytics_handle_order_create_purchase', 40);
add_action('order.after_payment_success', 'google_analytics_handle_paid_purchase', 20);

// UTM, gclid, and attribution persistence belong to the utm-attribution plugin.
// This plugin only emits GA4 browser events.

function google_analytics_measurement_id(): string
{
    return trim((string) plugin_setting('google-analytics', 'measurement_id', ''));
}

function google_analytics_setting_enabled(string $key, int $default = 1): bool
{
    return (int) plugin_setting('google-analytics', $key, $default) === 1;
}

function google_analytics_consent_granted(): bool
{
    if (function_exists('marketing_consent_granted')) {
        return marketing_consent_granted();
    }
    return true;
}

function google_analytics_enabled(): bool
{
    if (!google_analytics_setting_enabled('enabled', 1)) {
        return false;
    }
    if (!google_analytics_consent_granted()) {
        return false;
    }
    return google_analytics_measurement_id() !== '';
}

function google_analytics_enhanced_ecommerce_enabled(): bool
{
    return google_analytics_setting_enabled('enhanced_ecommerce_enabled', 1);
}

function google_analytics_csp_directives($directives, array $context)
{
    if (!is_array($directives) || !google_analytics_enabled()) {
        return $directives;
    }
    $directives['script-src'][] = 'https://www.googletagmanager.com';
    $directives['script-src'][] = 'https://www.google-analytics.com';
    $directives['connect-src'][] = 'https://www.googletagmanager.com';
    $directives['connect-src'][] = 'https://www.google-analytics.com';
    $directives['img-src'][] = 'https://www.googletagmanager.com';
    $directives['img-src'][] = 'https://www.google-analytics.com';
    return $directives;
}

function google_analytics_money($value): float
{
    return round(max(0, (float) $value), 2);
}

function google_analytics_quantity($value): float
{
    return round(max(0.01, (float) $value), 2);
}

function google_analytics_current_url(): string
{
    if (PHP_SAPI === 'cli') {
        return '';
    }
    $https = function_exists('app_request_is_https') ? app_request_is_https() : (!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off');
    $scheme = $https ? 'https' : 'http';
    $host = (string) ($_SERVER['HTTP_HOST'] ?? ($_SERVER['SERVER_NAME'] ?? ''));
    $uri = (string) ($_SERVER['REQUEST_URI'] ?? '');
    return ($host !== '' && $uri !== '') ? ($scheme . '://' . $host . $uri) : '';
}

function google_analytics_page_view_payload(array $context): array
{
    $payload = [
        'page_title' => (string) ($context['title'] ?? ''),
        'page_path' => (string) ($_SERVER['REQUEST_URI'] ?? ''),
        'page_location' => google_analytics_current_url(),
    ];
    if (google_analytics_setting_enabled('debug_mode', 0)) {
        $payload['debug_mode'] = true;
    }
    return array_filter($payload, static fn($value) => $value !== '');
}

function google_analytics_render_base(array $context): void
{
    if (!google_analytics_enabled()) {
        return;
    }
    $measurementId = google_analytics_measurement_id();
    $nonce = (string) ($GLOBALS['cspNonce'] ?? '');
    ?>
    <script async src="https://www.googletagmanager.com/gtag/js?id=<?php echo e(rawurlencode($measurementId)); ?>"></script>
    <script nonce="<?php echo e($nonce); ?>">
    window.dataLayer = window.dataLayer || [];
    function gtag(){dataLayer.push(arguments);}
    gtag('js', new Date());
    gtag('config', <?php echo json_encode($measurementId); ?>, <?php echo json_encode([
        'debug_mode' => google_analytics_setting_enabled('debug_mode', 0),
        'send_page_view' => false,
    ], JSON_UNESCAPED_SLASHES); ?>);
    window.amberGoogleAnalyticsTrack = function (eventName, payload) {
        if (typeof gtag !== 'function') return;
        gtag('event', eventName, payload || {});
    };
    </script>
    <?php
}

function google_analytics_queue_event(string $eventName, array $payload): array
{
    $event = [
        'name' => $eventName,
        'payload' => $payload,
    ];
    if (!isset($_SESSION['google_analytics_events']) || !is_array($_SESSION['google_analytics_events'])) {
        $_SESSION['google_analytics_events'] = [];
    }
    $_SESSION['google_analytics_events'][] = $event;
    return $event;
}

function google_analytics_pending_events(): array
{
    if (empty($_SESSION['google_analytics_events']) || !is_array($_SESSION['google_analytics_events'])) {
        return [];
    }
    $events = $_SESSION['google_analytics_events'];
    unset($_SESSION['google_analytics_events']);
    return $events;
}

function google_analytics_product_item(mysqli $conn, int $productId, float $quantity = 1.0, int $variantId = 0, string $unitType = ''): ?array
{
    if ($productId <= 0) {
        return null;
    }

    $stmt = $conn->prepare(
        "SELECT id, name, category, unit_type, price, sale_price, price_inr
         FROM fabrics
         WHERE id = ? AND status = 'active'
         LIMIT 1"
    );
    $stmt->bind_param('i', $productId);
    $stmt->execute();
    $product = $stmt->get_result()->fetch_assoc();
    if (!$product) {
        return null;
    }

    $regular = (float) (($product['price'] !== null && $product['price'] !== '') ? $product['price'] : ($product['price_inr'] ?? 0));
    $sale = (float) ($product['sale_price'] ?? 0);
    $price = ($sale > 0 && $sale < $regular) ? $sale : $regular;
    $variantParts = [];

    if ($variantId > 0) {
        $vStmt = $conn->prepare(
            "SELECT color, size, sku, pack_label, price_override
             FROM fabric_variants
             WHERE id = ? AND fabric_id = ?
             LIMIT 1"
        );
        $vStmt->bind_param('ii', $variantId, $productId);
        $vStmt->execute();
        $variant = $vStmt->get_result()->fetch_assoc();
        if ($variant) {
            if ((float) ($variant['price_override'] ?? 0) > 0) {
                $price = (float) $variant['price_override'];
            }
            foreach (['color', 'size', 'pack_label'] as $field) {
                $value = trim((string) ($variant[$field] ?? ''));
                if ($value !== '') {
                    $variantParts[] = $value;
                }
            }
            if (!empty($variant['sku'])) {
                $variantParts[] = 'SKU ' . (string) $variant['sku'];
            }
        }
    }

    $unitType = trim($unitType !== '' ? $unitType : (string) ($product['unit_type'] ?? ''));
    if ($unitType !== '') {
        $variantParts[] = $unitType;
    }

    $quantity = google_analytics_quantity($quantity);
    $price = google_analytics_money($price);
    $item = [
        'item_id' => (string) $product['id'],
        'item_name' => (string) $product['name'],
        'price' => $price,
        'quantity' => $quantity,
        'item_value' => google_analytics_money($price * $quantity),
    ];
    if (!empty($product['category'])) {
        $item['item_category'] = (string) $product['category'];
    }
    if (!empty($variantParts)) {
        $item['item_variant'] = implode(' / ', array_values(array_unique($variantParts)));
    }

    return $item;
}

function google_analytics_cart_payload(mysqli $conn): array
{
    $cart = isset($_SESSION['cart']) && is_array($_SESSION['cart']) ? $_SESSION['cart'] : [];
    $items = [];
    $value = 0.0;

    foreach ($cart as $rawKey => $rawQty) {
        $parts = explode('::', (string) $rawKey, 2);
        $productId = (int) ($parts[0] ?? 0);
        $variantId = (int) ($parts[1] ?? 0);
        $quantity = google_analytics_quantity($rawQty);
        $item = google_analytics_product_item($conn, $productId, $quantity, $variantId);
        if (!$item) {
            continue;
        }
        $items[] = $item;
        $value += (float) ($item['item_value'] ?? 0);
    }

    return [
        'currency' => 'INR',
        'value' => google_analytics_money($value),
        'items' => $items,
    ];
}

function google_analytics_purchase_payload(mysqli $conn, int $orderId): ?array
{
    if ($orderId <= 0) {
        return null;
    }

    $stmt = $conn->prepare(
        "SELECT id, order_number, total_amount, shipping_amount, discount_amount
         FROM orders
         WHERE id = ?
         LIMIT 1"
    );
    $stmt->bind_param('i', $orderId);
    $stmt->execute();
    $order = $stmt->get_result()->fetch_assoc();
    if (!$order) {
        return null;
    }

    $itemStmt = $conn->prepare(
        "SELECT product_id, product_name, unit_type, quantity, price, total, variant_id
         FROM order_items
         WHERE order_id = ?"
    );
    $itemStmt->bind_param('i', $orderId);
    $itemStmt->execute();
    $rows = $itemStmt->get_result()->fetch_all(MYSQLI_ASSOC);

    $items = [];
    foreach ($rows as $row) {
        $item = [
            'item_id' => (string) ((int) ($row['product_id'] ?? 0)),
            'item_name' => (string) ($row['product_name'] ?? ''),
            'price' => google_analytics_money($row['price'] ?? 0),
            'quantity' => google_analytics_quantity($row['quantity'] ?? 1),
            'item_value' => google_analytics_money($row['total'] ?? (((float) ($row['price'] ?? 0)) * ((float) ($row['quantity'] ?? 1)))),
        ];
        if (!empty($row['unit_type'])) {
            $item['item_variant'] = (string) $row['unit_type'];
        }
        $items[] = $item;
    }

    return [
        'transaction_id' => (string) ($order['order_number'] ?? $orderId),
        'order_number' => (string) ($order['order_number'] ?? $orderId),
        'currency' => 'INR',
        'value' => google_analytics_money($order['total_amount'] ?? 0),
        'shipping' => google_analytics_money($order['shipping_amount'] ?? 0),
        'discount' => google_analytics_money($order['discount_amount'] ?? 0),
        'items' => $items,
    ];
}

function google_analytics_purchase_session_key(int $orderId): string
{
    return 'google_analytics_purchase_sent_' . $orderId;
}

function google_analytics_purchase_already_sent(int $orderId): bool
{
    return $orderId > 0 && !empty($_SESSION[google_analytics_purchase_session_key($orderId)]);
}

function google_analytics_mark_purchase_sent(int $orderId): void
{
    if ($orderId > 0) {
        $_SESSION[google_analytics_purchase_session_key($orderId)] = 1;
    }
}

function google_analytics_handle_product_view(array $context): void
{
    if (!google_analytics_enabled() || !google_analytics_enhanced_ecommerce_enabled()) {
        return;
    }
    $conn = $context['conn'] ?? null;
    if (!$conn instanceof mysqli) {
        return;
    }
    $item = google_analytics_product_item($conn, (int) ($context['product_id'] ?? 0));
    if (!$item) {
        return;
    }
    google_analytics_queue_event('view_item', [
        'currency' => 'INR',
        'value' => google_analytics_money($item['price'] ?? 0),
        'items' => [$item],
    ]);
}

function google_analytics_handle_checkout_view(array $context): void
{
    if (!google_analytics_enabled() || !google_analytics_enhanced_ecommerce_enabled()) {
        return;
    }
    $conn = $context['conn'] ?? null;
    if (!$conn instanceof mysqli) {
        return;
    }
    $payload = google_analytics_cart_payload($conn);
    if (empty($payload['items'])) {
        return;
    }
    google_analytics_queue_event('begin_checkout', $payload);
}

function google_analytics_handle_add_to_cart(array $context): void
{
    if (!google_analytics_enabled() || !google_analytics_enhanced_ecommerce_enabled()) {
        return;
    }
    $conn = $context['conn'] ?? null;
    if (!$conn instanceof mysqli) {
        return;
    }
    $quantity = google_analytics_quantity($context['quantity'] ?? 1);
    $item = google_analytics_product_item(
        $conn,
        (int) ($context['product_id'] ?? 0),
        $quantity,
        (int) ($context['variant_id'] ?? 0),
        (string) ($context['unit_type'] ?? '')
    );
    if (!$item) {
        return;
    }
    if (isset($context['unit_price']) && is_numeric($context['unit_price'])) {
        $item['price'] = google_analytics_money($context['unit_price']);
        $item['item_value'] = google_analytics_money(((float) $item['price']) * $quantity);
    }
    $payload = [
        'currency' => 'INR',
        'value' => google_analytics_money($item['item_value'] ?? (((float) ($item['price'] ?? 0)) * $quantity)),
        'items' => [$item],
    ];
    $event = ['name' => 'add_to_cart', 'payload' => $payload];
    if (empty($context['is_ajax'])) {
        google_analytics_queue_event($event['name'], $event['payload']);
    }
    $GLOBALS['google_analytics_last_event'] = $event;
}

function google_analytics_queue_purchase_from_order(array $context): void
{
    if (!google_analytics_enabled() || !google_analytics_enhanced_ecommerce_enabled()) {
        return;
    }
    $conn = $context['conn'] ?? ($GLOBALS['conn'] ?? null);
    if (!$conn instanceof mysqli) {
        return;
    }
    $orderId = (int) ($context['order_id'] ?? 0);
    if ($orderId <= 0) {
        return;
    }
    if (google_analytics_purchase_already_sent($orderId)) {
        return;
    }
    $payload = google_analytics_purchase_payload($conn, $orderId);
    if (!$payload || empty($payload['items'])) {
        return;
    }
    google_analytics_queue_event('purchase', $payload);
    google_analytics_mark_purchase_sent($orderId);
}

function google_analytics_handle_order_create_purchase(array $context): void
{
    if (strtolower((string) ($context['payment_method'] ?? '')) !== 'cod') {
        return;
    }
    google_analytics_queue_purchase_from_order($context);
}

function google_analytics_handle_paid_purchase(array $context): void
{
    google_analytics_queue_purchase_from_order($context);
}

function google_analytics_render_page_events(array $context): void
{
    if (!google_analytics_enabled()) {
        if (!google_analytics_consent_granted()) {
            unset($_SESSION['google_analytics_events']);
        }
        return;
    }

    $events = [[
        'name' => 'page_view',
        'payload' => google_analytics_page_view_payload($context),
    ]];
    $events = array_merge($events, google_analytics_pending_events());

    if (empty($events)) {
        return;
    }

    $nonce = (string) ($GLOBALS['cspNonce'] ?? '');
    ?>
    <script nonce="<?php echo e($nonce); ?>">
    (function () {
        var events = <?php echo json_encode($events, JSON_UNESCAPED_SLASHES); ?>;
        events.forEach(function (event) {
            if (event.name === 'page_view') {
                event.payload = event.payload || {};
                event.payload.page_title = event.payload.page_title || document.title || '';
            }
            if (window.amberGoogleAnalyticsTrack) {
                window.amberGoogleAnalyticsTrack(event.name, event.payload || {});
                return;
            }
            if (typeof gtag === 'function') {
                gtag('event', event.name, event.payload || {});
            }
        });
    })();
    </script>
    <?php
}
