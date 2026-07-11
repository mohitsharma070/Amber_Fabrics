<?php
require_once __DIR__ . '/../services/CartService.php';

function cart_hydrate_items(mysqli $conn, array $source, array $sizeMap = [], array $meterMap = []) : array
{
    return CartService::cart_hydrate_items($conn, $source, $sizeMap, $meterMap);
}

function cart_items_subtotal(array $items) : float
{
    return CartService::cart_items_subtotal($items);
}

function variant_size_display(array $variant, string $unitType) : string
{
    return CartService::variant_size_display($variant, $unitType);
}

function parse_size_options(?string $sizeRaw) : array
{
    return CartService::parse_size_options($sizeRaw);
}

function parse_meter_options(?string $meterRaw, float $min = 0.01) : array
{
    return CartService::parse_meter_options($meterRaw, $min);
}

function meter_length_is_allowed(float $meterLength, array $allowedOptions) : bool
{
    return CartService::meter_length_is_allowed($meterLength, $allowedOptions);
}

function checkout_shipping_breakdown(float $subtotal, string $country, string $paymentMethod, bool $codFeeApply = true) : array
{
    return CartService::checkout_shipping_breakdown($subtotal, $country, $paymentMethod, $codFeeApply);
}
