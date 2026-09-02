<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$failures = [];
$assert = static function (bool $condition, string $message) use (&$failures): void {
    if (!$condition) {
        $failures[] = $message;
    }
};

if (!function_exists('e')) {
    function e(mixed $value): string
    {
        return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
    }
}

require_once $root . '/includes/helpers/admin.php';
require_once $root . '/includes/helpers/email-tax-ui.php';

$paginationHelper = $root . '/includes/helpers/catalog-pagination.php';
$assert(is_file($paginationHelper), 'Catalog pagination semantics must live in a focused helper.');
if (is_file($paginationHelper)) {
    require_once $paginationHelper;
}

if (function_exists('catalog_cursor_encode')
    && function_exists('catalog_pagination_context')
    && function_exists('catalog_pagination_resolve')
    && function_exists('catalog_numbered_page_state')
    && function_exists('catalog_cursor_page_state')
    && function_exists('catalog_reset_pagination_state')
    && function_exists('catalog_query')) {
    $queryParameters = static function (string $url): array {
        $query = (string) (parse_url($url, PHP_URL_QUERY) ?? '');
        parse_str($query, $parameters);
        return is_array($parameters) ? $parameters : [];
    };
    $base = [
        'q' => 'linen',
        'category' => 'bedsheets',
        'sort' => 'newest',
        'per_page' => 20,
        'page' => 9,
        'cursor' => 'stale-cursor',
    ];
    $cursorContext = catalog_pagination_context($base);
    $cursor = catalog_cursor_encode('2026-09-02 10:00:00', 120, 8, $cursorContext);

    $assert(catalog_query(catalog_numbered_page_state([], 1)) === '/catalog', 'Unfiltered page 1 must use the clean catalog route.');
    $assert(
        catalog_query(catalog_numbered_page_state($base, 1)) === '/catalog?q=linen&category=bedsheets&sort=newest&per_page=20',
        'Page 1 must have one canonical numbered state without page=1 or cursor.'
    );
    $assert(
        catalog_query(catalog_numbered_page_state($base, 2)) === '/catalog?q=linen&category=bedsheets&sort=newest&per_page=20&page=2',
        'Page 2 must preserve filters, sorting, and page size without a cursor.'
    );
    $assert(
        catalog_query(catalog_numbered_page_state($base, 47)) === '/catalog?q=linen&category=bedsheets&sort=newest&per_page=20&page=47',
        'Existing deep numbered page URLs must remain representable.'
    );
    $sortPageUrl = catalog_query(catalog_numbered_page_state(array_merge($base, ['sort' => 'name_asc']), 2));
    $assert(
        str_contains($sortPageUrl, 'sort=name_asc') && str_contains($sortPageUrl, 'page=2') && !str_contains($sortPageUrl, 'cursor='),
        'Sort plus numbered page must preserve the selected sort without cursor state.'
    );

    $cursorResolution = catalog_pagination_resolve(1, 'newest', $cursor, $cursorContext);
    $assert(
        ($cursorResolution['mode'] ?? '') === 'cursor'
            && ($cursorResolution['cursor_data']['fabric_id'] ?? 0) === 120
            && ($cursorResolution['cursor_data']['variant_id'] ?? -1) === 8,
        'A valid newest cursor with no deep page must activate keyset mode.'
    );
    $cursorUrl = catalog_query(catalog_cursor_page_state($base, $cursor));
    $cursorParameters = $queryParameters($cursorUrl);
    $assert(
        isset($cursorParameters['cursor']) && !isset($cursorParameters['page']),
        'Cursor flow URLs must never expose a contradictory page number.'
    );
    $assert(($cursorResolution['cursor'] ?? '') === $cursor, 'Cursor flow must preserve the current valid cursor token.');

    $pageWins = catalog_pagination_resolve(2, 'newest', $cursor, $cursorContext);
    $assert(
        ($pageWins['mode'] ?? '') === 'numbered' && ($pageWins['page'] ?? 0) === 2 && ($pageWins['cursor'] ?? 'x') === '',
        'An explicit deep numbered page must ignore stale cursor state.'
    );
    $invalidCursor = catalog_pagination_resolve(1, 'newest', 'not-a-valid-cursor', $cursorContext);
    $assert(
        ($invalidCursor['mode'] ?? '') === 'numbered' && ($invalidCursor['page'] ?? 0) === 1,
        'Invalid cursor input must degrade to ordinary page 1.'
    );
    foreach (['name_asc', 'price_desc'] as $incompatibleSort) {
        $changedSortState = array_merge($base, ['sort' => $incompatibleSort]);
        $sortReset = catalog_pagination_resolve(1, $incompatibleSort, $cursor, catalog_pagination_context($changedSortState));
        $assert(
            ($sortReset['mode'] ?? '') === 'numbered' && ($sortReset['cursor'] ?? 'x') === '',
            'Incompatible sort ' . $incompatibleSort . ' must reset cursor mode.'
        );
    }

    $filterChange = catalog_reset_pagination_state(array_merge($base, ['category' => 'towels']));
    $sortChange = catalog_reset_pagination_state(array_merge($base, ['sort' => 'oldest']));
    $filterRemoval = catalog_reset_pagination_state(array_merge($base, ['category' => '']));
    foreach ([
        'filter change' => $filterChange,
        'sort change' => $sortChange,
        'active-filter removal' => $filterRemoval,
    ] as $label => $resetState) {
        $resetUrl = catalog_query($resetState);
        $resetParameters = $queryParameters($resetUrl);
        $assert(
            !isset($resetParameters['cursor']) && !isset($resetParameters['page']),
            ucfirst($label) . ' must reset both pagination mechanisms.'
        );
    }
    $assert(str_contains(catalog_query($filterChange), 'category=towels'), 'Filter changes must preserve the new filter value.');
    $assert(str_contains(catalog_query($sortChange), 'sort=oldest'), 'Sort changes must preserve the new sort value.');
    $assert(!str_contains(catalog_query($filterRemoval), 'category='), 'Removing an active filter must omit its empty parameter.');

    $staleFilterCursor = catalog_pagination_resolve(1, 'newest', $cursor, catalog_pagination_context($filterChange));
    $staleSortCursor = catalog_pagination_resolve(1, 'oldest', $cursor, catalog_pagination_context($sortChange));
    $assert(
        ($staleFilterCursor['mode'] ?? '') === 'numbered' && ($staleFilterCursor['cursor'] ?? 'x') === '',
        'A cursor copied into a changed filter context must reset to numbered page 1.'
    );
    $assert(
        ($staleSortCursor['mode'] ?? '') === 'numbered' && ($staleSortCursor['cursor'] ?? 'x') === '',
        'A cursor copied from newest into oldest sorting must reset to numbered page 1.'
    );

    $paginationHtml = render_pagination(2, 12, $base, 'page', 120, 10);
    $assert(str_contains($paginationHtml, 'page=3'), 'Numbered pagination must still render ordinary page links.');
    $assert(
        !str_contains($paginationHtml, 'cursor=') && !str_contains($paginationHtml, 'stale-cursor'),
        'Ordinary numbered-page links must never inherit cursor state.'
    );
}

$catalog = (string) file_get_contents($root . '/catalog.php');
$assert(
    str_contains($catalog, '$perPageOptions = [10, 20, 30];')
        && str_contains($catalog, "'newest' => 'f.created_at DESC, f.id DESC, COALESCE(v.id, 0) DESC'")
        && str_contains($catalog, "'oldest' => 'f.created_at ASC, f.id ASC, COALESCE(v.id, 0) ASC'")
        && str_contains($catalog, 'ORDER BY {$orderBy} LIMIT ?'),
    'Pagination normalization must preserve catalog page sizes and established result ordering.'
);
$assert(
    str_contains($catalog, '$where = ["f.status = \'active\'", "f.category IN ($sellablePlaceholders)"];'),
    'Pagination changes must not alter catalog visibility or sellable-category rules.'
);
$assert(
    str_contains($catalog, '$baseWhereSql')
        && str_contains($catalog, '$listWhereSql')
        && str_contains($catalog, '{$baseWhereSql}')
        && str_contains($catalog, '{$listWhereSql}'),
    'Catalog totals must use base filters while only the list query receives the cursor boundary.'
);
$assert(
    str_contains($catalog, 'if (!$keysetMode)')
        && str_contains($catalog, 'if ($keysetMode && $nextCursor !== \'\')')
        && !str_contains($catalog, 'Next Page (Fast)'),
    'Numbered and cursor navigation must be mutually exclusive, and cursor mode must not be publicly promoted from deep pages.'
);
$assert(
    !str_contains($catalog, '<input type="hidden" name="page" value="1">'),
    'Catalog filter and search submissions must reset pagination by omitting both page and cursor.'
);

if ($failures !== []) {
    foreach ($failures as $failure) {
        fwrite(STDERR, 'FAIL: ' . $failure . PHP_EOL);
    }
    exit(1);
}

echo "catalog_pagination_contract_test: OK\n";
