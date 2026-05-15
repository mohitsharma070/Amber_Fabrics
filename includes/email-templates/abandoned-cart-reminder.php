<?php
$name = (string) ($data['customer_name'] ?? 'Customer');
$itemsCount = (int) ($data['items_count'] ?? 0);
$subtotal = (float) ($data['subtotal'] ?? 0);
$summary = trim((string) ($data['cart_summary'] ?? ''));
$cartUrl = (string) ($data['cart_url'] ?? '');

$subject = 'You left items in your Amber Fabrics cart';
$lines = [
    'Dear ' . $name . ',',
    '',
    'You still have ' . $itemsCount . ' item(s) in your cart.',
    'Current cart value: Rs ' . number_format($subtotal, 2),
    '',
];
if ($summary !== '') {
    $lines[] = 'Cart items:';
    $lines[] = $summary;
    $lines[] = '';
}
$lines[] = 'Complete your order here: ' . $cartUrl;
$lines[] = '';
$lines[] = 'Regards,';
$lines[] = 'Amber Fabrics';

return ['subject' => $subject, 'body' => implode("\r\n", $lines)];

