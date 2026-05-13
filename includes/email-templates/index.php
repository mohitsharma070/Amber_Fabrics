<?php

/**
 * Build plain-text email subject/body by template key.
 * Returns ['subject' => string, 'body' => string]
 */
function email_template_build(string $key, array $data = []): array
{
    $map = [
        'admin_login_otp' => 'admin-login-otp.php',
        'inquiry_notification' => 'inquiry-notification.php',
        'order_confirmation' => 'order-confirmation.php',
        'order_status_update' => 'order-status-update.php',
        'customer_password_reset' => 'customer-password-reset.php',
        'customer_email_verification' => 'customer-email-verification.php',
    ];

    $file = $map[$key] ?? '';
    if ($file === '') {
        return ['subject' => '', 'body' => ''];
    }

    $path = __DIR__ . '/' . $file;
    if (!is_file($path)) {
        return ['subject' => '', 'body' => ''];
    }

    $result = require $path;
    if (!is_array($result)) {
        return ['subject' => '', 'body' => ''];
    }

    return [
        'subject' => (string) ($result['subject'] ?? ''),
        'body' => (string) ($result['body'] ?? ''),
    ];
}
