<?php
$name = (string) ($data['name'] ?? '');
$siteName = SiteContext::name();
$subject = 'Verify your email - ' . $siteName;
$lines = [
    'Hi ' . $name . ',',
    '',
    'Thank you for registering with ' . $siteName . '!',
    '',
    'Please verify your email address by clicking the link below (valid for 24 hours):',
    (string) ($data['verify_url'] ?? ''),
    '',
    'If you did not create an account, please ignore this email.',
    '',
    'Regards,',
    $siteName,
];
return ['subject' => $subject, 'body' => implode("\r\n", $lines)];
