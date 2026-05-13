<?php
require_once __DIR__ . '/../includes/init.php';
require_once __DIR__ . '/../includes/customer-auth.php';
require_customer();

$orderId = (int) ($_GET['id'] ?? 0);
if ($orderId <= 0) {
    flash('error', 'Invalid order selected.');
    redirect('/customer/orders.php');
}

$invoice = build_order_invoice_payload($conn, $orderId);
if (!$invoice || (int) ($invoice['customer_id'] ?? 0) !== (int) ($_SESSION['customer_id'] ?? 0)) {
    flash('error', 'Order not found.');
    redirect('/customer/orders.php');
}

include __DIR__ . '/../includes/invoice-templates/billing-invoice.php';
