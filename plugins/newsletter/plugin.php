<?php

add_action('app.init', 'newsletter_handle_request', 30);
add_action('footer.newsletter', 'newsletter_render_footer_signup', 20);

function newsletter_settings(): array
{
    return [
        'enabled' => (int) plugin_setting('newsletter', 'enabled', 1) === 1,
        'double_opt_in' => (int) plugin_setting('newsletter', 'double_opt_in', 0) === 1,
        'send_welcome_email' => (int) plugin_setting('newsletter', 'send_welcome_email', 0) === 1,
        'footer_form_enabled' => (int) plugin_setting('newsletter', 'footer_form_enabled', 1) === 1,
        'batch_size' => max(1, min(200, (int) plugin_setting('newsletter', 'batch_size', 50))),
        'from_name' => trim((string) plugin_setting('newsletter', 'from_name', SiteContext::name())),
        'source_tracking_enabled' => (int) plugin_setting('newsletter', 'source_tracking_enabled', 1) === 1,
    ];
}

function newsletter_table_ready(mysqli $conn): bool
{
    try {
        $stmt = $conn->prepare(
            "SELECT COUNT(*) AS total
             FROM information_schema.TABLES
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = 'newsletter_subscribers'"
        );
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        return ((int) ($row['total'] ?? 0)) > 0;
    } catch (Throwable $e) {
        error_log('[newsletter] table check failed: ' . $e->getMessage());
        return false;
    }
}

function newsletter_normalize_email(string $email): string
{
    return strtolower(trim($email));
}

function newsletter_client_ip(): string
{
    foreach (['HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'REMOTE_ADDR'] as $key) {
        $value = trim((string) ($_SERVER[$key] ?? ''));
        if ($value === '') {
            continue;
        }
        $first = trim(explode(',', $value)[0]);
        if (filter_var($first, FILTER_VALIDATE_IP)) {
            return $first;
        }
    }
    return '';
}

function newsletter_rate_limit_exceeded(): bool
{
    if (function_exists('public_form_rate_limit_allow') && !public_form_rate_limit_allow('newsletter_subscribe', 8, 900)) {
        return true;
    }

    $key = 'newsletter_subscribe_attempts';
    $now = time();
    $attempts = isset($_SESSION[$key]) && is_array($_SESSION[$key]) ? $_SESSION[$key] : [];
    $attempts = array_values(array_filter(array_map('intval', $attempts), static function (int $ts) use ($now): bool {
        return $ts > 0 && ($now - $ts) <= 900;
    }));
    if (count($attempts) >= 5) {
        $_SESSION[$key] = $attempts;
        return true;
    }
    $attempts[] = $now;
    $_SESSION[$key] = $attempts;
    return false;
}

function newsletter_generic_success_message(array $settings): string
{
    return !empty($settings['double_opt_in'])
        ? 'If this address can receive our newsletter, we will send a confirmation email.'
        : 'If this address can receive our newsletter, the preference has been updated.';
}

function newsletter_public_source(string $source, array $settings): string
{
    if (empty($settings['source_tracking_enabled'])) {
        return 'direct';
    }

    $source = trim($source);
    if ($source === '') {
        return 'footer';
    }
    $source = preg_replace('/[^A-Za-z0-9_.:-]/', '', $source);
    if (!is_string($source) || $source === '') {
        return 'footer';
    }
    return substr($source, 0, 80);
}

function newsletter_send_template_email(string $templateKey, string $email, string $name, array $data, array $settings): bool
{
    $email = newsletter_normalize_email($email);
    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return false;
    }

    $template = email_template_build($templateKey, $data);
    if ($template['subject'] === '' || $template['body'] === '') {
        error_log('[newsletter] transactional email template unavailable: ' . $templateKey);
        return false;
    }

    try {
        $mail = EmailService::_mailer_base();
        $fromName = trim((string) ($settings['from_name'] ?? ''));
        if ($fromName !== '') {
            $mail->setFrom(_cfg('MAIL_FROM', contact_email()), $fromName);
        }
        $mail->addAddress($email, $name !== '' ? $name : '');
        $mail->Subject = $template['subject'];
        $mail->Body = $template['body'];
        $mail->send();
        return true;
    } catch (Throwable $e) {
        error_log('[newsletter] transactional email failed (' . $templateKey . '): ' . $e->getMessage());
        return false;
    }
}

function newsletter_send_confirm_email(string $email, string $name, string $verifyToken, array $settings): bool
{
    if ($verifyToken === '') {
        return false;
    }
    return newsletter_send_template_email('newsletter_confirm', $email, $name, [
        'name' => $name,
        'confirm_url' => app_url('/?newsletter_confirm=' . urlencode($verifyToken)),
    ], $settings);
}

function newsletter_send_welcome_email(string $email, string $name, string $unsubscribeToken, array $settings): bool
{
    if (empty($settings['send_welcome_email'])) {
        return false;
    }
    return newsletter_send_template_email('newsletter_welcome', $email, $name, [
        'name' => $name,
        'unsubscribe_url' => app_url('/?newsletter_unsubscribe=' . urlencode($unsubscribeToken)),
    ], $settings);
}

function newsletter_subscribe(mysqli $conn, string $email, string $name, int $customerId, string $source, array $settings): array
{
    $email = newsletter_normalize_email($email);
    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return ['ok' => false, 'email' => '', 'name' => '', 'status' => '', 'was_subscribed' => false, 'verify_token' => '', 'unsubscribe_token' => ''];
    }

    $name = trim($name);
    $source = newsletter_public_source($source, $settings);
    $customerIdParam = $customerId > 0 ? $customerId : null;
    $ip = newsletter_client_ip();
    $ua = substr(trim((string) ($_SERVER['HTTP_USER_AGENT'] ?? '')), 0, 255);
    $unsubscribeToken = bin2hex(random_bytes(32));
    $verifyToken = !empty($settings['double_opt_in']) ? bin2hex(random_bytes(32)) : null;
    $status = !empty($settings['double_opt_in']) ? 'pending' : 'subscribed';
    $confirmedAtSql = !empty($settings['double_opt_in']) ? 'NULL' : 'NOW()';
    $existingStatus = '';
    $existingUnsubscribeToken = '';

    try {
        $existing = $conn->prepare("SELECT status, unsubscribe_token FROM newsletter_subscribers WHERE email_normalized = LOWER(TRIM(?)) LIMIT 1");
        $existing->bind_param('s', $email);
        $existing->execute();
        $existingRow = $existing->get_result()->fetch_assoc() ?: [];
        $existingStatus = (string) ($existingRow['status'] ?? '');
        $existingUnsubscribeToken = (string) ($existingRow['unsubscribe_token'] ?? '');
    } catch (Throwable $e) {
        $existingStatus = '';
        $existingUnsubscribeToken = '';
    }

    $stmt = $conn->prepare(
        "INSERT INTO newsletter_subscribers
            (customer_id, email, name, status, source, consent_ip, consent_user_agent, confirmed_at, unsubscribed_at, unsubscribe_token, verify_token, subscribed_at)
         VALUES (?, ?, ?, ?, ?, ?, ?, {$confirmedAtSql}, NULL, ?, ?, NOW())
         ON DUPLICATE KEY UPDATE
            customer_id = COALESCE(VALUES(customer_id), customer_id),
            name = CASE WHEN VALUES(name) <> '' THEN VALUES(name) ELSE name END,
            status = CASE
                WHEN newsletter_subscribers.status = 'bounced' THEN newsletter_subscribers.status
                WHEN newsletter_subscribers.status = 'subscribed' THEN newsletter_subscribers.status
                ELSE VALUES(status)
            END,
            source = VALUES(source),
            consent_ip = VALUES(consent_ip),
            consent_user_agent = VALUES(consent_user_agent),
            confirmed_at = CASE
                WHEN VALUES(status) = 'subscribed' THEN COALESCE(newsletter_subscribers.confirmed_at, NOW())
                WHEN newsletter_subscribers.status = 'unsubscribed' THEN NULL
                ELSE newsletter_subscribers.confirmed_at
            END,
            unsubscribe_token = CASE
                WHEN newsletter_subscribers.status IN ('unsubscribed','bounced') THEN VALUES(unsubscribe_token)
                ELSE newsletter_subscribers.unsubscribe_token
            END,
            verify_token = CASE
                WHEN newsletter_subscribers.status = 'subscribed' THEN NULL
                WHEN VALUES(status) = 'pending' THEN VALUES(verify_token)
                ELSE NULL
            END,
            subscribed_at = CASE WHEN newsletter_subscribers.status <> 'subscribed' THEN NOW() ELSE newsletter_subscribers.subscribed_at END,
            unsubscribed_at = NULL,
            updated_at = NOW()"
    );
    $stmt->bind_param('issssssss', $customerIdParam, $email, $name, $status, $source, $ip, $ua, $unsubscribeToken, $verifyToken);
    try {
        $stmt->execute();
    } catch (Throwable $e) {
        error_log('[newsletter] subscribe failed: ' . $e->getMessage());
        return ['ok' => false, 'email' => $email, 'name' => $name, 'status' => '', 'was_subscribed' => $existingStatus === 'subscribed', 'verify_token' => '', 'unsubscribe_token' => ''];
    }

    return [
        'ok' => true,
        'email' => $email,
        'name' => $name,
        'status' => $status,
        'was_subscribed' => $existingStatus === 'subscribed',
        'suppress_email' => in_array($existingStatus, ['pending', 'subscribed', 'bounced'], true),
        'verify_token' => (string) ($verifyToken ?? ''),
        'unsubscribe_token' => $existingStatus === 'subscribed' && $existingUnsubscribeToken !== '' ? $existingUnsubscribeToken : $unsubscribeToken,
    ];
}

function newsletter_render_unsubscribe_page(string $title, string $message): never
{
    if (!headers_sent()) {
        http_response_code(200);
        header('Content-Type: text/html; charset=utf-8');
    }
    echo '<!doctype html><html lang="en"><head><meta charset="utf-8">';
    echo '<meta name="viewport" content="width=device-width, initial-scale=1">';
    echo '<title>' . e($title) . ' | ' . e(SiteContext::name()) . '</title>';
    echo '<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">';
    echo '</head><body class="bg-light"><main class="container py-5">';
    echo '<div class="mx-auto bg-white border rounded p-4 shadow-sm" style="max-width:560px">';
    echo '<h1 class="h4 mb-3">' . e($title) . '</h1>';
    echo '<p class="text-muted mb-4">' . e($message) . '</p>';
    echo '<a class="btn btn-primary" href="' . e(app_url('/')) . '">Continue shopping</a>';
    echo '</div></main></body></html>';
    exit;
}

function newsletter_handle_unsubscribe(mysqli $conn, string $token): never
{
    $token = trim($token);
    if (!preg_match('/^[a-f0-9]{64}$/i', $token)) {
        newsletter_render_unsubscribe_page('Newsletter Unsubscribe', 'This unsubscribe link is invalid or has expired.');
    }

    $stmt = $conn->prepare(
        "UPDATE newsletter_subscribers
         SET status = 'unsubscribed',
             unsubscribed_at = NOW(),
             updated_at = NOW()
         WHERE unsubscribe_token = ?"
    );
    $stmt->bind_param('s', $token);
    $stmt->execute();
    newsletter_render_unsubscribe_page('Newsletter Unsubscribe', 'Your newsletter preference has been updated.');
}

function newsletter_handle_confirm(mysqli $conn, string $token, array $settings): never
{
    $token = trim($token);
    if (!preg_match('/^[a-f0-9]{64}$/i', $token)) {
        newsletter_render_unsubscribe_page('Newsletter Confirmation', 'This confirmation link is invalid or has expired.');
    }

    $stmt = $conn->prepare(
        "SELECT email, name, unsubscribe_token
         FROM newsletter_subscribers
         WHERE verify_token = ?
           AND status = 'pending'
         LIMIT 1"
    );
    $stmt->bind_param('s', $token);
    $stmt->execute();
    $subscriber = $stmt->get_result()->fetch_assoc() ?: [];

    if (!empty($subscriber)) {
        $update = $conn->prepare(
            "UPDATE newsletter_subscribers
             SET status = 'subscribed',
                 confirmed_at = NOW(),
                 verify_token = NULL,
                 unsubscribed_at = NULL,
                 updated_at = NOW()
             WHERE verify_token = ?
               AND status = 'pending'"
        );
        $update->bind_param('s', $token);
        $update->execute();

        if ($update->affected_rows > 0) {
            newsletter_send_welcome_email(
                (string) ($subscriber['email'] ?? ''),
                (string) ($subscriber['name'] ?? ''),
                (string) ($subscriber['unsubscribe_token'] ?? ''),
                $settings
            );
        }
    }

    newsletter_render_unsubscribe_page('Newsletter Confirmation', 'Your newsletter preference has been updated.');
}

function newsletter_handle_request(array $context): void
{
    $settings = newsletter_settings();
    if (!$settings['enabled'] || PHP_SAPI === 'cli') {
        return;
    }
    $conn = $GLOBALS['conn'] ?? null;
    if (!$conn instanceof mysqli || !newsletter_table_ready($conn)) {
        return;
    }

    $unsubscribeToken = trim((string) ($_GET['newsletter_unsubscribe'] ?? ''));
    if ($unsubscribeToken !== '') {
        newsletter_handle_unsubscribe($conn, $unsubscribeToken);
    }

    $confirmToken = trim((string) ($_GET['newsletter_confirm'] ?? ''));
    if ($confirmToken !== '') {
        newsletter_handle_confirm($conn, $confirmToken, $settings);
    }

    if ($_SERVER['REQUEST_METHOD'] !== 'POST' || trim((string) ($_POST['newsletter_action'] ?? '')) !== 'subscribe') {
        return;
    }

    $returnUrl = trim((string) ($_POST['return_url'] ?? ($_SERVER['HTTP_REFERER'] ?? '/')));
    if ($returnUrl === '' || preg_match('/[\x00-\x1F\x7F]/', $returnUrl) || preg_match('#^(?:https?:)?//#i', $returnUrl)) {
        $returnUrl = '/';
    }
    if (!verify_csrf()) {
        flash('error', 'Invalid session token. Please try again.');
        redirect($returnUrl);
    }
    $successMessage = newsletter_generic_success_message($settings);
    if (trim((string) ($_POST['company_website'] ?? '')) !== '') {
        flash('success', $successMessage);
        redirect($returnUrl);
    }
    if (newsletter_rate_limit_exceeded()) {
        flash('error', 'Too many newsletter requests. Please try again later.');
        redirect($returnUrl);
    }

    $customerId = (int) ($_SESSION['customer_id'] ?? 0);
    $email = trim((string) ($_POST['email'] ?? ''));
    $name = trim((string) ($_POST['name'] ?? ''));
    if (($email === '' || $name === '') && $customerId > 0) {
        $stmt = $conn->prepare("SELECT name, email FROM customers WHERE id = ? LIMIT 1");
        $stmt->bind_param('i', $customerId);
        $stmt->execute();
        $customer = $stmt->get_result()->fetch_assoc() ?: [];
        if ($email === '') {
            $email = (string) ($customer['email'] ?? '');
        }
        if ($name === '') {
            $name = (string) ($customer['name'] ?? '');
        }
    }

    $result = newsletter_subscribe($conn, $email, $name, $customerId, (string) ($_POST['source'] ?? 'footer'), $settings);
    if (!empty($result['ok']) && empty($result['suppress_email'])) {
        if (!empty($settings['double_opt_in'])) {
            newsletter_send_confirm_email(
                (string) ($result['email'] ?? ''),
                (string) ($result['name'] ?? ''),
                (string) ($result['verify_token'] ?? ''),
                $settings
            );
        } elseif (!empty($settings['send_welcome_email'])) {
            newsletter_send_welcome_email(
                (string) ($result['email'] ?? ''),
                (string) ($result['name'] ?? ''),
                (string) ($result['unsubscribe_token'] ?? ''),
                $settings
            );
        }
    }
    flash('success', $successMessage);
    redirect($returnUrl);
}

function newsletter_render_footer_signup(array $context): void
{
    $settings = newsletter_settings();
    $conn = $GLOBALS['conn'] ?? null;
    if (!$settings['enabled'] || !$settings['footer_form_enabled'] || !$conn instanceof mysqli || !newsletter_table_ready($conn)) {
        return;
    }
    $page = '/' . ltrim((string) ($_SERVER['REQUEST_URI'] ?? '/'), '/');
    ?>
    <div class="newsletter-signup border-top py-4 my-4">
        <form method="POST" action="<?php echo e($page); ?>" class="row g-2 align-items-end justify-content-center">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="newsletter_action" value="subscribe">
            <input type="hidden" name="source" value="footer">
            <input type="hidden" name="return_url" value="<?php echo e($page); ?>">
            <div class="d-none" aria-hidden="true">
                <label for="newsletter_company_website">Website</label>
                <input type="text" name="company_website" id="newsletter_company_website" tabindex="-1" autocomplete="off">
            </div>
            <div class="col-lg-4 col-md-5">
                <label class="form-label text-light" for="newsletter_email">Newsletter</label>
                <input type="email" class="form-control" id="newsletter_email" name="email" placeholder="Email address" required>
            </div>
            <div class="col-lg-2 col-md-3">
                <button type="submit" class="btn btn-light w-100">Subscribe</button>
            </div>
        </form>
    </div>
    <?php
}
