# Hostinger Deployment Guide

Use this together with `PRODUCTION_RUNBOOK.md`.

## 1. Build A Safe Artifact Locally

From project root:

```bash
php scripts/build-release.php
php scripts/verify-release.php dist/release-YYYYMMDD-HHMMSS
```

Only deploy the generated artifact contents. Do not upload your full repo checkout.

## 2. Upload Artifact

Upload extracted `dist/release-...` contents to `public_html/` via hPanel File Manager or FTP.

The artifact process excludes unsafe content such as `.git`, `tmp`, `tmp_sessions`,
`docs`, `composer.phar`, local config files, and dev-only scripts.

## 3. Configure Secrets Safely

Do not store live secrets in committed files.
Do not store secrets in `.htaccess`.

Use environment variables in hosting settings, or place a `secure-config.php`
outside web root and set `APP_CONFIG_FILE` to that absolute path.

Required production keys are listed in `PRODUCTION_RUNBOOK.md`.

## 4. Database Setup

Create DB and import base schema (`database/schema.sql`) from your release management copy.
Then run migrations:

```bash
cd ~/public_html
APP_MODE=production php database/migrate.php
```

## 5. Install Dependencies On Server

```bash
cd ~/public_html
composer install --no-dev --optimize-autoloader --classmap-authoritative
```

Do not deploy `composer.phar` into `public_html`.

## 6. Verify Runtime

```bash
cd ~/public_html
APP_MODE=production php scripts/production-check.php
```

Check exit code:

```bash
echo $?
```

`0` means pass. Nonzero means fail and deployment should stop.

## 7. Cron

Schedule every 5-10 minutes:

```bash
APP_MODE=production php /home/<user>/public_html/cron/run-plugins.php
```

## 8. Webhook Setup

Razorpay webhook URL:

`https://yourdomain.com/payment/razorpay-webhook.php`

Events:
- `payment.captured`
- `payment.failed`
- `order.paid`
