<?php
require_once __DIR__ . '/../services/EmailService.php';
require_once __DIR__ . '/../services/SiteSettingsService.php';

// E-Commerce Email Helpers

/**
 * Read active app config from config/db.php bootstrap.
 */
function _cfg(string $key, string $default = ''): string
{
    if (isset($GLOBALS['_app_config'][$key]) && $GLOBALS['_app_config'][$key] !== '') {
        return (string) $GLOBALS['_app_config'][$key];
    }
    return $default;
}

function _mailer_base(): PHPMailer\PHPMailer\PHPMailer
{
    return EmailService::_mailer_base();
}

function send_order_confirmation_email(mysqli $conn, int $orderId): bool
{
    return EmailService::send_order_confirmation_email($conn, $orderId);
}

function send_order_status_update_email(mysqli $conn, int $orderId, string $newStatus): bool
{
    return EmailService::send_order_status_update_email($conn, $orderId, $newStatus);
}

function send_customer_password_reset_email(string $email, string $token): bool
{
    return EmailService::send_customer_password_reset_email($email, $token);
}

function send_customer_verification_email(string $email, string $name, string $token): bool
{
    return EmailService::send_customer_verification_email($email, $name, $token);
}

function send_admin_login_otp_email(string $email, string $name, string $otp, bool $isResend = false): bool
{
    return EmailService::send_admin_login_otp_email($email, $name, $otp, $isResend);
}

/**
 * Calculate GST breakdown for display without changing order totals.
 */
function configured_gst_rate(): float
{
    $settings = SiteSettingsService::get();
    $raw = trim((string) ($settings['gst_rate'] ?? '18'));
    if (!is_numeric($raw)) {
        return 18.0;
    }
    $rate = (float) $raw;
    if ($rate < 0) {
        return 0.0;
    }
    if ($rate > 100) {
        return 100.0;
    }
    return round($rate, 2);
}

function order_gst_breakdown(float $taxableAmount, string $country, ?float $gstRate = null): array
{
    if ($gstRate === null) {
        $gstRate = configured_gst_rate();
    }
    $isIndia = strcasecmp(trim($country), 'india') === 0;
    $taxable = max(0.0, round($taxableAmount, 2));
    if (!$isIndia || $taxable <= 0 || $gstRate <= 0) {
        return [
            'enabled' => false,
            'rate' => 0.0,
            'taxable_amount' => $taxable,
            'gst_amount' => 0.0,
            'cgst_amount' => 0.0,
            'sgst_amount' => 0.0,
        ];
    }

    $gst = round(($taxable * $gstRate) / 100, 2);
    $half = round($gst / 2, 2);
    return [
        'enabled' => true,
        'rate' => $gstRate,
        'taxable_amount' => $taxable,
        'gst_amount' => $gst,
        'cgst_amount' => $half,
        'sgst_amount' => round($gst - $half, 2),
    ];
}

function app_http_json(string $method, string $url, array $headers = [], ?array $payload = null): array
{
    if (!function_exists('curl_init')) {
        return ['ok' => false, 'status' => 0, 'body' => null, 'error' => 'cURL is unavailable'];
    }
    $ch = curl_init($url);
    if ($ch === false) {
        return ['ok' => false, 'status' => 0, 'body' => null, 'error' => 'Unable to initialize cURL'];
    }
    $timeoutSec = max(5, (int) _cfg('APP_HTTP_TIMEOUT_SEC', '15'));
    $connectTimeoutSec = max(2, (int) _cfg('APP_HTTP_CONNECT_TIMEOUT_SEC', '5'));
    $finalHeaders = array_merge(['Accept: application/json'], $headers);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => $timeoutSec,
        CURLOPT_CONNECTTIMEOUT => $connectTimeoutSec,
        CURLOPT_CUSTOMREQUEST => strtoupper($method),
        CURLOPT_HTTPHEADER => $finalHeaders,
    ]);
    if ($payload !== null) {
        $json = json_encode($payload);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $json === false ? '{}' : $json);
        if (!array_filter($finalHeaders, static fn($h) => stripos($h, 'Content-Type:') === 0)) {
            curl_setopt($ch, CURLOPT_HTTPHEADER, array_merge($finalHeaders, ['Content-Type: application/json']));
        }
    }

    $raw = curl_exec($ch);
    $errno = curl_errno($ch);
    $err = curl_error($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);

    if ($errno !== 0) {
        return ['ok' => false, 'status' => $status, 'body' => null, 'error' => $err !== '' ? $err : ('cURL error ' . $errno)];
    }
    $decoded = json_decode((string) $raw, true);
    return [
        'ok' => $status >= 200 && $status < 300,
        'status' => $status,
        'body' => is_array($decoded) ? $decoded : [],
        'error' => ($status >= 200 && $status < 300) ? '' : ('HTTP ' . $status),
    ];
}

// ---------------------------------------------------------------------------
// Form helpers - Bootstrap 5 inline validation
// ---------------------------------------------------------------------------

/**
 * Returns the appropriate Bootstrap input CSS classes.
 * $base is 'form-control' for <input>/<textarea>, 'form-select' for <select>.
 */
function form_class(array $errors, string $field, string $base = 'form-control'): string
{
    return $base . (empty($errors[$field]) ? '' : ' is-invalid');
}

/**
 * Returns an invalid-feedback <div> with the field error, or '' if none.
 */
function form_error(array $errors, string $field): string
{
    if (empty($errors[$field])) {
        return '';
    }
    return '<div class="invalid-feedback d-block">' . e($errors[$field]) . '</div>';
}

// ---------------------------------------------------------------------------
// Pagination helper - Bootstrap 5 pagination component
// ---------------------------------------------------------------------------

/**
 * Renders a Bootstrap 5 pagination nav.
 *
 * @param int    $page       Current page (1-based)
 * @param int    $pages      Total pages
 * @param array  $queryState Existing query params to preserve in page links
 * @param string $pageKey    URL parameter name for the page number
 * @param int    $total      Total records (used for "Showing X-Y of Z" info line)
 * @param int    $perPage    Records per page (used for info line)
 */
function render_pagination(int $page, int $pages, array $queryState, string $pageKey = 'page', int $total = 0, int $perPage = 0): string
{
    if ($pages <= 1) {
        return '';
    }
    $page = max(1, min($page, $pages));

    // Info line
    $info = '';
    if ($total > 0 && $perPage > 0) {
        $from = ($page - 1) * $perPage + 1;
        $to   = min($page * $perPage, $total);
        $info = '<p class="text-muted small text-center mb-1">Showing ' . $from . '&ndash;' . $to . ' of ' . $total . ' results</p>';
    }

    // Build an even-sized visible window of numeric page links.
    // This keeps pagination rhythm consistent across the site.
    $visibleCount = min(10, $pages); // always even
    $leftCount = (int) ($visibleCount / 2);
    $start = $page - $leftCount + 1;
    $end = $start + $visibleCount - 1;

    if ($start < 1) {
        $start = 1;
        $end = $visibleCount;
    }
    if ($end > $pages) {
        $end = $pages;
        $start = max(1, $end - $visibleCount + 1);
    }

    $pool = range($start, $end);

    $mkUrl = static fn(int $p): string => '?' . list_build_query(array_merge($queryState, [$pageKey => $p]));

    $html  = '<nav aria-label="Pagination" class="mt-3">';
    $html .= '<ul class="pagination justify-content-center flex-wrap mb-0">';

    // Prev button
    if ($page <= 1) {
        $html .= '<li class="page-item disabled"><span class="page-link">&laquo; Prev</span></li>';
    } else {
        $html .= '<li class="page-item"><a class="page-link" href="' . e($mkUrl($page - 1)) . '">&laquo; Prev</a></li>';
    }

    // Optional first-page shortcut with ellipsis
    if ($start > 1) {
        $html .= '<li class="page-item"><a class="page-link" href="' . e($mkUrl(1)) . '">1</a></li>';
        if ($start > 2) {
            $html .= '<li class="page-item disabled"><span class="page-link">&hellip;</span></li>';
        }
    }

    // Page numbers in even-sized window
    foreach ($pool as $p) {
        if ($p === $page) {
            $html .= '<li class="page-item active" aria-current="page"><span class="page-link">' . $p . '</span></li>';
        } else {
            $html .= '<li class="page-item"><a class="page-link" href="' . e($mkUrl($p)) . '">' . $p . '</a></li>';
        }
    }

    // Optional last-page shortcut with ellipsis
    if ($end < $pages) {
        if ($end < $pages - 1) {
            $html .= '<li class="page-item disabled"><span class="page-link">&hellip;</span></li>';
        }
        $html .= '<li class="page-item"><a class="page-link" href="' . e($mkUrl($pages)) . '">' . $pages . '</a></li>';
    }

    // Next button
    if ($page >= $pages) {
        $html .= '<li class="page-item disabled"><span class="page-link">Next &raquo;</span></li>';
    } else {
        $html .= '<li class="page-item"><a class="page-link" href="' . e($mkUrl($page + 1)) . '">Next &raquo;</a></li>';
    }

    $html .= '</ul></nav>';

    return $info . $html;
}
