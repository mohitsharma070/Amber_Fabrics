<?php
/**
 * Build normalized SKU from category, material, color, GSM.
 */
function build_fabric_sku_base(string $category, string $material, string $color, string $gsm): string
{
    $parts = [$category, $material, $color, $gsm];
    $clean = [];
    foreach ($parts as $part) {
        $p = strtoupper(trim($part));
        $p = preg_replace('/[^A-Z0-9]+/', '-', $p ?? '');
        $p = trim((string) $p, '-');
        if ($p !== '') {
            $clean[] = $p;
        }
    }
    if (empty($clean)) {
        return 'SKU';
    }
    return implode('-', $clean);
}

/**
 * Generate a unique fabrics.sku value by appending -2, -3... when needed.
 */
function generate_unique_fabric_sku(mysqli $conn, string $category, string $material, string $color, string $gsm, int $excludeId = 0): string
{
    $base = build_fabric_sku_base($category, $material, $color, $gsm);
    $candidate = $base;
    $n = 1;

    while (true) {
        if ($excludeId > 0) {
            $stmt = $conn->prepare("SELECT id FROM fabrics WHERE sku = ? AND id <> ? LIMIT 1");
            $stmt->bind_param('si', $candidate, $excludeId);
        } else {
            $stmt = $conn->prepare("SELECT id FROM fabrics WHERE sku = ? LIMIT 1");
            $stmt->bind_param('s', $candidate);
        }
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        if (!$row) {
            return $candidate;
        }
        $n++;
        $candidate = $base . '-' . $n;
    }
}

/**
 * Send a basic email notification for new inquiry submissions.
 */
function send_inquiry_notification(array $inquiry): bool
{
    $to = admin_notification_email();
    if ($to === '') {
        return false;
    }

    $template = email_template_build('inquiry_notification', $inquiry);

    $replyTo = filter_var($inquiry['email'] ?? '', FILTER_VALIDATE_EMAIL)
        ? (string) $inquiry['email']
        : '';

    try {
        $mail = EmailService::_mailer_base();
        $mail->addAddress($to);
        if ($replyTo !== '') {
            $mail->addReplyTo($replyTo);
        }
        $mail->Subject = $template['subject'];
        $mail->Body    = $template['body'];
        $mail->send();
        return true;
    } catch (Throwable $e) {
        error_log('[app] inquiry notification email failed: ' . $e->getMessage());
        return false;
    }
}

/**
 * Persist inquiry activity entries for audit and follow-up context.
 */
function log_inquiry_activity(
    mysqli $conn,
    int $inquiryId,
    string $action,
    ?int $adminId = null,
    string $actorName = 'system',
    string $details = ''
): void {
    try {
        $stmt = $conn->prepare(
            "INSERT INTO inquiry_activity_logs (inquiry_id, admin_id, actor_name, action, details)
             VALUES (?, ?, ?, ?, ?)"
        );
        $stmt->bind_param('iisss', $inquiryId, $adminId, $actorName, $action, $details);
        $stmt->execute();
    } catch (Throwable $e) {
        error_log('[fabric-export] inquiry activity log failed: ' . $e->getMessage());
    }
}

/**
 * Persist order lifecycle events for auditability.
 */
function log_order_activity(
    mysqli $conn,
    int $orderId,
    string $action,
    string $actorType = 'system',
    int $actorId = 0,
    string $actorName = '',
    string $details = ''
): void {
    try {
        $stmt = $conn->prepare(
            "INSERT INTO order_activity_logs (order_id, action, actor_type, actor_id, actor_name, details)
             VALUES (?, ?, ?, ?, ?, ?)"
        );
        $stmt->bind_param('ississ', $orderId, $action, $actorType, $actorId, $actorName, $details);
        $stmt->execute();
    } catch (Throwable $e) {
        error_log('[app] order activity log failed: ' . $e->getMessage());
    }
}

/**
 * Persist refund transactions to keep a real ledger.
 */
function log_refund_ledger(
    mysqli $conn,
    int $orderId,
    int $paymentId,
    float $amount,
    string $currency = 'INR',
    string $status = 'initiated',
    string $gateway = '',
    string $gatewayRefundId = '',
    string $notes = ''
): void {
    try {
        $stmt = $conn->prepare(
            "INSERT INTO refund_ledger (order_id, payment_id, amount, currency, status, gateway, gateway_refund_id, notes)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)"
        );
        $stmt->bind_param('iidsssss', $orderId, $paymentId, $amount, $currency, $status, $gateway, $gatewayRefundId, $notes);
        $stmt->execute();
    } catch (Throwable $e) {
        error_log('[app] refund ledger log failed: ' . $e->getMessage());
    }
}

function order_coupon_code_from_activity(mysqli $conn, int $orderId): string
{
    if ($orderId <= 0) {
        return '';
    }
    try {
        $stmt = $conn->prepare(
            "SELECT details
             FROM order_activity_logs
             WHERE order_id = ? AND action = 'coupon_applied'
             ORDER BY id DESC
             LIMIT 1"
        );
        $stmt->bind_param('i', $orderId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $details = (string) ($row['details'] ?? '');
        if ($details !== '' && preg_match('/Coupon code:\s*([A-Z0-9_-]+)/i', $details, $m)) {
            return strtoupper(trim((string) ($m[1] ?? '')));
        }
    } catch (Throwable $e) {
        error_log('[app] order coupon activity read failed: ' . $e->getMessage());
    }
    return '';
}

