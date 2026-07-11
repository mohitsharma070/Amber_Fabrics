<?php
require_once __DIR__ . '/../services/PaymentService.php';

function orders_structured_financial_columns_ready(mysqli $conn) : bool
{
    return PaymentService::orders_structured_financial_columns_ready($conn);
}

function resolve_coupon_id_for_order(mysqli $conn, int $orderId, string $orderNotes = '') : int
{
    return PaymentService::resolve_coupon_id_for_order($conn, $orderId, $orderNotes);
}

function razorpay_mark_order_paid(mysqli $conn,
    int $orderId,
    string $previousPaymentStatus,
    string $paymentId = '',
    string $rzpOrderId = '',
    string $signature = '') : void
{
    PaymentService::razorpay_mark_order_paid($conn, $orderId, $previousPaymentStatus, $paymentId, $rzpOrderId, $signature);
}

function razorpay_mark_order_failed(mysqli $conn,
    int $orderId,
    string $previousPaymentStatus,
    string $note,
    string $paymentId = '',
    string $rzpOrderId = '') : bool
{
    return PaymentService::razorpay_mark_order_failed($conn, $orderId, $previousPaymentStatus, $note, $paymentId, $rzpOrderId);
}

function consume_coupon_after_razorpay_capture(mysqli $conn,
    int $orderId,
    int $customerId,
    int $preferredCouponId = 0,
    string $orderNotes = '') : bool
{
    return PaymentService::consume_coupon_after_razorpay_capture($conn, $orderId, $customerId, $preferredCouponId, $orderNotes);
}

function razorpay_validate_remote_capture(string $paymentId, string $rzpOrderId, float $expectedAmountInr) : array
{
    return PaymentService::razorpay_validate_remote_capture($paymentId, $rzpOrderId, $expectedAmountInr);
}

function razorpay_http_json(string $method, string $path, ?array $payload = null) : array
{
    return PaymentService::razorpay_http_json($method, $path, $payload);
}

function razorpay_create_order_remote(int $orderId, string $orderNumber, int $amountPaise) : array
{
    return PaymentService::razorpay_create_order_remote($orderId, $orderNumber, $amountPaise);
}

function extract_razorpay_refund_id_from_notes(string $notes) : string
{
    return PaymentService::extract_razorpay_refund_id_from_notes($notes);
}

function latest_refund_ledger_gateway_refund_id(mysqli $conn, int $orderId, string $gateway = 'razorpay') : string
{
    return PaymentService::latest_refund_ledger_gateway_refund_id($conn, $orderId, $gateway);
}

function refund_ledger_event_exists(mysqli $conn,
    int $orderId,
    int $paymentId,
    string $status,
    string $gateway = '',
    string $gatewayRefundId = '') : bool
{
    return PaymentService::refund_ledger_event_exists($conn, $orderId, $paymentId, $status, $gateway, $gatewayRefundId);
}

function payment_webhook_payload_hash(string $payload) : string
{
    return PaymentService::payment_webhook_payload_hash($payload);
}

function cancel_stale_pending_razorpay_order(mysqli $conn, int $orderId, int $ttlMinutes = 30) : bool
{
    return PaymentService::cancel_stale_pending_razorpay_order($conn, $orderId, $ttlMinutes);
}

function release_stale_pending_razorpay_orders_for_customer(mysqli $conn, int $customerId, int $ttlMinutes = 30) : void
{
    PaymentService::release_stale_pending_razorpay_orders_for_customer($conn, $customerId, $ttlMinutes);
}

function release_stale_pending_razorpay_orders_global(mysqli $conn, int $ttlMinutes = 30, int $limit = 100) : int
{
    return PaymentService::release_stale_pending_razorpay_orders_global($conn, $ttlMinutes, $limit);
}

function checkout_shipping_for_order(float $subtotal, string $country, string $pincode, string $paymentMethod) : array
{
    return PaymentService::checkout_shipping_for_order($subtotal, $country, $pincode, $paymentMethod);
}

function payment_webhook_mark_processed(mysqli $conn,
    string $provider,
    string $eventId,
    string $signature,
    ?string $payloadHash = null,
    ?string $rawPayload = null) : void
{
    PaymentService::payment_webhook_mark_processed($conn, $provider, $eventId, $signature, $payloadHash, $rawPayload);
}

function payment_webhook_begin_processing(mysqli $conn,
    string $provider,
    string $eventId,
    string $signature,
    string $payload,
    int $processingTtlSeconds = 120) : array
{
    return PaymentService::payment_webhook_begin_processing($conn, $provider, $eventId, $signature, $payload, $processingTtlSeconds);
}

function payment_webhook_mark_failed(mysqli $conn,
    string $provider,
    string $eventId,
    string $errorMessage,
    string $signature = '') : void
{
    PaymentService::payment_webhook_mark_failed($conn, $provider, $eventId, $errorMessage, $signature);
}
