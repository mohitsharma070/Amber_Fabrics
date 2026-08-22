<?php

final class CustomerAccountService
{
    public static function profile(mysqli $conn, int $customerId): ?array
    {
        return CustomerReadService::contactById($conn, $customerId);
    }

    public static function profileValues(array $input): array
    {
        return [
            'name' => trim((string) ($input['name'] ?? '')),
            'phone' => trim((string) ($input['phone'] ?? '')),
            'country' => trim((string) ($input['country'] ?? '')),
        ];
    }

    public static function validateProfile(array $values): array
    {
        $errors = [];
        $name = (string) ($values['name'] ?? '');
        $phone = (string) ($values['phone'] ?? '');
        $country = (string) ($values['country'] ?? '');
        if ($name === '') { $errors['name'] = 'Name is required.'; }
        if (mb_strlen($name) > 255) { $errors['name'] = 'Name must be 255 characters or fewer.'; }
        if ($phone !== '' && (mb_strlen($phone) > 30 || !preg_match('/^[0-9+\-\s()]{7,30}$/', $phone))) { $errors['phone'] = 'Enter a valid phone number.'; }
        if (mb_strlen($country) > 100) { $errors['country'] = 'Country must be 100 characters or fewer.'; }
        return $errors;
    }

    public static function updateProfile(mysqli $conn, int $customerId, array $values): void
    {
        if ($customerId <= 0 || self::validateProfile($values) !== []) {
            throw new InvalidArgumentException('Profile data is invalid.');
        }
        $name = (string) $values['name'];
        $phone = (string) $values['phone'];
        $country = (string) $values['country'];
        $stmt = $conn->prepare('UPDATE customers SET name = ?, phone = ?, country = ? WHERE id = ?');
        $stmt->bind_param('sssi', $name, $phone, $country, $customerId);
        $stmt->execute();
    }

    public static function changePassword(
        mysqli $conn,
        int $customerId,
        string $currentPassword,
        string $newPassword,
        string $confirmation
    ): array {
        if ($customerId <= 0) {
            return ['errors' => ['current_password' => 'Current password is incorrect.']];
        }
        $stmt = $conn->prepare('SELECT password_hash, auth_version FROM customers WHERE id = ? LIMIT 1');
        $stmt->bind_param('i', $customerId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc() ?: [];
        if (!password_verify($currentPassword, (string) ($row['password_hash'] ?? ''))) {
            return ['errors' => ['current_password' => 'Current password is incorrect.']];
        }
        $passwordError = password_strength_error($newPassword);
        if ($passwordError !== null) {
            return ['errors' => ['new_password' => $passwordError]];
        }
        if ($newPassword !== $confirmation) {
            return ['errors' => ['confirm_password' => 'New passwords do not match.']];
        }

        $newHash = password_hash($newPassword, PASSWORD_DEFAULT);
        $authVersion = max(1, (int) ($row['auth_version'] ?? 1));
        $update = $conn->prepare('UPDATE customers SET password_hash = ?, auth_version = auth_version + 1 WHERE id = ? AND auth_version = ?');
        $update->bind_param('sii', $newHash, $customerId, $authVersion);
        $update->execute();
        if ($update->affected_rows !== 1) {
            return ['errors' => ['current_password' => 'Your account changed in another session. Please try again.']];
        }

        return ['errors' => [], 'auth_version' => $authVersion + 1];
    }
}
