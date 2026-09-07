<?php

final class CronService
{
    public const STATUS_SUCCESS = 'success';
    public const STATUS_SKIPPED = 'skipped';
    public const STATUS_DEGRADED = 'degraded';
    public const STATUS_FAILED = 'failed';

    public static function result(
        string $status = self::STATUS_SUCCESS,
        int $processed = 0,
        int $succeeded = 0,
        int $failed = 0,
        array $extra = []
    ): array {
        $status = self::normalizeStatus($status);
        return $extra + [
            'status' => $status,
            'processed' => max(0, $processed),
            'succeeded' => max(0, $succeeded),
            'failed' => max(0, $failed),
        ];
    }

    public static function normalizeStatus(string $status): string
    {
        $status = strtolower(trim($status));
        return in_array($status, [self::STATUS_SUCCESS, self::STATUS_SKIPPED, self::STATUS_DEGRADED, self::STATUS_FAILED], true)
            ? $status
            : self::STATUS_SUCCESS;
    }

    public static function normalizeResult($result): array
    {
        if (!is_array($result)) {
            return self::result(self::STATUS_SUCCESS);
        }
        $status = self::normalizeStatus((string) ($result['status'] ?? self::STATUS_SUCCESS));
        $failed = max(0, (int) ($result['failed'] ?? 0));
        if ($failed > 0 && $status === self::STATUS_SUCCESS) {
            $status = self::STATUS_DEGRADED;
        }
        return array_merge($result, [
            'status' => $status,
            'processed' => max(0, (int) ($result['processed'] ?? 0)),
            'succeeded' => max(0, (int) ($result['succeeded'] ?? 0)),
            'failed' => $failed,
        ]);
    }

    public static function sanitizeError(string $message): string
    {
        $message = trim($message);
        $message = preg_replace('/\bBearer\s+[A-Za-z0-9._~+\/-]+/i', 'Bearer [redacted]', $message) ?? $message;
        $message = preg_replace('/([?&](?:token|key|secret|signature|access_token)=)[^\s&#]+/i', '$1[redacted]', $message) ?? $message;
        $message = preg_replace('/("?(?:token|password|secret|authorization|access_key)"?\s*[:=]\s*)[^,\s]+/i', '$1[redacted]', $message) ?? $message;

        if (function_exists('_cfg')) {
            foreach ([
                'CRON_RUN_TOKEN', 'DB_PASSWORD', 'SMTP_PASSWORD', 'RAZORPAY_KEY_SECRET',
                'RAZORPAY_WEBHOOK_SECRET', 'COD_GUARD_WHATSAPP_ACCESS_TOKEN',
                'COD_GUARD_WHATSAPP_APP_SECRET',
                'SHIPPING_COURIER_WEBHOOK_SECRET', 'BIGSHIP_PASSWORD', 'BIGSHIP_ACCESS_KEY',
                'ADMIN_LOGIN_PASSPHRASE', 'APP_IDENTITY_HASH_KEY',
            ] as $key) {
                $secret = trim((string) _cfg($key, ''));
                if ($secret !== '') {
                    $message = str_replace($secret, '[redacted]', $message);
                }
            }
        }

        if (function_exists('mb_substr')) {
            return mb_substr($message, 0, 1000, 'UTF-8');
        }
        return substr($message, 0, 1000);
    }

    public static function runJob(string $name, bool $critical, callable $fn, callable $logger): array
    {
        $startedAt = date('c');
        $started = microtime(true);
        $logger('info', 'job_start', ['job' => $name, 'critical' => $critical, 'started_at' => $startedAt]);
        try {
            $result = self::normalizeResult($fn());
            $status = (string) $result['status'];
            $duration = (int) round((microtime(true) - $started) * 1000);
            $logger(in_array($status, [self::STATUS_DEGRADED, self::STATUS_FAILED], true) ? 'warning' : 'info', 'job_finish', [
                'job' => $name,
                'critical' => $critical,
                'status' => $status,
                'started_at' => $startedAt,
                'finished_at' => date('c'),
                'duration_ms' => $duration,
                'result' => $result,
            ]);
            return [
                'name' => $name,
                'critical' => $critical,
                'status' => $status,
                'started_at' => $startedAt,
                'duration_ms' => $duration,
                'result' => $result,
                'error' => '',
            ];
        } catch (Throwable $e) {
            $duration = (int) round((microtime(true) - $started) * 1000);
            $error = self::sanitizeError($e->getMessage());
            $logger('error', 'job_finish', [
                'job' => $name,
                'critical' => $critical,
                'status' => self::STATUS_FAILED,
                'started_at' => $startedAt,
                'finished_at' => date('c'),
                'duration_ms' => $duration,
                'error' => $error,
            ]);
            return [
                'name' => $name,
                'critical' => $critical,
                'started_at' => $startedAt,
                'duration_ms' => $duration,
                'error' => $error,
                'status' => self::STATUS_FAILED,
                'result' => null,
            ];
        }
    }

    public static function summarize(array $jobs): array
    {
        $failed = 0;
        $degraded = 0;
        $criticalFailures = 0;
        $compact = [];
        foreach ($jobs as $job) {
            $status = self::normalizeStatus((string) ($job['status'] ?? self::STATUS_SUCCESS));
            if ($status === self::STATUS_FAILED) {
                $failed++;
            } elseif ($status === self::STATUS_DEGRADED) {
                $degraded++;
            }
            if (!empty($job['critical']) && in_array($status, [self::STATUS_FAILED, self::STATUS_DEGRADED], true)) {
                $criticalFailures++;
            }
            if (in_array($status, [self::STATUS_FAILED, self::STATUS_DEGRADED], true)) {
                $detail = [
                    'job' => (string) ($job['name'] ?? 'unknown'),
                    'status' => $status,
                    'error' => self::sanitizeError((string) ($job['error'] ?? '')),
                ];
                $callbacks = is_array($job['result']['callbacks'] ?? null) ? $job['result']['callbacks'] : [];
                $detail['callbacks'] = [];
                foreach ($callbacks as $callback) {
                    $callbackStatus = self::normalizeStatus((string) ($callback['status'] ?? self::STATUS_SUCCESS));
                    if (!in_array($callbackStatus, [self::STATUS_DEGRADED, self::STATUS_FAILED], true)) {
                        continue;
                    }
                    $detail['callbacks'][] = [
                        'callback' => (string) ($callback['callback'] ?? 'unknown'),
                        'status' => $callbackStatus,
                        'error' => self::sanitizeError((string) ($callback['error'] ?? '')),
                    ];
                }
                $compact[] = $detail;
            }
        }
        return [
            'status' => $criticalFailures > 0
                ? self::STATUS_FAILED
                : (($failed + $degraded) > 0 ? self::STATUS_DEGRADED : self::STATUS_SUCCESS),
            'failed' => $failed,
            'degraded' => $degraded,
            'critical_failures' => $criticalFailures,
            'details' => $compact,
        ];
    }
}
