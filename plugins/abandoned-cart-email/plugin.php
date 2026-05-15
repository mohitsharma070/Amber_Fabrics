<?php

add_action('app.init', 'abandoned_cart_capture_activity', 20);
add_action('order.after_create', 'abandoned_cart_mark_recovered', 20);
add_action('cron.tick', 'abandoned_cart_send_reminders', 20);

function abandoned_cart_settings(): array
{
    return [
        'enabled' => (int) plugin_setting('abandoned-cart-email', 'enabled', 1) === 1,
        'delay_minutes' => max(10, (int) plugin_setting('abandoned-cart-email', 'delay_minutes', 60)),
        'max_emails' => max(1, (int) plugin_setting('abandoned-cart-email', 'max_emails', 1)),
    ];
}

function abandoned_cart_table_ready(mysqli $conn): bool
{
    try {
        $stmt = $conn->prepare(
            "SELECT COUNT(*) AS total
             FROM information_schema.TABLES
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = 'abandoned_cart_reminders'"
        );
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        return ((int) ($row['total'] ?? 0)) > 0;
    } catch (Throwable $e) {
        error_log('[abandoned-cart-email] table check failed: ' . $e->getMessage());
        return false;
    }
}

function abandoned_cart_parse_key(string $rawKey): array
{
    $parts = explode('::', $rawKey, 2);
    $pid = (int) ($parts[0] ?? 0);
    $size = '';
    if (isset($parts[1])) {
        $decoded = rawurldecode((string) $parts[1]);
        if ($decoded !== '_' && $decoded !== '') {
            $size = $decoded;
        }
    }
    return [$pid, $size];
}

function abandoned_cart_snapshot(mysqli $conn, array $cart): array
{
    $lines = [];
    $ids = [];
    foreach ($cart as $cartKey => $qty) {
        [$pid] = abandoned_cart_parse_key((string) $cartKey);
        if ($pid > 0) {
            $ids[] = $pid;
        }
    }
    $ids = array_values(array_unique($ids));
    if (empty($ids)) {
        return ['items_count' => 0, 'subtotal' => 0.0, 'summary' => ''];
    }

    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $types = str_repeat('i', count($ids));
    $stmt = $conn->prepare(
        "SELECT id, name, unit_type, price, sale_price, price_inr
         FROM fabrics
         WHERE status = 'active' AND id IN ($placeholders)"
    );
    $stmt->bind_param($types, ...$ids);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $map = [];
    foreach ($rows as $row) {
        $map[(int) $row['id']] = $row;
    }

    $subtotal = 0.0;
    $itemsCount = 0;
    foreach ($cart as $cartKey => $qtyRaw) {
        [$pid, $size] = abandoned_cart_parse_key((string) $cartKey);
        if ($pid <= 0 || !isset($map[$pid])) {
            continue;
        }
        $row = $map[$pid];
        $unitType = in_array((string) ($row['unit_type'] ?? ''), ['meter', 'piece', 'set'], true)
            ? (string) $row['unit_type']
            : 'meter';
        $qty = normalize_quantity_by_unit($qtyRaw ?? 1, $unitType);
        $regular = (float) (($row['price'] !== null && $row['price'] !== '') ? $row['price'] : ($row['price_inr'] ?? 0));
        $sale = (float) ($row['sale_price'] ?? 0);
        $unitPrice = ($sale > 0 && $sale < $regular) ? $sale : $regular;
        $lineTotal = round($unitPrice * $qty, 2);
        $subtotal = round($subtotal + $lineTotal, 2);
        $itemsCount++;
        $line = (string) $row['name'] . ' - ' . format_quantity_by_unit($qty, $unitType) . quantity_unit_suffix($unitType);
        if ($size !== '') {
            $line .= ' | Size: ' . $size;
        }
        $lines[] = $line;
    }

    return [
        'items_count' => $itemsCount,
        'subtotal' => $subtotal,
        'summary' => implode("\n", array_slice($lines, 0, 10)),
    ];
}

function abandoned_cart_capture_activity(array $context): void
{
    $settings = abandoned_cart_settings();
    if (!$settings['enabled']) {
        return;
    }
    if (PHP_SAPI === 'cli') {
        return;
    }
    $conn = $GLOBALS['conn'] ?? null;
    if (!$conn instanceof mysqli || !abandoned_cart_table_ready($conn)) {
        return;
    }

    $customerId = (int) ($_SESSION['customer_id'] ?? 0);
    $cart = isset($_SESSION['cart']) && is_array($_SESSION['cart']) ? $_SESSION['cart'] : [];
    if ($customerId <= 0 || empty($cart)) {
        return;
    }

    $cStmt = $conn->prepare("SELECT id, name, email FROM customers WHERE id = ? LIMIT 1");
    $cStmt->bind_param('i', $customerId);
    $cStmt->execute();
    $customer = $cStmt->get_result()->fetch_assoc();
    if (!$customer) {
        return;
    }
    $email = trim((string) ($customer['email'] ?? ''));
    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return;
    }

    $snapshot = abandoned_cart_snapshot($conn, $cart);
    if ((int) ($snapshot['items_count'] ?? 0) <= 0) {
        return;
    }

    $cartJson = json_encode($cart);
    $cartHash = substr(hash('sha256', is_string($cartJson) ? $cartJson : ''), 0, 64);
    $summary = (string) ($snapshot['summary'] ?? '');
    $itemsCount = (int) ($snapshot['items_count'] ?? 0);
    $subtotal = (float) ($snapshot['subtotal'] ?? 0.0);
    $nextSend = date('Y-m-d H:i:s', time() + ($settings['delay_minutes'] * 60));

    $stmt = $conn->prepare(
        "INSERT INTO abandoned_cart_reminders
            (customer_id, customer_email, customer_name, cart_hash, items_count, subtotal_amount, cart_summary, status, next_send_at, last_activity_at)
         VALUES (?, ?, ?, ?, ?, ?, ?, 'active', ?, NOW())
         ON DUPLICATE KEY UPDATE
            customer_email = VALUES(customer_email),
            customer_name = VALUES(customer_name),
            cart_hash = VALUES(cart_hash),
            items_count = VALUES(items_count),
            subtotal_amount = VALUES(subtotal_amount),
            cart_summary = VALUES(cart_summary),
            status = 'active',
            recovered_at = NULL,
            next_send_at = VALUES(next_send_at),
            last_activity_at = NOW(),
            updated_at = NOW()"
    );
    $customerName = (string) ($customer['name'] ?? '');
    $stmt->bind_param('isssidss', $customerId, $email, $customerName, $cartHash, $itemsCount, $subtotal, $summary, $nextSend);
    $stmt->execute();
}

function abandoned_cart_mark_recovered(array $context): void
{
    $conn = $context['conn'] ?? null;
    if (!$conn instanceof mysqli || !abandoned_cart_table_ready($conn)) {
        return;
    }
    $customerId = (int) ($context['customer_id'] ?? 0);
    if ($customerId <= 0) {
        return;
    }
    $stmt = $conn->prepare(
        "UPDATE abandoned_cart_reminders
         SET status = 'recovered',
             recovered_at = NOW(),
             updated_at = NOW()
         WHERE customer_id = ? AND status = 'active'"
    );
    $stmt->bind_param('i', $customerId);
    $stmt->execute();
}

function abandoned_cart_send_one_email(string $email, string $name, int $itemsCount, float $subtotal, string $summary): bool
{
    $appUrl = rtrim(_cfg('APP_URL', ''), '/');
    if ($appUrl === '') {
        $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $host = (string) ($_SERVER['SERVER_NAME'] ?? 'localhost');
        $appUrl = $protocol . '://' . $host;
    }
    $cartUrl = $appUrl . '/cart.php';
    $template = email_template_build('abandoned_cart_reminder', [
        'customer_name' => $name !== '' ? $name : 'Customer',
        'items_count' => $itemsCount,
        'subtotal' => $subtotal,
        'cart_summary' => $summary,
        'cart_url' => $cartUrl,
    ]);
    if ($template['subject'] === '' || $template['body'] === '') {
        return false;
    }

    try {
        $mail = _mailer_base();
        $mail->addAddress($email, $name !== '' ? $name : 'Customer');
        $mail->Subject = $template['subject'];
        $mail->Body = $template['body'];
        $mail->send();
        return true;
    } catch (Throwable $e) {
        error_log('[abandoned-cart-email] send failed: ' . $e->getMessage());
        return false;
    }
}

function abandoned_cart_send_reminders(array $context): void
{
    $settings = abandoned_cart_settings();
    if (!$settings['enabled']) {
        return;
    }
    $conn = $context['conn'] ?? ($GLOBALS['conn'] ?? null);
    if (!$conn instanceof mysqli || !abandoned_cart_table_ready($conn)) {
        return;
    }

    $stmt = $conn->prepare(
        "SELECT id, customer_id, customer_email, customer_name, items_count, subtotal_amount, cart_summary, emails_sent_count
         FROM abandoned_cart_reminders
         WHERE status = 'active'
           AND next_send_at IS NOT NULL
           AND next_send_at <= NOW()
           AND emails_sent_count < ?
         ORDER BY next_send_at ASC
         LIMIT 50"
    );
    $maxEmails = (int) $settings['max_emails'];
    $stmt->bind_param('i', $maxEmails);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

    foreach ($rows as $row) {
        $id = (int) ($row['id'] ?? 0);
        if ($id <= 0) {
            continue;
        }
        $email = trim((string) ($row['customer_email'] ?? ''));
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $invalid = $conn->prepare(
                "UPDATE abandoned_cart_reminders
                 SET status = 'completed',
                     next_send_at = NULL,
                     updated_at = NOW()
                 WHERE id = ?"
            );
            $invalid->bind_param('i', $id);
            $invalid->execute();
            continue;
        }

        $sent = abandoned_cart_send_one_email(
            $email,
            (string) ($row['customer_name'] ?? ''),
            (int) ($row['items_count'] ?? 0),
            (float) ($row['subtotal_amount'] ?? 0),
            (string) ($row['cart_summary'] ?? '')
        );

        if ($sent) {
            $upd = $conn->prepare(
                "UPDATE abandoned_cart_reminders
                 SET emails_sent_count = emails_sent_count + 1,
                     last_sent_at = NOW(),
                     status = CASE WHEN emails_sent_count + 1 >= ? THEN 'completed' ELSE status END,
                     next_send_at = CASE WHEN emails_sent_count + 1 >= ? THEN NULL ELSE DATE_ADD(NOW(), INTERVAL ? MINUTE) END,
                     updated_at = NOW()
                 WHERE id = ?"
            );
            $delay = (int) $settings['delay_minutes'];
            $upd->bind_param('iiii', $maxEmails, $maxEmails, $delay, $id);
            $upd->execute();
        }
    }
}
