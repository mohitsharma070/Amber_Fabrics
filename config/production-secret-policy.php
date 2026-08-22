<?php

function app_config_is_placeholder(string $value): bool
{
    $value = trim($value);
    if ($value === '') {
        return false;
    }
    $lower = strtolower($value);
    foreach (['replace-with', 'your-', 'your_', 'xxxxx', 'example.com', 'yourdomain', 'db-host-from-provider', 'db-username', 'db-name'] as $needle) {
        if (strpos($lower, $needle) !== false) {
            return true;
        }
    }
    return in_array($value, ['YOUR_ACCESS_TOKEN', 'YOUR_APP_SECRET', 'YOUR_PHONE_NUMBER_ID'], true);
}

/** @return string[] Configuration key names only; values are never returned. */
function app_config_production_secret_issues(array $config): array
{
    $minimumLengths = [
        'ADMIN_LOGIN_PASSPHRASE' => 16,
        'APP_IDENTITY_HASH_KEY' => 32,
    ];
    $issues = [];
    foreach ($minimumLengths as $key => $minimumLength) {
        $value = trim((string) ($config[$key] ?? ''));
        if (strlen($value) < $minimumLength || app_config_is_placeholder($value)) {
            $issues[] = $key;
        }
    }
    return $issues;
}
