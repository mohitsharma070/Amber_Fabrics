<?php

final class CustomerReadService
{
    public static function contactById(mysqli $conn, int $customerId): ?array
    {
        if ($customerId <= 0) {
            return null;
        }
        $stmt = $conn->prepare(
            "SELECT name, email, phone, country
             FROM customers
             WHERE id = ?
             LIMIT 1"
        );
        $stmt->bind_param('i', $customerId);
        $stmt->execute();
        return $stmt->get_result()->fetch_assoc() ?: null;
    }

    public static function identityById(mysqli $conn, int $customerId): ?array
    {
        if ($customerId <= 0) {
            return null;
        }
        $stmt = $conn->prepare(
            "SELECT id, name, email
             FROM customers
             WHERE id = ?
             LIMIT 1"
        );
        $stmt->bind_param('i', $customerId);
        $stmt->execute();
        return $stmt->get_result()->fetch_assoc() ?: null;
    }

    public static function emailById(mysqli $conn, int $customerId): string
    {
        if ($customerId <= 0) {
            return '';
        }
        $stmt = $conn->prepare("SELECT email FROM customers WHERE id = ? LIMIT 1");
        $stmt->bind_param('i', $customerId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        return (string) ($row['email'] ?? '');
    }
}
