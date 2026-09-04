<?php
declare(strict_types=1);

$failures = [];
$assert = static function (bool $condition, string $message) use (&$failures): void {
    if (!$condition) {
        $failures[] = $message;
    }
};

function test_router(string $uri): array {
    $script = '<?php
        $_SERVER["REQUEST_URI"] = ' . var_export($uri, true) . ';
        $_SERVER["REQUEST_METHOD"] = "GET";
        $_SERVER["HTTP_HOST"] = "localhost:8000";
        // mock return false inside router
        $router = file_get_contents(__DIR__ . "/router.php");
        
        // Replace return false with a print and exit
        $router = str_replace("return false;", "echo \"SERVED_DIRECTLY\"; exit;", $router);
        
        // Mock require $scriptFile to prevent execution of index.php
        $router = str_replace("require \$scriptFile;", "echo \"ROUTED_TO: \" . \$relativeFile; exit;", $router);
        
        if (!str_contains($router, "SERVED_DIRECTLY")) {
            echo "FAILED_TO_PATCH_ROUTER"; exit;
        }
        
        // Catch exit("Forbidden")
        ob_start();
        try {
            eval("?>" . $router);
        } catch (Throwable $e) {
            echo "Exception: " . $e->getMessage();
        }
        $out = ob_get_clean();
        echo $out;
    ';
    
    $tmpFile = __DIR__ . '/../.tmp_router_test.php';
    file_put_contents($tmpFile, $script);
    $output = shell_exec('php ' . escapeshellarg($tmpFile) . ' 2>&1');
    // unlink($tmpFile);
    return ['output' => trim((string)$output)];
}

// A. /.env => forbidden
$res = test_router('/.env');
$assert(str_contains($res['output'], 'Forbidden'), "A. /.env should be Forbidden, got: " . $res['output']);

// B. /.env.production => forbidden
file_put_contents(__DIR__ . '/../.env.production', 'dummy');
$res = test_router('/.env.production');
$assert(str_contains($res['output'], 'Forbidden'), "B. /.env.production should be Forbidden, got: " . $res['output']);
unlink(__DIR__ . '/../.env.production');

// C. /.git/something => forbidden
if (!is_dir(__DIR__ . '/../.git')) mkdir(__DIR__ . '/../.git');
file_put_contents(__DIR__ . '/../.git/dummy_test_file', 'dummy');
$res = test_router('/.git/dummy_test_file');
$assert(str_contains($res['output'], 'Forbidden'), "C. /.git/dummy_test_file should be Forbidden, got: " . $res['output']);
unlink(__DIR__ . '/../.git/dummy_test_file');

// D. /.htaccess => forbidden
$res = test_router('/.htaccess');
$assert(str_contains($res['output'], 'Forbidden'), "D. /.htaccess should be Forbidden, got: " . $res['output']);

// E. /composer.json => forbidden
$res = test_router('/composer.json');
$assert(str_contains($res['output'], 'Forbidden'), "E. /composer.json should be Forbidden, got: " . $res['output']);

// F. public CSS/JS/image files still work
// Create a dummy CSS file
if (!is_dir(__DIR__ . '/../css')) mkdir(__DIR__ . '/../css');
file_put_contents(__DIR__ . '/../css/test.css', 'body{}');
$res = test_router('/css/test.css');
$assert(str_contains($res['output'], 'SERVED_DIRECTLY'), "F. public CSS should be served directly, got: " . $res['output']);
unlink(__DIR__ . '/../css/test.css');

// G. normal clean routes still work
$res = test_router('/about');
// Because it does not map to an existing file, it will route to index.php with url=about
$assert(str_contains($res['output'], 'ROUTED_TO: about.php'), "G. /about should route to about.php, got: " . $res['output']);

// H. encoded traversal/dotfile attempts fail safely
$res = test_router('/css/..%2f.env');
$assert(str_contains($res['output'], 'Forbidden'), "H. encoded traversal to .env should be Forbidden, got: " . $res['output']);

// I. Windows trailing dot bypass
$res = test_router('/.env.');
$assert(str_contains($res['output'], 'Forbidden'), "I. /.env. trailing dot should be Forbidden, got: " . $res['output']);

$res = test_router('/composer.json.');
$assert(str_contains($res['output'], 'Forbidden'), "I. /composer.json. trailing dot should be Forbidden, got: " . $res['output']);

if ($failures !== []) {
    foreach ($failures as $failure) {
        fwrite(STDERR, "FAIL: {$failure}\n");
    }
    exit(1);
}

echo "router_security_contract_test: OK\n";
