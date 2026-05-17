<?php
require_once __DIR__ . '/email-templates/index.php';

// Harden session cookie settings before starting the session.
if (session_status() === PHP_SESSION_NONE) {
    if (PHP_SAPI !== 'cli' && !headers_sent()) {
        $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
        ini_set('session.use_strict_mode', '1');
        ini_set('session.use_only_cookies', '1');
        ini_set('session.use_trans_sid', '0');
        session_set_cookie_params([
            'lifetime' => 0,
            'path' => '/',
            'secure' => $secure,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        session_start();
    } elseif (!isset($_SESSION) || !is_array($_SESSION)) {
        $_SESSION = [];
    }
}

/**
 * CSRF token helpers.
 */
function csrf_token(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function csrf_field(): string
{
    $token = e(csrf_token());
    return '<input type="hidden" name="csrf_token" value="' . $token . '">';
}

function verify_csrf(): bool
{
    return isset($_POST['csrf_token'], $_SESSION['csrf_token']) &&
        hash_equals($_SESSION['csrf_token'], $_POST['csrf_token']);
}

/**
 * Marketing consent helpers (Pixel/CAPI/UTM).
 */
function marketing_consent_cookie_name(): string
{
    return 'amber_marketing_consent';
}

function marketing_consent_status(): string
{
    $sessionStatus = strtolower(trim((string) ($_SESSION['marketing_consent'] ?? '')));
    if (in_array($sessionStatus, ['granted', 'denied'], true)) {
        return $sessionStatus;
    }

    $cookieStatus = strtolower(trim((string) ($_COOKIE[marketing_consent_cookie_name()] ?? '')));
    if (in_array($cookieStatus, ['granted', 'denied'], true)) {
        $_SESSION['marketing_consent'] = $cookieStatus;
        return $cookieStatus;
    }

    return 'unknown';
}

function marketing_consent_granted(): bool
{
    return marketing_consent_status() === 'granted';
}

function marketing_consent_denied(): bool
{
    return marketing_consent_status() === 'denied';
}

function marketing_consent_clear_cookie(string $cookieName): void
{
    if (headers_sent() || $cookieName === '') {
        return;
    }

    $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
    setcookie($cookieName, '', [
        'expires' => time() - 3600,
        'path' => '/',
        'secure' => $secure,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    unset($_COOKIE[$cookieName]);
}

function marketing_consent_clear_tracking_data(): void
{
    unset($_SESSION['utm_attribution'], $_SESSION['meta_fbp'], $_SESSION['meta_fbc']);
    marketing_consent_clear_cookie('amber_utm');
    marketing_consent_clear_cookie('_fbp');
    marketing_consent_clear_cookie('_fbc');
}

function marketing_consent_set(string $status, int $days = 180): bool
{
    $status = strtolower(trim($status));
    if (!in_array($status, ['granted', 'denied'], true)) {
        return false;
    }

    $_SESSION['marketing_consent'] = $status;
    if (headers_sent()) {
        return false;
    }

    $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
    setcookie(marketing_consent_cookie_name(), $status, [
        'expires' => time() + (max(1, $days) * 86400),
        'path' => '/',
        'secure' => $secure,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);

    if ($status === 'denied') {
        marketing_consent_clear_tracking_data();
    }

    return true;
}



/**
 * Basic redirect helper.
 */
function redirect(string $path): void
{
    header("Location: {$path}");
    exit;
}

/**
 * Flash messaging stored in session.
 */
function flash(string $key, ?string $message = null): ?string
{
    if ($message !== null) {
        $_SESSION['flash'][$key] = $message;
        return null;
    }

    if (!empty($_SESSION['flash'][$key])) {
        $msg = $_SESSION['flash'][$key];
        unset($_SESSION['flash'][$key]);
        return $msg;
    }

    return null;
}

/**
 * Escape output for HTML contexts.
 */
function e(?string $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

/**
 * Enforce a baseline password policy for customer/admin credentials.
 */
function password_strength_error(string $password): ?string
{
    if (strlen($password) < 10) {
        return 'Password must be at least 10 characters.';
    }
    if (!preg_match('/[A-Z]/', $password)) {
        return 'Password must include at least one uppercase letter.';
    }
    if (!preg_match('/[a-z]/', $password)) {
        return 'Password must include at least one lowercase letter.';
    }
    if (!preg_match('/\d/', $password)) {
        return 'Password must include at least one number.';
    }
    return null;
}

/**
 * Normalize meter quantities to 2 decimals with a minimum of 1 meter.
 */
function normalize_meter_quantity($value, float $min = 1.0): float
{
    $qty = is_numeric($value) ? (float) $value : $min;
    if ($qty < $min) {
        $qty = $min;
    }
    return round($qty, 2);
}

/**
 * Display meter quantities without unnecessary trailing zeros.
 */
function format_meter_quantity($value): string
{
    $qty = normalize_meter_quantity($value);
    $formatted = number_format($qty, 2, '.', '');
    return rtrim(rtrim($formatted, '0'), '.');
}

/**
 * Normalize piece quantities to whole numbers with a minimum quantity.
 */
function normalize_piece_quantity($value, int $min = 1): int
{
    $qty = is_numeric($value) ? (int) round((float) $value) : $min;
    return max($min, $qty);
}

/**
 * Normalize quantities based on unit type.
 */
function normalize_quantity_by_unit($value, string $unitType, float $meterMin = 1.0)
{
    return ($unitType === 'piece' || $unitType === 'set')
        ? normalize_piece_quantity($value, 1)
        : normalize_meter_quantity($value, $meterMin);
}

/**
 * Format quantities based on unit type.
 */
function format_quantity_by_unit($value, string $unitType): string
{
    return ($unitType === 'piece' || $unitType === 'set')
        ? (string) normalize_piece_quantity($value, 1)
        : format_meter_quantity($value);
}

function normalize_units_per_set($value): int
{
    $n = is_numeric($value) ? (int) round((float) $value) : 1;
    return max(1, $n);
}

function format_pack_label(int $unitsPerSet): string
{
    return 'Pack of ' . max(1, (int) $unitsPerSet);
}

/**
 * Build normalized cart/wishlist line items from session cart maps.
 *
 * Returns:
 * - items: hydrated cart lines
 * - removed_keys: cart keys rejected due to missing/inactive products/variants
 * - invalid_variant_found: whether any variant mismatch was detected
 */
function cart_hydrate_items(mysqli $conn, array $source, array $sizeMap = [], array $meterMap = []): array
{
    if (empty($source)) {
        return ['items' => [], 'removed_keys' => [], 'invalid_variant_found' => false];
    }

    $ids = [];
    $variantIds = [];
    foreach (array_keys($source) as $key) {
        [$pid, $variantId] = cart_parse_key((string) $key);
        if ($pid > 0) {
            $ids[] = $pid;
        }
        if ($variantId > 0) {
            $variantIds[] = $variantId;
        }
    }

    $ids = array_values(array_unique($ids));
    $variantIds = array_values(array_unique($variantIds));
    if (empty($ids)) {
        return ['items' => [], 'removed_keys' => array_keys($source), 'invalid_variant_found' => false];
    }

    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $types = str_repeat('i', count($ids));
    $sql = "SELECT id, name, image, unit_type, price, sale_price, price_inr, stock, stock_meters, is_available, dispatch_time
            FROM fabrics
            WHERE status = 'active' AND id IN ($placeholders)";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param($types, ...$ids);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

    $rowMap = [];
    foreach ($rows as $row) {
        $rowMap[(int) $row['id']] = $row;
    }

    $variantMap = !empty($variantIds) ? get_variants_by_ids($conn, $variantIds) : [];
    $items = [];
    $removedKeys = [];
    $invalidVariantFound = false;

    foreach ($source as $cartKey => $sourceQty) {
        [$pid, $variantId] = cart_parse_key((string) $cartKey);
        if ($pid <= 0 || !isset($rowMap[$pid])) {
            $removedKeys[] = (string) $cartKey;
            continue;
        }

        $row = $rowMap[$pid];
        $variant = ($variantId > 0 && isset($variantMap[$variantId])) ? $variantMap[$variantId] : null;
        if ($variantId > 0 && (!$variant || (int) ($variant['fabric_id'] ?? 0) !== $pid || (int) ($variant['is_active'] ?? 0) !== 1)) {
            $removedKeys[] = (string) $cartKey;
            $invalidVariantFound = true;
            continue;
        }

        $unitType = in_array((string) ($row['unit_type'] ?? ''), ['meter', 'piece', 'set'], true)
            ? (string) $row['unit_type']
            : 'meter';
        $qty = normalize_quantity_by_unit($sourceQty ?? 1, $unitType);
        $meterLength = null;
        $bundleQty = null;
        if ($unitType === 'meter' && isset($meterMap[$cartKey]) && is_numeric($meterMap[$cartKey]) && (float) $meterMap[$cartKey] > 0) {
            $meterLength = (float) $meterMap[$cartKey];
            $bundleQty = max(1, (int) round($qty / $meterLength));
        }

        $regular = (float) (($row['price'] !== null && $row['price'] !== '') ? $row['price'] : ($row['price_inr'] ?? 0));
        $sale = (float) ($row['sale_price'] ?? 0);
        if ($variant && $variant['price_override'] !== null && (float) $variant['price_override'] > 0) {
            $unitPrice = (float) $variant['price_override'];
        } else {
            $unitPrice = ($sale > 0 && $sale < $regular) ? $sale : $regular;
        }
        $lineTotal = round($unitPrice * $qty, 2);

        $unitLabel = 'meter';
        if ($unitType === 'piece') {
            $unitLabel = ((float) $qty === 1.0) ? 'piece' : 'pieces';
        } elseif ($unitType === 'set') {
            $unitLabel = ((float) $qty === 1.0) ? 'set' : 'sets';
        }

        if ($variant) {
            $displayStock = ($unitType === 'piece' || $unitType === 'set')
                ? (float) ($variant['stock'] ?? 0)
                : (float) ($variant['stock_meters'] ?? 0);
        } else {
            $displayStock = ($unitType === 'piece' || $unitType === 'set')
                ? (float) ($row['stock'] ?? 0)
                : (float) ($row['stock_meters'] ?? 0);
        }
        $inStock = !empty($row['is_available']) && $displayStock > 0;
        $maxBundleQty = null;
        if ($unitType === 'meter' && $meterLength !== null && $meterLength > 0 && $displayStock > 0) {
            $maxBundleQty = max(1, (int) floor($displayStock / $meterLength));
        }

        $selectedColor = ($variant !== null) ? (string) ($variant['color'] ?? '') : '';
        $selectedSize = ($variant !== null)
            ? variant_size_display($variant, $unitType)
            : (string) ($sizeMap[$cartKey] ?? '');
        $unitsPerSet = ($variant !== null) ? (int) ($variant['units_per_set'] ?? 0) : 0;
        $packLabel = ($variant !== null) ? trim((string) ($variant['pack_label'] ?? '')) : '';

        $displayImage = trim((string) ($row['image'] ?? ''));
        if ($variant !== null) {
            foreach (['image', 'image2', 'image3', 'image4'] as $mediaKey) {
                $candidate = trim((string) ($variant[$mediaKey] ?? ''));
                if ($candidate !== '') {
                    $displayImage = $candidate;
                    break;
                }
            }
        }

        $items[] = [
            'cart_key' => (string) $cartKey,
            'id' => $pid,
            'name' => (string) $row['name'],
            'image' => $displayImage,
            'quantity' => $qty,
            'quantity_text' => format_quantity_by_unit($qty, $unitType),
            'quantity_unit_label' => $unitLabel,
            'unit_type' => $unitType,
            'selected_color' => $selectedColor,
            'selected_size' => $selectedSize,
            'variant_id' => $variantId,
            'regular_price' => $regular,
            'sale_price' => $sale,
            'unit_price' => $unitPrice,
            'subtotal' => $lineTotal,
            'stock' => $displayStock,
            'in_stock' => $inStock,
            'dispatch_time' => trim((string) ($row['dispatch_time'] ?? '')),
            'meter_length' => $meterLength,
            'bundle_quantity' => $bundleQty,
            'max_bundle_qty' => $maxBundleQty,
            'units_per_set' => $unitsPerSet,
            'pack_label' => $packLabel,
        ];
    }

    usort($items, static function (array $a, array $b): int {
        $cmp = $a['id'] <=> $b['id'];
        if ($cmp !== 0) {
            return $cmp;
        }
        return strcmp((string) ($a['selected_color'] ?? ''), (string) ($b['selected_color'] ?? ''))
            ?: strcmp((string) ($a['selected_size'] ?? ''), (string) ($b['selected_size'] ?? ''));
    });

    return [
        'items' => $items,
        'removed_keys' => array_values(array_unique($removedKeys)),
        'invalid_variant_found' => $invalidVariantFound,
    ];
}

function cart_items_subtotal(array $items): float
{
    $subtotal = 0.0;
    foreach ($items as $item) {
        $subtotal = round($subtotal + (float) ($item['subtotal'] ?? 0), 2);
    }
    return $subtotal;
}

function variant_size_display(array $variant, string $unitType): string
{
    $size = trim((string) ($variant['size'] ?? ''));
    if ($size !== '') {
        return $size;
    }

    if ($unitType === 'set') {
        $packLabel = trim((string) ($variant['pack_label'] ?? ''));
        $unitsPerSet = (int) ($variant['units_per_set'] ?? 0);
        if ($packLabel !== '') {
            return $packLabel;
        }
        if ($unitsPerSet > 0) {
            return format_pack_label($unitsPerSet);
        }
    }
    return '';
}

/**
 * Normalize product size options from comma/pipe/slash separated DB value.
 */
function parse_size_options(?string $sizeRaw): array
{
    $sizeRaw = (string) $sizeRaw;
    if ($sizeRaw === '') {
        return [];
    }
    $parts = preg_split('/[,\|\/]+/', $sizeRaw);
    if (!is_array($parts)) {
        return [];
    }
    $sizes = [];
    foreach ($parts as $part) {
        $clean = trim((string) $part);
        if ($clean !== '') {
            $sizes[] = $clean;
        }
    }
    return array_values(array_unique($sizes));
}

/**
 * Shared India shipping + COD fee calculation.
 */
function checkout_shipping_breakdown(float $subtotal, string $country, string $paymentMethod, bool $codFeeApply = true): array
{
    $isIndia = strcasecmp(trim($country), 'india') === 0;
    $baseShipping = 0.0;
    $codFee = 0.0;
    if ($isIndia) {
        $baseShipping = ($subtotal >= 999.0) ? 0.0 : 70.0;
        $codFee = (strtolower($paymentMethod) === 'cod' && $codFeeApply) ? 50.0 : 0.0;
    }
    return [
        'is_india' => $isIndia,
        'base_shipping' => round($baseShipping, 2),
        'cod_fee' => round($codFee, 2),
        'shipping_total' => round($baseShipping + $codFee, 2),
    ];
}

/**
 * Adjust stock for a single fabric row with in-transaction row lock.
 * $direction must be "decrease" or "increase".
 */
function adjust_fabric_stock(mysqli $conn, int $fabricId, string $unitType, float $qty, string $direction = 'decrease'): void
{
    if ($fabricId <= 0) {
        throw new RuntimeException('Invalid fabric id for stock update.');
    }
    if ($qty <= 0) {
        return;
    }
    $unitType = in_array($unitType, ['meter', 'piece', 'set'], true) ? $unitType : 'meter';
    $direction = strtolower($direction) === 'increase' ? 'increase' : 'decrease';

    $lock = $conn->prepare("SELECT id, stock, stock_meters FROM fabrics WHERE id = ? FOR UPDATE");
    $lock->bind_param('i', $fabricId);
    $lock->execute();
    $fabric = $lock->get_result()->fetch_assoc();
    if (!$fabric) {
        throw new RuntimeException('Fabric not found for stock update.');
    }

    $useMeters = $unitType === 'meter';
    if ($useMeters) {
        $amount = round($qty, 2);
        if ($direction === 'decrease') {
            $available = (float) ($fabric['stock_meters'] ?? 0);
            if ($available < $amount) {
                throw new RuntimeException('Insufficient stock during order confirmation.');
            }
            $stmt = $conn->prepare("UPDATE fabrics SET stock_meters = stock_meters - ? WHERE id = ? AND stock_meters >= ?");
            $stmt->bind_param('did', $amount, $fabricId, $amount);
        } else {
            $stmt = $conn->prepare("UPDATE fabrics SET stock_meters = stock_meters + ? WHERE id = ?");
            $stmt->bind_param('di', $amount, $fabricId);
        }
    } else {
        $amount = (int) round($qty);
        if ($amount <= 0) {
            return;
        }
        if ($direction === 'decrease') {
            $available = (int) round((float) ($fabric['stock'] ?? 0));
            if ($available < $amount) {
                throw new RuntimeException('Insufficient stock during order confirmation.');
            }
            $stmt = $conn->prepare("UPDATE fabrics SET stock = stock - ? WHERE id = ? AND stock >= ?");
            $stmt->bind_param('iii', $amount, $fabricId, $amount);
        } else {
            $stmt = $conn->prepare("UPDATE fabrics SET stock = stock + ? WHERE id = ?");
            $stmt->bind_param('ii', $amount, $fabricId);
        }
    }
    $stmt->execute();
    if ($conn->affected_rows === 0) {
        throw new RuntimeException('Stock update conflict for fabric ' . $fabricId . '. Please try again.');
    }
}

/**
 * Adjust stock for a single variant row with in-transaction row lock.
 * $direction must be "decrease" or "increase".
 */
function adjust_variant_stock(mysqli $conn, int $variantId, string $unitType, float $qty, string $direction = 'decrease'): void
{
    if ($variantId <= 0) {
        throw new RuntimeException('Invalid variant id for stock update.');
    }
    if ($qty <= 0) {
        return;
    }
    $unitType = in_array($unitType, ['meter', 'piece', 'set'], true) ? $unitType : 'meter';
    $direction = strtolower($direction) === 'increase' ? 'increase' : 'decrease';

    $lock = $conn->prepare("SELECT id, stock, stock_meters FROM fabric_variants WHERE id = ? FOR UPDATE");
    $lock->bind_param('i', $variantId);
    $lock->execute();
    $variant = $lock->get_result()->fetch_assoc();
    if (!$variant) {
        throw new RuntimeException('Variant not found for stock update.');
    }

    $useMeters = $unitType === 'meter';
    if ($useMeters) {
        $amount = round($qty, 2);
        if ($direction === 'decrease') {
            $available = (float) ($variant['stock_meters'] ?? 0);
            if ($available < $amount) {
                throw new RuntimeException('Insufficient variant stock during order confirmation.');
            }
            $stmt = $conn->prepare("UPDATE fabric_variants SET stock_meters = stock_meters - ? WHERE id = ? AND stock_meters >= ?");
            $stmt->bind_param('did', $amount, $variantId, $amount);
        } else {
            $stmt = $conn->prepare("UPDATE fabric_variants SET stock_meters = stock_meters + ? WHERE id = ?");
            $stmt->bind_param('di', $amount, $variantId);
        }
    } else {
        $amount = (int) round($qty);
        if ($amount <= 0) {
            return;
        }
        if ($direction === 'decrease') {
            $available = (int) round((float) ($variant['stock'] ?? 0));
            if ($available < $amount) {
                throw new RuntimeException('Insufficient variant stock during order confirmation.');
            }
            $stmt = $conn->prepare("UPDATE fabric_variants SET stock = stock - ? WHERE id = ? AND stock >= ?");
            $stmt->bind_param('iii', $amount, $variantId, $amount);
        } else {
            $stmt = $conn->prepare("UPDATE fabric_variants SET stock = stock + ? WHERE id = ?");
            $stmt->bind_param('ii', $amount, $variantId);
        }
    }
    $stmt->execute();
    if ($conn->affected_rows === 0) {
        throw new RuntimeException('Stock update conflict for variant ' . $variantId . '. Please try again.');
    }
}

/**
 * Return all variants for a fabric ordered by sort_order then id.
 */
function get_fabric_variants(mysqli $conn, int $fabricId): array
{
    $stmt = $conn->prepare(
        "SELECT id, fabric_id, color, size, sku, image, image2, image3, image4, video, pack_label, units_per_set, price_override, stock, stock_meters, is_active, sort_order
         FROM fabric_variants
         WHERE fabric_id = ?
         ORDER BY sort_order ASC, id ASC"
    );
    $stmt->bind_param('i', $fabricId);
    $stmt->execute();
    return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
}

/**
 * Return a single variant by primary key, or null if not found.
 */
function get_variant_by_id(mysqli $conn, int $variantId): ?array
{
    if ($variantId <= 0) {
        return null;
    }
    $stmt = $conn->prepare(
        "SELECT id, fabric_id, color, size, sku, image, image2, image3, image4, video, pack_label, units_per_set, price_override, stock, stock_meters, is_active, sort_order
         FROM fabric_variants
         WHERE id = ?
         LIMIT 1"
    );
    $stmt->bind_param('i', $variantId);
    $stmt->execute();
    return $stmt->get_result()->fetch_assoc() ?: null;
}

function shipping_quote_store(
    float $subtotal,
    string $country,
    string $pincode,
    string $paymentMethod,
    float $baseShipping,
    float $codFee,
    float $shippingTotal,
    string $source = 'manual',
    string $courierName = '',
    int $courierId = 0
): string {
    if (!isset($_SESSION['shipping_quotes']) || !is_array($_SESSION['shipping_quotes'])) {
        $_SESSION['shipping_quotes'] = [];
    }
    $now = time();
    foreach ($_SESSION['shipping_quotes'] as $k => $v) {
        $created = (int) (($v['created_at'] ?? 0));
        if ($created <= 0 || ($now - $created) > 1800) {
            unset($_SESSION['shipping_quotes'][$k]);
        }
    }
    $token = bin2hex(random_bytes(16));
    $_SESSION['shipping_quotes'][$token] = [
        'subtotal' => round($subtotal, 2),
        'country' => strtolower(trim($country)),
        'pincode' => trim($pincode),
        'payment_method' => strtolower(trim($paymentMethod)),
        'base_shipping' => round($baseShipping, 2),
        'cod_fee' => round($codFee, 2),
        'shipping_total' => round($shippingTotal, 2),
        'source' => $source,
        'courier_name' => $courierName,
        'courier_id' => max(0, (int) $courierId),
        'created_at' => $now,
    ];
    try {
        $customerId = (int) ($_SESSION['customer_id'] ?? 0);
        $expiresAt = date('Y-m-d H:i:s', $now + 1800);
        $stmt = $conn = $GLOBALS['conn'] ?? null;
        if ($stmt instanceof mysqli) {
            $ins = $stmt->prepare(
                "INSERT INTO shipping_quotes (
                    quote_token, customer_id, subtotal, country, pincode, payment_method,
                    base_shipping, cod_fee, shipping_total, source, courier_name, courier_id, expires_at
                 ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE
                    customer_id = VALUES(customer_id),
                    subtotal = VALUES(subtotal),
                    country = VALUES(country),
                    pincode = VALUES(pincode),
                    payment_method = VALUES(payment_method),
                    base_shipping = VALUES(base_shipping),
                    cod_fee = VALUES(cod_fee),
                    shipping_total = VALUES(shipping_total),
                    source = VALUES(source),
                    courier_name = VALUES(courier_name),
                    courier_id = VALUES(courier_id),
                    expires_at = VALUES(expires_at)"
            );
            $countryNorm = strtolower(trim($country));
            $pincodeNorm = trim($pincode);
            $methodNorm = strtolower(trim($paymentMethod));
            $baseShipping = round($baseShipping, 2);
            $codFee = round($codFee, 2);
            $shippingTotal = round($shippingTotal, 2);
            $courierId = max(0, (int) $courierId);
            $ins->bind_param(
                'sidsssdddssis',
                $token,
                $customerId,
                $subtotal,
                $countryNorm,
                $pincodeNorm,
                $methodNorm,
                $baseShipping,
                $codFee,
                $shippingTotal,
                $source,
                $courierName,
                $courierId,
                $expiresAt
            );
            $ins->execute();
        }
    } catch (Throwable $e) {
        error_log('[amberfabrics] shipping quote persist failed: ' . $e->getMessage());
    }
    return $token;
}

function shipping_quote_get(string $token): ?array
{
    $token = trim($token);
    if ($token === '') {
        return null;
    }
    $row = null;
    if (!empty($_SESSION['shipping_quotes']) && is_array($_SESSION['shipping_quotes'])) {
        $row = $_SESSION['shipping_quotes'][$token] ?? null;
    }
    if (!is_array($row)) {
        try {
            $conn = $GLOBALS['conn'] ?? null;
            if ($conn instanceof mysqli) {
                $stmt = $conn->prepare(
                    "SELECT subtotal, country, pincode, payment_method, base_shipping, cod_fee, shipping_total, source, courier_name, courier_id, expires_at
                     FROM shipping_quotes
                     WHERE quote_token = ?
                     LIMIT 1"
                );
                $stmt->bind_param('s', $token);
                $stmt->execute();
                $dbRow = $stmt->get_result()->fetch_assoc();
                if (is_array($dbRow)) {
                    $exp = strtotime((string) ($dbRow['expires_at'] ?? ''));
                    if ($exp !== false && $exp > time()) {
                        $row = [
                            'subtotal' => (float) ($dbRow['subtotal'] ?? 0),
                            'country' => (string) ($dbRow['country'] ?? ''),
                            'pincode' => (string) ($dbRow['pincode'] ?? ''),
                            'payment_method' => (string) ($dbRow['payment_method'] ?? ''),
                            'base_shipping' => (float) ($dbRow['base_shipping'] ?? 0),
                            'cod_fee' => (float) ($dbRow['cod_fee'] ?? 0),
                            'shipping_total' => (float) ($dbRow['shipping_total'] ?? 0),
                            'source' => (string) ($dbRow['source'] ?? 'manual'),
                            'courier_name' => (string) ($dbRow['courier_name'] ?? ''),
                            'courier_id' => (int) ($dbRow['courier_id'] ?? 0),
                            'created_at' => time(),
                        ];
                    }
                }
            }
        } catch (Throwable $e) {
            error_log('[amberfabrics] shipping quote read failed: ' . $e->getMessage());
        }
        if (!is_array($row)) {
            return null;
        }
    }
    $created = (int) ($row['created_at'] ?? 0);
    if ($created <= 0 || (time() - $created) > 1800) {
        unset($_SESSION['shipping_quotes'][$token]);
        return null;
    }
    return $row;
}

/**
 * Return the first active variant for a fabric (fallback for legacy quick-add flows).
 */
function get_first_active_variant(mysqli $conn, int $fabricId): ?array
{
    if ($fabricId <= 0) {
        return null;
    }
    $stmt = $conn->prepare(
        "SELECT id, fabric_id, color, size, sku, image, image2, image3, image4, video, pack_label, units_per_set, price_override, stock, stock_meters, is_active, sort_order
         FROM fabric_variants
         WHERE fabric_id = ? AND is_active = 1
         ORDER BY sort_order ASC, id ASC
         LIMIT 1"
    );
    $stmt->bind_param('i', $fabricId);
    $stmt->execute();
    return $stmt->get_result()->fetch_assoc() ?: null;
}

/**
 * Return the first active in-stock variant for a fabric.
 * Falls back to first active variant when all are out of stock.
 */
function get_first_active_in_stock_variant(mysqli $conn, int $fabricId, string $unitType): ?array
{
    if ($fabricId <= 0) {
        return null;
    }
    $isWhole = in_array($unitType, ['piece', 'set'], true);
    $stockColumn = $isWhole ? 'stock' : 'stock_meters';
    $stmt = $conn->prepare(
        "SELECT id, fabric_id, color, size, sku, image, image2, image3, image4, video, pack_label, units_per_set, price_override, stock, stock_meters, is_active, sort_order
         FROM fabric_variants
         WHERE fabric_id = ? AND is_active = 1
         ORDER BY CASE WHEN COALESCE($stockColumn, 0) > 0 THEN 0 ELSE 1 END, sort_order ASC, id ASC
         LIMIT 1"
    );
    $stmt->bind_param('i', $fabricId);
    $stmt->execute();
    return $stmt->get_result()->fetch_assoc() ?: null;
}

/**
 * Find an active variant by fabric, color and size. Returns null if not found.
 */
function find_variant(mysqli $conn, int $fabricId, string $color, string $size): ?array
{
    $stmt = $conn->prepare(
        "SELECT id, fabric_id, color, size, sku, image, image2, image3, image4, video, pack_label, units_per_set, price_override, stock, stock_meters, is_active, sort_order
         FROM fabric_variants
         WHERE fabric_id = ? AND color = ? AND size = ? AND is_active = 1
         LIMIT 1"
    );
    $stmt->bind_param('iss', $fabricId, $color, $size);
    $stmt->execute();
    return $stmt->get_result()->fetch_assoc() ?: null;
}

/**
 * Batch-fetch active variants by a list of variant IDs.
 * Returns array keyed by variant id.
 */
function get_variants_by_ids(mysqli $conn, array $variantIds): array
{
    $ids = array_values(array_filter(array_map('intval', $variantIds)));
    if (empty($ids)) {
        return [];
    }
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $types = str_repeat('i', count($ids));
    $stmt = $conn->prepare(
        "SELECT id, fabric_id, color, size, sku, image, image2, image3, image4, video, pack_label, units_per_set, price_override, stock, stock_meters, is_active
         FROM fabric_variants
         WHERE id IN ($placeholders)"
    );
    $stmt->bind_param($types, ...$ids);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $map = [];
    foreach ($rows as $row) {
        $map[(int) $row['id']] = $row;
    }
    return $map;
}

/**
 * Restore all order item quantities back into inventory.
 */
function orders_supports_inventory_tracking(mysqli $conn): bool
{
    static $checked = false;
    static $supported = false;
    if ($checked) {
        return $supported;
    }
    $checked = true;
    try {
        $stmt = $conn->prepare(
            "SELECT SUM(CASE WHEN COLUMN_NAME IN ('inventory_reserved_at', 'inventory_restored_at') THEN 1 ELSE 0 END) AS total
             FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = 'orders'
               AND COLUMN_NAME IN ('inventory_reserved_at', 'inventory_restored_at')"
        );
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $supported = ((int) ($row['total'] ?? 0)) === 2;
    } catch (Throwable $e) {
        $supported = false;
    }
    return $supported;
}

function mark_order_inventory_reserved(mysqli $conn, int $orderId): void
{
    if ($orderId <= 0 || !orders_supports_inventory_tracking($conn)) {
        return;
    }
    $stmt = $conn->prepare(
        "UPDATE orders
         SET inventory_reserved_at = COALESCE(inventory_reserved_at, NOW()),
             inventory_restored_at = NULL
         WHERE id = ?"
    );
    $stmt->bind_param('i', $orderId);
    $stmt->execute();
}

function reserve_order_inventory(mysqli $conn, int $orderId): void
{
    if ($orderId <= 0) {
        return;
    }

    $supportsTracking = orders_supports_inventory_tracking($conn);
    if ($supportsTracking) {
        $orderStmt = $conn->prepare(
            "SELECT inventory_reserved_at, inventory_restored_at
             FROM orders
             WHERE id = ?
             FOR UPDATE"
        );
        $orderStmt->bind_param('i', $orderId);
        $orderStmt->execute();
        $order = $orderStmt->get_result()->fetch_assoc();
        if (!$order) {
            throw new RuntimeException('Order not found for inventory reservation.');
        }
        if (!empty($order['inventory_reserved_at']) && empty($order['inventory_restored_at'])) {
            return;
        }
    }

    $itemsStmt = $conn->prepare(
        "SELECT id, fabric_id, variant_id, unit_type, quantity_meters
         FROM order_items
         WHERE order_id = ?"
    );
    $itemsStmt->bind_param('i', $orderId);
    $itemsStmt->execute();
    $items = $itemsStmt->get_result()->fetch_all(MYSQLI_ASSOC);
    foreach ($items as $item) {
        $fabricId = (int) ($item['fabric_id'] ?? 0);
        if ($fabricId <= 0) {
            continue;
        }
        $itemUnit = in_array((string) ($item['unit_type'] ?? ''), ['meter', 'piece', 'set'], true)
            ? (string) $item['unit_type']
            : 'meter';
        $qty = normalize_quantity_by_unit($item['quantity_meters'] ?? 1, $itemUnit);
        $variantId = (int) ($item['variant_id'] ?? 0);
        if ($variantId > 0) {
            adjust_variant_stock($conn, $variantId, $itemUnit, (float) $qty, 'decrease');
        } else {
            adjust_fabric_stock($conn, $fabricId, $itemUnit, (float) $qty, 'decrease');
        }
        log_stock_ledger(
            $conn,
            $orderId,
            (int) ($item['id'] ?? 0),
            0,
            0,
            $fabricId,
            $variantId,
            $itemUnit,
            (float) $qty,
            'reserve',
            'out',
            'order_flow',
            'Order inventory reserved'
        );
    }

    mark_order_inventory_reserved($conn, $orderId);
}

function restore_order_inventory(mysqli $conn, int $orderId): void
{
    $supportsTracking = orders_supports_inventory_tracking($conn);
    if ($supportsTracking) {
        $orderStmt = $conn->prepare(
            "SELECT inventory_reserved_at, inventory_restored_at
             FROM orders
             WHERE id = ?
             FOR UPDATE"
        );
        $orderStmt->bind_param('i', $orderId);
        $orderStmt->execute();
        $order = $orderStmt->get_result()->fetch_assoc();
        if (!$order || empty($order['inventory_reserved_at']) || !empty($order['inventory_restored_at'])) {
            return;
        }
    }

    $itemsStmt = $conn->prepare(
        "SELECT id, fabric_id, variant_id, unit_type, quantity_meters
         FROM order_items
         WHERE order_id = ?"
    );
    $itemsStmt->bind_param('i', $orderId);
    $itemsStmt->execute();
    $items = $itemsStmt->get_result()->fetch_all(MYSQLI_ASSOC);
    foreach ($items as $item) {
        $fabricId = (int) ($item['fabric_id'] ?? 0);
        if ($fabricId <= 0) {
            continue;
        }
        $itemUnit = in_array((string) ($item['unit_type'] ?? ''), ['meter', 'piece', 'set'], true)
            ? (string) $item['unit_type']
            : 'meter';
        $qty = normalize_quantity_by_unit($item['quantity_meters'] ?? 1, $itemUnit);
        $variantId = (int) ($item['variant_id'] ?? 0);
        if ($variantId > 0) {
            adjust_variant_stock($conn, $variantId, $itemUnit, (float) $qty, 'increase');
        } else {
            adjust_fabric_stock($conn, $fabricId, $itemUnit, (float) $qty, 'increase');
        }
        log_stock_ledger(
            $conn,
            $orderId,
            (int) ($item['id'] ?? 0),
            0,
            0,
            $fabricId,
            $variantId,
            $itemUnit,
            (float) $qty,
            'release',
            'in',
            'order_flow',
            'Order inventory released'
        );
    }

    if ($supportsTracking) {
        $upd = $conn->prepare("UPDATE orders SET inventory_restored_at = NOW() WHERE id = ?");
        $upd->bind_param('i', $orderId);
        $upd->execute();
    }
}

/**
 * Guard admin order status edits with allowed state transitions.
 */
function can_transition_order_status(string $currentStatus, string $nextStatus): bool
{
    $current = strtolower(trim($currentStatus));
    $next = strtolower(trim($nextStatus));
    $map = [
        'pending' => ['pending', 'confirmed', 'packed', 'cancelled'],
        'confirmed' => ['confirmed', 'packed', 'shipped', 'cancelled'],
        'packed' => ['packed', 'shipped', 'cancelled'],
        'shipped' => ['shipped', 'delivered', 'returned'],
        'delivered' => ['delivered', 'returned'],
        'cancelled' => ['cancelled', 'refunded'],
        'returned' => ['returned', 'refunded'],
        'refunded' => ['refunded'],
    ];
    $allowed = $map[$current] ?? [$current];
    return in_array($next, $allowed, true);
}

/**
 * Shared UI metadata for order status badges.
 */
function order_status_meta(string $status): array
{
    $status = strtolower(trim($status));
    $map = [
        'pending' => ['label' => 'Pending', 'class' => 'warning'],
        'confirmed' => ['label' => 'Confirmed', 'class' => 'info'],
        'processing' => ['label' => 'Processing', 'class' => 'primary'],
        'packed' => ['label' => 'Packed', 'class' => 'primary'],
        'shipped' => ['label' => 'Shipped', 'class' => 'primary'],
        'delivered' => ['label' => 'Delivered', 'class' => 'success'],
        'cancelled' => ['label' => 'Cancelled', 'class' => 'danger'],
        'returned' => ['label' => 'Returned', 'class' => 'secondary'],
        'refunded' => ['label' => 'Refunded', 'class' => 'dark'],
    ];
    return $map[$status] ?? ['label' => ucfirst($status), 'class' => 'secondary'];
}

/**
 * Shared UI metadata for payment status badges.
 */
function payment_status_meta(string $status): array
{
    $status = strtolower(trim($status));
    $map = [
        'pending' => ['label' => 'Pending', 'class' => 'secondary'],
        'paid' => ['label' => 'Paid', 'class' => 'success'],
        'failed' => ['label' => 'Failed', 'class' => 'danger'],
        'refunded' => ['label' => 'Refunded', 'class' => 'dark'],
    ];
    return $map[$status] ?? ['label' => ucfirst($status), 'class' => 'secondary'];
}

/**
 * Allow only supported online payment preference values.
 */
function sanitize_online_payment_method(?string $value): string
{
    $method = strtolower(trim((string) $value));
    return in_array($method, ['upi', 'card', 'emi'], true) ? $method : '';
}

/**
 * Unit suffix for display.
 */
function quantity_unit_suffix(string $unitType): string
{
    if ($unitType === 'piece') return ' pc';
    if ($unitType === 'set') return ' set';
    return 'm';
}

/**
 * Allow only absolute http/https URLs for outbound links.
 */
function safe_external_url(?string $value): string
{
    $url = trim((string) $value);
    if ($url === '' || !filter_var($url, FILTER_VALIDATE_URL)) {
        return '';
    }
    $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
    if (!in_array($scheme, ['http', 'https'], true)) {
        return '';
    }
    return $url;
}

/**
 * Generate a safe filename for uploads.
 */
function random_filename(string $originalName): string
{
    $ext = pathinfo($originalName, PATHINFO_EXTENSION);
    return uniqid('fabric_', true) . ($ext ? ".{$ext}" : '');
}

function image_upload_max_mb(): int
{
    $mb = (int) _cfg('IMAGE_UPLOAD_MAX_MB', '5');
    return $mb > 0 ? $mb : 5;
}

function image_upload_max_bytes(): int
{
    return image_upload_max_mb() * 1024 * 1024;
}

function image_pipeline_webp_quality(): int
{
    $quality = (int) _cfg('IMAGE_WEBP_QUALITY', '82');
    if ($quality < 40) {
        return 40;
    }
    if ($quality > 95) {
        return 95;
    }
    return $quality;
}

function image_pipeline_max_width(): int
{
    $maxWidth = (int) _cfg('IMAGE_MAX_WIDTH', '1920');
    return $maxWidth > 0 ? $maxWidth : 1920;
}

function image_pipeline_webp_widths(): array
{
    $raw = trim(_cfg('IMAGE_RESPONSIVE_WIDTHS', '360,720,1200'));
    $parts = array_filter(array_map('trim', explode(',', $raw)), static fn($v) => $v !== '');
    $widths = [];
    foreach ($parts as $part) {
        if (!is_numeric($part)) {
            continue;
        }
        $w = (int) $part;
        if ($w > 0) {
            $widths[] = $w;
        }
    }
    if (empty($widths)) {
        $widths = [360, 720, 1200];
    }
    $widths = array_values(array_unique($widths));
    sort($widths);
    return $widths;
}

function image_pipeline_thumb_dimensions(): array
{
    $w = (int) _cfg('IMAGE_THUMB_WIDTH', '360');
    $h = (int) _cfg('IMAGE_THUMB_HEIGHT', '360');
    return [max(64, $w), max(64, $h)];
}

function image_pipeline_create_resource(string $path, string $mime)
{
    if ($mime === 'image/jpeg') {
        return @imagecreatefromjpeg($path);
    }
    if ($mime === 'image/png') {
        return @imagecreatefrompng($path);
    }
    if ($mime === 'image/webp' && function_exists('imagecreatefromwebp')) {
        return @imagecreatefromwebp($path);
    }
    return false;
}

function image_pipeline_create_canvas(int $width, int $height)
{
    $canvas = imagecreatetruecolor($width, $height);
    if ($canvas === false) {
        return false;
    }
    imagealphablending($canvas, false);
    imagesavealpha($canvas, true);
    $transparent = imagecolorallocatealpha($canvas, 0, 0, 0, 127);
    imagefilledrectangle($canvas, 0, 0, $width, $height, $transparent);
    return $canvas;
}

function image_pipeline_save_resource($resource, string $path, string $mime, int $quality): bool
{
    if ($mime === 'image/jpeg') {
        if (function_exists('imageinterlace')) {
            imageinterlace($resource, true);
        }
        return (bool) @imagejpeg($resource, $path, $quality);
    }
    if ($mime === 'image/png') {
        $compression = (int) round((100 - $quality) / 10);
        $compression = max(0, min(9, $compression));
        return (bool) @imagepng($resource, $path, $compression);
    }
    if ($mime === 'image/webp' && function_exists('imagewebp')) {
        return (bool) @imagewebp($resource, $path, $quality);
    }
    return false;
}

function image_pipeline_resize_to_width($source, int $srcWidth, int $srcHeight, int $targetWidth)
{
    if ($targetWidth <= 0 || $srcWidth <= 0 || $srcHeight <= 0) {
        return false;
    }
    $targetHeight = (int) round(($srcHeight * $targetWidth) / $srcWidth);
    $target = image_pipeline_create_canvas($targetWidth, max(1, $targetHeight));
    if ($target === false) {
        return false;
    }
    imagecopyresampled($target, $source, 0, 0, 0, 0, $targetWidth, max(1, $targetHeight), $srcWidth, $srcHeight);
    return $target;
}

function image_pipeline_resize_cover($source, int $srcWidth, int $srcHeight, int $targetWidth, int $targetHeight)
{
    if ($srcWidth <= 0 || $srcHeight <= 0 || $targetWidth <= 0 || $targetHeight <= 0) {
        return false;
    }
    $srcRatio = $srcWidth / $srcHeight;
    $targetRatio = $targetWidth / $targetHeight;

    if ($srcRatio > $targetRatio) {
        $cropHeight = $srcHeight;
        $cropWidth = (int) round($srcHeight * $targetRatio);
        $srcX = (int) floor(($srcWidth - $cropWidth) / 2);
        $srcY = 0;
    } else {
        $cropWidth = $srcWidth;
        $cropHeight = (int) round($srcWidth / $targetRatio);
        $srcX = 0;
        $srcY = (int) floor(($srcHeight - $cropHeight) / 2);
    }

    $target = image_pipeline_create_canvas($targetWidth, $targetHeight);
    if ($target === false) {
        return false;
    }
    imagecopyresampled($target, $source, 0, 0, $srcX, $srcY, $targetWidth, $targetHeight, $cropWidth, $cropHeight);
    return $target;
}

function image_pipeline_generate_derivatives(string $absoluteImagePath): void
{
    if (!is_file($absoluteImagePath) || !extension_loaded('gd') || !function_exists('imagecreatetruecolor')) {
        return;
    }

    $info = @getimagesize($absoluteImagePath);
    if (!is_array($info) || !isset($info[0], $info[1], $info['mime'])) {
        return;
    }

    $mime = strtolower((string) $info['mime']);
    if (!in_array($mime, ['image/jpeg', 'image/png', 'image/webp'], true)) {
        return;
    }

    $source = image_pipeline_create_resource($absoluteImagePath, $mime);
    if ($source === false) {
        return;
    }

    $quality = image_pipeline_webp_quality();
    $srcWidth = (int) $info[0];
    $srcHeight = (int) $info[1];
    $maxWidth = image_pipeline_max_width();

    if ($maxWidth > 0 && $srcWidth > $maxWidth) {
        $resizedOriginal = image_pipeline_resize_to_width($source, $srcWidth, $srcHeight, $maxWidth);
        if ($resizedOriginal !== false) {
            if (image_pipeline_save_resource($resizedOriginal, $absoluteImagePath, $mime, $quality)) {
                imagedestroy($source);
                $source = $resizedOriginal;
                $srcWidth = imagesx($source);
                $srcHeight = imagesy($source);
            } else {
                imagedestroy($resizedOriginal);
            }
        }
    }

    $dir = dirname($absoluteImagePath);
    $base = pathinfo($absoluteImagePath, PATHINFO_FILENAME);
    $ext = strtolower(pathinfo($absoluteImagePath, PATHINFO_EXTENSION));

    if (function_exists('imagewebp')) {
        $widths = image_pipeline_webp_widths();
        foreach ($widths as $width) {
            if ($width <= 0 || $width > $srcWidth) {
                continue;
            }
            $resized = image_pipeline_resize_to_width($source, $srcWidth, $srcHeight, $width);
            if ($resized === false) {
                continue;
            }
            $targetPath = $dir . DIRECTORY_SEPARATOR . $base . '-' . $width . 'w.webp';
            image_pipeline_save_resource($resized, $targetPath, 'image/webp', $quality);
            imagedestroy($resized);
        }

        [$thumbW, $thumbH] = image_pipeline_thumb_dimensions();
        $thumbWebp = image_pipeline_resize_cover($source, $srcWidth, $srcHeight, $thumbW, $thumbH);
        if ($thumbWebp !== false) {
            $thumbWebpPath = $dir . DIRECTORY_SEPARATOR . $base . '-thumb.webp';
            image_pipeline_save_resource($thumbWebp, $thumbWebpPath, 'image/webp', $quality);
            imagedestroy($thumbWebp);
        }
    }

    [$thumbW, $thumbH] = image_pipeline_thumb_dimensions();
    $thumbFallback = image_pipeline_resize_cover($source, $srcWidth, $srcHeight, $thumbW, $thumbH);
    if ($thumbFallback !== false) {
        $thumbExt = $ext !== '' ? $ext : ($mime === 'image/png' ? 'png' : 'jpg');
        $thumbMime = $mime;
        if ($thumbMime === 'image/webp' && !function_exists('imagewebp')) {
            $thumbMime = 'image/jpeg';
            $thumbExt = 'jpg';
        }
        $thumbPath = $dir . DIRECTORY_SEPARATOR . $base . '-thumb.' . $thumbExt;
        image_pipeline_save_resource($thumbFallback, $thumbPath, $thumbMime, $quality);
        imagedestroy($thumbFallback);
    }

    imagedestroy($source);
}

function image_pipeline_delete_files(string $directory, string $filename): void
{
    $filename = trim($filename);
    if ($filename === '') {
        return;
    }

    $directory = rtrim($directory, '/\\');
    $filename = basename($filename);
    $originalPath = $directory . DIRECTORY_SEPARATOR . $filename;
    if (is_file($originalPath)) {
        @unlink($originalPath);
    }

    $base = pathinfo($filename, PATHINFO_FILENAME);
    if ($base === '') {
        return;
    }
    $matches = glob($directory . DIRECTORY_SEPARATOR . $base . '-*');
    if (is_array($matches)) {
        foreach ($matches as $path) {
            if (is_file($path)) {
                @unlink($path);
            }
        }
    }
}

function save_fabric_image_upload(array $file, string $label = 'Image'): string
{
    $allowedImageExt = ['jpg', 'jpeg', 'png', 'webp'];
    $allowedImageMime = ['image/jpeg', 'image/png', 'image/webp'];
    $maxImageSize = image_upload_max_bytes();
    $minImageWidth = max(1, (int) _cfg('IMAGE_MIN_WIDTH', '600'));
    $minImageHeight = max(1, (int) _cfg('IMAGE_MIN_HEIGHT', '800'));

    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        throw new RuntimeException($label . ' upload failed. Please try again.');
    }

    $tmpName = (string) ($file['tmp_name'] ?? '');
    $ext = strtolower(pathinfo((string) ($file['name'] ?? ''), PATHINFO_EXTENSION));
    $mime = function_exists('mime_content_type') ? (mime_content_type($tmpName) ?: '') : '';
    $size = (int) ($file['size'] ?? 0);
    $imageInfo = @getimagesize($tmpName);

    if ($size > $maxImageSize) {
        throw new RuntimeException($label . ' must be under ' . image_upload_max_mb() . 'MB.');
    }

    if (!in_array($ext, $allowedImageExt, true) || !in_array($mime, $allowedImageMime, true) || !is_array($imageInfo)) {
        throw new RuntimeException($label . ' must be JPG, PNG or WEBP.');
    }

    $imgWidth = (int) ($imageInfo[0] ?? 0);
    $imgHeight = (int) ($imageInfo[1] ?? 0);
    if ($imgWidth < $minImageWidth || $imgHeight < $minImageHeight) {
        throw new RuntimeException($label . ' must be at least ' . $minImageWidth . 'x' . $minImageHeight . ' px for clear website display.');
    }

    $saved = random_filename((string) ($file['name'] ?? 'image.jpg'));
    $target = __DIR__ . '/../images/fabrics/' . $saved;
    if (!move_uploaded_file($tmpName, $target)) {
        throw new RuntimeException($label . ' upload failed.');
    }

    image_pipeline_generate_derivatives($target);
    return $saved;
}

function image_pipeline_asset_data(string $relativeDir, string $filename): array
{
    $filename = trim($filename);
    if ($filename === '') {
        return [
            'src' => '',
            'thumb_src' => '',
            'webp_srcset' => '',
        ];
    }

    $relativeDir = trim(str_replace('\\', '/', $relativeDir), '/');
    $filename = basename(str_replace('\\', '/', $filename));
    $base = pathinfo($filename, PATHINFO_FILENAME);
    $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
    $absDir = __DIR__ . '/../' . $relativeDir;

    $baseUrl = '/' . $relativeDir;
    $originalUrl = $baseUrl . '/' . $filename;

    $thumbWebp = $base . '-thumb.webp';
    $thumbWebpAbs = $absDir . DIRECTORY_SEPARATOR . $thumbWebp;
    $thumbFallback = ($ext !== '') ? ($base . '-thumb.' . $ext) : '';
    $thumbFallbackAbs = $thumbFallback !== '' ? ($absDir . DIRECTORY_SEPARATOR . $thumbFallback) : '';

    if (is_file($thumbWebpAbs)) {
        $thumbUrl = $baseUrl . '/' . $thumbWebp;
    } elseif ($thumbFallbackAbs !== '' && is_file($thumbFallbackAbs)) {
        $thumbUrl = $baseUrl . '/' . $thumbFallback;
    } else {
        $thumbUrl = $originalUrl;
    }

    $srcsetParts = [];
    foreach (image_pipeline_webp_widths() as $w) {
        $variant = $base . '-' . $w . 'w.webp';
        $variantAbs = $absDir . DIRECTORY_SEPARATOR . $variant;
        if (is_file($variantAbs)) {
            $srcsetParts[] = $baseUrl . '/' . $variant . ' ' . $w . 'w';
        }
    }

    return [
        'src' => $originalUrl,
        'thumb_src' => $thumbUrl,
        'webp_srcset' => implode(', ', $srcsetParts),
    ];
}

function fabric_image_asset_data(string $filename): array
{
    return image_pipeline_asset_data('images/fabrics', $filename);
}

/**
 * Scan original fabric images and list files below configured minimum dimensions.
 */
function image_pipeline_low_resolution_fabric_images(int $limit = 20): array
{
    static $cache = [];

    $minWidth = max(1, (int) _cfg('IMAGE_MIN_WIDTH', '600'));
    $minHeight = max(1, (int) _cfg('IMAGE_MIN_HEIGHT', '800'));
    $limit = max(1, min(5000, $limit));
    $cacheKey = $minWidth . 'x' . $minHeight . ':' . $limit;

    if (isset($cache[$cacheKey])) {
        return $cache[$cacheKey];
    }

    $directory = __DIR__ . '/../images/fabrics';
    $rows = [];

    if (is_dir($directory)) {
        try {
            $iterator = new DirectoryIterator($directory);
            foreach ($iterator as $fileInfo) {
                if ($fileInfo->isDot() || !$fileInfo->isFile()) {
                    continue;
                }

                $filename = $fileInfo->getFilename();
                if (!preg_match('/\.(jpe?g|png|webp)$/i', $filename)) {
                    continue;
                }
                if (preg_match('/-(thumb|\d+w)\.(webp|jpe?g|png)$/i', $filename)) {
                    continue;
                }

                $info = @getimagesize($fileInfo->getPathname());
                if (!is_array($info)) {
                    continue;
                }

                $width = (int) ($info[0] ?? 0);
                $height = (int) ($info[1] ?? 0);
                if ($width < $minWidth || $height < $minHeight) {
                    $rows[] = [
                        'filename' => $filename,
                        'width' => $width,
                        'height' => $height,
                        'area' => $width * $height,
                    ];
                }
            }
        } catch (Throwable $e) {
            $rows = [];
        }
    }

    usort($rows, static function (array $a, array $b): int {
        $cmp = ((int) ($a['area'] ?? 0)) <=> ((int) ($b['area'] ?? 0));
        if ($cmp !== 0) {
            return $cmp;
        }
        return strcmp((string) ($a['filename'] ?? ''), (string) ($b['filename'] ?? ''));
    });

    $result = [
        'min_width' => $minWidth,
        'min_height' => $minHeight,
        'total' => count($rows),
        'items' => array_slice($rows, 0, $limit),
    ];

    $cache[$cacheKey] = $result;
    return $result;
}

/**
 * Require admin session.
 */
function require_admin(): void
{
    if (empty($_SESSION['admin_id'])) {
        flash('error', 'Please log in to continue.');
        redirect('login.php');
    }
    if (!empty($_SESSION['must_reset_password']) && basename($_SERVER['PHP_SELF']) !== 'password-reset.php') {
        flash('error', 'Please set a new password before continuing.');
        redirect('password-reset.php');
    }
}

/**
 * Convert mysqli result row to array safely.
 */
function fetch_all_assoc(mysqli_result $result): array
{
    return $result->fetch_all(MYSQLI_ASSOC);
}

/**
 * Shared list/filter helpers.
 */
function list_sanitize_sort(string $sort, array $sortMap, string $default = 'newest'): string
{
    return isset($sortMap[$sort]) ? $sort : $default;
}

function list_sanitize_per_page(int $perPage, array $options): int
{
    return in_array($perPage, $options, true) ? $perPage : (int) $options[0];
}

function list_sanitize_page(int $page): int
{
    return max(1, $page);
}

function list_clamp_page(int $page, int $pages): int
{
    return min(max(1, $page), max(1, $pages));
}

function list_build_query(array $params, bool $dropEmpty = true): string
{
    if ($dropEmpty) {
        $params = array_filter($params, static fn($v) => $v !== '' && $v !== null);
    }
    return http_build_query($params);
}

/**
 * Public form rate-limit backed by DB when available (session fallback).
 */
function public_form_rate_limit_allow(string $scope, int $maxAttempts = 5, int $windowSeconds = 600): bool
{
    $scope = trim($scope);
    if ($scope === '') {
        $scope = 'public_form';
    }
    $maxAttempts = max(1, (int) $maxAttempts);
    $windowSeconds = max(60, (int) $windowSeconds);

    $ip = trim((string) ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0'));
    $ua = trim((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''));
    $uaKey = substr(hash('sha256', $ua), 0, 16);
    $key = hash('sha256', strtolower($scope) . '|' . $ip . '|' . $uaKey);

    $conn = (isset($GLOBALS['conn']) && $GLOBALS['conn'] instanceof mysqli) ? $GLOBALS['conn'] : null;
    if ($conn instanceof mysqli) {
        try {
            $conn->query(
                "CREATE TABLE IF NOT EXISTS public_form_attempts (
                    attempt_key CHAR(64) PRIMARY KEY,
                    scope VARCHAR(80) NOT NULL,
                    ip_address VARCHAR(45) NOT NULL,
                    user_agent_hash CHAR(16) NOT NULL,
                    attempts INT NOT NULL DEFAULT 0,
                    window_started_at DATETIME NOT NULL,
                    blocked_until DATETIME DEFAULT NULL,
                    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    INDEX idx_public_form_attempts_scope_updated (scope, updated_at)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
            );

            $stmt = $conn->prepare(
                "SELECT attempts, UNIX_TIMESTAMP(window_started_at) AS window_ts, UNIX_TIMESTAMP(blocked_until) AS blocked_ts
                 FROM public_form_attempts
                 WHERE attempt_key = ?
                 LIMIT 1"
            );
            $stmt->bind_param('s', $key);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc() ?: null;
            $stmt->close();

            $now = time();
            $windowStart = $now - $windowSeconds;
            $attempts = (int) ($row['attempts'] ?? 0);
            $windowTs = (int) ($row['window_ts'] ?? 0);
            $blockedTs = (int) ($row['blocked_ts'] ?? 0);

            if ($blockedTs > $now) {
                return false;
            }

            if ($windowTs < $windowStart) {
                $attempts = 0;
                $windowTs = $now;
            }

            if ($attempts >= $maxAttempts) {
                $blockedUntil = date('Y-m-d H:i:s', $now + $windowSeconds);
                $upd = $conn->prepare(
                    "INSERT INTO public_form_attempts (attempt_key, scope, ip_address, user_agent_hash, attempts, window_started_at, blocked_until)
                     VALUES (?, ?, ?, ?, ?, FROM_UNIXTIME(?), ?)
                     ON DUPLICATE KEY UPDATE
                        attempts = VALUES(attempts),
                        window_started_at = VALUES(window_started_at),
                        blocked_until = VALUES(blocked_until),
                        updated_at = CURRENT_TIMESTAMP"
                );
                $upd->bind_param('ssssiis', $key, $scope, $ip, $uaKey, $attempts, $windowTs, $blockedUntil);
                $upd->execute();
                $upd->close();
                return false;
            }

            $attempts++;
            $ins = $conn->prepare(
                "INSERT INTO public_form_attempts (attempt_key, scope, ip_address, user_agent_hash, attempts, window_started_at, blocked_until)
                 VALUES (?, ?, ?, ?, ?, FROM_UNIXTIME(?), NULL)
                 ON DUPLICATE KEY UPDATE
                    attempts = VALUES(attempts),
                    window_started_at = VALUES(window_started_at),
                    blocked_until = NULL,
                    updated_at = CURRENT_TIMESTAMP"
            );
            $ins->bind_param('ssssii', $key, $scope, $ip, $uaKey, $attempts, $windowTs);
            $ins->execute();
            $ins->close();

            // Lightweight cleanup of stale rows.
            $conn->query("DELETE FROM public_form_attempts WHERE updated_at < (NOW() - INTERVAL 7 DAY)");
            return true;
        } catch (Throwable $e) {
            error_log('[amberfabrics] public form rate-limit fallback to session: ' . $e->getMessage());
        }
    }

    if (!isset($_SESSION['form_rate_limit']) || !is_array($_SESSION['form_rate_limit'])) {
        $_SESSION['form_rate_limit'] = [];
    }
    $now = time();
    $windowStart = $now - $windowSeconds;
    $hits = $_SESSION['form_rate_limit'][$key] ?? [];
    if (!is_array($hits)) {
        $hits = [];
    }
    $hits = array_values(array_filter($hits, static fn($ts) => is_int($ts) && $ts >= $windowStart));
    if (count($hits) >= $maxAttempts) {
        $_SESSION['form_rate_limit'][$key] = $hits;
        return false;
    }
    $hits[] = $now;
    $_SESSION['form_rate_limit'][$key] = $hits;
    return true;
}

/**
 * Get recipient for operational notifications.
 */
function admin_notification_email(): string
{
    $email = _cfg('ADMIN_NOTIFICATION_EMAIL', _cfg('ADMIN_EMAIL', ''));
    if ($email === '') {
        error_log('[amberfabrics] WARNING: ADMIN_NOTIFICATION_EMAIL is not set. Admin notifications will not be sent.');
        return '';
    }
    return $email;
}

/**
 * Build normalized SKU from category, material, color, GSM.
 */
function build_fabric_sku_base(string $category, string $material, string $color, string $gsm): string
{
    $parts = [$category, $material, $color, $gsm];
    $clean = [];
    foreach ($parts as $part) {
        $p = strtoupper(trim($part));
        $p = preg_replace('/[^A-Z0-9]+/', '-', $p ?? '');
        $p = trim((string) $p, '-');
        if ($p !== '') {
            $clean[] = $p;
        }
    }
    if (empty($clean)) {
        return 'SKU';
    }
    return implode('-', $clean);
}

/**
 * Generate a unique fabrics.sku value by appending -2, -3... when needed.
 */
function generate_unique_fabric_sku(mysqli $conn, string $category, string $material, string $color, string $gsm, int $excludeId = 0): string
{
    $base = build_fabric_sku_base($category, $material, $color, $gsm);
    $candidate = $base;
    $n = 1;

    while (true) {
        if ($excludeId > 0) {
            $stmt = $conn->prepare("SELECT id FROM fabrics WHERE sku = ? AND id <> ? LIMIT 1");
            $stmt->bind_param('si', $candidate, $excludeId);
        } else {
            $stmt = $conn->prepare("SELECT id FROM fabrics WHERE sku = ? LIMIT 1");
            $stmt->bind_param('s', $candidate);
        }
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        if (!$row) {
            return $candidate;
        }
        $n++;
        $candidate = $base . '-' . $n;
    }
}

/**
 * Send a basic email notification for new inquiry submissions.
 */
function send_inquiry_notification(array $inquiry): bool
{
    $to = admin_notification_email();
    if ($to === '') {
        return false;
    }

    $template = email_template_build('inquiry_notification', $inquiry);

    $replyTo = filter_var($inquiry['email'] ?? '', FILTER_VALIDATE_EMAIL)
        ? (string) $inquiry['email']
        : '';

    try {
        $mail = _mailer_base();
        $mail->addAddress($to);
        if ($replyTo !== '') {
            $mail->addReplyTo($replyTo);
        }
        $mail->Subject = $template['subject'];
        $mail->Body    = $template['body'];
        $mail->send();
        return true;
    } catch (Throwable $e) {
        error_log('[amberfabrics] inquiry notification email failed: ' . $e->getMessage());
        return false;
    }
}

/**
 * Persist inquiry activity entries for audit and follow-up context.
 */
function log_inquiry_activity(
    mysqli $conn,
    int $inquiryId,
    string $action,
    ?int $adminId = null,
    string $actorName = 'system',
    string $details = ''
): void {
    try {
        $stmt = $conn->prepare(
            "INSERT INTO inquiry_activity_logs (inquiry_id, admin_id, actor_name, action, details)
             VALUES (?, ?, ?, ?, ?)"
        );
        $stmt->bind_param('iisss', $inquiryId, $adminId, $actorName, $action, $details);
        $stmt->execute();
    } catch (Throwable $e) {
        error_log('[fabric-export] inquiry activity log failed: ' . $e->getMessage());
    }
}

/**
 * Persist order lifecycle events for auditability.
 */
function log_order_activity(
    mysqli $conn,
    int $orderId,
    string $action,
    string $actorType = 'system',
    int $actorId = 0,
    string $actorName = '',
    string $details = ''
): void {
    try {
        $stmt = $conn->prepare(
            "INSERT INTO order_activity_logs (order_id, action, actor_type, actor_id, actor_name, details)
             VALUES (?, ?, ?, ?, ?, ?)"
        );
        $stmt->bind_param('ississ', $orderId, $action, $actorType, $actorId, $actorName, $details);
        $stmt->execute();
    } catch (Throwable $e) {
        error_log('[amberfabrics] order activity log failed: ' . $e->getMessage());
    }
}

/**
 * Persist refund transactions to keep a real ledger.
 */
function log_refund_ledger(
    mysqli $conn,
    int $orderId,
    int $paymentId,
    float $amount,
    string $currency = 'INR',
    string $status = 'initiated',
    string $gateway = '',
    string $gatewayRefundId = '',
    string $notes = ''
): void {
    try {
        $stmt = $conn->prepare(
            "INSERT INTO refund_ledger (order_id, payment_id, amount, currency, status, gateway, gateway_refund_id, notes)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)"
        );
        $stmt->bind_param('iidsssss', $orderId, $paymentId, $amount, $currency, $status, $gateway, $gatewayRefundId, $notes);
        $stmt->execute();
    } catch (Throwable $e) {
        error_log('[amberfabrics] refund ledger log failed: ' . $e->getMessage());
    }
}

function order_coupon_code_from_activity(mysqli $conn, int $orderId): string
{
    if ($orderId <= 0) {
        return '';
    }
    try {
        $stmt = $conn->prepare(
            "SELECT details
             FROM order_activity_logs
             WHERE order_id = ? AND action = 'coupon_applied'
             ORDER BY id DESC
             LIMIT 1"
        );
        $stmt->bind_param('i', $orderId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $details = (string) ($row['details'] ?? '');
        if ($details !== '' && preg_match('/Coupon code:\s*([A-Z0-9_-]+)/i', $details, $m)) {
            return strtoupper(trim((string) ($m[1] ?? '')));
        }
    } catch (Throwable $e) {
        error_log('[amberfabrics] order coupon activity read failed: ' . $e->getMessage());
    }
    return '';
}

function orders_structured_financial_columns_ready(mysqli $conn): bool
{
    static $ready = null;
    if ($ready !== null) {
        return $ready;
    }
    try {
        $stmt = $conn->prepare(
            "SELECT COUNT(*) AS total
             FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = 'orders'
               AND COLUMN_NAME IN (
                 'coupon_id','coupon_code','coupon_discount',
                 'shipping_quote_token','shipping_source','courier_id','courier_name',
                 'cod_fee','base_shipping'
               )"
        );
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc() ?: [];
        $ready = ((int) ($row['total'] ?? 0)) === 9;
    } catch (Throwable $e) {
        $ready = false;
    }
    return $ready;
}

function resolve_coupon_id_for_order(mysqli $conn, int $orderId, string $orderNotes = ''): int
{
    if (orders_structured_financial_columns_ready($conn) && $orderId > 0) {
        try {
            $stmt = $conn->prepare("SELECT coupon_id, coupon_code FROM orders WHERE id = ? LIMIT 1");
            $stmt->bind_param('i', $orderId);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc() ?: [];
            $couponId = (int) ($row['coupon_id'] ?? 0);
            if ($couponId > 0) {
                return $couponId;
            }
            $couponCode = strtoupper(trim((string) ($row['coupon_code'] ?? '')));
            if ($couponCode !== '') {
                $idStmt = $conn->prepare("SELECT id FROM coupons WHERE code = ? LIMIT 1");
                $idStmt->bind_param('s', $couponCode);
                $idStmt->execute();
                $couponRow = $idStmt->get_result()->fetch_assoc() ?: [];
                $resolved = (int) ($couponRow['id'] ?? 0);
                if ($resolved > 0) {
                    return $resolved;
                }
            }
        } catch (Throwable $e) {
            error_log('[amberfabrics] structured coupon resolve failed: ' . $e->getMessage());
        }
    }

    $code = order_coupon_code_from_activity($conn, $orderId);
    if ($code === '' && $orderNotes !== '' && preg_match('/Coupon Applied:\s*([A-Z0-9_-]+)/i', $orderNotes, $m)) {
        $code = strtoupper(trim((string) ($m[1] ?? '')));
    }
    if ($code === '') {
        return 0;
    }
    try {
        $stmt = $conn->prepare("SELECT id FROM coupons WHERE code = ? LIMIT 1");
        $stmt->bind_param('s', $code);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        return (int) ($row['id'] ?? 0);
    } catch (Throwable $e) {
        error_log('[amberfabrics] coupon id resolve failed: ' . $e->getMessage());
        return 0;
    }
}

function razorpay_mark_order_paid(
    mysqli $conn,
    int $orderId,
    string $previousPaymentStatus,
    string $paymentId = '',
    string $rzpOrderId = '',
    string $signature = ''
): void {
    $updateOrder = $conn->prepare(
        "UPDATE orders
         SET payment_id = ?, payment_status = 'paid', order_status = 'confirmed', status = 'confirmed'
         WHERE id = ? AND payment_status IN ('pending', 'failed')"
    );
    $updateOrder->bind_param('si', $paymentId, $orderId);
    $updateOrder->execute();
    if ($conn->affected_rows === 0 && strtolower($previousPaymentStatus) !== 'paid') {
        throw new RuntimeException('Order payment state changed unexpectedly during Razorpay capture.');
    }

    $updatePayment = $conn->prepare(
        "UPDATE payments
         SET payment_status = 'paid',
             transaction_id = CASE WHEN ? <> '' THEN ? ELSE transaction_id END,
             razorpay_order_id = CASE WHEN ? <> '' THEN ? ELSE razorpay_order_id END,
             razorpay_payment_id = CASE WHEN ? <> '' THEN ? ELSE razorpay_payment_id END,
             razorpay_signature = CASE WHEN ? <> '' THEN ? ELSE razorpay_signature END
         WHERE order_id = ? AND payment_method = 'razorpay' AND payment_status IN ('pending', 'failed')"
    );
    $updatePayment->bind_param(
        'ssssssssi',
        $paymentId,
        $paymentId,
        $rzpOrderId,
        $rzpOrderId,
        $paymentId,
        $paymentId,
        $signature,
        $signature,
        $orderId
    );
    $updatePayment->execute();
}

function razorpay_mark_order_failed(
    mysqli $conn,
    int $orderId,
    string $previousPaymentStatus,
    string $note,
    string $paymentId = '',
    string $rzpOrderId = ''
): bool {
    if (strtolower($previousPaymentStatus) === 'paid') {
        return false;
    }

    $updatePayment = $conn->prepare(
        "UPDATE payments
         SET payment_status = 'failed',
             transaction_id = CASE WHEN ? <> '' THEN ? ELSE transaction_id END,
             razorpay_payment_id = CASE WHEN ? <> '' THEN ? ELSE razorpay_payment_id END,
             razorpay_order_id = CASE WHEN ? <> '' THEN ? ELSE razorpay_order_id END
         WHERE order_id = ? AND payment_method = 'razorpay'"
    );
    $updatePayment->bind_param('ssssssi', $paymentId, $paymentId, $paymentId, $paymentId, $rzpOrderId, $rzpOrderId, $orderId);
    $updatePayment->execute();

    $updateOrder = $conn->prepare(
        "UPDATE orders
         SET payment_status = 'failed',
             notes = CASE WHEN notes IS NULL OR notes = '' THEN ? ELSE CONCAT(notes, '\n', ?) END,
             updated_at = NOW()
         WHERE id = ?"
    );
    $updateOrder->bind_param('ssi', $note, $note, $orderId);
    $updateOrder->execute();

    return true;
}

function consume_coupon_after_razorpay_capture(
    mysqli $conn,
    int $orderId,
    int $customerId,
    int $preferredCouponId = 0,
    string $orderNotes = ''
): bool {
    $resolvedCouponId = $preferredCouponId > 0 ? $preferredCouponId : resolve_coupon_id_for_order($conn, $orderId, $orderNotes);
    if ($resolvedCouponId <= 0) {
        return false;
    }
    if (has_customer_used_coupon($conn, $resolvedCouponId, $customerId)) {
        throw new RuntimeException('Coupon already used by this customer.');
    }

    $couponStmt = $conn->prepare(
        "UPDATE coupons SET used_count = used_count + 1
         WHERE id = ? AND (usage_limit = 0 OR used_count < usage_limit)"
    );
    $couponStmt->bind_param('i', $resolvedCouponId);
    $couponStmt->execute();
    if ($conn->affected_rows <= 0) {
        throw new RuntimeException('Coupon usage limit reached.');
    }

    if (!mark_coupon_used_once($conn, $resolvedCouponId, $customerId, $orderId)) {
        throw new RuntimeException('Unable to mark coupon usage for this order.');
    }
    log_order_activity($conn, $orderId, 'coupon_consumed', 'system', 0, 'system', 'Coupon usage count incremented after payment.');
    return true;
}

function razorpay_validate_remote_capture(string $paymentId, string $rzpOrderId, float $expectedAmountInr): array
{
    $paymentId = trim($paymentId);
    $rzpOrderId = trim($rzpOrderId);
    $expectedPaise = (int) round(max(0.0, $expectedAmountInr) * 100);
    if ($paymentId === '' || $rzpOrderId === '' || $expectedPaise <= 0) {
        return ['ok' => false, 'error' => 'invalid_validation_inputs'];
    }

    $keyId = _cfg('RAZORPAY_KEY_ID', '');
    $keySecret = _cfg('RAZORPAY_KEY_SECRET', '');
    if ($keyId === '' || $keySecret === '') {
        return ['ok' => false, 'error' => 'razorpay_credentials_missing'];
    }
    if (!function_exists('curl_init')) {
        return ['ok' => false, 'error' => 'curl_missing'];
    }

    $url = 'https://api.razorpay.com/v1/payments/' . rawurlencode($paymentId);
    $ch = curl_init($url);
    if ($ch === false) {
        return ['ok' => false, 'error' => 'curl_init_failed'];
    }

    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 20,
        CURLOPT_HTTPAUTH => CURLAUTH_BASIC,
        CURLOPT_USERPWD => $keyId . ':' . $keySecret,
        CURLOPT_HTTPHEADER => ['Accept: application/json'],
    ]);
    $raw = curl_exec($ch);
    $errno = curl_errno($ch);
    $err = curl_error($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);

    if ($errno !== 0) {
        return ['ok' => false, 'error' => 'curl_error:' . ($err !== '' ? $err : (string) $errno)];
    }
    if ($status < 200 || $status >= 300) {
        return ['ok' => false, 'error' => 'gateway_http_' . $status];
    }

    $payload = json_decode((string) $raw, true);
    if (!is_array($payload)) {
        return ['ok' => false, 'error' => 'invalid_gateway_json'];
    }

    $remoteOrderId = trim((string) ($payload['order_id'] ?? ''));
    $remoteCurrency = strtoupper(trim((string) ($payload['currency'] ?? '')));
    $remoteAmount = (int) ($payload['amount'] ?? 0);
    $remoteStatus = strtolower(trim((string) ($payload['status'] ?? '')));
    $remoteCaptured = (int) ($payload['captured'] ?? 0);

    if ($remoteOrderId !== $rzpOrderId) {
        return ['ok' => false, 'error' => 'gateway_order_mismatch'];
    }
    if ($remoteCurrency !== 'INR') {
        return ['ok' => false, 'error' => 'gateway_currency_mismatch'];
    }
    if ($remoteAmount !== $expectedPaise) {
        return ['ok' => false, 'error' => 'gateway_amount_mismatch'];
    }
    if (!in_array($remoteStatus, ['captured', 'authorized'], true) || $remoteCaptured !== 1) {
        return ['ok' => false, 'error' => 'gateway_not_captured'];
    }

    return ['ok' => true];
}

function extract_razorpay_refund_id_from_notes(string $notes): string
{
    if (preg_match('/refund_id:\s*(rfnd_[A-Za-z0-9]+)/i', $notes, $m)) {
        return trim((string) ($m[1] ?? ''));
    }
    return '';
}

function latest_refund_ledger_gateway_refund_id(mysqli $conn, int $orderId, string $gateway = 'razorpay'): string
{
    if ($orderId <= 0) {
        return '';
    }
    $gateway = trim(strtolower($gateway));
    if ($gateway === '') {
        return '';
    }
    try {
        $stmt = $conn->prepare(
            "SELECT gateway_refund_id
             FROM refund_ledger
             WHERE order_id = ?
               AND gateway = ?
               AND gateway_refund_id IS NOT NULL
               AND gateway_refund_id <> ''
             ORDER BY id DESC
             LIMIT 1"
        );
        $stmt->bind_param('is', $orderId, $gateway);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        return trim((string) ($row['gateway_refund_id'] ?? ''));
    } catch (Throwable $e) {
        return '';
    }
}

function refund_ledger_event_exists(
    mysqli $conn,
    int $orderId,
    int $paymentId,
    string $status,
    string $gateway = '',
    string $gatewayRefundId = ''
): bool {
    if ($orderId <= 0 || $paymentId <= 0) {
        return false;
    }
    $status = trim(strtolower($status));
    if ($status === '') {
        return false;
    }
    $gateway = trim(strtolower($gateway));
    $gatewayRefundId = trim($gatewayRefundId);
    try {
        if ($gatewayRefundId !== '') {
            $stmt = $conn->prepare(
                "SELECT id
                 FROM refund_ledger
                 WHERE order_id = ?
                   AND payment_id = ?
                   AND status = ?
                   AND gateway = ?
                   AND gateway_refund_id = ?
                 LIMIT 1"
            );
            $stmt->bind_param('iisss', $orderId, $paymentId, $status, $gateway, $gatewayRefundId);
        } else {
            $stmt = $conn->prepare(
                "SELECT id
                 FROM refund_ledger
                 WHERE order_id = ?
                   AND payment_id = ?
                   AND status = ?
                   AND gateway = ?
                 LIMIT 1"
            );
            $stmt->bind_param('iiss', $orderId, $paymentId, $status, $gateway);
        }
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        return !empty($row);
    } catch (Throwable $e) {
        return false;
    }
}

/**
 * Idempotency guard for payment webhooks.
 * Returns true when event was already processed.
 */
function payment_webhook_is_duplicate(mysqli $conn, string $provider, string $eventId, string $signature): bool
{
    if ($provider === '' || $eventId === '') {
        return false;
    }
    try {
        $stmt = $conn->prepare(
            "SELECT id
             FROM payment_webhook_events
             WHERE provider = ? AND event_id = ?
             LIMIT 1"
        );
        $stmt->bind_param('ss', $provider, $eventId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        return !empty($row);
    } catch (Throwable $e) {
        throw $e;
    }
}

function cancel_stale_pending_razorpay_order(mysqli $conn, int $orderId, int $ttlMinutes = 30): bool
{
    if ($orderId <= 0 || $ttlMinutes < 1) {
        return false;
    }
    $note = 'System cancelled stale pending Razorpay order after ' . $ttlMinutes . ' minutes.';
    $upd = $conn->prepare(
        "UPDATE orders
         SET order_status = 'cancelled',
             status = 'cancelled',
             notes = CASE WHEN notes IS NULL OR notes = '' THEN ? ELSE CONCAT(notes, '\n', ?) END,
             updated_at = NOW()
         WHERE id = ?
           AND payment_method = 'razorpay'
           AND payment_status IN ('pending', 'failed')
           AND order_status IN ('pending', 'confirmed')"
    );
    $upd->bind_param('ssi', $note, $note, $orderId);
    $upd->execute();
    if ((int) $upd->affected_rows <= 0) {
        return false;
    }

    $updPay = $conn->prepare(
        "UPDATE payments
         SET payment_status = 'failed'
         WHERE order_id = ? AND payment_method = 'razorpay' AND payment_status = 'pending'"
    );
    $updPay->bind_param('i', $orderId);
    $updPay->execute();

    restore_order_inventory($conn, $orderId);
    log_order_activity($conn, $orderId, 'payment_expired', 'system', 0, 'system', $note);
    return true;
}

function release_stale_pending_razorpay_orders_for_customer(mysqli $conn, int $customerId, int $ttlMinutes = 30): void
{
    if ($customerId <= 0 || $ttlMinutes < 1) {
        return;
    }
    $stmt = $conn->prepare(
        "SELECT id
         FROM orders
         WHERE customer_id = ?
           AND payment_method = 'razorpay'
           AND payment_status IN ('pending', 'failed')
           AND order_status IN ('pending', 'confirmed')
           AND created_at < (NOW() - INTERVAL ? MINUTE)"
    );
    $stmt->bind_param('ii', $customerId, $ttlMinutes);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    foreach ($rows as $row) {
        $orderId = (int) ($row['id'] ?? 0);
        if ($orderId <= 0) {
            continue;
        }
        cancel_stale_pending_razorpay_order($conn, $orderId, $ttlMinutes);
    }
}

function release_stale_pending_razorpay_orders_global(mysqli $conn, int $ttlMinutes = 30, int $limit = 100): int
{
    if ($ttlMinutes < 1) {
        return 0;
    }
    $limit = max(1, min(500, $limit));
    $stmt = $conn->prepare(
        "SELECT id
         FROM orders
         WHERE payment_method = 'razorpay'
           AND payment_status IN ('pending', 'failed')
           AND order_status IN ('pending', 'confirmed')
           AND created_at < (NOW() - INTERVAL ? MINUTE)
         ORDER BY id ASC
         LIMIT ?"
    );
    $stmt->bind_param('ii', $ttlMinutes, $limit);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $released = 0;
    foreach ($rows as $row) {
        $orderId = (int) ($row['id'] ?? 0);
        if ($orderId <= 0) {
            continue;
        }
        if (cancel_stale_pending_razorpay_order($conn, $orderId, $ttlMinutes)) {
            $released++;
        }
    }
    return $released;
}

function checkout_shipping_for_order(float $subtotal, string $country, string $pincode, string $paymentMethod): array
{
    $manual = checkout_shipping_breakdown($subtotal, $country, $paymentMethod, $paymentMethod === 'cod');
    if (strcasecmp(trim($country), 'india') !== 0) {
        return $manual;
    }
    if (!preg_match('/^[1-9][0-9]{5}$/', trim($pincode))) {
        return $manual;
    }
    $forward = shiprocket_calculate_forward_rate(
        $subtotal,
        trim(_cfg('SHIPROCKET_PICKUP_PINCODE', '')),
        trim($pincode),
        $paymentMethod === 'cod'
    );
    if (empty($forward['ok'])) {
        return $manual;
    }
    $base = max(0.0, (float) ($forward['rate'] ?? 0));
    $codFee = $paymentMethod === 'cod' ? (float) $manual['cod_fee'] : 0.0;
    return [
        'country' => 'india',
        'base_shipping' => round($base, 2),
        'cod_fee' => round($codFee, 2),
        'shipping_total' => round($base + $codFee, 2),
    ];
}

function payment_webhook_mark_processed(mysqli $conn, string $provider, string $eventId, string $signature): void
{
    if ($provider === '' || $eventId === '') {
        return;
    }
    $stmt = $conn->prepare(
        "INSERT INTO payment_webhook_events (provider, event_id, signature, received_at)
         VALUES (?, ?, ?, NOW())
         ON DUPLICATE KEY UPDATE signature = VALUES(signature)"
    );
    $stmt->bind_param('sss', $provider, $eventId, $signature);
    $stmt->execute();
}

function site_settings_defaults(): array
{
    return [
        'site_name' => 'Amber Fabrics',
        'site_description' => 'Premium woven and blended fabrics for global brands, importers, and distributors.',
        'contact_email' => 'amberfabricstextiles@gmail.com',
        'branding_logo' => 'images/logo-brand-light.svg',
        'hero_swatch_1' => '',
        'hero_swatch_2' => '',
        'hero_swatch_3' => '',
        'hero_swatch_4' => '',
        'hero_swatch_5' => '',
        'hero_swatch_6' => '',
        'announcement_1_text' => 'Free Shipping on orders above ₹999',
        'announcement_1_enabled' => '1',
        'announcement_2_text' => 'New arrivals added every week',
        'announcement_2_enabled' => '1',
        'announcement_3_text' => '',
        'announcement_3_enabled' => '0',
        'announcement_4_text' => '',
        'announcement_4_enabled' => '0',
        'announcement_5_text' => '',
        'announcement_5_enabled' => '0',
        'gst_rate' => '18',
        'company_address' => '',
        'company_phone' => '',
        'gst_number' => '',
        'hsn_code' => '5208',
        'pan_number' => '',
        'company_state' => '',
        'packing_unboxing_notice'        => 'Please record an unboxing video while opening the parcel. This video is mandatory for raising any disputes or return requests. Thank you!',
        'packing_cod_notice'             => 'Collect cash from customer on delivery. Do NOT handover parcel without payment.',
        'packing_footer_note'            => 'If outer packaging/label is found tampered/damaged, do not accept the parcel. All disputes are subject to local jurisdiction only.',
        'packing_repeat_badge_label'     => '',
        'packing_repeat_min_orders'      => '1',
    ];
}

function ensure_site_settings_table(mysqli $conn): bool
{
    static $checked = false;
    static $available = false;
    if ($checked) {
        return $available;
    }
    $checked = true;

    try {
        $stmt = $conn->prepare(
            "SELECT COUNT(*) AS total
             FROM information_schema.TABLES
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = 'site_settings'"
        );
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $available = ((int) ($row['total'] ?? 0)) > 0;
        if (!$available) {
            error_log('[amberfabrics] site_settings table missing. Run: php database/setup.php');
        }
    } catch (Throwable $e) {
        $available = false;
        error_log('[amberfabrics] site_settings table check failed: ' . $e->getMessage());
    }

    return $available;
}

function load_site_settings_from_db(mysqli $conn): array
{
    if (!ensure_site_settings_table($conn)) {
        return [];
    }
    $rows = $conn->query("SELECT setting_key, setting_value FROM site_settings");
    $settings = [];
    while ($row = $rows->fetch_assoc()) {
        $key = (string) ($row['setting_key'] ?? '');
        if ($key !== '') {
            $settings[$key] = (string) ($row['setting_value'] ?? '');
        }
    }
    return $settings;
}

function save_site_settings_to_db(mysqli $conn, array $settings): void
{
    if (!ensure_site_settings_table($conn)) {
        return;
    }
    $stmt = $conn->prepare(
        "INSERT INTO site_settings (setting_key, setting_value)
         VALUES (?, ?)
         ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)"
    );

    foreach ($settings as $key => $value) {
        $settingKey = (string) $key;
        $settingValue = is_scalar($value) ? (string) $value : '';
        $stmt->bind_param('ss', $settingKey, $settingValue);
        $stmt->execute();
    }
}

/**
 * Load site settings with DB-first strategy and JSON fallback.
 */
function get_site_settings(): array
{
    static $settings = null;
    if ($settings !== null) {
        return $settings;
    }

    $settings = site_settings_defaults();

    $settingsFile = __DIR__ . '/../config/site-settings.json';
    if (file_exists($settingsFile)) {
        $json = @file_get_contents($settingsFile);
        if ($json !== false) {
            $loaded = @json_decode($json, true);
            if (is_array($loaded)) {
                $settings = array_merge($settings, $loaded);
            }
        }
    }

    try {
        if (isset($GLOBALS['conn']) && $GLOBALS['conn'] instanceof mysqli) {
            $dbSettings = load_site_settings_from_db($GLOBALS['conn']);
            if (!empty($dbSettings)) {
                $settings = array_merge($settings, $dbSettings);
            }
        }
    } catch (Throwable $e) {
        error_log('[amberfabrics] load site settings from db failed: ' . $e->getMessage());
    }

    return $settings;
}

/**
 * Ensure announcement dismissal table exists.
 */
function ensure_announcement_dismissals_table(mysqli $conn): bool
{
    static $checked = false;
    static $available = false;
    if ($checked) {
        return $available;
    }
    $checked = true;

    try {
        $stmt = $conn->prepare(
            "SELECT COUNT(*) AS total
             FROM information_schema.TABLES
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = 'announcement_dismissals'"
        );
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $available = ((int) ($row['total'] ?? 0)) > 0;
        if (!$available) {
            error_log('[amberfabrics] announcement_dismissals table missing. Run: php database/setup.php');
        }
    } catch (Throwable $e) {
        $available = false;
        error_log('[amberfabrics] announcement_dismissals table check failed: ' . $e->getMessage());
    }

    return $available;
}

/**
 * Build a stable server-side key for current visitor session.
 */
function announcement_session_key(): string
{
    $sid = session_id();
    if ($sid === '') {
        $sid = 'no-session';
    }
    return hash('sha256', $sid . '|announcement');
}

/**
 * Check if current announcement set has been dismissed by this visitor.
 */
function announcement_is_dismissed(mysqli $conn, string $announcementKey): bool
{
    if ($announcementKey === '') {
        return false;
    }

    if (!ensure_announcement_dismissals_table($conn)) {
        return false;
    }
    $sessionKey = announcement_session_key();
    $stmt = $conn->prepare(
        "SELECT id
         FROM announcement_dismissals
         WHERE session_key = ? AND announcement_key = ?
         LIMIT 1"
    );
    $stmt->bind_param('ss', $sessionKey, $announcementKey);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    return !empty($row);
}

/**
 * Persist dismissal for the current visitor + announcement key.
 */
function announcement_mark_dismissed(mysqli $conn, string $announcementKey): bool
{
    if ($announcementKey === '') {
        return false;
    }

    if (!ensure_announcement_dismissals_table($conn)) {
        return false;
    }
    $sessionKey = announcement_session_key();
    $customerId = !empty($_SESSION['customer_id']) ? (int) $_SESSION['customer_id'] : null;

    $stmt = $conn->prepare(
        "INSERT INTO announcement_dismissals (session_key, customer_id, announcement_key)
         VALUES (?, ?, ?)
         ON DUPLICATE KEY UPDATE
            customer_id = VALUES(customer_id),
            updated_at = CURRENT_TIMESTAMP"
    );
    $stmt->bind_param('sis', $sessionKey, $customerId, $announcementKey);
    return $stmt->execute();
}

// Cart Persistence Helpers

function session_ensure_cart_wishlist_arrays(): void
{
    $defaults = [
        'cart' => [],
        'wishlist' => [],
        'cart_size' => [],
        'wishlist_size' => [],
        'cart_meter_length' => [],
        'wishlist_meter_length' => [],
    ];
    foreach ($defaults as $key => $fallback) {
        if (!isset($_SESSION[$key]) || !is_array($_SESSION[$key])) {
            $_SESSION[$key] = $fallback;
        }
    }
}

function wishlist_table_ready(mysqli $conn): bool
{
    static $checked = false;
    static $ready = false;
    if ($checked) {
        return $ready;
    }
    $checked = true;
    try {
        $stmt = $conn->prepare(
            "SELECT COUNT(*) AS total
             FROM information_schema.TABLES
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = 'wishlist_items'"
        );
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $ready = ((int) ($row['total'] ?? 0)) > 0;
    } catch (Throwable $e) {
        $ready = false;
    }
    return $ready;
}

function wishlist_save_to_db(mysqli $conn, int $customerId, array $wishlist, ?array $meterMap = null, ?array $sizeMap = null): void
{
    if ($customerId <= 0 || !wishlist_table_ready($conn)) {
        return;
    }
    try {
        if ($meterMap === null) {
            $meterMap = (isset($_SESSION['wishlist_meter_length']) && is_array($_SESSION['wishlist_meter_length']))
                ? $_SESSION['wishlist_meter_length']
                : [];
        }
        if ($sizeMap === null) {
            $sizeMap = (isset($_SESSION['wishlist_size']) && is_array($_SESSION['wishlist_size']))
                ? $_SESSION['wishlist_size']
                : [];
        }

        $del = $conn->prepare("DELETE FROM wishlist_items WHERE customer_id = ?");
        $del->bind_param('i', $customerId);
        $del->execute();
        if (empty($wishlist)) {
            return;
        }

        $ins = $conn->prepare(
            "INSERT INTO wishlist_items (customer_id, product_id, cart_key, selected_size, quantity, meter_length)
             VALUES (?, ?, ?, ?, ?, ?)"
        );
        foreach ($wishlist as $cartKey => $qtyRaw) {
            [$pid, $sizeFromKey] = cart_parse_key((string) $cartKey);
            if ($pid <= 0) {
                continue;
            }
            $selectedSize = trim((string) ($sizeMap[$cartKey] ?? $sizeFromKey));
            $qty = normalize_meter_quantity($qtyRaw ?? 1, 1.0);
            $meterLength = null;
            if (isset($meterMap[$cartKey]) && is_numeric($meterMap[$cartKey]) && (float) $meterMap[$cartKey] > 0) {
                $meterLength = round((float) $meterMap[$cartKey], 2);
            } elseif (isset($meterMap[$pid]) && is_numeric($meterMap[$pid]) && (float) $meterMap[$pid] > 0) {
                $meterLength = round((float) $meterMap[$pid], 2);
            }
            $keyStr = (string) $cartKey;
            $ins->bind_param('iissdd', $customerId, $pid, $keyStr, $selectedSize, $qty, $meterLength);
            $ins->execute();
        }
    } catch (Throwable $e) {
        error_log('[amberfabrics] wishlist_save_to_db failed: ' . $e->getMessage());
    }
}

function wishlist_load_from_db_bundle(mysqli $conn, int $customerId): array
{
    if ($customerId <= 0 || !wishlist_table_ready($conn)) {
        return ['wishlist' => [], 'size_map' => [], 'meter_map' => []];
    }
    try {
        $stmt = $conn->prepare(
            "SELECT product_id, cart_key, selected_size, quantity, meter_length
             FROM wishlist_items
             WHERE customer_id = ?"
        );
        $stmt->bind_param('i', $customerId);
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

        $wishlist = [];
        $sizeMap = [];
        $meterMap = [];
        foreach ($rows as $row) {
            $pid = (int) ($row['product_id'] ?? 0);
            if ($pid <= 0) {
                continue;
            }
            $selectedSize = trim((string) ($row['selected_size'] ?? ''));
            $cartKey = trim((string) ($row['cart_key'] ?? ''));
            if ($cartKey === '') {
                $cartKey = $pid . '::' . rawurlencode($selectedSize);
            }
            $wishlist[$cartKey] = normalize_meter_quantity($row['quantity'] ?? 1, 1.0);
            if ($selectedSize !== '') {
                $sizeMap[$cartKey] = $selectedSize;
            }
            if (isset($row['meter_length']) && is_numeric($row['meter_length']) && (float) $row['meter_length'] > 0) {
                $meterMap[$cartKey] = round((float) $row['meter_length'], 2);
            }
        }
        return ['wishlist' => $wishlist, 'size_map' => $sizeMap, 'meter_map' => $meterMap];
    } catch (Throwable $e) {
        error_log('[amberfabrics] wishlist_load_from_db failed: ' . $e->getMessage());
        return ['wishlist' => [], 'size_map' => [], 'meter_map' => []];
    }
}

function wishlist_bootstrap_session(mysqli $conn): void
{
    session_ensure_cart_wishlist_arrays();
    $customerId = (int) ($_SESSION['customer_id'] ?? 0);
    if ($customerId <= 0) {
        unset($_SESSION['wishlist_loaded_for']);
        return;
    }
    if ((int) ($_SESSION['wishlist_loaded_for'] ?? 0) === $customerId) {
        return;
    }
    $bundle = wishlist_load_from_db_bundle($conn, $customerId);
    $_SESSION['wishlist'] = is_array($bundle['wishlist'] ?? null) ? $bundle['wishlist'] : [];
    $_SESSION['wishlist_size'] = is_array($bundle['size_map'] ?? null) ? $bundle['size_map'] : [];
    $_SESSION['wishlist_meter_length'] = is_array($bundle['meter_map'] ?? null) ? $bundle['meter_map'] : [];
    $_SESSION['wishlist_loaded_for'] = $customerId;
}

function customer_addresses_table_ready(mysqli $conn): bool
{
    static $checked = false;
    static $ready = false;
    if ($checked) {
        return $ready;
    }
    $checked = true;
    try {
        $stmt = $conn->prepare(
            "SELECT COUNT(*) AS total
             FROM information_schema.TABLES
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = 'customer_addresses'"
        );
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $ready = ((int) ($row['total'] ?? 0)) > 0;
    } catch (Throwable $e) {
        $ready = false;
    }
    return $ready;
}

function customer_addresses_list(mysqli $conn, int $customerId): array
{
    if ($customerId <= 0 || !customer_addresses_table_ready($conn)) {
        return [];
    }
    try {
        $stmt = $conn->prepare(
            "SELECT id, label, full_name, phone, address_line, city, state, pincode, country, is_default_shipping, created_at, updated_at
             FROM customer_addresses
             WHERE customer_id = ?
             ORDER BY is_default_shipping DESC, id DESC"
        );
        $stmt->bind_param('i', $customerId);
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        return is_array($rows) ? $rows : [];
    } catch (Throwable $e) {
        error_log('[amberfabrics] customer_addresses_list failed: ' . $e->getMessage());
        return [];
    }
}

function customer_address_get(mysqli $conn, int $customerId, int $addressId): ?array
{
    if ($customerId <= 0 || $addressId <= 0 || !customer_addresses_table_ready($conn)) {
        return null;
    }
    try {
        $stmt = $conn->prepare(
            "SELECT id, label, full_name, phone, address_line, city, state, pincode, country, is_default_shipping
             FROM customer_addresses
             WHERE id = ? AND customer_id = ?
             LIMIT 1"
        );
        $stmt->bind_param('ii', $addressId, $customerId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        return $row ?: null;
    } catch (Throwable $e) {
        error_log('[amberfabrics] customer_address_get failed: ' . $e->getMessage());
        return null;
    }
}

/**
 * Get (or create) a DB cart row for a logged-in customer.
 */
function cart_get_or_create_db_cart(mysqli $conn, int $customerId): int
{
    $stmt = $conn->prepare("SELECT id FROM cart WHERE customer_id = ? LIMIT 1");
    $stmt->bind_param('i', $customerId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    if ($row) {
        return (int) $row['id'];
    }
    $ins = $conn->prepare("INSERT INTO cart (customer_id) VALUES (?)");
    $ins->bind_param('i', $customerId);
    $ins->execute();
    return (int) $conn->insert_id;
}

/**
 * Save the current session cart to the database for the logged-in customer.
 * Replaces any previously saved cart items.
 */
function cart_items_supports_meter_length(mysqli $conn): bool
{
    static $checked = false;
    static $supported = false;
    if ($checked) {
        return $supported;
    }
    $checked = true;
    try {
        $stmt = $conn->prepare(
            "SELECT COUNT(*) AS total
             FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = 'cart_items'
               AND COLUMN_NAME = 'meter_length'"
        );
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $supported = ((int) ($row['total'] ?? 0)) > 0;
    } catch (Throwable $e) {
        $supported = false;
    }
    return $supported;
}

function cart_items_supports_key_columns(mysqli $conn): bool
{
    static $checked = false;
    static $supported = false;
    if ($checked) {
        return $supported;
    }
    $checked = true;
    try {
        $stmt = $conn->prepare(
            "SELECT SUM(CASE WHEN COLUMN_NAME IN ('cart_key', 'selected_size') THEN 1 ELSE 0 END) AS total
             FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = 'cart_items'
               AND COLUMN_NAME IN ('cart_key', 'selected_size')"
        );
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $supported = ((int) ($row['total'] ?? 0)) === 2;
    } catch (Throwable $e) {
        $supported = false;
    }
    return $supported;
}

/**
 * Parse a cart key in the format "{fabricId}::{variantId}".
 * Returns [fabricId, variantId] — both integers.
 * variantId = 0 means no variant (legacy key or default).
 * Legacy keys like "{fabricId}::{size-text}" are treated as variantId = 0.
 */
function cart_parse_key(string $rawKey): array
{
    $parts = explode('::', trim($rawKey), 2);
    $fabricId = (int) ($parts[0] ?? 0);
    $variantPart = trim((string) ($parts[1] ?? ''));
    $variantId = ($variantPart !== '' && ctype_digit($variantPart))
        ? (int) $variantPart
        : 0;
    return [$fabricId, $variantId];
}

/**
 * Upsert payment attempt audit row by provider + gateway attempt reference.
 * attemptRef should be gateway order id (e.g. Razorpay order id).
 */
function payment_attempt_touch(
    mysqli $conn,
    string $provider,
    string $attemptRef,
    int $orderId = 0,
    int $paymentId = 0,
    string $status = 'created',
    string $source = 'create',
    string $gatewayPaymentId = '',
    string $gatewaySignature = '',
    string $errorCode = '',
    string $errorMessage = '',
    string $webhookEventId = '',
    string $webhookSignature = '',
    ?string $payloadJson = null,
    bool $incrementRetry = false
): void {
    $provider = trim($provider);
    $attemptRef = trim($attemptRef);
    if ($provider === '' || $attemptRef === '') {
        return;
    }
    $status = trim($status) !== '' ? trim($status) : 'created';
    $source = trim($source) !== '' ? trim($source) : 'create';
    $gatewayPaymentId = trim($gatewayPaymentId);
    $gatewaySignature = trim($gatewaySignature);
    $errorCode = trim($errorCode);
    $errorMessage = trim($errorMessage);
    $webhookEventId = trim($webhookEventId);
    $webhookSignature = trim($webhookSignature);
    if ($payloadJson === null) {
        $payloadJson = '';
    }

    try {
        $retryBump = $incrementRetry ? 1 : 0;
        $stmt = $conn->prepare(
            "INSERT INTO payment_attempts (
                order_id, payment_id, provider, attempt_ref, status, source,
                gateway_payment_id, gateway_signature, error_code, error_message,
                webhook_event_id, webhook_signature, payload_json,
                retry_count, first_seen_at, last_seen_at
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())
            ON DUPLICATE KEY UPDATE
                order_id = CASE WHEN VALUES(order_id) > 0 THEN VALUES(order_id) ELSE order_id END,
                payment_id = CASE WHEN VALUES(payment_id) > 0 THEN VALUES(payment_id) ELSE payment_id END,
                status = VALUES(status),
                source = VALUES(source),
                gateway_payment_id = CASE WHEN VALUES(gateway_payment_id) <> '' THEN VALUES(gateway_payment_id) ELSE gateway_payment_id END,
                gateway_signature = CASE WHEN VALUES(gateway_signature) <> '' THEN VALUES(gateway_signature) ELSE gateway_signature END,
                error_code = CASE WHEN VALUES(error_code) <> '' THEN VALUES(error_code) ELSE error_code END,
                error_message = CASE WHEN VALUES(error_message) <> '' THEN VALUES(error_message) ELSE error_message END,
                webhook_event_id = CASE WHEN VALUES(webhook_event_id) <> '' THEN VALUES(webhook_event_id) ELSE webhook_event_id END,
                webhook_signature = CASE WHEN VALUES(webhook_signature) <> '' THEN VALUES(webhook_signature) ELSE webhook_signature END,
                payload_json = CASE WHEN VALUES(payload_json) <> '' THEN VALUES(payload_json) ELSE payload_json END,
                retry_count = retry_count + VALUES(retry_count),
                last_seen_at = NOW()"
        );
        $stmt->bind_param(
            'iissssssssssis',
            $orderId,
            $paymentId,
            $provider,
            $attemptRef,
            $status,
            $source,
            $gatewayPaymentId,
            $gatewaySignature,
            $errorCode,
            $errorMessage,
            $webhookEventId,
            $webhookSignature,
            $payloadJson,
            $retryBump
        );
        $stmt->execute();
    } catch (Throwable $e) {
        error_log('[amberfabrics] payment_attempt_touch failed: ' . $e->getMessage());
    }
}

function log_stock_ledger(
    mysqli $conn,
    int $orderId,
    int $orderItemId,
    int $returnId,
    int $returnItemId,
    int $fabricId,
    int $variantId,
    string $unitType,
    float $quantity,
    string $movement,
    string $direction,
    string $source = '',
    string $notes = ''
): void {
    try {
        $unitType = in_array($unitType, ['meter', 'piece', 'set'], true) ? $unitType : 'meter';
        $movement = in_array($movement, ['reserve', 'release', 'return_restock', 'adjustment'], true) ? $movement : 'adjustment';
        $direction = in_array($direction, ['in', 'out'], true) ? $direction : 'in';
        if ($quantity <= 0) {
            return;
        }
        $stmt = $conn->prepare(
            "INSERT INTO stock_ledger (
                order_id, order_item_id, return_id, return_item_id, fabric_id, variant_id,
                unit_type, quantity, movement, direction, source, notes
             ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
        );
        $stmt->bind_param(
            'iiiiiisdssss',
            $orderId,
            $orderItemId,
            $returnId,
            $returnItemId,
            $fabricId,
            $variantId,
            $unitType,
            $quantity,
            $movement,
            $direction,
            $source,
            $notes
        );
        $stmt->execute();
    } catch (Throwable $e) {
        error_log('[amberfabrics] stock ledger log failed: ' . $e->getMessage());
    }
}

function restock_return_items_inventory(mysqli $conn, int $returnId): float
{
    if ($returnId <= 0) {
        return 0.0;
    }
    $stmt = $conn->prepare(
        "SELECT ri.id, ri.return_id, ri.order_item_id, ri.fabric_id, ri.variant_id, ri.unit_type, ri.quantity, ri.restocked_qty,
                r.order_id
         FROM return_items ri
         JOIN returns r ON r.id = ri.return_id
         WHERE ri.return_id = ?
         FOR UPDATE"
    );
    $stmt->bind_param('i', $returnId);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $totalRestocked = 0.0;
    foreach ($rows as $row) {
        $unitType = in_array((string) ($row['unit_type'] ?? ''), ['meter', 'piece', 'set'], true) ? (string) $row['unit_type'] : 'meter';
        $qtyRequested = (float) ($row['quantity'] ?? 0);
        $qtyRestocked = (float) ($row['restocked_qty'] ?? 0);
        $qtyToRestock = round(max(0.0, $qtyRequested - $qtyRestocked), 2);
        if ($qtyToRestock <= 0) {
            continue;
        }
        $fabricId = (int) ($row['fabric_id'] ?? 0);
        $variantId = (int) ($row['variant_id'] ?? 0);
        if ($variantId > 0) {
            adjust_variant_stock($conn, $variantId, $unitType, $qtyToRestock, 'increase');
        } elseif ($fabricId > 0) {
            adjust_fabric_stock($conn, $fabricId, $unitType, $qtyToRestock, 'increase');
        } else {
            continue;
        }
        $update = $conn->prepare(
            "UPDATE return_items
             SET restocked_qty = restocked_qty + ?,
                 restocked_at = CASE WHEN restocked_at IS NULL THEN NOW() ELSE restocked_at END
             WHERE id = ?"
        );
        $returnItemId = (int) ($row['id'] ?? 0);
        $update->bind_param('di', $qtyToRestock, $returnItemId);
        $update->execute();
        log_stock_ledger(
            $conn,
            (int) ($row['order_id'] ?? 0),
            (int) ($row['order_item_id'] ?? 0),
            $returnId,
            $returnItemId,
            $fabricId,
            $variantId,
            $unitType,
            $qtyToRestock,
            'return_restock',
            'in',
            'returns_module',
            'Restocked from return'
        );
        $totalRestocked += $qtyToRestock;
    }
    return round($totalRestocked, 2);
}

/**
 * Category-wise variant size policy.
 * Returns: ['mode' => 'preset_with_custom'|'hidden', 'sizes' => string[]]
 */
function get_variant_size_policy_by_category(string $category): array
{
    $normalized = mb_strtolower(trim($category));
    $normalized = preg_replace('/[^a-z0-9]+/u', '-', $normalized ?? '');
    $normalized = trim((string) $normalized, '-');

    $presetMap = [
        'towel' => ['Face', 'Hand', 'Bath', 'Bath Sheet'],
        'bedsheet' => ['Single', 'Double', 'Queen', 'King'],
        'table-cover' => ['4 Seater', '6 Seater', '8 Seater'],
    ];

    if (in_array($normalized, ['fabric-by-meter', 'fabric-meter', 'meter-fabric', 'fabrics-by-meter'], true)) {
        return ['mode' => 'hidden', 'sizes' => []];
    }
    if ($normalized === 'table-covers' || $normalized === 'tablecover' || $normalized === 'table-covers') {
        $normalized = 'table-cover';
    }
    if ($normalized === 'bed-sheet' || $normalized === 'bed-sheets') {
        $normalized = 'bedsheet';
    }
    if (isset($presetMap[$normalized])) {
        return ['mode' => 'preset_with_custom', 'sizes' => $presetMap[$normalized]];
    }
    return ['mode' => 'preset_with_custom', 'sizes' => []];
}

function normalize_variant_size_text(string $value): string
{
    $value = preg_replace('/\s+/u', ' ', trim($value));
    return trim((string) $value);
}

/**
 * Check whether the cart_items table has a variant_id column.
 */
function cart_items_supports_variant(mysqli $conn): bool
{
    static $checked   = false;
    static $supported = false;
    if ($checked) {
        return $supported;
    }
    $checked = true;
    try {
        $stmt = $conn->prepare(
            "SELECT COUNT(*) AS total
             FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = 'cart_items'
               AND COLUMN_NAME = 'variant_id'"
        );
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $supported = ((int) ($row['total'] ?? 0)) > 0;
    } catch (Throwable $e) {
        $supported = false;
    }
    return $supported;
}

/**
 * Check whether the order_items table has a variant_id column.
 */
function order_items_supports_variant(mysqli $conn): bool
{
    static $checked   = false;
    static $supported = false;
    if ($checked) {
        return $supported;
    }
    $checked = true;
    try {
        $stmt = $conn->prepare(
            "SELECT COUNT(*) AS total
             FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = 'order_items'
               AND COLUMN_NAME = 'variant_id'"
        );
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $supported = ((int) ($row['total'] ?? 0)) > 0;
    } catch (Throwable $e) {
        $supported = false;
    }
    return $supported;
}

function order_items_supports_tax_snapshot(mysqli $conn): bool
{
    static $checked = false;
    static $supported = false;
    if ($checked) {
        return $supported;
    }
    $checked = true;
    try {
        $stmt = $conn->prepare(
            "SELECT COUNT(*) AS total
             FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = 'order_items'
               AND COLUMN_NAME IN (
                    'taxable_amount',
                    'discount_amount',
                    'gst_rate_snapshot',
                    'gst_amount',
                    'cgst_amount',
                    'sgst_amount',
                    'igst_amount',
                    'tax_type',
                    'hsn_code_snapshot'
               )"
        );
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $supported = ((int) ($row['total'] ?? 0)) === 9;
    } catch (Throwable $e) {
        $supported = false;
    }
    return $supported;
}

function cart_items_supports_unit_type(mysqli $conn): bool
{
    static $supports = null;
    if ($supports !== null) {
        return $supports;
    }
    try {
        $res = $conn->query(
            "SELECT COUNT(*) AS total
             FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = 'cart_items'
               AND COLUMN_NAME = 'unit_type'"
        );
        $supports = ((int) ($res->fetch_assoc()['total'] ?? 0)) > 0;
    } catch (Throwable $e) {
        $supports = false;
    }
    return $supports;
}

function order_items_supports_cost_snapshot(mysqli $conn): bool
{
    static $checked = false;
    static $supported = false;
    if ($checked) {
        return $supported;
    }
    $checked = true;
    try {
        $stmt = $conn->prepare(
            "SELECT COUNT(*) AS total
             FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = 'order_items'
               AND COLUMN_NAME = 'cost_price_snapshot'"
        );
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $supported = ((int) ($row['total'] ?? 0)) > 0;
    } catch (Throwable $e) {
        $supported = false;
    }
    return $supported;
}

function cart_save_to_db(mysqli $conn, int $customerId, array $cart, ?array $meterMap = null): void
{
    try {
        if ($meterMap === null) {
            $meterMap = (isset($_SESSION['cart_meter_length']) && is_array($_SESSION['cart_meter_length']))
                ? $_SESSION['cart_meter_length']
                : [];
        }
        $cartId = cart_get_or_create_db_cart($conn, $customerId);
        $del = $conn->prepare("DELETE FROM cart_items WHERE cart_id = ?");
        $del->bind_param('i', $cartId);
        $del->execute();
        if (empty($cart)) {
            return;
        }
        $supportsMeterLength = cart_items_supports_meter_length($conn);
        $supportsKeyColumns  = cart_items_supports_key_columns($conn);
        $supportsVariant     = cart_items_supports_variant($conn);
        $supportsUnitType    = cart_items_supports_unit_type($conn);

        $productIds = [];
        $variantIds = [];
        foreach ($cart as $cartKey => $qty) {
            [$pid, $variantId] = cart_parse_key((string) $cartKey);
            if ($pid > 0) {
                $productIds[] = $pid;
            }
            if ($variantId > 0) {
                $variantIds[] = $variantId;
            }
        }
        $productIds = array_values(array_unique($productIds));
        $variantIds = array_values(array_unique($variantIds));
        $productUnitMap = [];
        if (!empty($productIds)) {
            $ph = implode(',', array_fill(0, count($productIds), '?'));
            $typ = str_repeat('i', count($productIds));
            $uStmt = $conn->prepare("SELECT id, unit_type FROM fabrics WHERE id IN ($ph)");
            $uStmt->bind_param($typ, ...$productIds);
            $uStmt->execute();
            $uRows = $uStmt->get_result()->fetch_all(MYSQLI_ASSOC);
            foreach ($uRows as $ur) {
                $productUnitMap[(int) ($ur['id'] ?? 0)] = (string) ($ur['unit_type'] ?? 'meter');
            }
        }
        $variantMap = !empty($variantIds) ? get_variants_by_ids($conn, $variantIds) : [];

        if ($supportsKeyColumns && $supportsMeterLength && $supportsVariant && $supportsUnitType) {
            $ins = $conn->prepare(
                "INSERT INTO cart_items (cart_id, product_id, quantity, fabric_id, quantity_meters, meter_length, cart_key, selected_size, variant_id, unit_type)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
            );
        } elseif ($supportsKeyColumns && $supportsMeterLength && $supportsVariant) {
            $ins = $conn->prepare(
                "INSERT INTO cart_items (cart_id, product_id, quantity, fabric_id, quantity_meters, meter_length, cart_key, selected_size, variant_id)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)"
            );
        } elseif ($supportsKeyColumns && $supportsMeterLength && $supportsUnitType) {
            $ins = $conn->prepare(
                "INSERT INTO cart_items (cart_id, product_id, quantity, fabric_id, quantity_meters, meter_length, cart_key, selected_size, unit_type)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)"
            );
        } elseif ($supportsKeyColumns && $supportsMeterLength) {
            $ins = $conn->prepare(
                "INSERT INTO cart_items (cart_id, product_id, quantity, fabric_id, quantity_meters, meter_length, cart_key, selected_size)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?)"
            );
        } elseif ($supportsKeyColumns && $supportsUnitType) {
            $ins = $conn->prepare(
                "INSERT INTO cart_items (cart_id, product_id, quantity, fabric_id, quantity_meters, cart_key, selected_size, unit_type)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?)"
            );
        } elseif ($supportsKeyColumns) {
            $ins = $conn->prepare(
                "INSERT INTO cart_items (cart_id, product_id, quantity, fabric_id, quantity_meters, cart_key, selected_size)
                 VALUES (?, ?, ?, ?, ?, ?, ?)"
            );
        } elseif ($supportsMeterLength && $supportsUnitType) {
            $ins = $conn->prepare(
                "INSERT INTO cart_items (cart_id, product_id, quantity, fabric_id, quantity_meters, meter_length, unit_type)
                 VALUES (?, ?, ?, ?, ?, ?, ?)"
            );
        } elseif ($supportsMeterLength) {
            $ins = $conn->prepare(
                "INSERT INTO cart_items (cart_id, product_id, quantity, fabric_id, quantity_meters, meter_length)
                 VALUES (?, ?, ?, ?, ?, ?)"
            );
        } elseif ($supportsUnitType) {
            $ins = $conn->prepare(
                "INSERT INTO cart_items (cart_id, product_id, quantity, fabric_id, quantity_meters, unit_type)
                 VALUES (?, ?, ?, ?, ?, ?)"
            );
        } else {
            $ins = $conn->prepare(
                "INSERT INTO cart_items (cart_id, product_id, quantity, fabric_id, quantity_meters)
                 VALUES (?, ?, ?, ?, ?)"
            );
        }
        foreach ($cart as $cartKey => $qty) {
            $rawKey = (string) $cartKey;
            [$pid, $variantId] = cart_parse_key($rawKey);
            if ($pid <= 0) {
                continue;
            }
            $unitType = in_array((string) ($productUnitMap[$pid] ?? 'meter'), ['meter', 'piece', 'set'], true)
                ? (string) $productUnitMap[$pid]
                : 'meter';
            if ($variantId > 0 && isset($variantMap[$variantId])) {
                $variantUnit = in_array((string) ($variantMap[$variantId]['unit_type'] ?? ''), ['meter', 'piece', 'set'], true)
                    ? (string) $variantMap[$variantId]['unit_type']
                    : '';
                if ($variantUnit !== '') {
                    $unitType = $variantUnit;
                }
            }
            $q = normalize_quantity_by_unit($qty, $unitType);
            // For display in legacy columns, preserve size from session when variant not present.
            $selectedSize = '';
            if ($variantId <= 0) {
                $selectedSize = trim((string) ($_SESSION['cart_size'][$rawKey] ?? ''));
                if ($selectedSize === '') {
                    $parts = explode('::', $rawKey, 2);
                    $legacyToken = trim((string) ($parts[1] ?? ''));
                    if ($legacyToken !== '' && !ctype_digit($legacyToken)) {
                        $selectedSize = trim(rawurldecode($legacyToken));
                    }
                }
            }
            $meterLength = null;
            if (isset($meterMap[$rawKey]) && is_numeric($meterMap[$rawKey]) && (float) $meterMap[$rawKey] > 0) {
                $meterLength = round((float) $meterMap[$rawKey], 2);
            } elseif (isset($meterMap[$pid]) && is_numeric($meterMap[$pid]) && (float) $meterMap[$pid] > 0) {
                $meterLength = round((float) $meterMap[$pid], 2);
            }
            $variantIdVal = $variantId > 0 ? $variantId : null;
            if ($supportsKeyColumns && $supportsMeterLength && $supportsVariant && $supportsUnitType) {
                $ins->bind_param('iididdssis', $cartId, $pid, $q, $pid, $q, $meterLength, $rawKey, $selectedSize, $variantIdVal, $unitType);
            } elseif ($supportsKeyColumns && $supportsMeterLength && $supportsVariant) {
                $ins->bind_param('iididdssi', $cartId, $pid, $q, $pid, $q, $meterLength, $rawKey, $selectedSize, $variantIdVal);
            } elseif ($supportsKeyColumns && $supportsMeterLength && $supportsUnitType) {
                $ins->bind_param('iididdsss', $cartId, $pid, $q, $pid, $q, $meterLength, $rawKey, $selectedSize, $unitType);
            } elseif ($supportsKeyColumns && $supportsMeterLength) {
                $ins->bind_param('iididdss', $cartId, $pid, $q, $pid, $q, $meterLength, $rawKey, $selectedSize);
            } elseif ($supportsKeyColumns && $supportsUnitType) {
                $ins->bind_param('iididsss', $cartId, $pid, $q, $pid, $q, $rawKey, $selectedSize, $unitType);
            } elseif ($supportsKeyColumns) {
                $ins->bind_param('iididss', $cartId, $pid, $q, $pid, $q, $rawKey, $selectedSize);
            } elseif ($supportsMeterLength && $supportsUnitType) {
                $ins->bind_param('iididds', $cartId, $pid, $q, $pid, $q, $meterLength, $unitType);
            } elseif ($supportsMeterLength) {
                $ins->bind_param('iididd', $cartId, $pid, $q, $pid, $q, $meterLength);
            } elseif ($supportsUnitType) {
                $ins->bind_param('iidids', $cartId, $pid, $q, $pid, $q, $unitType);
            } else {
                $ins->bind_param('iidid', $cartId, $pid, $q, $pid, $q);
            }
            $ins->execute();
        }
    } catch (Throwable $e) {
        error_log('[amberfabrics] cart_save_to_db failed: ' . $e->getMessage());
    }
}

/**
 * Load the saved cart from DB for a logged-in customer.
 * Returns an associative array [product_id => quantity].
 */
function cart_load_from_db(mysqli $conn, int $customerId): array
{
    $bundle = cart_load_from_db_bundle($conn, $customerId);
    return $bundle['cart'];
}

/**
 * Load the saved cart and meter metadata from DB for a logged-in customer.
 * Returns ['cart' => [product_id => quantity], 'meter_map' => [product_id => meter_length]].
 */
function cart_load_from_db_bundle(mysqli $conn, int $customerId): array
{
    try {
        $supportsMeterLength = cart_items_supports_meter_length($conn);
        $supportsKeyColumns  = cart_items_supports_key_columns($conn);
        $supportsVariant     = cart_items_supports_variant($conn);
        $supportsUnitType    = cart_items_supports_unit_type($conn);

        if ($supportsKeyColumns && $supportsMeterLength && $supportsVariant && $supportsUnitType) {
            $stmt = $conn->prepare(
                "SELECT ci.product_id, ci.quantity, ci.meter_length, ci.cart_key, ci.selected_size, ci.variant_id, ci.unit_type
                 FROM cart c
                 JOIN cart_items ci ON ci.cart_id = c.id
                 WHERE c.customer_id = ?"
            );
        } elseif ($supportsKeyColumns && $supportsMeterLength && $supportsVariant) {
            $stmt = $conn->prepare(
                "SELECT ci.product_id, ci.quantity, ci.meter_length, ci.cart_key, ci.selected_size, ci.variant_id
                 FROM cart c
                 JOIN cart_items ci ON ci.cart_id = c.id
                 WHERE c.customer_id = ?"
            );
        } elseif ($supportsKeyColumns && $supportsMeterLength && $supportsUnitType) {
            $stmt = $conn->prepare(
                "SELECT ci.product_id, ci.quantity, ci.meter_length, ci.cart_key, ci.selected_size, ci.unit_type
                 FROM cart c
                 JOIN cart_items ci ON ci.cart_id = c.id
                 WHERE c.customer_id = ?"
            );
        } elseif ($supportsKeyColumns && $supportsMeterLength) {
            $stmt = $conn->prepare(
                "SELECT ci.product_id, ci.quantity, ci.meter_length, ci.cart_key, ci.selected_size
                 FROM cart c
                 JOIN cart_items ci ON ci.cart_id = c.id
                 WHERE c.customer_id = ?"
            );
        } elseif ($supportsKeyColumns && $supportsUnitType) {
            $stmt = $conn->prepare(
                "SELECT ci.product_id, ci.quantity, ci.cart_key, ci.selected_size, ci.unit_type
                 FROM cart c
                 JOIN cart_items ci ON ci.cart_id = c.id
                 WHERE c.customer_id = ?"
            );
        } elseif ($supportsKeyColumns) {
            $stmt = $conn->prepare(
                "SELECT ci.product_id, ci.quantity, ci.cart_key, ci.selected_size
                 FROM cart c
                 JOIN cart_items ci ON ci.cart_id = c.id
                 WHERE c.customer_id = ?"
            );
        } elseif ($supportsMeterLength && $supportsUnitType) {
            $stmt = $conn->prepare(
                "SELECT ci.product_id, ci.quantity, ci.meter_length, ci.unit_type
                 FROM cart c
                 JOIN cart_items ci ON ci.cart_id = c.id
                 WHERE c.customer_id = ?"
            );
        } elseif ($supportsMeterLength) {
            $stmt = $conn->prepare(
                "SELECT ci.product_id, ci.quantity, ci.meter_length
                 FROM cart c
                 JOIN cart_items ci ON ci.cart_id = c.id
                 WHERE c.customer_id = ?"
            );
        } elseif ($supportsUnitType) {
            $stmt = $conn->prepare(
                "SELECT ci.product_id, ci.quantity, ci.unit_type
                 FROM cart c
                 JOIN cart_items ci ON ci.cart_id = c.id
                 WHERE c.customer_id = ?"
            );
        } else {
            $stmt = $conn->prepare(
                "SELECT ci.product_id, ci.quantity
                 FROM cart c
                 JOIN cart_items ci ON ci.cart_id = c.id
                 WHERE c.customer_id = ?"
            );
        }
        $stmt->bind_param('i', $customerId);
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $cart     = [];
        $meterMap = [];
        foreach ($rows as $row) {
            if ((int) $row['product_id'] > 0) {
                $pid       = (int) $row['product_id'];
                $variantId = (int) ($row['variant_id'] ?? 0);
                $cartKey   = trim((string) ($row['cart_key'] ?? ''));
                if ($cartKey === '') {
                    // Reconstruct key: prefer variant id, fall back to legacy size-based key.
                    if ($variantId > 0) {
                        $cartKey = $pid . '::' . $variantId;
                    } else {
                        $cartKey = $pid . '::0';
                    }
                }
                $itemUnit = in_array((string) ($row['unit_type'] ?? ''), ['meter', 'piece', 'set'], true)
                    ? (string) $row['unit_type']
                    : 'meter';
                $cart[$cartKey] = normalize_quantity_by_unit($row['quantity'] ?? 1, $itemUnit);
                if ($supportsMeterLength && isset($row['meter_length']) && is_numeric($row['meter_length']) && (float) $row['meter_length'] > 0) {
                    $meterMap[$cartKey] = round((float) $row['meter_length'], 2);
                }
            }
        }
        return ['cart' => $cart, 'meter_map' => $meterMap];
    } catch (Throwable $e) {
        error_log('[amberfabrics] cart_load_from_db failed: ' . $e->getMessage());
        return ['cart' => [], 'meter_map' => []];
    }
}

/**
 * Clear the customer's saved DB cart (called after order is placed).
 */
function cart_clear_db(mysqli $conn, int $customerId): void
{
    try {
        $stmt = $conn->prepare("SELECT id FROM cart WHERE customer_id = ? LIMIT 1");
        $stmt->bind_param('i', $customerId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        if (!$row) {
            return;
        }
        $cartId = (int) $row['id'];
        $del = $conn->prepare("DELETE FROM cart_items WHERE cart_id = ?");
        $del->bind_param('i', $cartId);
        $del->execute();
    } catch (Throwable $e) {
        error_log('[amberfabrics] cart_clear_db failed: ' . $e->getMessage());
    }
}

/**
 * For cancelled + paid Razorpay orders, create a real gateway refund first.
 * Mark local order/payment as refunded only when gateway reports processed.
 * Returns ['ok' => bool, 'message' => string].
 */
function admin_mark_order_refunded(mysqli $conn, int $orderId): array
{
    try {
        $conn->begin_transaction();

        $orderStmt = $conn->prepare(
            "SELECT id, order_number, payment_method, payment_status, order_status, notes
             FROM orders
             WHERE id = ?
             FOR UPDATE"
        );
        $orderStmt->bind_param('i', $orderId);
        $orderStmt->execute();
        $order = $orderStmt->get_result()->fetch_assoc();
        if (!$order) {
            throw new RuntimeException('Order not found.');
        }

        $method = strtolower((string) ($order['payment_method'] ?? ''));
        $payStatus = strtolower((string) ($order['payment_status'] ?? ''));
        $ordStatus = strtolower((string) ($order['order_status'] ?? ''));

        if ($ordStatus !== 'cancelled' || $payStatus !== 'paid') {
            throw new RuntimeException('Order is not eligible for refund update.');
        }

        $payStmt = $conn->prepare(
            "SELECT id, amount, razorpay_payment_id, transaction_id
             FROM payments
             WHERE order_id = ? AND payment_method = ?
             LIMIT 1
             FOR UPDATE"
        );
        $payStmt->bind_param('is', $orderId, $method);
        $payStmt->execute();
        $payment = $payStmt->get_result()->fetch_assoc();
        $resolvedGatewayRefundId = '';

        if ($method === 'razorpay') {
            if (!$payment) {
                throw new RuntimeException('Payment record not found for Razorpay order.');
            }

            $paymentId = trim((string) ($payment['razorpay_payment_id'] ?? ''));
            if ($paymentId === '') {
                $paymentId = trim((string) ($payment['transaction_id'] ?? ''));
            }
            if ($paymentId === '') {
                throw new RuntimeException('Missing Razorpay payment id.');
            }

            require_once __DIR__ . '/../vendor/autoload.php';
            $keyId = _cfg('RAZORPAY_KEY_ID', '');
            $keySecret = _cfg('RAZORPAY_KEY_SECRET', '');
            if ($keyId === '' || $keySecret === '') {
                throw new RuntimeException('Razorpay configuration missing.');
            }

            $amountPaise = 0;
            if (isset($payment['amount']) && is_numeric($payment['amount'])) {
                $amountPaise = max(0, (int) round(((float) $payment['amount']) * 100));
            }

            $refundStatus = '';
            $existingRefundId = '';
            $existingNotes = (string) ($order['notes'] ?? '');
            $existingRefundId = extract_razorpay_refund_id_from_notes($existingNotes);
            if ($existingRefundId === '') {
                $existingRefundId = latest_refund_ledger_gateway_refund_id($conn, $orderId, 'razorpay');
            }
            try {
                $api = new Razorpay\Api\Api($keyId, $keySecret);
                $refundId = '';
                if ($existingRefundId !== '') {
                    $refund = $api->refund->fetch($existingRefundId);
                    $refundId = $existingRefundId;
                } else {
                    $payload = ['speed' => 'normal'];
                    if ($amountPaise > 0) {
                        $payload['amount'] = $amountPaise;
                    }
                    $refund = $api->payment->fetch($paymentId)->refund($payload);
                    $refundId = trim((string) ($refund['id'] ?? ''));
                }
                $resolvedGatewayRefundId = $refundId;
                $refundStatus = strtolower(trim((string) ($refund['status'] ?? '')));
                if ($existingRefundId === '') {
                    $refundNote = '[System] Razorpay refund initiated';
                    if ($refundId !== '') {
                        $refundNote .= ' (refund_id: ' . $refundId . ')';
                    }
                    if ($refundStatus !== '') {
                        $refundNote .= ' [status: ' . $refundStatus . ']';
                    }
                    $refundNote .= ' on ' . date('d M Y, H:i');

                    $updNotes = $conn->prepare(
                        "UPDATE orders
                         SET notes = CASE WHEN notes IS NULL OR notes = '' THEN ? ELSE CONCAT(notes, '\n', ?) END
                         WHERE id = ?"
                    );
                    $updNotes->bind_param('ssi', $refundNote, $refundNote, $orderId);
                    $updNotes->execute();

                    $paymentRowId = (int) ($payment['id'] ?? 0);
                    $refundAmount = isset($payment['amount']) ? (float) $payment['amount'] : 0.0;
                    if (
                        $paymentRowId > 0 &&
                        $refundAmount > 0 &&
                        !refund_ledger_event_exists($conn, $orderId, $paymentRowId, 'initiated', 'razorpay', $refundId)
                    ) {
                        log_refund_ledger(
                            $conn,
                            $orderId,
                            $paymentRowId,
                            $refundAmount,
                            'INR',
                            'initiated',
                            'razorpay',
                            $refundId,
                            'Refund initiated from admin order view.'
                        );
                    }
                    log_order_activity($conn, $orderId, 'refund_initiated', 'admin', (int) ($_SESSION['admin_id'] ?? 0), (string) ($_SESSION['admin_name'] ?? 'admin'), 'Razorpay refund initiated.');
                }
            } catch (Throwable $e) {
                throw new RuntimeException('Razorpay refund failed: ' . $e->getMessage());
            }

            if ($refundStatus !== 'processed') {
                $conn->commit();
                return [
                    'ok' => true,
                    'message' => 'Refund initiated in Razorpay (status: ' . ($refundStatus !== '' ? $refundStatus : 'processing') . '). Keep payment as Paid until processed.',
                ];
            }
        }

        $updOrder = $conn->prepare(
            "UPDATE orders
             SET payment_status = 'refunded',
                 order_status = CASE WHEN order_status = 'cancelled' THEN 'refunded' ELSE order_status END,
                 status = CASE WHEN status = 'cancelled' THEN 'cancelled' ELSE status END,
                 updated_at = NOW()
             WHERE id = ?"
        );
        $updOrder->bind_param('i', $orderId);
        $updOrder->execute();

        if ($payment) {
            $updPayment = $conn->prepare(
                "UPDATE payments
                 SET payment_status = 'refunded'
                 WHERE id = ?"
            );
            $paymentRowId = (int) ($payment['id'] ?? 0);
            $updPayment->bind_param('i', $paymentRowId);
            $updPayment->execute();
            $refundAmount = isset($payment['amount']) ? (float) $payment['amount'] : 0.0;
            if (
                $refundAmount > 0 &&
                !refund_ledger_event_exists($conn, $orderId, $paymentRowId, 'processed', $method, $resolvedGatewayRefundId)
            ) {
                log_refund_ledger(
                    $conn,
                    $orderId,
                    $paymentRowId,
                    $refundAmount,
                    'INR',
                    'processed',
                    $method,
                    $resolvedGatewayRefundId,
                    'Refund marked processed from admin flow.'
                );
            }
        }

        // Restore inventory after successful refund completion for cancelled paid orders.
        restore_order_inventory($conn, $orderId);
        log_order_activity($conn, $orderId, 'refund_completed', 'admin', (int) ($_SESSION['admin_id'] ?? 0), (string) ($_SESSION['admin_name'] ?? 'admin'), 'Order marked refunded.');

        $conn->commit();
        return ['ok' => true, 'message' => 'Order marked as refunded.'];
    } catch (Throwable $e) {
        try {
            $conn->rollback();
        } catch (Throwable $rollbackException) {
            // ignore rollback errors
        }
        return ['ok' => false, 'message' => $e->getMessage() ?: 'Refund failed.'];
    }
}

/**
 * Sync local refund status with Razorpay using stored refund_id in order notes.
 * Useful for previously mismatched orders.
 * Returns ['ok' => bool, 'message' => string].
 */
function admin_sync_razorpay_refund_status(mysqli $conn, int $orderId): array
{
    try {
        $conn->begin_transaction();

        $orderStmt = $conn->prepare(
            "SELECT id, payment_method, payment_status, order_status, status, notes
             FROM orders
             WHERE id = ?
             FOR UPDATE"
        );
        $orderStmt->bind_param('i', $orderId);
        $orderStmt->execute();
        $order = $orderStmt->get_result()->fetch_assoc();
        if (!$order) {
            throw new RuntimeException('Order not found.');
        }

        $method = strtolower((string) ($order['payment_method'] ?? ''));
        if ($method !== 'razorpay') {
            throw new RuntimeException('Sync is available only for Razorpay orders.');
        }

        $notes = (string) ($order['notes'] ?? '');
        $refundId = extract_razorpay_refund_id_from_notes($notes);
        if ($refundId === '') {
            $refundId = latest_refund_ledger_gateway_refund_id($conn, $orderId, 'razorpay');
        }
        if ($refundId === '') {
            throw new RuntimeException('No Razorpay refund_id found in order notes.');
        }

        require_once __DIR__ . '/../vendor/autoload.php';
        $keyId = _cfg('RAZORPAY_KEY_ID', '');
        $keySecret = _cfg('RAZORPAY_KEY_SECRET', '');
        if ($keyId === '' || $keySecret === '') {
            throw new RuntimeException('Razorpay configuration missing.');
        }

        $api = new Razorpay\Api\Api($keyId, $keySecret);
        $refund = $api->refund->fetch($refundId);
        $refundStatus = strtolower(trim((string) ($refund['status'] ?? '')));

        $paymentRowStmt = $conn->prepare(
            "SELECT id FROM payments WHERE order_id = ? AND payment_method = 'razorpay' LIMIT 1 FOR UPDATE"
        );
        $paymentRowStmt->bind_param('i', $orderId);
        $paymentRowStmt->execute();
        $payment = $paymentRowStmt->get_result()->fetch_assoc();

        if ($refundStatus === 'processed') {
            $updOrder = $conn->prepare(
                "UPDATE orders
                 SET payment_status = 'refunded',
                     order_status = 'refunded',
                     status = CASE WHEN status = 'cancelled' THEN 'cancelled' ELSE status END,
                     updated_at = NOW()
                 WHERE id = ?"
            );
            $updOrder->bind_param('i', $orderId);
            $updOrder->execute();

            if ($payment) {
                $updPayment = $conn->prepare("UPDATE payments SET payment_status = 'refunded' WHERE id = ?");
                $paymentId = (int) $payment['id'];
                $updPayment->bind_param('i', $paymentId);
                $updPayment->execute();
                $payAmtStmt = $conn->prepare("SELECT amount FROM payments WHERE id = ? LIMIT 1");
                $payAmtStmt->bind_param('i', $paymentId);
                $payAmtStmt->execute();
                $payAmtRow = $payAmtStmt->get_result()->fetch_assoc() ?: [];
                $refundAmount = (float) ($payAmtRow['amount'] ?? 0);
                if (
                    $refundAmount > 0 &&
                    !refund_ledger_event_exists($conn, $orderId, $paymentId, 'processed', 'razorpay', $refundId)
                ) {
                    log_refund_ledger(
                        $conn,
                        $orderId,
                        $paymentId,
                        $refundAmount,
                        'INR',
                        'processed',
                        'razorpay',
                        $refundId,
                        'Refund processed confirmed by Razorpay sync.'
                    );
                }
            }

            restore_order_inventory($conn, $orderId);
            log_order_activity($conn, $orderId, 'refund_completed', 'admin', (int) ($_SESSION['admin_id'] ?? 0), (string) ($_SESSION['admin_name'] ?? 'admin'), 'Refund synced as processed from Razorpay.');

            $conn->commit();
            return ['ok' => true, 'message' => 'Refund processed in Razorpay. Local status updated to Refunded.'];
        }

        // Not processed yet: keep "refund initiated" state locally.
        $updOrder = $conn->prepare(
            "UPDATE orders
             SET payment_status = 'paid',
                 order_status = 'cancelled',
                 status = 'cancelled',
                 updated_at = NOW()
             WHERE id = ?"
        );
        $updOrder->bind_param('i', $orderId);
        $updOrder->execute();

        if ($payment) {
            $updPayment = $conn->prepare("UPDATE payments SET payment_status = 'paid' WHERE id = ?");
            $paymentId = (int) $payment['id'];
            $updPayment->bind_param('i', $paymentId);
            $updPayment->execute();
        }

        $conn->commit();
        return ['ok' => true, 'message' => 'Refund is still ' . ($refundStatus !== '' ? $refundStatus : 'processing') . ' in Razorpay. Local status corrected to Refund Initiated state.'];
    } catch (Throwable $e) {
        try {
            $conn->rollback();
        } catch (Throwable $rollbackException) {
            // ignore rollback errors
        }
        return ['ok' => false, 'message' => $e->getMessage() ?: 'Refund sync failed.'];
    }
}

// E-Commerce Email Helpers

/**
 * Read active app config from config/db.php bootstrap.
 */
function _cfg(string $key, string $default = ''): string
{
    if (isset($GLOBALS['_app_config'][$key]) && $GLOBALS['_app_config'][$key] !== '') {
        return (string) $GLOBALS['_app_config'][$key];
    }
    return $default;
}

function _mailer_base(): PHPMailer\PHPMailer\PHPMailer
{
    require_once __DIR__ . '/../vendor/phpmailer/phpmailer/src/PHPMailer.php';
    require_once __DIR__ . '/../vendor/phpmailer/phpmailer/src/SMTP.php';
    require_once __DIR__ . '/../vendor/phpmailer/phpmailer/src/Exception.php';

    $mail = new PHPMailer\PHPMailer\PHPMailer(true);

    $driver = strtolower(trim(_cfg('MAIL_DRIVER', 'smtp')));

    if ($driver === 'mail') {
        // Use PHP's built-in mail() — required on hosts that block outbound SMTP
        // (e.g. InfinityFree). The host's sendmail handles delivery.
        $mail->isMail();
    } else {
        // Full SMTP (default) — for Gmail App Password, Mailgun, etc.
        $mail->isSMTP();
        $mail->Host       = _cfg('SMTP_HOST', 'smtp.gmail.com');
        $mail->SMTPAuth   = true;
        $mail->Username   = _cfg('MAIL_FROM');
        $mail->Password   = _cfg('SMTP_PASSWORD');
        $mail->SMTPSecure = PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port       = (int) _cfg('SMTP_PORT', '587');
    }

    $fromAddress = _cfg('MAIL_FROM', 'no-reply@amberfabrics.com');
    $mail->setFrom($fromAddress, 'Amber Fabrics');
    $mail->CharSet = 'UTF-8';
    return $mail;
}

/**
 * Send order confirmation to the customer after order placement.
 */
function send_order_confirmation_email(mysqli $conn, int $orderId): bool
{
    $row = $conn->prepare(
        "SELECT o.*, c.name AS cname, c.email AS cemail
         FROM orders o JOIN customers c ON c.id = o.customer_id
         WHERE o.id = ?"
    );
    $row->bind_param('i', $orderId);
    $row->execute();
    $order = $row->get_result()->fetch_assoc();
    if (!$order) { return false; }

    $iStmt = $conn->prepare(
        "SELECT unit_type, fabric_name_snapshot, quantity, quantity_meters, price, price_per_meter, total, line_total
         FROM order_items WHERE order_id = ?"
    );
    $iStmt->bind_param('i', $orderId);
    $iStmt->execute();
    $items = $iStmt->get_result()->fetch_all(MYSQLI_ASSOC);

    $sym = $order['currency'] === 'USD' ? '$' : 'Rs ';
    $isPaid = strtolower((string) ($order['payment_status'] ?? '')) === 'paid';
    $paymentMethodLabel = strtoupper((string) ($order['payment_method'] ?? ''));
    $lines = [
        'Dear ' . $order['cname'] . ',',
        '',
        'Thank you for your order! Your order has been received and is being processed.',
        $isPaid ? 'Payment Status: Paid' : ('Payment Status: Pending (' . $paymentMethodLabel . ')'),
        '',
        'Order Number : ' . $order['order_number'],
        'Date         : ' . date('d M Y', strtotime($order['created_at'])),
        'Currency     : ' . $order['currency'],
        '',
        '--- Items ---',
    ];
    foreach ($items as $it) {
        $unitType = in_array((string) ($it['unit_type'] ?? ''), ['meter', 'piece', 'set'], true) ? (string) $it['unit_type'] : 'meter';
        $qty = (($it['quantity'] ?? 0) > 0) ? $it['quantity'] : ($it['quantity_meters'] ?? 1);
        $unitPrice = (($it['price'] ?? 0) > 0) ? $it['price'] : ($it['price_per_meter'] ?? 0);
        $lineTotal = (($it['total'] ?? 0) > 0) ? $it['total'] : ($it['line_total'] ?? 0);
        $lines[] = $it['fabric_name_snapshot'] . ' - ' . format_quantity_by_unit($qty, $unitType)
            . quantity_unit_suffix($unitType) . ' x '
            . $sym . number_format((float) $unitPrice, 2)
            . (($unitType === 'piece' || $unitType === 'set') ? ' each = ' : '/m = ')
            . $sym . number_format((float) $lineTotal, 2);
    }
    $lines[] = '';
    $lines[] = 'Subtotal : ' . $sym . number_format((float)$order['subtotal'], 2);
    $lines[] = 'Shipping : ' . $sym . number_format((float)$order['shipping_cost'], 2);
    $lines[] = 'Total    : ' . $sym . number_format((float)$order['total'], 2) . ' ' . $order['currency'];
    $lines[] = '';
    $lines[] = '';
    $lines[] = 'We will notify you once your order is shipped.';
    $lines[] = '';
    $lines[] = 'Regards,';
    $lines[] = 'Amber Fabrics';
    $template = email_template_build('order_confirmation', [
        'order_number' => (string) $order['order_number'],
        'lines' => $lines,
    ]);

    try {
        $mail = _mailer_base();
        $mail->addAddress($order['cemail'], $order['cname']);
        $mail->Subject = $template['subject'];
        $mail->Body    = $template['body'];
        $mail->send();
        return true;
    } catch (Throwable $e) {
        error_log('[amberfabrics] order confirmation email failed: ' . $e->getMessage());
        return false;
    }
}

/**
 * Notify customer when admin changes order status.
 */
function send_order_status_update_email(mysqli $conn, int $orderId, string $newStatus): bool
{
    $row = $conn->prepare(
        "SELECT o.order_number, o.created_at, c.name AS cname, c.email AS cemail
         FROM orders o JOIN customers c ON c.id = o.customer_id
         WHERE o.id = ?"
    );
    $row->bind_param('i', $orderId);
    $row->execute();
    $order = $row->get_result()->fetch_assoc();
    if (!$order) { return false; }

    $statusLower = strtolower(trim($newStatus));
    $lines = [
        'Dear ' . $order['cname'] . ',',
        '',
        'Your order ' . $order['order_number'] . ' status has been updated to: ' . strtoupper($newStatus),
        '',
    ];

    if (in_array($statusLower, ['shipped', 'delivered'], true)) {
        $shipStmt = $conn->prepare(
            "SELECT courier_name, tracking_id, tracking_url, shipped_at, delivered_at
             FROM shipments
             WHERE order_id = ?
             LIMIT 1"
        );
        $shipStmt->bind_param('i', $orderId);
        $shipStmt->execute();
        $shipment = $shipStmt->get_result()->fetch_assoc() ?: [];

        $courier = trim((string) ($shipment['courier_name'] ?? ''));
        $trackingId = trim((string) ($shipment['tracking_id'] ?? ''));
        $trackingUrl = safe_external_url((string) ($shipment['tracking_url'] ?? ''));
        $shippedAt = trim((string) ($shipment['shipped_at'] ?? ''));
        $deliveredAt = trim((string) ($shipment['delivered_at'] ?? ''));

        if ($courier !== '' || $trackingId !== '' || $trackingUrl !== '' || $shippedAt !== '' || $deliveredAt !== '') {
            $lines[] = 'Shipment Details:';
            if ($courier !== '') { $lines[] = 'Courier: ' . $courier; }
            if ($trackingId !== '') { $lines[] = 'Tracking ID: ' . $trackingId; }
            if ($trackingUrl !== '') { $lines[] = 'Tracking URL: ' . $trackingUrl; }
            if ($shippedAt !== '') { $lines[] = 'Shipped At: ' . $shippedAt; }
            if ($deliveredAt !== '') { $lines[] = 'Delivered At: ' . $deliveredAt; }
            $lines[] = '';
        }
    }

    $lines[] = 'Log in to your account to view full order details.';
    $lines[] = '';
    $lines[] = 'Regards,';
    $lines[] = 'Amber Fabrics';
    $template = email_template_build('order_status_update', [
        'order_number' => (string) $order['order_number'],
        'new_status' => $newStatus,
        'lines' => $lines,
    ]);

    try {
        $mail = _mailer_base();
        $mail->addAddress($order['cemail'], $order['cname']);
        $mail->Subject = $template['subject'];
        $mail->Body    = $template['body'];
        $mail->send();
        return true;
    } catch (Throwable $e) {
        error_log('[amberfabrics] order status email failed: ' . $e->getMessage());
        return false;
    }
}

/**
 * Send password reset link to a customer.
 */
function send_customer_password_reset_email(string $email, string $token): bool
{
    $appUrl   = rtrim(_cfg('APP_URL', ''), '/');
    if ($appUrl === '') {
        // Fallback: build from server vars but never trust HTTP_HOST for security-sensitive URLs.
        $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $appUrl   = $protocol . '://' . ($_SERVER['SERVER_NAME'] ?? 'localhost');
    }
    $resetUrl = $appUrl . '/customer/reset-password.php?token=' . urlencode($token);

    $template = email_template_build('customer_password_reset', ['reset_url' => $resetUrl]);

    try {
        $mail = _mailer_base();
        $mail->addAddress($email);
        $mail->Subject = $template['subject'];
        $mail->Body    = $template['body'];
        $mail->send();
        return true;
    } catch (Throwable $e) {
        error_log('[amberfabrics] password reset email failed: ' . $e->getMessage());
        return false;
    }
}

/**
 * Send email address verification link to a newly registered customer.
 */
function send_customer_verification_email(string $email, string $name, string $token): bool
{
    $appUrl  = rtrim(_cfg('APP_URL', ''), '/');
    if ($appUrl === '') {
        $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $appUrl   = $protocol . '://' . ($_SERVER['SERVER_NAME'] ?? 'localhost');
    }
    $verifyUrl = $appUrl . '/customer/verify-email.php?token=' . urlencode($token);

    $template = email_template_build('customer_email_verification', [
        'name' => $name,
        'verify_url' => $verifyUrl,
    ]);

    try {
        $mail = _mailer_base();
        $mail->addAddress($email, $name);
        $mail->Subject = $template['subject'];
        $mail->Body    = $template['body'];
        $mail->send();
        return true;
    } catch (Throwable $e) {
        error_log('[amberfabrics] verification email failed: ' . $e->getMessage());
        return false;
    }
}

/**
 * Send admin login OTP email (initial + resend) using shared template.
 */
function send_admin_login_otp_email(string $email, string $name, string $otp, bool $isResend = false): bool
{
    $template = email_template_build('admin_login_otp', [
        'name' => $name,
        'otp' => $otp,
        'is_resend' => $isResend,
    ]);

    try {
        $mail = _mailer_base();
        $mail->addAddress($email, $name);
        $mail->Subject = $template['subject'];
        $mail->Body = $template['body'];
        $mail->send();
        return true;
    } catch (Throwable $e) {
        error_log('[amberfabrics] admin otp email send failed: ' . $e->getMessage());
        return false;
    }
}

/**
 * Calculate GST breakdown for display without changing order totals.
 */
function configured_gst_rate(): float
{
    $settings = get_site_settings();
    $raw = trim((string) ($settings['gst_rate'] ?? '18'));
    if (!is_numeric($raw)) {
        return 18.0;
    }
    $rate = (float) $raw;
    if ($rate < 0) {
        return 0.0;
    }
    if ($rate > 100) {
        return 100.0;
    }
    return round($rate, 2);
}

function order_gst_breakdown(float $taxableAmount, string $country, ?float $gstRate = null): array
{
    if ($gstRate === null) {
        $gstRate = configured_gst_rate();
    }
    $isIndia = strcasecmp(trim($country), 'india') === 0;
    $taxable = max(0.0, round($taxableAmount, 2));
    if (!$isIndia || $taxable <= 0 || $gstRate <= 0) {
        return [
            'enabled' => false,
            'rate' => 0.0,
            'taxable_amount' => $taxable,
            'gst_amount' => 0.0,
            'cgst_amount' => 0.0,
            'sgst_amount' => 0.0,
        ];
    }

    $gst = round(($taxable * $gstRate) / 100, 2);
    $half = round($gst / 2, 2);
    return [
        'enabled' => true,
        'rate' => $gstRate,
        'taxable_amount' => $taxable,
        'gst_amount' => $gst,
        'cgst_amount' => $half,
        'sgst_amount' => round($gst - $half, 2),
    ];
}

/**
 * Shiprocket integration helpers (API-first with manual fallback).
 */
function shiprocket_enabled(): bool
{
    return _cfg('SHIPROCKET_ENABLED', '0') === '1';
}

function shiprocket_fallback_mode(string $reason): array
{
    return ['ok' => false, 'manual_fallback' => true, 'reason' => $reason];
}

function shiprocket_http_json(string $method, string $url, array $headers = [], ?array $payload = null): array
{
    if (!function_exists('curl_init')) {
        return ['ok' => false, 'status' => 0, 'body' => null, 'error' => 'cURL is unavailable'];
    }
    $ch = curl_init($url);
    if ($ch === false) {
        return ['ok' => false, 'status' => 0, 'body' => null, 'error' => 'Unable to initialize cURL'];
    }
    $finalHeaders = array_merge(['Accept: application/json'], $headers);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 20,
        CURLOPT_CUSTOMREQUEST => strtoupper($method),
        CURLOPT_HTTPHEADER => $finalHeaders,
    ]);
    if ($payload !== null) {
        $json = json_encode($payload);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $json === false ? '{}' : $json);
        if (!array_filter($finalHeaders, static fn($h) => stripos($h, 'Content-Type:') === 0)) {
            curl_setopt($ch, CURLOPT_HTTPHEADER, array_merge($finalHeaders, ['Content-Type: application/json']));
        }
    }

    $raw = curl_exec($ch);
    $errno = curl_errno($ch);
    $err = curl_error($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);

    if ($errno !== 0) {
        return ['ok' => false, 'status' => $status, 'body' => null, 'error' => $err !== '' ? $err : ('cURL error ' . $errno)];
    }
    $decoded = json_decode((string) $raw, true);
    return [
        'ok' => $status >= 200 && $status < 300,
        'status' => $status,
        'body' => is_array($decoded) ? $decoded : [],
        'error' => ($status >= 200 && $status < 300) ? '' : ('HTTP ' . $status),
    ];
}

function shiprocket_get_token(): array
{
    if (!shiprocket_enabled()) {
        return shiprocket_fallback_mode('Shiprocket disabled');
    }
    $email = trim(_cfg('SHIPROCKET_EMAIL', ''));
    $password = trim(_cfg('SHIPROCKET_PASSWORD', ''));
    $baseUrl = rtrim(_cfg('SHIPROCKET_BASE_URL', 'https://apiv2.shiprocket.in'), '/');
    if ($email === '' || $password === '') {
        return shiprocket_fallback_mode('Shiprocket credentials missing');
    }

    if (!isset($_SESSION['shiprocket_token_cache']) || !is_array($_SESSION['shiprocket_token_cache'])) {
        $_SESSION['shiprocket_token_cache'] = [];
    }
    $cached = $_SESSION['shiprocket_token_cache'];
    $token = trim((string) ($cached['token'] ?? ''));
    $expiresAt = (int) ($cached['expires_at'] ?? 0);
    if ($token !== '' && $expiresAt > (time() + 60)) {
        return ['ok' => true, 'token' => $token];
    }

    $resp = shiprocket_http_json('POST', $baseUrl . '/v1/external/auth/login', [], [
        'email' => $email,
        'password' => $password,
    ]);
    if (empty($resp['ok'])) {
        return shiprocket_fallback_mode('Shiprocket auth failed');
    }
    $newToken = trim((string) ($resp['body']['token'] ?? ''));
    if ($newToken === '') {
        return shiprocket_fallback_mode('Shiprocket token missing');
    }
    $_SESSION['shiprocket_token_cache'] = [
        'token' => $newToken,
        'expires_at' => time() + 8 * 60,
    ];
    return ['ok' => true, 'token' => $newToken];
}

function shiprocket_calculate_forward_rate(float $subtotal, string $pickupPincode, string $deliveryPincode, bool $isCod): array
{
    if (!shiprocket_enabled()) {
        return shiprocket_fallback_mode('Shiprocket disabled');
    }
    if ($pickupPincode === '' || $deliveryPincode === '') {
        return shiprocket_fallback_mode('Pincode missing for rate check');
    }
    $tokenResp = shiprocket_get_token();
    if (empty($tokenResp['ok'])) {
        return $tokenResp;
    }
    $baseUrl = rtrim(_cfg('SHIPROCKET_BASE_URL', 'https://apiv2.shiprocket.in'), '/');
    $weightKg = (float) _cfg('SHIPROCKET_DEFAULT_WEIGHT_KG', '0.5');
    if ($weightKg <= 0) {
        $weightKg = 0.5;
    }
    $resp = shiprocket_http_json('GET', $baseUrl . '/v1/external/courier/serviceability?' . http_build_query([
        'pickup_postcode' => $pickupPincode,
        'delivery_postcode' => $deliveryPincode,
        'cod' => $isCod ? 1 : 0,
        'weight' => $weightKg,
        'declared_value' => max(1, round($subtotal, 2)),
    ]), ['Authorization: Bearer ' . $tokenResp['token']]);

    if (empty($resp['ok'])) {
        return shiprocket_fallback_mode('Forward rate API unavailable');
    }
    $options = (array) ($resp['body']['data']['available_courier_companies'] ?? []);
    if (empty($options)) {
        return shiprocket_fallback_mode('No courier available for this pincode');
    }
    usort($options, static function ($a, $b): int {
        return ((float) ($a['rate'] ?? 0)) <=> ((float) ($b['rate'] ?? 0));
    });
    $best = $options[0];
    $normalizedOptions = [];
    foreach (array_slice($options, 0, 8) as $opt) {
        $normalizedOptions[] = [
            'courier_id' => (int) ($opt['courier_company_id'] ?? 0),
            'courier_name' => (string) ($opt['courier_name'] ?? ''),
            'rate' => round((float) ($opt['rate'] ?? 0), 2),
            'estimated_days' => (int) ($opt['estimated_delivery_days'] ?? 0),
        ];
    }
    return [
        'ok' => true,
        'manual_fallback' => false,
        'rate' => round((float) ($best['rate'] ?? 0), 2),
        'courier_name' => (string) ($best['courier_name'] ?? ''),
        'courier_id' => (int) ($best['courier_company_id'] ?? 0),
        'options' => $normalizedOptions,
        'raw' => $best,
    ];
}

function shiprocket_calculate_reverse_rate(string $pickupPincode, string $deliveryPincode): array
{
    if (!shiprocket_enabled()) {
        return shiprocket_fallback_mode('Shiprocket disabled');
    }
    $tokenResp = shiprocket_get_token();
    if (empty($tokenResp['ok'])) {
        return $tokenResp;
    }
    $baseUrl = rtrim(_cfg('SHIPROCKET_BASE_URL', 'https://apiv2.shiprocket.in'), '/');
    $weightKg = (float) _cfg('SHIPROCKET_DEFAULT_REVERSE_WEIGHT_KG', _cfg('SHIPROCKET_DEFAULT_WEIGHT_KG', '0.5'));
    if ($weightKg <= 0) {
        $weightKg = 0.5;
    }
    $resp = shiprocket_http_json('GET', $baseUrl . '/v1/external/courier/serviceability?' . http_build_query([
        'pickup_postcode' => $pickupPincode,
        'delivery_postcode' => $deliveryPincode,
        'cod' => 0,
        'weight' => $weightKg,
        'is_return' => 1,
    ]), ['Authorization: Bearer ' . $tokenResp['token']]);
    if (empty($resp['ok'])) {
        return shiprocket_fallback_mode('Reverse rate API unavailable');
    }
    $options = (array) ($resp['body']['data']['available_courier_companies'] ?? []);
    if (empty($options)) {
        return shiprocket_fallback_mode('No reverse courier serviceability');
    }
    usort($options, static function ($a, $b): int {
        return ((float) ($a['rate'] ?? 0)) <=> ((float) ($b['rate'] ?? 0));
    });
    $best = $options[0];
    return [
        'ok' => true,
        'manual_fallback' => false,
        'rate' => round((float) ($best['rate'] ?? 0), 2),
        'courier_name' => (string) ($best['courier_name'] ?? ''),
    ];
}

function shiprocket_auto_create_awb_for_order(mysqli $conn, int $orderId): array
{
    if ($orderId <= 0) {
        return shiprocket_fallback_mode('Invalid order id');
    }
    if (!shiprocket_enabled()) {
        return shiprocket_fallback_mode('Shiprocket disabled');
    }
    try {
        $orderStmt = $conn->prepare(
            "SELECT id, order_number, customer_name, customer_phone, customer_email, address, city, state, pincode, total_amount, payment_method
             FROM orders WHERE id = ? LIMIT 1"
        );
        $orderStmt->bind_param('i', $orderId);
        $orderStmt->execute();
        $order = $orderStmt->get_result()->fetch_assoc();
        if (!$order) {
            return shiprocket_fallback_mode('Order not found');
        }

        $existingStmt = $conn->prepare("SELECT id, tracking_id FROM shipments WHERE order_id = ? LIMIT 1");
        $existingStmt->bind_param('i', $orderId);
        $existingStmt->execute();
        $existing = $existingStmt->get_result()->fetch_assoc() ?: [];
        if (trim((string) ($existing['tracking_id'] ?? '')) !== '') {
            return ['ok' => true, 'manual_fallback' => false, 'message' => 'Shipment already exists'];
        }

        $tokenResp = shiprocket_get_token();
        if (empty($tokenResp['ok'])) {
            return $tokenResp;
        }
        $baseUrl = rtrim(_cfg('SHIPROCKET_BASE_URL', 'https://apiv2.shiprocket.in'), '/');
        $pickup = trim(_cfg('SHIPROCKET_PICKUP_LOCATION', 'Primary'));
        $payload = [
            'order_id' => (string) $order['order_number'],
            'order_date' => date('Y-m-d H:i'),
            'pickup_location' => $pickup !== '' ? $pickup : 'Primary',
            'billing_customer_name' => (string) $order['customer_name'],
            'billing_last_name' => '',
            'billing_address' => (string) $order['address'],
            'billing_city' => (string) $order['city'],
            'billing_pincode' => (string) $order['pincode'],
            'billing_state' => (string) $order['state'],
            'billing_country' => 'India',
            'billing_email' => (string) $order['customer_email'],
            'billing_phone' => (string) $order['customer_phone'],
            'shipping_is_billing' => true,
            'order_items' => [['name' => 'Amber Fabrics Order', 'sku' => (string) $order['order_number'], 'units' => 1, 'selling_price' => (float) $order['total_amount']]],
            'payment_method' => strtolower((string) ($order['payment_method'] ?? '')) === 'cod' ? 'COD' : 'Prepaid',
            'sub_total' => (float) $order['total_amount'],
            'length' => (float) _cfg('SHIPROCKET_DEFAULT_LENGTH_CM', '20'),
            'breadth' => (float) _cfg('SHIPROCKET_DEFAULT_BREADTH_CM', '20'),
            'height' => (float) _cfg('SHIPROCKET_DEFAULT_HEIGHT_CM', '2'),
            'weight' => (float) _cfg('SHIPROCKET_DEFAULT_WEIGHT_KG', '0.5'),
        ];
        $resp = shiprocket_http_json('POST', $baseUrl . '/v1/external/orders/create/adhoc', [
            'Authorization: Bearer ' . $tokenResp['token'],
            'Content-Type: application/json',
        ], $payload);

        if (empty($resp['ok'])) {
            return shiprocket_fallback_mode('Shipment creation API failed');
        }

        $trackingId = trim((string) ($resp['body']['shipment_id'] ?? ''));
        $awbCode = trim((string) ($resp['body']['awb_code'] ?? $trackingId));
        if ($awbCode === '') {
            return shiprocket_fallback_mode('AWB missing from Shiprocket response');
        }
        $courierName = trim((string) ($resp['body']['courier_name'] ?? 'Shiprocket'));
        $trackingUrl = trim((string) (_cfg('SHIPROCKET_TRACKING_URL_BASE', 'https://shiprocket.co/tracking/') . $awbCode));
        $shippingCost = (float) ($resp['body']['freight_charges'] ?? 0);

        $current = date('Y-m-d H:i:s');
        if (!empty($existing['id'])) {
            $sid = (int) $existing['id'];
            $upd = $conn->prepare(
                "UPDATE shipments SET courier_name = ?, tracking_id = ?, tracking_url = ?, shipping_cost = ?, shipped_at = COALESCE(shipped_at, ?) WHERE id = ?"
            );
            $upd->bind_param('sssdsi', $courierName, $awbCode, $trackingUrl, $shippingCost, $current, $sid);
            $upd->execute();
        } else {
            $ins = $conn->prepare(
                "INSERT INTO shipments (order_id, courier_name, tracking_id, tracking_url, shipping_cost, shipped_at) VALUES (?, ?, ?, ?, ?, ?)"
            );
            $ins->bind_param('isssds', $orderId, $courierName, $awbCode, $trackingUrl, $shippingCost, $current);
            $ins->execute();
        }

        log_order_activity($conn, $orderId, 'awb_created', 'system', 0, 'shiprocket', 'AWB created automatically: ' . $awbCode);
        return ['ok' => true, 'manual_fallback' => false, 'awb' => $awbCode];
    } catch (Throwable $e) {
        error_log('[shiprocket] auto awb failed: ' . $e->getMessage());
        return shiprocket_fallback_mode('Auto AWB failed, continue manual shipment mode');
    }
}

// ---------------------------------------------------------------------------
// Form helpers — Bootstrap 5 inline validation
// ---------------------------------------------------------------------------

/**
 * Returns the appropriate Bootstrap input CSS classes.
 * $base is 'form-control' for <input>/<textarea>, 'form-select' for <select>.
 */
function form_class(array $errors, string $field, string $base = 'form-control'): string
{
    return $base . (empty($errors[$field]) ? '' : ' is-invalid');
}

/**
 * Returns an invalid-feedback <div> with the field error, or '' if none.
 */
function form_error(array $errors, string $field): string
{
    if (empty($errors[$field])) {
        return '';
    }
    return '<div class="invalid-feedback d-block">' . e($errors[$field]) . '</div>';
}

// ---------------------------------------------------------------------------
// Pagination helper — Bootstrap 5 pagination component
// ---------------------------------------------------------------------------

/**
 * Renders a Bootstrap 5 pagination nav.
 *
 * @param int    $page       Current page (1-based)
 * @param int    $pages      Total pages
 * @param array  $queryState Existing query params to preserve in page links
 * @param string $pageKey    URL parameter name for the page number
 * @param int    $total      Total records (used for "Showing X–Y of Z" info line)
 * @param int    $perPage    Records per page (used for info line)
 */
function render_pagination(int $page, int $pages, array $queryState, string $pageKey = 'page', int $total = 0, int $perPage = 0): string
{
    if ($pages <= 1) {
        return '';
    }
    $page = max(1, min($page, $pages));

    // Info line
    $info = '';
    if ($total > 0 && $perPage > 0) {
        $from = ($page - 1) * $perPage + 1;
        $to   = min($page * $perPage, $total);
        $info = '<p class="text-muted small text-center mb-1">Showing ' . $from . '&ndash;' . $to . ' of ' . $total . ' results</p>';
    }

    // Build an even-sized visible window of numeric page links.
    // This keeps pagination rhythm consistent across the site.
    $visibleCount = min(10, $pages); // always even
    $leftCount = (int) ($visibleCount / 2);
    $start = $page - $leftCount + 1;
    $end = $start + $visibleCount - 1;

    if ($start < 1) {
        $start = 1;
        $end = $visibleCount;
    }
    if ($end > $pages) {
        $end = $pages;
        $start = max(1, $end - $visibleCount + 1);
    }

    $pool = range($start, $end);

    $mkUrl = static fn(int $p): string => '?' . list_build_query(array_merge($queryState, [$pageKey => $p]));

    $html  = '<nav aria-label="Pagination" class="mt-3">';
    $html .= '<ul class="pagination justify-content-center flex-wrap mb-0">';

    // Prev button
    if ($page <= 1) {
        $html .= '<li class="page-item disabled"><span class="page-link">&laquo; Prev</span></li>';
    } else {
        $html .= '<li class="page-item"><a class="page-link" href="' . e($mkUrl($page - 1)) . '">&laquo; Prev</a></li>';
    }

    // Optional first-page shortcut with ellipsis
    if ($start > 1) {
        $html .= '<li class="page-item"><a class="page-link" href="' . e($mkUrl(1)) . '">1</a></li>';
        if ($start > 2) {
            $html .= '<li class="page-item disabled"><span class="page-link">&hellip;</span></li>';
        }
    }

    // Page numbers in even-sized window
    foreach ($pool as $p) {
        if ($p === $page) {
            $html .= '<li class="page-item active" aria-current="page"><span class="page-link">' . $p . '</span></li>';
        } else {
            $html .= '<li class="page-item"><a class="page-link" href="' . e($mkUrl($p)) . '">' . $p . '</a></li>';
        }
    }

    // Optional last-page shortcut with ellipsis
    if ($end < $pages) {
        if ($end < $pages - 1) {
            $html .= '<li class="page-item disabled"><span class="page-link">&hellip;</span></li>';
        }
        $html .= '<li class="page-item"><a class="page-link" href="' . e($mkUrl($pages)) . '">' . $pages . '</a></li>';
    }

    // Next button
    if ($page >= $pages) {
        $html .= '<li class="page-item disabled"><span class="page-link">Next &raquo;</span></li>';
    } else {
        $html .= '<li class="page-item"><a class="page-link" href="' . e($mkUrl($page + 1)) . '">Next &raquo;</a></li>';
    }

    $html .= '</ul></nav>';

    return $info . $html;
}
