<?php

final class BigshipService
{
    private array $settings;
    private static array $tokens = [];

    public function __construct(array $settings)
    {
        $this->settings = $settings;
    }

    public function login(): array { return $this->request('POST', '/api/outbound/login', $this->credentials(), [], false); }
    public function profile(): array { return $this->request('GET', '/api/outbound/profile'); }
    public function saveWarehouse(array $payload): array { return $this->request('POST', '/api/outbound/save-warehouse-data', $payload); }
    public function warehouses(array $query = []): array
    {
        return $this->request('GET', '/api/outbound/get-warehouse-list', array_replace([
            'page' => 1,
            'perPage' => 100,
            'segment_type' => $this->warehouseSegment(),
        ], $query));
    }
    public function editWarehouse(array $payload): array { return $this->request('POST', '/api/outbound/edit-warehouse-data', $payload); }
    public function packages(array $query = []): array { return $this->request('GET', '/api/outbound/hyperlocal/get-packages-list', $query); }
    public function paymentModes(string $segment): array { return $this->request('GET', '/api/outbound/get-payment-mode', ['segment_type' => $segment]); }
    public function riskTypes(array $query = []): array { return $this->request('GET', '/api/outbound/domestic/risk-types', $query); }
    public function rates(array $payload): array { return $this->request('POST', '/api/outbound/user-rate-calculator', $payload); }
    public function createOrder(array $payload): array { return $this->request('POST', '/api/outbound/create-order', $payload); }
    public function courierCosts(array $payload): array { return $this->request('POST', '/api/outbound/courier-wise-shipment-cost', $payload); }
    public function placeOrder(array $payload, bool $multipart = false): array { return $this->request('POST', '/api/outbound/place-order', $payload, [], true, $multipart); }
    public function cancelOrder(array $payload): array { return $this->request('POST', '/api/outbound/cancel-order', $payload); }
    public function trackOrder(string $id): array { return $this->request('GET', '/api/outbound/track-order', ['CustomGlobalOrderId' => $id]); }
    public function shipmentDetails(string $id): array { return $this->request('GET', '/api/outbound/order-shipment-details', ['CustomGlobalOrderId' => $id]); }
    public function downloadDocuments(string $id, string $type = 'label'): array
    {
        return $this->request('GET', '/api/outbound/download-shipment-documents', [
            'CustomGlobalOrderId' => $id,
            'document_type' => $type,
        ]);
    }

    public function request(
        string $method,
        string $path,
        array $payload = [],
        array $headers = [],
        bool $authenticated = true,
        bool $multipart = false
    ): array {
        if (!function_exists('curl_init')) {
            return self::result(false, 'cURL is unavailable.');
        }
        $method = strtoupper(trim($method));
        if (!in_array($method, ['GET', 'POST', 'PUT', 'PATCH', 'DELETE'], true)) {
            return self::result(false, 'Bigship HTTP method is invalid.');
        }

        $base = rtrim(trim((string) ($this->settings['api_base_url'] ?? '')), '/');
        $url = preg_match('/^https?:\/\//i', $path) ? $path : $base . '/' . ltrim($path, '/');
        if ($base === '' || (class_exists('InventoryService') && InventoryService::safe_external_url($url) === '')) {
            return self::result(false, 'Bigship API URL is invalid.');
        }
        if ($method === 'GET' && $payload !== []) {
            $url .= (str_contains($url, '?') ? '&' : '?') . http_build_query($payload, '', '&', PHP_QUERY_RFC3986);
        }

        $requestHeaders = ['Accept: application/json'];
        if (!$multipart) {
            $requestHeaders[] = 'Content-Type: application/json';
        }
        foreach ($headers as $header) {
            if (stripos((string) $header, 'Authorization:') !== 0) {
                $requestHeaders[] = (string) $header;
            }
        }
        if ($authenticated) {
            $token = $this->accessToken();
            if (empty($token['ok'])) {
                return $token;
            }
            $requestHeaders[] = 'Authorization: Bearer ' . (string) $token['token'];
        }

        $last = self::result(false, 'Bigship request was not attempted.');
        for ($attempt = 1; $attempt <= 3; $attempt++) {
            $this->throttle();
            $last = $this->execute($method, $url, $payload, $requestHeaders, $multipart);
            $status = (int) ($last['status'] ?? 0);
            if (!in_array($status, [429, 500, 502, 503, 504], true) || $attempt === 3) {
                return $last;
            }
            usleep(150000 * $attempt);
        }
        return $last;
    }

    private function credentials(): array
    {
        return [
            'username' => (string) ($this->settings['bigship_username'] ?? ''),
            'password' => (string) ($this->settings['bigship_password'] ?? ''),
            'access_key' => (string) ($this->settings['bigship_access_key'] ?? ''),
        ];
    }

    /**
     * Bigship's warehouse API groups domestic B2B and B2C warehouses under
     * "domestic", while order/rate APIs use the more specific segment names.
     */
    private function warehouseSegment(): string
    {
        $configured = strtolower(trim((string) ($this->settings['bigship_warehouse_segment'] ?? '')));
        if ($configured !== '') {
            return $configured;
        }

        $segment = strtolower(trim((string) ($this->settings['bigship_segment'] ?? 'domestic_b2c')));
        return str_starts_with($segment, 'domestic_') ? 'domestic' : $segment;
    }

    private function accessToken(): array
    {
        $key = 'bigship_token_' . hash('sha256', implode("\0", array_values($this->credentials())) . "\0" . (string) ($this->settings['api_base_url'] ?? ''));
        $now = time();
        $cached = self::$tokens[$key] ?? null;
        if (!is_array($cached) && function_exists('apcu_fetch')) {
            $success = false;
            $value = apcu_fetch($key, $success);
            $cached = $success && is_array($value) ? $value : null;
        }
        if (is_array($cached) && !empty($cached['token']) && (int) ($cached['expires_at'] ?? 0) > $now + 30) {
            self::$tokens[$key] = $cached;
            return self::result(true, '', 200, null, ['token' => (string) $cached['token']]);
        }

        $login = $this->login();
        $body = is_array($login['body'] ?? null) ? (array) $login['body'] : [];
        $token = self::value($body, ['access_token', 'accessToken', 'token']);
        if (empty($login['ok']) || $token === '') {
            return self::result(false, 'Unable to authenticate with Bigship Direct.', (int) ($login['status'] ?? 0));
        }
        $expiresAt = $this->tokenExpiry($body);
        $cached = ['token' => $token, 'expires_at' => $expiresAt];
        self::$tokens[$key] = $cached;
        if (function_exists('apcu_store')) {
            apcu_store($key, $cached, max(1, $expiresAt - $now));
        }
        return self::result(true, '', 200, null, ['token' => $token]);
    }

    private function tokenExpiry(array $body): int
    {
        $expiry = self::value($body, ['expires_at', 'expiresAt', 'expiry', 'expiration', 'tokenExpiringAt', 'token_expiring_at', 'tokenExpiry', 'token_expiry']);
        if ($expiry !== '') {
            $timestamp = is_numeric($expiry) ? (int) $expiry : (int) strtotime($expiry);
            if ($timestamp > 100000000000) {
                $timestamp = (int) floor($timestamp / 1000);
            }
            if ($timestamp > time()) {
                return $timestamp;
            }
        }
        $ttl = self::value($body, ['expires_in', 'expiresIn', 'ttl']);
        return time() + (is_numeric($ttl) && (int) $ttl > 0 ? (int) $ttl : 600);
    }

    private function execute(string $method, string $url, array $payload, array $headers, bool $multipart): array
    {
        $ch = curl_init($url);
        if ($ch === false) {
            return self::result(false, 'Unable to initialize Bigship request.');
        }
        $skipTls = (($GLOBALS['_app_mode'] ?? '') === 'local') && (int) ($this->settings['bigship_http_skip_tls_verify'] ?? 0) === 1;
        $options = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => 25,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_SSL_VERIFYPEER => !$skipTls,
            CURLOPT_SSL_VERIFYHOST => $skipTls ? 0 : 2,
        ];
        if ($method !== 'GET') {
            if ($multipart) {
                $options[CURLOPT_POSTFIELDS] = $this->multipart($payload);
            } else {
                $json = json_encode($payload, JSON_UNESCAPED_SLASHES);
                if (!is_string($json)) {
                    curl_close($ch);
                    return self::result(false, 'Unable to encode Bigship payload.');
                }
                $options[CURLOPT_POSTFIELDS] = $json;
            }
        }
        curl_setopt_array($ch, $options);
        $raw = curl_exec($ch);
        $errno = curl_errno($ch);
        $error = curl_error($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        if ($errno !== 0) {
            return self::result(false, 'Bigship request failed: ' . $error, $status);
        }
        $decoded = json_decode((string) $raw, true);
        $body = is_array($decoded) ? $decoded : null;
        $ok = $status >= 200 && $status < 300;
        return self::result($ok, $ok ? 'Bigship API request successful.' : 'Bigship API returned HTTP ' . $status, $status, $body, [
            'raw_body' => is_string($raw) ? $raw : '',
        ]);
    }

    private function multipart(array $payload): array
    {
        $fields = [];
        foreach ($payload as $key => $value) {
            if ($value instanceof CURLFile) {
                $fields[(string) $key] = $value;
            } elseif (is_string($value) && is_file($value)) {
                $mime = function_exists('mime_content_type') ? (string) mime_content_type($value) : 'application/octet-stream';
                $fields[(string) $key] = new CURLFile($value, $mime, basename($value));
            } elseif (is_scalar($value) || $value === null) {
                $fields[(string) $key] = $value === null ? '' : (string) $value;
            } else {
                $fields[(string) $key] = json_encode($value, JSON_UNESCAPED_SLASHES);
            }
        }
        return $fields;
    }

    private function throttle(): void
    {
        if (!function_exists('apcu_add') || !function_exists('apcu_inc')) {
            return;
        }
        $bucket = 'bigship_rate_' . gmdate('YmdHi');
        if (!apcu_add($bucket, 1, 65)) {
            $count = apcu_inc($bucket);
            if (is_int($count) && $count > 95) {
                usleep(250000);
            }
        }
    }

    private static function value(array $body, array $keys): string
    {
        foreach ($keys as $key) {
            if (isset($body[$key]) && is_scalar($body[$key]) && trim((string) $body[$key]) !== '') {
                return trim((string) $body[$key]);
            }
        }
        foreach (['data', 'result', 'profile'] as $container) {
            if (is_array($body[$container] ?? null)) {
                $value = self::value((array) $body[$container], $keys);
                if ($value !== '') return $value;
            }
        }
        return '';
    }

    private static function result(bool $ok, string $message, int $status = 0, ?array $body = null, array $extra = []): array
    {
        return array_merge(['ok' => $ok, 'message' => $message, 'status' => $status, 'body' => $body], $extra);
    }
}
