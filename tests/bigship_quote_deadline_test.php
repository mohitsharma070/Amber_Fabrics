<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/services/BigshipService.php';
$assert = static function (bool $ok, string $message): void {
    if (!$ok) throw new RuntimeException($message);
};
$assert(defined('BigshipService::STOREFRONT_QUOTE_TIMEOUT_MS'), 'Storefront quotes need a shared provider deadline.');
$root = dirname(__DIR__);
$GLOBALS['_app_mode'] = 'local';
$nullDevice = PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null';
foreach (['success', 'timeout', 'login-timeout', 'shared-timeout', 'retry-timeout', 'error', '429', '503', 'no-rate'] as $scenario) {
    $socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
    $assert(is_resource($socket), 'Cannot allocate loopback port.');
    $address = stream_socket_get_name($socket, false);
    fclose($socket);
    $process = proc_open([PHP_BINARY, '-S', $address, $root . '/tests/helpers/bigship_quote_stub.php'],
        [0 => ['pipe', 'r'], 1 => ['file', $nullDevice, 'a'], 2 => ['file', $nullDevice, 'a']], $pipes, $root);
    $assert(is_resource($process), 'Cannot start loopback fixture.');
    try {
        for ($i = 0; $i < 100; $i++) {
            $ready = @stream_socket_client('tcp://' . $address, $errno, $error, 0.05);
            if (is_resource($ready)) { fclose($ready); break; }
            usleep(20000);
        }
        $client = new BigshipService(['api_base_url' => 'http://' . $address . '/' . $scenario]);
        $started = hrtime(true);
        $result = $client->rates([], $started + BigshipService::STOREFRONT_QUOTE_TIMEOUT_MS * 1000000);
        $elapsed = (hrtime(true) - $started) / 1e9;
        $assert($elapsed < 8.0, "$scenario exceeded the provider budget: $elapsed seconds.");
        if (str_contains($scenario, 'timeout')) {
            $assert(empty($result['ok']), "$scenario must fail for manual fallback.");
            $assert($elapsed >= 6.5, "$scenario did not exercise the real seven-second deadline.");
        } else {
            $assert(!empty($result['ok']) === in_array($scenario, ['success', 'no-rate'], true), "$scenario returned unexpected status.");
        }
        // Expired budgets must not start login or rate I/O; no zero (unlimited) cURL timeout.
        $expired = $client->rates([], hrtime(true) - 1);
        $assert(empty($expired['ok']), 'An expired quote deadline must fail closed.');
        $property = new ReflectionProperty(BigshipService::class, 'deadlineNs');
        $assert($property->getValue($client) === null, 'Quote deadline leaked to subsequent admin/shipment operations.');
        // Both throttle and retry pauses must consume only the remaining budget.
        $property->setValue($client, hrtime(true) + 20000000);
        $pause = new ReflectionMethod(BigshipService::class, 'pause');
        $pauseStarted = hrtime(true);
        $pause->invoke($client, 250000);
        $assert((hrtime(true) - $pauseStarted) / 1e9 < 0.15, 'Throttle/backoff slept past the remaining quote budget.');
        $property->setValue($client, null);
        echo "$scenario: " . number_format($elapsed, 3) . "s\n";
    } finally {
        fclose($pipes[0]);
        proc_terminate($process);
        proc_close($process);
    }
}
echo "Bigship quote deadline tests passed.\n";
