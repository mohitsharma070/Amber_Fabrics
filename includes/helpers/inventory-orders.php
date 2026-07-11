<?php
require_once __DIR__ . '/../services/InventoryService.php';

function adjust_fabric_stock(mysqli $conn, int $fabricId, string $unitType, float $qty, string $direction = 'decrease') : void
{
    InventoryService::adjust_fabric_stock($conn, $fabricId, $unitType, $qty, $direction);
}

function adjust_variant_stock(mysqli $conn, int $variantId, string $unitType, float $qty, string $direction = 'decrease') : void
{
    InventoryService::adjust_variant_stock($conn, $variantId, $unitType, $qty, $direction);
}

function get_fabric_variants(mysqli $conn, int $fabricId) : array
{
    return InventoryService::get_fabric_variants($conn, $fabricId);
}

function get_variant_by_id(mysqli $conn, int $variantId) : ?array
{
    return InventoryService::get_variant_by_id($conn, $variantId);
}

function shipping_quote_store(float $subtotal,
    string $country,
    string $pincode,
    string $paymentMethod,
    float $baseShipping,
    float $codFee,
    float $shippingTotal,
    string $source = 'manual',
    string $courierName = '',
    int $courierId = 0) : string
{
    return InventoryService::shipping_quote_store($subtotal, $country, $pincode, $paymentMethod, $baseShipping, $codFee, $shippingTotal, $source, $courierName, $courierId);
}

function shipping_quote_get(string $token) : ?array
{
    return InventoryService::shipping_quote_get($token);
}

function get_first_active_variant(mysqli $conn, int $fabricId) : ?array
{
    return InventoryService::get_first_active_variant($conn, $fabricId);
}

function get_first_active_in_stock_variant(mysqli $conn, int $fabricId, string $unitType) : ?array
{
    return InventoryService::get_first_active_in_stock_variant($conn, $fabricId, $unitType);
}

function find_variant(mysqli $conn, int $fabricId, string $color, string $size) : ?array
{
    return InventoryService::find_variant($conn, $fabricId, $color, $size);
}

function get_variants_by_ids(mysqli $conn, array $variantIds) : array
{
    return InventoryService::get_variants_by_ids($conn, $variantIds);
}

function orders_supports_inventory_tracking(mysqli $conn) : bool
{
    return InventoryService::orders_supports_inventory_tracking($conn);
}

function mark_order_inventory_reserved(mysqli $conn, int $orderId) : void
{
    InventoryService::mark_order_inventory_reserved($conn, $orderId);
}

function reserve_order_inventory(mysqli $conn, int $orderId) : void
{
    InventoryService::reserve_order_inventory($conn, $orderId);
}

function ensure_order_inventory_reserved_for_payment_capture(mysqli $conn, int $orderId) : void
{
    InventoryService::ensure_order_inventory_reserved_for_payment_capture($conn, $orderId);
}

function restore_order_inventory(mysqli $conn, int $orderId) : void
{
    InventoryService::restore_order_inventory($conn, $orderId);
}

function order_cancel_should_restore_inventory(string $paymentMethod, string $paymentStatus) : bool
{
    return InventoryService::order_cancel_should_restore_inventory($paymentMethod, $paymentStatus);
}

function customer_cancel_order(mysqli $conn, int $orderId, int $customerId, bool $manageTransaction = true) : array
{
    return InventoryService::customer_cancel_order($conn, $orderId, $customerId, $manageTransaction);
}

function can_transition_order_status(string $currentStatus, string $nextStatus) : bool
{
    return InventoryService::can_transition_order_status($currentStatus, $nextStatus);
}

function order_status_meta(string $status) : array
{
    return InventoryService::order_status_meta($status);
}

function payment_status_meta(string $status) : array
{
    return InventoryService::payment_status_meta($status);
}

function sanitize_online_payment_method(?string $value) : string
{
    return InventoryService::sanitize_online_payment_method($value);
}

function quantity_unit_suffix(string $unitType) : string
{
    return InventoryService::quantity_unit_suffix($unitType);
}

function safe_external_url(?string $value) : string
{
    return InventoryService::safe_external_url($value);
}
