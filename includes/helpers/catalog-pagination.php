<?php

/**
 * Encode the stable row boundary used by the catalog's internal keyset mode.
 */
function catalog_pagination_context(array $state): string
{
    $context = [];
    foreach ([
        'q', 'category', 'min_price', 'max_price', 'in_stock',
        'material', 'color', 'size', 'dispatch', 'sort', 'per_page',
    ] as $key) {
        $context[$key] = (string) ($state[$key] ?? '');
    }

    return substr(hash('sha256', (string) json_encode($context, JSON_UNESCAPED_UNICODE)), 0, 24);
}

function catalog_cursor_encode(string $createdAt, int $fabricId, int $variantId, string $context): string
{
    $createdAt = trim($createdAt);
    $context = strtolower(trim($context));
    if ($createdAt === '' || $fabricId <= 0 || $variantId < 0 || preg_match('/^[a-f0-9]{24}$/', $context) !== 1) {
        return '';
    }

    $json = json_encode([
        'created_at' => $createdAt,
        'fabric_id' => $fabricId,
        'variant_id' => $variantId,
        'context' => $context,
    ], JSON_UNESCAPED_UNICODE);
    if (!is_string($json)) {
        return '';
    }

    return rtrim(strtr(base64_encode($json), '+/', '-_'), '=');
}

/**
 * @return array{created_at:string,fabric_id:int,variant_id:int,context:string}|null
 */
function catalog_cursor_decode(string $cursor): ?array
{
    $cursor = trim($cursor);
    if ($cursor === '' || strlen($cursor) > 1024 || preg_match('/^[A-Za-z0-9_-]+$/', $cursor) !== 1) {
        return null;
    }

    $encoded = strtr($cursor, '-_', '+/');
    $padding = strlen($encoded) % 4;
    if ($padding > 0) {
        $encoded .= str_repeat('=', 4 - $padding);
    }
    $json = base64_decode($encoded, true);
    if (!is_string($json)) {
        return null;
    }

    $decoded = json_decode($json, true);
    if (!is_array($decoded)) {
        return null;
    }

    $createdAt = trim((string) ($decoded['created_at'] ?? ''));
    $fabricId = (int) ($decoded['fabric_id'] ?? 0);
    $variantId = (int) ($decoded['variant_id'] ?? 0);
    $context = strtolower(trim((string) ($decoded['context'] ?? '')));
    if ($createdAt === '' || $fabricId <= 0 || $variantId < 0 || preg_match('/^[a-f0-9]{24}$/', $context) !== 1) {
        return null;
    }

    return [
        'created_at' => $createdAt,
        'fabric_id' => $fabricId,
        'variant_id' => $variantId,
        'context' => $context,
    ];
}

/**
 * Numbered pages are the public default. A cursor is compatible only with
 * newest/oldest page 1 requests; explicit deep pages always win.
 *
 * @return array{mode:string,page:int,cursor:string,cursor_data:?array}
 */
function catalog_pagination_resolve(int $page, string $sort, string $cursor, string $context): array
{
    $page = max(1, $page);
    $cursorData = null;
    if ($page === 1 && in_array($sort, ['newest', 'oldest'], true)) {
        $cursorData = catalog_cursor_decode($cursor);
        if (($cursorData['context'] ?? '') !== $context) {
            $cursorData = null;
        }
    }

    if ($cursorData !== null) {
        return [
            'mode' => 'cursor',
            'page' => 1,
            'cursor' => trim($cursor),
            'cursor_data' => $cursorData,
        ];
    }

    return [
        'mode' => 'numbered',
        'page' => $page,
        'cursor' => '',
        'cursor_data' => null,
    ];
}

function catalog_reset_pagination_state(array $state): array
{
    unset($state['page'], $state['cursor']);
    return $state;
}

function catalog_numbered_page_state(array $state, int $page): array
{
    unset($state['cursor']);
    $page = max(1, $page);
    if ($page === 1) {
        unset($state['page']);
    } else {
        $state['page'] = $page;
    }
    return $state;
}

function catalog_cursor_page_state(array $state, string $cursor): array
{
    unset($state['page']);
    $cursor = trim($cursor);
    if ($cursor === '') {
        unset($state['cursor']);
    } else {
        $state['cursor'] = $cursor;
    }
    return $state;
}

function catalog_query(array $params): string
{
    unset($params['debug_explain']);
    if (trim((string) ($params['cursor'] ?? '')) !== '') {
        unset($params['page']);
    } else {
        unset($params['cursor']);
        if ((int) ($params['page'] ?? 1) <= 1) {
            unset($params['page']);
        }
    }

    $query = list_build_query($params);
    return $query !== '' ? '/catalog?' . $query : '/catalog';
}
