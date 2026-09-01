# Admin OTP Concurrency Deadlock Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Prevent deterministic MySQL deadlocks and lost increments during concurrent admin OTP verification.

**Architecture:** Replace the transaction-local `INSERT IGNORE` seed with a no-op duplicate-key update that acquires the exclusive row lock immediately. Preserve the existing rate-limit-row-before-OTP-row lock order and keep row creation and attempt changes atomic inside the transaction.

**Tech Stack:** PHP 8.2+, `mysqli`, MySQL/MariaDB, process-based PHP integration tests.

**Spec:** `docs/superpowers/specs/2026-09-01-admin-otp-concurrency-deadlock-design.md`

## Global Constraints

- Preserve OTP statuses, thresholds, cooldown calculations, schema, endpoint contracts, and authentication behavior.
- Do not add deadlock retry logic.
- Preserve unrelated staged and unstaged changes.
- Do not commit or push without separate user authorization.

---

### Task 1: Make worker database failures an explicit regression

**Files:**
- Modify: `tests/priority_findings_mysql_integration_test.php:195-204`

**Interfaces:**
- Consumes: `$runConcurrent(array $workerArgs): array` statuses, including `failed` when the worker catches a database exception.
- Produces: A regression assertion that all five invalid OTP workers complete without database failure.

- [x] **Step 1: Write the failing assertion**

```php
$assert(
    count(array_filter($guessResults, static fn(string $status): bool => $status === 'failed')) === 0,
    'Concurrent invalid OTP verification must not fail with a database error.'
);
```

- [x] **Step 2: Run the focused integration test to verify RED**

Run with `AMBER_RUN_DISPOSABLE_MYSQL_TESTS=1` and the repository's ignored local test database configuration:

```powershell
php tests/priority_findings_mysql_integration_test.php
```

Expected: FAIL with the new database-error assertion and the existing attempt-limit assertions.

### Task 2: Acquire an exclusive lock while seeding the rate-limit row

**Files:**
- Modify: `includes/services/AdminOtpService.php:236-243`

**Interfaces:**
- Consumes: `private static function ensureAttemptRow(mysqli $conn, string $attemptKey): void` and `private static function lockAttemptRow(mysqli $conn, string $attemptKey): array`.
- Produces: The existing public return values of `isRateLimited()`, `recordRateAttempt()`, and `verify()` with serialized concurrent updates and no shared-lock upgrade during `FOR UPDATE`.

- [x] **Step 1: Apply the minimal locking change**

Keep `ensureAttemptRow()` inside each existing transaction and change its SQL to
take an exclusive lock on duplicate keys:

```php
$stmt = $conn->prepare(
    "INSERT INTO admin_login_attempts (attempt_key, attempts, blocked_until) VALUES (?, 0, NULL)
     ON DUPLICATE KEY UPDATE attempts = attempts"
);
```

Keep the public methods and the remainder of each transaction unchanged.

- [x] **Step 2: Run the focused integration test to verify GREEN**

```powershell
php tests/priority_findings_mysql_integration_test.php
```

Expected: PASS with no worker `failed` status, the OTP deleted at five invalid attempts, and the limiter counter equal to five.

### Task 3: Verify compatibility and repository state

**Files:**
- Verify: `includes/services/AdminOtpService.php`
- Verify: `tests/priority_findings_mysql_integration_test.php`
- Preserve: `.github/workflows/ci.yml`
- Preserve: `database/setup.php`
- Preserve: `tests/reliability_release_blockers_contract_test.php`

**Interfaces:**
- Consumes: Composer scripts `test` and `test:integration` from `composer.json`.
- Produces: Lint, contract-test, integration-test, and diff evidence for handoff.

- [x] **Step 1: Lint changed PHP files**

```powershell
php -l includes/services/AdminOtpService.php
php -l tests/priority_findings_mysql_integration_test.php
```

- [x] **Step 2: Run the registered non-integration suite**

Run `composer test` when Composer is available. Otherwise execute the exact
`scripts.test` PHP command chain from `composer.json` directly.

- [x] **Step 3: Run all disposable MySQL integration tests**

Run `composer test:integration` with `AMBER_RUN_DISPOSABLE_MYSQL_TESTS=1` when
Composer is available. Otherwise execute the three PHP commands registered in
`scripts.test:integration` directly.

- [x] **Step 4: Review the final diff without committing**

```powershell
git diff --check
git status --short
git diff -- includes/services/AdminOtpService.php tests/priority_findings_mysql_integration_test.php
```

Expected: no whitespace errors, only the approved service/test changes plus the
pre-existing CI task changes and these approved design/plan documents.
