<?php

add_filter('order.timeline.events', 'order_timeline_filter_events', 10);

function order_timeline_settings(): array
{
    return [
        'enabled' => (int) plugin_setting('order-timeline', 'enabled', 1) === 1,
        'show_internal_to_admin' => (int) plugin_setting('order-timeline', 'show_internal_to_admin', 1) === 1,
    ];
}

function order_timeline_label_map(): array
{
    return [
        'order_placed' => 'Order Placed',
        'payment_pending_cod' => 'Payment Pending (COD)',
        'cod_guard_auto_confirmed' => 'Order Confirmed',
        'cod_guard_whatsapp_confirmation' => 'Order Confirmation Pending',
        'cod_guard_call_confirmation' => 'Call Confirmation Pending',
        'cod_guard_message_sent' => 'Confirmation Message Sent',
        'cod_guard_message_failed' => 'Confirmation Message Failed',
        'cod_guard_message_not_configured' => 'Confirmation Message Not Configured',
        'cod_guard_customer_ack_sent' => 'Customer Reply Acknowledged',
        'cod_guard_confirmed' => 'Order Confirmed',
        'payment_success' => 'Payment Received',
        'order_shipped' => 'Order Shipped',
        'order_delivered' => 'Order Delivered',
        'admin_status_update' => 'Order Status Updated',
        'cod_guard_cancelled' => 'Order Cancelled',
        'refund_completed' => 'Refund Completed',
        'refund_initiated' => 'Refund Initiated',
    ];
}

function order_timeline_customer_whitelist(): array
{
    return [
        'order_placed',
        'payment_pending_cod',
        'cod_guard_auto_confirmed',
        'cod_guard_confirmed',
        'payment_success',
        'order_shipped',
        'order_delivered',
        'cod_guard_cancelled',
        'refund_completed',
        'refund_initiated',
    ];
}

function order_timeline_filter_events($events, array $context)
{
    $settings = order_timeline_settings();
    if (!$settings['enabled'] || !is_array($events)) {
        return is_array($events) ? $events : [];
    }

    $audience = strtolower((string) ($context['audience'] ?? 'customer'));
    $labelMap = order_timeline_label_map();
    $customerAllow = order_timeline_customer_whitelist();
    $filtered = [];

    foreach ($events as $ev) {
        if (!is_array($ev)) {
            continue;
        }
        $action = strtolower(trim((string) ($ev['action'] ?? '')));
        if ($action === '') {
            continue;
        }

        if ($audience === 'customer') {
            if (!in_array($action, $customerAllow, true)) {
                continue;
            }
            // Hide internal/technical details for customer timeline.
            $details = trim((string) ($ev['details'] ?? ''));
            if (in_array($action, ['payment_pending_cod', 'cod_guard_whatsapp_confirmation', 'cod_guard_call_confirmation'], true)) {
                $details = '';
            }
            $ev['display_action'] = $labelMap[$action] ?? ucwords(str_replace('_', ' ', $action));
            $ev['display_details'] = $details;
            $filtered[] = $ev;
            continue;
        }

        // Admin audience.
        if (!$settings['show_internal_to_admin']) {
            // If disabled, only show customer-facing milestones to admin too.
            if (!in_array($action, $customerAllow, true)) {
                continue;
            }
        }
        $ev['display_action'] = $labelMap[$action] ?? ucwords(str_replace('_', ' ', $action));
        $ev['display_details'] = trim((string) ($ev['details'] ?? ''));
        $filtered[] = $ev;
    }

    return array_values($filtered);
}
