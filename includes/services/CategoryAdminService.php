<?php

final class CategoryAdminService
{
    public static function all(mysqli $conn): array
    {
        $stmt = $conn->prepare('SELECT id, name, slug, parent_id, image, status, uses_variant_size, created_at FROM categories ORDER BY parent_id ASC, name ASC');
        $stmt->execute();
        return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    }

    public static function image(mysqli $conn, int $categoryId): string
    {
        if ($categoryId <= 0) {
            return '';
        }
        $stmt = $conn->prepare('SELECT image FROM categories WHERE id = ? LIMIT 1');
        $stmt->bind_param('i', $categoryId);
        $stmt->execute();
        return (string) ($stmt->get_result()->fetch_assoc()['image'] ?? '');
    }

    public static function create(
        mysqli $conn,
        string $name,
        string $slug,
        ?string $image,
        string $status,
        int $usesVariantSize
    ): int {
        $parentId = null;
        $stmt = $conn->prepare('INSERT INTO categories (name, slug, parent_id, image, status, uses_variant_size) VALUES (?, ?, ?, ?, ?, ?)');
        $stmt->bind_param('ssissi', $name, $slug, $parentId, $image, $status, $usesVariantSize);
        $stmt->execute();
        return (int) $conn->insert_id;
    }

    public static function update(
        mysqli $conn,
        int $categoryId,
        string $name,
        string $slug,
        ?string $image,
        string $status,
        int $usesVariantSize
    ): void {
        if ($categoryId <= 0) {
            throw new InvalidArgumentException('Invalid category selected.');
        }
        $parentId = null;
        $stmt = $conn->prepare(
            'UPDATE categories SET name = ?, slug = ?, parent_id = ?, image = ?, status = ?, uses_variant_size = ? WHERE id = ?'
        );
        $stmt->bind_param('ssissii', $name, $slug, $parentId, $image, $status, $usesVariantSize, $categoryId);
        $stmt->execute();
    }

    public static function delete(mysqli $conn, int $categoryId, array $lockedSlugs): void
    {
        if ($categoryId <= 0) {
            throw new InvalidArgumentException('Invalid category selected.');
        }
        $conn->begin_transaction();
        try {
            $stmt = $conn->prepare('SELECT slug FROM categories WHERE id = ? LIMIT 1 FOR UPDATE');
            $stmt->bind_param('i', $categoryId);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            if (!$row) {
                throw new InvalidArgumentException('Category not found.');
            }
            $slug = (string) ($row['slug'] ?? '');
            if ($slug !== '' && in_array($slug, $lockedSlugs, true)) {
                throw new InvalidArgumentException('Locked taxonomy categories cannot be deleted.');
            }
            if ($slug !== '') {
                $used = $conn->prepare('SELECT COUNT(*) AS total FROM fabrics WHERE category = ?');
                $used->bind_param('s', $slug);
                $used->execute();
                if ((int) ($used->get_result()->fetch_assoc()['total'] ?? 0) > 0) {
                    throw new InvalidArgumentException('Cannot delete this category because products are using it.');
                }
            }
            $delete = $conn->prepare('DELETE FROM categories WHERE id = ?');
            $delete->bind_param('i', $categoryId);
            $delete->execute();
            $conn->commit();
        } catch (Throwable $e) {
            try { $conn->rollback(); } catch (Throwable $ignored) {}
            throw $e;
        }
    }
}
