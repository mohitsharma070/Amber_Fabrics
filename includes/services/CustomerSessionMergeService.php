<?php

final class CustomerSessionMergeService
{
    public static function mergeOnLogin(mysqli $conn, int $customerId): void
    {
        if ($customerId <= 0) {
            return;
        }

        self::mergeCart($conn, $customerId);
        self::mergeWishlist($conn, $customerId);
    }

    private static function mergeCart(mysqli $conn, int $customerId): void
    {
        $dbCartBundle = CartService::cart_load_from_db_bundle($conn, $customerId);
        $dbCart = is_array($dbCartBundle['cart'] ?? null) ? $dbCartBundle['cart'] : [];
        $dbMeterMap = is_array($dbCartBundle['meter_map'] ?? null) ? $dbCartBundle['meter_map'] : [];
        $sessionCart = isset($_SESSION['cart']) && is_array($_SESSION['cart']) ? $_SESSION['cart'] : [];
        $sessionMeterMap = isset($_SESSION['cart_meter_length']) && is_array($_SESSION['cart_meter_length'])
            ? $_SESSION['cart_meter_length']
            : [];

        // Whose cart is sitting in $_SESSION? customer_clear_auth_session() deliberately
        // preserves the cart across an involuntary session kill (idle timeout, account
        // deactivation, auth_version bump - see includes/init.php), so on the next login
        // the session still holds this customer's own already-persisted cart. Summing it
        // into the DB copy would silently double every line, and compound on each repeat.
        // Mirrors the existing wishlist_loaded_for provenance marker.
        $sessionCartOwner = (int) ($_SESSION['cart_loaded_for'] ?? 0);
        if ($sessionCartOwner > 0 && $sessionCartOwner !== $customerId) {
            // A different customer's persisted cart is still in this session. It is not a
            // guest cart to hand over, so drop it rather than leak it into this account.
            $sessionCart = [];
            $sessionMeterMap = [];
        }
        // Only a genuine guest cart is additive; a returning owner's copy takes the larger
        // side, exactly as mergeWishlist() already does.
        $sumQuantities = $sessionCartOwner !== $customerId;

        $mergedIds = array_values(array_filter(array_unique(array_map(
            static function ($key): int {
                [$productId] = CartService::cart_parse_key((string) $key);
                return $productId;
            },
            array_merge(array_keys($dbCart), array_keys($sessionCart))
        )), static fn($value): bool => $value > 0));
        $unitMap = self::productUnitMap($conn, $mergedIds);

        foreach ($sessionCart as $cartKey => $qty) {
            [$productId] = CartService::cart_parse_key((string) $cartKey);
            if ($productId <= 0) {
                continue;
            }
            $unitType = $unitMap[$productId] ?? 'meter';
            $currentQty = isset($dbCart[$cartKey]) ? normalize_quantity_by_unit($dbCart[$cartKey], $unitType) : 0;
            $incomingQty = normalize_quantity_by_unit($qty, $unitType);
            $combinedQty = $sumQuantities
                ? (float) $currentQty + (float) $incomingQty
                : max((float) $currentQty, (float) $incomingQty);
            $dbCart[$cartKey] = $unitType === 'meter'
                ? round($combinedQty, 2)
                : (int) $combinedQty;
            if (
                $unitType === 'meter'
                && isset($sessionMeterMap[$cartKey])
                && is_numeric($sessionMeterMap[$cartKey])
                && (float) $sessionMeterMap[$cartKey] > 0
            ) {
                $dbMeterMap[$cartKey] = round((float) $sessionMeterMap[$cartKey], 2);
            }
        }

        self::capCartToAvailableStock($conn, $dbCart, $dbMeterMap);

        $_SESSION['cart'] = $dbCart;
        $_SESSION['cart_meter_length'] = $dbMeterMap;
        $_SESSION['cart_loaded_for'] = $customerId;
        if ($dbCart !== []) {
            CartService::cart_save_to_db($conn, $customerId, $dbCart, $dbMeterMap);
        }
    }

    private static function mergeWishlist(mysqli $conn, int $customerId): void
    {
        $dbBundle = wishlist_load_from_db_bundle($conn, $customerId);
        $dbWishlist = is_array($dbBundle['wishlist'] ?? null) ? $dbBundle['wishlist'] : [];
        $dbSizeMap = is_array($dbBundle['size_map'] ?? null) ? $dbBundle['size_map'] : [];
        $dbMeterMap = is_array($dbBundle['meter_map'] ?? null) ? $dbBundle['meter_map'] : [];
        $sessionWishlist = isset($_SESSION['wishlist']) && is_array($_SESSION['wishlist']) ? $_SESSION['wishlist'] : [];
        $sessionSizeMap = isset($_SESSION['wishlist_size']) && is_array($_SESSION['wishlist_size']) ? $_SESSION['wishlist_size'] : [];
        $sessionMeterMap = isset($_SESSION['wishlist_meter_length']) && is_array($_SESSION['wishlist_meter_length'])
            ? $_SESSION['wishlist_meter_length']
            : [];

        foreach ($sessionWishlist as $wishlistKey => $wishlistQty) {
            [$productId] = CartService::cart_parse_key((string) $wishlistKey);
            if ($productId <= 0) {
                continue;
            }
            $existingQty = isset($dbWishlist[$wishlistKey]) ? normalize_meter_quantity($dbWishlist[$wishlistKey], 1.0) : 0.0;
            $incomingQty = normalize_meter_quantity($wishlistQty, 1.0);
            $dbWishlist[$wishlistKey] = max($existingQty, $incomingQty);
            if (isset($sessionSizeMap[$wishlistKey])) {
                $dbSizeMap[$wishlistKey] = (string) $sessionSizeMap[$wishlistKey];
            }
            if (
                isset($sessionMeterMap[$wishlistKey])
                && is_numeric($sessionMeterMap[$wishlistKey])
                && (float) $sessionMeterMap[$wishlistKey] > 0
            ) {
                $dbMeterMap[$wishlistKey] = round((float) $sessionMeterMap[$wishlistKey], 2);
            }
        }

        $_SESSION['wishlist'] = $dbWishlist;
        $_SESSION['wishlist_size'] = $dbSizeMap;
        $_SESSION['wishlist_meter_length'] = $dbMeterMap;
        $_SESSION['wishlist_loaded_for'] = $customerId;
        wishlist_save_to_db($conn, $customerId, $dbWishlist, $dbMeterMap, $dbSizeMap);
    }

    private static function productUnitMap(mysqli $conn, array $productIds): array
    {
        if ($productIds === []) {
            return [];
        }
        $map = [];
        foreach (ProductReadService::unitTypeRows($conn, $productIds) as $row) {
            $productId = (int) ($row['id'] ?? 0);
            if ($productId <= 0) {
                continue;
            }
            $map[$productId] = in_array((string) ($row['unit_type'] ?? ''), ['meter', 'piece', 'set'], true)
                ? (string) $row['unit_type']
                : 'meter';
        }
        return $map;
    }

    private static function capCartToAvailableStock(mysqli $conn, array &$cart, array &$meterMap): void
    {
        if ($cart === []) {
            return;
        }

        $productIds = [];
        $variantIds = [];
        foreach (array_keys($cart) as $key) {
            [$productId, $variantId] = CartService::cart_parse_key((string) $key);
            if ($productId > 0) {
                $productIds[] = $productId;
            }
            if ($variantId > 0) {
                $variantIds[] = $variantId;
            }
        }
        $productIds = array_values(array_unique($productIds));
        $variantIds = array_values(array_unique($variantIds));
        if ($productIds === []) {
            $cart = [];
            $meterMap = [];
            return;
        }

        $placeholders = implode(',', array_fill(0, count($productIds), '?'));
        $stmt = $conn->prepare("SELECT id, unit_type, stock, stock_meters FROM fabrics WHERE id IN ($placeholders)");
        $stmt->bind_param(str_repeat('i', count($productIds)), ...$productIds);
        $stmt->execute();
        $stockMap = [];
        foreach ($stmt->get_result()->fetch_all(MYSQLI_ASSOC) as $row) {
            $productId = (int) ($row['id'] ?? 0);
            if ($productId <= 0) {
                continue;
            }
            $stockMap[$productId] = [
                'unit_type' => in_array((string) ($row['unit_type'] ?? ''), ['meter', 'piece', 'set'], true)
                    ? (string) $row['unit_type']
                    : 'meter',
                'stock' => (float) ($row['stock'] ?? 0),
                'stock_meters' => (float) ($row['stock_meters'] ?? 0),
            ];
        }
        $variantMap = $variantIds !== [] ? InventoryService::get_variants_by_ids($conn, $variantIds) : [];

        foreach ($cart as $key => $quantity) {
            [$productId, $variantId] = CartService::cart_parse_key((string) $key);
            if ($productId <= 0 || !isset($stockMap[$productId])) {
                unset($cart[$key], $meterMap[$key]);
                continue;
            }
            $unitType = (string) $stockMap[$productId]['unit_type'];
            $available = $unitType === 'meter'
                ? (float) $stockMap[$productId]['stock_meters']
                : (float) $stockMap[$productId]['stock'];
            if ($variantId > 0) {
                $variant = $variantMap[$variantId] ?? null;
                if (!$variant || (int) ($variant['fabric_id'] ?? 0) !== $productId || (int) ($variant['is_active'] ?? 0) !== 1) {
                    unset($cart[$key], $meterMap[$key]);
                    continue;
                }
                $available = $unitType === 'meter'
                    ? (float) ($variant['stock_meters'] ?? 0)
                    : (float) ($variant['stock'] ?? 0);
            }
            if ($available <= 0) {
                unset($cart[$key], $meterMap[$key]);
                continue;
            }
            if ((float) $quantity > $available) {
                $cart[$key] = $unitType === 'meter' ? round($available, 2) : (int) floor($available);
            }
        }
    }
}
