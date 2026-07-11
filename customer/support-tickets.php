<?php
require_once __DIR__ . '/../includes/init.php';
require_once __DIR__ . '/../includes/customer-auth.php';

if (!function_exists('support_tickets_render_customer_page')) {
    flash('error', 'Support tickets are not enabled.');
    redirect('/customer/orders.php');
}

support_tickets_render_customer_page($conn);
