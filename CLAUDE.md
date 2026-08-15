# CLAUDE.md

## Working agreement

Use [AGENTS.md](AGENTS.md) as the repository-wide authority. This file summarizes the safest investigation order for Claude-compatible coding agents.

## Start here

1. Check `git status` and preserve user work.
2. Read `README.md`, `AGENTS.md`, and `docs/repo-architecture.md`.
3. Trace the target endpoint from its PHP handler through `includes/init.php`, helpers/services, hooks/plugins, SQL, and response.
4. Inspect focused tests before editing.
5. Prefer additive docs or contract tests when the request does not authorize runtime changes.

## High-risk areas

Treat these as coupled workflows, not isolated files:

- checkout, shipping quotes, coupons, inventory reservation, and order creation
- Razorpay create/verify/failure/webhook handling
- guest order tokens and customer ownership checks
- admin/customer sessions, OTPs, password reset, and email verification
- product mode, variants, stock ledgers, returns, and historical order references
- courier authentication, rate selection, shipment creation, tracking, and webhooks
- migrations, setup schema, and deployed configuration

Do not expose server secrets or weaken CSRF, signature verification, ownership checks, rate limits, session validation, or transaction boundaries.

## Repository contracts

- File-based routes may return HTML, redirects, downloads, or JSON; do not assume REST conventions.
- `openapi.yaml` documents externally callable routes, including browser-form behavior.
- `tests/` contains executable PHP contract checks rather than a PHPUnit suite.
- `composer test` is the canonical aggregate test command.
- `tmp/`, runtime uploads, private shipping documents, `vendor/`, and generated artifacts are not source-of-truth files.

## Finish

Run focused validation, the complete test script, PHP lint for changed PHP files, and OpenAPI validation where relevant. Report what could not be exercised locally. Never commit without explicit approval.
