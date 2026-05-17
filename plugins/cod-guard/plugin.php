<?php
require_once __DIR__ . '/../../includes/coupon-functions.php';

add_action('order.after_create', 'cod_guard_after_order_create', 10);
add_action('order.after_commit', 'cod_guard_send_confirmation_after_commit', 10);
add_action('admin.order_view.sidebar', 'cod_guard_render_admin_panel', 10);
add_filter('admin.order_action.handled', 'cod_guard_handle_admin_action', 10);
add_action('cron.tick', 'cod_guard_send_pending_confirmation_messages', 5);
add_action('cron.tick', 'cod_guard_auto_cancel_expired', 10);

function cod_guard_settings(): array
{
    return [
        'whatsapp_threshold' => (float) plugin_setting('cod-guard', 'whatsapp_threshold', 1000),
        'call_threshold' => (float) plugin_setting('cod-guard', 'call_threshold', 2000),
        'confirmation_hours' => max(1, (int) plugin_setting('cod-guard', 'confirmation_hours', 24)),
        'message_max_attempts' => max(1, (int) plugin_setting('cod-guard', 'message_max_attempts', 3)),
        'whatsapp_provider' => strtolower(trim((string) plugin_setting('cod-guard', 'whatsapp_provider', 'whatsapp_cloud'))),
        'whatsapp_api_base_url' => rtrim((string) plugin_setting('cod-guard', 'whatsapp_api_base_url', 'https://graph.facebook.com/v21.0'), '/'),
        'whatsapp_phone_number_id' => trim((string) plugin_setting('cod-guard', 'whatsapp_phone_number_id', '')),
        'whatsapp_access_token' => trim((string) plugin_setting('cod-guard', 'whatsapp_access_token', '')),
        'whatsapp_template_name' => trim((string) plugin_setting('cod-guard', 'whatsapp_template_name', '')),
        'whatsapp_template_language' => trim((string) plugin_setting('cod-guard', 'whatsapp_template_language', 'en')),
        'whatsapp_app_secret' => trim((string) plugin_setting('cod-guard', 'whatsapp_app_secret', '')),
        'webhook_verify_token' => trim((string) plugin_setting('cod-guard', 'webhook_verify_token', '')),
        'webhook_auth_token' => trim((string) plugin_setting('cod-guard', 'webhook_auth_token', '')),
    ];
}

function cod_guard_table_ready(mysqli $conn): bool
{
    try {
        $stmt = $conn->prepare(
            "SELECT COUNT(*) AS total
             FROM information_schema.TABLES
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = 'cod_confirmations'"
        );
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        return ((int) ($row['total'] ?? 0)) > 0;
    } catch (Throwable $e) {
        error_log('[cod-guard] table check failed: ' . $e->getMessage());
        return false;
    }
}

function cod_guard_columns(mysqli $conn): array
{
    static $cache = [];
    $key = spl_object_id($conn);
    if (isset($cache[$key])) {
        return $cache[$key];
    }

    $columns = [];
    try {
        $res = $conn->query(
            "SELECT COLUMN_NAME
             FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = 'cod_confirmations'"
        );
        while ($row = $res ? $res->fetch_assoc() : null) {
            $columns[(string) $row['COLUMN_NAME']] = true;
        }
    } catch (Throwable $e) {
        error_log('[cod-guard] column check failed: ' . $e->getMessage());
    }

    $cache[$key] = $columns;
    return $columns;
}

function cod_guard_has_column(mysqli $conn, string $column): bool
{
    $columns = cod_guard_columns($conn);
    return !empty($columns[$column]);
}

function cod_guard_message_tracking_ready(mysqli $conn): bool
{
    return cod_guard_has_column($conn, 'message_status')
        && cod_guard_has_column($conn, 'message_attempts')
        && cod_guard_has_column($conn, 'message_sent_at');
}

function cod_guard_plan_for_amount(float $amount): array
{
    $settings = cod_guard_settings();
    if ($amount >= $settings['call_threshold']) {
        return ['channel' => 'call', 'status' => 'pending', 'action' => 'call_confirmation'];
    }
    if ($amount >= $settings['whatsapp_threshold']) {
        return ['channel' => 'whatsapp', 'status' => 'pending', 'action' => 'whatsapp_confirmation'];
    }
    return ['channel' => 'auto', 'status' => 'confirmed', 'action' => 'auto_confirmed'];
}

function cod_guard_queue_confirmation_message(mysqli $conn, int $orderId): void
{
    if ($orderId <= 0 || !cod_guard_message_tracking_ready($conn)) {
        return;
    }

    $provider = 'whatsapp_cloud';
    $responseToken = bin2hex(random_bytes(16));
    $stmt = $conn->prepare(
        "UPDATE cod_confirmations
         SET response_token = COALESCE(response_token, ?),
             message_provider = ?,
             message_status = 'queued',
             message_error = NULL,
             updated_at = NOW()
         WHERE order_id = ?
           AND status = 'pending'
           AND channel IN ('whatsapp','call')"
    );
    $stmt->bind_param('ssi', $responseToken, $provider, $orderId);
    $stmt->execute();
}

function cod_guard_after_order_create(array $context): void
{
    $conn = $context['conn'] ?? null;
    if (!$conn instanceof mysqli || !cod_guard_table_ready($conn)) {
        return;
    }

    $paymentMethod = strtolower((string) ($context['payment_method'] ?? ''));
    if ($paymentMethod !== 'cod') {
        return;
    }

    $orderId = (int) ($context['order_id'] ?? 0);
    $totalAmount = (float) ($context['total_amount'] ?? 0);
    if ($orderId <= 0) {
        return;
    }

    $plan = cod_guard_plan_for_amount($totalAmount);
    $settings = cod_guard_settings();
    $deadline = date('Y-m-d H:i:s', time() + ($settings['confirmation_hours'] * 3600));

    $stmt = $conn->prepare(
        "INSERT INTO cod_confirmations
            (order_id, channel, status, deadline_at, notes, confirmed_at)
         VALUES (?, ?, ?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE
            channel = VALUES(channel),
            status = VALUES(status),
            deadline_at = VALUES(deadline_at),
            notes = VALUES(notes),
            confirmed_at = VALUES(confirmed_at),
            updated_at = NOW()"
    );
    $notes = $plan['channel'] === 'auto'
        ? 'Auto-confirmed because COD order amount is within low-risk threshold.'
        : ($plan['channel'] === 'call'
            ? 'Awaiting customer message confirmation before dispatch. High-value COD order may also need a call.'
            : 'Awaiting customer message confirmation before dispatch.');
    $confirmedAt = $plan['status'] === 'confirmed' ? date('Y-m-d H:i:s') : null;
    $stmt->bind_param('isssss', $orderId, $plan['channel'], $plan['status'], $deadline, $notes, $confirmedAt);
    $stmt->execute();

    if ($plan['status'] === 'confirmed') {
        $upd = $conn->prepare("UPDATE orders SET order_status = 'confirmed', status = 'confirmed', updated_at = NOW() WHERE id = ? AND order_status = 'pending'");
        $upd->bind_param('i', $orderId);
        $upd->execute();
    } else {
        cod_guard_queue_confirmation_message($conn, $orderId);
    }

    if (function_exists('log_order_activity')) {
        log_order_activity($conn, $orderId, 'cod_guard_' . $plan['action'], 'system', 0, 'cod-guard', $notes);
    }
}

function cod_guard_get_confirmation(mysqli $conn, int $orderId): ?array
{
    if ($orderId <= 0 || !cod_guard_table_ready($conn)) {
        return null;
    }
    $stmt = $conn->prepare("SELECT * FROM cod_confirmations WHERE order_id = ? LIMIT 1");
    $stmt->bind_param('i', $orderId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    return $row ?: null;
}

function cod_guard_label(array $row): string
{
    $channel = ucfirst((string) ($row['channel'] ?? ''));
    $status = ucfirst(str_replace('_', ' ', (string) ($row['status'] ?? '')));
    return trim($channel . ' / ' . $status, ' /');
}

function cod_guard_whatsapp_configured(array $settings): bool
{
    return ($settings['whatsapp_provider'] ?? '') === 'whatsapp_cloud'
        && trim((string) ($settings['whatsapp_api_base_url'] ?? '')) !== ''
        && trim((string) ($settings['whatsapp_phone_number_id'] ?? '')) !== ''
        && trim((string) ($settings['whatsapp_access_token'] ?? '')) !== '';
}

function cod_guard_phone_for_whatsapp(string $phone): string
{
    $digits = preg_replace('/\D+/', '', $phone);
    $digits = is_string($digits) ? ltrim($digits, '0') : '';
    if (strlen($digits) === 10) {
        return '91' . $digits;
    }
    return $digits;
}

function cod_guard_phone_key(string $phone): string
{
    $digits = preg_replace('/\D+/', '', $phone);
    $digits = is_string($digits) ? ltrim($digits, '0') : '';
    if (strlen($digits) < 10) {
        return $digits;
    }
    return substr($digits, -10);
}

function cod_guard_order_for_message(mysqli $conn, int $orderId): ?array
{
    $stmt = $conn->prepare(
        "SELECT cc.*, o.order_number, o.customer_name, o.customer_phone, o.customer_email,
                o.total_amount, o.payment_method, o.order_status
         FROM cod_confirmations cc
         JOIN orders o ON o.id = cc.order_id
         WHERE cc.order_id = ?
         LIMIT 1"
    );
    $stmt->bind_param('i', $orderId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    return $row ?: null;
}

function cod_guard_build_confirmation_message(array $row): string
{
    $amount = number_format((float) ($row['total_amount'] ?? 0), 2);
    $orderNumber = (string) ($row['order_number'] ?? '');
    $lines = [
        'Amber Fabrics COD confirmation',
        'Order: ' . $orderNumber,
        'Amount: Rs ' . $amount,
        'Reply YES ' . $orderNumber . ' to confirm this order.',
        'Reply NO ' . $orderNumber . ' to cancel it.',
    ];

    if (strtolower((string) ($row['channel'] ?? '')) === 'call') {
        $lines[] = 'This is a high-value COD order, so our team may also call before dispatch.';
    }

    return implode("\n", $lines);
}

function cod_guard_whatsapp_payload(array $settings, string $to, string $message, array $row): array
{
    $templateName = trim((string) ($settings['whatsapp_template_name'] ?? ''));
    if ($templateName !== '') {
        return [
            'messaging_product' => 'whatsapp',
            'to' => $to,
            'type' => 'template',
            'template' => [
                'name' => $templateName,
                'language' => [
                    'code' => trim((string) ($settings['whatsapp_template_language'] ?? 'en')) ?: 'en',
                ],
                'components' => [
                    [
                        'type' => 'body',
                        'parameters' => [
                            ['type' => 'text', 'text' => (string) ($row['customer_name'] ?? 'Customer')],
                            ['type' => 'text', 'text' => (string) ($row['order_number'] ?? '')],
                            ['type' => 'text', 'text' => 'Rs ' . number_format((float) ($row['total_amount'] ?? 0), 2)],
                        ],
                    ],
                ],
            ],
        ];
    }

    return [
        'messaging_product' => 'whatsapp',
        'to' => $to,
        'type' => 'text',
        'text' => [
            'preview_url' => false,
            'body' => $message,
        ],
    ];
}

function cod_guard_whatsapp_text_payload(string $to, string $message): array
{
    return [
        'messaging_product' => 'whatsapp',
        'to' => $to,
        'type' => 'text',
        'text' => [
            'preview_url' => false,
            'body' => $message,
        ],
    ];
}

function cod_guard_http_post_json(string $url, array $headers, array $payload): array
{
    if (!function_exists('curl_init')) {
        return ['ok' => false, 'status' => 0, 'body' => null, 'error' => 'cURL is unavailable'];
    }

    $json = json_encode($payload);
    if (!is_string($json)) {
        return ['ok' => false, 'status' => 0, 'body' => null, 'error' => 'Unable to encode request payload'];
    }

    $ch = curl_init($url);
    if ($ch === false) {
        return ['ok' => false, 'status' => 0, 'body' => null, 'error' => 'Unable to initialize cURL'];
    }

    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 20,
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => array_merge(['Content-Type: application/json', 'Accept: application/json'], $headers),
        CURLOPT_POSTFIELDS => $json,
    ]);

    $raw = curl_exec($ch);
    $errno = curl_errno($ch);
    $err = curl_error($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);

    $body = json_decode((string) $raw, true);
    return [
        'ok' => $errno === 0 && $status >= 200 && $status < 300,
        'status' => $status,
        'body' => is_array($body) ? $body : null,
        'error' => $errno !== 0 ? $err : ($status >= 200 && $status < 300 ? '' : ('HTTP ' . $status)),
    ];
}

function cod_guard_mark_message_not_configured(mysqli $conn, int $orderId, string $reason): void
{
    if (!cod_guard_message_tracking_ready($conn)) {
        return;
    }
    $stmt = $conn->prepare(
        "UPDATE cod_confirmations
         SET message_status = 'not_configured',
             message_error = ?,
             updated_at = NOW()
         WHERE order_id = ?"
    );
    $stmt->bind_param('si', $reason, $orderId);
    $stmt->execute();
}

function cod_guard_mark_message_failed(mysqli $conn, int $orderId, string $reason): void
{
    if (!cod_guard_message_tracking_ready($conn)) {
        return;
    }
    $reason = substr($reason, 0, 1000);
    $stmt = $conn->prepare(
        "UPDATE cod_confirmations
         SET message_status = 'failed',
             message_error = ?,
             message_attempts = message_attempts + 1,
             updated_at = NOW()
         WHERE order_id = ?"
    );
    $stmt->bind_param('si', $reason, $orderId);
    $stmt->execute();
}

function cod_guard_mark_message_sent(mysqli $conn, int $orderId, string $messageId): void
{
    if (!cod_guard_message_tracking_ready($conn)) {
        return;
    }
    $stmt = $conn->prepare(
        "UPDATE cod_confirmations
         SET message_status = 'sent',
             message_id = ?,
             message_error = NULL,
             message_sent_at = COALESCE(message_sent_at, NOW()),
             message_attempts = message_attempts + 1,
             updated_at = NOW()
         WHERE order_id = ?"
    );
    $stmt->bind_param('si', $messageId, $orderId);
    $stmt->execute();
}

function cod_guard_send_confirmation_message(mysqli $conn, int $orderId): array
{
    if ($orderId <= 0 || !cod_guard_message_tracking_ready($conn)) {
        return ['ok' => false, 'message' => 'COD message tracking is not ready. Run the latest COD Guard migration.'];
    }

    $settings = cod_guard_settings();
    $provider = 'whatsapp_cloud';
    $maxAttempts = (int) ($settings['message_max_attempts'] ?? 3);

    $claim = $conn->prepare(
        "UPDATE cod_confirmations cc
         JOIN orders o ON o.id = cc.order_id
         SET cc.message_provider = ?,
             cc.message_status = 'sending',
             cc.updated_at = NOW()
         WHERE cc.order_id = ?
           AND cc.status = 'pending'
           AND cc.channel IN ('whatsapp','call')
           AND o.payment_method = 'cod'
           AND o.order_status = 'pending'
           AND (cc.message_status IS NULL OR cc.message_status IN ('queued','failed','not_configured'))
           AND cc.message_attempts < ?"
    );
    $claim->bind_param('sii', $provider, $orderId, $maxAttempts);
    $claim->execute();
    if ((int) $claim->affected_rows <= 0) {
        return ['ok' => false, 'message' => 'No queued COD confirmation message found.'];
    }

    $row = cod_guard_order_for_message($conn, $orderId);
    if (!$row) {
        cod_guard_mark_message_failed($conn, $orderId, 'Order not found for COD confirmation message.');
        return ['ok' => false, 'message' => 'Order not found.'];
    }

    if (!cod_guard_whatsapp_configured($settings)) {
        $reason = 'WhatsApp Cloud API credentials are missing.';
        cod_guard_mark_message_not_configured($conn, $orderId, $reason);
        if (function_exists('log_order_activity')) {
            log_order_activity($conn, $orderId, 'cod_guard_message_not_configured', 'system', 0, 'cod-guard', $reason);
        }
        return ['ok' => false, 'message' => $reason];
    }

    $to = cod_guard_phone_for_whatsapp((string) ($row['customer_phone'] ?? ''));
    if ($to === '' || strlen($to) < 10) {
        $reason = 'Customer phone is invalid for WhatsApp confirmation.';
        cod_guard_mark_message_failed($conn, $orderId, $reason);
        if (function_exists('log_order_activity')) {
            log_order_activity($conn, $orderId, 'cod_guard_message_failed', 'system', 0, 'cod-guard', $reason);
        }
        return ['ok' => false, 'message' => $reason];
    }

    $message = cod_guard_build_confirmation_message($row);
    $payload = cod_guard_whatsapp_payload($settings, $to, $message, $row);
    $endpoint = rtrim((string) $settings['whatsapp_api_base_url'], '/') . '/'
        . rawurlencode((string) $settings['whatsapp_phone_number_id']) . '/messages';
    $response = cod_guard_http_post_json($endpoint, [
        'Authorization: Bearer ' . (string) $settings['whatsapp_access_token'],
    ], $payload);

    $body = is_array($response['body'] ?? null) ? $response['body'] : [];
    $messageId = trim((string) ($body['messages'][0]['id'] ?? ''));
    if (!empty($response['ok'])) {
        cod_guard_mark_message_sent($conn, $orderId, $messageId);
        if (function_exists('log_order_activity')) {
            log_order_activity($conn, $orderId, 'cod_guard_message_sent', 'system', 0, 'cod-guard', 'COD confirmation message sent to customer.');
        }
        return ['ok' => true, 'message' => 'COD confirmation message sent.'];
    }

    $providerError = trim((string) ($body['error']['message'] ?? ($response['error'] ?? 'Message send failed.')));
    cod_guard_mark_message_failed($conn, $orderId, $providerError);
    if (function_exists('log_order_activity')) {
        log_order_activity($conn, $orderId, 'cod_guard_message_failed', 'system', 0, 'cod-guard', $providerError);
    }
    return ['ok' => false, 'message' => $providerError];
}

function cod_guard_send_whatsapp_text(string $to, string $message): array
{
    $settings = cod_guard_settings();
    if (!cod_guard_whatsapp_configured($settings)) {
        return ['ok' => false, 'message' => 'WhatsApp Cloud API credentials are missing.'];
    }

    $to = cod_guard_phone_for_whatsapp($to);
    if ($to === '' || strlen($to) < 10) {
        return ['ok' => false, 'message' => 'Customer phone is invalid for WhatsApp response.'];
    }

    $endpoint = rtrim((string) $settings['whatsapp_api_base_url'], '/') . '/'
        . rawurlencode((string) $settings['whatsapp_phone_number_id']) . '/messages';
    $response = cod_guard_http_post_json($endpoint, [
        'Authorization: Bearer ' . (string) $settings['whatsapp_access_token'],
    ], cod_guard_whatsapp_text_payload($to, $message));

    $body = is_array($response['body'] ?? null) ? $response['body'] : [];
    $providerError = trim((string) ($body['error']['message'] ?? ($response['error'] ?? 'Message send failed.')));
    return [
        'ok' => !empty($response['ok']),
        'message' => !empty($response['ok']) ? 'WhatsApp response sent.' : $providerError,
        'message_id' => trim((string) ($body['messages'][0]['id'] ?? '')),
    ];
}

function cod_guard_customer_acknowledgement_text(mysqli $conn, int $orderId, string $reply): string
{
    $stmt = $conn->prepare(
        "SELECT order_number, total_amount
         FROM orders
         WHERE id = ?
         LIMIT 1"
    );
    $stmt->bind_param('i', $orderId);
    $stmt->execute();
    $order = $stmt->get_result()->fetch_assoc() ?: [];
    $orderNumber = trim((string) ($order['order_number'] ?? ''));
    $orderLabel = $orderNumber !== '' ? (' ' . $orderNumber) : '';

    if ($reply === 'yes') {
        return 'Thank you. Your COD order' . $orderLabel . ' is confirmed. We will prepare it for dispatch.';
    }

    return 'Your COD order' . $orderLabel . ' has been cancelled as requested. You can place a new order anytime from Amber Fabrics.';
}

function cod_guard_unmatched_acknowledgement_text(): string
{
    return 'We could not match your COD confirmation reply to a pending order. Please reply with YES <order number> to confirm or NO <order number> to cancel.';
}

function cod_guard_send_customer_reply_ack(mysqli $conn, int $orderId, string $reply, string $from): array
{
    $text = cod_guard_customer_acknowledgement_text($conn, $orderId, $reply);
    $result = cod_guard_send_whatsapp_text($from, $text);
    if (!empty($result['ok']) && function_exists('log_order_activity')) {
        log_order_activity(
            $conn,
            $orderId,
            'cod_guard_customer_ack_sent',
            'system',
            0,
            'cod-guard',
            $reply === 'yes' ? 'Confirmation acknowledgement sent to customer.' : 'Cancellation acknowledgement sent to customer.'
        );
    } elseif (empty($result['ok']) && (string) ($result['message'] ?? '') !== 'WhatsApp Cloud API credentials are missing.') {
        error_log('[cod-guard] customer acknowledgement failed for order ' . $orderId . ': ' . (string) ($result['message'] ?? 'unknown error'));
    }
    return $result;
}

function cod_guard_send_unmatched_reply_ack(string $from): array
{
    return cod_guard_send_whatsapp_text($from, cod_guard_unmatched_acknowledgement_text());
}

function cod_guard_send_confirmation_after_commit(array $context): void
{
    $conn = $context['conn'] ?? null;
    $orderId = (int) ($context['order_id'] ?? 0);
    $paymentMethod = strtolower((string) ($context['payment_method'] ?? ''));
    if (!$conn instanceof mysqli || $orderId <= 0 || $paymentMethod !== 'cod') {
        return;
    }
    cod_guard_send_confirmation_message($conn, $orderId);
}

function cod_guard_send_pending_confirmation_messages(array $context): void
{
    $conn = $context['conn'] ?? ($GLOBALS['conn'] ?? null);
    if (!$conn instanceof mysqli || !cod_guard_message_tracking_ready($conn)) {
        return;
    }

    $settings = cod_guard_settings();
    $maxAttempts = (int) ($settings['message_max_attempts'] ?? 3);
    $includeNotConfigured = cod_guard_whatsapp_configured($settings);
    $statusClause = $includeNotConfigured
        ? "(cc.message_status IS NULL OR cc.message_status IN ('queued','failed','not_configured'))"
        : "(cc.message_status IS NULL OR cc.message_status IN ('queued','failed'))";

    $sql = "
        SELECT cc.order_id
        FROM cod_confirmations cc
        JOIN orders o ON o.id = cc.order_id
        WHERE cc.status = 'pending'
          AND cc.channel IN ('whatsapp','call')
          AND o.payment_method = 'cod'
          AND o.order_status = 'pending'
          AND {$statusClause}
          AND cc.message_attempts < ?
          AND (cc.deadline_at IS NULL OR cc.deadline_at > NOW())
        ORDER BY cc.created_at ASC
        LIMIT 25";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param('i', $maxAttempts);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

    foreach ($rows as $row) {
        $orderId = (int) ($row['order_id'] ?? 0);
        if ($orderId > 0) {
            cod_guard_send_confirmation_message($conn, $orderId);
        }
    }
}

function cod_guard_render_admin_panel(array $context): void
{
    $conn = $context['conn'] ?? null;
    $order = $context['order'] ?? [];
    if (!$conn instanceof mysqli || strtolower((string) ($order['payment_method'] ?? '')) !== 'cod') {
        return;
    }

    $orderId = (int) ($order['id'] ?? 0);
    $row = cod_guard_get_confirmation($conn, $orderId);
    if (!$row) {
        return;
    }

    $status = strtolower((string) ($row['status'] ?? ''));
    $channel = strtolower((string) ($row['channel'] ?? ''));
    ?>
    <div class="card mb-4 border-warning">
        <div class="card-body">
            <h6 class="card-title">COD Guard</h6>
            <div class="small text-muted mb-2">
                <div>Status: <strong><?php echo e(cod_guard_label($row)); ?></strong></div>
                <div>Deadline: <strong><?php echo e((string) ($row['deadline_at'] ?? '-')); ?></strong></div>
                <div>Attempts: <strong><?php echo (int) ($row['attempts'] ?? 0); ?></strong></div>
                <?php if (array_key_exists('message_status', $row)): ?>
                    <div>Message: <strong><?php echo e(ucfirst(str_replace('_', ' ', (string) ($row['message_status'] ?? 'queued')))); ?></strong></div>
                    <?php if (!empty($row['message_sent_at'])): ?>
                        <div>Sent: <strong><?php echo e((string) $row['message_sent_at']); ?></strong></div>
                    <?php endif; ?>
                    <?php if (!empty($row['message_error'])): ?>
                        <div class="text-danger">Last error: <?php echo e((string) $row['message_error']); ?></div>
                    <?php endif; ?>
                <?php endif; ?>
            </div>

            <?php if ($status === 'pending' && in_array($channel, ['whatsapp', 'call'], true)): ?>
                <form method="POST" action="order-view.php?id=<?php echo $orderId; ?>" class="d-grid gap-2">
                    <?php echo csrf_field(); ?>
                    <input type="hidden" name="action" value="cod_guard_mark_confirmed">
                    <button class="btn btn-sm btn-success" type="submit">Mark COD Confirmed</button>
                </form>
                <form method="POST" action="order-view.php?id=<?php echo $orderId; ?>" class="d-grid gap-2 mt-2" onsubmit="return confirm('Cancel this unconfirmed COD order?');">
                    <?php echo csrf_field(); ?>
                    <input type="hidden" name="action" value="cod_guard_mark_cancelled">
                    <button class="btn btn-sm btn-outline-danger" type="submit">Cancel COD Order</button>
                </form>
                <form method="POST" action="order-view.php?id=<?php echo $orderId; ?>" class="mt-2">
                    <?php echo csrf_field(); ?>
                    <input type="hidden" name="action" value="cod_guard_log_attempt">
                    <button class="btn btn-sm btn-outline-secondary w-100" type="submit">Log Attempt</button>
                </form>
            <?php endif; ?>
        </div>
    </div>
    <?php
}

function cod_guard_release_coupon(mysqli $conn, int $orderId): void
{
    release_coupon_usage_for_order($conn, $orderId);
}

function cod_guard_cancel_order(mysqli $conn, int $orderId, string $reason, string $actorType = 'system', int $actorId = 0, string $actorName = 'cod-guard', string $confirmationStatus = 'cancelled'): void
{
    $confirmationStatus = $confirmationStatus === 'auto_cancelled' ? 'auto_cancelled' : 'cancelled';
    $upd = $conn->prepare(
        "UPDATE orders
         SET order_status = 'cancelled',
             status = 'cancelled',
             notes = CASE WHEN notes IS NULL OR notes = '' THEN ? ELSE CONCAT(notes, '\n', ?) END,
             updated_at = NOW()
         WHERE id = ? AND payment_method = 'cod' AND order_status = 'pending'"
    );
    $upd->bind_param('ssi', $reason, $reason, $orderId);
    $upd->execute();
    if ((int) $upd->affected_rows <= 0) {
        return;
    }

    restore_order_inventory($conn, $orderId);
    cod_guard_release_coupon($conn, $orderId);

    $cod = $conn->prepare(
        "UPDATE cod_confirmations
         SET status = ?,
             cancelled_at = NOW(),
             notes = CASE WHEN notes IS NULL OR notes = '' THEN ? ELSE CONCAT(notes, '\n', ?) END,
             updated_at = NOW()
         WHERE order_id = ?"
    );
    $cod->bind_param('sssi', $confirmationStatus, $reason, $reason, $orderId);
    $cod->execute();

    log_order_activity($conn, $orderId, 'cod_guard_cancelled', $actorType, $actorId, $actorName, $reason);
}

function cod_guard_webhook_verify_token(): string
{
    $settings = cod_guard_settings();
    return (string) ($settings['webhook_verify_token'] ?? '');
}

function cod_guard_validate_webhook_request(string $payload): bool
{
    $settings = cod_guard_settings();
    $secret = trim((string) ($settings['whatsapp_app_secret'] ?? ''));
    if ($secret !== '') {
        $signature = trim((string) ($_SERVER['HTTP_X_HUB_SIGNATURE_256'] ?? ''));
        if ($signature === '') {
            return false;
        }
        $expected = 'sha256=' . hash_hmac('sha256', $payload, $secret);
        return hash_equals($expected, $signature);
    }

    $authToken = trim((string) ($settings['webhook_auth_token'] ?? ''));
    if ($authToken === '') {
        return false;
    }
    $provided = trim((string) ($_SERVER['HTTP_X_COD_GUARD_TOKEN'] ?? ($_GET['token'] ?? '')));
    return $provided !== '' && hash_equals($authToken, $provided);
}

function cod_guard_extract_inbound_messages(array $payload): array
{
    $messages = [];
    foreach ((array) ($payload['entry'] ?? []) as $entry) {
        foreach ((array) ($entry['changes'] ?? []) as $change) {
            $value = is_array($change['value'] ?? null) ? $change['value'] : [];
            foreach ((array) ($value['messages'] ?? []) as $message) {
                if (!is_array($message)) {
                    continue;
                }
                $textParts = [];
                $type = (string) ($message['type'] ?? '');
                if ($type === 'text') {
                    $textParts[] = (string) ($message['text']['body'] ?? '');
                } elseif ($type === 'interactive') {
                    $button = is_array($message['interactive']['button_reply'] ?? null) ? $message['interactive']['button_reply'] : [];
                    $list = is_array($message['interactive']['list_reply'] ?? null) ? $message['interactive']['list_reply'] : [];
                    $textParts[] = (string) ($button['title'] ?? '');
                    $textParts[] = (string) ($button['id'] ?? '');
                    $textParts[] = (string) ($list['title'] ?? '');
                    $textParts[] = (string) ($list['id'] ?? '');
                } elseif ($type === 'button') {
                    $textParts[] = (string) ($message['button']['text'] ?? '');
                    $textParts[] = (string) ($message['button']['payload'] ?? '');
                }

                $body = trim(implode(' ', array_filter(array_map('trim', $textParts), static fn($v) => $v !== '')));
                $messages[] = [
                    'id' => trim((string) ($message['id'] ?? '')),
                    'from' => trim((string) ($message['from'] ?? '')),
                    'text' => $body,
                    'raw' => $message,
                ];
            }
        }
    }
    return $messages;
}

function cod_guard_parse_customer_reply(string $text): ?string
{
    $normalized = strtolower(trim($text));
    if ($normalized === '') {
        return null;
    }
    $yes = (bool) preg_match('/\b(yes|y|confirm|confirmed|ok|okay)\b/', $normalized)
        || str_contains($normalized, 'cod_yes');
    $no = (bool) preg_match('/\b(no|n|cancel|cancelled)\b/', $normalized)
        || str_contains($normalized, 'cod_no');
    if ($yes && !$no) {
        return 'yes';
    }
    if ($no && !$yes) {
        return 'no';
    }
    return null;
}

function cod_guard_find_pending_order_by_id(mysqli $conn, int $orderId): int
{
    if ($orderId <= 0) {
        return 0;
    }
    $stmt = $conn->prepare(
        "SELECT cc.order_id
         FROM cod_confirmations cc
         JOIN orders o ON o.id = cc.order_id
         WHERE cc.order_id = ?
           AND cc.status = 'pending'
           AND o.payment_method = 'cod'
           AND o.order_status = 'pending'
         LIMIT 1"
    );
    $stmt->bind_param('i', $orderId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    return (int) ($row['order_id'] ?? 0);
}

function cod_guard_find_pending_order_by_number(mysqli $conn, string $orderNumber): int
{
    $orderNumber = trim($orderNumber);
    if ($orderNumber === '') {
        return 0;
    }
    $stmt = $conn->prepare(
        "SELECT cc.order_id
         FROM cod_confirmations cc
         JOIN orders o ON o.id = cc.order_id
         WHERE o.order_number = ?
           AND cc.status = 'pending'
           AND o.payment_method = 'cod'
           AND o.order_status = 'pending'
         LIMIT 1"
    );
    $stmt->bind_param('s', $orderNumber);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    return (int) ($row['order_id'] ?? 0);
}

function cod_guard_find_pending_order_for_reply(mysqli $conn, string $from, string $text): int
{
    if (preg_match('/cod_(?:yes|no|confirm|cancel):(\d+)/i', $text, $m)) {
        $orderId = cod_guard_find_pending_order_by_id($conn, (int) $m[1]);
        if ($orderId > 0) {
            return $orderId;
        }
    }

    if (preg_match('/\b(VT[A-Z0-9]{8,})\b/i', $text, $m)) {
        $orderId = cod_guard_find_pending_order_by_number($conn, strtoupper((string) $m[1]));
        if ($orderId > 0) {
            return $orderId;
        }
    }

    $phoneKey = cod_guard_phone_key($from);
    if ($phoneKey === '') {
        return 0;
    }

    $stmt = $conn->prepare(
        "SELECT cc.order_id, o.customer_phone
         FROM cod_confirmations cc
         JOIN orders o ON o.id = cc.order_id
         WHERE cc.status = 'pending'
           AND cc.channel IN ('whatsapp','call')
           AND o.payment_method = 'cod'
           AND o.order_status = 'pending'
           AND (cc.deadline_at IS NULL OR cc.deadline_at > NOW())
         ORDER BY o.created_at DESC
         LIMIT 50"
    );
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $matches = [];
    foreach ($rows as $row) {
        if (cod_guard_phone_key((string) ($row['customer_phone'] ?? '')) === $phoneKey) {
            $matches[] = (int) ($row['order_id'] ?? 0);
        }
    }

    $matches = array_values(array_unique(array_filter($matches)));
    return count($matches) === 1 ? (int) $matches[0] : 0;
}

function cod_guard_store_inbound_message(mysqli $conn, int $orderId, string $messageId, string $text): void
{
    if (!cod_guard_has_column($conn, 'last_inbound_message_id')) {
        return;
    }
    $text = substr($text, 0, 1000);
    $stmt = $conn->prepare(
        "UPDATE cod_confirmations
         SET last_inbound_message_id = ?,
             last_inbound_text = ?,
             last_inbound_at = NOW(),
             updated_at = NOW()
         WHERE order_id = ?"
    );
    $stmt->bind_param('ssi', $messageId, $text, $orderId);
    $stmt->execute();
}

function cod_guard_transaction_active(mysqli $conn): bool
{
    try {
        $res = $conn->query("SELECT @@in_transaction AS active");
        $row = $res ? $res->fetch_assoc() : [];
        return ((int) ($row['active'] ?? 0)) === 1;
    } catch (Throwable $e) {
        return false;
    }
}

function cod_guard_begin_atomic(mysqli $conn): bool
{
    if (cod_guard_transaction_active($conn)) {
        $conn->query("SAVEPOINT cod_guard_reply");
        return false;
    }
    $conn->begin_transaction();
    return true;
}

function cod_guard_commit_atomic(mysqli $conn, bool $startedTransaction): void
{
    if ($startedTransaction) {
        $conn->commit();
        return;
    }
    $conn->query("RELEASE SAVEPOINT cod_guard_reply");
}

function cod_guard_rollback_atomic(mysqli $conn, bool $startedTransaction): void
{
    if ($startedTransaction) {
        $conn->rollback();
        return;
    }
    $conn->query("ROLLBACK TO SAVEPOINT cod_guard_reply");
    $conn->query("RELEASE SAVEPOINT cod_guard_reply");
}

function cod_guard_apply_customer_reply(mysqli $conn, int $orderId, string $reply, array $message): bool
{
    if ($orderId <= 0 || !in_array($reply, ['yes', 'no'], true)) {
        return false;
    }

    $messageId = trim((string) ($message['id'] ?? ''));
    $text = trim((string) ($message['text'] ?? ''));
    $startedTransaction = false;

    try {
        $startedTransaction = cod_guard_begin_atomic($conn);
        $stmt = $conn->prepare(
            "SELECT cc.status AS confirmation_status, o.payment_method, o.order_status
             FROM cod_confirmations cc
             JOIN orders o ON o.id = cc.order_id
             WHERE cc.order_id = ?
             LIMIT 1
             FOR UPDATE"
        );
        $stmt->bind_param('i', $orderId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc() ?: [];
        if (($row['confirmation_status'] ?? '') !== 'pending'
            || strtolower((string) ($row['payment_method'] ?? '')) !== 'cod'
            || strtolower((string) ($row['order_status'] ?? '')) !== 'pending') {
            cod_guard_rollback_atomic($conn, $startedTransaction);
            return false;
        }

        cod_guard_store_inbound_message($conn, $orderId, $messageId, $text);

        if ($reply === 'yes') {
            $upd = $conn->prepare(
                "UPDATE orders
                 SET order_status = 'confirmed',
                     status = 'confirmed',
                     updated_at = NOW()
                 WHERE id = ?
                   AND payment_method = 'cod'
                   AND order_status = 'pending'"
            );
            $upd->bind_param('i', $orderId);
            $upd->execute();

            $cod = $conn->prepare(
                "UPDATE cod_confirmations
                 SET status = 'confirmed',
                     confirmed_at = NOW(),
                     updated_at = NOW()
                 WHERE order_id = ?"
            );
            $cod->bind_param('i', $orderId);
            $cod->execute();
            log_order_activity($conn, $orderId, 'cod_guard_confirmed', 'webhook', 0, 'cod-guard-whatsapp', 'Customer replied YES to COD confirmation.');
        } else {
            cod_guard_cancel_order($conn, $orderId, 'Customer replied NO to COD confirmation. Order cancelled.', 'webhook', 0, 'cod-guard-whatsapp');
        }

        cod_guard_commit_atomic($conn, $startedTransaction);
        return true;
    } catch (Throwable $e) {
        try {
            cod_guard_rollback_atomic($conn, $startedTransaction);
        } catch (Throwable $rollbackException) {
        }
        error_log('[cod-guard] customer reply failed for order ' . $orderId . ': ' . $e->getMessage());
        return false;
    }
}

function cod_guard_handle_webhook_payload(mysqli $conn, array $payload): array
{
    $result = ['processed' => 0, 'confirmed' => 0, 'cancelled' => 0, 'ignored' => 0, 'acks_sent' => 0, 'acks_failed' => 0];
    foreach (cod_guard_extract_inbound_messages($payload) as $message) {
        $reply = cod_guard_parse_customer_reply((string) ($message['text'] ?? ''));
        if ($reply === null) {
            $result['ignored']++;
            continue;
        }

        $orderId = cod_guard_find_pending_order_for_reply(
            $conn,
            (string) ($message['from'] ?? ''),
            (string) ($message['text'] ?? '')
        );
        if ($orderId <= 0) {
            $result['ignored']++;
            $ack = cod_guard_send_unmatched_reply_ack((string) ($message['from'] ?? ''));
            if (!empty($ack['ok'])) {
                $result['acks_sent']++;
            } else {
                $result['acks_failed']++;
            }
            error_log('[cod-guard] inbound reply could not be matched to a single pending order.');
            continue;
        }

        if (cod_guard_apply_customer_reply($conn, $orderId, $reply, $message)) {
            $result['processed']++;
            if ($reply === 'yes') {
                $result['confirmed']++;
            } else {
                $result['cancelled']++;
            }
            $ack = cod_guard_send_customer_reply_ack($conn, $orderId, $reply, (string) ($message['from'] ?? ''));
            if (!empty($ack['ok'])) {
                $result['acks_sent']++;
            } else {
                $result['acks_failed']++;
            }
        } else {
            $result['ignored']++;
        }
    }

    return $result;
}

function cod_guard_handle_admin_action($handled, array $context)
{
    if ($handled) {
        return true;
    }
    $conn = $context['conn'] ?? null;
    $action = (string) ($context['action'] ?? '');
    $orderId = (int) ($context['order_id'] ?? 0);
    if (!$conn instanceof mysqli || $orderId <= 0 || !in_array($action, ['cod_guard_mark_confirmed', 'cod_guard_mark_cancelled', 'cod_guard_log_attempt'], true)) {
        return false;
    }

    try {
        $conn->begin_transaction();
        $row = cod_guard_get_confirmation($conn, $orderId);
        if (!$row || strtolower((string) ($row['status'] ?? '')) !== 'pending') {
            throw new RuntimeException('COD confirmation is not pending.');
        }

        $adminId = (int) ($_SESSION['admin_id'] ?? 0);
        $adminName = (string) ($_SESSION['admin_name'] ?? 'admin');

        if ($action === 'cod_guard_mark_confirmed') {
            $upd = $conn->prepare("UPDATE orders SET order_status = 'confirmed', status = 'confirmed', updated_at = NOW() WHERE id = ? AND payment_method = 'cod' AND order_status = 'pending'");
            $upd->bind_param('i', $orderId);
            $upd->execute();

            $cod = $conn->prepare("UPDATE cod_confirmations SET status = 'confirmed', confirmed_at = NOW(), updated_at = NOW() WHERE order_id = ?");
            $cod->bind_param('i', $orderId);
            $cod->execute();
            log_order_activity($conn, $orderId, 'cod_guard_confirmed', 'admin', $adminId, $adminName, 'COD order manually confirmed.');
            flash('success', 'COD order confirmed.');
        } elseif ($action === 'cod_guard_mark_cancelled') {
            cod_guard_cancel_order($conn, $orderId, 'COD order cancelled after failed confirmation.', 'admin', $adminId, $adminName);
            flash('success', 'COD order cancelled and stock restored.');
        } else {
            $upd = $conn->prepare("UPDATE cod_confirmations SET attempts = attempts + 1, updated_at = NOW() WHERE order_id = ?");
            $upd->bind_param('i', $orderId);
            $upd->execute();
            log_order_activity($conn, $orderId, 'cod_guard_attempt_logged', 'admin', $adminId, $adminName, 'Confirmation attempt logged.');
            flash('success', 'COD confirmation attempt logged.');
        }

        $conn->commit();
    } catch (Throwable $e) {
        try {
            $conn->rollback();
        } catch (Throwable $rollbackException) {
        }
        flash('error', $e->getMessage() !== '' ? $e->getMessage() : 'Unable to update COD confirmation.');
    }

    return true;
}

function cod_guard_auto_cancel_expired(array $context): void
{
    $conn = $context['conn'] ?? ($GLOBALS['conn'] ?? null);
    if (!$conn instanceof mysqli || !cod_guard_table_ready($conn)) {
        return;
    }

    $stmt = $conn->prepare(
        "SELECT cc.order_id
         FROM cod_confirmations cc
         JOIN orders o ON o.id = cc.order_id
         WHERE cc.status = 'pending'
           AND cc.deadline_at IS NOT NULL
           AND cc.deadline_at < NOW()
           AND o.payment_method = 'cod'
           AND o.order_status = 'pending'
         LIMIT 50"
    );
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

    foreach ($rows as $row) {
        $orderId = (int) ($row['order_id'] ?? 0);
        if ($orderId <= 0) {
            continue;
        }
        try {
            $conn->begin_transaction();
            cod_guard_cancel_order($conn, $orderId, 'Auto-cancelled because COD confirmation deadline expired.', 'system', 0, 'cod-guard', 'auto_cancelled');
            $conn->commit();
        } catch (Throwable $e) {
            try {
                $conn->rollback();
            } catch (Throwable $rollbackException) {
            }
            error_log('[cod-guard] auto cancel failed for order ' . $orderId . ': ' . $e->getMessage());
        }
    }
}
