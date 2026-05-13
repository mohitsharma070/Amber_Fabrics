<?php
$name = (string) ($data['name'] ?? 'Admin');
$otp = (string) ($data['otp'] ?? '');
$isResend = !empty($data['is_resend']);
$subject = $isResend
    ? 'Amber Fabrics Admin Login OTP (Resend)'
    : 'Amber Fabrics Admin Login OTP';
$lines = [
    'Hi ' . $name . ',',
    '',
    ($isResend ? 'Your new admin login OTP is: ' : 'Your admin login OTP is: ') . $otp,
    'It is valid for 5 minutes.',
    '',
    'If you did not request this OTP, ignore this email.',
    '',
    'Amber Fabrics',
];
return ['subject' => $subject, 'body' => implode("\r\n", $lines)];
