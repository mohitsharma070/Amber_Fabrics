<?php
$siteName = SiteContext::name();
$subject = 'Password Reset - ' . $siteName;
$lines = [
    'Hi,',
    '',
    'We received a request to reset the password for your ' . $siteName . ' account.',
    '',
    'Click the link below to set a new password (valid for 1 hour):',
    (string) ($data['reset_url'] ?? ''),
    '',
    'If you did not request this, please ignore this email.',
    '',
    'Regards,',
    $siteName,
];
return ['subject' => $subject, 'body' => implode("\r\n", $lines)];
