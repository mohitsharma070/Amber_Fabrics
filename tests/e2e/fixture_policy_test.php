<?php
declare(strict_types=1);

require_once __DIR__ . '/fixture-policy.php';

$failures = [];
$assertSame = static function (mixed $actual, mixed $expected, string $message) use (&$failures): void {
    if ($actual !== $expected) {
        $failures[] = $message;
    }
};

$assertSame(
    e2e_fixture_policy_errors('production', '1', 'test', 'amber_fabrics_e2e'),
    ['E2E fixtures require APP_MODE=local.'],
    'Production mode must be rejected.'
);
$assertSame(
    e2e_fixture_policy_errors('local', '', 'test', 'amber_fabrics_e2e'),
    ['E2E fixtures require E2E_FIXTURE_CONFIRM=1.'],
    'Missing explicit fixture confirmation must be rejected.'
);
$assertSame(
    e2e_fixture_policy_errors('local', '1', 'test', 'amber_fabrics'),
    ['E2E fixtures require a database name ending in _test or _e2e.'],
    'An ordinary database name must be rejected.'
);
$assertSame(
    e2e_fixture_policy_errors('local', '1', '', 'amber_fabrics_e2e'),
    ['E2E fixtures require APP_ENV=test.'],
    'An empty application environment must be rejected.'
);
$assertSame(
    e2e_fixture_policy_errors('local', '1', 'development', 'amber_fabrics_e2e'),
    ['E2E fixtures require APP_ENV=test.'],
    'A non-test application environment must be rejected.'
);
$assertSame(
    e2e_fixture_policy_errors('local', '1', 'local', 'amber_fabrics_e2e'),
    ['E2E fixtures require APP_ENV=test.'],
    'A local application environment must not be treated as test.'
);
$assertSame(
    e2e_fixture_policy_errors('local', '1', 'test', 'amber_fabrics_test'),
    [],
    'All required guards must accept an explicitly test-marked local database.'
);
$assertSame(
    e2e_fixture_policy_errors('LOCAL', '1', 'TEST', 'AMBER_FABRICS_E2E'),
    [],
    'Policy matching should be case-insensitive.'
);
$assertSame(
    e2e_fixture_policy_errors('production', '1', 'production', 'amber_fabrics_e2e'),
    [
        'E2E fixtures require APP_MODE=local.',
        'E2E fixtures require APP_ENV=test.',
    ],
    'Production-like combinations must be rejected before fixture mutation.'
);

if ($failures !== []) {
    fwrite(STDERR, "E2E fixture policy failures:\n- " . implode("\n- ", $failures) . "\n");
    exit(1);
}

echo "E2E fixture policy: OK\n";
