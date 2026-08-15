# AGENTS.md

## Scope

These instructions apply to the entire repository. Make the smallest change that satisfies the request and preserve existing ecommerce behavior unless the user explicitly authorizes a behavior change.

## Architecture essentials

- PHP 8.2+, MySQL/MariaDB, `mysqli`, server-rendered pages, and vanilla JavaScript.
- Requests enter direct `*.php` handlers or clean URLs mapped by `.htaccess`/`router.php`.
- Every normal web request should bootstrap through `includes/init.php`.
- Business operations live primarily in `includes/services/`; cross-cutting extensions use hooks and `plugins/`.
- Authentication is session-cookie based. Browser mutations normally require CSRF. Webhooks use provider signatures or configured shared secrets.

Read `docs/repo-architecture.md` before changing authentication, checkout, payment, inventory, order, courier, migration, or plugin flows.

## Change discipline

- Preserve public request fields, redirects, JSON keys, status codes, session keys, hooks, and database semantics unless the task explicitly changes their contract.
- Do not rewrite working business logic merely to improve style or testability.
- Preserve unrelated staged and unstaged user changes.
- Never commit secrets, secure config, `.env` files, logs, OTPs, uploads, generated feeds, payment data, or database exports.
- Add new schema work as a dated migration. Never modify an applied migration.
- Keep `database/schema.sql` and `database/setup.php` aligned when a migration affects fresh installs.
- Do not run production migrations, setup, external payments, courier mutations, destructive SQL, or history-rewriting Git commands without explicit authorization.

## Endpoint rules

- State-changing browser handlers: enforce the intended HTTP method and `verify_csrf()`.
- Customer-only handlers: use `require_customer()` or the established token flow.
- Admin handlers: use `require_admin()`.
- Webhooks: read the raw body and validate the provider signature before processing.
- JSON handlers: set an explicit JSON content type and retain their established response envelope.
- Redirect-based forms: preserve flash messages and redirect destinations.

When endpoint behavior changes, update `openapi.yaml`, `docs/repo-architecture.md`, and the relevant contract test.

## Verification

Safe local commands include:

```bash
composer test
php tests/<test-file>.php
php -l <changed-php-file>
npx --yes @apidevtools/swagger-cli validate openapi.yaml
```

Prefer source-contract tests when database fixtures are unavailable. Tests must describe current behavior and must not require production credentials, network calls, or writes to production services.

## Completion checklist

1. Review `git diff` and confirm no unrelated files changed.
2. Lint every changed PHP file.
3. Run the focused tests, then the full Composer test script.
4. Validate `composer.json` and `openapi.yaml` when changed.
5. Document untested integration risks explicitly.
6. Do not commit or push unless the user authorizes it.
