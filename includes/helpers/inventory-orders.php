<?php
require_once __DIR__ . '/../services/InventoryService.php';

function return_request_window_days(): int
{
    return 7;
}

function return_request_eligibility(?string $deliveredAt, ?DateTimeImmutable $now = null): array
{
    $value = trim((string) $deliveredAt);
    if ($value === '') {
        return ['eligible' => false, 'expires_at' => null, 'reason' => 'delivery_not_confirmed'];
    }
    try {
        $utc = new DateTimeZone('UTC');
        $delivered = new DateTimeImmutable($value, $utc);
        $delivered = $delivered->setTimezone($utc);
        $now = ($now ?? new DateTimeImmutable('now', $utc))->setTimezone($utc);
        $expires = $delivered->modify('+' . return_request_window_days() . ' days');
        return [
            'eligible' => $now >= $delivered && $now <= $expires,
            'expires_at' => $expires->format('Y-m-d H:i:s'),
            'reason' => $now > $expires ? 'window_closed' : ($now < $delivered ? 'delivery_in_future' : ''),
        ];
    } catch (Throwable $e) {
        return ['eligible' => false, 'expires_at' => null, 'reason' => 'invalid_delivery_timestamp'];
    }
}

function return_request_is_eligible(?string $deliveredAt, ?DateTimeImmutable $now = null): bool
{
    return !empty(return_request_eligibility($deliveredAt, $now)['eligible']);
}

function adjust_fabric_stock(mysqli $conn, int $fabricId, string $unitType, float $qty, string $direction = 'decrease') : void
{
    InventoryService::adjust_fabric_stock($conn, $fabricId, $unitType, $qty, $direction);
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
    return OrderLifecycle::canTransition($currentStatus, $nextStatus);
}

function order_status_meta(string $status) : array
{
    return CommercePresenter::orderStatus($status);
}

function payment_status_meta(string $status) : array
{
    return CommercePresenter::paymentStatus($status);
}

function sanitize_online_payment_method(?string $value) : string
{
    return OnlinePaymentMethod::normalize($value);
}

function quantity_unit_suffix(string $unitType) : string
{
    return CommercePresenter::quantityUnitSuffix($unitType);
}

function safe_external_url(?string $value) : string
{
    return ExternalUrlPolicy::sanitize($value);
}
