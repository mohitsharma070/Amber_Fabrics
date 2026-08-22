<?php

function shipping_courier_after_order_commit(array $context): void
{
    if (!shipping_courier_auto_create_enabled()) {
        return;
    }
    $conn = $context['conn'] ?? null;
    $orderId = (int) ($context['order_id'] ?? 0);
    if (!$conn instanceof mysqli || $orderId <= 0) {
        return;
    }
    $payload = shipping_courier_order_payload($conn, $orderId);
    $order = is_array($payload['order'] ?? null) ? $payload['order'] : [];
    if (!shipping_courier_can_auto_create_after_commit($order)) {
        return;
    }
    $result = shipping_courier_create_shipment($conn, $orderId);
    if (empty($result['ok'])) {
        throw new RuntimeException((string) ($result['message'] ?? 'Courier auto-create after commit failed.'));
    }
}

function shipping_courier_after_payment_success(array $context): void
{
    if (!shipping_courier_auto_create_enabled()) {
        return;
    }
    $conn = $context['conn'] ?? null;
    $orderId = (int) ($context['order_id'] ?? 0);
    if (!$conn instanceof mysqli || $orderId <= 0) {
        return;
    }
    $payload = shipping_courier_order_payload($conn, $orderId);
    $order = is_array($payload['order'] ?? null) ? $payload['order'] : [];
    if (!shipping_courier_can_auto_create_after_payment_success($order)) {
        return;
    }
    $result = shipping_courier_create_shipment($conn, $orderId);
    if (empty($result['ok'])) {
        throw new RuntimeException((string) ($result['message'] ?? 'Courier auto-create after payment failed.'));
    }
}

function shipping_courier_after_status_change(array $context): void
{
    if (!shipping_courier_auto_create_enabled()) {
        return;
    }
    $conn = $context['conn'] ?? null;
    $orderId = (int) ($context['order_id'] ?? 0);
    $targetStatus = strtolower(trim((string) ($context['target_status'] ?? '')));
    if (!$conn instanceof mysqli || $orderId <= 0 || !in_array($targetStatus, ['confirmed', 'packed'], true)) {
        return;
    }
    $payload = shipping_courier_order_payload($conn, $orderId);
    $order = is_array($payload['order'] ?? null) ? $payload['order'] : [];
    if (!shipping_courier_can_auto_create_after_commit($order)) {
        return;
    }
    $result = shipping_courier_create_shipment($conn, $orderId);
    if (empty($result['ok'])) {
        error_log('[shipping-courier] auto-create after status change skipped for order ' . $orderId . ': ' . (string) ($result['message'] ?? 'unknown'));
    }
}
