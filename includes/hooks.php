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

/**
 * Execute action callbacks and return structured execution report.
 * Useful for cron/ops workflows that need per-callback visibility.
 *
 * @return array<int, array<string, mixed>>
 */
function do_action_report(string $hook, array $context = []): array
{
    $report = [];
    if (empty($GLOBALS['amber_hooks']['actions'][$hook]) || !is_array($GLOBALS['amber_hooks']['actions'][$hook])) {
        return $report;
    }

    ksort($GLOBALS['amber_hooks']['actions'][$hook]);
    foreach ($GLOBALS['amber_hooks']['actions'][$hook] as $priority => $callbacks) {
        foreach ((array) $callbacks as $callback) {
            $name = 'closure';
            if (is_string($callback)) {
                $name = $callback;
            } elseif (is_array($callback) && count($callback) >= 2) {
                $name = (is_object($callback[0]) ? get_class($callback[0]) : (string) $callback[0]) . '::' . (string) $callback[1];
            }
            $started = microtime(true);
            try {
                $callback($context);
                $report[] = [
                    'hook' => $hook,
                    'priority' => (int) $priority,
                    'callback' => $name,
                    'ok' => true,
                    'duration_ms' => (int) round((microtime(true) - $started) * 1000),
                    'error' => '',
                ];
            } catch (Throwable $e) {
                error_log('[amber-plugin] action "' . $hook . '" failed: ' . $e->getMessage());
                $report[] = [
                    'hook' => $hook,
                    'priority' => (int) $priority,
                    'callback' => $name,
                    'ok' => false,
                    'duration_ms' => (int) round((microtime(true) - $started) * 1000),
                    'error' => $e->getMessage(),
                ];
            }
        }
    }

    return $report;
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
