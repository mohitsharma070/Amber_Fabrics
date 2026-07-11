<?php
$name = (string) ($data['name'] ?? 'Admin');
$otp = (string) ($data['otp'] ?? '');
$isResend = !empty($data['is_resend']);
$siteName = SiteContext::name();
$subject = $isResend
    ? $siteName . ' Admin Login OTP (Resend)'
    : $siteName . ' Admin Login OTP';
$lines = [
    'Hi ' . $name . ',',
    '',
    ($isResend ? 'Your new admin login OTP is: ' : 'Your admin login OTP is: ') . $otp,
    'It is valid for 5 minutes.',
    '',
    'If you did not request this OTP, ignore this email.',
    '',
    $siteName,
];
return ['subject' => $subject, 'body' => implode("\r\n", $lines)];
