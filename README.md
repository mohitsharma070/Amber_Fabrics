# Ecommerce Website (PHP + MySQL)

Local setup guide for running this project safely on **XAMPP + phpMyAdmin**.

## 1) Configuration files and credentials

This project uses:

- `config/app-config.php` (local + production config map)
- `config/db.php` (loads active mode config and creates mysqli connection)

Expected DB variables:

- `DB_HOST`
- `DB_PORT`
- `DB_USER`
- `DB_PASSWORD`
- `DB_NAME`

Email-related variables are also read from `config/app-config.php`:

- `ADMIN_NOTIFICATION_EMAIL`
- `MAIL_FROM`
- `SMTP_HOST`
- `SMTP_PORT`
- `SMTP_PASSWORD`

## 2) How database connection is created

Database connection is centralized in `config/db.php`:

1. Loads `config/app-config.php`.
2. Selects `local` mode for localhost/CLI and `production` otherwise.
   - Override explicitly with `APP_MODE=local` or `APP_MODE=production`.
   - Use `APP_MODE=production` for production CLI cron jobs.
3. Keeps active values in the app config map for runtime access.
4. Creates connection using `new mysqli(...)`.
5. Sets charset to `utf8mb4`.

## 3) Can `schema.sql` be imported?

Yes. `database/schema.sql` is import-ready for phpMyAdmin.

It contains:

- `CREATE DATABASE IF NOT EXISTS fabric_export;`
- `USE fabric_export;`
- all required table `CREATE TABLE` statements

### Notes

- Requires MySQL/MariaDB with support for the `JSON` column type used in `orders.shipping_address`.
- If your hosting DB user cannot create databases, first create `fabric_export` manually in phpMyAdmin, then import the file.

## 4) Missing setup instructions identified

This repository was missing a top-level setup guide. Important steps that were not documented:

- XAMPP placement (`htdocs` path)
- phpMyAdmin schema import
- `config/app-config.php` creation/update from `config/app-config.example.php`
- Composer dependency install
- optional migration/setup script usage
- admin bootstrap credentials behavior

## 5) Local setup steps (XAMPP + phpMyAdmin)

## Prerequisites

- XAMPP installed (Apache + MySQL)
- PHP 8.1+ recommended
- Composer installed

## Step A: Place project in XAMPP

1. Copy project folder to:
   - `C:\xampp\htdocs\ecommerce-website`
2. Start **Apache** and **MySQL** from XAMPP Control Panel.

## Step B: Install PHP dependencies

From project root:

```bash
composer install
```

This installs:

- `phpmailer/phpmailer`
- `razorpay/razorpay`

## Step C: Configure environment

1. Copy `config/app-config.example.php` to `config/app-config.php`.
2. Edit the `local` section values:

```env
DB_HOST=localhost
DB_PORT=3306
DB_USER=root
DB_PASSWORD=
DB_NAME=fabric_export
```

Also set mail and SMTP values in the same `local` section if you want email features working.

For Razorpay test mode, also set:

```env
RAZORPAY_KEY_ID=rzp_test_xxxxxxxxxx
RAZORPAY_KEY_SECRET=xxxxxxxxxx
```

Get these from Razorpay Dashboard in **Test Mode**:

1. Login to Razorpay Dashboard
2. Enable Test Mode toggle
3. Go to `Settings -> API Keys`
4. Generate/get Test Key ID and Test Key Secret
5. Put them in `config/app-config.php` under `local`.

## Step D: Import database schema (phpMyAdmin)

1. Open `http://localhost/phpmyadmin`
2. Go to **Import**
3. Choose file: `database/schema.sql`
4. Click **Go**

This creates DB + tables.

## Step E: Optional setup script

One helper script exists:

- `database/setup.php` (CLI-only table ensure + bootstrap admin when admins table is empty)

Run from project root if needed:

```bash
php database/setup.php
```

## Step F: Open the site

- Frontend: `http://localhost/ecommerce-website/`
- Admin login: `http://localhost/ecommerce-website/admin/login.php`

If bootstrap admin was created by `setup.php`, the admin email is printed in terminal output. Admin login is OTP-only, so confirm mail delivery before going live.

## 6) Safe run checklist

Before first real use:

1. Confirm `config/app-config.php` local DB credentials are correct.
2. Confirm schema import completed without SQL errors.
3. Confirm `vendor/` exists after `composer install`.
4. Confirm Apache rewrite/permissions are normal.
5. Confirm the bootstrap admin email can receive OTP before going live.
6. Confirm `RAZORPAY_KEY_ID` and `RAZORPAY_KEY_SECRET` are set before testing online payments.
7. Set a strong `CRON_RUN_TOKEN` and schedule `cron/run-plugins.php` to run every 5-10 minutes.

## Scheduled cron (required for stale online-payment cleanup)

`cron/run-plugins.php` now performs:
- global stale Razorpay pending-order cancellation
- reserved inventory release for those stale orders
- plugin cron hooks via `do_action('cron.tick', ...)`

Run it via CLI or secured HTTP:

- CLI:
  - Local smoke: `php cron/run-plugins.php --local-smoke`
  - Production Windows CMD/XAMPP:
    `set APP_MODE=production&& C:\xampp\php\php.exe cron\run-plugins.php`
  - Production PowerShell/XAMPP:
    `$env:APP_MODE='production'; C:\xampp\php\php.exe cron\run-plugins.php`
- HTTP:
  - `https://your-domain/cron/run-plugins.php?token=<CRON_RUN_TOKEN>`
  - or send header: `X-CRON-TOKEN: <CRON_RUN_TOKEN>`

For IIS or Windows Task Scheduler, create a job every 5-10 minutes that runs the production CLI command with `APP_MODE=production`, or calls the secured HTTP URL.

## Operational checks (local/prod readiness)

Run these scripts from project root:

- Runtime and operations status:
  - `php scripts/ops-health.php`
- Index coverage audit (catalog/admin hot paths):
  - `php scripts/index-audit.php`
- Production gate (strict, production mode only):
  - `php scripts/production-check.php`

## COD Guard WhatsApp confirmation

For COD orders at or above `COD_GUARD_WHATSAPP_THRESHOLD` (default `1000`), COD Guard creates a pending confirmation and sends a WhatsApp confirmation message after the order is committed. Orders at or above `COD_GUARD_CALL_THRESHOLD` (default `2000`) still receive the message and remain flagged for higher-touch confirmation.

Set these in `config/app-config.php`:

```env
COD_GUARD_WHATSAPP_PHONE_NUMBER_ID=your-meta-phone-number-id
COD_GUARD_WHATSAPP_ACCESS_TOKEN=your-meta-whatsapp-token
COD_GUARD_WHATSAPP_TEMPLATE_NAME=optional-approved-template-name
COD_GUARD_WHATSAPP_TEMPLATE_LANGUAGE=en
COD_GUARD_WHATSAPP_APP_SECRET=your-meta-app-secret
COD_GUARD_WEBHOOK_VERIFY_TOKEN=random-token-for-webhook-setup
COD_GUARD_WEBHOOK_TOKEN=random-token-if-app-secret-is-not-used
```

If `COD_GUARD_WHATSAPP_TEMPLATE_NAME` is set, the approved template should accept body parameters in this order: customer name, order number, amount. If it is empty, COD Guard sends a plain text message.

Webhook URL:

```text
https://your-domain/cod-guard-webhook.php
```

When the customer replies `YES <order number>`, the order moves to `confirmed` and the customer receives a confirmation acknowledgement. When they reply `NO <order number>`, the order is cancelled, reserved stock/coupon usage is released, and the customer receives a cancellation acknowledgement. If the customer only replies `YES` or `NO`, COD Guard will process it only when that WhatsApp number has exactly one pending COD confirmation; otherwise it asks the customer to include the order number.

## Razorpay test flow (local)

1. Add products to cart and go to checkout.
2. Select `Pay Online (Razorpay)`.
3. Place order to open Razorpay checkout.
4. Use Razorpay test payment credentials/methods.
5. After successful payment:
   - order `payment_status` becomes `paid`
   - payment IDs/signature are stored in `payments`
6. If payment fails or is closed:
   - order remains `payment_status = pending`
   - order is not marked paid

## 7) Troubleshooting

- **`Access denied for user`**: fix `DB_USER` / `DB_PASSWORD` in `config/app-config.php`.
- **`Unknown database fabric_export`**: import `database/schema.sql` or create DB manually.
- **`Class not found` (Razorpay/PHPMailer)**: run `composer install`.
- **Blank page / 500**: check XAMPP Apache/PHP error logs.

## 8) Agentic Readiness

This repository now includes lightweight agent-facing documentation and contracts to improve safe automation without changing business logic.

- Architecture overview: `docs/repo-architecture.md`
- Agentic readiness status: `docs/agentic-ready.md`
- Agent instructions for contributors: `AGENTS.md`
- Claude-oriented guidance: `CLAUDE.md`
- OpenAPI baseline: `openapi.yaml`
- Endpoint behavior contract test: `tests/endpoint_contract_test.php`

Run the endpoint contract test:

```bash
php tests/endpoint_contract_test.php
```

Validate OpenAPI syntax:

```bash
npx @apidevtools/swagger-cli validate openapi.yaml
```
