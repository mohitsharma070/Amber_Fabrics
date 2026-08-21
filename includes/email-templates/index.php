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
        'abandoned_cart_reminder' => 'abandoned-cart-reminder.php',
        'back_in_stock_alert' => 'back-in-stock-alert.php',
        'return_status_update' => 'return-status-update.php',
        'support_ticket_created' => 'support-ticket-created.php',
        'support_ticket_reply_customer' => 'support-ticket-reply-customer.php',
        'support_ticket_reply_admin' => 'support-ticket-reply-admin.php',
        'guest_order_manage' => 'guest-order-manage.php',
        'account_activation' => 'account-activation.php',
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

    $text = (string) ($result['text_body'] ?? $result['body'] ?? '');
    return ['subject'=>(string)($result['subject']??''),'body'=>$text,'text_body'=>$text,'html_body'=>(string)($result['html_body']??'')];
}
