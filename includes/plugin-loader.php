<?php
require_once __DIR__ . '/hooks.php';

function plugin_config_path(): string
{
    return __DIR__ . '/../config/plugins.php';
}

function plugin_base_path(): string
{
    return __DIR__ . '/../plugins';
}

function plugin_load_config(): array
{
    $path = plugin_config_path();
    if (!is_file($path)) {
        return ['enabled' => []];
    }

    $config = require $path;
    if (!is_array($config)) {
        return ['enabled' => []];
    }

    $enabled = $config['enabled'] ?? [];
    if (!is_array($enabled)) {
        $enabled = [];
    }

    $settings = $config['settings'] ?? [];
    if (!is_array($settings)) {
        $settings = [];
    }

    return [
        'enabled' => array_values(array_unique(array_filter(array_map('strval', $enabled)))),
        'settings' => $settings,
    ];
}

function plugin_is_safe_name(string $plugin): bool
{
    return (bool) preg_match('/^[a-z0-9][a-z0-9_-]*$/', $plugin);
}

function plugin_setting(string $plugin, string $key, $default = null)
{
    $settings = $GLOBALS['amber_plugin_settings'][$plugin] ?? [];
    if (!is_array($settings) || !array_key_exists($key, $settings)) {
        return $default;
    }

    return $settings[$key];
}

function plugin_load_all(): void
{
    static $loaded = false;
    if ($loaded) {
        return;
    }
    $loaded = true;

    $config = plugin_load_config();
    $GLOBALS['amber_plugin_settings'] = $config['settings'] ?? [];

    foreach (($config['enabled'] ?? []) as $plugin) {
        $plugin = strtolower(trim((string) $plugin));
        if ($plugin === '' || !plugin_is_safe_name($plugin)) {
            error_log('[amber-plugin] skipped invalid plugin name: ' . $plugin);
            continue;
        }

        $entry = plugin_base_path() . '/' . $plugin . '/plugin.php';
        if (!is_file($entry)) {
            error_log('[amber-plugin] missing plugin entry: ' . $entry);
            continue;
        }

        try {
            require_once $entry;
            $GLOBALS['amber_loaded_plugins'][] = $plugin;
        } catch (Throwable $e) {
            error_log('[amber-plugin] failed loading "' . $plugin . '": ' . $e->getMessage());
        }
    }
}

