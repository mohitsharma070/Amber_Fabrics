<?php

final class OrderAccessService
{
    private const IDLE_SECONDS = 1800;
    private const ABSOLUTE_SECONDS = 7200;

    public static function enabled(): bool { return (int) plugin_setting('conversion-mvp', 'guest_order_access_enabled', 1) === 1; }

    private static function purposeEnabled(string $purpose): bool
    {
        if ($purpose === 'activate') {
            return (int) plugin_setting('conversion-mvp', 'account_activation_enabled', 1) === 1;
        }
        return self::enabled();
    }

    public static function createToken(mysqli $conn, int $orderId, string $purpose = 'manage', ?int $ttlSeconds = null): string
    {
        if ($orderId <= 0 || !in_array($purpose, ['manage', 'activate'], true) || !self::purposeEnabled($purpose)) { throw new InvalidArgumentException('Invalid order access token request.'); }
        $configuredTtl = $purpose === 'activate'
            ? (int) plugin_setting('conversion-mvp', 'account_activation_token_ttl_seconds', 86400)
            : (int) plugin_setting('conversion-mvp', 'guest_order_token_ttl_seconds', 1800);
        $ttl = $ttlSeconds ?? $configuredTtl;
        $raw = bin2hex(random_bytes(32));
        $hash = hash('sha256', $raw);
        $expires = gmdate('Y-m-d H:i:s', time() + max(300, $ttl));
        $ip = substr((string) ($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45);
        $ua = substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255);
        $stmt = $conn->prepare("INSERT INTO guest_order_access_tokens (order_id, token_hash, purpose, expires_at, created_ip, created_user_agent) VALUES (?, ?, ?, ?, ?, ?)");
        $stmt->bind_param('isssss', $orderId, $hash, $purpose, $expires, $ip, $ua);
        $stmt->execute();
        return $raw;
    }

    public static function consume(mysqli $conn, string $raw, string $purpose = 'manage'): ?array
    {
        if (!in_array($purpose, ['manage', 'activate'], true) || !self::purposeEnabled($purpose) || !preg_match('/^[a-f0-9]{64}$/', $raw)) { return null; }
        $hash = hash('sha256', $raw);
        $conn->begin_transaction();
        try {
            $stmt = $conn->prepare("SELECT t.id, t.order_id, o.order_number, o.customer_email FROM guest_order_access_tokens t JOIN orders o ON o.id = t.order_id WHERE t.token_hash = ? AND t.purpose = ? AND t.consumed_at IS NULL AND t.expires_at > UTC_TIMESTAMP() LIMIT 1 FOR UPDATE");
            $stmt->bind_param('ss', $hash, $purpose); $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            if (!$row) { $conn->rollback(); return null; }
            $id = (int) $row['id'];
            $upd = $conn->prepare("UPDATE guest_order_access_tokens SET consumed_at = UTC_TIMESTAMP() WHERE id = ?");
            $upd->bind_param('i', $id); $upd->execute(); $conn->commit();
            return $row;
        } catch (Throwable $e) { $conn->rollback(); throw $e; }
    }

    public static function grant(int $orderId): void
    {
        session_regenerate_id(true);
        $_SESSION['guest_order_access'] = ['order_id' => $orderId, 'started_at' => time(), 'last_seen_at' => time(), 'fingerprint' => self::fingerprint()];
    }

    public static function guestOrderId(): int
    {
        if(!self::enabled()){return 0;}
        $grant = $_SESSION['guest_order_access'] ?? null;
        if (!is_array($grant)) { return 0; }
        $now = time();
        if (($now - (int) ($grant['last_seen_at'] ?? 0)) > self::IDLE_SECONDS || ($now - (int) ($grant['started_at'] ?? 0)) > self::ABSOLUTE_SECONDS || !hash_equals((string) ($grant['fingerprint'] ?? ''), self::fingerprint())) { unset($_SESSION['guest_order_access']); return 0; }
        $_SESSION['guest_order_access']['last_seen_at'] = $now;
        return (int) ($grant['order_id'] ?? 0);
    }

    public static function canAccess(int $orderId): bool
    {
        if ($orderId <= 0) { return false; }
        $customerId = (int) ($_SESSION['customer_id'] ?? 0); $conn = $GLOBALS['conn'] ?? null;
        if ($customerId > 0 && $conn instanceof mysqli) {
            $stmt = $conn->prepare("SELECT id FROM orders WHERE id = ? AND customer_id = ? LIMIT 1");
            $stmt->bind_param('ii', $orderId, $customerId); $stmt->execute();
            return (bool) $stmt->get_result()->fetch_assoc();
        }
        return self::guestOrderId() === $orderId;
    }

    public static function order(mysqli $conn, int $orderId): ?array
    {
        if (!self::canAccess($orderId)) { return null; }
        $stmt = $conn->prepare("SELECT * FROM orders WHERE id = ? LIMIT 1"); $stmt->bind_param('i', $orderId); $stmt->execute();
        return $stmt->get_result()->fetch_assoc() ?: null;
    }

    public static function actor(): array
    {
        $customerId = (int) ($_SESSION['customer_id'] ?? 0);
        return $customerId > 0 ? ['type' => 'customer', 'id' => $customerId, 'name' => 'customer'] : ['type' => 'guest', 'id' => 0, 'name' => 'guest'];
    }

    private static function fingerprint(): string { return hash('sha256', (string) ($_SERVER['HTTP_USER_AGENT'] ?? 'unknown')); }
}
