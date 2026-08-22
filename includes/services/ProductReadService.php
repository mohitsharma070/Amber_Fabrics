<?php

final class ProductReadService
{
    public static function activeByReference(mysqli $conn, int $productId, string $slug): ?array
    {
        if ($slug !== '') {
            $stmt = $conn->prepare("SELECT * FROM fabrics WHERE slug = ? AND status = 'active'");
            $stmt->bind_param('s', $slug);
        } elseif ($productId > 0) {
            $stmt = $conn->prepare("SELECT * FROM fabrics WHERE id = ? AND status = 'active'");
            $stmt->bind_param('i', $productId);
        } else {
            return null;
        }
        $stmt->execute();
        return $stmt->get_result()->fetch_assoc() ?: null;
    }

    public static function analyticsProduct(mysqli $conn, int $productId): ?array
    {
        if ($productId <= 0) {
            return null;
        }
        $stmt = $conn->prepare(
            "SELECT id, name, price, sale_price
             FROM fabrics
             WHERE id = ? AND status = 'active'
             LIMIT 1"
        );
        $stmt->bind_param('i', $productId);
        $stmt->execute();
        return $stmt->get_result()->fetch_assoc() ?: null;
    }

    public static function unitTypeRows(mysqli $conn, array $productIds): array
    {
        $productIds = array_values(array_unique(array_filter(
            array_map('intval', $productIds),
            static fn(int $productId): bool => $productId > 0
        )));
        if ($productIds === []) {
            return [];
        }
        $placeholders = implode(',', array_fill(0, count($productIds), '?'));
        $stmt = $conn->prepare("SELECT id, unit_type FROM fabrics WHERE id IN ({$placeholders})");
        $stmt->bind_param(str_repeat('i', count($productIds)), ...$productIds);
        $stmt->execute();
        return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    }
}
