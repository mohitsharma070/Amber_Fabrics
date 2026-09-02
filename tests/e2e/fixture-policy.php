<?php
declare(strict_types=1);

/**
 * @return list<string>
 */
function e2e_fixture_policy_errors(string $mode, string $confirmation, string $appEnvironment, string $databaseName): array
{
    $errors = [];

    if (strtolower(trim($mode)) !== 'local') {
        $errors[] = 'E2E fixtures require APP_MODE=local.';
    }
    if (strtolower(trim($appEnvironment)) !== 'test') {
        $errors[] = 'E2E fixtures require APP_ENV=test.';
    }
    if (trim($confirmation) !== '1') {
        $errors[] = 'E2E fixtures require E2E_FIXTURE_CONFIRM=1.';
    }
    if (!preg_match('/_(?:test|e2e)$/i', trim($databaseName))) {
        $errors[] = 'E2E fixtures require a database name ending in _test or _e2e.';
    }

    return $errors;
}
