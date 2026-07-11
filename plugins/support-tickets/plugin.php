<?php

// Customer-service tickets are intentionally separate from contact/export inquiries.
// Inquiries remain pre-sales lead capture; this plugin serves logged-in customers and order help.
// Returns/refunds stay in the existing return request workflow; tickets only discuss support follow-up.
add_filter('admin.nav.items', 'support_tickets_admin_nav_items', 20);
add_filter('admin.order_action.handled', 'support_tickets_handle_admin_order_action', 20);
add_action('customer.order_view.after', 'support_tickets_render_order_panel', 20);
add_action('admin.order_view.sidebar', 'support_tickets_render_admin_order_sidebar', 20);
add_action('cron.tick', 'support_tickets_cron_tick', 60);

function support_tickets_settings(): array
{
    return [
        'enabled' => (int) plugin_setting('support-tickets', 'enabled', 1) === 1,
        'allow_order_tickets' => (int) plugin_setting('support-tickets', 'allow_order_tickets', 1) === 1,
        'allow_general_tickets' => (int) plugin_setting('support-tickets', 'allow_general_tickets', 1) === 1,
        'notify_admin' => (int) plugin_setting('support-tickets', 'notify_admin', 1) === 1,
        'notify_customer' => (int) plugin_setting('support-tickets', 'notify_customer', 1) === 1,
        'auto_close_days' => max(0, (int) plugin_setting('support-tickets', 'auto_close_days', 14)),
        'max_open_tickets_per_customer' => max(1, (int) plugin_setting('support-tickets', 'max_open_tickets_per_customer', 5)),
        'max_message_length' => max(200, min(5000, (int) plugin_setting('support-tickets', 'max_message_length', 2000))),
    ];
}

function support_tickets_table_ready(mysqli $conn): bool
{
    try {
        $stmt = $conn->prepare(
            "SELECT COUNT(*) AS total
             FROM information_schema.TABLES
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME IN ('support_tickets', 'support_ticket_messages')"
        );
        $stmt->execute();
        return (int) ($stmt->get_result()->fetch_assoc()['total'] ?? 0) === 2;
    } catch (Throwable $e) {
        error_log('[support-tickets] table check failed: ' . $e->getMessage());
        return false;
    }
}

function support_tickets_admin_nav_items($items, array $context): array
{
    $settings = support_tickets_settings();
    if (!$settings['enabled']) {
        return is_array($items) ? $items : [];
    }

    $items = is_array($items) ? $items : [];
    $currentPage = (string) ($context['current_page'] ?? '');
    $items[] = [
        'label' => 'Support',
        'url' => 'support-tickets.php',
        'icon' => 'bi bi-headset',
        'active' => $currentPage === 'support-tickets.php',
    ];
    return $items;
}

function support_tickets_categories(): array
{
    return [
        'order' => 'Order',
        'shipping' => 'Shipping',
        'payment' => 'Payment',
        'product' => 'Product',
        'account' => 'Account',
        'other' => 'Other',
    ];
}

function support_tickets_priorities(): array
{
    return [
        'low' => 'Low',
        'normal' => 'Normal',
        'high' => 'High',
        'urgent' => 'Urgent',
    ];
}

function support_tickets_statuses(): array
{
    return [
        'open' => 'Open',
        'waiting_customer' => 'Waiting for Customer',
        'waiting_admin' => 'Waiting for Admin',
        'resolved' => 'Resolved',
        'closed' => 'Closed',
    ];
}

function support_tickets_trim_text(string $text, int $maxLength): string
{
    $text = trim($text);
    $text = preg_replace("/[ \t]+/", ' ', $text) ?? '';
    if (function_exists('mb_substr')) {
        return mb_substr($text, 0, $maxLength, 'UTF-8');
    }
    return substr($text, 0, $maxLength);
}

function support_tickets_customer(mysqli $conn, int $customerId): array
{
    $stmt = $conn->prepare("SELECT id, name, email FROM customers WHERE id = ? LIMIT 1");
    $stmt->bind_param('i', $customerId);
    $stmt->execute();
    return $stmt->get_result()->fetch_assoc() ?: [];
}

function support_tickets_customer_order(mysqli $conn, int $customerId, int $orderId): array
{
    if ($orderId <= 0) {
        return [];
    }
    $stmt = $conn->prepare(
        "SELECT id, order_number, order_status, payment_status
         FROM orders
         WHERE id = ? AND customer_id = ?
         LIMIT 1"
    );
    $stmt->bind_param('ii', $orderId, $customerId);
    $stmt->execute();
    return $stmt->get_result()->fetch_assoc() ?: [];
}

function support_tickets_generate_number(mysqli $conn): string
{
    for ($i = 0; $i < 5; $i++) {
        $candidate = 'TKT' . date('Ymd') . strtoupper(substr(bin2hex(random_bytes(4)), 0, 8));
        $stmt = $conn->prepare("SELECT id FROM support_tickets WHERE ticket_number = ? LIMIT 1");
        $stmt->bind_param('s', $candidate);
        $stmt->execute();
        if (!$stmt->get_result()->fetch_assoc()) {
            return $candidate;
        }
    }
    return 'TKT' . date('YmdHis') . strtoupper(substr(bin2hex(random_bytes(2)), 0, 4));
}

function support_tickets_find_recent_duplicate(mysqli $conn, int $customerId, int $orderId, string $category, string $subject): int
{
    $orderParam = $orderId > 0 ? $orderId : null;
    $stmt = $conn->prepare(
        "SELECT id
         FROM support_tickets
         WHERE customer_id = ?
           AND ((order_id = ?) OR (order_id IS NULL AND ? IS NULL))
           AND category = ?
           AND subject = ?
           AND status NOT IN ('resolved','closed')
           AND created_at >= (NOW() - INTERVAL 30 MINUTE)
         ORDER BY id DESC
         LIMIT 1"
    );
    $stmt->bind_param('iiiss', $customerId, $orderParam, $orderParam, $category, $subject);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    return (int) ($row['id'] ?? 0);
}

function support_tickets_open_count_for_customer(mysqli $conn, int $customerId): int
{
    if ($customerId <= 0) {
        return 0;
    }
    $stmt = $conn->prepare(
        "SELECT COUNT(*) AS total
         FROM support_tickets
         WHERE customer_id = ?
           AND status NOT IN ('resolved', 'closed')"
    );
    $stmt->bind_param('i', $customerId);
    $stmt->execute();
    return (int) ($stmt->get_result()->fetch_assoc()['total'] ?? 0);
}

function support_tickets_create(mysqli $conn, int $customerId, int $orderId, string $category, string $priority, string $subject, string $message): int
{
    $settings = support_tickets_settings();
    $categories = support_tickets_categories();
    $priorities = support_tickets_priorities();

    if (!$settings['enabled']) {
        throw new RuntimeException('Support tickets are not available.');
    }
    if ($orderId > 0 && empty($settings['allow_order_tickets'])) {
        throw new RuntimeException('Order support tickets are not available.');
    }
    if ($orderId <= 0 && empty($settings['allow_general_tickets'])) {
        throw new RuntimeException('General support tickets are not available.');
    }
    if (!isset($categories[$category])) {
        $category = $orderId > 0 ? 'order' : 'other';
    }
    if ($orderId > 0) {
        $category = $category === 'account' ? 'order' : $category;
    }
    if (!isset($priorities[$priority])) {
        $priority = 'normal';
    }
    $subject = support_tickets_trim_text($subject, 160);
    $message = support_tickets_trim_text($message, (int) $settings['max_message_length']);
    if ($customerId <= 0 || $subject === '' || $message === '') {
        throw new RuntimeException('Please provide a subject and message.');
    }
    if ($orderId > 0 && empty(support_tickets_customer_order($conn, $customerId, $orderId))) {
        throw new RuntimeException('Selected order was not found.');
    }

    $duplicateId = support_tickets_find_recent_duplicate($conn, $customerId, $orderId, $category, $subject);
    if ($duplicateId > 0) {
        return $duplicateId;
    }
    if (support_tickets_open_count_for_customer($conn, $customerId) >= (int) $settings['max_open_tickets_per_customer']) {
        throw new RuntimeException('You have reached the open support ticket limit. Please reply to an existing ticket or wait for the team to resolve one.');
    }

    $ticketNumber = support_tickets_generate_number($conn);
    $orderParam = $orderId > 0 ? $orderId : null;
    $customer = support_tickets_customer($conn, $customerId);
    $authorName = (string) ($customer['name'] ?? 'Customer');

    $conn->begin_transaction();
    try {
        $stmt = $conn->prepare(
            "INSERT INTO support_tickets
                (ticket_number, customer_id, order_id, subject, category, priority, status, last_message_at)
             VALUES (?, ?, ?, ?, ?, ?, 'open', NOW())"
        );
        $stmt->bind_param('siisss', $ticketNumber, $customerId, $orderParam, $subject, $category, $priority);
        $stmt->execute();
        $ticketId = (int) $conn->insert_id;

        $msgStmt = $conn->prepare(
            "INSERT INTO support_ticket_messages (ticket_id, sender_type, sender_id, sender_name, message)
             VALUES (?, 'customer', ?, ?, ?)"
        );
        $msgStmt->bind_param('iiss', $ticketId, $customerId, $authorName, $message);
        $msgStmt->execute();

        if ($orderId > 0 && function_exists('log_order_activity')) {
            log_order_activity($conn, $orderId, 'support_ticket_created', 'customer', $customerId, $authorName, 'Support ticket ' . $ticketNumber . ' opened: ' . $subject);
        }

        $conn->commit();
    } catch (Throwable $e) {
        $conn->rollback();
        throw $e;
    }

    support_tickets_notify_admin($conn, $ticketId, 'created');
    return $ticketId;
}

function support_tickets_get(mysqli $conn, int $ticketId, int $customerId = 0): array
{
    $sql = "SELECT st.*, c.name AS customer_name, c.email AS customer_email, o.order_number
            FROM support_tickets st
            JOIN customers c ON c.id = st.customer_id
            LEFT JOIN orders o ON o.id = st.order_id
            WHERE st.id = ?";
    $types = 'i';
    $params = [$ticketId];
    if ($customerId > 0) {
        $sql .= " AND st.customer_id = ?";
        $types .= 'i';
        $params[] = $customerId;
    }
    $sql .= " LIMIT 1";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    return $stmt->get_result()->fetch_assoc() ?: [];
}

function support_tickets_messages(mysqli $conn, int $ticketId, bool $includeInternal = false): array
{
    $sql = "SELECT sender_type, sender_name, message, is_internal, attachment_path, created_at
            FROM support_ticket_messages
            WHERE ticket_id = ?";
    if (!$includeInternal) {
        $sql .= " AND is_internal = 0";
    }
    $sql .= " ORDER BY created_at ASC, id ASC";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param('i', $ticketId);
    $stmt->execute();
    return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
}

function support_tickets_add_message(mysqli $conn, int $ticketId, string $authorType, int $authorId, string $authorName, string $message, bool $internal = false): void
{
    $settings = support_tickets_settings();
    $message = support_tickets_trim_text($message, (int) $settings['max_message_length']);
    if ($message === '') {
        throw new RuntimeException('Message cannot be empty.');
    }
    if (!in_array($authorType, ['customer', 'admin', 'system'], true)) {
        throw new RuntimeException('Invalid message author.');
    }
    $nextStatus = $authorType === 'customer' ? 'waiting_admin' : 'waiting_customer';

    $conn->begin_transaction();
    try {
        $ticket = support_tickets_get($conn, $ticketId);
        if (!$ticket) {
            throw new RuntimeException('Ticket not found.');
        }
        if (in_array((string) ($ticket['status'] ?? ''), ['resolved', 'closed'], true) && $authorType === 'customer') {
            $nextStatus = 'open';
        }

        $internalFlag = $internal ? 1 : 0;
        $msgStmt = $conn->prepare(
            "INSERT INTO support_ticket_messages (ticket_id, sender_type, sender_id, sender_name, message, is_internal)
             VALUES (?, ?, ?, ?, ?, ?)"
        );
        $msgStmt->bind_param('isissi', $ticketId, $authorType, $authorId, $authorName, $message, $internalFlag);
        $msgStmt->execute();

        $statusSql = $internal ? 'status' : '?';
        if ($internal) {
            $update = $conn->prepare(
                "UPDATE support_tickets
                 SET last_message_at = NOW(), updated_at = NOW()
                 WHERE id = ?"
            );
            $update->bind_param('i', $ticketId);
        } else {
            $update = $conn->prepare(
                "UPDATE support_tickets
                 SET status = {$statusSql}, last_message_at = NOW(), updated_at = NOW(),
                     closed_at = CASE WHEN status = 'closed' THEN NULL ELSE closed_at END
                 WHERE id = ?"
            );
            $update->bind_param('si', $nextStatus, $ticketId);
        }
        $update->execute();

        $orderId = (int) ($ticket['order_id'] ?? 0);
        if ($orderId > 0 && !$internal && function_exists('log_order_activity')) {
            log_order_activity($conn, $orderId, 'support_ticket_replied', $authorType, $authorId, $authorName, 'Support ticket ' . (string) $ticket['ticket_number'] . ' received a reply.');
        }

        $conn->commit();
    } catch (Throwable $e) {
        $conn->rollback();
        throw $e;
    }

    if (!$internal) {
        if ($authorType === 'customer') {
            support_tickets_notify_admin($conn, $ticketId, 'reply');
        } elseif ($authorType === 'admin') {
            support_tickets_notify_customer($conn, $ticketId, $message);
        }
    }
}

function support_tickets_update_status(mysqli $conn, int $ticketId, string $status, int $adminId, string $adminName): void
{
    $statuses = support_tickets_statuses();
    if (!isset($statuses[$status])) {
        throw new RuntimeException('Invalid ticket status.');
    }
    $ticket = support_tickets_get($conn, $ticketId);
    if (!$ticket) {
        throw new RuntimeException('Ticket not found.');
    }
    $oldStatus = (string) ($ticket['status'] ?? '');

    $closedAtSql = $status === 'closed' ? 'NOW()' : 'closed_at';
    $stmt = $conn->prepare(
        "UPDATE support_tickets
         SET status = ?, closed_at = {$closedAtSql}, updated_at = NOW()
         WHERE id = ?"
    );
    $stmt->bind_param('si', $status, $ticketId);
    $stmt->execute();

    $note = 'Status changed to ' . $statuses[$status] . '.';
    $msgStmt = $conn->prepare(
        "INSERT INTO support_ticket_messages (ticket_id, sender_type, sender_id, sender_name, message, is_internal)
         VALUES (?, 'system', ?, ?, ?, 1)"
    );
    $msgStmt->bind_param('iiss', $ticketId, $adminId, $adminName, $note);
    $msgStmt->execute();

    $orderId = (int) ($ticket['order_id'] ?? 0);
    if (
        $orderId > 0
        && $oldStatus !== $status
        && in_array($status, ['resolved', 'closed'], true)
        && function_exists('log_order_activity')
    ) {
        $action = $status === 'closed' ? 'support_ticket_closed' : 'support_ticket_resolved';
        log_order_activity(
            $conn,
            $orderId,
            $action,
            'admin',
            $adminId,
            $adminName,
            'Support ticket ' . (string) ($ticket['ticket_number'] ?? ('#' . $ticketId)) . ' marked ' . $statuses[$status] . '.'
        );
    }
}

function support_tickets_cron_tick(array $context): void
{
    $settings = support_tickets_settings();
    $conn = $context['conn'] ?? null;
    if (!$settings['enabled'] || !$conn instanceof mysqli || !support_tickets_table_ready($conn)) {
        return;
    }

    $autoCloseDays = (int) ($settings['auto_close_days'] ?? 0);
    if ($autoCloseDays <= 0) {
        return;
    }

    $stmt = $conn->prepare(
        "SELECT id, ticket_number, order_id
         FROM support_tickets
         WHERE status = 'resolved'
           AND updated_at < (NOW() - INTERVAL ? DAY)
         ORDER BY updated_at ASC
         LIMIT 100"
    );
    $stmt->bind_param('i', $autoCloseDays);
    $stmt->execute();
    $tickets = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    if (empty($tickets)) {
        return;
    }

    $systemName = 'cron';
    foreach ($tickets as $ticket) {
        $ticketId = (int) ($ticket['id'] ?? 0);
        if ($ticketId <= 0) {
            continue;
        }
        try {
            support_tickets_update_status($conn, $ticketId, 'closed', 0, $systemName);
        } catch (Throwable $e) {
            error_log('[support-tickets] cron auto-close failed for ticket ' . $ticketId . ': ' . $e->getMessage());
        }
    }
}

function support_tickets_handle_admin_order_action($handled, array $context): bool
{
    if ($handled) {
        return true;
    }
    $action = trim((string) ($context['action'] ?? ''));
    if (!in_array($action, ['support_ticket_reply', 'support_ticket_status'], true)) {
        return false;
    }

    require_admin();
    $conn = $context['conn'] ?? null;
    $orderId = (int) ($context['order_id'] ?? 0);
    $post = isset($context['post']) && is_array($context['post']) ? $context['post'] : [];
    $settings = support_tickets_settings();
    if (!$settings['enabled'] || !$conn instanceof mysqli || $orderId <= 0 || !support_tickets_table_ready($conn)) {
        flash('error', 'Support tickets are not available.');
        return true;
    }

    $ticketId = (int) ($post['ticket_id'] ?? 0);
    $ticket = $ticketId > 0 ? support_tickets_get($conn, $ticketId) : [];
    if (!$ticket || (int) ($ticket['order_id'] ?? 0) !== $orderId) {
        flash('error', 'Support ticket not found for this order.');
        return true;
    }

    $adminId = (int) ($_SESSION['admin_id'] ?? 0);
    $adminName = (string) ($_SESSION['admin_name'] ?? 'admin');

    try {
        if ($action === 'support_ticket_reply') {
            if ((string) ($ticket['status'] ?? '') === 'closed') {
                throw new RuntimeException('This ticket is closed.');
            }
            support_tickets_add_message($conn, $ticketId, 'admin', $adminId, $adminName, (string) ($post['message'] ?? ''), false);
            flash('success', 'Support ticket reply sent.');
            return true;
        }

        support_tickets_update_status($conn, $ticketId, trim((string) ($post['status'] ?? '')), $adminId, $adminName);
        flash('success', 'Support ticket status updated.');
        return true;
    } catch (Throwable $e) {
        error_log('[support-tickets] admin order action failed: ' . $e->getMessage());
        flash('error', $e->getMessage() !== '' ? $e->getMessage() : 'Unable to update support ticket.');
        return true;
    }
}

function support_tickets_notify_admin(mysqli $conn, int $ticketId, string $event): bool
{
    $settings = support_tickets_settings();
    if (!$settings['notify_admin']) {
        return false;
    }
    $to = function_exists('admin_notification_email') ? admin_notification_email() : '';
    if ($to === '') {
        return false;
    }
    $ticket = support_tickets_get($conn, $ticketId);
    if (!$ticket) {
        return false;
    }
    $latestReply = '';
    if ($event === 'reply') {
        $messages = support_tickets_messages($conn, $ticketId, false);
        $last = !empty($messages) ? end($messages) : [];
        if (is_array($last) && (string) ($last['sender_type'] ?? '') === 'customer') {
            $latestReply = (string) ($last['message'] ?? '');
        }
    }
    $templateKey = $event === 'created' ? 'support_ticket_created' : 'support_ticket_reply_admin';
    $template = email_template_build($templateKey, [
        'ticket' => $ticket,
        'event' => $event,
        'reply' => $latestReply,
        'admin_url' => app_url('/admin/support-tickets.php?id=' . $ticketId),
    ]);
    if ($template['subject'] === '' || $template['body'] === '') {
        return false;
    }
    try {
        $mail = EmailService::_mailer_base();
        $mail->addAddress($to);
        if (filter_var((string) ($ticket['customer_email'] ?? ''), FILTER_VALIDATE_EMAIL)) {
            $mail->addReplyTo((string) $ticket['customer_email'], (string) ($ticket['customer_name'] ?? 'Customer'));
        }
        $mail->Subject = $template['subject'];
        $mail->Body = $template['body'];
        $mail->send();
        return true;
    } catch (Throwable $e) {
        error_log('[support-tickets] admin notification failed: ' . $e->getMessage());
        return false;
    }
}

function support_tickets_notify_customer(mysqli $conn, int $ticketId, string $reply): bool
{
    $settings = support_tickets_settings();
    if (!$settings['notify_customer']) {
        return false;
    }
    $ticket = support_tickets_get($conn, $ticketId);
    if (!$ticket || !filter_var((string) ($ticket['customer_email'] ?? ''), FILTER_VALIDATE_EMAIL)) {
        return false;
    }
    $template = email_template_build('support_ticket_reply_customer', [
        'ticket' => $ticket,
        'reply' => $reply,
        'ticket_url' => app_url('/customer/support-tickets.php?id=' . $ticketId),
    ]);
    if ($template['subject'] === '' || $template['body'] === '') {
        return false;
    }
    try {
        $mail = EmailService::_mailer_base();
        $mail->addAddress((string) $ticket['customer_email'], (string) ($ticket['customer_name'] ?? 'Customer'));
        $mail->Subject = $template['subject'];
        $mail->Body = $template['body'];
        $mail->send();
        return true;
    } catch (Throwable $e) {
        error_log('[support-tickets] customer notification failed: ' . $e->getMessage());
        return false;
    }
}

function support_tickets_handle_customer_post(mysqli $conn, int $customerId): void
{
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        return;
    }
    if (!function_exists('is_customer_logged_in') || !is_customer_logged_in() || $customerId <= 0) {
        require_customer();
    }
    if (!verify_csrf()) {
        flash('error', 'Invalid session token. Please try again.');
        redirect('/customer/support-tickets.php');
    }
    if (trim((string) ($_POST['company_website'] ?? '')) !== '') {
        flash('success', 'Support request received.');
        redirect('/customer/support-tickets.php');
    }

    $action = trim((string) ($_POST['support_action'] ?? ''));
    if (!in_array($action, ['create', 'reply'], true)) {
        flash('error', 'Invalid support request.');
        redirect('/customer/support-tickets.php');
    }
    $rateLimitScope = $action === 'reply' ? 'support_ticket_reply_' : 'support_ticket_create_';
    $rateLimitMax = $action === 'reply' ? 12 : 6;
    if (!public_form_rate_limit_allow($rateLimitScope . $customerId, $rateLimitMax, 900)) {
        flash('error', 'Too many support requests. Please wait a few minutes and try again.');
        redirect('/customer/support-tickets.php');
    }

    try {
        if ($action === 'create') {
            $ticketId = support_tickets_create(
                $conn,
                $customerId,
                (int) ($_POST['order_id'] ?? 0),
                trim((string) ($_POST['category'] ?? 'other')),
                trim((string) ($_POST['priority'] ?? 'normal')),
                trim((string) ($_POST['subject'] ?? '')),
                trim((string) ($_POST['message'] ?? ''))
            );
            flash('success', 'Support ticket submitted.');
            redirect('/customer/support-tickets.php?id=' . $ticketId);
        }

        if ($action === 'reply') {
            $ticketId = (int) ($_POST['ticket_id'] ?? 0);
            if ($ticketId <= 0) {
                throw new RuntimeException('Ticket not found.');
            }
            $ticket = support_tickets_get($conn, $ticketId, $customerId);
            if (!$ticket) {
                throw new RuntimeException('Ticket not found.');
            }
            if ((string) ($ticket['status'] ?? '') === 'closed') {
                throw new RuntimeException('This ticket is closed. Please create a new ticket if you need more help.');
            }
            $customer = support_tickets_customer($conn, $customerId);
            support_tickets_add_message($conn, $ticketId, 'customer', $customerId, (string) ($customer['name'] ?? 'Customer'), (string) ($_POST['message'] ?? ''));
            flash('success', 'Reply added.');
            redirect('/customer/support-tickets.php?id=' . $ticketId);
        }
    } catch (Throwable $e) {
        error_log('[support-tickets] customer action failed: ' . $e->getMessage());
        flash('error', $e->getMessage() !== '' ? $e->getMessage() : 'Unable to update support ticket.');
    }

    redirect('/customer/support-tickets.php');
}

function support_tickets_customer_orders(mysqli $conn, int $customerId): array
{
    $stmt = $conn->prepare(
        "SELECT id, order_number, created_at
         FROM orders
         WHERE customer_id = ?
         ORDER BY created_at DESC
         LIMIT 50"
    );
    $stmt->bind_param('i', $customerId);
    $stmt->execute();
    return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
}

function support_tickets_render_customer_page(mysqli $conn): void
{
    require_customer();
    $settings = support_tickets_settings();
    if (!$settings['enabled'] || !support_tickets_table_ready($conn)) {
        flash('error', 'Support tickets are not available yet.');
        redirect('/customer/orders.php');
    }

    $customerId = (int) ($_SESSION['customer_id'] ?? 0);
    support_tickets_handle_customer_post($conn, $customerId);

    $ticketId = (int) ($_GET['id'] ?? 0);
    $ticket = $ticketId > 0 ? support_tickets_get($conn, $ticketId, $customerId) : [];
    $messages = $ticket ? support_tickets_messages($conn, (int) $ticket['id'], false) : [];
    $orders = support_tickets_customer_orders($conn, $customerId);

    $stmt = $conn->prepare(
        "SELECT st.*, o.order_number
         FROM support_tickets st
         LEFT JOIN orders o ON o.id = st.order_id
         WHERE st.customer_id = ?
         ORDER BY st.last_message_at DESC, st.id DESC
         LIMIT 100"
    );
    $stmt->bind_param('i', $customerId);
    $stmt->execute();
    $tickets = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

    $categories = support_tickets_categories();
    $priorities = support_tickets_priorities();
    $statuses = support_tickets_statuses();
    $prefillOrderId = (int) ($_GET['order_id'] ?? 0);
    $canOpenAnyTicket = !empty($settings['allow_general_tickets']) || !empty($settings['allow_order_tickets']);
    $metaTitle = SiteContext::title($ticket ? 'Support Ticket' : 'Support Tickets');
    include dirname(__DIR__, 2) . '/includes/header.php';
    ?>
    <section class="page-hero">
        <div class="container">
            <h1>Support Tickets</h1>
            <p class="mb-0">Order support stays attached to your account and order history.</p>
        </div>
    </section>
    <section class="section-block">
        <div class="container">
            <div class="mb-3"><a href="/customer/orders.php" class="app-back-link">&larr; Back to My Orders</a></div>
            <div class="row g-4">
                <div class="col-lg-5">
                    <div class="surface-panel p-4 mb-4">
                        <h5 class="mb-3">Open a Ticket</h5>
                        <?php if (!$canOpenAnyTicket): ?>
                            <p class="text-muted mb-0">New support tickets are not available right now.</p>
                        <?php else: ?>
                        <form method="POST" action="/customer/support-tickets.php">
                            <?php echo csrf_field(); ?>
                            <input type="hidden" name="support_action" value="create">
                            <div class="d-none" aria-hidden="true">
                                <label>Website</label>
                                <input type="text" name="company_website" tabindex="-1" autocomplete="off">
                            </div>
                            <div class="mb-3">
                                <label class="form-label">Related Order</label>
                                <select name="order_id" class="form-select">
                                    <?php if (!empty($settings['allow_general_tickets'])): ?>
                                        <option value="0">No specific order</option>
                                    <?php endif; ?>
                                    <?php if (!empty($settings['allow_order_tickets'])): ?>
                                        <?php foreach ($orders as $order): ?>
                                            <option value="<?php echo (int) $order['id']; ?>" <?php echo $prefillOrderId === (int) $order['id'] ? 'selected' : ''; ?>>
                                                <?php echo e((string) $order['order_number']); ?> - <?php echo e(date('d M Y', strtotime((string) $order['created_at']))); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </select>
                            </div>
                            <div class="row g-2">
                                <div class="col-md-6">
                                    <label class="form-label">Category</label>
                                    <select name="category" class="form-select">
                                        <?php foreach ($categories as $value => $label): ?>
                                            <option value="<?php echo e($value); ?>"><?php echo e($label); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label">Priority</label>
                                    <select name="priority" class="form-select">
                                        <?php foreach ($priorities as $value => $label): ?>
                                            <option value="<?php echo e($value); ?>" <?php echo $value === 'normal' ? 'selected' : ''; ?>><?php echo e($label); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </div>
                            <div class="mt-3">
                                <label class="form-label">Subject</label>
                                <input class="form-control" name="subject" maxlength="160" required>
                            </div>
                            <div class="mt-3">
                                <label class="form-label">Message</label>
                                <textarea class="form-control" name="message" rows="5" maxlength="<?php echo (int) $settings['max_message_length']; ?>" required></textarea>
                            </div>
                            <button class="btn btn-primary w-100 mt-3" type="submit">Submit Ticket</button>
                        </form>
                        <?php endif; ?>
                    </div>
                    <div class="surface-panel p-4">
                        <h5 class="mb-3">Your Tickets</h5>
                        <?php if (empty($tickets)): ?>
                            <p class="text-muted mb-0">No support tickets yet.</p>
                        <?php endif; ?>
                        <?php foreach ($tickets as $row): ?>
                            <a class="d-block border rounded p-3 mb-2 text-decoration-none" href="/customer/support-tickets.php?id=<?php echo (int) $row['id']; ?>">
                                <div class="d-flex justify-content-between gap-2">
                                    <strong><?php echo e((string) $row['ticket_number']); ?></strong>
                                    <span class="badge bg-secondary"><?php echo e($statuses[(string) $row['status']] ?? (string) $row['status']); ?></span>
                                </div>
                                <div class="text-dark"><?php echo e((string) $row['subject']); ?></div>
                                <div class="small text-muted">
                                    <?php echo !empty($row['order_number']) ? 'Order ' . e((string) $row['order_number']) . ' | ' : ''; ?>
                                    <?php echo e((string) $row['last_message_at']); ?>
                                </div>
                            </a>
                        <?php endforeach; ?>
                    </div>
                </div>
                <div class="col-lg-7">
                    <?php if (!$ticket): ?>
                        <div class="surface-panel p-5 text-center text-muted">Select a ticket to view the conversation.</div>
                    <?php else: ?>
                        <div class="surface-panel p-4">
                            <div class="d-flex justify-content-between align-items-start gap-3 flex-wrap mb-3">
                                <div>
                                    <h5 class="mb-1"><?php echo e((string) $ticket['subject']); ?></h5>
                                    <div class="small text-muted">
                                        <?php echo e((string) $ticket['ticket_number']); ?>
                                        <?php if (!empty($ticket['order_number'])): ?> | Order <?php echo e((string) $ticket['order_number']); ?><?php endif; ?>
                                    </div>
                                </div>
                                <span class="badge bg-secondary"><?php echo e($statuses[(string) $ticket['status']] ?? (string) $ticket['status']); ?></span>
                            </div>
                            <?php foreach ($messages as $msg): ?>
                                <?php $isCustomer = (string) ($msg['sender_type'] ?? '') === 'customer'; ?>
                                <div class="border rounded p-3 mb-2 <?php echo $isCustomer ? 'bg-light' : ''; ?>">
                                    <div class="d-flex justify-content-between gap-2 flex-wrap small text-muted mb-1">
                                        <span><?php echo e((string) ($msg['sender_name'] ?? ucfirst((string) $msg['sender_type']))); ?></span>
                                        <span><?php echo e((string) $msg['created_at']); ?></span>
                                    </div>
                                    <div><?php echo nl2br(e((string) $msg['message'])); ?></div>
                                </div>
                            <?php endforeach; ?>
                            <?php if ((string) $ticket['status'] !== 'closed'): ?>
                                <form method="POST" action="/customer/support-tickets.php?id=<?php echo (int) $ticket['id']; ?>" class="mt-3">
                                    <?php echo csrf_field(); ?>
                                    <input type="hidden" name="support_action" value="reply">
                                    <input type="hidden" name="ticket_id" value="<?php echo (int) $ticket['id']; ?>">
                                    <label class="form-label">Reply</label>
                                    <textarea class="form-control" name="message" rows="4" maxlength="<?php echo (int) $settings['max_message_length']; ?>" required></textarea>
                                    <button class="btn btn-primary mt-2" type="submit">Send Reply</button>
                                </form>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </section>
    <?php
    include dirname(__DIR__, 2) . '/includes/footer.php';
}

function support_tickets_handle_admin_post(mysqli $conn): void
{
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        return;
    }
    require_admin();
    $ticketId = (int) ($_POST['ticket_id'] ?? 0);
    $returnUrl = '/admin/support-tickets.php' . ($ticketId > 0 ? '?id=' . $ticketId : '');
    if (!verify_csrf()) {
        flash('error', 'Invalid token. Please try again.');
        redirect($returnUrl);
    }
    $adminId = (int) ($_SESSION['admin_id'] ?? 0);
    $adminName = (string) ($_SESSION['admin_name'] ?? 'admin');
    $action = trim((string) ($_POST['support_action'] ?? ''));
    if ($ticketId <= 0 || !in_array($action, ['reply', 'internal_note', 'status'], true)) {
        flash('error', 'Invalid support ticket action.');
        redirect('/admin/support-tickets.php');
    }
    try {
        if ($action === 'reply') {
            $ticket = support_tickets_get($conn, $ticketId);
            if (!$ticket || (string) ($ticket['status'] ?? '') === 'closed') {
                throw new RuntimeException('This ticket cannot receive replies.');
            }
            support_tickets_add_message($conn, $ticketId, 'admin', $adminId, $adminName, (string) ($_POST['message'] ?? ''), false);
            flash('success', 'Reply sent.');
        } elseif ($action === 'internal_note') {
            support_tickets_add_message($conn, $ticketId, 'admin', $adminId, $adminName, (string) ($_POST['message'] ?? ''), true);
            flash('success', 'Internal note saved.');
        } elseif ($action === 'status') {
            support_tickets_update_status($conn, $ticketId, trim((string) ($_POST['status'] ?? '')), $adminId, $adminName);
            flash('success', 'Ticket status updated.');
        }
    } catch (Throwable $e) {
        error_log('[support-tickets] admin action failed: ' . $e->getMessage());
        flash('error', $e->getMessage() !== '' ? $e->getMessage() : 'Unable to update ticket.');
    }
    redirect($returnUrl);
}

function support_tickets_render_admin_page(mysqli $conn): void
{
    require_admin();
    $settings = support_tickets_settings();
    if (!$settings['enabled'] || !support_tickets_table_ready($conn)) {
        $metaTitle = 'Support Tickets | Admin';
        include dirname(__DIR__, 2) . '/admin/partials/header.php';
        echo '<div class="alert alert-warning">Support ticket tables are not ready. Run <code>php database/migrate.php --only=2026-06-27-support-tickets-plugin.sql</code>.</div>';
        include dirname(__DIR__, 2) . '/admin/partials/footer.php';
        return;
    }

    support_tickets_handle_admin_post($conn);

    $statuses = support_tickets_statuses();
    $statusFilter = trim((string) ($_GET['status'] ?? ''));
    if (!isset($statuses[$statusFilter])) {
        $statusFilter = '';
    }
    $q = trim((string) ($_GET['q'] ?? ''));
    $perPageOptions = [20, 50, 100];
    $perPage = list_sanitize_per_page((int) ($_GET['per_page'] ?? 20), $perPageOptions);
    $page = list_sanitize_page((int) ($_GET['page'] ?? 1));
    $ticketId = (int) ($_GET['id'] ?? 0);

    $where = [];
    $types = '';
    $params = [];
    if ($statusFilter !== '') {
        $where[] = 'st.status = ?';
        $types .= 's';
        $params[] = $statusFilter;
    }
    if ($q !== '') {
        $where[] = '(st.ticket_number LIKE ? OR st.subject LIKE ? OR c.name LIKE ? OR c.email LIKE ? OR o.order_number LIKE ?)';
        $like = '%' . $q . '%';
        $types .= 'sssss';
        array_push($params, $like, $like, $like, $like, $like);
    }
    $whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';
    $countStmt = $conn->prepare(
        "SELECT COUNT(*) AS total
         FROM support_tickets st
         JOIN customers c ON c.id = st.customer_id
         LEFT JOIN orders o ON o.id = st.order_id
         {$whereSql}"
    );
    if ($types !== '') {
        $countStmt->bind_param($types, ...$params);
    }
    $countStmt->execute();
    $total = (int) ($countStmt->get_result()->fetch_assoc()['total'] ?? 0);
    $pages = max(1, (int) ceil($total / $perPage));
    $page = list_clamp_page($page, $pages);
    $offset = ($page - 1) * $perPage;

    $listStmt = $conn->prepare(
        "SELECT st.*, c.name AS customer_name, c.email AS customer_email, o.order_number
         FROM support_tickets st
         JOIN customers c ON c.id = st.customer_id
         LEFT JOIN orders o ON o.id = st.order_id
         {$whereSql}
         ORDER BY FIELD(st.status, 'open', 'waiting_admin', 'waiting_customer', 'resolved', 'closed'),
                  FIELD(st.priority, 'urgent', 'high', 'normal', 'low'),
                  st.last_message_at DESC
         LIMIT ? OFFSET ?"
    );
    $listTypes = $types . 'ii';
    $listParams = array_merge($params, [$perPage, $offset]);
    $listStmt->bind_param($listTypes, ...$listParams);
    $listStmt->execute();
    $tickets = $listStmt->get_result()->fetch_all(MYSQLI_ASSOC);

    $ticket = $ticketId > 0 ? support_tickets_get($conn, $ticketId) : [];
    $messages = $ticket ? support_tickets_messages($conn, (int) $ticket['id'], true) : [];
    $categories = support_tickets_categories();
    $priorities = support_tickets_priorities();

    $metaTitle = 'Support Tickets | Admin';
    include dirname(__DIR__, 2) . '/admin/partials/header.php';
    ?>
    <div class="admin-page-header d-flex justify-content-between align-items-end flex-wrap gap-3 mb-3">
        <div>
            <h1 class="mb-1">Support Tickets</h1>
            <p class="text-muted mb-0">Account and order support conversations.</p>
        </div>
    </div>

    <form method="GET" action="support-tickets.php" class="row g-2 mb-3 admin-filter-form">
        <div class="col-md-3">
            <label class="form-label">Status</label>
            <select name="status" class="form-select">
                <option value="">All</option>
                <?php foreach ($statuses as $value => $label): ?>
                    <option value="<?php echo e($value); ?>" <?php echo $statusFilter === $value ? 'selected' : ''; ?>><?php echo e($label); ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-6">
            <label class="form-label">Search</label>
            <input name="q" class="form-control" value="<?php echo e($q); ?>" placeholder="Ticket, subject, customer, email, or order">
        </div>
        <div class="col-md-1">
            <label class="form-label">Rows</label>
            <select name="per_page" class="form-select">
                <?php foreach ($perPageOptions as $opt): ?>
                    <option value="<?php echo (int) $opt; ?>" <?php echo $perPage === (int) $opt ? 'selected' : ''; ?>><?php echo (int) $opt; ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-2 d-flex align-items-end gap-2 admin-filter-actions">
            <button class="btn btn-primary w-100" type="submit">Apply</button>
            <a class="btn btn-outline-secondary w-100" href="support-tickets.php">Reset</a>
        </div>
    </form>

    <div class="row g-4">
        <div class="col-lg-5">
            <div class="table-responsive">
                <table class="table table-hover align-middle admin-card-table">
                    <thead>
                        <tr>
                            <th>Ticket</th>
                            <th>Customer</th>
                            <th>Status</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($tickets)): ?>
                            <tr><td colspan="4" class="text-center text-muted py-4">No tickets found.</td></tr>
                        <?php endif; ?>
                        <?php foreach ($tickets as $row): ?>
                            <tr>
                                <td>
                                    <div class="fw-semibold"><?php echo e((string) $row['ticket_number']); ?></div>
                                    <div><?php echo e((string) $row['subject']); ?></div>
                                    <div class="small text-muted">
                                        <?php echo e($categories[(string) $row['category']] ?? (string) $row['category']); ?>
                                        | <?php echo e($priorities[(string) $row['priority']] ?? (string) $row['priority']); ?>
                                        <?php if (!empty($row['order_number'])): ?> | <?php echo e((string) $row['order_number']); ?><?php endif; ?>
                                    </div>
                                </td>
                                <td>
                                    <?php echo e((string) $row['customer_name']); ?>
                                    <div class="small text-muted"><?php echo e((string) $row['customer_email']); ?></div>
                                </td>
                                <td><span class="badge bg-secondary"><?php echo e($statuses[(string) $row['status']] ?? (string) $row['status']); ?></span></td>
                                <td><a class="btn btn-sm btn-outline-primary" href="support-tickets.php?id=<?php echo (int) $row['id']; ?>">View</a></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php echo render_pagination($page, $pages, ['status' => $statusFilter, 'q' => $q, 'per_page' => $perPage], 'page', $total, $perPage); ?>
        </div>
        <div class="col-lg-7">
            <?php if (!$ticket): ?>
                <div class="surface-panel p-5 text-center text-muted">Select a ticket to view details.</div>
            <?php else: ?>
                <div class="surface-panel p-4">
                    <div class="d-flex justify-content-between align-items-start gap-3 flex-wrap mb-3">
                        <div>
                            <h5 class="mb-1"><?php echo e((string) $ticket['subject']); ?></h5>
                            <div class="small text-muted">
                                <?php echo e((string) $ticket['ticket_number']); ?>
                                | <?php echo e((string) $ticket['customer_name']); ?>
                                <?php if (!empty($ticket['order_number'])): ?>
                                    | <a href="order-view.php?id=<?php echo (int) $ticket['order_id']; ?>"><?php echo e((string) $ticket['order_number']); ?></a>
                                <?php endif; ?>
                            </div>
                        </div>
                        <span class="badge bg-secondary"><?php echo e($statuses[(string) $ticket['status']] ?? (string) $ticket['status']); ?></span>
                    </div>
                    <form method="POST" action="support-tickets.php?id=<?php echo (int) $ticket['id']; ?>" class="row g-2 align-items-end mb-3">
                        <?php echo csrf_field(); ?>
                        <input type="hidden" name="support_action" value="status">
                        <input type="hidden" name="ticket_id" value="<?php echo (int) $ticket['id']; ?>">
                        <div class="col-md-8">
                            <label class="form-label">Status</label>
                            <select name="status" class="form-select">
                                <?php foreach ($statuses as $value => $label): ?>
                                    <option value="<?php echo e($value); ?>" <?php echo (string) $ticket['status'] === $value ? 'selected' : ''; ?>><?php echo e($label); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-4"><button class="btn btn-outline-primary w-100" type="submit">Update</button></div>
                    </form>
                    <?php foreach ($messages as $msg): ?>
                        <?php $internal = (int) ($msg['is_internal'] ?? 0) === 1; ?>
                        <div class="border rounded p-3 mb-2 <?php echo $internal ? 'bg-warning-subtle' : ''; ?>">
                            <div class="d-flex justify-content-between gap-2 flex-wrap small text-muted mb-1">
                                <span><?php echo e((string) ($msg['sender_name'] ?? ucfirst((string) $msg['sender_type']))); ?><?php echo $internal ? ' (internal)' : ''; ?></span>
                                <span><?php echo e((string) $msg['created_at']); ?></span>
                            </div>
                            <div><?php echo nl2br(e((string) $msg['message'])); ?></div>
                        </div>
                    <?php endforeach; ?>
                    <?php if ((string) $ticket['status'] !== 'closed'): ?>
                        <form method="POST" action="support-tickets.php?id=<?php echo (int) $ticket['id']; ?>" class="mt-3">
                            <?php echo csrf_field(); ?>
                            <input type="hidden" name="support_action" value="reply">
                            <input type="hidden" name="ticket_id" value="<?php echo (int) $ticket['id']; ?>">
                            <label class="form-label">Reply to Customer</label>
                            <textarea class="form-control" name="message" rows="4" maxlength="<?php echo (int) $settings['max_message_length']; ?>" required></textarea>
                            <button class="btn btn-primary mt-2" type="submit">Send Reply</button>
                        </form>
                    <?php endif; ?>
                    <form method="POST" action="support-tickets.php?id=<?php echo (int) $ticket['id']; ?>" class="mt-3">
                        <?php echo csrf_field(); ?>
                        <input type="hidden" name="support_action" value="internal_note">
                        <input type="hidden" name="ticket_id" value="<?php echo (int) $ticket['id']; ?>">
                        <label class="form-label">Internal Note</label>
                        <textarea class="form-control" name="message" rows="3" maxlength="<?php echo (int) $settings['max_message_length']; ?>" required></textarea>
                        <button class="btn btn-outline-secondary mt-2" type="submit">Save Note</button>
                    </form>
                </div>
            <?php endif; ?>
        </div>
    </div>
    <?php
    include dirname(__DIR__, 2) . '/admin/partials/footer.php';
}

function support_tickets_render_order_panel(array $context): void
{
    $settings = support_tickets_settings();
    $conn = $context['conn'] ?? null;
    $order = isset($context['order']) && is_array($context['order']) ? $context['order'] : [];
    $shipment = isset($context['shipment']) && is_array($context['shipment']) ? $context['shipment'] : [];
    $returnRequest = isset($context['return_request']) && is_array($context['return_request']) ? $context['return_request'] : null;
    $orderId = (int) ($context['order_id'] ?? 0);
    $customerId = (int) ($context['customer_id'] ?? 0);
    if (
        !$settings['enabled']
        || !$conn instanceof mysqli
        || $orderId <= 0
        || $customerId <= 0
        || !function_exists('is_customer_logged_in')
        || !is_customer_logged_in()
        || !support_tickets_table_ready($conn)
    ) {
        return;
    }
    $stmt = $conn->prepare(
        "SELECT id, ticket_number, subject, category, priority, status, last_message_at
         FROM support_tickets
         WHERE customer_id = ? AND order_id = ?
         ORDER BY last_message_at DESC
         LIMIT 10"
    );
    $stmt->bind_param('ii', $customerId, $orderId);
    $stmt->execute();
    $tickets = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $statuses = support_tickets_statuses();
    $categories = support_tickets_categories();
    $priorities = support_tickets_priorities();
    $effectiveOrderStatus = strtolower((string) ($order['order_status'] ?? $order['status'] ?? ''));
    $deliveredAt = trim((string) ($shipment['delivered_at'] ?? ''));
    $isWithinReturnWindow = $deliveredAt !== '' && strtotime($deliveredAt) >= strtotime('-7 days');
    $canUseReturnFlow = $effectiveOrderStatus === 'delivered' && $isWithinReturnWindow && !$returnRequest;
    ?>
    <section class="section-block pt-0">
        <div class="container">
            <div class="surface-panel p-4">
                <div class="d-flex justify-content-between align-items-start gap-3 flex-wrap mb-3">
                    <div>
                        <h5 class="mb-1">Need Help With This Order?</h5>
                        <p class="text-muted mb-0 small">Create an order-linked support ticket or review existing conversations.</p>
                    </div>
                    <a class="btn btn-outline-secondary" href="/customer/support-tickets.php?order_id=<?php echo $orderId; ?>">View Support Center</a>
                </div>

                <?php if ($returnRequest): ?>
                    <div class="alert alert-secondary py-2 small">
                        Return request <?php echo e((string) ($returnRequest['return_number'] ?? '')); ?> is already in the returns workflow with status <?php echo e(strtoupper(str_replace('_', ' ', (string) ($returnRequest['status'] ?? '')))); ?>.
                    </div>
                <?php elseif ($canUseReturnFlow): ?>
                    <div class="alert alert-info py-2 small">
                        For returns or refunds, use the existing return request form on this order page. Support tickets do not create returns.
                    </div>
                <?php endif; ?>

                <?php if (!empty($settings['allow_order_tickets'])): ?>
                    <form method="POST" action="/customer/support-tickets.php" class="border rounded p-3 mb-3">
                        <?php echo csrf_field(); ?>
                        <input type="hidden" name="support_action" value="create">
                        <input type="hidden" name="order_id" value="<?php echo $orderId; ?>">
                        <div class="d-none" aria-hidden="true">
                            <label>Website</label>
                            <input type="text" name="company_website" tabindex="-1" autocomplete="off">
                        </div>
                        <div class="row g-2">
                            <div class="col-md-4">
                                <label class="form-label">Category</label>
                                <select name="category" class="form-select">
                                    <?php foreach ($categories as $value => $label): ?>
                                        <option value="<?php echo e($value); ?>" <?php echo $value === 'order' ? 'selected' : ''; ?>><?php echo e($label); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">Priority</label>
                                <select name="priority" class="form-select">
                                    <?php foreach ($priorities as $value => $label): ?>
                                        <option value="<?php echo e($value); ?>" <?php echo $value === 'normal' ? 'selected' : ''; ?>><?php echo e($label); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">Subject</label>
                                <input class="form-control" name="subject" maxlength="160" required>
                            </div>
                            <div class="col-12">
                                <label class="form-label">Message</label>
                                <textarea class="form-control" name="message" rows="3" maxlength="<?php echo (int) $settings['max_message_length']; ?>" required></textarea>
                            </div>
                            <div class="col-12">
                                <button class="btn btn-primary" type="submit">Submit Ticket</button>
                            </div>
                        </div>
                    </form>
                <?php else: ?>
                    <p class="text-muted small mb-3">Order support tickets are not available right now.</p>
                <?php endif; ?>

                <?php if (!empty($tickets)): ?>
                    <div class="border-top mt-3 pt-3">
                        <?php foreach ($tickets as $ticket): ?>
                            <?php $messages = support_tickets_messages($conn, (int) $ticket['id'], false); ?>
                            <div class="border rounded p-3 mb-3">
                                <div class="d-flex justify-content-between gap-2 flex-wrap mb-2">
                                    <div>
                                        <strong><?php echo e((string) $ticket['ticket_number']); ?></strong>
                                        <div><?php echo e((string) $ticket['subject']); ?></div>
                                        <div class="small text-muted">
                                            <?php echo e($categories[(string) $ticket['category']] ?? (string) $ticket['category']); ?>
                                            | <?php echo e($priorities[(string) $ticket['priority']] ?? (string) $ticket['priority']); ?>
                                            | Last update: <?php echo e((string) $ticket['last_message_at']); ?>
                                        </div>
                                    </div>
                                    <span class="badge bg-secondary align-self-start"><?php echo e($statuses[(string) $ticket['status']] ?? (string) $ticket['status']); ?></span>
                                </div>
                                <?php if (empty($messages)): ?>
                                    <p class="text-muted small mb-0">No messages captured for this ticket.</p>
                                <?php endif; ?>
                                <?php foreach ($messages as $msg): ?>
                                    <?php $isCustomer = (string) ($msg['sender_type'] ?? '') === 'customer'; ?>
                                    <div class="border rounded p-2 mb-2 <?php echo $isCustomer ? 'bg-light' : ''; ?>">
                                        <div class="d-flex justify-content-between gap-2 flex-wrap small text-muted mb-1">
                                            <span><?php echo e((string) ($msg['sender_name'] ?? ucfirst((string) $msg['sender_type']))); ?></span>
                                            <span><?php echo e((string) $msg['created_at']); ?></span>
                                        </div>
                                        <div class="small"><?php echo nl2br(e((string) $msg['message'])); ?></div>
                                    </div>
                                <?php endforeach; ?>
                                <?php if ((string) $ticket['status'] !== 'closed'): ?>
                                    <form method="POST" action="/customer/support-tickets.php?id=<?php echo (int) $ticket['id']; ?>" class="mt-2">
                                        <?php echo csrf_field(); ?>
                                        <input type="hidden" name="support_action" value="reply">
                                        <input type="hidden" name="ticket_id" value="<?php echo (int) $ticket['id']; ?>">
                                        <label class="form-label small">Reply</label>
                                        <textarea class="form-control form-control-sm" name="message" rows="2" maxlength="<?php echo (int) $settings['max_message_length']; ?>" required></textarea>
                                        <button class="btn btn-sm btn-outline-primary mt-2" type="submit">Send Reply</button>
                                    </form>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php else: ?>
                    <p class="text-muted small mb-0">No support tickets for this order yet.</p>
                <?php endif; ?>
            </div>
        </div>
    </section>
    <?php
}

function support_tickets_render_admin_order_sidebar(array $context): void
{
    $settings = support_tickets_settings();
    $conn = $context['conn'] ?? null;
    $order = isset($context['order']) && is_array($context['order']) ? $context['order'] : [];
    $orderId = (int) ($order['id'] ?? 0);
    if (!$settings['enabled'] || !$conn instanceof mysqli || $orderId <= 0 || !support_tickets_table_ready($conn)) {
        return;
    }

    $stmt = $conn->prepare(
        "SELECT st.id, st.ticket_number, st.subject, st.category, st.priority, st.status, st.last_message_at,
                c.name AS customer_name, c.email AS customer_email
         FROM support_tickets st
         JOIN customers c ON c.id = st.customer_id
         WHERE st.order_id = ?
         ORDER BY st.last_message_at DESC
         LIMIT 5"
    );
    $stmt->bind_param('i', $orderId);
    $stmt->execute();
    $tickets = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $statuses = support_tickets_statuses();
    $categories = support_tickets_categories();
    $priorities = support_tickets_priorities();
    ?>
    <div class="card mb-4">
        <div class="card-body">
            <div class="d-flex justify-content-between align-items-start gap-2 mb-2">
                <h6 class="card-title mb-0">Support Tickets</h6>
                <a class="btn btn-sm btn-outline-primary" href="support-tickets.php?q=<?php echo e((string) ($order['order_number'] ?? '')); ?>">View</a>
            </div>
            <?php if (empty($tickets)): ?>
                <p class="text-muted small mb-0">No support tickets linked to this order.</p>
            <?php endif; ?>
            <?php foreach ($tickets as $ticket): ?>
                <div class="border rounded p-2 mb-2">
                    <div class="d-flex justify-content-between gap-2">
                        <a class="fw-semibold" href="support-tickets.php?id=<?php echo (int) $ticket['id']; ?>"><?php echo e((string) $ticket['ticket_number']); ?></a>
                        <span class="badge bg-secondary"><?php echo e($statuses[(string) $ticket['status']] ?? (string) $ticket['status']); ?></span>
                    </div>
                    <div class="small"><?php echo e((string) $ticket['subject']); ?></div>
                    <div class="small text-muted">
                        <?php echo e($categories[(string) $ticket['category']] ?? (string) $ticket['category']); ?>
                        | <?php echo e($priorities[(string) $ticket['priority']] ?? (string) $ticket['priority']); ?>
                    </div>
                    <div class="small text-muted"><?php echo e((string) $ticket['customer_name']); ?> | <?php echo e((string) $ticket['last_message_at']); ?></div>
                    <form method="POST" action="order-view.php?id=<?php echo $orderId; ?>" class="mt-2">
                        <?php echo csrf_field(); ?>
                        <input type="hidden" name="action" value="support_ticket_status">
                        <input type="hidden" name="ticket_id" value="<?php echo (int) $ticket['id']; ?>">
                        <div class="d-flex gap-1">
                            <select name="status" class="form-select form-select-sm">
                                <?php foreach ($statuses as $statusValue => $statusLabel): ?>
                                    <option value="<?php echo e($statusValue); ?>" <?php echo (string) $ticket['status'] === $statusValue ? 'selected' : ''; ?>><?php echo e($statusLabel); ?></option>
                                <?php endforeach; ?>
                            </select>
                            <button class="btn btn-sm btn-outline-primary" type="submit">Update</button>
                        </div>
                    </form>
                    <?php if ((string) $ticket['status'] !== 'closed'): ?>
                        <form method="POST" action="order-view.php?id=<?php echo $orderId; ?>" class="mt-2">
                            <?php echo csrf_field(); ?>
                            <input type="hidden" name="action" value="support_ticket_reply">
                            <input type="hidden" name="ticket_id" value="<?php echo (int) $ticket['id']; ?>">
                            <textarea name="message" class="form-control form-control-sm" rows="2" maxlength="<?php echo (int) $settings['max_message_length']; ?>" placeholder="Reply to customer" required></textarea>
                            <button class="btn btn-sm btn-outline-secondary mt-1" type="submit">Reply</button>
                        </form>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
    <?php
}
