<?php
declare(strict_types=1);

$failures = [];
$assert = static function (bool $condition, string $message) use (&$failures): void {
    if (!$condition) {
        $failures[] = $message;
    }
};

function run_env_test(array $env, string $sapi = 'cli-server'): string
{
    $envLines = [];
    foreach ($env as $k => $v) {
        if ($v === false) {
            $envLines[] = "putenv('$k');";
            $envLines[] = "unset(\$_SERVER['$k']);";
        } else {
            $envLines[] = "putenv('$k=$v');";
            $envLines[] = "\$_SERVER['$k'] = '$v';";
        }
    }
    
    $script = '<?php
        ' . implode("\n", $envLines) . '
        $dbPhp = file_get_contents(__DIR__ . "/../config/db.php");
        $dbPhp = str_replace("PHP_SAPI", "\'' . $sapi . '\'", $dbPhp);
        $dbPhp = preg_replace("/new mysqli\(.*?\);/", "new class { public function set_charset(){} };", $dbPhp);
        $dbPhp = preg_replace("/function app_bootstrap_fail\(.*?\)\s*:\s*void\s*\{.*?\n\}/s", "function app_bootstrap_fail(\$a, \$b = null, \$c = 1): void { throw new Exception(\"app_bootstrap_fail: \" . \$a); }", $dbPhp);
        try {
            eval("?>" . $dbPhp);
            echo $mode ?? "unknown";
        } catch (Throwable $e) {
            echo $mode ?? ("Exception: " . $e->getMessage());
        }
    ';
    
    $tmpFile = __DIR__ . '/../config/.tmp_test.php';
    file_put_contents($tmpFile, $script);
    $output = shell_exec('php ' . escapeshellarg($tmpFile) . ' 2>&1');
    unlink($tmpFile);
    return trim((string)$output);
}

// A. APP_MODE=production + Host localhost => production remains production.
$mode = run_env_test(['APP_MODE' => 'production', 'HTTP_HOST' => 'localhost'], 'apache2handler');
$assert($mode === 'production', "APP_MODE=production + Host localhost should be production, got: $mode");

// B. Host spoof cannot enable debug_explain (which means local mode).
$mode = run_env_test(['HTTP_HOST' => 'localhost', 'APP_MODE' => false], 'apache2handler');
$assert($mode === 'production', "Spoofing localhost on standard SAPI should yield production, got: $mode");

// C. APP_MODE=local works in explicit local/test environment.
$mode = run_env_test(['APP_MODE' => 'local', 'HTTP_HOST' => 'amber.test'], 'apache2handler');
$assert($mode === 'local', "APP_MODE=local explicitly set should be local, got: $mode");

// D. CLI migration/test behavior is preserved.
$mode = run_env_test(['APP_MODE' => false, 'HTTP_HOST' => ''], 'cli');
$assert($mode === 'local', "CLI default should be local, got: $mode");

// E. ordinary production hostname works.
$mode = run_env_test(['APP_MODE' => false, 'HTTP_HOST' => 'amberfabrics.com'], 'apache2handler');
$assert($mode === 'production', "Ordinary production hostname should yield production, got: $mode");

// F. missing APP_MODE follows the project's documented safe default (production on web).
$mode = run_env_test(['APP_MODE' => false, 'HTTP_HOST' => ''], 'apache2handler');
$assert($mode === 'production', "Missing APP_MODE on web should default to production, got: $mode");

// G. malformed Host values do not affect environment mode.
$mode = run_env_test(['APP_MODE' => false, 'HTTP_HOST' => '../../etc/passwd'], 'apache2handler');
$assert($mode === 'production', "Malformed Host values should yield production, got: $mode");

if ($failures !== []) {
    foreach ($failures as $failure) {
        fwrite(STDERR, "FAIL: {$failure}\n");
    }
    exit(1);
}

echo "environment_resolution_security_test: OK\n";
