<?php

final class CustomerAddressService
{
    public static function tableReady(mysqli $conn): bool
    {
        static $readiness = [];
        $connectionId = spl_object_id($conn);
        if (array_key_exists($connectionId, $readiness)) {
            return $readiness[$connectionId];
        }
        try {
            $stmt = $conn->prepare(
                "SELECT COUNT(*) AS total
                 FROM information_schema.TABLES
                 WHERE TABLE_SCHEMA = DATABASE()
                   AND TABLE_NAME = 'customer_addresses'"
            );
            $stmt->execute();
            $readiness[$connectionId] = (int) ($stmt->get_result()->fetch_assoc()['total'] ?? 0) > 0;
        } catch (Throwable $e) {
            $readiness[$connectionId] = false;
        }
        return $readiness[$connectionId];
    }

    public static function list(mysqli $conn, int $customerId): array
    {
        if ($customerId <= 0 || !self::tableReady($conn)) {
            return [];
        }
        try {
            $stmt = $conn->prepare(
                "SELECT id, label, full_name, phone, address_line, city, state, pincode, country, is_default_shipping, created_at, updated_at
                 FROM customer_addresses
                 WHERE customer_id = ?
                 ORDER BY is_default_shipping DESC, id DESC"
            );
            $stmt->bind_param('i', $customerId);
            $stmt->execute();
            return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        } catch (Throwable $e) {
            app_log('error', 'customer_address_list_failed', ['exception_type' => get_class($e)]);
            return [];
        }
    }

    public static function get(mysqli $conn, int $customerId, int $addressId): ?array
    {
        if ($customerId <= 0 || $addressId <= 0 || !self::tableReady($conn)) {
            return null;
        }
        try {
            $stmt = $conn->prepare(
                "SELECT id, label, full_name, phone, address_line, city, state, pincode, country, is_default_shipping
                 FROM customer_addresses
                 WHERE id = ? AND customer_id = ?
                 LIMIT 1"
            );
            $stmt->bind_param('ii', $addressId, $customerId);
            $stmt->execute();
            return $stmt->get_result()->fetch_assoc() ?: null;
        } catch (Throwable $e) {
            app_log('error', 'customer_address_get_failed', ['exception_type' => get_class($e)]);
            return null;
        }
    }

    public static function formValues(array $input): array
    {
        return [
            'id' => (int) ($input['address_id'] ?? $input['id'] ?? 0),
            'label' => trim((string) ($input['label'] ?? '')),
            'full_name' => trim((string) ($input['full_name'] ?? '')),
            'phone' => trim((string) ($input['address_phone'] ?? $input['phone'] ?? '')),
            'address_line' => trim((string) ($input['address_line'] ?? '')),
            'city' => trim((string) ($input['address_city'] ?? $input['city'] ?? '')),
            'state' => trim((string) ($input['address_state'] ?? $input['state'] ?? '')),
            'pincode' => trim((string) ($input['address_pincode'] ?? $input['pincode'] ?? '')),
            'country' => trim((string) ($input['address_country'] ?? $input['country'] ?? 'India')),
            'is_default_shipping' => isset($input['is_default_shipping']) && (int) $input['is_default_shipping'] !== 0 ? 1 : 0,
        ];
    }

    public static function validate(array $values): array
    {
        $errors = [];
        $label = (string) ($values['label'] ?? '');
        $fullName = (string) ($values['full_name'] ?? '');
        $phone = (string) ($values['phone'] ?? '');
        $addressLine = (string) ($values['address_line'] ?? '');
        $city = (string) ($values['city'] ?? '');
        $state = (string) ($values['state'] ?? '');
        $pincode = (string) ($values['pincode'] ?? '');
        $country = (string) ($values['country'] ?? '');

        if ($fullName === '') { $errors['full_name'] = 'Full name is required.'; }
        if ($addressLine === '') { $errors['address_line'] = 'Address is required.'; }
        if ($city === '') { $errors['address_city'] = 'City is required.'; }
        if ($country === '') { $errors['address_country'] = 'Country is required.'; }
        if (mb_strlen($label) > 80) { $errors['label'] = 'Label must be 80 characters or fewer.'; }
        if (mb_strlen($fullName) > 255) { $errors['full_name'] = 'Full name must be 255 characters or fewer.'; }
        if ($phone !== '' && (mb_strlen($phone) > 30 || !preg_match('/^[0-9+\-\s()]{7,30}$/', $phone))) { $errors['address_phone'] = 'Enter a valid phone number.'; }
        if (mb_strlen($city) > 120) { $errors['address_city'] = 'City must be 120 characters or fewer.'; }
        if (mb_strlen($state) > 120) { $errors['address_state'] = 'State must be 120 characters or fewer.'; }
        if (mb_strlen($pincode) > 20) { $errors['address_pincode'] = 'Pincode must be 20 characters or fewer.'; }
        if (mb_strlen($country) > 120) { $errors['address_country'] = 'Country must be 120 characters or fewer.'; }
        return $errors;
    }

    public static function save(mysqli $conn, int $customerId, array $values): void
    {
        if ($customerId <= 0 || !self::tableReady($conn)) {
            throw new RuntimeException('Address book is unavailable.');
        }
        $errors = self::validate($values);
        if ($errors !== []) {
            throw new InvalidArgumentException('Address data is invalid.');
        }

        $addressId = (int) ($values['id'] ?? 0);
        $label = (string) $values['label'];
        $fullName = (string) $values['full_name'];
        $phone = (string) $values['phone'];
        $addressLine = (string) $values['address_line'];
        $city = (string) $values['city'];
        $state = (string) $values['state'];
        $pincode = (string) $values['pincode'];
        $country = (string) $values['country'];
        $isDefault = (int) ($values['is_default_shipping'] ?? 0) === 1 ? 1 : 0;

        $conn->begin_transaction();
        try {
            self::lockCustomer($conn, $customerId);
            if ($addressId > 0) {
                self::requireOwnedForUpdate($conn, $customerId, $addressId);
            } else {
                $count = $conn->prepare('SELECT COUNT(*) AS total FROM customer_addresses WHERE customer_id = ?');
                $count->bind_param('i', $customerId);
                $count->execute();
                if ((int) ($count->get_result()->fetch_assoc()['total'] ?? 0) === 0) {
                    $isDefault = 1;
                }
            }
            if ($isDefault === 1) {
                self::clearDefault($conn, $customerId);
            }

            if ($addressId > 0) {
                $stmt = $conn->prepare(
                    "UPDATE customer_addresses
                     SET label = ?, full_name = ?, phone = ?, address_line = ?, city = ?, state = ?, pincode = ?, country = ?, is_default_shipping = ?, updated_at = NOW()
                     WHERE id = ? AND customer_id = ?"
                );
                $stmt->bind_param('ssssssssiii', $label, $fullName, $phone, $addressLine, $city, $state, $pincode, $country, $isDefault, $addressId, $customerId);
            } else {
                $stmt = $conn->prepare(
                    "INSERT INTO customer_addresses (customer_id, label, full_name, phone, address_line, city, state, pincode, country, is_default_shipping)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
                );
                $stmt->bind_param('issssssssi', $customerId, $label, $fullName, $phone, $addressLine, $city, $state, $pincode, $country, $isDefault);
            }
            $stmt->execute();
            $conn->commit();
        } catch (Throwable $e) {
            try { $conn->rollback(); } catch (Throwable $ignored) {}
            throw $e;
        }
    }

    public static function delete(mysqli $conn, int $customerId, int $addressId): void
    {
        if ($customerId <= 0 || $addressId <= 0 || !self::tableReady($conn)) {
            return;
        }
        $conn->begin_transaction();
        try {
            self::lockCustomer($conn, $customerId);
            $stmt = $conn->prepare('SELECT is_default_shipping FROM customer_addresses WHERE id = ? AND customer_id = ? LIMIT 1 FOR UPDATE');
            $stmt->bind_param('ii', $addressId, $customerId);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            if ($row) {
                $wasDefault = (int) ($row['is_default_shipping'] ?? 0) === 1;
                $delete = $conn->prepare('DELETE FROM customer_addresses WHERE id = ? AND customer_id = ?');
                $delete->bind_param('ii', $addressId, $customerId);
                $delete->execute();
                if ($wasDefault) {
                    $pick = $conn->prepare('SELECT id FROM customer_addresses WHERE customer_id = ? ORDER BY id DESC LIMIT 1 FOR UPDATE');
                    $pick->bind_param('i', $customerId);
                    $pick->execute();
                    $nextId = (int) ($pick->get_result()->fetch_assoc()['id'] ?? 0);
                    if ($nextId > 0) {
                        $set = $conn->prepare('UPDATE customer_addresses SET is_default_shipping = 1 WHERE id = ? AND customer_id = ?');
                        $set->bind_param('ii', $nextId, $customerId);
                        $set->execute();
                    }
                }
            }
            $conn->commit();
        } catch (Throwable $e) {
            try { $conn->rollback(); } catch (Throwable $ignored) {}
            throw $e;
        }
    }

    public static function setDefault(mysqli $conn, int $customerId, int $addressId): void
    {
        if ($customerId <= 0 || $addressId <= 0 || !self::tableReady($conn)) {
            throw new RuntimeException('Address book is unavailable.');
        }
        $conn->begin_transaction();
        try {
            self::lockCustomer($conn, $customerId);
            self::requireOwnedForUpdate($conn, $customerId, $addressId);
            self::clearDefault($conn, $customerId);
            $set = $conn->prepare('UPDATE customer_addresses SET is_default_shipping = 1 WHERE id = ? AND customer_id = ?');
            $set->bind_param('ii', $addressId, $customerId);
            $set->execute();
            if ($set->affected_rows !== 1) {
                throw new RuntimeException('Default address update failed.');
            }
            $conn->commit();
        } catch (Throwable $e) {
            try { $conn->rollback(); } catch (Throwable $ignored) {}
            throw $e;
        }
    }

    private static function requireOwnedForUpdate(mysqli $conn, int $customerId, int $addressId): void
    {
        $stmt = $conn->prepare('SELECT id FROM customer_addresses WHERE id = ? AND customer_id = ? LIMIT 1 FOR UPDATE');
        $stmt->bind_param('ii', $addressId, $customerId);
        $stmt->execute();
        if (!$stmt->get_result()->fetch_assoc()) {
            throw new RuntimeException('Address not found.');
        }
    }

    private static function lockCustomer(mysqli $conn, int $customerId): void
    {
        $stmt = $conn->prepare('SELECT id FROM customers WHERE id = ? LIMIT 1 FOR UPDATE');
        $stmt->bind_param('i', $customerId);
        $stmt->execute();
        if (!$stmt->get_result()->fetch_assoc()) {
            throw new RuntimeException('Customer not found.');
        }
    }

    private static function clearDefault(mysqli $conn, int $customerId): void
    {
        $stmt = $conn->prepare('UPDATE customer_addresses SET is_default_shipping = 0 WHERE customer_id = ?');
        $stmt->bind_param('i', $customerId);
        $stmt->execute();
    }
}
