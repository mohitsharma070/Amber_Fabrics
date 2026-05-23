<?php
$name = (string) ($data['name'] ?? '');
$subject = 'Verify your email - Amber Fabrics';
$lines = [
    'Hi ' . $name . ',',
    '',
    'Thank you for registering with Amber Fabrics!',
    '',
    'Please verify your email address by clicking the link below (valid for 24 hours):',
    (string) ($data['verify_url'] ?? ''),
    '',
    'If you did not create an account, please ignore this email.',
    '',
    'Regards,',
    'Amber Fabrics',
];
return ['subject' => $subject, 'body' => implode("\r\n", $lines)];
