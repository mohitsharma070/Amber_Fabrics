<?php
$ticket = isset($data['ticket']) && is_array($data['ticket']) ? $data['ticket'] : [];
$reply = trim((string) ($data['reply'] ?? ''));
$adminUrl = trim((string) ($data['admin_url'] ?? ''));
$ticketNumber = (string) ($ticket['ticket_number'] ?? '');
$subjectText = (string) ($ticket['subject'] ?? 'Support ticket');
$customerName = (string) ($ticket['customer_name'] ?? 'Customer');
$orderNumber = (string) ($ticket['order_number'] ?? '');

$lines = [
    'A customer replied to a support ticket.',
    '',
    'Ticket: ' . $ticketNumber,
    'Subject: ' . $subjectText,
    'Customer: ' . $customerName,
];
if ($orderNumber !== '') {
    $lines[] = 'Order: ' . $orderNumber;
}
if ($reply !== '') {
    $lines[] = '';
    $lines[] = 'Reply:';
    $lines[] = $reply;
}
if ($adminUrl !== '') {
    $lines[] = '';
    $lines[] = 'Open in admin: ' . $adminUrl;
}

return [
    'subject' => '[' . site_name() . '] Customer reply on ticket ' . $ticketNumber,
    'body' => implode("\n", $lines),
];
