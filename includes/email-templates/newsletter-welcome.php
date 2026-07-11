<?php
$name = trim((string) ($data['name'] ?? ''));
$unsubscribeUrl = trim((string) ($data['unsubscribe_url'] ?? ''));
$siteName = SiteContext::name();

$subject = 'Welcome to ' . $siteName . ' updates';
$lines = [
    $name !== '' ? ('Hi ' . $name . ',') : 'Hello,',
    '',
    'Thank you for subscribing to ' . $siteName . ' updates.',
    '',
    'We will send occasional fabric, catalog, and store updates to this email address.',
    '',
];

if ($unsubscribeUrl !== '') {
    $lines[] = 'Unsubscribe: ' . $unsubscribeUrl;
    $lines[] = '';
}

$lines[] = 'Regards,';
$lines[] = $siteName;

return ['subject' => $subject, 'body' => implode("\r\n", $lines)];
