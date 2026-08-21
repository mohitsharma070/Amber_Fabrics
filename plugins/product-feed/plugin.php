<?php

add_action('app.init', 'product_feed_maybe_serve', 15);
function_exists('add_cron_action') ? add_cron_action('product_feed_refresh_files', 30, false) : add_action('cron.tick', 'product_feed_refresh_files', 30);

function product_feed_settings(): array
{
    return [
        'enabled' => (int) plugin_setting('product-feed', 'enabled', 1) === 1,
        'base_path' => trim((string) plugin_setting('product-feed', 'base_path', '/feeds')),
        'xml_file' => trim((string) plugin_setting('product-feed', 'xml_file', 'products.xml')),
        'json_file' => trim((string) plugin_setting('product-feed', 'json_file', 'products.json')),
    ];
}

function product_feed_enabled(): bool
{
    $settings = product_feed_settings();
    return $settings['enabled'];
}

function product_feed_public_base_path(): string
{
    $base = product_feed_settings()['base_path'];
    $base = trim($base);
    if ($base === '' || $base[0] !== '/') {
        return '/feeds';
    }
    $parts = explode('/', trim($base, '/'));
    $safeParts = [];
    foreach ($parts as $part) {
        $part = trim($part);
        if ($part === '' || $part === '.' || $part === '..') {
            continue;
        }
        $part = preg_replace('/[^A-Za-z0-9._-]/', '', $part) ?? '';
        if ($part !== '') {
            $safeParts[] = $part;
        }
    }
    if (empty($safeParts)) {
        return '/feeds';
    }
    return '/' . implode('/', $safeParts);
}

function product_feed_filesystem_dir(): string
{
    $base = product_feed_public_base_path();
    $relative = ltrim($base, '/');
    return dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . $relative;
}

function product_feed_fetch_rows(mysqli $conn): array
{
    $stmt = $conn->prepare(
        "SELECT CONCAT('p-', f.id) offer_id, f.id product_id, NULL variant_id,
                f.name, f.sku, f.slug, f.category, f.description, f.price, f.sale_price,
                NULL price_override, f.stock, f.stock_meters, f.unit_type, '' color, '' size,
                f.status, f.is_available, f.created_at,
                COALESCE((SELECT fm.filename FROM fabric_media fm WHERE fm.fabric_id=f.id AND fm.media_type='image' ORDER BY fm.is_primary DESC, fm.sort_order, fm.id LIMIT 1), '') image
         FROM fabrics f
         WHERE f.status='active' AND f.is_available=1 AND f.product_type='simple'
         UNION ALL
         SELECT CONCAT('p-', f.id, '-v-', fv.id) offer_id, f.id product_id, fv.id variant_id,
                f.name, COALESCE(NULLIF(fv.sku,''), f.sku), f.slug, f.category, f.description,
                f.price, f.sale_price, fv.price_override, fv.stock, fv.stock_meters, f.unit_type,
                fv.color, fv.size, f.status, f.is_available, f.created_at,
                COALESCE(NULLIF(fv.image,''), (SELECT fm.filename FROM fabric_media fm WHERE fm.fabric_id=f.id AND fm.media_type='image' ORDER BY fm.is_primary DESC, fm.sort_order, fm.id LIMIT 1), '') image
         FROM fabrics f JOIN fabric_variants fv ON fv.fabric_id=f.id AND fv.is_active=1
         WHERE f.status='active' AND f.is_available=1 AND f.product_type='variable'
         ORDER BY product_id DESC, variant_id ASC"
    );
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    return is_array($rows) ? $rows : [];
}

function product_feed_product_url(int $productId, string $slug = ''): string
{
    return SiteContext::url($slug !== '' ? '/fabric/' . rawurlencode($slug) : '/fabric.php?id=' . $productId);
}

function product_feed_image_url(string $image): string
{
    $image = ltrim(trim($image), '/');
    if ($image === '') {
        return '';
    }
    return SiteContext::url('/images/fabrics/' . $image);
}

function product_feed_normalize_row(array $row): array
{
    $productId = (int) ($row['product_id'] ?? 0);
    $variantId = (int) ($row['variant_id'] ?? 0);
    $price = (float) ($row['price'] ?? 0);
    $sale = (float) ($row['sale_price'] ?? 0);
    $finalPrice = ($sale > 0 && $sale < $price) ? $sale : $price;
    $override = (float) ($row['price_override'] ?? 0);
    if ($override > 0) $finalPrice = $override;
    $unitType = in_array((string) ($row['unit_type'] ?? ''), ['meter', 'piece', 'set'], true) ? (string) $row['unit_type'] : 'meter';
    $stock = ($unitType === 'meter') ? (float) ($row['stock_meters'] ?? 0) : (float) ($row['stock'] ?? 0);
    $color = trim((string) ($row['color'] ?? ''));
    $size = trim((string) ($row['size'] ?? ''));
    $variantLabel = trim(implode(' / ', array_filter([$color, $size], static fn(string $value): bool => $value !== '')));

    return [
        'id' => trim((string) ($row['offer_id'] ?? ('p-' . $productId))),
        'product_id' => $productId,
        'variant_id' => $variantId > 0 ? $variantId : null,
        'item_group_id' => 'p-' . $productId,
        'title' => trim((string) ($row['name'] ?? '')) . ($variantLabel !== '' ? ' - ' . $variantLabel : ''),
        'sku' => trim((string) ($row['sku'] ?? '')),
        'color' => $color,
        'size' => $size,
        'description' => trim((string) ($row['description'] ?? '')),
        'category' => trim((string) ($row['category'] ?? '')),
        'link' => product_feed_product_url($productId, trim((string)($row['slug'] ?? ''))),
        'image_link' => product_feed_image_url((string) ($row['image'] ?? '')),
        'availability' => $stock > 0 ? 'in stock' : 'out of stock',
        'price_inr' => round(max(0, $finalPrice), 2),
        'sale_price_inr' => $override <= 0 && $sale > 0 && $sale < $price ? round(max(0, $sale), 2) : null,
        'unit_type' => $unitType,
        'stock' => $stock,
        'currency' => 'INR',
        'created_at' => (string) ($row['created_at'] ?? ''),
    ];
}

function product_feed_build_json(array $products): string
{
    return (string) json_encode([
        'generated_at' => date('c'),
        'count' => count($products),
        'products' => array_values($products),
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
}

function product_feed_xml_escape(string $value): string
{
    return htmlspecialchars($value, ENT_XML1 | ENT_COMPAT, 'UTF-8');
}

function product_feed_build_xml(array $products): string
{
    $xml = [];
    $xml[] = '<?xml version="1.0" encoding="UTF-8"?>';
    $xml[] = '<products generated_at="' . product_feed_xml_escape(date('c')) . '" count="' . count($products) . '">';
    foreach ($products as $product) {
        $xml[] = '  <product>';
        $xml[] = '    <id>' . product_feed_xml_escape((string) ($product['id'] ?? '')) . '</id>';
        $xml[] = '    <product_id>' . (int) ($product['product_id'] ?? 0) . '</product_id>';
        if (!empty($product['variant_id'])) $xml[] = '    <variant_id>' . (int) $product['variant_id'] . '</variant_id>';
        $xml[] = '    <item_group_id>' . product_feed_xml_escape((string) ($product['item_group_id'] ?? '')) . '</item_group_id>';
        $xml[] = '    <title>' . product_feed_xml_escape((string) ($product['title'] ?? '')) . '</title>';
        $xml[] = '    <sku>' . product_feed_xml_escape((string) ($product['sku'] ?? '')) . '</sku>';
        $xml[] = '    <color>' . product_feed_xml_escape((string) ($product['color'] ?? '')) . '</color>';
        $xml[] = '    <size>' . product_feed_xml_escape((string) ($product['size'] ?? '')) . '</size>';
        $xml[] = '    <description>' . product_feed_xml_escape((string) ($product['description'] ?? '')) . '</description>';
        $xml[] = '    <category>' . product_feed_xml_escape((string) ($product['category'] ?? '')) . '</category>';
        $xml[] = '    <link>' . product_feed_xml_escape((string) ($product['link'] ?? '')) . '</link>';
        $xml[] = '    <image_link>' . product_feed_xml_escape((string) ($product['image_link'] ?? '')) . '</image_link>';
        $xml[] = '    <availability>' . product_feed_xml_escape((string) ($product['availability'] ?? '')) . '</availability>';
        $xml[] = '    <price currency="INR">' . number_format((float) ($product['price_inr'] ?? 0), 2, '.', '') . '</price>';
        if (isset($product['sale_price_inr']) && $product['sale_price_inr'] !== null) {
            $xml[] = '    <sale_price currency="INR">' . number_format((float) $product['sale_price_inr'], 2, '.', '') . '</sale_price>';
        }
        $xml[] = '    <unit_type>' . product_feed_xml_escape((string) ($product['unit_type'] ?? '')) . '</unit_type>';
        $xml[] = '    <stock>' . product_feed_xml_escape((string) ($product['stock'] ?? 0)) . '</stock>';
        $xml[] = '  </product>';
    }
    $xml[] = '</products>';
    return implode("\n", $xml);
}

function product_feed_build_payloads(mysqli $conn): array
{
    $rows = product_feed_fetch_rows($conn);
    $products = [];
    foreach ($rows as $row) {
        $products[] = product_feed_normalize_row($row);
    }
    return [
        'json' => product_feed_build_json($products),
        'xml' => product_feed_build_xml($products),
    ];
}

function product_feed_serve_content(string $format, array $payloads): void
{
    if (!headers_sent()) {
        if ($format === 'json') {
            header('Content-Type: application/json; charset=utf-8');
        } else {
            header('Content-Type: application/xml; charset=utf-8');
        }
        header('Cache-Control: public, max-age=300');
    }
    echo $format === 'json' ? $payloads['json'] : $payloads['xml'];
    exit;
}

function product_feed_maybe_serve(array $context): void
{
    if (!product_feed_enabled() || PHP_SAPI === 'cli') {
        return;
    }
    $conn = $GLOBALS['conn'] ?? null;
    if (!$conn instanceof mysqli) {
        return;
    }
    $settings = product_feed_settings();
    $base = product_feed_public_base_path();
    $uriPath = parse_url((string) ($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH);
    $uriPath = is_string($uriPath) ? rtrim($uriPath, '/') : '';

    $xmlPath = $base . '/' . ltrim($settings['xml_file'], '/');
    $jsonPath = $base . '/' . ltrim($settings['json_file'], '/');
    $xmlPath = rtrim($xmlPath, '/');
    $jsonPath = rtrim($jsonPath, '/');

    if ($uriPath !== $xmlPath && $uriPath !== $jsonPath) {
        return;
    }

    $payloads = product_feed_build_payloads($conn);
    product_feed_serve_content($uriPath === $jsonPath ? 'json' : 'xml', $payloads);
}

function product_feed_atomic_write(string $path, string $payload): void
{
    $directory = dirname($path);
    $temporary = tempnam($directory, '.feed-');
    if ($temporary === false) {
        throw new RuntimeException('Unable to create a temporary product feed file.');
    }
    try {
        $written = file_put_contents($temporary, $payload, LOCK_EX);
        if ($written === false || $written !== strlen($payload)) {
            throw new RuntimeException('Unable to write the complete product feed payload.');
        }
        if (!@rename($temporary, $path)) {
            throw new RuntimeException('Unable to publish the product feed file.');
        }
        $temporary = '';
    } finally {
        if ($temporary !== '' && is_file($temporary)) {
            @unlink($temporary);
        }
    }
}

function product_feed_refresh_files(array $context): array
{
    if (!product_feed_enabled()) {
        return CronService::result('skipped', 0, 0, 0, ['reason' => 'disabled']);
    }
    $conn = $context['conn'] ?? ($GLOBALS['conn'] ?? null);
    if (!$conn instanceof mysqli) {
        return CronService::result('failed', 0, 0, 1, ['reason' => 'database_unavailable']);
    }
    $settings = product_feed_settings();
    $dir = product_feed_filesystem_dir();
    if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
        throw new RuntimeException('Unable to create the product feed directory.');
    }

    $payloads = product_feed_build_payloads($conn);
    $xmlFile = $dir . DIRECTORY_SEPARATOR . basename($settings['xml_file']);
    $jsonFile = $dir . DIRECTORY_SEPARATOR . basename($settings['json_file']);

    product_feed_atomic_write($xmlFile, (string) $payloads['xml']);
    product_feed_atomic_write($jsonFile, (string) $payloads['json']);
    return CronService::result('success', 2, 2, 0);
}
