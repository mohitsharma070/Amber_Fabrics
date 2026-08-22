<?php

final class CheckoutReadService
{
    public static function customerPrefill(mysqli $conn, int $customerId): array
    {
        if ($customerId <= 0) {
            return [];
        }
        try {
            $customer = CustomerAccountService::profile($conn, $customerId) ?: [];
            $prefill = [
                'full_name' => (string) ($customer['name'] ?? ''),
                'email' => (string) ($customer['email'] ?? ''),
                'phone' => (string) ($customer['phone'] ?? ''),
                'country' => (string) ($customer['country'] ?? ''),
                'address' => '',
                'city' => '',
                'state' => '',
                'pincode' => '',
            ];

            $stmt = $conn->prepare(
                'SELECT customer_name, customer_phone, customer_email, address, city, state, pincode, country
                 FROM orders
                 WHERE customer_id = ?
                 ORDER BY id DESC
                 LIMIT 1'
            );
            $stmt->bind_param('i', $customerId);
            $stmt->execute();
            $lastOrder = $stmt->get_result()->fetch_assoc() ?: [];

            foreach ([
                'full_name' => 'customer_name',
                'phone' => 'customer_phone',
                'email' => 'customer_email',
                'address' => 'address',
                'city' => 'city',
                'state' => 'state',
                'pincode' => 'pincode',
                'country' => 'country',
            ] as $target => $source) {
                if ($prefill[$target] === '' && !empty($lastOrder[$source])) {
                    $prefill[$target] = (string) $lastOrder[$source];
                }
            }
            return array_filter($prefill, static fn(mixed $value): bool => $value !== '');
        } catch (Throwable $e) {
            app_log('error', 'checkout_prefill_load_failed', [
                'customer_id' => $customerId,
                'exception_type' => get_class($e),
            ]);
            return [];
        }
    }

    public static function savedAddressState(
        mysqli $conn,
        int $customerId,
        array $form,
        int $requestedAddressId
    ): array {
        if ($customerId <= 0 || !CustomerAddressService::tableReady($conn)) {
            return [
                'form' => $form,
                'addresses' => [],
                'selected_address_id' => (int) ($form['shipping_address_id'] ?? 0),
            ];
        }

        $addresses = CustomerAddressService::list($conn, $customerId);
        $addressMap = [];
        foreach ($addresses as $address) {
            $addressId = (int) ($address['id'] ?? 0);
            if ($addressId > 0) {
                $addressMap[$addressId] = $address;
            }
        }

        $selectedAddressId = (int) ($form['shipping_address_id'] ?? 0);
        if ($requestedAddressId > 0 && isset($addressMap[$requestedAddressId])) {
            $selectedAddressId = $requestedAddressId;
            $form = CheckoutInput::withSavedAddress($form, $addressMap[$requestedAddressId]);
            $form['country'] = 'India';
        } elseif ($selectedAddressId > 0 && isset($addressMap[$selectedAddressId])) {
            $form = CheckoutInput::withSavedAddress($form, $addressMap[$selectedAddressId]);
            $form['country'] = 'India';
        } elseif (!self::hasTypedAddress($form)) {
            foreach ($addresses as $address) {
                if ((int) ($address['is_default_shipping'] ?? 0) === 1) {
                    $selectedAddressId = (int) ($address['id'] ?? 0);
                    $form = CheckoutInput::withSavedAddress($form, $address);
                    $form['country'] = 'India';
                    break;
                }
            }
        }

        $form['shipping_address_id'] = $selectedAddressId;
        return [
            'form' => $form,
            'addresses' => $addresses,
            'selected_address_id' => $selectedAddressId,
        ];
    }

    private static function hasTypedAddress(array $form): bool
    {
        return trim((string) ($form['address'] ?? '')) !== ''
            || trim((string) ($form['city'] ?? '')) !== ''
            || trim((string) ($form['pincode'] ?? '')) !== '';
    }
}
