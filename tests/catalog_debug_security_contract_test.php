<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$catalog = (string) file_get_contents($root . '/catalog.php');
$failures = [];
$assert = static function (bool $condition, string $message) use (&$failures): void {
    if (!$condition) {
        $failures[] = $message;
    }
};

$assert(
    str_contains($catalog, "\$debugExplainAuthorized = ((\$GLOBALS['_app_mode'] ?? '') === 'local');"),
    'Catalog SQL diagnostics must require the established server-selected local runtime mode.'
);
$assert(
    str_contains($catalog, "\$debugExplainRequested = (string) (\$_GET['debug_explain'] ?? '') === '1';")
        && str_contains($catalog, '$debugExplain = $debugExplainAuthorized && $debugExplainRequested;'),
    'The public debug_explain parameter must not enable diagnostics without the local-mode authorization condition.'
);
$assert(
    !preg_match("/'debug_explain'\s*=>/", $catalog)
        && str_contains($catalog, "unset(\$params['debug_explain']);"),
    'Unauthorized debug state must be excluded from catalog state and removed from generated catalog URLs.'
);

$explainGuardPosition = strpos($catalog, 'if ($debugExplain)');
$explainSqlPosition = strpos($catalog, '$explainSql = "EXPLAIN " . $listSql;');
$explainRenderPosition = strpos($catalog, '<?php if ($debugExplain): ?>');
$assert(
    $explainGuardPosition !== false
        && $explainSqlPosition !== false
        && $explainGuardPosition < $explainSqlPosition
        && $explainRenderPosition !== false,
    'EXPLAIN execution and diagnostic rendering must use the authorized diagnostic state.'
);
$assert(
    !str_contains($catalog, '$explainError->getMessage()'),
    'Catalog diagnostic failures must not expose raw database exception messages.'
);

if ($failures !== []) {
    foreach ($failures as $failure) {
        fwrite(STDERR, "FAIL: {$failure}\n");
    }
    exit(1);
}

echo "catalog_debug_security_contract_test: OK\n";
