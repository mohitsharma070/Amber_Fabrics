<?php

final class CustomerAuthenticationService
{
    public static function authenticate(mysqli $conn, string $email, string $password): array
    {
        $stmt = $conn->prepare(
            'SELECT id, name, password_hash, auth_version, email_verified, is_active FROM customers WHERE email = ? LIMIT 1'
        );
        $stmt->bind_param('s', $email);
        $stmt->execute();
        $customer = $stmt->get_result()->fetch_assoc();

        if (!$customer || !password_verify($password, (string) ($customer['password_hash'] ?? ''))) {
            return ['status' => 'invalid', 'customer' => null];
        }
        if ((int) ($customer['email_verified'] ?? 0) !== 1) {
            return ['status' => 'unverified', 'customer' => null];
        }
        if (isset($customer['is_active']) && (int) $customer['is_active'] !== 1) {
            return ['status' => 'inactive', 'customer' => null];
        }

        unset($customer['password_hash'], $customer['email_verified'], $customer['is_active']);
        return ['status' => 'authenticated', 'customer' => $customer];
    }
}
