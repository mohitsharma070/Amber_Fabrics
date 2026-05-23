<?php
$subject = 'Password Reset - Amber Fabrics';
$lines = [
    'Hi,',
    '',
    'We received a request to reset the password for your Amber Fabrics account.',
    '',
    'Click the link below to set a new password (valid for 1 hour):',
    (string) ($data['reset_url'] ?? ''),
    '',
    'If you did not request this, please ignore this email.',
    '',
    'Regards,',
    'Amber Fabrics',
];
return ['subject' => $subject, 'body' => implode("\r\n", $lines)];
