<?php

add_action('admin.order_view.sidebar', 'shipping_courier_render_admin_panel', 30);
add_filter('admin.order_action.handled', 'shipping_courier_handle_admin_action', 20);
add_action('order.after_commit', 'shipping_courier_after_order_commit', 30);
add_action('order.after_payment_success', 'shipping_courier_after_payment_success', 30);
add_action('order.after_status_change', 'shipping_courier_after_status_change', 30);
function_exists('add_cron_action') ? add_cron_action('shipping_courier_cron_tracking_sync', 35, false) : add_action('cron.tick', 'shipping_courier_cron_tracking_sync', 35);
function_exists('add_cron_action') ? add_cron_action('shipping_courier_cron_reverse_sync', 36, false) : add_action('cron.tick', 'shipping_courier_cron_reverse_sync', 36);
add_filter('admin.return_action.handled', 'shipping_courier_handle_admin_return_action', 20);
add_action('admin.return_row.actions', 'shipping_courier_render_return_actions', 20);
add_filter('shipping.quote', 'shipping_courier_filter_shipping_quote', 20);
add_action('admin.shipping_rates.after', 'shipping_courier_render_shipping_rates_status', 20);
