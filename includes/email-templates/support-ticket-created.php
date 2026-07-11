<?php
$ticket = isset($data['ticket']) && is_array($data['ticket']) ? $data['ticket'] : [];
$adminUrl = trim((string) ($data['admin_url'] ?? ''));
$ticketNumber = (string) ($ticket['ticket_number'] ?? '');
$subjectText = (string) ($ticket['subject'] ?? 'Support ticket');
$customerName = (string) ($ticket['customer_name'] ?? 'Customer');
$customerEmail = (string) ($ticket['customer_email'] ?? '');
$orderNumber = (string) ($ticket['order_number'] ?? '');

$lines = [
    'A new support ticket was created.',
    '',
    'Ticket: ' . $ticketNumber,
    'Subject: ' . $subjectText,
    'Customer: ' . $customerName . ($customerEmail !== '' ? ' <' . $customerEmail . '>' : ''),
];
if ($orderNumber !== '') {
    $lines[] = 'Order: ' . $orderNumber;
}
if ($adminUrl !== '') {
    $lines[] = '';
    $lines[] = 'Open in admin: ' . $adminUrl;
}

return [
    'subject' => '[' . site_name() . '] New support ticket ' . $ticketNumber,
    'body' => implode("\n", $lines),
];
