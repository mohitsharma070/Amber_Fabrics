<?php
require_once __DIR__ . '/../services/CartService.php';
require_once __DIR__ . '/../services/PaymentService.php';
require_once __DIR__ . '/../services/InventoryService.php';

// Cart Persistence Helpers

function session_ensure_cart_wishlist_arrays() : void
{
    CartService::session_ensure_cart_wishlist_arrays();
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
        error_log('[app] wishlist_save_to_db failed: ' . $e->getMessage());
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
        error_log('[app] wishlist_load_from_db failed: ' . $e->getMessage());
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
        error_log('[app] customer_addresses_list failed: ' . $e->getMessage());
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
        error_log('[app] customer_address_get failed: ' . $e->getMessage());
        return null;
    }
}

function cart_get_or_create_db_cart(mysqli $conn, int $customerId) : int
{
    return CartService::cart_get_or_create_db_cart($conn, $customerId);
}


function cart_items_supports_meter_length(mysqli $conn) : bool
{
    return CartService::cart_items_supports_meter_length($conn);
}


function cart_items_supports_key_columns(mysqli $conn) : bool
{
    return CartService::cart_items_supports_key_columns($conn);
}


function cart_parse_key(string $rawKey) : array
{
    return CartService::cart_parse_key($rawKey);
}


function payment_attempt_touch(mysqli $conn,
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
    bool $incrementRetry = false) : void
{
    PaymentService::payment_attempt_touch($conn, $provider, $attemptRef, $orderId, $paymentId, $status, $source, $gatewayPaymentId, $gatewaySignature, $errorCode, $errorMessage, $webhookEventId, $webhookSignature, $payloadJson, $incrementRetry);
}


function log_stock_ledger(mysqli $conn,
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
    string $notes = '') : void
{
    InventoryService::log_stock_ledger($conn, $orderId, $orderItemId, $returnId, $returnItemId, $fabricId, $variantId, $unitType, $quantity, $movement, $direction, $source, $notes);
}


function restock_return_items_inventory(mysqli $conn, int $returnId) : float
{
    return InventoryService::restock_return_items_inventory($conn, $returnId);
}


/**
 * Category-wise variant size policy.
 * Returns: ['mode' => 'preset_with_custom'|'hidden', 'sizes' => string[]]
 * Source of truth: categories.uses_variant_size (dynamic admin setting).
 */
function get_variant_size_policy_by_category(string $category, ?mysqli $conn = null): array
{
    $normalized = mb_strtolower(trim($category));
    $normalized = preg_replace('/[^a-z0-9]+/u', '-', $normalized ?? '');
    $normalized = trim((string) $normalized, '-');

    $presetMap = [
        'towel' => ['Face', 'Hand', 'Bath', 'Bath Sheet'],
        'bedsheet' => ['Single', 'Double', 'Queen', 'King'],
        'table-cover' => ['4 Seater', '6 Seater', '8 Seater'],
    ];

    if ($normalized === 'table-covers' || $normalized === 'tablecover') {
        $normalized = 'table-cover';
    }
    if ($normalized === 'bed-sheet' || $normalized === 'bed-sheets') {
        $normalized = 'bedsheet';
    }
    if ($normalized === 'bedsheets') {
        $normalized = 'bedsheet';
    }
    if ($normalized === 'towels') {
        $normalized = 'towel';
    }

    // Dynamic per-category override from DB (for future categories).
    if ($conn instanceof mysqli && $normalized !== '') {
        static $hasUsesVariantSizeColumn = null;
        if ($hasUsesVariantSizeColumn === null) {
            try {
                $colRes = $conn->query("SHOW COLUMNS FROM categories LIKE 'uses_variant_size'");
                $hasUsesVariantSizeColumn = $colRes && $colRes->num_rows > 0;
            } catch (Throwable $e) {
                $hasUsesVariantSizeColumn = false;
            }
        }
        if ($hasUsesVariantSizeColumn) {
            try {
                $stmt = $conn->prepare("SELECT uses_variant_size FROM categories WHERE slug = ? LIMIT 1");
                $stmt->bind_param('s', $normalized);
                $stmt->execute();
                $row = $stmt->get_result()->fetch_assoc();
                if ($row !== null) {
                    $usesVariantSize = (int) ($row['uses_variant_size'] ?? 0) === 1;
                    if ($usesVariantSize) {
                        return ['mode' => 'preset_with_custom', 'sizes' => $presetMap[$normalized] ?? []];
                    }
                    return ['mode' => 'hidden', 'sizes' => []];
                }
            } catch (Throwable $e) {
                // Fall back to static mapping below.
            }
        }
    }

    // Old slug-only fallback removed intentionally; category flag is authoritative.
    return ['mode' => 'hidden', 'sizes' => []];
}

/**
 * Unit-wise variant size policy.
 * meter => size hidden
 * piece/set => size enabled (custom or preset-ready mode)
 */
function get_variant_size_policy_by_unit_type(string $unitType): array
{
    $unit = in_array($unitType, ['meter', 'piece', 'set'], true) ? $unitType : 'meter';
    if ($unit === 'meter') {
        return ['mode' => 'hidden', 'sizes' => []];
    }
    return ['mode' => 'preset_with_custom', 'sizes' => []];
}

function normalize_variant_size_text(string $value): string
{
    $value = preg_replace('/\s+/u', ' ', trim($value));
    return trim((string) $value);
}

function cart_items_supports_variant(mysqli $conn) : bool
{
    return CartService::cart_items_supports_variant($conn);
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

function cart_items_supports_unit_type(mysqli $conn) : bool
{
    return CartService::cart_items_supports_unit_type($conn);
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

function cart_save_to_db(mysqli $conn, int $customerId, array $cart, ?array $meterMap = null) : void
{
    CartService::cart_save_to_db($conn, $customerId, $cart, $meterMap);
}


function cart_load_from_db(mysqli $conn, int $customerId) : array
{
    return CartService::cart_load_from_db($conn, $customerId);
}


function cart_load_from_db_bundle(mysqli $conn, int $customerId) : array
{
    return CartService::cart_load_from_db_bundle($conn, $customerId);
}


function cart_clear_db(mysqli $conn, int $customerId) : void
{
    CartService::cart_clear_db($conn, $customerId);
}


function checkout_session_clear_after_order(mysqli $conn, int $customerId = 0) : void
{
    CartService::checkout_session_clear_after_order($conn, $customerId);
}


function admin_mark_order_refunded(mysqli $conn, int $orderId) : array
{
    return PaymentService::admin_mark_order_refunded($conn, $orderId);
}

function admin_mark_order_paid(mysqli $conn, int $orderId) : array
{
    return PaymentService::admin_mark_order_paid($conn, $orderId);
}


function admin_sync_razorpay_refund_status(mysqli $conn, int $orderId) : array
{
    return PaymentService::admin_sync_razorpay_refund_status($conn, $orderId);
}
