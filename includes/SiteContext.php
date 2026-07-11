<?php
require_once __DIR__ . '/services/SiteSettingsService.php';

final class SiteContext
{
    public static function name(): string
    {
        $settings = SiteSettingsService::get();
        $name = trim((string) ($settings['site_name'] ?? ''));
        return $name !== '' ? $name : 'Store';
    }

    public static function description(): string
    {
        $settings = SiteSettingsService::get();
        $description = trim((string) ($settings['site_description'] ?? ''));
        return $description !== '' ? $description : 'Quality ecommerce products for retail and bulk buyers.';
    }

    public static function contactEmail(): string
    {
        $settings = SiteSettingsService::get();
        $email = trim((string) ($settings['contact_email'] ?? ''));
        return $email !== '' ? $email : trim(_cfg('MAIL_FROM', ''));
    }

    public static function url(string $path = ''): string
    {
        $baseUrl = rtrim(_cfg('APP_URL', ''), '/');
        if ($baseUrl === '') {
            $protocol = app_request_is_https() ? 'https' : 'http';
            $baseUrl = $protocol . '://' . ($_SERVER['SERVER_NAME'] ?? 'localhost');
        }

        return $path === '' ? $baseUrl : $baseUrl . '/' . ltrim($path, '/');
    }

    public static function title(string $title = ''): string
    {
        $title = trim($title);
        return $title !== '' ? ($title . ' | ' . self::name()) : self::name();
    }
}
