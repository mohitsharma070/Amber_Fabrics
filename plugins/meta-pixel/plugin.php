<?php

add_filter('security.csp_directives', 'meta_pixel_csp_directives', 10);
add_action('page.head', 'meta_pixel_render_base', 10);
add_action('page.footer', 'meta_pixel_render_page_events', 10);
add_action('cart.after_add', 'meta_pixel_queue_add_to_cart', 10);

function meta_pixel_id(): string
{
    return trim((string) plugin_setting('meta-pixel', 'pixel_id', ''));
}

function meta_pixel_enabled(): bool
{
    if (function_exists('marketing_consent_granted') && !marketing_consent_granted()) {
        return false;
    }
    return meta_pixel_id() !== '';
}

function meta_pixel_csp_directives($directives, array $context)
{
    if (!is_array($directives) || !meta_pixel_enabled()) {
        return $directives;
    }
    $directives['script-src'][] = 'https://connect.facebook.net';
    $directives['img-src'][] = 'https://www.facebook.com';
    $directives['connect-src'][] = 'https://www.facebook.com';
    $directives['connect-src'][] = 'https://connect.facebook.net';
    return $directives;
}

function meta_pixel_event_id(string $eventName, string $seed = ''): string
{
    $base = $eventName . '|' . $seed . '|' . session_id();
    return substr(hash('sha256', $base), 0, 24);
}

function meta_pixel_money($value): float
{
    return round(max(0, (float) $value), 2);
}

function meta_pixel_render_base(array $context): void
{
    if (!meta_pixel_enabled()) {
        return;
    }
    $pixelId = meta_pixel_id();
    $nonce = (string) ($GLOBALS['cspNonce'] ?? '');
    ?>
    <script nonce="<?php echo e($nonce); ?>">
    !function(f,b,e,v,n,t,s){if(f.fbq)return;n=f.fbq=function(){n.callMethod?
    n.callMethod.apply(n,arguments):n.queue.push(arguments)};if(!f._fbq)f._fbq=n;
    n.push=n;n.loaded=!0;n.version='2.0';n.queue=[];t=b.createElement(e);t.async=!0;
    t.src=v;s=b.getElementsByTagName(e)[0];s.parentNode.insertBefore(t,s)}
    (window, document,'script','https://connect.facebook.net/en_US/fbevents.js');
    fbq('init', <?php echo json_encode($pixelId); ?>);
    fbq('track', 'PageView');
    window.amberMetaPixelTrack = function (eventName, payload, eventId) {
        if (typeof fbq !== 'function') return;
        fbq('track', eventName, payload || {}, eventId ? {eventID: eventId} : {});
    };
    </script>
    <noscript><img height="1" width="1" style="display:none" src="https://www.facebook.com/tr?id=<?php echo e($pixelId); ?>&ev=PageView&noscript=1"></noscript>
    <?php
}

function meta_pixel_pending_events(): array
{
    if (empty($_SESSION['meta_pixel_events']) || !is_array($_SESSION['meta_pixel_events'])) {
        return [];
    }
    $events = $_SESSION['meta_pixel_events'];
    unset($_SESSION['meta_pixel_events']);
    return $events;
}

function meta_pixel_queue_event(string $eventName, array $payload, ?string $eventId = null): array
{
    $event = [
        'name' => $eventName,
        'payload' => $payload,
        'event_id' => $eventId ?: meta_pixel_event_id($eventName, json_encode($payload)),
    ];
    if (!isset($_SESSION['meta_pixel_events']) || !is_array($_SESSION['meta_pixel_events'])) {
        $_SESSION['meta_pixel_events'] = [];
    }
    $_SESSION['meta_pixel_events'][] = $event;
    return $event;
}

function meta_pixel_product_payload(mysqli $conn, int $productId): ?array
{
    if ($productId <= 0) {
        return null;
    }
    $stmt = $conn->prepare("SELECT id, name, price, sale_price, price_inr FROM fabrics WHERE id = ? AND status = 'active' LIMIT 1");
    $stmt->bind_param('i', $productId);
    $stmt->execute();
    $product = $stmt->get_result()->fetch_assoc();
    if (!$product) {
        return null;
    }
    $regular = (float) (($product['price'] !== null && $product['price'] !== '') ? $product['price'] : ($product['price_inr'] ?? 0));
    $sale = (float) ($product['sale_price'] ?? 0);
    $price = ($sale > 0 && $sale < $regular) ? $sale : $regular;
    return [
        'content_ids' => [(string) $product['id']],
        'content_name' => (string) $product['name'],
        'content_type' => 'product',
        'currency' => 'INR',
        'value' => meta_pixel_money($price),
    ];
}

function meta_pixel_checkout_payload(): array
{
    $cart = isset($_SESSION['cart']) && is_array($_SESSION['cart']) ? $_SESSION['cart'] : [];
    $contentIds = [];
    foreach (array_keys($cart) as $rawKey) {
        $parts = explode('::', (string) $rawKey, 2);
        $productId = (int) ($parts[0] ?? 0);
        if ($productId > 0) {
            $contentIds[] = (string) $productId;
        }
    }
    $contentIds = array_values(array_unique($contentIds));
    return [
        'content_ids' => $contentIds,
        'content_type' => 'product',
        'num_items' => count($cart),
        'currency' => 'INR',
    ];
}

function meta_pixel_purchase_payload(mysqli $conn, int $orderId): ?array
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
    $itemStmt = $conn->prepare("SELECT product_id FROM order_items WHERE order_id = ?");
    $itemStmt->bind_param('i', $orderId);
    $itemStmt->execute();
    $items = $itemStmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $contentIds = [];
    foreach ($items as $item) {
        $pid = (int) ($item['product_id'] ?? 0);
        if ($pid > 0) {
            $contentIds[] = (string) $pid;
        }
    }
    return [
        'content_ids' => array_values(array_unique($contentIds)),
        'content_type' => 'product',
        'currency' => 'INR',
        'value' => meta_pixel_money($order['total_amount'] ?? 0),
    ];
}

function meta_pixel_queue_add_to_cart(array $context): void
{
    if (!meta_pixel_enabled()) {
        return;
    }
    $conn = $context['conn'] ?? null;
    if (!$conn instanceof mysqli) {
        return;
    }
    $productId = (int) ($context['product_id'] ?? 0);
    $payload = meta_pixel_product_payload($conn, $productId);
    if (!$payload) {
        return;
    }
    $payload['value'] = meta_pixel_money(($payload['value'] ?? 0) * max(1, (float) ($context['quantity'] ?? 1)));
    $event = [
        'name' => 'AddToCart',
        'payload' => $payload,
        'event_id' => meta_pixel_event_id('AddToCart', $productId . '|' . time()),
    ];
    if (empty($context['is_ajax'])) {
        meta_pixel_queue_event($event['name'], $event['payload'], $event['event_id']);
    }
    $GLOBALS['meta_pixel_last_event'] = $event;
}

function meta_pixel_render_page_events(array $context): void
{
    if (!meta_pixel_enabled()) {
        return;
    }

    $conn = $GLOBALS['conn'] ?? null;
    if (!$conn instanceof mysqli) {
        return;
    }

    $page = (string) ($context['page'] ?? basename($_SERVER['PHP_SELF'] ?? ''));
    $events = meta_pixel_pending_events();

    if ($page === 'fabric.php') {
        $productId = (int) ($_GET['id'] ?? 0);
        $payload = meta_pixel_product_payload($conn, $productId);
        if ($payload) {
            $events[] = [
                'name' => 'ViewContent',
                'payload' => $payload,
                'event_id' => meta_pixel_event_id('ViewContent', (string) $productId),
            ];
        }
    }

    if ($page === 'checkout.php') {
        $payload = meta_pixel_checkout_payload();
        if (!empty($payload['content_ids'])) {
            $events[] = [
                'name' => 'InitiateCheckout',
                'payload' => $payload,
                'event_id' => meta_pixel_event_id('InitiateCheckout', implode(',', $payload['content_ids'])),
            ];
        }
    }

    if ($page === 'order-success.php') {
        $orderNumber = trim((string) ($_GET['order'] ?? ''));
        $customerId = (int) ($_SESSION['customer_id'] ?? 0);
        if ($orderNumber !== '' && $customerId > 0) {
            $stmt = $conn->prepare("SELECT id FROM orders WHERE order_number = ? AND customer_id = ? LIMIT 1");
            $stmt->bind_param('si', $orderNumber, $customerId);
            $stmt->execute();
            $order = $stmt->get_result()->fetch_assoc();
            $orderId = (int) ($order['id'] ?? 0);
            $flag = 'meta_pixel_purchase_' . $orderId;
            if ($orderId > 0 && empty($_SESSION[$flag])) {
                $payload = meta_pixel_purchase_payload($conn, $orderId);
                if ($payload) {
                    $events[] = [
                        'name' => 'Purchase',
                        'payload' => $payload,
                        'event_id' => meta_pixel_event_id('Purchase', $orderNumber),
                    ];
                    $_SESSION[$flag] = 1;
                }
            }
        }
    }

    if (empty($events)) {
        return;
    }

    $nonce = (string) ($GLOBALS['cspNonce'] ?? '');
    ?>
    <script nonce="<?php echo e($nonce); ?>">
    (function () {
        var events = <?php echo json_encode($events, JSON_UNESCAPED_SLASHES); ?>;
        events.forEach(function (event) {
            if (window.amberMetaPixelTrack) {
                window.amberMetaPixelTrack(event.name, event.payload || {}, event.event_id || '');
                return;
            }
            if (typeof fbq === 'function') {
                fbq('track', event.name, event.payload || {}, event.event_id ? {eventID: event.event_id} : {});
            }
        });
    })();
    </script>
    <?php
}
