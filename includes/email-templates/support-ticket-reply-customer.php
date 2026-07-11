<?php
$ticket = isset($data['ticket']) && is_array($data['ticket']) ? $data['ticket'] : [];
$reply = trim((string) ($data['reply'] ?? ''));
$ticketUrl = trim((string) ($data['ticket_url'] ?? ''));
$ticketNumber = (string) ($ticket['ticket_number'] ?? '');
$customerName = (string) ($ticket['customer_name'] ?? 'Customer');

$lines = [
    'Hello ' . $customerName . ',',
    '',
    'We replied to your support ticket ' . $ticketNumber . '.',
    '',
    $reply,
];
if ($ticketUrl !== '') {
    $lines[] = '';
    $lines[] = 'View and reply: ' . $ticketUrl;
}
$lines[] = '';
$lines[] = 'Regards,';
$lines[] = site_name();

return [
    'subject' => '[' . site_name() . '] Reply to support ticket ' . $ticketNumber,
    'body' => implode("\n", $lines),
];
