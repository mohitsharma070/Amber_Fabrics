<?php

final class CartService
{
    /**
     * Return the greatest quantity that can actually be sold from the supplied stock.
     *
     * This is deliberately separate from customer-request normalization: a stock
     * ceiling must never be raised to the configured minimum quantity.
     */
    public static function maximumSellableQuantity(
        float $availableStock,
        string $unitType,
        float $minimumQuantity = 1.0,
        float $quantityStep = 0.0,
        ?float $meterLength = null
    ): float {
        $unitType = in_array($unitType, ['meter', 'piece', 'set'], true) ? $unitType : 'meter';
        if ($unitType === 'piece' || $unitType === 'set') {
            $minimumUnits = max(1, (int) round($minimumQuantity));
            $availableUnits = max(0, (int) floor($availableStock + 0.0000001));
            return $availableUnits >= $minimumUnits ? (float) $availableUnits : 0.0;
        }

        // Legacy four-decimal steps cannot safely produce new two-decimal cart,
        // stock, or order quantities. Keep historical rows unchanged and fail
        // closed for new cart reconciliation until an administrator corrects it.
        if ($quantityStep != 0.0 && !meter_qty_step_is_representable($quantityStep)) {
            return 0.0;
        }

        $stockCents = max(0, (int) floor(($availableStock * 100) + 0.0000001));
        $stockUnits = $stockCents * 100;
        $minimumUnits = (int) round(normalize_meter_quantity($minimumQuantity, 1.0) * 10000);
        if ($stockUnits < $minimumUnits) {
            return 0.0;
        }

        $quantityIncrement = 100;
        if ($meterLength !== null) {
            $normalizedMeterLength = round($meterLength, 2);
            if ($normalizedMeterLength <= 0) {
                return 0.0;
            }
            $quantityIncrement = (int) round($normalizedMeterLength * 10000);
        }

        $maximumMultiplier = intdiv($stockUnits, $quantityIncrement);
        $minimumMultiplier = max(1, (int) ceil($minimumUnits / $quantityIncrement));
        if ($maximumMultiplier < $minimumMultiplier) {
            return 0.0;
        }

        $stepUnits = (int) round($quantityStep * 10000);
        if ($stepUnits <= 0) {
            $candidateUnits = $maximumMultiplier * $quantityIncrement;
            return $candidateUnits >= $minimumUnits ? round($candidateUnits / 10000, 2) : 0.0;
        }

        // Solve increment * bundle_count = minimum (mod step) so the result
        // satisfies both the selected bundle grid and the configured step grid.
        $divisor = self::greatestCommonDivisor($quantityIncrement, $stepUnits);
        if ($minimumUnits % $divisor !== 0) {
            return 0.0;
        }

        $reducedModulus = intdiv($stepUnits, $divisor);
        if ($reducedModulus === 1) {
            $firstMultiplier = 0;
        } else {
            $reducedIncrement = intdiv($quantityIncrement, $divisor) % $reducedModulus;
            $reducedMinimum = intdiv($minimumUnits, $divisor) % $reducedModulus;
            $inverse = self::modularInverse($reducedIncrement, $reducedModulus);
            if ($inverse === null) {
                return 0.0;
            }
            $firstMultiplier = self::multiplyModulo($reducedMinimum, $inverse, $reducedModulus);
        }

        if ($firstMultiplier > $maximumMultiplier) {
            return 0.0;
        }
        $periods = intdiv($maximumMultiplier - $firstMultiplier, $reducedModulus);
        $sellableMultiplier = $firstMultiplier + ($periods * $reducedModulus);
        if ($sellableMultiplier < $minimumMultiplier) {
            return 0.0;
        }

        return round(($sellableMultiplier * $quantityIncrement) / 10000, 2);
    }

    /**
     * Normalize a stored/requested quantity, then cap it to the real sellable ceiling.
     * A zero result means the line cannot currently satisfy all cart invariants.
     */
    public static function reconcileSellableQuantity(
        $requestedQuantity,
        float $availableStock,
        string $unitType,
        float $minimumQuantity = 1.0,
        float $quantityStep = 0.0,
        ?float $meterLength = null
    ): float {
        $normalizedQuantity = (float) normalize_quantity_by_unit(
            $requestedQuantity,
            $unitType,
            $minimumQuantity
        );
        return CartService::maximumSellableQuantity(
            min($availableStock, $normalizedQuantity),
            $unitType,
            $minimumQuantity,
            $quantityStep,
            $meterLength
        );
    }

    private static function greatestCommonDivisor(int $left, int $right): int
    {
        $left = abs($left);
        $right = abs($right);
        while ($right !== 0) {
            $remainder = $left % $right;
            $left = $right;
            $right = $remainder;
        }
        return max(1, $left);
    }

    private static function modularInverse(int $value, int $modulus): ?int
    {
        $originalModulus = $modulus;
        $coefficient = 0;
        $nextCoefficient = 1;
        $remainder = $modulus;
        $nextRemainder = $value % $modulus;

        while ($nextRemainder !== 0) {
            $quotient = intdiv($remainder, $nextRemainder);
            [$coefficient, $nextCoefficient] = [
                $nextCoefficient,
                $coefficient - ($quotient * $nextCoefficient),
            ];
            [$remainder, $nextRemainder] = [
                $nextRemainder,
                $remainder - ($quotient * $nextRemainder),
            ];
        }

        if ($remainder !== 1) {
            return null;
        }
        return (($coefficient % $originalModulus) + $originalModulus) % $originalModulus;
    }

    private static function multiplyModulo(int $left, int $right, int $modulus): int
    {
        $result = 0;
        $left %= $modulus;
        while ($right > 0) {
            if (($right & 1) === 1) {
                $result = ($result + $left) % $modulus;
            }
            $right = intdiv($right, 2);
            $left = ($left * 2) % $modulus;
        }
        return $result;
    }

    /**
     * Build normalized cart/wishlist line items from session cart maps.
     *
     * Returns:
     * - items: hydrated cart lines
     * - removed_keys: cart keys rejected due to missing/inactive products/variants
     * - invalid_variant_found: whether any variant mismatch was detected
     * - quantity_updates: normalized/capped quantities that callers must persist
     */
    public static function cart_hydrate_items(
        mysqli $conn,
        array $source,
        array $sizeMap = [],
        array $meterMap = [],
        bool $enforceSellableQuantity = true
    ): array
    {
        if (empty($source)) {
            return ['items' => [], 'removed_keys' => [], 'invalid_variant_found' => false, 'quantity_updates' => []];
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
            return ['items' => [], 'removed_keys' => array_keys($source), 'invalid_variant_found' => false, 'quantity_updates' => []];
        }

        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $types = str_repeat('i', count($ids));
        $sql = "SELECT f.id, f.name, f.slug, COALESCE((SELECT fm.filename FROM fabric_media fm WHERE fm.fabric_id=f.id AND fm.media_type='image' ORDER BY fm.is_primary DESC, fm.sort_order, fm.id LIMIT 1), '') AS image, f.product_type, f.unit_type, f.meter_options, f.min_order_meters, f.qty_step, f.price, f.sale_price, f.stock, f.stock_meters, f.is_available,
                       shipping_weight_kg, parcel_length_cm, parcel_width_cm, parcel_height_cm, gst_rate, hsn_code
                FROM fabrics f
                WHERE f.status = 'active' AND f.id IN ($placeholders)";
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
        $quantityUpdates = [];

        foreach ($source as $cartKey => $sourceQty) {
            [$pid, $variantId] = CartService::cart_parse_key((string) $cartKey);
            if ($pid <= 0 || !isset($rowMap[$pid])) {
                $removedKeys[] = (string) $cartKey;
                continue;
            }

            $row = $rowMap[$pid];
            $variant = ($variantId > 0 && isset($variantMap[$variantId])) ? $variantMap[$variantId] : null;
            $requiresVariant = (($row['product_type'] ?? 'simple') === 'variable');
            if (($requiresVariant && $variantId <= 0) || (!$requiresVariant && $variantId > 0)) {
                $removedKeys[] = (string) $cartKey;
                $invalidVariantFound = true;
                continue;
            }
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
                : (float) max(1, (int) round((float) ($row['min_order_meters'] ?? 1)));
            $qty = normalize_quantity_by_unit($sourceQty ?? 1, $unitType, (float) $minQty);
            if ($unitType === 'meter') {
                $qtyStep = is_numeric($row['qty_step'] ?? null) ? (float) $row['qty_step'] : 0.0;
                if (
                    !$enforceSellableQuantity
                    && !meter_qty_respects_step((float) $qty, (float) $minQty, (float) $qtyStep)
                ) {
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
                if (
                    !$enforceSellableQuantity
                    && ($bundleRatio <= 0 || abs($bundleRatio - round($bundleRatio)) > 0.0001)
                ) {
                    $removedKeys[] = (string) $cartKey;
                    continue;
                }
                $bundleQty = max(1, (int) round($bundleRatio));
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
            $maximumSellableQuantity = CartService::maximumSellableQuantity(
                $displayStock,
                $unitType,
                (float) $minQty,
                $unitType === 'meter' ? $qtyStep : 0.0,
                $meterLength
            );
            if ($enforceSellableQuantity) {
                $reconciledQuantity = CartService::reconcileSellableQuantity(
                    $qty,
                    $displayStock,
                    $unitType,
                    (float) $minQty,
                    $unitType === 'meter' ? $qtyStep : 0.0,
                    $meterLength
                );
                if (empty($row['is_available']) || $reconciledQuantity <= 0) {
                    $removedKeys[] = (string) $cartKey;
                    continue;
                }
                $storedQuantity = is_numeric($sourceQty) ? (float) $sourceQty : 0.0;
                if (abs($reconciledQuantity - $storedQuantity) > 0.0001) {
                    $quantityUpdates[(string) $cartKey] = $reconciledQuantity;
                }
                $qty = $reconciledQuantity;
                if ($unitType === 'meter' && $meterLength !== null && $meterLength > 0) {
                    $bundleQty = max(1, (int) round($qty / $meterLength));
                }
            }

            $regular = (float) ($row['price'] ?? 0);
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

            $inStock = !empty($row['is_available']) && $maximumSellableQuantity > 0;
            $maxBundleQty = null;
            if ($unitType === 'meter' && $meterLength !== null && $meterLength > 0 && $maximumSellableQuantity > 0) {
                $maxBundleQty = (int) floor(($maximumSellableQuantity / $meterLength) + 0.0001);
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
                'slug' => (string) ($row['slug'] ?? ''),
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
                'maximum_sellable_quantity' => $maximumSellableQuantity,
                'shipping_weight_kg' => $row['shipping_weight_kg'] ?? null,
                'parcel_length_cm' => $row['parcel_length_cm'] ?? null,
                'parcel_width_cm' => $row['parcel_width_cm'] ?? null,
                'parcel_height_cm' => $row['parcel_height_cm'] ?? null,
                'gst_rate' => $row['gst_rate'] ?? null,
                'hsn_code' => $row['hsn_code'] ?? null,
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
            'quantity_updates' => $quantityUpdates,
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
     * Check whether a cart mutation preserves the selected meter length for an
     * existing product/variant line. Existing meter lines fail closed when
     * either the stored or requested length is missing or invalid.
     */
    public static function cartLineAllowsMeterLength(
        array $cart,
        array $meterMap,
        string $cartKey,
        string $unitType,
        $requestedMeterLength
    ): bool {
        if ($unitType !== 'meter' || !array_key_exists($cartKey, $cart)) {
            return true;
        }
        if (
            !array_key_exists($cartKey, $meterMap)
            || !is_numeric($meterMap[$cartKey])
            || (float) $meterMap[$cartKey] <= 0
            || !is_numeric($requestedMeterLength)
            || (float) $requestedMeterLength <= 0
        ) {
            return false;
        }

        $existingLength = round((float) $meterMap[$cartKey], 2);
        $requestedLength = round((float) $requestedMeterLength, 2);
        return abs($existingLength - $requestedLength) <= 0.001;
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
     * variantId = 0 identifies a simple product.
     * Non-numeric suffixes are normalized to the simple-product form.
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
     * Resolve the immutable identity of an existing cart line for an UPDATE request.
     * Canonical keys are preferred, while exact legacy simple-product keys remain
     * updateable for backward compatibility. No resolution path may create a line.
     *
     * @return array{cart_key: string, product_id: int, variant_id: int}|null
     */
    public static function resolveExistingUpdateIdentity(
        array $cart,
        string $rawCartKey,
        mixed $postedProductId = null,
        mixed $postedVariantId = null
    ): ?array {
        $normalizeIdentity = static function (mixed $value, bool $allowZero): ?int {
            if (!is_int($value) && !is_string($value)) {
                return null;
            }
            $rawValue = trim((string) $value);
            if ($rawValue === '' || !ctype_digit($rawValue)) {
                return null;
            }
            $normalized = (int) $rawValue;
            if ((!$allowZero && $normalized <= 0) || ($allowZero && $normalized < 0)) {
                return null;
            }
            return (string) $normalized === $rawValue ? $normalized : null;
        };

        $cartKey = trim($rawCartKey);
        if ($cartKey === '') {
            $fallbackProductId = $normalizeIdentity($postedProductId, false);
            $fallbackVariantId = ($postedVariantId === null || $postedVariantId === '')
                ? 0
                : $normalizeIdentity($postedVariantId, true);
            if ($fallbackProductId === null || $fallbackVariantId === null) {
                return null;
            }
            $cartKey = $fallbackProductId . '::' . $fallbackVariantId;
        }
        if (!array_key_exists($cartKey, $cart)) {
            return null;
        }

        $productId = 0;
        $variantId = 0;
        if (preg_match('/\A([1-9][0-9]*)::(0|[1-9][0-9]*)\z/D', $cartKey, $matches)) {
            $productId = (int) $matches[1];
            $variantId = (int) $matches[2];
        } elseif (preg_match('/\A([1-9][0-9]*)\z/D', $cartKey, $matches)) {
            // Historical simple-product carts used the bare product ID as the key.
            $productId = (int) $matches[1];
        } elseif (preg_match('/\A([1-9][0-9]*)::(.+)\z/D', $cartKey, $matches)) {
            // Historical simple-product carts stored the selected size in the suffix.
            $legacySuffix = trim((string) $matches[2]);
            if ($legacySuffix === '' || ctype_digit($legacySuffix)) {
                return null;
            }
            $productId = (int) $matches[1];
        } else {
            return null;
        }

        $postedProduct = ($postedProductId === null || $postedProductId === '')
            ? $productId
            : $normalizeIdentity($postedProductId, false);
        $postedVariant = ($postedVariantId === null || $postedVariantId === '')
            ? $variantId
            : $normalizeIdentity($postedVariantId, true);

        if (
            $postedProduct === null
            || $postedVariant === null
            || $postedProduct !== $productId
            || $postedVariant !== $variantId
        ) {
            return null;
        }

        return [
            'cart_key' => $cartKey,
            'product_id' => $productId,
            'variant_id' => $variantId,
        ];
    }

    /**
     * Enforce the same product/variant identity rules used by hydration and checkout.
     */
    public static function isValidUpdateSelection(
        int $productId,
        int $variantId,
        ?array $product,
        ?array $variant
    ): bool {
        if (
            !$product
            || (int) ($product['id'] ?? 0) !== $productId
            || (string) ($product['status'] ?? '') !== 'active'
            || empty($product['is_available'])
        ) {
            return false;
        }

        $requiresVariant = (($product['product_type'] ?? 'simple') === 'variable');
        if (($requiresVariant && $variantId <= 0) || (!$requiresVariant && $variantId > 0)) {
            return false;
        }
        if (!$requiresVariant) {
            return true;
        }

        return $variant !== null
            && (int) ($variant['id'] ?? 0) === $variantId
            && (int) ($variant['fabric_id'] ?? 0) === $productId
            && (int) ($variant['is_active'] ?? 0) === 1;
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

    public static function cart_save_to_db(mysqli $conn, int $customerId, array $cart, ?array $meterMap = null, ?array $sizeMap = null): void
    {
        try {
            $conn->begin_transaction();
            if ($meterMap === null) {
                $meterMap = (isset($_SESSION['cart_meter_length']) && is_array($_SESSION['cart_meter_length']))
                    ? $_SESSION['cart_meter_length']
                    : [];
            }
            if ($sizeMap === null) {
                $sizeMap = (isset($_SESSION['cart_size']) && is_array($_SESSION['cart_size']))
                    ? $_SESSION['cart_size']
                    : [];
            }
            $cartId = CartService::cart_get_or_create_db_cart($conn, $customerId);
            $del = $conn->prepare("DELETE FROM cart_items WHERE cart_id = ?");
            $del->bind_param('i', $cartId);
            $del->execute();
            if (empty($cart)) {
                $conn->commit();
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
                foreach (ProductReadService::unitTypeRows($conn, $productIds) as $ur) {
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
                // Simple products preserve the selected base-product size.
                $selectedSize = '';
                if ($variantId <= 0) {
                    $selectedSize = trim((string) ($sizeMap[$rawKey] ?? ''));
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
            $conn->commit();
        } catch (Throwable $e) {
            try { $conn->rollback(); } catch (Throwable $ignored) {}
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
     * Returns ['cart' => [product_id => quantity], 'meter_map' => [product_id => meter_length], 'size_map' => [cart_key => selected_size]].
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
            $sizeMap  = [];
            foreach ($rows as $row) {
                if ((int) $row['product_id'] > 0) {
                    $pid       = (int) $row['product_id'];
                    $variantId = (int) ($row['variant_id'] ?? 0);
                    $cartKey   = trim((string) ($row['cart_key'] ?? ''));
                    if ($cartKey === '') {
                        // Reconstruct the canonical product/variant key.
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
                    if ($supportsKeyColumns && isset($row['selected_size'])) {
                        $sizeVal = trim((string) $row['selected_size']);
                        if ($sizeVal !== '') {
                            $sizeMap[$cartKey] = $sizeVal;
                        }
                    }
                }
            }
            return ['cart' => $cart, 'meter_map' => $meterMap, 'size_map' => $sizeMap];
        } catch (Throwable $e) {
            error_log('[app] cart_load_from_db failed: ' . $e->getMessage());
            return ['cart' => [], 'meter_map' => [], 'size_map' => []];
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
