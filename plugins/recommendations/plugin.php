<?php

add_action('product.view', 'recommendations_record_product_view', 15);
add_action('product.view', 'recommendations_record_recommendation_click', 20);
add_action('product.details.after', 'recommendations_render_product_details', 30);
add_action('cart.after_items', 'recommendations_render_cart_upsells', 20);
add_action('catalog.after_results', 'recommendations_render_catalog_after_results', 20);

function recommendations_settings(): array
{
    return [
        'enabled' => (int) plugin_setting('recommendations', 'enabled', 1) === 1,
        'related_products_enabled' => (int) plugin_setting('recommendations', 'related_products_enabled', 1) === 1,
        'recently_viewed_enabled' => (int) plugin_setting('recommendations', 'recently_viewed_enabled', 1) === 1,
        'cart_upsells_enabled' => (int) plugin_setting('recommendations', 'cart_upsells_enabled', 1) === 1,
        'popular_picks_enabled' => (int) plugin_setting('recommendations', 'popular_picks_enabled', 1) === 1,
        'price_similarity_enabled' => (int) plugin_setting('recommendations', 'price_similarity_enabled', 1) === 1,
        'exclude_out_of_stock' => (int) plugin_setting('recommendations', 'exclude_out_of_stock', 1) === 1,
        'analytics_enabled' => (int) plugin_setting('recommendations', 'analytics_enabled', 1) === 1,
        'impression_logging_enabled' => (int) plugin_setting('recommendations', 'impression_logging_enabled', 0) === 1,
        'min_popularity_events' => max(1, min(100, (int) plugin_setting('recommendations', 'min_popularity_events', 1))),
        'max_items' => max(1, min(12, (int) plugin_setting('recommendations', 'max_items', 4))),
        'title_related' => recommendations_setting_text('title_related', 'You may also like'),
        'title_recently_viewed' => recommendations_setting_text('title_recently_viewed', 'Recently viewed'),
        'title_cart_upsells' => recommendations_setting_text('title_cart_upsells', 'Complete Your Cart'),
        'title_popular' => recommendations_setting_text('title_popular', 'Popular picks'),
        'title_personalized' => recommendations_setting_text('title_personalized', 'Recommended for you'),
    ];
}

function recommendations_setting_text(string $key, string $default): string
{
    $value = trim((string) plugin_setting('recommendations', $key, $default));
    return $value !== '' ? $value : $default;
}

function recommendations_enabled(string $feature = ''): bool
{
    $settings = recommendations_settings();
    if (!$settings['enabled']) {
        return false;
    }
    if ($feature === '') {
        return true;
    }
    return !empty($settings[$feature]);
}

function recommendations_recently_viewed_key(): string
{
    return 'recommendations_recently_viewed';
}

function recommendations_record_product_view(array $context): void
{
    if (PHP_SAPI === 'cli' || !recommendations_enabled('recently_viewed_enabled')) {
        return;
    }

    $conn = $context['conn'] ?? null;
    $productId = (int) ($context['product_id'] ?? 0);
    if (!$conn instanceof mysqli || $productId <= 0) {
        return;
    }

    $key = recommendations_recently_viewed_key();
    $recent = isset($_SESSION[$key]) && is_array($_SESSION[$key]) ? $_SESSION[$key] : [];
    $recent = array_values(array_filter(array_map('intval', $recent), static fn($id) => $id > 0 && $id !== $productId));
    array_unshift($recent, $productId);
    $_SESSION[$key] = array_slice(recommendations_filter_active_product_ids($conn, array_values(array_unique($recent))), 0, 12);
}

function recommendations_record_recommendation_click(array $context): void
{
    if (PHP_SAPI === 'cli' || !function_exists('log_ecommerce_event')) {
        return;
    }

    $settings = recommendations_settings();
    if (!$settings['enabled'] || !$settings['analytics_enabled']) {
        return;
    }

    $conn = $context['conn'] ?? null;
    $productId = (int) ($context['product_id'] ?? 0);
    $section = recommendations_sanitize_tracking_value((string) ($_GET['rec_src'] ?? ''));
    if (!$conn instanceof mysqli || $productId <= 0 || $section === '') {
        return;
    }

    log_ecommerce_event(
        $conn,
        'recommendation_click',
        !empty($context['customer_id']) ? (int) $context['customer_id'] : null,
        null,
        $productId,
        null,
        null,
        null,
        ['section' => $section]
    );
}

function recommendations_sanitize_tracking_value(string $value): string
{
    $value = strtolower(trim($value));
    $value = preg_replace('/[^a-z0-9_-]/', '', $value) ?? '';
    return substr($value, 0, 40);
}

function recommendations_filter_active_product_ids(mysqli $conn, array $productIds): array
{
    $productIds = array_values(array_unique(array_filter(array_map('intval', $productIds), static fn($id) => $id > 0)));
    if (empty($productIds)) {
        return [];
    }

    try {
        $placeholders = implode(',', array_fill(0, count($productIds), '?'));
        $types = str_repeat('i', count($productIds));
        $stmt = $conn->prepare("SELECT id FROM fabrics WHERE status = 'active' AND id IN ($placeholders)");
        if (!$stmt) {
            return [];
        }
        $stmt->bind_param($types, ...$productIds);
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    } catch (Throwable $e) {
        error_log('[recommendations] active product check failed: ' . $e->getMessage());
        return [];
    }

    $activeIds = [];
    foreach ($rows as $row) {
        $activeIds[(int) ($row['id'] ?? 0)] = true;
    }

    $filtered = [];
    foreach ($productIds as $productId) {
        if (!empty($activeIds[$productId])) {
            $filtered[] = $productId;
        }
    }
    return $filtered;
}

function recommendations_recently_viewed_ids(int $excludeProductId = 0): array
{
    $key = recommendations_recently_viewed_key();
    $recent = isset($_SESSION[$key]) && is_array($_SESSION[$key]) ? $_SESSION[$key] : [];
    return array_values(array_filter(array_map('intval', $recent), static fn($id) => $id > 0 && $id !== $excludeProductId));
}

function recommendations_sellable_categories(): array
{
    if (!function_exists('locked_storefront_category_slugs')) {
        return [];
    }
    $categories = locked_storefront_category_slugs();
    return is_array($categories) ? array_values(array_filter(array_map('strval', $categories))) : [];
}

function recommendations_table_exists(mysqli $conn, string $tableName): bool
{
    static $cache = [];
    $tableName = preg_replace('/[^A-Za-z0-9_]/', '', $tableName) ?? '';
    if ($tableName === '') {
        return false;
    }
    if (array_key_exists($tableName, $cache)) {
        return (bool) $cache[$tableName];
    }

    try {
        $stmt = $conn->prepare(
            "SELECT COUNT(*) AS total
             FROM information_schema.TABLES
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = ?"
        );
        if (!$stmt) {
            $cache[$tableName] = false;
            return false;
        }
        $stmt->bind_param('s', $tableName);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $cache[$tableName] = ((int) ($row['total'] ?? 0)) > 0;
    } catch (Throwable $e) {
        error_log('[recommendations] table check failed: ' . $e->getMessage());
        $cache[$tableName] = false;
    }

    return (bool) $cache[$tableName];
}

function recommendations_product_select_sql(): string
{
    return "SELECT
                " . product_card_select_columns() . "
            FROM fabrics f
            LEFT JOIN fabric_variants v
              ON v.id = (
                  SELECT v2.id
                  FROM fabric_variants v2
                  WHERE v2.fabric_id = f.id
                    AND v2.is_active = 1
                  ORDER BY
                    CASE
                      WHEN f.unit_type IN ('piece', 'set') AND COALESCE(v2.stock, 0) > 0 THEN 0
                      WHEN f.unit_type = 'meter' AND COALESCE(v2.stock_meters, 0) > 0 THEN 0
                      ELSE 1
                    END,
                    v2.sort_order ASC,
                    v2.id ASC
                  LIMIT 1
              )";
}

function recommendations_effective_price_sql(): string
{
    return "CASE
        WHEN COALESCE(v.price_override, 0) > 0 THEN COALESCE(v.price_override, 0)
        WHEN COALESCE(f.sale_price, 0) > 0
             AND COALESCE(NULLIF(f.price, 0), f.price_inr, 0) > 0
             AND f.sale_price < COALESCE(NULLIF(f.price, 0), f.price_inr, 0)
            THEN f.sale_price
        ELSE COALESCE(NULLIF(f.price, 0), f.price_inr, 0)
    END";
}

function recommendations_product_price(array $product): float
{
    $regular = (float) (($product['price'] ?? null) !== null && ($product['price'] ?? '') !== ''
        ? $product['price']
        : ($product['price_inr'] ?? 0));
    $sale = (float) ($product['sale_price'] ?? 0);
    return ($sale > 0 && $regular > 0 && $sale < $regular) ? $sale : $regular;
}

function recommendations_base_where_sql(array &$types, array &$params, array $excludeProductIds = []): string
{
    $where = ["f.status = 'active'", "f.is_available = 1"];
    $settings = recommendations_settings();

    $categories = recommendations_sellable_categories();
    if (!empty($categories)) {
        $where[] = 'f.category IN (' . implode(',', array_fill(0, count($categories), '?')) . ')';
        $types[] = str_repeat('s', count($categories));
        foreach ($categories as $category) {
            $params[] = $category;
        }
    }

    $excludeProductIds = array_values(array_unique(array_filter(array_map('intval', $excludeProductIds), static fn($id) => $id > 0)));
    if (!empty($excludeProductIds)) {
        $where[] = 'f.id NOT IN (' . implode(',', array_fill(0, count($excludeProductIds), '?')) . ')';
        $types[] = str_repeat('i', count($excludeProductIds));
        foreach ($excludeProductIds as $productId) {
            $params[] = $productId;
        }
    }

    if ($settings['exclude_out_of_stock']) {
        $where[] = "(
            (f.unit_type IN ('piece', 'set') AND COALESCE(v.stock, f.stock) > 0)
            OR (f.unit_type = 'meter' AND COALESCE(v.stock_meters, f.stock_meters) > 0)
        )";
    }

    return 'WHERE ' . implode(' AND ', $where);
}

function recommendations_fetch_related_products(mysqli $conn, array $product, int $limit, array $excludeProductIds = []): array
{
    $productId = (int) ($product['id'] ?? 0);
    if ($productId > 0) {
        $excludeProductIds[] = $productId;
    }

    $types = [];
    $params = [];
    $whereSql = recommendations_base_where_sql($types, $params, $excludeProductIds);
    $category = trim((string) ($product['category'] ?? ''));
    $material = trim((string) ($product['material'] ?? ''));
    $color = trim((string) ($product['color'] ?? ''));
    $unitType = in_array((string) ($product['unit_type'] ?? ''), ['meter', 'piece', 'set'], true) ? (string) $product['unit_type'] : '';
    $price = recommendations_product_price($product);
    $effectivePriceSql = recommendations_effective_price_sql();
    $settings = recommendations_settings();

    $orderParts = [];
    if ($category !== '') {
        $orderParts[] = 'CASE WHEN f.category = ? THEN 0 ELSE 1 END';
        $types[] = 's';
        $params[] = $category;
    }
    if ($material !== '') {
        $orderParts[] = 'CASE WHEN f.material LIKE ? THEN 0 ELSE 1 END';
        $types[] = 's';
        $params[] = '%' . $material . '%';
    }
    if ($color !== '') {
        $orderParts[] = 'CASE WHEN COALESCE(NULLIF(v.color, \'\'), f.color) LIKE ? THEN 0 ELSE 1 END';
        $types[] = 's';
        $params[] = '%' . $color . '%';
    }
    if ($unitType !== '') {
        $orderParts[] = 'CASE WHEN f.unit_type = ? THEN 0 ELSE 1 END';
        $types[] = 's';
        $params[] = $unitType;
    }
    if ($settings['price_similarity_enabled'] && $price > 0) {
        $orderParts[] = "CASE WHEN {$effectivePriceSql} BETWEEN ? AND ? THEN 0 ELSE 1 END";
        $types[] = 'dd';
        $params[] = round($price * 0.75, 2);
        $params[] = round($price * 1.25, 2);
        $orderParts[] = "ABS({$effectivePriceSql} - ?)";
        $types[] = 'd';
        $params[] = $price;
    }
    $orderParts[] = "CASE WHEN f.unit_type IN ('piece', 'set') THEN COALESCE(v.stock, f.stock) ELSE COALESCE(v.stock_meters, f.stock_meters) END DESC";
    $orderParts[] = 'f.created_at DESC';
    $orderParts[] = 'f.id DESC';

    $sql = recommendations_product_select_sql() . "
            {$whereSql}
            ORDER BY " . implode(', ', $orderParts) . "
            LIMIT ?";
    $types[] = 'i';
    $params[] = $limit;

    $rows = recommendations_run_product_query($conn, $sql, implode('', $types), $params);
    return recommendations_fill_product_rows($conn, $rows, $limit, $excludeProductIds);
}

function recommendations_fetch_products_by_ids(mysqli $conn, array $productIds, int $limit, array $excludeProductIds = []): array
{
    $productIds = array_values(array_unique(array_filter(array_map('intval', $productIds), static fn($id) => $id > 0)));
    if (empty($productIds) || $limit <= 0) {
        return [];
    }

    $types = [];
    $params = [];
    $whereSql = recommendations_base_where_sql($types, $params, $excludeProductIds);
    $whereSql .= ' AND f.id IN (' . implode(',', array_fill(0, count($productIds), '?')) . ')';
    $types[] = str_repeat('i', count($productIds));
    foreach ($productIds as $productId) {
        $params[] = $productId;
    }

    $sql = recommendations_product_select_sql() . "
            {$whereSql}
            ORDER BY f.created_at DESC, f.id DESC
            LIMIT ?";
    $types[] = 'i';
    $params[] = max($limit, count($productIds));

    $rows = recommendations_run_product_query($conn, $sql, implode('', $types), $params);
    if (empty($rows)) {
        return [];
    }

    $rowsById = [];
    foreach ($rows as $row) {
        $rowsById[(int) ($row['id'] ?? 0)] = $row;
    }

    $ordered = [];
    foreach ($productIds as $productId) {
        if (isset($rowsById[$productId])) {
            $ordered[] = $rowsById[$productId];
        }
        if (count($ordered) >= $limit) {
            break;
        }
    }
    return $ordered;
}

function recommendations_fetch_recently_viewed(mysqli $conn, int $excludeProductId, int $limit, array $excludeProductIds = []): array
{
    $ids = array_slice(recommendations_recently_viewed_ids($excludeProductId), 0, max($limit * 3, $limit));
    if (empty($ids)) {
        return [];
    }

    if ($excludeProductId > 0) {
        $excludeProductIds[] = $excludeProductId;
    }

    return recommendations_fetch_products_by_ids($conn, $ids, $limit, $excludeProductIds);
}

function recommendations_item_product_ids(array $items): array
{
    $productIds = [];
    foreach ($items as $item) {
        $productId = (int) ($item['id'] ?? 0);
        if ($productId > 0) {
            $productIds[] = $productId;
        }
    }
    return array_values(array_unique($productIds));
}

function recommendations_fetch_product_signals(mysqli $conn, array $productIds): array
{
    $productIds = array_values(array_unique(array_filter(array_map('intval', $productIds), static fn($id) => $id > 0)));
    if (empty($productIds)) {
        return ['categories' => [], 'materials' => [], 'colors' => [], 'unit_types' => [], 'avg_price' => 0.0];
    }

    try {
        $placeholders = implode(',', array_fill(0, count($productIds), '?'));
        $types = str_repeat('i', count($productIds));
        $stmt = $conn->prepare(
            "SELECT category, material, color, unit_type, price, sale_price, price_inr
             FROM fabrics
             WHERE status = 'active' AND id IN ($placeholders)"
        );
        if (!$stmt) {
            return ['categories' => [], 'materials' => [], 'colors' => [], 'unit_types' => [], 'avg_price' => 0.0];
        }
        $stmt->bind_param($types, ...$productIds);
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    } catch (Throwable $e) {
        error_log('[recommendations] product signal fetch failed: ' . $e->getMessage());
        return ['categories' => [], 'materials' => [], 'colors' => [], 'unit_types' => [], 'avg_price' => 0.0];
    }

    $categories = [];
    $materials = [];
    $colors = [];
    $unitTypes = [];
    $prices = [];
    foreach ($rows as $row) {
        $category = trim((string) ($row['category'] ?? ''));
        if ($category !== '') {
            $categories[] = $category;
        }
        $material = trim((string) ($row['material'] ?? ''));
        if ($material !== '') {
            $materials[] = $material;
        }
        $color = trim((string) ($row['color'] ?? ''));
        if ($color !== '') {
            $colors[] = $color;
        }
        $unitType = (string) ($row['unit_type'] ?? '');
        if (in_array($unitType, ['meter', 'piece', 'set'], true)) {
            $unitTypes[] = $unitType;
        }
        $price = recommendations_product_price($row);
        if ($price > 0) {
            $prices[] = $price;
        }
    }

    return [
        'categories' => array_values(array_unique($categories)),
        'materials' => array_values(array_unique($materials)),
        'colors' => array_values(array_unique($colors)),
        'unit_types' => array_values(array_unique($unitTypes)),
        'avg_price' => !empty($prices) ? (array_sum($prices) / count($prices)) : 0.0,
    ];
}

function recommendations_fetch_signal_products(mysqli $conn, array $signalProductIds, int $limit, array $excludeProductIds = []): array
{
    $signalProductIds = array_values(array_unique(array_filter(array_map('intval', $signalProductIds), static fn($id) => $id > 0)));
    if (empty($signalProductIds) || $limit <= 0) {
        return [];
    }

    $signals = recommendations_fetch_product_signals($conn, $signalProductIds);

    $types = [];
    $params = [];
    $whereSql = recommendations_base_where_sql($types, $params, $excludeProductIds);
    $categories = is_array($signals['categories'] ?? null) ? $signals['categories'] : [];
    $materials = is_array($signals['materials'] ?? null) ? $signals['materials'] : [];
    $colors = is_array($signals['colors'] ?? null) ? $signals['colors'] : [];
    $unitTypes = is_array($signals['unit_types'] ?? null) ? $signals['unit_types'] : [];
    $avgPrice = (float) ($signals['avg_price'] ?? 0);
    $effectivePriceSql = recommendations_effective_price_sql();
    $settings = recommendations_settings();

    $orderParts = [];
    if (!empty($categories)) {
        $orderParts[] = 'CASE WHEN f.category IN (' . implode(',', array_fill(0, count($categories), '?')) . ') THEN 0 ELSE 1 END';
        $types[] = str_repeat('s', count($categories));
        foreach ($categories as $category) {
            $params[] = $category;
        }
    }
    if (!empty($materials)) {
        $materialChecks = [];
        foreach ($materials as $material) {
            $materialChecks[] = 'f.material LIKE ?';
            $types[] = 's';
            $params[] = '%' . $material . '%';
        }
        $orderParts[] = 'CASE WHEN (' . implode(' OR ', $materialChecks) . ') THEN 0 ELSE 1 END';
    }
    if (!empty($colors)) {
        $colorChecks = [];
        foreach ($colors as $color) {
            $colorChecks[] = "COALESCE(NULLIF(v.color, ''), f.color) LIKE ?";
            $types[] = 's';
            $params[] = '%' . $color . '%';
        }
        $orderParts[] = 'CASE WHEN (' . implode(' OR ', $colorChecks) . ') THEN 0 ELSE 1 END';
    }
    if (!empty($unitTypes)) {
        $orderParts[] = 'CASE WHEN f.unit_type IN (' . implode(',', array_fill(0, count($unitTypes), '?')) . ') THEN 0 ELSE 1 END';
        $types[] = str_repeat('s', count($unitTypes));
        foreach ($unitTypes as $cartUnitType) {
            $params[] = $cartUnitType;
        }
    }
    if ($settings['price_similarity_enabled'] && $avgPrice > 0) {
        $orderParts[] = "CASE WHEN {$effectivePriceSql} BETWEEN ? AND ? THEN 0 ELSE 1 END";
        $types[] = 'dd';
        $params[] = round($avgPrice * 0.75, 2);
        $params[] = round($avgPrice * 1.25, 2);
        $orderParts[] = "ABS({$effectivePriceSql} - ?)";
        $types[] = 'd';
        $params[] = $avgPrice;
    }
    $orderParts[] = "CASE WHEN f.unit_type IN ('piece', 'set') THEN COALESCE(v.stock, f.stock) ELSE COALESCE(v.stock_meters, f.stock_meters) END DESC";
    $orderParts[] = 'f.created_at DESC';
    $orderParts[] = 'f.id DESC';

    $sql = recommendations_product_select_sql() . "
            {$whereSql}
            ORDER BY " . implode(', ', $orderParts) . "
            LIMIT ?";
    $types[] = 'i';
    $params[] = $limit;

    $rows = recommendations_run_product_query($conn, $sql, implode('', $types), $params);
    return recommendations_fill_product_rows($conn, $rows, $limit, $excludeProductIds);
}

function recommendations_fetch_cart_upsells(mysqli $conn, array $cartItems, array $wishlistItems, int $limit): array
{
    $cartProductIds = recommendations_item_product_ids($cartItems);
    $wishlistProductIds = recommendations_item_product_ids($wishlistItems);
    $recentProductIds = recommendations_recently_viewed_ids(0);
    $excludeProductIds = array_values(array_unique(array_merge($cartProductIds, $wishlistProductIds)));
    $signalProductIds = array_values(array_unique(array_merge($cartProductIds, $wishlistProductIds, $recentProductIds)));

    $rows = recommendations_fetch_signal_products($conn, $signalProductIds, $limit, $excludeProductIds);
    if (!empty($rows)) {
        return $rows;
    }

    return recommendations_fetch_popular_picks($conn, $limit, $excludeProductIds);
}

function recommendations_fetch_popular_picks(mysqli $conn, int $limit, array $excludeProductIds = []): array
{
    if ($limit <= 0 || !recommendations_enabled('popular_picks_enabled')) {
        return [];
    }

    $popularIds = recommendations_fetch_popular_product_ids($conn, $limit, $excludeProductIds);
    $rows = [];
    if (!empty($popularIds)) {
        $rows = recommendations_fetch_products_by_ids($conn, $popularIds, $limit, $excludeProductIds);
    }

    return recommendations_fill_product_rows($conn, $rows, $limit, $excludeProductIds);
}

function recommendations_fetch_latest_products(mysqli $conn, int $limit, array $excludeProductIds = []): array
{
    if ($limit <= 0) {
        return [];
    }

    $types = [];
    $params = [];
    $whereSql = recommendations_base_where_sql($types, $params, $excludeProductIds);
    $sql = recommendations_product_select_sql() . "
            {$whereSql}
            ORDER BY f.created_at DESC, f.id DESC
            LIMIT ?";
    $types[] = 'i';
    $params[] = $limit;

    return recommendations_run_product_query($conn, $sql, implode('', $types), $params);
}

function recommendations_product_ids_from_rows(array $rows): array
{
    $productIds = [];
    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }
        $productId = (int) ($row['id'] ?? 0);
        if ($productId > 0) {
            $productIds[] = $productId;
        }
    }
    return array_values(array_unique($productIds));
}

function recommendations_append_unique_rows(array $rows, array $moreRows, int $limit): array
{
    $seen = [];
    $merged = [];
    foreach (array_merge($rows, $moreRows) as $row) {
        if (!is_array($row)) {
            continue;
        }
        $productId = (int) ($row['id'] ?? 0);
        if ($productId <= 0 || isset($seen[$productId])) {
            continue;
        }
        $seen[$productId] = true;
        $merged[] = $row;
        if (count($merged) >= $limit) {
            break;
        }
    }
    return $merged;
}

function recommendations_fill_product_rows(mysqli $conn, array $rows, int $limit, array $excludeProductIds = []): array
{
    if ($limit <= 0) {
        return [];
    }

    $rows = recommendations_append_unique_rows($rows, [], $limit);
    if (count($rows) >= $limit) {
        return $rows;
    }

    $excludeProductIds = array_values(array_unique(array_merge(
        $excludeProductIds,
        recommendations_product_ids_from_rows($rows)
    )));
    $fallbackRows = recommendations_fetch_latest_products($conn, $limit - count($rows), $excludeProductIds);
    return recommendations_append_unique_rows($rows, $fallbackRows, $limit);
}

function recommendations_fetch_popular_product_ids(mysqli $conn, int $limit, array $excludeProductIds = []): array
{
    if ($limit <= 0) {
        return [];
    }

    $settings = recommendations_settings();
    $excludeProductIds = array_values(array_unique(array_filter(array_map('intval', $excludeProductIds), static fn($id) => $id > 0)));
    $cacheKey = hash('sha256', json_encode([
        'limit' => $limit,
        'exclude' => $excludeProductIds,
        'min_events' => (int) $settings['min_popularity_events'],
    ], JSON_UNESCAPED_UNICODE));
    static $cache = [];
    if (isset($cache[$cacheKey])) {
        return $cache[$cacheKey];
    }

    $candidateLimit = min(36, max($limit, $limit * 3));
    $scores = [];
    foreach (recommendations_fetch_order_item_popularity($conn, $candidateLimit, $excludeProductIds) as $productId => $score) {
        $scores[(int) $productId] = ($scores[(int) $productId] ?? 0) + ((float) $score * 3);
    }
    foreach (recommendations_fetch_event_log_popularity($conn, $candidateLimit, $excludeProductIds) as $productId => $score) {
        $scores[(int) $productId] = ($scores[(int) $productId] ?? 0) + (float) $score;
    }

    if (empty($scores)) {
        $cache[$cacheKey] = [];
        return [];
    }

    arsort($scores, SORT_NUMERIC);
    $cache[$cacheKey] = array_slice(array_keys($scores), 0, $limit);
    return $cache[$cacheKey];
}

function recommendations_fetch_order_item_popularity(mysqli $conn, int $limit, array $excludeProductIds = []): array
{
    if (!recommendations_table_exists($conn, 'order_items')) {
        return [];
    }

    $settings = recommendations_settings();
    $excludeProductIds = array_values(array_unique(array_filter(array_map('intval', $excludeProductIds), static fn($id) => $id > 0)));
    $useOrdersRecentWindow = recommendations_table_exists($conn, 'orders');
    $productColumn = $useOrdersRecentWindow ? 'oi.product_id' : 'product_id';
    $where = [$productColumn . ' IS NOT NULL', $productColumn . ' > 0'];
    $types = '';
    $params = [];
    if (!empty($excludeProductIds)) {
        $where[] = $productColumn . ' NOT IN (' . implode(',', array_fill(0, count($excludeProductIds), '?')) . ')';
        $types .= str_repeat('i', count($excludeProductIds));
        foreach ($excludeProductIds as $productId) {
            $params[] = $productId;
        }
    }
    if ($useOrdersRecentWindow) {
        $where[] = "o.created_at >= DATE_SUB(NOW(), INTERVAL 365 DAY)";
    }

    $fromSql = $useOrdersRecentWindow
        ? 'orders o JOIN order_items oi ON oi.order_id = o.id'
        : 'order_items';
    $maxIdSql = $useOrdersRecentWindow ? 'MAX(oi.id)' : 'MAX(id)';
    $sql = "SELECT {$productColumn} AS product_id, COUNT(*) AS score
            FROM {$fromSql}
            WHERE " . implode(' AND ', $where) . "
            GROUP BY {$productColumn}
            HAVING score >= ?
            ORDER BY score DESC, {$maxIdSql} DESC
            LIMIT ?";
    $types .= 'ii';
    $params[] = (int) $settings['min_popularity_events'];
    $params[] = max(1, $limit);

    return recommendations_run_score_query($conn, $sql, $types, $params);
}

function recommendations_fetch_event_log_popularity(mysqli $conn, int $limit, array $excludeProductIds = []): array
{
    if (!recommendations_table_exists($conn, 'ecommerce_event_logs')) {
        return [];
    }

    $settings = recommendations_settings();
    $excludeProductIds = array_values(array_unique(array_filter(array_map('intval', $excludeProductIds), static fn($id) => $id > 0)));
    $where = [
        'product_id IS NOT NULL',
        'product_id > 0',
        "created_at >= DATE_SUB(NOW(), INTERVAL 180 DAY)",
    ];
    $types = '';
    $params = [];
    if (!empty($excludeProductIds)) {
        $where[] = 'product_id NOT IN (' . implode(',', array_fill(0, count($excludeProductIds), '?')) . ')';
        $types .= str_repeat('i', count($excludeProductIds));
        foreach ($excludeProductIds as $productId) {
            $params[] = $productId;
        }
    }

    $sql = "SELECT product_id,
                   SUM(CASE
                       WHEN event_type = 'order_item_placed' THEN 4
                       WHEN event_type = 'cart_add' THEN 2
                       ELSE 1
                   END) AS score,
                   COUNT(*) AS event_count
            FROM ecommerce_event_logs
            WHERE " . implode(' AND ', $where) . "
              AND event_type IN ('cart_add', 'order_item_placed')
            GROUP BY product_id
            HAVING event_count >= ?
            ORDER BY score DESC, MAX(created_at) DESC
            LIMIT ?";
    $types .= 'ii';
    $params[] = (int) $settings['min_popularity_events'];
    $params[] = max(1, $limit);

    return recommendations_run_score_query($conn, $sql, $types, $params);
}

function recommendations_run_score_query(mysqli $conn, string $sql, string $types, array $params): array
{
    try {
        $stmt = $conn->prepare($sql);
        if (!$stmt) {
            return [];
        }
        if ($types !== '') {
            $stmt->bind_param($types, ...$params);
        }
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    } catch (Throwable $e) {
        error_log('[recommendations] popularity query failed: ' . $e->getMessage());
        return [];
    }

    $scores = [];
    foreach ($rows as $row) {
        $productId = (int) ($row['product_id'] ?? 0);
        if ($productId > 0) {
            $scores[$productId] = (float) ($row['score'] ?? 0);
        }
    }
    return $scores;
}

function recommendations_run_product_query(mysqli $conn, string $sql, string $types, array $params): array
{
    try {
        $stmt = $conn->prepare($sql);
        if (!$stmt) {
            return [];
        }
        if ($types !== '') {
            $stmt->bind_param($types, ...$params);
        }
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        return is_array($rows) ? $rows : [];
    } catch (Throwable $e) {
        error_log('[recommendations] product query failed: ' . $e->getMessage());
        return [];
    }
}

function recommendations_render_section(mysqli $conn, array $rows, string $title, string $sectionKey = ''): void
{
    if (empty($rows) || !function_exists('product_card_build_context') || !function_exists('product_card_render')) {
        return;
    }
    $sectionKey = recommendations_sanitize_tracking_value($sectionKey !== '' ? $sectionKey : $title);
    recommendations_log_impressions($conn, $rows, $sectionKey);
    ?>
    <div class="mt-4 border-top pt-4 recommendations-block">
        <h5 class="mb-3"><?php echo e($title); ?></h5>
        <div class="catalog-products-grid">
            <?php foreach ($rows as $row): ?>
                <?php echo recommendations_render_product_card($conn, $row, $sectionKey); ?>
            <?php endforeach; ?>
        </div>
    </div>
    <?php
}

function recommendations_render_product_card(mysqli $conn, array $row, string $sectionKey = ''): string
{
    $productCard = product_card_build_context($conn, $row);
    $sectionKey = recommendations_sanitize_tracking_value($sectionKey);
    if ($sectionKey !== '' && !empty($productCard['product_url'])) {
        $productCard['product_url'] = recommendations_append_tracking_to_url((string) $productCard['product_url'], $sectionKey);
    }
    $filteredProductCard = apply_filters('product.card.context', $productCard, [
        'conn' => $conn,
        'row' => $row,
        'source' => 'recommendations',
        'recommendation_section' => $sectionKey,
    ]);
    if (is_array($filteredProductCard)) {
        $productCard = $filteredProductCard;
    }

    $productCardBadges = apply_filters('product.card.badges', [], [
        'conn' => $conn,
        'row' => $row,
        'card' => $productCard,
        'source' => 'recommendations',
        'recommendation_section' => $sectionKey,
        'product_id' => (int) ($productCard['product_id'] ?? 0),
        'variant_id' => (int) ($productCard['variant_id'] ?? 0),
        'display_name' => (string) ($productCard['display_name'] ?? ''),
        'product_url' => (string) ($productCard['product_url'] ?? ''),
        'unit_type' => (string) ($productCard['unit_type'] ?? 'meter'),
        'unit_price' => (float) ($productCard['unit_price'] ?? 0),
        'regular_price' => (float) ($productCard['regular_price'] ?? 0),
        'in_stock' => !empty($productCard['in_stock']),
        'stock' => (float) ($productCard['stock'] ?? 0),
    ]);
    $productCard['badges'] = is_array($productCardBadges) ? $productCardBadges : [];

    $defaultProductCardHtml = product_card_render($productCard);
    $productCardHtml = apply_filters('product.card.render', $defaultProductCardHtml, [
        'conn' => $conn,
        'row' => $row,
        'card' => $productCard,
        'source' => 'recommendations',
        'recommendation_section' => $sectionKey,
    ]);
    if (!is_string($productCardHtml) || trim($productCardHtml) === '') {
        return $defaultProductCardHtml;
    }
    return $productCardHtml;
}

function recommendations_append_tracking_to_url(string $url, string $sectionKey): string
{
    $sectionKey = recommendations_sanitize_tracking_value($sectionKey);
    if ($url === '' || $sectionKey === '') {
        return $url;
    }
    $separator = strpos($url, '?') !== false ? '&' : '?';
    return $url . $separator . 'rec_src=' . rawurlencode($sectionKey);
}

function recommendations_log_impressions(mysqli $conn, array $rows, string $sectionKey): void
{
    if (!function_exists('log_ecommerce_event')) {
        return;
    }

    $settings = recommendations_settings();
    if (!$settings['enabled'] || !$settings['analytics_enabled'] || !$settings['impression_logging_enabled']) {
        return;
    }

    $sectionKey = recommendations_sanitize_tracking_value($sectionKey);
    if ($sectionKey === '') {
        return;
    }

    foreach (array_slice(recommendations_product_ids_from_rows($rows), 0, (int) $settings['max_items']) as $productId) {
        log_ecommerce_event(
            $conn,
            'recommendation_impression',
            !empty($_SESSION['customer_id']) ? (int) $_SESSION['customer_id'] : null,
            null,
            (int) $productId,
            null,
            null,
            null,
            ['section' => $sectionKey]
        );
    }
}

function recommendations_render_product_details(array $context): void
{
    if (!recommendations_enabled()) {
        return;
    }
    $conn = $context['conn'] ?? null;
    $product = $context['product'] ?? [];
    if (!$conn instanceof mysqli || !is_array($product)) {
        return;
    }

    $settings = recommendations_settings();
    $productId = (int) ($product['id'] ?? 0);
    $renderedProductIds = [];
    if ($settings['related_products_enabled']) {
        $related = recommendations_fetch_related_products($conn, $product, (int) $settings['max_items'], []);
        recommendations_render_section($conn, $related, (string) $settings['title_related'], 'related');
        $renderedProductIds = array_merge($renderedProductIds, recommendations_product_ids_from_rows($related));
    }

    if ($settings['recently_viewed_enabled']) {
        $recent = recommendations_fetch_recently_viewed($conn, $productId, (int) $settings['max_items'], $renderedProductIds);
        recommendations_render_section($conn, $recent, (string) $settings['title_recently_viewed'], 'recently_viewed');
    }
}

function recommendations_render_cart_upsells(array $context): void
{
    if (!recommendations_enabled('cart_upsells_enabled')) {
        return;
    }
    $conn = $context['conn'] ?? null;
    $cartItems = $context['cart_items'] ?? [];
    $wishlistItems = $context['wishlist_items'] ?? [];
    if (!$conn instanceof mysqli || !is_array($cartItems) || !is_array($wishlistItems)) {
        return;
    }

    $settings = recommendations_settings();
    $rows = recommendations_fetch_cart_upsells($conn, $cartItems, $wishlistItems, (int) $settings['max_items']);
    recommendations_render_section($conn, $rows, (string) $settings['title_cart_upsells'], 'cart_upsells');
}

function recommendations_render_catalog_after_results(array $context): void
{
    if (!recommendations_enabled()) {
        return;
    }
    $conn = $context['conn'] ?? null;
    if (!$conn instanceof mysqli) {
        return;
    }

    $settings = recommendations_settings();
    $visibleRows = $context['rows'] ?? [];
    $excludeProductIds = [];
    if (is_array($visibleRows)) {
        foreach ($visibleRows as $row) {
            $productId = (int) ($row['id'] ?? 0);
            if ($productId > 0) {
                $excludeProductIds[] = $productId;
            }
        }
    }

    $rows = [];
    $title = (string) $settings['title_popular'];
    if ($settings['recently_viewed_enabled']) {
        $recentIds = recommendations_recently_viewed_ids(0);
        if (!empty($recentIds)) {
            $rows = recommendations_fetch_recently_viewed($conn, 0, min(4, (int) $settings['max_items']), $excludeProductIds);
            if (!empty($rows)) {
                $title = (string) $settings['title_recently_viewed'];
                $sectionKey = 'recently_viewed';
            } else {
                $rows = recommendations_fetch_signal_products($conn, $recentIds, min(4, (int) $settings['max_items']), $excludeProductIds);
                if (!empty($rows)) {
                    $title = (string) $settings['title_personalized'];
                    $sectionKey = 'personalized';
                }
            }
        }
    }

    if (empty($rows)) {
        $rows = recommendations_fetch_popular_picks($conn, min(4, (int) $settings['max_items']), $excludeProductIds);
        $title = (string) $settings['title_popular'];
        $sectionKey = 'popular';
    }

    recommendations_render_section($conn, $rows, $title, $sectionKey ?? 'popular');
}
