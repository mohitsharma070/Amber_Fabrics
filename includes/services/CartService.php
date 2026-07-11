<?php

final class CartService
{
    /**
     * Build normalized cart/wishlist line items from session cart maps.
     *
     * Returns:
     * - items: hydrated cart lines
     * - removed_keys: cart keys rejected due to missing/inactive products/variants
     * - invalid_variant_found: whether any variant mismatch was detected
     */
    public static function cart_hydrate_items(mysqli $conn, array $source, array $sizeMap = [], array $meterMap = []): array
    {
        if (empty($source)) {
            return ['items' => [], 'removed_keys' => [], 'invalid_variant_found' => false];
        }

        $ids = [];
        $variantIds = [];
        foreach (array_keys($source) as $key) {
            [$pid, $variantId] = CartService::cart_parse_key((string) $key);
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
        $sql = "SELECT id, name, image, unit_type, meter_options, min_order_meters, qty_step, wastage_percent, price, sale_price, price_inr, stock, stock_meters, is_available, dispatch_time
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

        $variantMap = !empty($variantIds) ? InventoryService::get_variants_by_ids($conn, $variantIds) : [];
        $items = [];
        $removedKeys = [];
        $invalidVariantFound = false;

        foreach ($source as $cartKey => $sourceQty) {
            [$pid, $variantId] = CartService::cart_parse_key((string) $cartKey);
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
            $minQty = $unitType === 'meter'
                ? normalize_meter_quantity($row['min_order_meters'] ?? 1, 1.0)
                : 1.0;
            $qty = normalize_quantity_by_unit($sourceQty ?? 1, $unitType, (float) $minQty);
            if ($unitType === 'meter') {
                $qtyStep = is_numeric($row['qty_step'] ?? null) ? (float) $row['qty_step'] : 0.0;
                if (!meter_qty_respects_step((float) $qty, (float) $minQty, (float) $qtyStep)) {
                    $removedKeys[] = (string) $cartKey;
                    continue;
                }
            }
            $meterLength = null;
            $bundleQty = null;
            if ($unitType === 'meter') {
                if (!isset($meterMap[$cartKey]) || !is_numeric($meterMap[$cartKey]) || (float) $meterMap[$cartKey] <= 0) {
                    $removedKeys[] = (string) $cartKey;
                    continue;
                }
                $meterLength = round((float) $meterMap[$cartKey], 2);
                $allowedMeterOptions = CartService::parse_meter_options((string) ($row['meter_options'] ?? ''), (float) $minQty);
                if (!CartService::meter_length_is_allowed($meterLength, $allowedMeterOptions)) {
                    $removedKeys[] = (string) $cartKey;
                    continue;
                }
                $bundleRatio = $meterLength > 0 ? ($qty / $meterLength) : 0;
                if ($bundleRatio <= 0 || abs($bundleRatio - round($bundleRatio)) > 0.0001) {
                    $removedKeys[] = (string) $cartKey;
                    continue;
                }
                $bundleQty = max(1, (int) round($bundleRatio));
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
                ? CartService::variant_size_display($variant, $unitType)
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

    public static function cart_items_subtotal(array $items): float
    {
        $subtotal = 0.0;
        foreach ($items as $item) {
            $subtotal = round($subtotal + (float) ($item['subtotal'] ?? 0), 2);
        }
        return $subtotal;
    }

    public static function variant_size_display(array $variant, string $unitType): string
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
    public static function parse_size_options(?string $sizeRaw): array
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
     * Parse admin-configured meter options (e.g. "1, 2, 2.5") into normalized floats.
     */
    public static function parse_meter_options(?string $meterRaw, float $min = 0.01): array
    {
        $meterRaw = (string) $meterRaw;
        if ($meterRaw === '') {
            return [];
        }
        $parts = preg_split('/[,\|]+/', $meterRaw);
        if (!is_array($parts)) {
            return [];
        }
        $options = [];
        foreach ($parts as $part) {
            $clean = trim((string) $part);
            if ($clean === '' || !is_numeric($clean)) {
                continue;
            }
            $value = round((float) $clean, 2);
            if ($value < $min) {
                continue;
            }
            $options[(string) $value] = $value;
        }
        $final = array_values($options);
        sort($final);
        return $final;
    }

    /**
     * Check whether a posted meter length is valid for the product.
     * If no configured options exist, any positive meter length is allowed.
     */
    public static function meter_length_is_allowed(float $meterLength, array $allowedOptions): bool
    {
        if ($meterLength <= 0) {
            return false;
        }
        if (empty($allowedOptions)) {
            return true;
        }
        foreach ($allowedOptions as $option) {
            if (abs((float) $option - $meterLength) < 0.001) {
                return true;
            }
        }
        return false;
    }

    /**
     * Shared India shipping + COD fee calculation.
     */
    public static function checkout_shipping_breakdown(float $subtotal, string $country, string $paymentMethod, bool $codFeeApply = true): array
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

    public static function session_ensure_cart_wishlist_arrays(): void
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

    /**
     * Get (or create) a DB cart row for a logged-in customer.
     */
    public static function cart_get_or_create_db_cart(mysqli $conn, int $customerId): int
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
    public static function cart_items_supports_meter_length(mysqli $conn): bool
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

    public static function cart_items_supports_key_columns(mysqli $conn): bool
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
     * Returns [fabricId, variantId] - both integers.
     * variantId = 0 means no variant (legacy key or default).
     * Legacy keys like "{fabricId}::{size-text}" are treated as variantId = 0.
     */
    public static function cart_parse_key(string $rawKey): array
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
     * Check whether the cart_items table has a variant_id column.
     */
    public static function cart_items_supports_variant(mysqli $conn): bool
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

    public static function cart_items_supports_unit_type(mysqli $conn): bool
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

    public static function cart_save_to_db(mysqli $conn, int $customerId, array $cart, ?array $meterMap = null): void
    {
        try {
            if ($meterMap === null) {
                $meterMap = (isset($_SESSION['cart_meter_length']) && is_array($_SESSION['cart_meter_length']))
                    ? $_SESSION['cart_meter_length']
                    : [];
            }
            $cartId = CartService::cart_get_or_create_db_cart($conn, $customerId);
            $del = $conn->prepare("DELETE FROM cart_items WHERE cart_id = ?");
            $del->bind_param('i', $cartId);
            $del->execute();
            if (empty($cart)) {
                return;
            }
            $supportsMeterLength = CartService::cart_items_supports_meter_length($conn);
            $supportsKeyColumns  = CartService::cart_items_supports_key_columns($conn);
            $supportsVariant     = CartService::cart_items_supports_variant($conn);
            $supportsUnitType    = CartService::cart_items_supports_unit_type($conn);

            $productIds = [];
            $variantIds = [];
            foreach ($cart as $cartKey => $qty) {
                [$pid, $variantId] = CartService::cart_parse_key((string) $cartKey);
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
            $variantMap = !empty($variantIds) ? InventoryService::get_variants_by_ids($conn, $variantIds) : [];

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
                [$pid, $variantId] = CartService::cart_parse_key($rawKey);
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
            error_log('[app] cart_save_to_db failed: ' . $e->getMessage());
        }
    }

    /**
     * Load the saved cart from DB for a logged-in customer.
     * Returns an associative array [product_id => quantity].
     */
    public static function cart_load_from_db(mysqli $conn, int $customerId): array
    {
        $bundle = CartService::cart_load_from_db_bundle($conn, $customerId);
        return $bundle['cart'];
    }

    /**
     * Load the saved cart and meter metadata from DB for a logged-in customer.
     * Returns ['cart' => [product_id => quantity], 'meter_map' => [product_id => meter_length]].
     */
    public static function cart_load_from_db_bundle(mysqli $conn, int $customerId): array
    {
        try {
            $supportsMeterLength = CartService::cart_items_supports_meter_length($conn);
            $supportsKeyColumns  = CartService::cart_items_supports_key_columns($conn);
            $supportsVariant     = CartService::cart_items_supports_variant($conn);
            $supportsUnitType    = CartService::cart_items_supports_unit_type($conn);

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
            error_log('[app] cart_load_from_db failed: ' . $e->getMessage());
            return ['cart' => [], 'meter_map' => []];
        }
    }

    /**
     * Clear the customer's saved DB cart (called after order is placed).
     */
    public static function cart_clear_db(mysqli $conn, int $customerId): void
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
            error_log('[app] cart_clear_db failed: ' . $e->getMessage());
        }
    }

    public static function checkout_session_clear_after_order(mysqli $conn, int $customerId = 0): void
    {
        unset(
            $_SESSION['pending_order_id'],
            $_SESSION['pending_order_number'],
            $_SESSION['pending_coupon_id'],
            $_SESSION['pending_online_method'],
            $_SESSION['cart'],
            $_SESSION['cart_size'],
            $_SESSION['cart_meter_length'],
            $_SESSION['checkout_old'],
            $_SESSION['checkout_errors'],
            $_SESSION['applied_coupon_code']
        );

        if ($customerId > 0) {
            CartService::cart_clear_db($conn, $customerId);
        }
    }
}
