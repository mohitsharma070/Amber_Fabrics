<?php

require_once __DIR__ . '/../security/ExternalUrlPolicy.php';

final class HttpClientPolicy
{
    private const METHODS = ['GET', 'POST', 'PUT', 'PATCH', 'DELETE'];
    private const RETRYABLE_STATUS = [429, 500, 502, 503, 504];

    public static function normalizeMethod(string $method): string
    {
        $method = strtoupper(trim($method));
        return in_array($method, self::METHODS, true) ? $method : '';
    }

    public static function urlAllowed(string $url, array $allowedHosts = [], ?bool $requireHttps = null): bool
    {
        if (ExternalUrlPolicy::sanitize($url) === '') {
            return false;
        }

        $parts = parse_url($url);
        if (!is_array($parts) || !empty($parts['user']) || !empty($parts['pass'])) {
            return false;
        }

        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = strtolower((string) ($parts['host'] ?? ''));
        if ($host === '') {
            return false;
        }
        if ($requireHttps === null) {
            $requireHttps = strtolower((string) ($GLOBALS['_app_mode'] ?? 'local')) === 'production';
        }
        if ($requireHttps && $scheme !== 'https') {
            return false;
        }

        $allowedHosts = array_values(array_unique(array_filter(array_map(
            static fn(mixed $value): string => strtolower(trim((string) $value)),
            $allowedHosts
        ))));
        return $allowedHosts === [] || in_array($host, $allowedHosts, true);
    }

    public static function hostFromUrl(string $url): string
    {
        return strtolower(trim((string) parse_url($url, PHP_URL_HOST)));
    }

    public static function curlOptions(
        int $timeoutSec,
        int $connectTimeoutSec,
        bool $requestSkipTlsVerify = false,
        string $caBundlePath = ''
    ): array {
        $skipTls = strtolower((string) ($GLOBALS['_app_mode'] ?? 'local')) === 'local' && $requestSkipTlsVerify;
        $options = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => max(1, $timeoutSec),
            CURLOPT_CONNECTTIMEOUT => max(1, $connectTimeoutSec),
            CURLOPT_SSL_VERIFYPEER => !$skipTls,
            CURLOPT_SSL_VERIFYHOST => $skipTls ? 0 : 2,
        ];
        $caBundlePath = trim($caBundlePath);
        if ($caBundlePath !== '' && is_file($caBundlePath)) {
            $options[CURLOPT_CAINFO] = $caBundlePath;
        }
        return $options;
    }

    public static function retryableStatus(int $status): bool
    {
        return in_array($status, self::RETRYABLE_STATUS, true);
    }

    public static function durationMs(float $startedAt): int
    {
        return max(0, (int) round((microtime(true) - $startedAt) * 1000));
    }
}
