<?php
function app_request_is_https(): bool
{
    if (!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off') {
        return true;
    }
    if (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && strtolower((string) $_SERVER['HTTP_X_FORWARDED_PROTO']) === 'https') {
        return true;
    }
    if (!empty($_SERVER['HTTP_X_FORWARDED_SSL']) && strtolower((string) $_SERVER['HTTP_X_FORWARDED_SSL']) === 'on') {
        return true;
    }

    $appEnv = strtolower(trim((string) ($GLOBALS['_app_config']['APP_ENV'] ?? '')));
    $appUrl = strtolower(trim((string) ($GLOBALS['_app_config']['APP_URL'] ?? '')));
    $forceHttps = strtolower(trim((string) ($GLOBALS['_app_config']['APP_FORCE_HTTPS'] ?? '')));

    return $forceHttps === '1' ||
        $forceHttps === 'true' ||
        ($appEnv === 'production' && strpos($appUrl, 'https://') === 0);
}

// Harden session cookie settings before starting the session.
if (session_status() === PHP_SESSION_NONE) {
    if (PHP_SAPI !== 'cli' && !headers_sent()) {
        $secure = app_request_is_https();
        $requestPath = (string) (parse_url((string) ($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH) ?? '');
        $scriptPath = str_replace('\\', '/', (string) ($_SERVER['SCRIPT_NAME'] ?? ''));
        $isAdminRequest = strpos($requestPath, '/admin/') === 0 || strpos($scriptPath, '/admin/') === 0;
        $cookiePath = $isAdminRequest ? '/admin' : '/';

        // Isolate admin authentication from the storefront/customer session.
        // The storefront retains PHP's existing session name so active carts
        // and customer logins survive deployment of this change.
        if ($isAdminRequest) {
            session_name('AMBERADMINSESSID');
        }

        $adminAbsoluteTimeout = max(900, (int) ($GLOBALS['_app_config']['ADMIN_SESSION_ABSOLUTE_TIMEOUT_SEC'] ?? 28800));
        $customerAbsoluteTimeout = max(3600, (int) ($GLOBALS['_app_config']['CUSTOMER_SESSION_ABSOLUTE_TIMEOUT_SEC'] ?? 2592000));
        ini_set('session.gc_maxlifetime', (string) max($adminAbsoluteTimeout, $customerAbsoluteTimeout));
        ini_set('session.use_strict_mode', '1');
        ini_set('session.use_only_cookies', '1');
        ini_set('session.use_trans_sid', '0');
        session_set_cookie_params([
            'lifetime' => 0,
            'path' => $cookiePath,
            'secure' => $secure,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        session_start();
    } elseif (!isset($_SESSION) || !is_array($_SESSION)) {
        $_SESSION = [];
    }
}

/**
 * Destroy the active session and its cookie. Optionally start a clean session
 * so the caller can store a post-logout flash message.
 */
function app_destroy_session(bool $restart = false): void
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        return;
    }

    $cookieName = session_name();
    $params = session_get_cookie_params();
    $_SESSION = [];
    session_destroy();

    if (!headers_sent() && ini_get('session.use_cookies')) {
        setcookie($cookieName, '', [
            'expires' => time() - 42000,
            'path' => (string) ($params['path'] ?? '/'),
            'domain' => (string) ($params['domain'] ?? ''),
            'secure' => (bool) ($params['secure'] ?? false),
            'httponly' => (bool) ($params['httponly'] ?? true),
            'samesite' => (string) ($params['samesite'] ?? 'Lax'),
        ]);
        unset($_COOKIE[$cookieName]);
    }

    if ($restart && !headers_sent()) {
        session_id('');
        session_start();
        session_regenerate_id(true);
    }
}

/**
 * CSRF token helpers.
 */
function csrf_token(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function csrf_field(): string
{
    $token = e(csrf_token());
    return '<input type="hidden" name="csrf_token" value="' . $token . '">';
}

function verify_csrf(): bool
{
    return isset($_POST['csrf_token'], $_SESSION['csrf_token']) &&
        hash_equals($_SESSION['csrf_token'], $_POST['csrf_token']);
}

/**
 * Marketing consent helpers (Pixel/CAPI/UTM).
 */
function marketing_consent_cookie_name(): string
{
    return 'amber_marketing_consent';
}

function marketing_consent_status(): string
{
    $sessionStatus = strtolower(trim((string) ($_SESSION['marketing_consent'] ?? '')));
    if (in_array($sessionStatus, ['granted', 'denied'], true)) {
        return $sessionStatus;
    }

    $cookieStatus = strtolower(trim((string) ($_COOKIE[marketing_consent_cookie_name()] ?? '')));
    if (in_array($cookieStatus, ['granted', 'denied'], true)) {
        $_SESSION['marketing_consent'] = $cookieStatus;
        return $cookieStatus;
    }

    return 'unknown';
}

function marketing_consent_granted(): bool
{
    return marketing_consent_status() === 'granted';
}

function marketing_consent_denied(): bool
{
    return marketing_consent_status() === 'denied';
}

function marketing_consent_clear_cookie(string $cookieName): void
{
    if (headers_sent() || $cookieName === '') {
        return;
    }

    $secure = app_request_is_https();
    setcookie($cookieName, '', [
        'expires' => time() - 3600,
        'path' => '/',
        'secure' => $secure,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    unset($_COOKIE[$cookieName]);
}

function marketing_consent_clear_tracking_data(): void
{
    unset($_SESSION['utm_attribution'], $_SESSION['meta_fbp'], $_SESSION['meta_fbc']);
    marketing_consent_clear_cookie('amber_utm');
    marketing_consent_clear_cookie('_fbp');
    marketing_consent_clear_cookie('_fbc');
}

function marketing_consent_set(string $status, int $days = 180): bool
{
    $status = strtolower(trim($status));
    if (!in_array($status, ['granted', 'denied'], true)) {
        return false;
    }

    $_SESSION['marketing_consent'] = $status;
    if (headers_sent()) {
        return false;
    }

    $secure = app_request_is_https();
    setcookie(marketing_consent_cookie_name(), $status, [
        'expires' => time() + (max(1, $days) * 86400),
        'path' => '/',
        'secure' => $secure,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);

    if ($status === 'denied') {
        marketing_consent_clear_tracking_data();
    }

    return true;
}



/**
 * Basic redirect helper.
 */
function redirect(string $path): void
{
    header("Location: {$path}");
    exit;
}

/**
 * Consistent JSON API response helper.
 */
function api_json(array $payload, int $status = 200): void
{
    if (!headers_sent()) {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
    }
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

/**
 * Flash messaging stored in session.
 */
function flash(string $key, ?string $message = null): ?string
{
    if ($message !== null) {
        $_SESSION['flash'][$key] = $message;
        return null;
    }

    if (!empty($_SESSION['flash'][$key])) {
        $msg = $_SESSION['flash'][$key];
        unset($_SESSION['flash'][$key]);
        return $msg;
    }

    return null;
}

/**
 * Escape output for HTML contexts.
 */
function e(?string $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

/**
 * Encode structured page data for a quoted HTML data attribute.
 *
 * Keeping browser configuration in inert attributes avoids executable PHP/JS
 * interpolation while preserving the original scalar and array types.
 */
function ui_data_json($value): string
{
    $json = json_encode(
        $value,
        JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE
    );

    if (!is_string($json)) {
        $json = 'null';
    }

    return htmlspecialchars($json, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/**
 * Return a cache-busted URL for a first-party browser asset.
 */
function ui_asset(string $publicPath): string
{
    $normalized = '/' . ltrim(str_replace('\\', '/', $publicPath), '/');
    $root = dirname(__DIR__, 2);
    $absolutePath = $root . str_replace('/', DIRECTORY_SEPARATOR, $normalized);
    $version = is_file($absolutePath) ? (string) filemtime($absolutePath) : '1';

    return $normalized . '?v=' . rawurlencode($version);
}

/**
 * Render an icon from the first-party SVG sprite with a safe accessible name.
 */
function ui_icon(string $name, ?string $label = null, string $className = ''): string
{
    $safeName = strtolower(trim($name));
    if (!preg_match('/^[a-z0-9-]+$/', $safeName)) {
        return '';
    }

    $classes = trim('ui-icon ' . preg_replace('/[^a-zA-Z0-9 _-]/', '', $className));
    $labelAttribute = $label === null || trim($label) === ''
        ? ' aria-hidden="true" focusable="false"'
        : ' role="img" aria-label="' . e($label) . '"';

    return '<svg class="' . e($classes) . '"' . $labelAttribute . '>'
        . '<use href="/images/ui-icons.svg#icon-' . e($safeName) . '"></use>'
        . '</svg>';
}

function ui_tone(string $tone): string
{
    return match (strtolower(trim($tone))) {
        'success' => 'success',
        'warning' => 'warning',
        'danger', 'error' => 'error',
        'primary', 'info' => 'info',
        default => 'neutral',
    };
}

/**
 * Decide whether a href/src value from editable HTML may be kept.
 *
 * Browsers ignore control characters and whitespace inside a scheme, so
 * "java\tscript:alert(1)" executes; the probe strips those before matching.
 */
function ui_rich_text_url_is_safe(string $url): bool
{
    $probe = strtolower((string) preg_replace('/[\x00-\x20\x7f]+/', '', $url));
    if ($probe === '') {
        return false;
    }
    if ($probe[0] === '#' || $probe[0] === '/' || $probe[0] === '?') {
        return true;
    }
    if (preg_match('#^([a-z][a-z0-9+.\-]*):#', $probe, $scheme) === 1) {
        return in_array($scheme[1], ['http', 'https', 'mailto', 'tel'], true);
    }

    // No scheme at all: a document-relative path such as "shipping-policy.php".
    return true;
}

/**
 * Sanitize and normalize admin-editable rich text for unescaped output.
 *
 * The policy/FAQ bodies behind this helper are authored in admin settings and
 * echoed without escaping on six public pages, so the stored value is
 * attacker-controlled from the perspective of any compromised or
 * over-privileged admin account. This used to be a pure class-token rewriter -
 * it returned every tag it was given - which meant a stored
 * `<script src="https://cdn.jsdelivr.net/...">` executed for every visitor,
 * since that origin was globally allowed in script-src. It now parses the
 * fragment and rebuilds it from an allowlist:
 *
 *   - script/style/iframe/object/embed/form/svg/math and friends are removed
 *     together with their entire subtree;
 *   - any other unknown element is unwrapped, keeping its text;
 *   - attributes not on the allowlist are dropped, which covers every on*
 *     handler, `style`, and the whole `data-*` surface that drives this app's
 *     own JavaScript behaviours;
 *   - href/src must resolve to http(s)/mailto/tel or stay relative.
 *
 * Legacy Bootstrap presentation classes are mapped to their first-party
 * equivalents during the same pass.
 */
function ui_rich_text_html(string $html): string
{
    if (trim($html) === '') {
        return '';
    }

    static $classMap = [
        'mb-4' => 'u-mb-4',
        'btn' => 'ui-button',
        'btn-sm' => 'ui-button--small',
        'btn-outline-dark' => 'ui-button--outline',
        'table-responsive' => 'ui-table-wrap',
        'table' => 'ui-table',
        'table-bordered' => 'ui-table--bordered',
        'align-middle' => 'u-align-middle',
    ];
    static $allowedTags = [
        'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'p', 'br', 'hr', 'strong', 'b', 'em',
        'i', 'u', 's', 'small', 'sub', 'sup', 'span', 'div', 'ul', 'ol', 'li',
        'dl', 'dt', 'dd', 'a', 'table', 'thead', 'tbody', 'tfoot', 'tr', 'th',
        'td', 'caption', 'colgroup', 'col', 'blockquote', 'code', 'pre',
        'figure', 'figcaption', 'img', 'button',
    ];
    static $droppedSubtrees = [
        'script', 'style', 'iframe', 'object', 'embed', 'applet', 'form',
        'input', 'select', 'textarea', 'svg', 'math', 'template',
        'noscript', 'link', 'meta', 'base', 'head', 'title', 'audio', 'video',
        'source', 'track', 'canvas', 'portal',
    ];
    static $allowedAttributes = [
        '*' => ['class', 'id', 'title', 'dir', 'lang'],
        'a' => ['href', 'target', 'rel'],
        'img' => ['src', 'alt', 'width', 'height', 'loading'],
        'th' => ['colspan', 'rowspan', 'scope', 'headers', 'abbr'],
        'td' => ['colspan', 'rowspan', 'headers'],
        'col' => ['span'],
        'colgroup' => ['span'],
        'ol' => ['start', 'reversed', 'type'],
        'blockquote' => ['cite'],
        // <button> is allowed but inert: `form` and `formaction` are not on this
        // list, <form> is dropped outright, and `type` is forced to "button"
        // below. data-open-cookie-consent is named explicitly rather than opening
        // the whole data-* surface - the shipped privacy-policy default uses it to
        // reopen the consent banner (js/commerce.js), and that is the only
        // editable-content JavaScript hook this app intends to expose.
        'button' => ['type', 'disabled', 'data-open-cookie-consent'],
    ];

    $document = new DOMDocument();
    $previousErrorState = libxml_use_internal_errors(true);
    // The wrapper gives every fragment a single known root to serialize back
    // from; <meta charset> is what makes libxml treat the bytes as UTF-8.
    // LIBXML_NONET forbids any network fetch during parsing.
    $loaded = $document->loadHTML(
        '<!DOCTYPE html><html><head><meta charset="utf-8"></head><body>'
        . '<div data-ui-rich-text-root="1">' . $html . '</div></body></html>',
        LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING
    );
    libxml_clear_errors();
    libxml_use_internal_errors($previousErrorState);
    if ($loaded === false) {
        return '';
    }

    $roots = (new DOMXPath($document))->query('//div[@data-ui-rich-text-root]');
    $root = ($roots instanceof DOMNodeList && $roots->length > 0) ? $roots->item(0) : null;
    if (!$root instanceof DOMElement) {
        return '';
    }

    $scrub = static function (DOMNode $parent) use (
        &$scrub,
        $classMap,
        $allowedTags,
        $droppedSubtrees,
        $allowedAttributes
    ): void {
        // Snapshot the list: the loop reparents and removes nodes as it goes.
        foreach (iterator_to_array($parent->childNodes) as $node) {
            if ($node instanceof DOMComment || $node instanceof DOMProcessingInstruction) {
                $parent->removeChild($node);
                continue;
            }
            if (!$node instanceof DOMElement) {
                // Text and CDATA are re-escaped by saveHTML(), so they are safe.
                continue;
            }

            $tag = strtolower($node->tagName);
            if (in_array($tag, $droppedSubtrees, true)) {
                $parent->removeChild($node);
                continue;
            }
            if (!in_array($tag, $allowedTags, true)) {
                $scrub($node);
                while ($node->firstChild !== null) {
                    $parent->insertBefore($node->firstChild, $node);
                }
                $parent->removeChild($node);
                continue;
            }

            $allowed = array_merge($allowedAttributes['*'], $allowedAttributes[$tag] ?? []);
            foreach (iterator_to_array($node->attributes) as $attribute) {
                $name = strtolower($attribute->nodeName);
                if (!in_array($name, $allowed, true)) {
                    $node->removeAttribute($attribute->nodeName);
                    continue;
                }
                if (($name === 'href' || $name === 'src')
                    && !ui_rich_text_url_is_safe((string) $attribute->nodeValue)) {
                    $node->removeAttribute($attribute->nodeName);
                    continue;
                }
                if ($name === 'class') {
                    $classes = preg_split('/\s+/', trim((string) $attribute->nodeValue)) ?: [];
                    $classes = array_values(array_unique(array_filter(
                        array_map(
                            static fn(string $className): string => $classMap[$className] ?? $className,
                            $classes
                        ),
                        static fn(string $className): bool => $className !== ''
                    )));
                    if ($classes === []) {
                        $node->removeAttribute('class');
                    } else {
                        $node->setAttribute('class', implode(' ', $classes));
                    }
                }
            }

            // An editable link that opens a new tab must not hand the opener over.
            if ($tag === 'a' && strtolower($node->getAttribute('target')) === '_blank') {
                $node->setAttribute('rel', 'noopener noreferrer');
            }

            // Force type="button" rather than trusting the author's value. Today a
            // stored <button type="submit"> is already inert because no page echoes
            // this helper's output inside a <form>, but that is a property of the
            // call sites, not of the sanitizer. Pinning the type here keeps the
            // guarantee if a future template ever renders editable copy in a form.
            if ($tag === 'button') {
                $node->setAttribute('type', 'button');
            }

            $scrub($node);
        }
    };
    $scrub($root);

    $safe = '';
    foreach ($root->childNodes as $node) {
        $serialized = $document->saveHTML($node);
        if (is_string($serialized)) {
            $safe .= $serialized;
        }
    }

    return $safe;
}

function site_name(): string
{
    return SiteContext::name();
}

function contact_email(): string
{
    return SiteContext::contactEmail();
}

function app_url(string $path = ''): string
{
    return SiteContext::url($path);
}

function money($amount, string $currency = 'INR', bool $withCode = false): string
{
    $code = strtoupper(trim($currency));
    if ($code === '') {
        $code = 'INR';
    }
    $symbol = $code === 'USD' ? '$' : 'Rs ';
    $formatted = $symbol . number_format((float) $amount, 2);
    return $withCode ? ($formatted . ' ' . $code) : $formatted;
}

/**
 * Enforce a baseline password policy for customer credentials.
 */
function password_strength_error(string $password): ?string
{
    if (strlen($password) < 10) {
        return 'Password must be at least 10 characters.';
    }
    if (!preg_match('/[A-Z]/', $password)) {
        return 'Password must include at least one uppercase letter.';
    }
    if (!preg_match('/[a-z]/', $password)) {
        return 'Password must include at least one lowercase letter.';
    }
    if (!preg_match('/\d/', $password)) {
        return 'Password must include at least one number.';
    }
    return null;
}

/**
 * Normalize meter quantities to 2 decimals with a minimum of 1 meter.
 */
function normalize_meter_quantity($value, float $min = 1.0): float
{
    $qty = is_numeric($value) ? (float) $value : $min;
    if ($qty < $min) {
        $qty = $min;
    }
    return round($qty, 2);
}

/**
 * Display meter quantities without unnecessary trailing zeros.
 */
function format_meter_quantity($value): string
{
    $qty = normalize_meter_quantity($value);
    $formatted = number_format($qty, 2, '.', '');
    return rtrim(rtrim($formatted, '0'), '.');
}

/**
 * Normalize piece quantities to whole numbers with a minimum quantity.
 */
function normalize_piece_quantity($value, int $min = 1): int
{
    $qty = is_numeric($value) ? (int) round((float) $value) : $min;
    return max($min, $qty);
}

/**
 * Normalize quantities based on unit type.
 *
 * For piece/set units, $minQty is treated as whole-number minimum quantity.
 * For meter units, $minQty is treated as minimum meter quantity.
 */
function normalize_quantity_by_unit($value, string $unitType, float $minQty = 1.0)
{
    if ($unitType === 'piece' || $unitType === 'set') {
        return normalize_piece_quantity($value, max(1, (int) round($minQty)));
    }
    return normalize_meter_quantity($value, $minQty);
}

/**
 * Validate that a meter quantity respects a configured step.
 * Example: min=1.00, step=0.25 allows 1.00, 1.25, 1.50, ...
 */
function meter_qty_respects_step(float $qty, float $minQty, float $step): bool
{
    $step = round($step, 4);
    if ($step <= 0) {
        return true;
    }
    if ($qty < $minQty) {
        return false;
    }
    $delta = $qty - $minQty;
    $ratio = $delta / $step;
    return abs($ratio - round($ratio)) < 0.0001;
}

/**
 * Format quantities based on unit type.
 */
function format_quantity_by_unit($value, string $unitType): string
{
    return ($unitType === 'piece' || $unitType === 'set')
        ? (string) normalize_piece_quantity($value, 1)
        : format_meter_quantity($value);
}

function normalize_units_per_set($value): int
{
    $n = is_numeric($value) ? (int) round((float) $value) : 1;
    return max(1, $n);
}

function format_pack_label(int $unitsPerSet): string
{
    return 'Pack of ' . max(1, (int) $unitsPerSet);
}
