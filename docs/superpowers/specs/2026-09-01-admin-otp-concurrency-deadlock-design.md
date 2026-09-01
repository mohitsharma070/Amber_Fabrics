# Admin OTP Concurrency Deadlock Design

## Problem

`AdminOtpService::verify()` currently creates the rate-limit row with
`INSERT IGNORE` after starting its transaction and then locks that row with
`SELECT ... FOR UPDATE`. Concurrent invalid OTP requests for the same admin and
IP can retain duplicate-key locks from the insert while each transaction tries
to upgrade to an exclusive row lock. MySQL resolves that cycle by aborting
workers with deadlock error 1213.

The aborted workers do not increment either the OTP attempt counter or the
rate-limit counter. Consequently, five simultaneous invalid guesses can leave
the OTP active and record fewer than five attempts.

## Approved approach

Keep attempt-row creation inside the mutation transaction, but replace
`INSERT IGNORE` with `INSERT ... ON DUPLICATE KEY UPDATE` using a no-op update.
On an existing key, the duplicate-key update acquires the exclusive row lock
immediately instead of retaining a shared duplicate-key lock that must later be
upgraded. The following `SELECT ... FOR UPDATE` can therefore read the row
without a competing lock-upgrade cycle.

This preserves the rate-limit-row-before-OTP-row lock order and keeps row
creation, attempt changes, and OTP changes atomic in the existing transaction.

## Compatibility boundary

- Do not change OTP statuses, attempt thresholds, cooldown calculations, schema,
  endpoint fields, redirects, or authentication behavior.
- Do not add deadlock retries or hide failed workers in the test harness.
- Apply the corrected seed-and-lock behavior through the shared
  `ensureAttemptRow()` helper used by `isRateLimited()`, `recordRateAttempt()`,
  and `verify()`.
- Preserve the existing CI, setup-schema, and release-blocker test changes.
- Do not commit or push without separate user authorization.

## Verification

Add an explicit integration assertion that concurrent invalid OTP workers never
return the helper's `failed` status. Confirm that assertion fails before the
service change. After implementation, run the disposable MySQL concurrency
test, lint the changed PHP files, run the registered non-integration suite, run
the full disposable MySQL integration script, and review the final diff.
