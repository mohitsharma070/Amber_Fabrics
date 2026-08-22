<?php

require_once __DIR__ . '/../security/ExternalUrlPolicy.php';
require_once __DIR__ . '/HttpClientPolicy.php';

final class JsonHttpClient
{
    public static function request(
        string $method,
        string $url,
        array $headers = [],
        ?array $payload = null,
        array $options = []
    ): array {
        if (!function_exists('curl_init')) {
            return self::failure('curl_missing', 'cURL is unavailable');
        }

        $method = HttpClientPolicy::normalizeMethod($method);
        if ($method === '') {
            return self::failure('invalid_method', 'HTTP method is invalid');
        }
        $allowedHosts = is_array($options['allowed_hosts'] ?? null) ? $options['allowed_hosts'] : [];
        $requireHttps = array_key_exists('require_https', $options) ? (bool) $options['require_https'] : null;
        if (!HttpClientPolicy::urlAllowed($url, $allowedHosts, $requireHttps)) {
            return self::failure('invalid_url', 'HTTP URL is invalid');
        }

        $json = null;
        if ($payload !== null) {
            $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            if (!is_string($json)) {
                return self::failure('payload_encode_failed', 'Unable to encode request payload');
            }
        }

        $ch = curl_init($url);
        if ($ch === false) {
            return self::failure('curl_init_failed', 'Unable to initialize cURL');
        }

        $finalHeaders = array_values(array_map('strval', array_merge(['Accept: application/json'], $headers)));
        if ($json !== null && !array_filter($finalHeaders, static fn(string $header): bool => stripos($header, 'Content-Type:') === 0)) {
            $finalHeaders[] = 'Content-Type: application/json';
        }
        $curlOptions = HttpClientPolicy::curlOptions(
            max(1, (int) ($options['timeout_sec'] ?? 15)),
            max(1, (int) ($options['connect_timeout_sec'] ?? 5)),
            (bool) ($options['skip_tls_verify'] ?? false),
            (string) ($options['ca_bundle'] ?? '')
        );
        $curlOptions[CURLOPT_CUSTOMREQUEST] = $method;
        $curlOptions[CURLOPT_HTTPHEADER] = $finalHeaders;
        if ($json !== null) {
            $curlOptions[CURLOPT_POSTFIELDS] = $json;
        }
        $basicAuth = (string) ($options['basic_auth'] ?? '');
        if ($basicAuth !== '') {
            $curlOptions[CURLOPT_HTTPAUTH] = CURLAUTH_BASIC;
            $curlOptions[CURLOPT_USERPWD] = $basicAuth;
        }

        $startedAt = microtime(true);
        curl_setopt_array($ch, $curlOptions);
        $raw = curl_exec($ch);
        $errno = curl_errno($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        $durationMs = HttpClientPolicy::durationMs($startedAt);

        if ($errno !== 0) {
            return self::failure('curl_error', 'cURL transport error ' . $errno, $status, $durationMs);
        }

        $decoded = json_decode((string) $raw, true);
        if ($status < 200 || $status >= 300) {
            return self::failure('http_error', 'HTTP ' . $status, $status, $durationMs, is_array($decoded) ? $decoded : null);
        }
        if (!is_array($decoded)) {
            return self::failure('invalid_json', 'HTTP response was not valid JSON', $status, $durationMs);
        }

        return [
            'ok' => true,
            'status' => $status,
            'body' => $decoded,
            'error' => '',
            'error_code' => '',
            'duration_ms' => $durationMs,
        ];
    }

    private static function failure(
        string $errorCode,
        string $error,
        int $status = 0,
        int $durationMs = 0,
        ?array $body = null
    ): array {
        return [
            'ok' => false,
            'status' => $status,
            'body' => $body,
            'error' => $error,
            'error_code' => $errorCode,
            'duration_ms' => $durationMs,
        ];
    }
}
