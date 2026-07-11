<?php

add_action('app.init', 'back_in_stock_alert_handle_request', 25);
add_action('product.details.after', 'back_in_stock_alert_render_signup_form', 15);
add_action('cron.tick', 'back_in_stock_alert_send_notifications', 50);

function back_in_stock_alert_settings(): array
{
    return [
        'enabled' => (int) plugin_setting('back-in-stock-alert', 'enabled', 1) === 1,
        'batch_size' => max(1, min(200, (int) plugin_setting('back-in-stock-alert', 'batch_size', 50))),
        'cooldown_hours' => max(0, (int) plugin_setting('back-in-stock-alert', 'cooldown_hours', 1)),
        'from_name' => trim((string) plugin_setting('back-in-stock-alert', 'from_name', SiteContext::name())),
    ];
}

function back_in_stock_alert_table_ready(mysqli $conn): bool
{
    try {
        $stmt = $conn->prepare(
            "SELECT COUNT(*) AS total
             FROM information_schema.TABLES
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = 'back_in_stock_subscriptions'"
        );
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        return ((int) ($row['total'] ?? 0)) > 0;
    } catch (Throwable $e) {
        error_log('[back-in-stock-alert] table check failed: ' . $e->getMessage());
        return false;
    }
}

function back_in_stock_alert_normalize_email(string $email): string
{
    return strtolower(trim($email));
}

function back_in_stock_alert_product_url(int $productId, int $variantId = 0): string
{
    $url = '/fabric.php?id=' . $productId;
    if ($variantId > 0) {
        $url .= '&variant=' . $variantId;
    }
    return app_url($url);
}

function back_in_stock_alert_rate_limit_key(): string
{
    return 'back_in_stock_alert_subscribe_attempts';
}

function back_in_stock_alert_rate_limit_exceeded(): bool
{
    $key = back_in_stock_alert_rate_limit_key();
    $now = time();
    $windowSeconds = 15 * 60;
    $maxAttempts = 5;
    $attempts = isset($_SESSION[$key]) && is_array($_SESSION[$key]) ? $_SESSION[$key] : [];
    $attempts = array_values(array_filter(array_map('intval', $attempts), static function (int $ts) use ($now, $windowSeconds): bool {
        return $ts > 0 && ($now - $ts) <= $windowSeconds;
    }));
    if (count($attempts) >= $maxAttempts) {
        $_SESSION[$key] = $attempts;
        return true;
    }
    $attempts[] = $now;
    $_SESSION[$key] = $attempts;
    return false;
}

function back_in_stock_alert_stock_value(array $row, string $unitType): float
{
    $unitType = in_array($unitType, ['meter', 'piece', 'set'], true) ? $unitType : 'meter';
    return $unitType === 'meter'
        ? (float) ($row['stock_meters'] ?? 0)
        : (float) ($row['stock'] ?? 0);
}

function back_in_stock_alert_fetch_product(mysqli $conn, int $productId): ?array
{
    if ($productId <= 0) {
        return null;
    }
    $stmt = $conn->prepare(
        "SELECT id, name, sku, unit_type, stock, stock_meters, status, is_available
         FROM fabrics
         WHERE id = ? AND status = 'active'
         LIMIT 1"
    );
    $stmt->bind_param('i', $productId);
    $stmt->execute();
    return $stmt->get_result()->fetch_assoc() ?: null;
}

function back_in_stock_alert_fetch_variant(mysqli $conn, int $productId, int $variantId): ?array
{
    if ($productId <= 0 || $variantId <= 0) {
        return null;
    }
    $stmt = $conn->prepare(
        "SELECT id, fabric_id, color, size, sku, stock, stock_meters, is_active
         FROM fabric_variants
         WHERE id = ? AND fabric_id = ? AND is_active = 1
         LIMIT 1"
    );
    $stmt->bind_param('ii', $variantId, $productId);
    $stmt->execute();
    return $stmt->get_result()->fetch_assoc() ?: null;
}

function back_in_stock_alert_is_target_in_stock(mysqli $conn, int $productId, int $variantId = 0): bool
{
    $product = back_in_stock_alert_fetch_product($conn, $productId);
    if (!$product || empty($product['is_available'])) {
        return false;
    }
    $unitType = in_array((string) ($product['unit_type'] ?? ''), ['meter', 'piece', 'set'], true)
        ? (string) $product['unit_type']
        : 'meter';
    if ($variantId > 0) {
        $variant = back_in_stock_alert_fetch_variant($conn, $productId, $variantId);
        return $variant ? back_in_stock_alert_stock_value($variant, $unitType) > 0 : false;
    }
    if (back_in_stock_alert_stock_value($product, $unitType) > 0) {
        return true;
    }

    $stockColumn = $unitType === 'meter' ? 'stock_meters' : 'stock';
    $stmt = $conn->prepare(
        "SELECT id
         FROM fabric_variants
         WHERE fabric_id = ?
           AND is_active = 1
           AND {$stockColumn} > 0
         LIMIT 1"
    );
    $stmt->bind_param('i', $productId);
    $stmt->execute();
    return (bool) $stmt->get_result()->fetch_assoc();
}

function back_in_stock_alert_subscribe(mysqli $conn, int $productId, int $variantId, string $email, int $customerId): string
{
    $product = back_in_stock_alert_fetch_product($conn, $productId);
    if (!$product) {
        return 'Product is no longer available.';
    }
    if (back_in_stock_alert_has_variants($conn, $productId)) {
        if ($variantId <= 0) {
            return 'Please select an unavailable option.';
        }
        if (!back_in_stock_alert_fetch_variant($conn, $productId, $variantId)) {
            return 'Selected variant is no longer available.';
        }
    } elseif ($variantId > 0 && !back_in_stock_alert_fetch_variant($conn, $productId, $variantId)) {
        return 'Selected variant is no longer available.';
    }
    if (back_in_stock_alert_is_target_in_stock($conn, $productId, $variantId)) {
        return 'This item is already back in stock.';
    }

    $email = back_in_stock_alert_normalize_email($email);
    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return 'Please enter a valid email address.';
    }

    $token = bin2hex(random_bytes(32));
    $variantIdParam = $variantId > 0 ? $variantId : null;
    $customerIdParam = $customerId > 0 ? $customerId : null;

    $activeCheck = $conn->prepare(
        "SELECT id
         FROM back_in_stock_subscriptions
         WHERE email = ?
           AND product_id = ?
           AND variant_id <=> ?
           AND status IN ('pending','processing')
         LIMIT 1"
    );
    $activeCheck->bind_param('sii', $email, $productId, $variantIdParam);
    $activeCheck->execute();
    if ($activeCheck->get_result()->fetch_assoc()) {
        return '';
    }

    $reactivate = $conn->prepare(
        "UPDATE back_in_stock_subscriptions
         SET customer_id = ?,
             status = 'pending',
             unsubscribe_token = ?,
             requested_at = NOW(),
             notified_at = NULL,
             last_error = NULL,
             updated_at = NOW()
         WHERE email = ?
           AND product_id = ?
           AND variant_id <=> ?
           AND status IN ('sent','cancelled')
         ORDER BY updated_at DESC, id DESC
         LIMIT 1"
    );
    $reactivate->bind_param('issii', $customerIdParam, $token, $email, $productId, $variantIdParam);
    try {
        $reactivate->execute();
        if ($conn->affected_rows > 0) {
            return '';
        }
    } catch (mysqli_sql_exception $e) {
        if ((int) $e->getCode() === 1062) {
            return '';
        }
        error_log('[back-in-stock-alert] subscription reactivation failed: ' . $e->getMessage());
        return 'Unable to save your request right now.';
    }

    $stmt = $conn->prepare(
        "INSERT INTO back_in_stock_subscriptions
            (product_id, variant_id, customer_id, email, status, unsubscribe_token, requested_at)
         VALUES (?, ?, ?, ?, 'pending', ?, NOW())"
    );
    $stmt->bind_param('iiiss', $productId, $variantIdParam, $customerIdParam, $email, $token);
    try {
        $stmt->execute();
    } catch (mysqli_sql_exception $e) {
        if ((int) $e->getCode() === 1062) {
            return '';
        }
        error_log('[back-in-stock-alert] subscribe failed: ' . $e->getMessage());
        return 'Unable to save your request right now.';
    }
    return '';
}

function back_in_stock_alert_render_unsubscribe_page(string $title, string $message): never
{
    if (!headers_sent()) {
        http_response_code(200);
        header('Content-Type: text/html; charset=utf-8');
    }
    $safeTitle = e($title);
    $safeMessage = e($message);
    $siteName = e(SiteContext::name());
    $homeUrl = e(app_url('/'));
    echo '<!doctype html><html lang="en"><head><meta charset="utf-8">';
    echo '<meta name="viewport" content="width=device-width, initial-scale=1">';
    echo '<title>' . $safeTitle . ' | ' . $siteName . '</title>';
    echo '<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">';
    echo '</head><body class="bg-light">';
    echo '<main class="container py-5">';
    echo '<div class="mx-auto bg-white border rounded p-4 shadow-sm" style="max-width:560px">';
    echo '<h1 class="h4 mb-3">' . $safeTitle . '</h1>';
    echo '<p class="text-muted mb-4">' . $safeMessage . '</p>';
    echo '<a class="btn btn-primary" href="' . $homeUrl . '">Continue shopping</a>';
    echo '</div></main></body></html>';
    exit;
}

function back_in_stock_alert_handle_unsubscribe(mysqli $conn, string $token): never
{
    $token = trim($token);
    if (!preg_match('/^[a-f0-9]{64}$/i', $token)) {
        back_in_stock_alert_render_unsubscribe_page(
            'Stock Alert Unsubscribe',
            'This unsubscribe link is invalid or has expired.'
        );
    }

    $stmt = $conn->prepare(
        "UPDATE back_in_stock_subscriptions
         SET status = 'cancelled',
             updated_at = NOW()
         WHERE unsubscribe_token = ?
           AND status IN ('pending','processing','sent')"
    );
    $stmt->bind_param('s', $token);
    $stmt->execute();

    back_in_stock_alert_render_unsubscribe_page(
        'Stock Alert Unsubscribe',
        'Your stock alert preference has been updated.'
    );
}

function back_in_stock_alert_handle_request(array $context): void
{
    $settings = back_in_stock_alert_settings();
    if (!$settings['enabled'] || PHP_SAPI === 'cli') {
        return;
    }
    $conn = $GLOBALS['conn'] ?? null;
    if (!$conn instanceof mysqli || !back_in_stock_alert_table_ready($conn)) {
        return;
    }

    $unsubscribeToken = trim((string) ($_GET['back_in_stock_unsubscribe'] ?? ''));
    if ($unsubscribeToken !== '') {
        back_in_stock_alert_handle_unsubscribe($conn, $unsubscribeToken);
    }

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        return;
    }
    if (trim((string) ($_POST['back_in_stock_alert_action'] ?? '')) !== 'subscribe') {
        return;
    }
    $productId = (int) ($_POST['product_id'] ?? 0);
    $variantId = (int) ($_POST['variant_id'] ?? 0);
    $returnUrl = $productId > 0
        ? '/fabric.php?id=' . $productId . ($variantId > 0 ? '&variant=' . $variantId : '')
        : '/catalog.php';
    if (!verify_csrf()) {
        flash('error', 'Invalid session token. Please try again.');
        redirect($returnUrl);
    }
    if (trim((string) ($_POST['company_website'] ?? '')) !== '') {
        flash('success', 'We will email you when this item is back in stock.');
        redirect($returnUrl);
    }
    if (back_in_stock_alert_rate_limit_exceeded()) {
        flash('error', 'Too many stock alert requests. Please try again later.');
        redirect($returnUrl);
    }

    $customerId = (int) ($_SESSION['customer_id'] ?? 0);
    $email = trim((string) ($_POST['email'] ?? ''));
    if ($email === '' && $customerId > 0) {
        $stmt = $conn->prepare("SELECT email FROM customers WHERE id = ? LIMIT 1");
        $stmt->bind_param('i', $customerId);
        $stmt->execute();
        $customer = $stmt->get_result()->fetch_assoc() ?: [];
        $email = (string) ($customer['email'] ?? '');
    }

    $error = back_in_stock_alert_subscribe($conn, $productId, $variantId, $email, $customerId);
    if ($error !== '') {
        flash('error', $error);
    } else {
        flash('success', 'We will email you when this item is back in stock.');
    }
    redirect($returnUrl);
}

function back_in_stock_alert_out_of_stock_variants(mysqli $conn, array $product): array
{
    $productId = (int) ($product['id'] ?? 0);
    if ($productId <= 0) {
        return [];
    }
    $unitType = in_array((string) ($product['unit_type'] ?? ''), ['meter', 'piece', 'set'], true)
        ? (string) $product['unit_type']
        : 'meter';
    $variants = InventoryService::get_fabric_variants($conn, $productId);
    $rows = [];
    foreach ($variants as $variant) {
        if ((int) ($variant['is_active'] ?? 0) !== 1) {
            continue;
        }
        if (back_in_stock_alert_stock_value($variant, $unitType) > 0) {
            continue;
        }
        $labelParts = [];
        $color = trim((string) ($variant['color'] ?? ''));
        $size = trim((string) ($variant['size'] ?? ''));
        if ($color !== '' && strtolower($color) !== 'default') {
            $labelParts[] = $color;
        }
        if ($size !== '') {
            $labelParts[] = $size;
        }
        $rows[] = [
            'id' => (int) ($variant['id'] ?? 0),
            'label' => !empty($labelParts) ? implode(' / ', $labelParts) : 'Default option',
        ];
    }
    return $rows;
}

function back_in_stock_alert_has_variants(mysqli $conn, int $productId): bool
{
    if ($productId <= 0) {
        return false;
    }
    $stmt = $conn->prepare("SELECT id FROM fabric_variants WHERE fabric_id = ? AND is_active = 1 LIMIT 1");
    $stmt->bind_param('i', $productId);
    $stmt->execute();
    return (bool) $stmt->get_result()->fetch_assoc();
}

function back_in_stock_alert_render_signup_form(array $context): void
{
    $settings = back_in_stock_alert_settings();
    if (!$settings['enabled']) {
        return;
    }
    $conn = $context['conn'] ?? null;
    $product = $context['product'] ?? [];
    if (!$conn instanceof mysqli || !is_array($product) || !back_in_stock_alert_table_ready($conn)) {
        return;
    }

    $productId = (int) ($product['id'] ?? 0);
    if ($productId <= 0) {
        return;
    }
    $unitType = in_array((string) ($product['unit_type'] ?? ''), ['meter', 'piece', 'set'], true)
        ? (string) $product['unit_type']
        : 'meter';
    $variantOptions = back_in_stock_alert_out_of_stock_variants($conn, $product);
    $hasVariants = back_in_stock_alert_has_variants($conn, $productId);
    $productOutOfStock = !$hasVariants
        && (empty($product['is_available']) || back_in_stock_alert_stock_value($product, $unitType) <= 0);
    if (!$productOutOfStock && empty($variantOptions)) {
        return;
    }

    $customerId = (int) ($context['customer_id'] ?? 0);
    $defaultEmail = '';
    if ($customerId > 0) {
        $stmt = $conn->prepare("SELECT email FROM customers WHERE id = ? LIMIT 1");
        $stmt->bind_param('i', $customerId);
        $stmt->execute();
        $customer = $stmt->get_result()->fetch_assoc() ?: [];
        $defaultEmail = (string) ($customer['email'] ?? '');
    }
    $initialDisplay = $productOutOfStock ? '' : ' style="display:none"';
    $nonce = (string) ($GLOBALS['cspNonce'] ?? '');
    ?>
    <div class="mt-4 border-top pt-4" id="back-in-stock-alert-block"<?php echo $initialDisplay; ?>>
        <h5 class="mb-2">Notify me when available</h5>
        <form method="POST" action="/fabric.php?id=<?php echo (int) $productId; ?>" class="row g-2 align-items-end">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="back_in_stock_alert_action" value="subscribe">
            <input type="hidden" name="product_id" value="<?php echo (int) $productId; ?>">
            <input type="hidden" name="variant_id" id="back_in_stock_alert_variant_id" value="0">
            <div class="d-none" aria-hidden="true">
                <label for="back_in_stock_alert_company_website">Website</label>
                <input type="text" name="company_website" id="back_in_stock_alert_company_website" tabindex="-1" autocomplete="off">
            </div>
            <div class="col-md-8">
                <label class="form-label">Email</label>
                <input type="email" name="email" class="form-control" value="<?php echo e($defaultEmail); ?>" required>
            </div>
            <div class="col-md-4">
                <button type="submit" class="btn btn-outline-primary w-100">Notify me when available</button>
            </div>
        </form>
    </div>
    <?php if (!empty($variantOptions)): ?>
        <script nonce="<?php echo e($nonce); ?>">
            (function () {
                var block = document.getElementById('back-in-stock-alert-block');
                var variantInput = document.getElementById('back_in_stock_alert_variant_id');
                var selectedVariantInput = document.getElementById('selected_variant_id_add');
                var variants = Array.isArray(window.FABRIC_VARIANTS) ? window.FABRIC_VARIANTS : [];
                var unitType = <?php echo json_encode($unitType); ?>;
                if (!block || !variantInput || variants.length === 0) return;

                function findVariant(id) {
                    id = parseInt(String(id || '0'), 10);
                    if (!Number.isFinite(id) || id <= 0) return null;
                    for (var i = 0; i < variants.length; i++) {
                        if (parseInt(String(variants[i].id || '0'), 10) === id) {
                            return variants[i];
                        }
                    }
                    return null;
                }

                function isInStock(v) {
                    if (!v || parseInt(String(v.is_active || '0'), 10) !== 1) return true;
                    var stock = unitType === 'meter' ? parseFloat(v.stock_meters || 0) : parseFloat(v.stock || 0);
                    return Number.isFinite(stock) && stock > 0;
                }

                function sync() {
                    var selectedId = selectedVariantInput ? selectedVariantInput.value : '0';
                    var selectedVariant = findVariant(selectedId);
                    if (!selectedVariant || isInStock(selectedVariant)) {
                        block.style.display = 'none';
                        variantInput.value = '0';
                        return;
                    }
                    variantInput.value = String(selectedVariant.id || '0');
                    block.style.display = '';
                }

                sync();
                document.querySelectorAll('.color-swatch-btn, .size-option-btn').forEach(function (button) {
                    button.addEventListener('click', function () {
                        window.setTimeout(sync, 0);
                    });
                });
            })();
        </script>
    <?php endif; ?>
    <?php
}

function back_in_stock_alert_fetch_due_subscriptions(mysqli $conn, int $limit, int $cooldownHours): array
{
    $stmt = $conn->prepare(
        "SELECT bis.id, bis.product_id, bis.variant_id, bis.email, bis.unsubscribe_token,
                f.name AS product_name, f.unit_type, f.stock, f.stock_meters,
                c.name AS customer_name,
                v.color AS variant_color, v.size AS variant_size, v.stock AS variant_stock, v.stock_meters AS variant_stock_meters
         FROM back_in_stock_subscriptions bis
         JOIN fabrics f ON f.id = bis.product_id
         LEFT JOIN customers c ON c.id = bis.customer_id
         LEFT JOIN fabric_variants v ON v.id = bis.variant_id
         WHERE bis.status = 'pending'
           AND bis.requested_at <= DATE_SUB(NOW(), INTERVAL ? HOUR)
           AND bis.updated_at <= DATE_SUB(NOW(), INTERVAL ? HOUR)
           AND f.status = 'active'
           AND f.is_available = 1
           AND (
                (bis.variant_id IS NULL AND (
                    (f.unit_type IN ('piece','set') AND f.stock > 0)
                    OR (f.unit_type = 'meter' AND f.stock_meters > 0)
                    OR EXISTS (
                        SELECT 1
                        FROM fabric_variants av
                        WHERE av.fabric_id = f.id
                          AND av.is_active = 1
                          AND (
                              (f.unit_type IN ('piece','set') AND av.stock > 0)
                              OR (f.unit_type = 'meter' AND av.stock_meters > 0)
                          )
                        LIMIT 1
                    )
                ))
                OR
                (bis.variant_id IS NOT NULL AND v.is_active = 1 AND (
                    (f.unit_type IN ('piece','set') AND v.stock > 0)
                    OR (f.unit_type = 'meter' AND v.stock_meters > 0)
                ))
           )
         ORDER BY bis.requested_at ASC, bis.id ASC
         LIMIT ?"
    );
    $stmt->bind_param('iii', $cooldownHours, $cooldownHours, $limit);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    return is_array($rows) ? $rows : [];
}

function back_in_stock_alert_send_one_email(array $row, array $settings): array
{
    $productId = (int) ($row['product_id'] ?? 0);
    $variantId = (int) ($row['variant_id'] ?? 0);
    $email = back_in_stock_alert_normalize_email((string) ($row['email'] ?? ''));
    if ($productId <= 0 || $email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return ['ok' => false, 'error' => 'Invalid subscription recipient or product.'];
    }

    $productName = (string) ($row['product_name'] ?? 'Item');
    $color = trim((string) ($row['variant_color'] ?? ''));
    $size = trim((string) ($row['variant_size'] ?? ''));
    if (strtolower($color) === 'default') {
        $color = '';
    }
    $unitType = in_array((string) ($row['unit_type'] ?? ''), ['meter', 'piece', 'set'], true)
        ? (string) $row['unit_type']
        : 'meter';
    $stockValue = $variantId > 0
        ? ($unitType === 'meter' ? (float) ($row['variant_stock_meters'] ?? 0) : (float) ($row['variant_stock'] ?? 0))
        : ($unitType === 'meter' ? (float) ($row['stock_meters'] ?? 0) : (float) ($row['stock'] ?? 0));
    $availability = $stockValue > 0
        ? 'In stock (' . format_quantity_by_unit($stockValue, $unitType) . InventoryService::quantity_unit_suffix($unitType) . ' available)'
        : 'In stock';
    $productUrl = back_in_stock_alert_product_url($productId, $variantId);
    $unsubscribeUrl = app_url('/?back_in_stock_unsubscribe=' . urlencode((string) ($row['unsubscribe_token'] ?? '')));
    $template = email_template_build('back_in_stock_alert', [
        'customer_name' => (string) ($row['customer_name'] ?? ''),
        'product_name' => $productName,
        'variant_color' => $color,
        'variant_size' => $size,
        'availability' => $availability,
        'product_url' => $productUrl,
        'unsubscribe_url' => $unsubscribeUrl,
    ]);
    if ($template['subject'] === '' || $template['body'] === '') {
        return ['ok' => false, 'error' => 'Back-in-stock email template is unavailable.'];
    }

    try {
        $mail = EmailService::_mailer_base();
        $fromName = trim((string) ($settings['from_name'] ?? ''));
        if ($fromName !== '') {
            $mail->setFrom(_cfg('MAIL_FROM', contact_email()), $fromName);
        }
        $mail->addAddress($email);
        $mail->Subject = $template['subject'];
        $mail->Body = $template['body'];
        $mail->send();
        return ['ok' => true, 'error' => ''];
    } catch (Throwable $e) {
        error_log('[back-in-stock-alert] email failed: ' . $e->getMessage());
        return ['ok' => false, 'error' => $e->getMessage() !== '' ? $e->getMessage() : 'Email delivery failed.'];
    }
}

function back_in_stock_alert_claim_subscription(mysqli $conn, int $id): bool
{
    $claim = $conn->prepare(
        "UPDATE back_in_stock_subscriptions
         SET status = 'processing',
             last_error = NULL,
             updated_at = NOW()
         WHERE id = ?
           AND status = 'pending'"
    );
    $claim->bind_param('i', $id);
    $claim->execute();
    return $conn->affected_rows > 0;
}

function back_in_stock_alert_mark_sent(mysqli $conn, int $id): void
{
    $stmt = $conn->prepare(
        "UPDATE back_in_stock_subscriptions
         SET status = 'sent',
             notified_at = NOW(),
             last_error = NULL,
             updated_at = NOW()
         WHERE id = ?
           AND status = 'processing'"
    );
    $stmt->bind_param('i', $id);
    $stmt->execute();
}

function back_in_stock_alert_mark_failed(mysqli $conn, int $id, string $error): void
{
    $error = trim($error);
    if ($error === '') {
        $error = 'Email delivery failed.';
    }
    if (function_exists('mb_substr')) {
        $error = mb_substr($error, 0, 1000, 'UTF-8');
    } else {
        $error = substr($error, 0, 1000);
    }
    $stmt = $conn->prepare(
        "UPDATE back_in_stock_subscriptions
         SET status = 'pending',
             last_error = ?,
             updated_at = NOW()
         WHERE id = ?
           AND status = 'processing'"
    );
    $stmt->bind_param('si', $error, $id);
    $stmt->execute();
}

function back_in_stock_alert_send_notifications(array $context): void
{
    $settings = back_in_stock_alert_settings();
    if (!$settings['enabled']) {
        return;
    }
    $conn = $context['conn'] ?? ($GLOBALS['conn'] ?? null);
    if (!$conn instanceof mysqli || !back_in_stock_alert_table_ready($conn)) {
        return;
    }

    $rows = back_in_stock_alert_fetch_due_subscriptions($conn, (int) $settings['batch_size'], (int) $settings['cooldown_hours']);
    foreach ($rows as $row) {
        $id = (int) ($row['id'] ?? 0);
        if ($id <= 0) {
            continue;
        }
        if (!back_in_stock_alert_claim_subscription($conn, $id)) {
            continue;
        }

        try {
            $result = back_in_stock_alert_send_one_email($row, $settings);
            if (!empty($result['ok'])) {
                back_in_stock_alert_mark_sent($conn, $id);
                continue;
            }

            $error = (string) ($result['error'] ?? 'Email delivery failed.');
            error_log('[back-in-stock-alert] notification failed for subscription ' . $id . ': ' . $error);
            back_in_stock_alert_mark_failed($conn, $id, $error);
        } catch (Throwable $e) {
            $error = $e->getMessage() !== '' ? $e->getMessage() : 'Notification processing failed.';
            error_log('[back-in-stock-alert] notification exception for subscription ' . $id . ': ' . $error);
            back_in_stock_alert_mark_failed($conn, $id, $error);
        }
    }
}
