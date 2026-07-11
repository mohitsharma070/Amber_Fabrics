<?php

add_action('app.init', 'seo_suite_on_app_init', 20);
add_action('page.head', 'seo_suite_on_page_head', 20);

function seo_suite_settings(): array
{
    return [
        'enabled' => (int) plugin_setting('seo-suite', 'enabled', 1) === 1,
        'meta_enabled' => (int) plugin_setting('seo-suite', 'meta_enabled', 1) === 1,
        'schema_enabled' => (int) plugin_setting('seo-suite', 'schema_enabled', 1) === 1,
        'sitemap_enabled' => (int) plugin_setting('seo-suite', 'sitemap_enabled', 1) === 1,
        'robots_enabled' => (int) plugin_setting('seo-suite', 'robots_enabled', 1) === 1,
    ];
}

function seo_suite_enabled(): bool
{
    return seo_suite_settings()['enabled'];
}

function seo_suite_meta_enabled(): bool
{
    $settings = seo_suite_settings();
    return $settings['enabled'] && $settings['meta_enabled'];
}

function seo_suite_schema_enabled(): bool
{
    $settings = seo_suite_settings();
    return $settings['enabled'] && $settings['schema_enabled'];
}

function seo_suite_sitemap_enabled(): bool
{
    $settings = seo_suite_settings();
    return $settings['enabled'] && $settings['sitemap_enabled'];
}

function seo_suite_robots_enabled(): bool
{
    $settings = seo_suite_settings();
    return $settings['enabled'] && $settings['robots_enabled'];
}

function seo_suite_on_app_init(array $context): void
{
    if (!seo_suite_enabled() || PHP_SAPI === 'cli') {
        return;
    }

    $uriPath = parse_url((string) ($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH);
    $uriPath = is_string($uriPath) ? rtrim($uriPath, '/') : '';
    if ($uriPath === '') {
        $uriPath = '/';
    }

    if ($uriPath === '/robots.txt') {
        seo_suite_serve_robots_txt();
    }

    if ($uriPath === '/sitemap-products.xml') {
        seo_suite_serve_products_sitemap($context);
    }

    if ($uriPath === '/sitemap-categories.xml') {
        seo_suite_serve_categories_sitemap($context);
    }
}

function seo_suite_on_page_head(array $context): void
{
    if (seo_suite_meta_enabled()) {
        $canonicalUrl = seo_suite_current_canonical_url();
        if ($canonicalUrl !== '') {
            echo '<link rel="canonical" href="' . seo_suite_html_escape($canonicalUrl) . '">' . "\n";
        }

        if (seo_suite_robots_enabled()) {
            echo '<meta name="robots" content="index,follow">' . "\n";
        }
    }

    if (seo_suite_schema_enabled()) {
        seo_suite_render_product_schema($context);
        seo_suite_render_breadcrumb_schema($context);
        seo_suite_render_faq_schema($context);
    }
}

function seo_suite_html_escape(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

function seo_suite_current_canonical_url(): string
{
    $uriPath = parse_url((string) ($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH);
    $uriPath = is_string($uriPath) ? trim($uriPath) : '';
    if ($uriPath === '') {
        $uriPath = '/';
    }

    $query = [];
    if (strtolower(basename($uriPath)) === 'fabric.php') {
        $productId = (int) ($_GET['id'] ?? 0);
        if ($productId > 0) {
            $query['id'] = $productId;
        }
    }

    if (!empty($query)) {
        return SiteContext::url($uriPath . '?' . http_build_query($query));
    }
    return SiteContext::url($uriPath);
}

function seo_suite_xml_escape(string $value): string
{
    return htmlspecialchars($value, ENT_XML1 | ENT_COMPAT, 'UTF-8');
}

function seo_suite_sitemap_urlset(array $urls): string
{
    $urls = apply_filters('seo.sitemap.urls', $urls, [
        'source' => 'seo-suite',
    ]);
    if (!is_array($urls)) {
        $urls = [];
    }

    $xml = [];
    $xml[] = '<?xml version="1.0" encoding="UTF-8"?>';
    $xml[] = '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">';
    foreach ($urls as $url) {
        $loc = trim((string) ($url['loc'] ?? ''));
        if ($loc === '') {
            continue;
        }
        $lastmod = trim((string) ($url['lastmod'] ?? ''));
        $xml[] = '  <url>';
        $xml[] = '    <loc>' . seo_suite_xml_escape($loc) . '</loc>';
        if ($lastmod !== '') {
            $xml[] = '    <lastmod>' . seo_suite_xml_escape($lastmod) . '</lastmod>';
        }
        $xml[] = '  </url>';
    }
    $xml[] = '</urlset>';
    return implode("\n", $xml);
}

function seo_suite_serve_xml(string $xml): void
{
    if (!headers_sent()) {
        header('Content-Type: application/xml; charset=UTF-8');
        header('Cache-Control: public, max-age=300');
    }
    echo $xml;
    exit;
}

function seo_suite_serve_plain_text(string $text): void
{
    if (!headers_sent()) {
        header('Content-Type: text/plain; charset=UTF-8');
        header('Cache-Control: public, max-age=300');
    }
    echo $text;
    exit;
}

function seo_suite_serve_robots_txt(): void
{
    if (!seo_suite_robots_enabled()) {
        seo_suite_serve_plain_text("User-agent: *\nDisallow: /\n");
    }

    $lines = [
        'User-agent: *',
        'Allow: /',
        'Sitemap: ' . SiteContext::url('/sitemap-products.xml'),
        'Sitemap: ' . SiteContext::url('/sitemap-categories.xml'),
    ];
    seo_suite_serve_plain_text(implode("\n", $lines) . "\n");
}

function seo_suite_fetch_active_products(mysqli $conn): array
{
    $stmt = $conn->prepare(
        "SELECT id, created_at
         FROM fabrics
         WHERE status = 'active' AND is_available = 1
         ORDER BY id DESC"
    );
    if (!$stmt) {
        return [];
    }
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    return is_array($rows) ? $rows : [];
}

function seo_suite_fetch_active_categories(mysqli $conn): array
{
    return storefront_categories_fetch($conn);
}

function seo_suite_serve_products_sitemap(array $context): void
{
    if (!seo_suite_sitemap_enabled()) {
        return;
    }
    $conn = $context['conn'] ?? ($GLOBALS['conn'] ?? null);
    if (!$conn instanceof mysqli) {
        return;
    }

    $rows = seo_suite_fetch_active_products($conn);
    $urls = [];
    foreach ($rows as $row) {
        $id = (int) ($row['id'] ?? 0);
        if ($id <= 0) {
            continue;
        }
        $lastmod = seo_suite_normalize_lastmod((string) ($row['created_at'] ?? ''));
        $urls[] = [
            'loc' => SiteContext::url('/fabric.php?id=' . $id),
            'lastmod' => $lastmod,
        ];
    }
    seo_suite_serve_xml(seo_suite_sitemap_urlset($urls));
}

function seo_suite_serve_categories_sitemap(array $context): void
{
    if (!seo_suite_sitemap_enabled()) {
        return;
    }
    $conn = $context['conn'] ?? ($GLOBALS['conn'] ?? null);
    if (!$conn instanceof mysqli) {
        return;
    }

    $rows = seo_suite_fetch_active_categories($conn);
    $urls = [];
    foreach ($rows as $row) {
        $slug = trim((string) ($row['slug'] ?? ''));
        if ($slug === '') {
            continue;
        }
        $urls[] = [
            'loc' => SiteContext::url('/catalog.php?category=' . rawurlencode($slug)),
            'lastmod' => '',
        ];
    }
    seo_suite_serve_xml(seo_suite_sitemap_urlset($urls));
}

function seo_suite_render_product_schema(array $context): void
{
    $page = strtolower(trim((string) ($context['page'] ?? '')));
    if ($page !== 'fabric.php') {
        return;
    }

    $productId = (int) ($_GET['id'] ?? 0);
    if ($productId <= 0) {
        return;
    }

    $conn = $GLOBALS['conn'] ?? null;
    if (!$conn instanceof mysqli) {
        return;
    }

    $product = seo_suite_fetch_schema_product($conn, $productId);
    if (empty($product)) {
        return;
    }

    $schema = seo_suite_build_product_schema($conn, $product);
    if (empty($schema)) {
        return;
    }

    $json = json_encode($schema, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
    if (!is_string($json) || $json === '') {
        return;
    }

    echo '<script type="application/ld+json">' . $json . '</script>' . "\n";
}

function seo_suite_fetch_schema_product(mysqli $conn, int $productId): array
{
    static $cache = [];
    if (isset($cache[$productId]) && is_array($cache[$productId])) {
        return $cache[$productId];
    }

    $stmt = $conn->prepare(
        "SELECT id, name, sku, description, image, price, sale_price, price_inr, stock, stock_meters, unit_type, is_available, status
         FROM fabrics
         WHERE id = ? AND status = 'active'
         LIMIT 1"
    );
    if (!$stmt) {
        return [];
    }
    $stmt->bind_param('i', $productId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $cache[$productId] = is_array($row) ? $row : [];
    return $cache[$productId];
}

function seo_suite_schema_price(array $product): float
{
    $regularPrice = (float) (($product['price'] !== null && $product['price'] !== '') ? $product['price'] : ($product['price_inr'] ?? 0));
    $salePrice = (float) ($product['sale_price'] ?? 0);
    if ($salePrice > 0 && ($regularPrice <= 0 || $salePrice < $regularPrice)) {
        return round($salePrice, 2);
    }
    return round(max(0, $regularPrice), 2);
}

function seo_suite_schema_availability(array $product): string
{
    $unitType = in_array((string) ($product['unit_type'] ?? ''), ['meter', 'piece', 'set'], true)
        ? (string) $product['unit_type']
        : 'meter';
    $stock = $unitType === 'meter'
        ? (float) ($product['stock_meters'] ?? 0)
        : (float) ($product['stock'] ?? 0);
    $isAvailable = (int) ($product['is_available'] ?? 0) === 1;
    if ($isAvailable && $stock > 0) {
        return 'https://schema.org/InStock';
    }
    return 'https://schema.org/OutOfStock';
}

function seo_suite_fetch_product_review_aggregate(mysqli $conn, int $productId): array
{
    try {
        $stmt = $conn->prepare(
            "SELECT AVG(rating) AS avg_rating, COUNT(*) AS total_reviews
             FROM product_reviews
             WHERE product_id = ? AND status = 'approved'"
        );
        if (!$stmt) {
            return [];
        }
        $stmt->bind_param('i', $productId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc() ?: [];
        $count = (int) ($row['total_reviews'] ?? 0);
        $avg = (float) ($row['avg_rating'] ?? 0);
        if ($count <= 0 || $avg <= 0) {
            return [];
        }
        return [
            '@type' => 'AggregateRating',
            'ratingValue' => number_format($avg, 1, '.', ''),
            'reviewCount' => $count,
        ];
    } catch (Throwable $e) {
        return [];
    }
}

function seo_suite_build_product_schema(mysqli $conn, array $product): array
{
    $id = (int) ($product['id'] ?? 0);
    if ($id <= 0) {
        return [];
    }

    $name = trim((string) ($product['name'] ?? ''));
    if ($name === '') {
        return [];
    }

    $image = trim((string) ($product['image'] ?? ''));
    $imageUrl = $image !== '' ? SiteContext::url('/images/fabrics/' . ltrim($image, '/')) : '';
    $description = trim((string) ($product['description'] ?? ''));
    $sku = trim((string) ($product['sku'] ?? ''));
    $price = seo_suite_schema_price($product);
    $url = SiteContext::url('/fabric.php?id=' . $id);

    $schema = [
        '@context' => 'https://schema.org',
        '@type' => 'Product',
        'name' => $name,
        'description' => $description,
        'sku' => $sku,
        'brand' => [
            '@type' => 'Brand',
            'name' => SiteContext::name(),
        ],
        'url' => $url,
        'offers' => [
            '@type' => 'Offer',
            'url' => $url,
            'priceCurrency' => 'INR',
            'price' => number_format($price, 2, '.', ''),
            'availability' => seo_suite_schema_availability($product),
        ],
    ];

    if ($imageUrl !== '') {
        $schema['image'] = [$imageUrl];
    }

    $aggregateRating = seo_suite_fetch_product_review_aggregate($conn, $id);
    if (!empty($aggregateRating)) {
        $schema['aggregateRating'] = $aggregateRating;
    }

    return $schema;
}

function seo_suite_render_breadcrumb_schema(array $context): void
{
    $page = strtolower(trim((string) ($context['page'] ?? '')));
    $items = [];

    if ($page === 'catalog.php') {
        $items = [
            ['name' => 'Home', 'url' => SiteContext::url('/index.php')],
            ['name' => 'Shop', 'url' => SiteContext::url('/catalog.php')],
        ];
    } elseif ($page === 'fabric.php') {
        $productName = seo_suite_breadcrumb_product_name();
        if ($productName === '') {
            return;
        }
        $items = [
            ['name' => 'Home', 'url' => SiteContext::url('/index.php')],
            ['name' => 'Shop', 'url' => SiteContext::url('/catalog.php')],
            ['name' => $productName, 'url' => SiteContext::url('/fabric.php?id=' . (int) ($_GET['id'] ?? 0))],
        ];
    } else {
        return;
    }

    $schema = [
        '@context' => 'https://schema.org',
        '@type' => 'BreadcrumbList',
        'itemListElement' => [],
    ];

    $position = 1;
    foreach ($items as $item) {
        $name = trim((string) ($item['name'] ?? ''));
        $url = trim((string) ($item['url'] ?? ''));
        if ($name === '' || $url === '') {
            continue;
        }
        $schema['itemListElement'][] = [
            '@type' => 'ListItem',
            'position' => $position,
            'name' => $name,
            'item' => $url,
        ];
        $position++;
    }

    if (empty($schema['itemListElement'])) {
        return;
    }

    $json = json_encode($schema, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if (!is_string($json) || $json === '') {
        return;
    }
    echo '<script type="application/ld+json">' . $json . '</script>' . "\n";
}

function seo_suite_breadcrumb_product_name(): string
{
    $productId = (int) ($_GET['id'] ?? 0);
    if ($productId <= 0) {
        return '';
    }

    $conn = $GLOBALS['conn'] ?? null;
    if (!$conn instanceof mysqli) {
        return '';
    }

    $product = seo_suite_fetch_schema_product($conn, $productId);
    return trim((string) ($product['name'] ?? ''));
}

function seo_suite_render_faq_schema(array $context): void
{
    $page = strtolower(trim((string) ($context['page'] ?? '')));
    if ($page !== 'faq.php') {
        return;
    }

    $faqItems = seo_suite_extract_faq_items();
    if (empty($faqItems)) {
        return;
    }

    $mainEntity = [];
    foreach ($faqItems as $item) {
        $question = trim((string) ($item['question'] ?? ''));
        $answer = trim((string) ($item['answer'] ?? ''));
        if ($question === '' || $answer === '') {
            continue;
        }
        $mainEntity[] = [
            '@type' => 'Question',
            'name' => $question,
            'acceptedAnswer' => [
                '@type' => 'Answer',
                'text' => $answer,
            ],
        ];
    }

    if (empty($mainEntity)) {
        return;
    }

    $schema = [
        '@context' => 'https://schema.org',
        '@type' => 'FAQPage',
        'mainEntity' => $mainEntity,
    ];

    $json = json_encode($schema, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
    if (!is_string($json) || $json === '') {
        return;
    }
    echo '<script type="application/ld+json">' . $json . '</script>' . "\n";
}

function seo_suite_extract_faq_items(): array
{
    try {
        $siteSettings = SiteSettingsService::get();
        $html = trim((string) ($siteSettings['faq_body_html'] ?? ''));
        $html = strtr($html, [
            '{{site_name}}' => SiteContext::name(),
            '{{contact_email}}' => SiteContext::contactEmail(),
        ]);
        if ($html === '') {
            return [];
        }

        if (!class_exists('DOMDocument')) {
            return [];
        }

        $dom = new DOMDocument();
        $flags = LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD;
        $prev = libxml_use_internal_errors(true);
        $loaded = $dom->loadHTML('<?xml encoding="UTF-8"><div>' . $html . '</div>', $flags);
        libxml_clear_errors();
        libxml_use_internal_errors($prev);
        if (!$loaded) {
            return [];
        }

        $root = $dom->getElementsByTagName('div')->item(0);
        if (!$root) {
            return [];
        }

        $items = [];
        $currentQuestion = '';
        foreach ($root->childNodes as $node) {
            if (!$node instanceof DOMNode) {
                continue;
            }
            $nodeName = strtolower((string) $node->nodeName);
            $text = trim((string) $node->textContent);
            if ($text === '') {
                continue;
            }

            $isQuestionTag = in_array($nodeName, ['h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'strong'], true);
            if ($isQuestionTag) {
                $currentQuestion = $text;
                continue;
            }

            if ($currentQuestion === '') {
                continue;
            }

            if (in_array($nodeName, ['p', 'li'], true)) {
                if (seo_suite_strlen($currentQuestion) > 200 || seo_suite_strlen($text) > 5000) {
                    continue;
                }
                $items[] = [
                    'question' => $currentQuestion,
                    'answer' => $text,
                ];
                $currentQuestion = '';
            }
        }

        // Conservative cap to keep payload bounded and predictable.
        return array_slice($items, 0, 20);
    } catch (Throwable $e) {
        return [];
    }
}

function seo_suite_strlen(string $value): int
{
    if (function_exists('mb_strlen')) {
        return (int) mb_strlen($value, 'UTF-8');
    }
    return strlen($value);
}

function seo_suite_normalize_lastmod(string $value): string
{
    $value = trim($value);
    if ($value === '') {
        return '';
    }
    $ts = strtotime($value);
    if ($ts === false || $ts <= 0) {
        return '';
    }
    return date('c', $ts);
}
