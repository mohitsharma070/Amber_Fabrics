<?php

final class ExternalUrlPolicy
{
    public static function sanitize(?string $value): string
    {
        $url = trim((string) $value);
        if ($url === '' || !filter_var($url, FILTER_VALIDATE_URL)) {
            return '';
        }

        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
        if (!in_array($scheme, ['http', 'https'], true)) {
            return '';
        }

        return $url;
    }
}
