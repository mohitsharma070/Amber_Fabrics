<?php

final class OnlinePaymentMethod
{
    private const SUPPORTED = ['upi', 'card', 'emi'];

    public static function normalize(?string $value): string
    {
        $method = strtolower(trim((string) $value));
        return in_array($method, self::SUPPORTED, true) ? $method : '';
    }
}
