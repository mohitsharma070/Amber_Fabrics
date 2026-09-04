# Amber Fabrics

Custom PHP/MySQL ecommerce application for catalog, cart, checkout, Razorpay payments, customer and guest order management, product administration, and courier integrations.

## Stack

- PHP 8.2+
- MySQL/MariaDB through `mysqli`
- Composer dependencies: PHPMailer, Razorpay SDK, and Picqer barcode generator
- Server-rendered HTML with Bootstrap-compatible CSS and vanilla JavaScript
- File-based PHP endpoints with Apache rewrites and a development-server router

## Repository map

- `admin/` — OTP-protected product, order, customer, return, support, and settings administration
- `customer/` — customer authentication, profile, orders, returns, and support
- `guest/` — token-protected guest order and account-activation workflows
- `includes/` — bootstrap, helpers, hooks, and domain services
- `payment/` — Razorpay browser callbacks and signed webhook
- `plugins/` — feature hooks for courier, analytics, feeds, reviews, notifications, and risk controls
- `database/` — fresh schema, CLI setup, ordered migrations, and migration runner
- `cron/` — scheduled background work
- `tests/` — lightweight behavior and source-contract tests
- `docs/` — architecture and agent-readiness documentation

See [docs/repo-architecture.md](docs/repo-architecture.md) for the detailed request and data-flow map.

## Local setup

### Requirements

- PHP 8.2 or newer with `curl`, `fileinfo`, `json`, `mbstring`, `mysqli`, and `openssl`
- MySQL or MariaDB
- Composer

### Install

```bash
composer install
```

Create an ignored `config/secure-config.local.php` or set equivalent environment variables. The config file may return a flat array:

```php
<?php
return [
    'APP_ENV' => 'local',
    'APP_DEBUG' => '1',
    'APP_URL' => 'http://localhost:8000',
    'DB_HOST' => '127.0.0.1',
    'DB_PORT' => '3306',
    'DB_USER' => 'root',
    'DB_PASSWORD' => '',
    'DB_NAME' => 'fabric_export',
    'MAIL_DRIVER' => 'mail',
    'MAIL_FROM' => 'local@example.test',
    'ADMIN_NOTIFICATION_EMAIL' => 'admin@example.test',
    'APP_IDENTITY_HASH_KEY' => 'local-development-identity-key-32-chars',
    'CRON_RUN_TOKEN' => 'local-only-token',
    'RAZORPAY_KEY_ID' => 'rzp_test_example',
    'RAZORPAY_KEY_SECRET' => 'test-secret',
    'RAZORPAY_WEBHOOK_SECRET' => 'test-webhook-secret',
];
```

Never commit secure config files, environment files, logs, OTPs, API keys, or production exports.

For a fresh local database:

```bash
php database/setup.php
```

For an existing database, apply ordered migrations:

```bash
APP_MODE=local php database/migrate.php
```

Start the local server:

```bash
php -S localhost:8000 router.php
```

Then open `http://localhost:8000/`. The development router mirrors the production clean URLs and blocks private directories.

## Configuration

Runtime configuration precedence is:

1. `secure-config.<mode>.php` from an allowed server-only location
2. `/home/<account>/.app-secrets` for the two required production identity secrets
3. environment variables

CLI defaults to local mode. Production-only CLI commands must explicitly set `APP_MODE=production`. Production validates required database, mail, Razorpay, enabled courier settings, `ADMIN_LOGIN_PASSPHRASE` (minimum 16 characters), and immutable `APP_IDENTITY_HASH_KEY` (minimum 32 characters) at bootstrap. Prefer server environment variables. On shared hosting, these two keys may instead be stored as `NAME=value` lines in `/home/<account>/.app-secrets`, outside the application root with Unix mode `0600`; the bootstrap loads only this allowlist into the current PHP process before validation. `APP_SECRETS_FILE` may select a different outside-root path. Never print the values. See `config/secure-config.production.example.php` for placeholders.

## Tests and validation

Run the full behavior-preserving contract suite:

```bash
composer test
```

Run the live-schema integration test only against an authorized local database configured for CLI local mode:

```bash
composer test:integration
```

PHP syntax check example:

```bash
php -l includes/init.php
```

Validate the API description:

```bash
npx --yes @apidevtools/swagger-cli validate openapi.yaml
```

The default tests are intentionally lightweight and do not replace browser, payment-provider, courier sandbox, or broader database integration testing.

### Local Playwright storefront tests

The Playwright suite uses the real PHP router, a local MySQL database, PHP sessions, CSRF protection, cart endpoints, and the checkout shipping-quote endpoint. It is guarded so it cannot target a remote storefront or seed an ordinary database.

Install the browser-test dependency and Chromium once:

```bash
npm install
npm run test:e2e:install
```

Create a disposable local database whose name ends in `_test` or `_e2e`, such as `amber_fabrics_e2e`. Do not reuse a development or production database. In PowerShell, point the application at that database and disable outbound integrations before running setup:

```powershell
$env:APP_MODE = 'local'
$env:APP_ENV = 'test'
$env:DB_NAME = 'amber_fabrics_e2e'
$env:SHIPPING_COURIER_ENABLED = '0'
$env:GOOGLE_ANALYTICS_ENABLED = '0'
$env:META_PIXEL_ID = ''
$env:META_CAPI_PIXEL_ID = ''
$env:META_CAPI_ACCESS_TOKEN = ''
$env:MAIL_DRIVER = 'log'
php database/setup.php
```

Keep those values in the terminal that starts the local application:

```powershell
php -S 127.0.0.1:8000 router.php
```

In a second terminal, set the same local database variables plus the explicit browser and fixture authorization values, then run:

```powershell
$env:APP_MODE = 'local'
$env:APP_ENV = 'test'
$env:DB_NAME = 'amber_fabrics_e2e'
$env:E2E_BASE_URL = 'http://127.0.0.1:8000'
$env:E2E_FIXTURE_CONFIRM = '1'
$env:SHIPPING_COURIER_ENABLED = '0'
$env:GOOGLE_ANALYTICS_ENABLED = '0'
$env:META_PIXEL_ID = ''
$env:META_CAPI_PIXEL_ID = ''
$env:META_CAPI_ACCESS_TOKEN = ''
$env:MAIL_DRIVER = 'log'
npm run test:e2e
```

`E2E_BASE_URL` has no default and accepts only an HTTP loopback origin. Fixture seeding additionally requires `APP_MODE=local`, `APP_ENV=test`, explicit confirmation, and the `_test` or `_e2e` database suffix.

Run the axe browser baseline separately with the same environment values:

```powershell
npm run test:e2e:a11y
```

It scans the homepage, catalog, selected product states, cart, checkout payment/review, customer login, and mobile filter/navigation drawers. The baseline permits no serious or critical axe violations; it uses the existing local Bootstrap mirror and does not suppress first-party rules.

The suite stops at checkout payment/review. It does not submit an order, initialize or complete Razorpay, create courier shipments, send analytics events, or deliver outbound mail. Browser requests to Razorpay, Bigship, Meta/Facebook, or Google Analytics hosts fail the test.

## Database changes

- Add schema changes as a new dated file under `database/migrations/`.
- Do not edit an already-applied migration.
- Use `database/schema.sql` and `database/setup.php` only to keep fresh installations aligned.
- Run `database/migrate.php --only=<migration-file>` for a targeted production migration.
- Never run `database/setup.php` against production unless that operation is explicitly reviewed.

## Production operations

The secured cron entry point is `cron/run-plugins.php`. Configure one Hostinger CLI job every 10 minutes. CLI is preferred; HTTP execution requires `X-Cron-Token` (the query token remains a compatibility fallback). A normal or `--local-smoke` run processes real records, while `--check` performs read-only readiness validation.

```bash
php cron/run-plugins.php
php cron/run-plugins.php --check
php cron/run-plugins.php --local-smoke
```

The runner uses filesystem and MySQL locks, returns nonzero for payment/COD integrity failures, records degraded noncritical work for the admin dashboard, and retries scheduled notification delivery with bounded backoff.

`2026-08-22-ecommerce-operations-completion.sql` removes the retired, empty newsletter subscriber table and adds per-variant alert history, cron run history, and reverse-pickup claim metadata. Bigship reverse pickup remains a manual operation until a documented and sandbox-tested provider adapter declares the required capability.

`2026-08-23-whatsapp-consent-webhook-idempotency.sql` adds order-scoped transactional WhatsApp consent fields and the unique COD Guard webhook-event ledger. Apply it before enabling WhatsApp Cloud API credentials and an approved utility template. COD checkout requires consent only for amounts routed to WhatsApp/call confirmation; terminal duplicate message IDs are acknowledged without changing an order twice, active claims remain retryable, and the ledger is cleaned in bounded 90-day batches.

`2026-08-24-priority-findings-remediation.sql` adds privacy-safe guest coupon identity enforcement and the transactional outbox/delivery ledger. Deployment order is: configure the two new secrets through the server environment or protected runtime secret file, apply the migration, deploy matching code, dry-run and then explicitly apply the guest coupon backfill, run `php cron/run-plugins.php --check`, and enable the updated cron worker. Do not rotate `APP_IDENTITY_HASH_KEY` without a rehash migration.

Deployment should back up files and database, apply backward-compatible migrations, deploy the matching commit, and smoke-test authentication, catalog, cart, checkout, payment, order, and admin workflows.

## Agentic maintenance

- Repository-wide agent instructions: [AGENTS.md](AGENTS.md)
- Claude-specific working notes: [CLAUDE.md](CLAUDE.md)
- Agentic readiness and evidence: [docs/agentic-ready.md](docs/agentic-ready.md)
- Endpoint contract: [openapi.yaml](openapi.yaml)
