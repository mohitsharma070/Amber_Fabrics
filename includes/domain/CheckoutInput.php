<?php

require_once __DIR__ . '/OnlinePaymentMethod.php';

final class CheckoutInput
{
    public static function defaults(): array
    {
        return [
            'full_name' => '',
            'phone' => '',
            'email' => '',
            'address' => '',
            'city' => '',
            'state' => '',
            'pincode' => '',
            'country' => 'India',
            'order_notes' => '',
            'payment_method' => 'cod',
            'online_method' => 'upi',
            'cod_whatsapp_consent' => 0,
            'cod_fee_apply' => 1,
            'shipping_address_id' => 0,
            'shipping_quote_token' => '',
            'create_account' => 0,
            'wants_create_account' => false,
        ];
    }

    public static function fromRequest(array $request, int $customerId): array
    {
        $paymentMethod = strtolower(trim((string) ($request['payment_method'] ?? '')));
        $wantsCreateAccount = $customerId <= 0 && (int) ($request['create_account'] ?? 0) === 1;

        return [
            'full_name' => trim((string) ($request['full_name'] ?? '')),
            'phone' => trim((string) ($request['phone'] ?? '')),
            'email' => trim((string) ($request['email'] ?? '')),
            'address' => trim((string) ($request['address'] ?? '')),
            'city' => trim((string) ($request['city'] ?? '')),
            'state' => trim((string) ($request['state'] ?? '')),
            'pincode' => trim((string) ($request['pincode'] ?? '')),
            'country' => trim((string) ($request['country'] ?? '')),
            'order_notes' => trim((string) ($request['order_notes'] ?? '')),
            'payment_method' => $paymentMethod,
            'online_method' => OnlinePaymentMethod::normalize((string) ($request['online_method'] ?? '')),
            'cod_whatsapp_consent' => (int) ($request['cod_whatsapp_consent'] ?? 0) === 1 ? 1 : 0,
            'cod_fee_apply' => $paymentMethod === 'cod' ? 1 : 0,
            'shipping_address_id' => (int) ($request['shipping_address_id'] ?? 0),
            'shipping_quote_token' => trim((string) ($request['shipping_quote_token'] ?? '')),
            'create_account' => $wantsCreateAccount ? 1 : 0,
            'wants_create_account' => $wantsCreateAccount,
        ];
    }

    public static function withSavedAddress(array $input, ?array $address): array
    {
        if (!$address) {
            $input['shipping_address_id'] = 0;
            return $input;
        }

        foreach ([
            'full_name' => 'full_name',
            'phone' => 'phone',
            'address' => 'address_line',
            'city' => 'city',
            'state' => 'state',
            'pincode' => 'pincode',
            'country' => 'country',
        ] as $target => $source) {
            $input[$target] = trim((string) ($address[$source] ?? $input[$target] ?? ''));
        }
        return $input;
    }

    public static function sessionState(array $input): array
    {
        $defaults = self::defaults();
        $state = [];
        foreach ([
            'full_name', 'phone', 'email', 'address', 'city', 'state', 'pincode', 'country',
            'order_notes', 'payment_method', 'online_method', 'cod_whatsapp_consent', 'cod_fee_apply',
            'shipping_address_id', 'create_account',
        ] as $key) {
            $state[$key] = $input[$key] ?? $defaults[$key];
        }
        return $state;
    }

    public static function validateContactDeliveryPayment(array $input): array
    {
        $errors = [];
        $fullName = (string) ($input['full_name'] ?? '');
        $phone = (string) ($input['phone'] ?? '');
        $email = (string) ($input['email'] ?? '');
        $address = (string) ($input['address'] ?? '');
        $city = (string) ($input['city'] ?? '');
        $state = (string) ($input['state'] ?? '');
        $pincode = (string) ($input['pincode'] ?? '');
        $country = (string) ($input['country'] ?? '');
        $paymentMethod = (string) ($input['payment_method'] ?? '');

        if ($fullName === '') { $errors['full_name'] = 'Full name is required.'; }
        if ($phone === '') {
            $errors['phone'] = 'Phone is required.';
        } elseif (!preg_match('/^[0-9+\-\s()]{7,20}$/', $phone)) {
            $errors['phone'] = 'Enter a valid phone number.';
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) { $errors['email'] = 'Valid email is required.'; }
        if ($address === '') { $errors['address'] = 'Address is required.'; }
        elseif (strlen($address) > 500) { $errors['address'] = 'Address must be 500 characters or fewer.'; }
        if ($city === '') { $errors['city'] = 'City is required.'; }
        if ($state === '') { $errors['state'] = 'State is required.'; }
        if ($pincode === '') {
            $errors['pincode'] = 'Pincode is required.';
        } elseif (strcasecmp($country, 'india') === 0 && !preg_match('/^[1-9][0-9]{5}$/', $pincode)) {
            $errors['pincode'] = 'Enter a valid 6-digit Indian pincode.';
        }
        if ($country === '') { $errors['country'] = 'Country is required.'; }
        if ($country !== '' && strcasecmp($country, 'india') !== 0) {
            $errors['country'] = 'International checkout is inquiry-only for now. Please use Request International Quote.';
        }
        if (!in_array($paymentMethod, ['cod', 'razorpay'], true)) {
            $errors['payment_method'] = 'Invalid payment method.';
        }
        return $errors;
    }

    public static function validateOrderNotes(array $input): array
    {
        return strlen((string) ($input['order_notes'] ?? '')) > 500
            ? ['order_notes' => 'Notes must be 500 characters or fewer.']
            : [];
    }

    public static function hasCompleteDelivery(array $input): bool
    {
        return trim((string) ($input['full_name'] ?? '')) !== ''
            && trim((string) ($input['phone'] ?? '')) !== ''
            && filter_var(trim((string) ($input['email'] ?? '')), FILTER_VALIDATE_EMAIL)
            && trim((string) ($input['address'] ?? '')) !== ''
            && trim((string) ($input['city'] ?? '')) !== ''
            && trim((string) ($input['state'] ?? '')) !== ''
            && (bool) preg_match('/^[1-9][0-9]{5}$/', trim((string) ($input['pincode'] ?? '')));
    }
}
