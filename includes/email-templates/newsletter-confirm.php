<?php
$name = trim((string) ($data['name'] ?? ''));
$confirmUrl = trim((string) ($data['confirm_url'] ?? ''));
$siteName = SiteContext::name();

$subject = 'Confirm your newsletter subscription - ' . $siteName;
$lines = [
    $name !== '' ? ('Hi ' . $name . ',') : 'Hello,',
    '',
    'Please confirm that you want to receive newsletter emails from ' . $siteName . '.',
    '',
];

if ($confirmUrl !== '') {
    $lines[] = 'Confirm subscription: ' . $confirmUrl;
    $lines[] = '';
}

$lines[] = 'If you did not request this, you can ignore this email.';
$lines[] = '';
$lines[] = 'Regards,';
$lines[] = $siteName;

return ['subject' => $subject, 'body' => implode("\r\n", $lines)];
