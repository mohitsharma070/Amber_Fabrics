<?php
$name = (string) ($data['customer_name'] ?? 'Customer');
$itemsCount = (int) ($data['items_count'] ?? 0);
$subtotal = (float) ($data['subtotal'] ?? 0);
$summary = trim((string) ($data['cart_summary'] ?? ''));
$cartUrl = (string) ($data['cart_url'] ?? '');
$siteName = SiteContext::name();

$subject = 'You left items in your ' . $siteName . ' cart';
$lines = [
    'Dear ' . $name . ',',
    '',
    'You still have ' . $itemsCount . ' item(s) in your cart.',
    'Current cart value: ' . money($subtotal),
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
$lines[] = $siteName;

return ['subject' => $subject, 'body' => implode("\r\n", $lines)];
