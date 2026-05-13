<?php
require_once __DIR__ . '/../includes/init.php';
require_admin();

$orderId = (int) ($_GET['id'] ?? 0);
if ($orderId <= 0) {
    flash('error', 'Invalid order selected.');
    redirect('orders.php');
}

$invoice = build_order_invoice_payload($conn, $orderId);
if (!$invoice) {
    flash('error', 'Order not found.');
    redirect('orders.php');
}

include __DIR__ . '/../includes/invoice-templates/billing-invoice.php';
