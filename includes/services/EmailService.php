<?php

final class EmailService
{
    private static function deliver(PHPMailer\PHPMailer\PHPMailer $mail): bool
    {
        $driver = strtolower(trim(_cfg('MAIL_DRIVER', 'smtp')));
        if ($driver !== 'log') {
            $mail->send();
            return true;
        }

        $appMode = strtolower((string) ($GLOBALS['_app_mode'] ?? ''));
        if ($appMode !== 'local') {
            throw new RuntimeException('The log mail driver is allowed only in local mode.');
        }

        $mailDirectory = dirname(__DIR__, 2) . '/tmp';
        if (!is_dir($mailDirectory) && !mkdir($mailDirectory, 0700, true) && !is_dir($mailDirectory)) {
            throw new RuntimeException('Unable to create the local mail directory.');
        }

        $recipients = array_map(
            static fn(array $address): string => (string) ($address[0] ?? ''),
            $mail->getToAddresses()
        );
        $entry = implode(PHP_EOL, [
            str_repeat('=', 72),
            'Date: ' . date('Y-m-d H:i:s'),
            'To: ' . implode(', ', array_filter($recipients)),
            'Subject: ' . $mail->Subject,
            '',
            $mail->Body,
            '',
        ]);

        $written = file_put_contents($mailDirectory . '/local-mail.log', $entry, FILE_APPEND | LOCK_EX);
        if ($written === false) {
            throw new RuntimeException('Unable to write the local mail log.');
        }
        return true;
    }

    public static function _mailer_base(): PHPMailer\PHPMailer\PHPMailer
    {
        require_once __DIR__ . '/../../vendor/phpmailer/phpmailer/src/PHPMailer.php';
        require_once __DIR__ . '/../../vendor/phpmailer/phpmailer/src/SMTP.php';
        require_once __DIR__ . '/../../vendor/phpmailer/phpmailer/src/Exception.php';

        $mail = new PHPMailer\PHPMailer\PHPMailer(true);

        $driver = strtolower(trim(_cfg('MAIL_DRIVER', 'smtp')));

        if ($driver === 'log') {
            // Local delivery is handled by deliver(); no transport is needed.
        } elseif ($driver === 'mail') {
            // Use PHP's built-in mail() - required on hosts that block outbound SMTP
            // (e.g. InfinityFree). The host's sendmail handles delivery.
            $mail->isMail();
        } else {
            // Full SMTP (default) - for Gmail App Password, Mailgun, etc.
            $mail->isSMTP();
            $mail->Host       = _cfg('SMTP_HOST', 'smtp.gmail.com');
            $mail->SMTPAuth   = true;
            $mail->Username   = _cfg('MAIL_FROM');
            $mail->Password   = _cfg('SMTP_PASSWORD');
            $mail->Port       = (int) _cfg('SMTP_PORT', '587');
            // Port 465 = implicit SSL; anything else = STARTTLS
            $mail->SMTPSecure = $mail->Port === 465
                ? PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_SMTPS
                : PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
            $mail->Timeout    = 10; // fail fast instead of hanging 60 s
        }

        $fromAddress = _cfg('MAIL_FROM', contact_email());
        $mail->setFrom($fromAddress, site_name());
        $mail->CharSet = 'UTF-8';
        return $mail;
    }

    /**
     * Send order confirmation to the customer after order placement.
     */
    public static function send_order_confirmation_email(mysqli $conn, int $orderId): bool
    {
        $row = $conn->prepare(
            "SELECT o.*, c.name AS cname, c.email AS cemail
             FROM orders o JOIN customers c ON c.id = o.customer_id
             WHERE o.id = ?"
        );
        $row->bind_param('i', $orderId);
        $row->execute();
        $order = $row->get_result()->fetch_assoc();
        if (!$order) { return false; }

        $iStmt = $conn->prepare(
            "SELECT unit_type, fabric_name_snapshot, quantity, quantity_meters, price, price_per_meter, total, line_total
             FROM order_items WHERE order_id = ?"
        );
        $iStmt->bind_param('i', $orderId);
        $iStmt->execute();
        $items = $iStmt->get_result()->fetch_all(MYSQLI_ASSOC);

        $currency = (string) ($order['currency'] ?? 'INR');
        $subtotalAmount = (float) ($order['subtotal'] ?? 0);
        $shippingAmount = (float) (($order['shipping_amount'] ?? null) !== null ? $order['shipping_amount'] : ($order['shipping_cost'] ?? 0));
        $discountAmount = (float) (($order['discount_amount'] ?? null) !== null ? $order['discount_amount'] : ($order['coupon_discount'] ?? 0));
        $totalAmount = (float) (($order['total_amount'] ?? null) !== null ? $order['total_amount'] : ($order['total'] ?? 0));
        $isPaid = strtolower((string) ($order['payment_status'] ?? '')) === 'paid';
        $paymentMethodLabel = strtoupper((string) ($order['payment_method'] ?? ''));
        $lines = [
            'Dear ' . $order['cname'] . ',',
            '',
            'Thank you for your order. Your order has been received and is being processed.',
            $isPaid ? 'Payment Status: Paid' : ('Payment Status: Pending (' . $paymentMethodLabel . ')'),
            '',
            'Order Number: ' . $order['order_number'],
            'Date: ' . date('d M Y', strtotime($order['created_at'])),
            'Currency: ' . $order['currency'],
            '',
            'Items',
            '-----',
        ];
        foreach ($items as $it) {
            $unitType = in_array((string) ($it['unit_type'] ?? ''), ['meter', 'piece', 'set'], true) ? (string) $it['unit_type'] : 'meter';
            $qty = (($it['quantity'] ?? 0) > 0) ? $it['quantity'] : ($it['quantity_meters'] ?? 1);
            $unitPrice = (($it['price'] ?? 0) > 0) ? $it['price'] : ($it['price_per_meter'] ?? 0);
            $lineTotal = (($it['total'] ?? 0) > 0) ? $it['total'] : ($it['line_total'] ?? 0);
            $lines[] = '- ' . $it['fabric_name_snapshot'] . ' - ' . format_quantity_by_unit($qty, $unitType)
                . InventoryService::quantity_unit_suffix($unitType) . ' x '
                . money((float) $unitPrice, $currency)
                . (($unitType === 'piece' || $unitType === 'set') ? ' each = ' : '/m = ')
                . money((float) $lineTotal, $currency);
        }
        $lines[] = '';
        $lines[] = 'Summary';
        $lines[] = '-------';
        $lines[] = 'Subtotal: ' . money($subtotalAmount, $currency);
        if ($discountAmount > 0) {
            $lines[] = 'Discount: -' . money($discountAmount, $currency);
        }
        $lines[] = 'Shipping: ' . money($shippingAmount, $currency);
        $lines[] = 'Total: ' . money($totalAmount, $currency, true);
        $lines[] = '';
        $lines[] = 'We will notify you once your order is shipped.';
        $lines[] = '';
        $lines[] = 'Regards,';
        $lines[] = site_name();
        $template = email_template_build('order_confirmation', [
            'order_number' => (string) $order['order_number'],
            'lines' => $lines,
        ]);

        try {
            $mail = EmailService::_mailer_base();
            $mail->addAddress($order['cemail'], $order['cname']);
            $mail->Subject = $template['subject'];
            $mail->Body    = $template['body'];
            self::deliver($mail);
            return true;
        } catch (Throwable $e) {
            error_log('[app] order confirmation email failed: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Notify customer when admin changes order status.
     */
    public static function send_order_status_update_email(mysqli $conn, int $orderId, string $newStatus): bool
    {
        $row = $conn->prepare(
            "SELECT o.order_number, o.created_at, c.name AS cname, c.email AS cemail
             FROM orders o JOIN customers c ON c.id = o.customer_id
             WHERE o.id = ?"
        );
        $row->bind_param('i', $orderId);
        $row->execute();
        $order = $row->get_result()->fetch_assoc();
        if (!$order) { return false; }

        $statusLower = strtolower(trim($newStatus));
        $lines = [
            'Dear ' . $order['cname'] . ',',
            '',
            'Your order ' . $order['order_number'] . ' status has been updated to: ' . strtoupper($newStatus),
            '',
        ];

        if (in_array($statusLower, ['shipped', 'delivered'], true)) {
            $shipStmt = $conn->prepare(
                "SELECT courier_name,
                        COALESCE(NULLIF(tracking_id, ''), NULLIF(awb_code, ''), '') AS tracking_id,
                        tracking_url, shipped_at, delivered_at
                 FROM shipments
                 WHERE order_id = ?
                 LIMIT 1"
            );
            $shipStmt->bind_param('i', $orderId);
            $shipStmt->execute();
            $shipment = $shipStmt->get_result()->fetch_assoc() ?: [];

            $courier = trim((string) ($shipment['courier_name'] ?? ''));
            $trackingId = trim((string) ($shipment['tracking_id'] ?? ''));
            $trackingUrl = InventoryService::safe_external_url((string) ($shipment['tracking_url'] ?? ''));
            $shippedAt = trim((string) ($shipment['shipped_at'] ?? ''));
            $deliveredAt = trim((string) ($shipment['delivered_at'] ?? ''));

            if ($courier !== '' || $trackingId !== '' || $trackingUrl !== '' || $shippedAt !== '' || $deliveredAt !== '') {
                $lines[] = 'Shipment Details:';
                if ($courier !== '') { $lines[] = 'Courier: ' . $courier; }
                if ($trackingId !== '') { $lines[] = 'Tracking ID: ' . $trackingId; }
                if ($trackingUrl !== '') { $lines[] = 'Tracking URL: ' . $trackingUrl; }
                if ($shippedAt !== '') { $lines[] = 'Shipped At: ' . $shippedAt; }
                if ($deliveredAt !== '') { $lines[] = 'Delivered At: ' . $deliveredAt; }
                $lines[] = '';
            }
        }

        $lines[] = 'Log in to your account to view full order details.';
        $lines[] = '';
        $lines[] = 'Regards,';
        $lines[] = site_name();
        $template = email_template_build('order_status_update', [
            'order_number' => (string) $order['order_number'],
            'new_status' => $newStatus,
            'lines' => $lines,
        ]);

        try {
            $mail = EmailService::_mailer_base();
            $mail->addAddress($order['cemail'], $order['cname']);
            $mail->Subject = $template['subject'];
            $mail->Body    = $template['body'];
            self::deliver($mail);
            return true;
        } catch (Throwable $e) {
            error_log('[app] order status email failed: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Send password reset link to a customer.
     */
    public static function send_customer_password_reset_email(string $email, string $token): bool
    {
        $resetUrl = app_url('/customer/reset-password?token=' . urlencode($token));

        $template = email_template_build('customer_password_reset', ['reset_url' => $resetUrl]);

        try {
            $mail = EmailService::_mailer_base();
            $mail->addAddress($email);
            $mail->Subject = $template['subject'];
            $mail->Body    = $template['body'];
            self::deliver($mail);
            return true;
        } catch (Throwable $e) {
            error_log('[app] password reset email failed: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Send email address verification link to a newly registered customer.
     */
    public static function send_customer_verification_email(string $email, string $name, string $token): bool
    {
        $verifyUrl = app_url('/customer/verify-email?token=' . urlencode($token));

        $template = email_template_build('customer_email_verification', [
            'name' => $name,
            'verify_url' => $verifyUrl,
        ]);

        try {
            $mail = EmailService::_mailer_base();
            $mail->addAddress($email, $name);
            $mail->Subject = $template['subject'];
            $mail->Body    = $template['body'];
            self::deliver($mail);
            return true;
        } catch (Throwable $e) {
            error_log('[app] verification email failed: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Send admin login OTP email (initial + resend) using shared template.
     */
    public static function send_admin_login_otp_email(string $email, string $name, string $otp, bool $isResend = false): bool
    {
        $template = email_template_build('admin_login_otp', [
            'name' => $name,
            'otp' => $otp,
            'is_resend' => $isResend,
        ]);

        try {
            $mail = EmailService::_mailer_base();
            $mail->addAddress($email, $name);
            $mail->Subject = $template['subject'];
            $mail->Body = $template['body'];
            self::deliver($mail);
            return true;
        } catch (Throwable $e) {
            error_log('[app] admin otp email send failed: ' . $e->getMessage());
            return false;
        }
    }
}
