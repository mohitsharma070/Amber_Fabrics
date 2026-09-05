<?php
$failures = [];
$assert = static function (bool $condition, string $message) use (&$failures): void {
    if (!$condition) $failures[] = $message;
};

$pluginSource = (string) file_get_contents(__DIR__ . '/../plugins/recommendations/plugin.php');
$catalogFallbackLogic = substr($pluginSource, strpos($pluginSource, 'function recommendations_render_catalog_after_results'));

// 1. popularity signal -> Popular picks
$assert(
    str_contains($catalogFallbackLogic, "\$title = (string) \$settings['title_popular'];") &&
    str_contains($catalogFallbackLogic, "\$sectionKey = 'popular';"),
    'Must fall back to popular picks when popularity signal exists'
);

// 2. no popularity but latest -> New arrivals
$assert(
    str_contains($catalogFallbackLogic, "\$title = (string) (\$settings['title_new_arrivals'] ?? 'New arrivals');") &&
    str_contains($catalogFallbackLogic, "\$sectionKey = 'new_arrivals';"),
    'Must fall back to new arrivals when no popularity signal exists but latest products exist'
);

// 3. personalized signals
$assert(
    str_contains($catalogFallbackLogic, "\$title = (string) \$settings['title_personalized'];") &&
    str_contains($catalogFallbackLogic, "\$sectionKey = 'personalized';"),
    'Must use personalized title for view signals'
);

// 4. empty candidates -> no section
$assert(
    str_contains($pluginSource, "if (empty(\$rows) || !function_exists('product_card_build_context')") &&
    str_contains($pluginSource, "function recommendations_render_section"),
    'Must not render section if candidates are empty'
);

// 5. visible catalog products remain excluded
$assert(
    preg_match('/\$excludeProductIds\s*=\s*\[\];\s*if\s*\(is_array\(\$visibleRows\)\)/', $catalogFallbackLogic) === 1 &&
    str_contains($catalogFallbackLogic, "\$excludeProductIds[] = \$productId;"),
    'Visible catalog products must be added to exclude list'
);

// Execute the real catalog coordinator against controlled query results. No DB
// connection is opened; availability filtering is represented by eligible rows.
function recommendations_settings(): array { return $GLOBALS['rec_settings']; }
function recommendations_enabled(string $feature = ''): bool
{
    return $feature === '' || !empty($GLOBALS['rec_settings'][$feature]);
}
function recommendations_recently_viewed_ids(int $exclude): array { return $GLOBALS['rec_recent_ids']; }
function recommendations_fetch_recently_viewed(...$args): array { return $GLOBALS['rec_recent_rows']; }
function recommendations_fetch_signal_products(...$args): array { return $GLOBALS['rec_personalized_rows']; }
function recommendations_fetch_popular_product_ids($conn, $limit, $exclude): array { return $GLOBALS['rec_popular_ids']; }
function recommendations_fetch_products_by_ids($conn, $ids, $limit, $exclude): array
{
    $GLOBALS['rec_exclusions'] = $exclude;
    return array_values(array_filter($GLOBALS['rec_eligible_rows'],
        static fn(array $row): bool => in_array($row['id'], $ids, true) && !in_array($row['id'], $exclude, true)));
}
function recommendations_fetch_latest_products($conn, $limit, $exclude): array { return $GLOBALS['rec_latest_rows']; }
function recommendations_fetch_popular_picks($conn, $limit, $exclude): array
{
    // Existing compatibility helper fills missing popular rows with new arrivals.
    return array_slice(array_merge(recommendations_fetch_products_by_ids($conn, $GLOBALS['rec_popular_ids'], $limit, $exclude),
        $GLOBALS['rec_latest_rows']), 0, $limit);
}
function recommendations_render_section($conn, $rows, $title, $section): void
{
    $GLOBALS['rec_rendered'] = compact('rows', 'title', 'section');
}
eval($catalogFallbackLogic);
$connection = new mysqli();
$scenarios = [
    'unavailable popular product' => [[9], [], [], [], [], 'new_arrivals', 'New arrivals', [20, 21]],
    'one eligible popular product' => [[9], [['id' => 9]], [], [], [], 'popular', 'Popular picks', [9]],
    'no popularity' => [[], [], [], [], [], 'new_arrivals', 'New arrivals', [20, 21]],
    'visible popular product excluded' => [[1], [['id' => 1]], [], [], [], 'new_arrivals', 'New arrivals', [20, 21]],
    'personalized' => [[], [], [1], [], [['id' => 30]], 'personalized', 'Recommended for you', [30]],
    'recently viewed' => [[], [], [31], [['id' => 31]], [], 'recently_viewed', 'Recently viewed', [31]],
];
foreach ($scenarios as $name => [$popularIds, $eligible, $recentIds, $recentRows, $personalized, $key, $title, $expectedIds]) {
    $GLOBALS['rec_settings'] = [
        'recently_viewed_enabled' => true, 'popular_picks_enabled' => true, 'max_items' => 4,
        'title_popular' => 'Popular picks', 'title_new_arrivals' => 'New arrivals',
        'title_recently_viewed' => 'Recently viewed', 'title_personalized' => 'Recommended for you',
    ];
    $GLOBALS['rec_popular_ids'] = $popularIds;
    $GLOBALS['rec_eligible_rows'] = $eligible;
    $GLOBALS['rec_recent_ids'] = $recentIds;
    $GLOBALS['rec_recent_rows'] = $recentRows;
    $GLOBALS['rec_personalized_rows'] = $personalized;
    $GLOBALS['rec_latest_rows'] = [['id' => 20], ['id' => 21]];
    recommendations_render_catalog_after_results(['conn' => $connection, 'rows' => [['id' => 1]]]);
    $rendered = $GLOBALS['rec_rendered'];
    $assert($rendered['section'] === $key && $rendered['title'] === $title, "$name: incorrect heading/source");
    $assert(array_column($rendered['rows'], 'id') === $expectedIds, "$name: incorrect products or latest rows labeled popular");
}

if (!empty($failures)) {
    fwrite(STDERR, "Recommendation fallback contract test failed:\n- " . implode("\n- ", $failures) . "\n");
    exit(1);
}

echo "Recommendation fallback contract test passed.\n";
exit(0);
