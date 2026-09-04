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

function add_cron_action(callable $callback, int $priority = 10, bool $critical = false): void
{
    add_action('cron.tick', $callback, $priority);
    $GLOBALS['amber_cron_metadata'][$priority][cron_callback_name($callback)] = [
        'critical' => $critical,
    ];
}

function cron_callback_name(callable $callback): string
{
    if (is_string($callback)) {
        return $callback;
    }
    if (is_array($callback) && count($callback) >= 2) {
        return (is_object($callback[0]) ? get_class($callback[0]) : (string) $callback[0]) . '::' . (string) $callback[1];
    }
    return 'closure';
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
                $message = class_exists('CronService') ? CronService::sanitizeError($e->getMessage()) : $e->getMessage();
                error_log('[amber-plugin] action "' . $hook . '" failed: ' . $message);
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
            $name = cron_callback_name($callback);
            $critical = !empty($GLOBALS['amber_cron_metadata'][$priority][$name]['critical']);
            $started = microtime(true);
            try {
                $callbackResult = $callback($context);
                $result = class_exists('CronService')
                    ? CronService::normalizeResult($callbackResult)
                    : (is_array($callbackResult) ? $callbackResult : ['status' => 'success']);
                $report[] = [
                    'hook' => $hook,
                    'priority' => (int) $priority,
                    'callback' => $name,
                    'critical' => $critical,
                    'ok' => !in_array((string) ($result['status'] ?? 'success'), ['failed'], true),
                    'status' => (string) ($result['status'] ?? 'success'),
                    'duration_ms' => (int) round((microtime(true) - $started) * 1000),
                    'error' => '',
                    'result' => $result,
                ];
            } catch (Throwable $e) {
                $message = class_exists('CronService') ? CronService::sanitizeError($e->getMessage()) : $e->getMessage();
                error_log('[amber-plugin] action "' . $hook . '" failed: ' . $message);
                $report[] = [
                    'hook' => $hook,
                    'priority' => (int) $priority,
                    'callback' => $name,
                    'critical' => $critical,
                    'ok' => false,
                    'status' => 'failed',
                    'duration_ms' => (int) round((microtime(true) - $started) * 1000),
                    'error' => class_exists('CronService') ? CronService::sanitizeError($e->getMessage()) : $e->getMessage(),
                    'result' => null,
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

function apply_filters(string $hook, $value, array $context = [], bool $throwOnFailure = false)
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
                if ($throwOnFailure) {
                    throw $e;
                }
                if (function_exists('app_log')) {
                    app_log('error', 'plugin_filter_failed', [
                        'hook' => $hook,
                        'exception_type' => get_class($e),
                    ]);
                } else {
                    error_log('[amber-plugin] filter failed: ' . $hook . ' (' . get_class($e) . ')');
                }
            }
        }
    }

    return $value;
}
