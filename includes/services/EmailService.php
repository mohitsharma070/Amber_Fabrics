<?php

final class EmailService
{
    public static function mark_account_activation_requested(mysqli $conn, int $orderId): void
    {
        if ($orderId <= 0) {
            throw new InvalidArgumentException('Invalid order for account activation request.');
        }
        $stmt = $conn->prepare(
            "UPDATE orders
             SET account_activation_requested = 1,
                 account_activation_sent_at = NULL,
                 activation_email_status = 'pending',
                 activation_email_claimed_at = NULL,
                 activation_email_claim_token = NULL,
                 activation_email_last_error = NULL
             WHERE id = ? AND customer_id IS NULL"
        );
        $stmt->bind_param('i', $orderId);
        $stmt->execute();
    }

    public static function send_requested_account_activation_email(mysqli $conn, int $orderId): bool
    {
        if ($orderId <= 0) {
            return false;
        }

        // Claim the side effect atomically. A stale processing lease can be
        // reclaimed after a crashed PHP request or transport timeout.
        $claimToken = bin2hex(random_bytes(16));
        $claim = $conn->prepare(
            "UPDATE orders
             SET activation_email_status = 'processing',
                 activation_email_claimed_at = NOW(),
                 activation_email_claim_token = ?,
                 activation_email_attempts = activation_email_attempts + 1,
                 activation_email_last_error = NULL
             WHERE id = ? AND customer_id IS NULL
               AND account_activation_requested = 1
               AND account_activation_sent_at IS NULL
               AND (
                    activation_email_status IN ('pending','failed')
                    OR (activation_email_status = 'processing'
                        AND activation_email_claimed_at < DATE_SUB(NOW(), INTERVAL 15 MINUTE))
               )"
        );
        $claim->bind_param('si', $claimToken, $orderId);
        $claim->execute();
        if ($claim->affected_rows !== 1) {
            return false;
        }

        $sent = self::send_account_activation_email($conn, $orderId);
        $status = $sent ? 'sent' : 'failed';
        $error = $sent ? null : 'Activation email delivery failed.';
        $complete = $conn->prepare(
            "UPDATE orders
             SET activation_email_status = ?,
                 activation_email_claimed_at = NULL,
                 activation_email_claim_token = NULL,
                 activation_email_last_error = ?,
                 account_activation_sent_at = CASE WHEN ? = 'sent' THEN NOW() ELSE NULL END
             WHERE id = ? AND customer_id IS NULL
               AND activation_email_status = 'processing'
               AND activation_email_claim_token = ?"
        );
        $complete->bind_param('sssis', $status, $error, $status, $orderId, $claimToken);
        $complete->execute();
        return $sent;
    }

    private static function applyTemplate(PHPMailer\PHPMailer\PHPMailer $mail, array $template): void
    {
        $mail->Subject=(string)($template['subject']??'');$html=(string)($template['html_body']??'');$text=(string)($template['text_body']??$template['body']??'');
        if($html!==''){$mail->isHTML(true);$mail->Body=$html;$mail->AltBody=$text;}else{$mail->Body=$text;}
    }

    public static function send_guest_manage_link(mysqli $conn, int $orderId): bool
    {
        $stmt=$conn->prepare("SELECT order_number,customer_name,customer_email FROM orders WHERE id=? LIMIT 1");$stmt->bind_param('i',$orderId);$stmt->execute();$o=$stmt->get_result()->fetch_assoc();if(!$o||!filter_var($o['customer_email']??'',FILTER_VALIDATE_EMAIL)){return false;}
        try{$token=OrderAccessService::createToken($conn,$orderId,'manage');$t=email_template_build('guest_order_manage',['name'=>$o['customer_name'],'order_number'=>$o['order_number'],'manage_url'=>app_url('/guest/order-auth?token='.urlencode($token))]);$mail=self::_mailer_base();$mail->addAddress($o['customer_email'],$o['customer_name']);self::applyTemplate($mail,$t);return self::deliver($mail);}catch(Throwable $e){error_log('[email] guest manage link failed: '.$e->getMessage());return false;}
    }

    public static function send_account_activation_email(mysqli $conn, int $orderId): bool
    {
        $stmt=$conn->prepare("SELECT customer_name,customer_email FROM orders WHERE id=? LIMIT 1");$stmt->bind_param('i',$orderId);$stmt->execute();$o=$stmt->get_result()->fetch_assoc();if(!$o||!filter_var($o['customer_email']??'',FILTER_VALIDATE_EMAIL)){return false;}
        $exists=$conn->prepare("SELECT id FROM customers WHERE LOWER(TRIM(email))=LOWER(TRIM(?)) LIMIT 1");$exists->bind_param('s',$o['customer_email']);$exists->execute();$customer=$exists->get_result()->fetch_assoc();if($customer){$raw=bin2hex(random_bytes(32));$hash=hash('sha256',$raw);$expires=gmdate('Y-m-d H:i:s',time()+3600);$upd=$conn->prepare("UPDATE customers SET reset_token=?,reset_token_expires=? WHERE id=?");$cid=(int)$customer['id'];$upd->bind_param('ssi',$hash,$expires,$cid);$upd->execute();return self::send_customer_password_reset_email($o['customer_email'],$raw);}
        try{$token=OrderAccessService::createToken($conn,$orderId,'activate');$t=email_template_build('account_activation',['name'=>$o['customer_name'],'activation_url'=>app_url('/guest/account-activate?token='.urlencode($token))]);$mail=self::_mailer_base();$mail->addAddress($o['customer_email'],$o['customer_name']);self::applyTemplate($mail,$t);return self::deliver($mail);}catch(Throwable $e){error_log('[email] account activation failed: '.$e->getMessage());return false;}
    }
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
            "SELECT o.*, c.name AS account_name, c.email AS account_email
             FROM orders o
             LEFT JOIN customers c ON c.id = o.customer_id
             WHERE o.id = ?"
        );
        $row->bind_param('i', $orderId);
        $row->execute();
        $order = $row->get_result()->fetch_assoc();
        if (!$order) { return false; }

        // Guest orders intentionally have customer_id = NULL. Their checkout
        // identity is stored on the order and must remain sufficient for
        // transactional emails. Account details are only a legacy fallback.
        $recipientName = trim((string) ($order['customer_name'] ?? ''));
        if ($recipientName === '') {
            $recipientName = trim((string) ($order['account_name'] ?? ''));
        }
        $recipientEmail = trim((string) ($order['customer_email'] ?? ''));
        if ($recipientEmail === '') {
            $recipientEmail = trim((string) ($order['account_email'] ?? ''));
        }
        if (!filter_var($recipientEmail, FILTER_VALIDATE_EMAIL)) {
            error_log('[app] order confirmation email skipped: order has no valid recipient email.');
            return false;
        }

        $iStmt = $conn->prepare(
            "SELECT unit_type, fabric_name_snapshot, quantity, quantity_meters, price, price_per_meter, total, line_total
             FROM order_items WHERE order_id = ?"
        );
        $iStmt->bind_param('i', $orderId);
        $iStmt->execute();
        $items = $iStmt->get_result()->fetch_all(MYSQLI_ASSOC);

        $currency = (string) ($order['currency'] ?? 'INR');
        $subtotalAmount = (float) ($order['subtotal'] ?? 0);
        $shippingAmount = (float) ($order['shipping_amount'] ?? 0);
        $discountAmount = (float) (($order['discount_amount'] ?? null) !== null ? $order['discount_amount'] : ($order['coupon_discount'] ?? 0));
        $totalAmount = (float) ($order['total_amount'] ?? 0);
        $isPaid = strtolower((string) ($order['payment_status'] ?? '')) === 'paid';
        $paymentMethodLabel = strtoupper((string) ($order['payment_method'] ?? ''));
        $lines = [
            'Dear ' . ($recipientName !== '' ? $recipientName : 'Customer') . ',',
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
                . CommercePresenter::quantityUnitSuffix($unitType) . ' x '
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
        $manageUrl=(int)($order['customer_id']??0)>0?app_url('/customer/orders'):app_url('/guest/order-access');
        if((int)($order['customer_id']??0)<=0){try{$manageUrl=app_url('/guest/order-auth?token='.urlencode(OrderAccessService::createToken($conn,$orderId,'manage')));}catch(Throwable $ignored){}}
        $template = email_template_build('order_confirmation', [
            'order_number' => (string) $order['order_number'],
            'lines' => $lines,
            'manage_url' => $manageUrl,
        ]);

        try {
            $mail = EmailService::_mailer_base();
            $mail->addAddress($recipientEmail, $recipientName);
            self::applyTemplate($mail, $template);
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
            "SELECT o.order_number, o.created_at, COALESCE(NULLIF(o.customer_name,''),c.name) AS cname, COALESCE(NULLIF(o.customer_email,''),c.email) AS cemail, o.customer_id
             FROM orders o LEFT JOIN customers c ON c.id = o.customer_id
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
            $trackingUrl = ExternalUrlPolicy::sanitize((string) ($shipment['tracking_url'] ?? ''));
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

        if ((int)($order['customer_id']??0)>0) { $lines[]='Manage your order: '.app_url('/customer/orders'); }
        else { try { $lines[]='Manage your order: '.app_url('/guest/order-auth?token='.urlencode(OrderAccessService::createToken($conn,$orderId,'manage'))); } catch(Throwable $ignored) { $lines[]='Visit our website for order support.'; } }
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
            self::applyTemplate($mail, $template);
            self::deliver($mail);
            return true;
        } catch (Throwable $e) {
            error_log('[app] order status email failed: ' . $e->getMessage());
            return false;
        }
    }

    public static function send_return_status_update_email(mysqli $conn, int $returnId): bool
    {
        $stmt = $conn->prepare(
            "SELECT r.return_number, r.status, r.admin_note, r.refund_amount,
                    o.id order_id, o.order_number, o.customer_id,
                    COALESCE(NULLIF(o.customer_name,''), c.name) recipient_name,
                    COALESCE(NULLIF(o.customer_email,''), c.email) recipient_email
             FROM returns r JOIN orders o ON o.id=r.order_id
             LEFT JOIN customers c ON c.id=o.customer_id
             WHERE r.id=? LIMIT 1"
        );
        $stmt->bind_param('i', $returnId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        if (!$row || !filter_var((string) ($row['recipient_email'] ?? ''), FILTER_VALIDATE_EMAIL)) return false;
        $lines = [
            'Dear ' . ((string) ($row['recipient_name'] ?? '') ?: 'Customer') . ',', '',
            'Return ' . (string) $row['return_number'] . ' for order ' . (string) $row['order_number']
                . ' is now ' . strtoupper(str_replace('_', ' ', (string) $row['status'])) . '.',
        ];
        if (trim((string) ($row['admin_note'] ?? '')) !== '') $lines[] = 'Update: ' . trim((string) $row['admin_note']);
        if ((float) ($row['refund_amount'] ?? 0) > 0) $lines[] = 'Refund amount: ' . money((float) $row['refund_amount']);
        $lines[] = '';
        if ((int) ($row['customer_id'] ?? 0) > 0) {
            $lines[] = 'View your order: ' . app_url('/customer/order-view?id=' . (int) $row['order_id']);
        } else {
            try {
                $token = OrderAccessService::createToken($conn, (int) $row['order_id'], 'manage');
                $lines[] = 'View your order: ' . app_url('/guest/order-auth?token=' . urlencode($token));
            } catch (Throwable $ignored) {
                $lines[] = 'Visit our website to request a secure order access link.';
            }
        }
        $template = email_template_build('return_status_update', [
            'return_number' => (string) $row['return_number'], 'status' => (string) $row['status'], 'lines' => $lines,
        ]);
        try {
            $mail = self::_mailer_base();
            $mail->addAddress((string) $row['recipient_email'], (string) ($row['recipient_name'] ?? ''));
            self::applyTemplate($mail, $template);
            return self::deliver($mail);
        } catch (Throwable $e) {
            error_log('[email] return status update failed: ' . CronService::sanitizeError($e->getMessage()));
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
