<?php
require_once __DIR__ . '/../services/SiteSettingsService.php';

function site_settings_defaults(): array
{
    return SiteSettingsService::defaults();
}

function ensure_site_settings_table(mysqli $conn): bool
{
    return SiteSettingsService::ensureTable($conn);
}

function load_site_settings_from_db(mysqli $conn): array
{
    return SiteSettingsService::loadFromDb($conn);
}

function save_site_settings_to_db(mysqli $conn, array $settings): void
{
    SiteSettingsService::saveToDb($conn, $settings);
}

/**
 * Category taxonomy policy.
 *
 * Decision: slugs are permanent business rules for storefront flows.
 * Rationale: pricing/stock/variant UX and merchandising are tightly coupled to
 * a fixed top-level taxonomy; labels/media remain editable in admin.
 */
function category_taxonomy_mode(): string
{
    return 'locked';
}

function locked_storefront_category_slugs(): array
{
    return ['fabric-by-meter', 'bedsheets', 'towels', 'table-covers'];
}

function is_storefront_category_slug(string $slug): bool
{
    return in_array(trim(strtolower($slug)), locked_storefront_category_slugs(), true);
}

/**
 * Fetch storefront categories in business order.
 */
function storefront_categories_fetch(mysqli $conn): array
{
    $slugs = locked_storefront_category_slugs();
    if (empty($slugs)) {
        return [];
    }

    $placeholders = implode(',', array_fill(0, count($slugs), '?'));
    $orderCaseParts = [];
    foreach ($slugs as $idx => $slug) {
        $orderCaseParts[] = "WHEN '" . $conn->real_escape_string($slug) . "' THEN " . ($idx + 1);
    }
    $orderCase = implode(' ', $orderCaseParts);
    $sql = "SELECT id, name, slug, parent_id
            FROM categories
            WHERE status = 'active' AND slug IN ($placeholders)
            ORDER BY CASE slug {$orderCase} ELSE 999 END, name ASC";

    try {
        $stmt = $conn->prepare($sql);
        $stmt->bind_param(str_repeat('s', count($slugs)), ...$slugs);
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        return is_array($rows) ? $rows : [];
    } catch (Throwable $e) {
        return [];
    }
}

/**
 * Compatibility wrapper for legacy callers.
 */
function get_site_settings(): array
{
    return SiteSettingsService::get();
}

/**
 * Ensure announcement dismissal table exists.
 */
function ensure_announcement_dismissals_table(mysqli $conn): bool
{
    static $checked = false;
    static $available = false;
    if ($checked) {
        return $available;
    }
    $checked = true;

    try {
        $stmt = $conn->prepare(
            "SELECT COUNT(*) AS total
             FROM information_schema.TABLES
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = 'announcement_dismissals'"
        );
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $available = ((int) ($row['total'] ?? 0)) > 0;
        if (!$available) {
            error_log('[app] announcement_dismissals table missing. Run: php database/setup.php');
        }
    } catch (Throwable $e) {
        $available = false;
        error_log('[app] announcement_dismissals table check failed: ' . $e->getMessage());
    }

    return $available;
}

/**
 * Build a stable server-side key for current visitor session.
 */
function announcement_session_key(): string
{
    $sid = session_id();
    if ($sid === '') {
        $sid = 'no-session';
    }
    return hash('sha256', $sid . '|announcement');
}

/**
 * Check if current announcement set has been dismissed by this visitor.
 */
function announcement_is_dismissed(mysqli $conn, string $announcementKey): bool
{
    if ($announcementKey === '') {
        return false;
    }

    if (!ensure_announcement_dismissals_table($conn)) {
        return false;
    }
    $sessionKey = announcement_session_key();
    $stmt = $conn->prepare(
        "SELECT id
         FROM announcement_dismissals
         WHERE session_key = ? AND announcement_key = ?
         LIMIT 1"
    );
    $stmt->bind_param('ss', $sessionKey, $announcementKey);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    return !empty($row);
}

/**
 * Persist dismissal for the current visitor + announcement key.
 */
function announcement_mark_dismissed(mysqli $conn, string $announcementKey): bool
{
    if ($announcementKey === '') {
        return false;
    }

    if (!ensure_announcement_dismissals_table($conn)) {
        return false;
    }
    $sessionKey = announcement_session_key();
    $customerId = !empty($_SESSION['customer_id']) ? (int) $_SESSION['customer_id'] : null;

    $stmt = $conn->prepare(
        "INSERT INTO announcement_dismissals (session_key, customer_id, announcement_key)
         VALUES (?, ?, ?)
         ON DUPLICATE KEY UPDATE
            customer_id = VALUES(customer_id),
            updated_at = CURRENT_TIMESTAMP"
    );
    $stmt->bind_param('sis', $sessionKey, $customerId, $announcementKey);
    return $stmt->execute();
}
