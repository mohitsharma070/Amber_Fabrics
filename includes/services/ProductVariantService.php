<?php

final class ProductVariantService
{
    public static function productContext(mysqli $conn, int $productId): ?array
    {
        if ($productId <= 0) return null;
        $stmt = $conn->prepare(
            "SELECT f.id, f.name, f.sku, f.product_type, f.unit_type, f.price, f.sale_price,
                    f.status, f.is_available, f.qty_step, f.min_order_meters,
                    (SELECT COUNT(*) FROM fabric_media fm WHERE fm.fabric_id=f.id AND fm.media_type='image') AS base_media_count,
                    COALESCE((SELECT fm.filename FROM fabric_media fm WHERE fm.fabric_id=f.id AND fm.media_type='image'
                              ORDER BY fm.is_primary DESC, fm.sort_order, fm.id LIMIT 1), '') AS base_image
             FROM fabrics f
             WHERE f.id=?
             LIMIT 1"
        );
        $stmt->bind_param('i', $productId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        if (!$row) return null;

        $regular = max(0.0, (float) ($row['price'] ?? 0));
        $sale = max(0.0, (float) ($row['sale_price'] ?? 0));
        $row['effective_price'] = ($sale > 0 && $sale < $regular) ? $sale : $regular;
        $row['base_media_count'] = (int) ($row['base_media_count'] ?? 0);
        return $row;
    }

    public static function suggestSku(array $product, string $color, string $size): string
    {
        $base = ProductAdminService::normalizeSku((string) ($product['sku'] ?? ''));
        if ($base === '') $base = 'P' . (int) ($product['id'] ?? 0);
        $parts = [];
        foreach ([$color, $size] as $value) {
            $part = ProductAdminService::normalizeSku($value);
            if ($part !== '') $parts[] = $part;
        }
        return substr($base . '-' . ($parts ? implode('-', $parts) : 'DEFAULT'), 0, 100);
    }

    public static function skuAvailable(mysqli $conn, string $sku, int $variantId = 0): bool
    {
        $stmt = $conn->prepare(
            "SELECT sku FROM fabrics WHERE sku=?
             UNION ALL
             SELECT sku FROM fabric_variants WHERE sku=? AND id<>?
             LIMIT 1"
        );
        $stmt->bind_param('ssi', $sku, $sku, $variantId);
        $stmt->execute();
        return !$stmt->get_result()->fetch_assoc();
    }

    public static function validate(mysqli $conn, array $product, array $input, int $variantId = 0): array
    {
        $errors = [];
        if (($product['product_type'] ?? 'simple') !== 'variable') {
            $errors['product_type'] = 'Enable variable inventory for this product before adding variants.';
        }

        $unit = in_array((string) ($product['unit_type'] ?? ''), ['meter','piece','set'], true)
            ? (string) $product['unit_type'] : 'piece';
        $color = trim((string) ($input['color'] ?? ''));
        $size = normalize_variant_size_text((string) ($input['size'] ?? ''));
        if (mb_strlen($color) > 100) $errors['color'] = 'Colour must be 100 characters or fewer.';

        $sizeMode = (string) (get_variant_size_policy_by_unit_type($unit)['mode'] ?? 'preset_with_custom');
        if ($sizeMode === 'hidden') {
            $size = '';
            if ($color === '') $errors['color'] = 'Colour is required for a meter-product variant.';
        } else {
            if ($size === '') $errors['size'] = 'Size is required for piece and set variants.';
            if (mb_strlen($size) > 100) $errors['size'] = 'Size must be 100 characters or fewer.';
            if (preg_match('/[,\/|]/', $size)) $errors['size'] = 'Enter one size only; create a separate variant for each size.';
            if ($unit === 'set' && preg_match('/^pack\s+of\s+\d+$/i', $size)) {
                $errors['size'] = 'Use Units per set for pack quantity; Size should contain the actual size.';
            }
        }

        $sku = ProductAdminService::normalizeSku((string) ($input['sku'] ?? ''));
        if ($sku === '') $sku = self::suggestSku($product, $color, $size);
        if (strlen($sku) < 3 || strlen($sku) > 100) $errors['sku'] = 'Variant SKU must contain 3 to 100 valid characters.';
        elseif (!self::skuAvailable($conn, $sku, $variantId)) $errors['sku'] = 'This SKU is already used by a product or variant.';

        $duplicate = $conn->prepare('SELECT id FROM fabric_variants WHERE fabric_id=? AND color=? AND size=? AND id<>? LIMIT 1');
        $productId = (int) ($product['id'] ?? 0);
        $duplicate->bind_param('issi', $productId, $color, $size, $variantId);
        $duplicate->execute();
        if ($duplicate->get_result()->fetch_assoc()) $errors['combination'] = 'This colour and size combination already exists.';

        $priceRaw = trim((string) ($input['price_override'] ?? ''));
        $priceOverride = null;
        if ($priceRaw !== '') {
            if (!is_numeric($priceRaw) || (float) $priceRaw <= 0) {
                $errors['price_override'] = 'Variant price must be greater than zero or left blank to inherit product pricing.';
            } else {
                $priceOverride = round((float) $priceRaw, 2);
                $mrp = (float) ($product['price'] ?? 0);
                if ($mrp > 0 && $priceOverride > $mrp) $errors['price_override'] = 'Variant selling price cannot exceed the product MRP.';
            }
        }

        $stock = 0.0;
        $stockMeters = 0.0;
        $stockRaw = $unit === 'meter' ? ($input['stock_meters'] ?? '') : ($input['stock'] ?? '');
        if ($stockRaw === '' || !is_numeric($stockRaw) || (float) $stockRaw < 0) {
            $errors['stock'] = 'Stock must be a non-negative number.';
        } elseif ($unit !== 'meter' && floor((float) $stockRaw) !== (float) $stockRaw) {
            $errors['stock'] = 'Piece and set stock must be a whole number.';
        } elseif ($unit === 'meter') {
            $stockMeters = round((float) $stockRaw, 2);
        } else {
            $stock = (float) (int) $stockRaw;
        }

        $unitsPerSet = null;
        $packLabel = '';
        if ($unit === 'set') {
            $unitsRaw = $input['units_per_set'] ?? '';
            if (!is_numeric($unitsRaw) || (float) $unitsRaw < 1 || floor((float) $unitsRaw) !== (float) $unitsRaw) {
                $errors['units_per_set'] = 'Units per set must be a whole number greater than zero.';
            } else {
                $unitsPerSet = normalize_units_per_set($unitsRaw);
                $packLabel = normalize_variant_size_text((string) ($input['pack_label'] ?? ''));
                if ($packLabel === '') $packLabel = format_pack_label($unitsPerSet);
                if (mb_strlen($packLabel) > 120) $errors['pack_label'] = 'Pack label must be 120 characters or fewer.';
            }
        }

        return [
            'errors' => $errors,
            'values' => [
                'color'=>$color, 'size'=>$size, 'sku'=>$sku, 'price_override'=>$priceOverride,
                'stock'=>$stock, 'stock_meters'=>$stockMeters, 'units_per_set'=>$unitsPerSet,
                'pack_label'=>$packLabel, 'is_active'=>(int) !empty($input['is_active']),
                'sort_order'=>max(0, (int) ($input['sort_order'] ?? 0)),
            ],
        ];
    }

    public static function enrich(array $variants, array $product): array
    {
        $unit = (string) ($product['unit_type'] ?? 'piece');
        foreach ($variants as &$variant) {
            $override = $variant['price_override'] !== null ? (float) $variant['price_override'] : null;
            $variant['effective_price'] = $override ?: (float) ($product['effective_price'] ?? 0);
            $variant['inherits_price'] = $override === null;
            $variant['inherits_media'] = trim((string) ($variant['image'] ?? '')) === ''
                && trim((string) ($variant['image2'] ?? '')) === ''
                && trim((string) ($variant['image3'] ?? '')) === ''
                && trim((string) ($variant['image4'] ?? '')) === '';
            $variant['effective_stock'] = $unit === 'meter'
                ? (float) ($variant['stock_meters'] ?? 0)
                : (float) ($variant['stock'] ?? 0);
        }
        unset($variant);
        return $variants;
    }

    public static function syncAvailability(mysqli $conn, int $productId): void
    {
        $product = self::productContext($conn, $productId);
        if (!$product || ($product['product_type'] ?? '') !== 'variable') return;
        $stockColumn = ($product['unit_type'] ?? '') === 'meter' ? 'stock_meters' : 'stock';
        $stmt = $conn->prepare("SELECT COUNT(*) AS total FROM fabric_variants WHERE fabric_id=? AND is_active=1 AND {$stockColumn}>0");
        $stmt->bind_param('i', $productId);
        $stmt->execute();
        $sellable = (int) ($stmt->get_result()->fetch_assoc()['total'] ?? 0) > 0;
        $available = (($product['status'] ?? '') === 'active' && $sellable) ? 1 : 0;
        $update = $conn->prepare('UPDATE fabrics SET is_available=? WHERE id=?');
        $update->bind_param('ii', $available, $productId);
        $update->execute();
    }
}
