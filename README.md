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
2. environment variables

CLI defaults to local mode. Production-only CLI commands must explicitly set `APP_MODE=production`. Production validates required database, mail, Razorpay, and enabled courier settings at bootstrap.

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

Deployment should back up files and database, apply backward-compatible migrations, deploy the matching commit, and smoke-test authentication, catalog, cart, checkout, payment, order, and admin workflows.

## Agentic maintenance

- Repository-wide agent instructions: [AGENTS.md](AGENTS.md)
- Claude-specific working notes: [CLAUDE.md](CLAUDE.md)
- Agentic readiness and evidence: [docs/agentic-ready.md](docs/agentic-ready.md)
- Endpoint contract: [openapi.yaml](openapi.yaml)
