<?php
$customerName = trim((string) ($data['customer_name'] ?? ''));
$productName = trim((string) ($data['product_name'] ?? 'Item'));
$variantColor = trim((string) ($data['variant_color'] ?? ''));
$variantSize = trim((string) ($data['variant_size'] ?? ''));
$availability = trim((string) ($data['availability'] ?? 'Available now'));
$productUrl = trim((string) ($data['product_url'] ?? ''));
$unsubscribeUrl = trim((string) ($data['unsubscribe_url'] ?? ''));
$siteName = SiteContext::name();

$subject = $productName . ' is back in stock';
$lines = [
    $customerName !== '' ? ('Dear ' . $customerName . ',') : 'Hello,',
    '',
    'Good news. The item you asked about is available again.',
    '',
    'Product: ' . $productName,
];

if ($variantColor !== '' || $variantSize !== '') {
    $variantParts = [];
    if ($variantColor !== '') {
        $variantParts[] = 'Colour: ' . $variantColor;
    }
    if ($variantSize !== '') {
        $variantParts[] = 'Size: ' . $variantSize;
    }
    $lines[] = 'Variant: ' . implode(' | ', $variantParts);
}

$lines[] = 'Current availability: ' . $availability;
$lines[] = '';
if ($productUrl !== '') {
    $lines[] = 'View product: ' . $productUrl;
    $lines[] = '';
}
if ($unsubscribeUrl !== '') {
    $lines[] = 'Unsubscribe from this alert: ' . $unsubscribeUrl;
    $lines[] = '';
}
$lines[] = 'Regards,';
$lines[] = $siteName;

return ['subject' => $subject, 'body' => implode("\r\n", $lines)];
