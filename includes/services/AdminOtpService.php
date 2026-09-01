<?php

final class AdminOtpService
{
    public static function loginAttemptKey(string $email, string $ip): string
    {
        return hash('sha256', strtolower(trim($email)) . '|' . trim($ip));
    }

    public static function otpAttemptKey(string $scope, int $adminId, string $ip): string
    {
        return hash('sha256', 'admin_otp|' . strtolower(trim($scope)) . '|' . $adminId . '|' . trim($ip));
    }

    public static function isRateLimited(mysqli $conn, string $attemptKey): bool
    {
        $conn->begin_transaction();
        try {
            self::ensureAttemptRow($conn, $attemptKey);
            $row = self::lockAttemptRow($conn, $attemptKey);
            $blocked = self::blockedUntilIsFuture($row['blocked_until'] ?? null);
            if (!$blocked && !empty($row['blocked_until'])) {
                self::resetAttemptRow($conn, $attemptKey);
            }
            $conn->commit();
            return $blocked;
        } catch (Throwable $e) {
            $conn->rollback();
            throw $e;
        }
    }

    public static function recordRateAttempt(
        mysqli $conn,
        string $attemptKey,
        bool $success,
        int $maxAttempts,
        int $windowSeconds
    ): void {
        $conn->begin_transaction();
        try {
            self::ensureAttemptRow($conn, $attemptKey);
            self::lockAttemptRow($conn, $attemptKey);
            if ($success) {
                self::clearAttemptRow($conn, $attemptKey);
            } else {
                self::incrementAttemptRow($conn, $attemptKey, $maxAttempts, $windowSeconds);
            }
            $conn->commit();
        } catch (Throwable $e) {
            $conn->rollback();
            throw $e;
        }
    }

    /** @return array{status:string,otp?:string,otp_hash?:string,cooldown?:int} */
    public static function issue(
        mysqli $conn,
        int $adminId,
        string $ip,
        int $ttlSeconds,
        int $resendSeconds,
        bool $mustExist = false
    ): array {
        if ($adminId <= 0) {
            return ['status' => 'missing'];
        }

        $otp = (string) random_int(100000, 999999);
        $otpHash = hash('sha256', $otp);
        $conn->begin_transaction();
        try {
            $inserted = false;
            if (!$mustExist) {
                // The unique admin_id key turns first-time concurrent issuance into
                // a single serialized row before cooldown is evaluated.
                $seed = $conn->prepare(
                    "INSERT IGNORE INTO admin_login_otps
                        (admin_id, otp_hash, expires_at, attempts, resend_available_at, created_ip)
                     VALUES (?, ?, DATE_ADD(UTC_TIMESTAMP(), INTERVAL ? SECOND), 0,
                             DATE_ADD(UTC_TIMESTAMP(), INTERVAL ? SECOND), ?)"
                );
                $seed->bind_param('isiis', $adminId, $otpHash, $ttlSeconds, $resendSeconds, $ip);
                $seed->execute();
                $inserted = $seed->affected_rows > 0;
            }
            $existing = self::lockOtpRow($conn, $adminId);
            if ($mustExist && !$existing) {
                $conn->commit();
                return ['status' => 'missing'];
            }

            if ($inserted) {
                $conn->commit();
                return ['status' => 'issued', 'otp' => $otp, 'otp_hash' => $otpHash];
            }

            if ($existing && self::utcTimestamp((string) ($existing['resend_available_at'] ?? '')) > time()) {
                $cooldown = max(1, self::utcTimestamp((string) $existing['resend_available_at']) - time());
                $conn->commit();
                return ['status' => 'cooldown', 'cooldown' => $cooldown];
            }

            if ($existing) {
                $stmt = $conn->prepare(
                    "UPDATE admin_login_otps
                     SET otp_hash = ?, expires_at = DATE_ADD(UTC_TIMESTAMP(), INTERVAL ? SECOND), attempts = 0,
                         resend_available_at = DATE_ADD(UTC_TIMESTAMP(), INTERVAL ? SECOND), created_ip = ?,
                         updated_at = CURRENT_TIMESTAMP
                     WHERE admin_id = ?"
                );
                $stmt->bind_param('siisi', $otpHash, $ttlSeconds, $resendSeconds, $ip, $adminId);
            } else {
                $conn->commit();
                return ['status' => 'missing'];
            }
            $stmt->execute();
            $conn->commit();
            return ['status' => 'issued', 'otp' => $otp, 'otp_hash' => $otpHash];
        } catch (Throwable $e) {
            $conn->rollback();
            throw $e;
        }
    }

    public static function invalidateIssuedOtp(mysqli $conn, int $adminId, string $otpHash): void
    {
        $stmt = $conn->prepare("DELETE FROM admin_login_otps WHERE admin_id = ? AND otp_hash = ?");
        $stmt->bind_param('is', $adminId, $otpHash);
        $stmt->execute();
    }

    /** @return array{status:string,admin?:array,passphrase_failed?:bool} */
    public static function verify(
        mysqli $conn,
        int $adminId,
        string $ip,
        string $otp,
        string $passphraseInput,
        string $configuredPassphrase,
        bool $passphraseRequired,
        int $maxOtpAttempts,
        int $maxRateAttempts,
        int $windowSeconds
    ): array {
        $attemptKey = self::otpAttemptKey('verify', $adminId, $ip);
        $conn->begin_transaction();
        try {
            self::ensureAttemptRow($conn, $attemptKey);
            $attemptRow = self::lockAttemptRow($conn, $attemptKey);
            $otpRow = self::lockOtpRow($conn, $adminId);

            if (self::blockedUntilIsFuture($attemptRow['blocked_until'] ?? null)) {
                self::deleteOtpRow($conn, $adminId);
                $conn->commit();
                return ['status' => 'blocked'];
            }
            if (!empty($attemptRow['blocked_until'])) {
                self::resetAttemptRow($conn, $attemptKey);
            }
            if (!$otpRow) {
                $conn->commit();
                return ['status' => 'missing'];
            }
            if (self::utcTimestamp((string) ($otpRow['expires_at'] ?? '')) <= time()) {
                self::deleteOtpRow($conn, $adminId);
                $conn->commit();
                return ['status' => 'expired'];
            }
            if ((int) ($otpRow['attempts'] ?? 0) >= $maxOtpAttempts) {
                self::deleteOtpRow($conn, $adminId);
                $conn->commit();
                return ['status' => 'exhausted'];
            }

            $otpValid = hash_equals((string) $otpRow['otp_hash'], hash('sha256', $otp));
            $passphraseValid = !$passphraseRequired
                || ($configuredPassphrase !== '' && hash_equals($configuredPassphrase, $passphraseInput));
            if (!$otpValid || !$passphraseValid) {
                self::incrementAttemptRow($conn, $attemptKey, $maxRateAttempts, $windowSeconds);
                $newAttempts = (int) ($otpRow['attempts'] ?? 0) + 1;
                if ($newAttempts >= $maxOtpAttempts) {
                    self::deleteOtpRow($conn, $adminId);
                    $status = 'exhausted';
                } else {
                    $stmt = $conn->prepare(
                        "UPDATE admin_login_otps SET attempts = ?, updated_at = CURRENT_TIMESTAMP WHERE admin_id = ?"
                    );
                    $stmt->bind_param('ii', $newAttempts, $adminId);
                    $stmt->execute();
                    $status = 'invalid';
                }
                $conn->commit();
                return ['status' => $status, 'passphrase_failed' => $otpValid && !$passphraseValid];
            }

            $adminStmt = $conn->prepare(
                "SELECT is_active, role, name, email FROM admins WHERE id = ? LIMIT 1 FOR UPDATE"
            );
            $adminStmt->bind_param('i', $adminId);
            $adminStmt->execute();
            $admin = $adminStmt->get_result()->fetch_assoc();
            if (!$admin || (int) ($admin['is_active'] ?? 1) !== 1) {
                self::deleteOtpRow($conn, $adminId);
                $conn->commit();
                return ['status' => 'inactive'];
            }

            // Consumption and limiter reset are committed together. A concurrent
            // verifier waits for this lock, then observes that the OTP is gone.
            self::deleteOtpRow($conn, $adminId);
            self::clearAttemptRow($conn, $attemptKey);
            $conn->commit();
            return ['status' => 'success', 'admin' => $admin];
        } catch (Throwable $e) {
            $conn->rollback();
            throw $e;
        }
    }

    public static function cooldownSeconds(mysqli $conn, int $adminId): int
    {
        if ($adminId <= 0) {
            return 0;
        }
        $stmt = $conn->prepare("SELECT resend_available_at FROM admin_login_otps WHERE admin_id = ? LIMIT 1");
        $stmt->bind_param('i', $adminId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        if (!$row) {
            return 0;
        }
        return max(0, self::utcTimestamp((string) ($row['resend_available_at'] ?? '')) - time());
    }

    private static function ensureAttemptRow(mysqli $conn, string $attemptKey): void
    {
        // A duplicate-key update takes the exclusive row lock immediately.
        // INSERT IGNORE would retain a shared lock and deadlock on FOR UPDATE.
        $stmt = $conn->prepare(
            "INSERT INTO admin_login_attempts (attempt_key, attempts, blocked_until) VALUES (?, 0, NULL)
             ON DUPLICATE KEY UPDATE attempts = attempts"
        );
        $stmt->bind_param('s', $attemptKey);
        $stmt->execute();
    }

    private static function lockAttemptRow(mysqli $conn, string $attemptKey): array
    {
        $stmt = $conn->prepare(
            "SELECT attempts, blocked_until FROM admin_login_attempts WHERE attempt_key = ? LIMIT 1 FOR UPDATE"
        );
        $stmt->bind_param('s', $attemptKey);
        $stmt->execute();
        return $stmt->get_result()->fetch_assoc() ?: ['attempts' => 0, 'blocked_until' => null];
    }

    private static function incrementAttemptRow(
        mysqli $conn,
        string $attemptKey,
        int $maxAttempts,
        int $windowSeconds
    ): void {
        $windowMinutes = (int) ceil(max(60, $windowSeconds) / 60);
        $stmt = $conn->prepare(
            "UPDATE admin_login_attempts
             SET attempts = attempts + 1,
                 blocked_until = CASE
                     WHEN (attempts + 1) >= ? THEN DATE_ADD(UTC_TIMESTAMP(), INTERVAL ? MINUTE)
                     ELSE blocked_until
                 END,
                 updated_at = CURRENT_TIMESTAMP
             WHERE attempt_key = ?"
        );
        $stmt->bind_param('iis', $maxAttempts, $windowMinutes, $attemptKey);
        $stmt->execute();
    }

    private static function clearAttemptRow(mysqli $conn, string $attemptKey): void
    {
        $stmt = $conn->prepare("DELETE FROM admin_login_attempts WHERE attempt_key = ?");
        $stmt->bind_param('s', $attemptKey);
        $stmt->execute();
    }

    private static function resetAttemptRow(mysqli $conn, string $attemptKey): void
    {
        $stmt = $conn->prepare(
            "UPDATE admin_login_attempts SET attempts = 0, blocked_until = NULL, updated_at = CURRENT_TIMESTAMP WHERE attempt_key = ?"
        );
        $stmt->bind_param('s', $attemptKey);
        $stmt->execute();
    }

    private static function lockOtpRow(mysqli $conn, int $adminId): ?array
    {
        $stmt = $conn->prepare(
            "SELECT otp_hash, expires_at, attempts, resend_available_at
             FROM admin_login_otps WHERE admin_id = ? LIMIT 1 FOR UPDATE"
        );
        $stmt->bind_param('i', $adminId);
        $stmt->execute();
        return $stmt->get_result()->fetch_assoc() ?: null;
    }

    private static function deleteOtpRow(mysqli $conn, int $adminId): void
    {
        $stmt = $conn->prepare("DELETE FROM admin_login_otps WHERE admin_id = ?");
        $stmt->bind_param('i', $adminId);
        $stmt->execute();
    }

    private static function blockedUntilIsFuture(mixed $value): bool
    {
        return self::utcTimestamp((string) ($value ?? '')) > time();
    }

    private static function utcTimestamp(string $value): int
    {
        if ($value === '') {
            return 0;
        }
        $timestamp = strtotime($value . ' UTC');
        return $timestamp === false ? 0 : $timestamp;
    }
}
