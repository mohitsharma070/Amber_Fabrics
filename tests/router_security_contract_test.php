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
        $projectRoot = realpath(' . var_export(__DIR__ . '/..', true) . ');
        $router = file_get_contents($projectRoot . "/router.php");
        
        // Replace __DIR__ with the actual project root so static file checks work from the temp directory
        $router = preg_replace("/\b__DIR__\b/", var_export($projectRoot, true), $router);
        
        // Replace return false with a print and exit
        $router = str_replace("return false;", "echo \"SERVED_DIRECTLY\"; exit;", $router);
        
        // Mock require $scriptFile to prevent execution of index.php
        $router = str_replace("require \$scriptFile;", "echo \"ROUTED_TO: \" . \$relativeFile; exit;", $router);
        
        if (!str_contains($router, "SERVED_DIRECTLY")) {
            echo "FAILED_TO_PATCH_ROUTER"; exit;
        }
        
        // Catch exit("Forbidden") or exit("Bad Request")
        ob_start();
        try {
            eval("?>" . $router);
        } catch (Throwable $e) {
            echo "Exception: " . $e->getMessage();
        }
        $out = ob_get_clean();
        echo $out;
    ';
    
    $tmpFile = tempnam(sys_get_temp_dir(), 'router_test_');
    if ($tmpFile === false) {
        return ['output' => 'FAILED_TO_CREATE_TEMP_FILE'];
    }
    
    try {
        file_put_contents($tmpFile, $script);
        $output = shell_exec('php ' . escapeshellarg($tmpFile) . ' 2>&1');
    } finally {
        unlink($tmpFile);
    }
    return ['output' => trim((string)$output)];
}

// A. /.env => forbidden
$res = test_router('/.env');
$assert(str_contains($res['output'], 'Forbidden'), "A. /.env should be Forbidden, got: " . $res['output']);

// B. /.env.production => forbidden without creating such a file
$res = test_router('/.env.production');
$assert(str_contains($res['output'], 'Forbidden'), "B. /.env.production should be Forbidden, got: " . $res['output']);

// C. /secure-config.production.php => forbidden
$res = test_router('/secure-config.production.php');
$assert(str_contains($res['output'], 'Forbidden'), "C. /secure-config.production.php should be Forbidden, got: " . $res['output']);

// D. /secure-config.local.php => forbidden
$res = test_router('/secure-config.local.php');
$assert(str_contains($res['output'], 'Forbidden'), "D. /secure-config.local.php should be Forbidden, got: " . $res['output']);

// E. /.git/... => forbidden
$res = test_router('/.git/config');
$assert(str_contains($res['output'], 'Forbidden'), "E. /.git/config should be Forbidden, got: " . $res['output']);

// F. internal directories remain forbidden
$res = test_router('/includes/init.php');
$assert(str_contains($res['output'], 'Forbidden'), "F. /includes/init.php should be Forbidden, got: " . $res['output']);

// G. /composer.json => forbidden
$res = test_router('/composer.json');
$assert(str_contains($res['output'], 'Forbidden'), "G. /composer.json should be Forbidden, got: " . $res['output']);

// H. encoded traversal targeting .env/config => forbidden
$res = test_router('/css/..%2f.env');
$assert(str_contains($res['output'], 'Forbidden'), "H. encoded traversal to .env should be Forbidden, got: " . $res['output']);

// I. backslash/mixed traversal attempts => forbidden
$res = test_router('/css/..\\config/db.php');
$assert(str_contains($res['output'], 'Forbidden'), "I. backslash traversal should be Forbidden, got: " . $res['output']);

// J. trailing-dot sensitive filename bypass attempts => forbidden
$res = test_router('/.env.');
$assert(str_contains($res['output'], 'Forbidden'), "J. /.env. trailing dot should be Forbidden, got: " . $res['output']);

$res = test_router('/composer.json.');
$assert(str_contains($res['output'], 'Forbidden'), "J. /composer.json. trailing dot should be Forbidden, got: " . $res['output']);

// K. ordinary public assets still work (using a temporary public asset to verify is_file logic if needed)
$safePublicAsset = __DIR__ . '/../css/router_safe_test_asset_' . md5(uniqid()) . '.css';
if (!file_exists($safePublicAsset)) {
    try {
        file_put_contents($safePublicAsset, 'body{}');
        $basename = basename($safePublicAsset);
        $res = test_router('/css/' . $basename);
        $assert(str_contains($res['output'], 'SERVED_DIRECTLY'), "K. public CSS should be served directly, got: " . $res['output']);
    } finally {
        if (file_exists($safePublicAsset)) {
            unlink($safePublicAsset);
        }
    }
}

// L. normal clean routes still work
$res = test_router('/about');
$assert(str_contains($res['output'], 'ROUTED_TO: about.php'), "L. /about should route to about.php, got: " . $res['output']);

if ($failures !== []) {
    foreach ($failures as $failure) {
        fwrite(STDERR, "FAIL: {$failure}\n");
    }
    exit(1);
}

echo "router_security_contract_test: OK\n";
