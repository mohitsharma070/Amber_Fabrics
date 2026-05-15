<?php
/**
 * Lightweight hook registry for optional site plugins.
 *
 * Plugins can attach behavior without changing core checkout/payment files.
 */

function add_action(string $hook, callable $callback, int $priority = 10): void
{
    $GLOBALS['amber_hooks']['actions'][$hook][$priority][] = $callback;
}

function do_action(string $hook, array $context = []): void
{
    if (empty($GLOBALS['amber_hooks']['actions'][$hook]) || !is_array($GLOBALS['amber_hooks']['actions'][$hook])) {
        return;
    }

    ksort($GLOBALS['amber_hooks']['actions'][$hook]);
    foreach ($GLOBALS['amber_hooks']['actions'][$hook] as $callbacks) {
        foreach ((array) $callbacks as $callback) {
            try {
                $callback($context);
            } catch (Throwable $e) {
                error_log('[amber-plugin] action "' . $hook . '" failed: ' . $e->getMessage());
            }
        }
    }
}

function add_filter(string $hook, callable $callback, int $priority = 10): void
{
    $GLOBALS['amber_hooks']['filters'][$hook][$priority][] = $callback;
}

function apply_filters(string $hook, $value, array $context = [])
{
    if (empty($GLOBALS['amber_hooks']['filters'][$hook]) || !is_array($GLOBALS['amber_hooks']['filters'][$hook])) {
        return $value;
    }

    ksort($GLOBALS['amber_hooks']['filters'][$hook]);
    foreach ($GLOBALS['amber_hooks']['filters'][$hook] as $callbacks) {
        foreach ((array) $callbacks as $callback) {
            try {
                $value = $callback($value, $context);
            } catch (Throwable $e) {
                error_log('[amber-plugin] filter "' . $hook . '" failed: ' . $e->getMessage());
            }
        }
    }

    return $value;
}

