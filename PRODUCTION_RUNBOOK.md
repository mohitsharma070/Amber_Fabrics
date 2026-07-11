# Production Runbook

Use this as the release path. Production must not depend on committed secrets.

## 1. Rotate Secrets

Rotate these in their provider dashboards before launch:

- Database password
- SMTP app password
- Razorpay key secret
- Razorpay webhook secret
- Meta/CAPI token
- WhatsApp/COD Guard tokens
- Shiprocket password and webhook secret, if enabled

Never reuse any value that appeared in a local file or git history.

## 1.1 Admin Mailbox MFA (Mandatory)

Admin login uses OTP by email, so mailbox security is part of admin auth.
For every admin mailbox used for OTP:

- Enforce mailbox MFA (TOTP or hardware key), no SMS fallback where possible
- Use a unique strong mailbox password in a password manager
- Disable legacy IMAP/POP protocols if not required
- Enable new-login alerts and review them
- Restrict recovery channels to secured admin-owned devices

Optional app-level second factor:

- Set `ADMIN_LOGIN_PASSPHRASE` as an environment variable in production.
- When set, admin OTP verification also requires this passphrase.

## 2. Configure Runtime

Set production values as server environment variables or in `secure-config.php`
outside the deployed web root. Required production keys:

- `APP_MODE=production`
- `APP_ENV=production`
- `APP_URL=https://yourdomain.com`
- `APP_FORCE_HTTPS=1`
- `DB_HOST`
- `DB_PORT`
- `DB_USER`
- `DB_PASSWORD`
- `DB_NAME`
- `ADMIN_NOTIFICATION_EMAIL`
- `CRON_RUN_TOKEN`
- `MAIL_FROM`
- `SMTP_HOST`, `SMTP_PORT`, `SMTP_PASSWORD` unless `MAIL_DRIVER=mail`
- `RAZORPAY_KEY_ID`
- `RAZORPAY_KEY_SECRET`
- `RAZORPAY_WEBHOOK_SECRET`

Required PHP runtime extensions:

- `curl`
- `fileinfo`
- `json`
- `mbstring`
- `mysqli`
- `openssl`

The app now fails closed in production if required keys are missing or still look
like placeholders. Optional integrations such as Shiprocket, COD Guard WhatsApp,
Meta Pixel, and Meta CAPI should be left blank until they are intentionally
enabled with real provider credentials.

## 3. Deploy

Build and verify a release artifact before upload:

```powershell
C:\xampp\php\php.exe scripts\build-release.php
C:\xampp\php\php.exe scripts\verify-release.php "dist\release-YYYYMMDD-HHMMSS"
```

Use the artifact path printed by `build-release.php`; it may be a directory or a
`.zip` depending on the PHP runtime.

Do not deploy from a raw git checkout.

Expected production layout:

- `/home/<user>/public_html`:
  deployed artifact contents only (runtime app files)
- `/home/<user>/secure/secure-config.php`:
  secrets file outside web root (optional alternative to env vars)
- `/home/<user>/backups`:
  backup output (not web-accessible)

The artifact process removes `.git`, temp/session folders, docs, local config,
composer binary, and dev/test files, reducing blast radius from web-server
misconfiguration. It must include `database/migrate.php` and
`database/migrations/*.sql`, and it must exclude setup-only database files such
as `database/setup.php` and `database/schema.sql`.

Install dependencies on the target or in the artifact:

```powershell
C:\xampp\php\php.exe composer.phar install --no-dev --optimize-autoloader
```

Run migrations from CLI:

```powershell
$env:APP_MODE='production'; C:\xampp\php\php.exe database\migrate.php
```

To apply one migration during an emergency or controlled hotfix:

```powershell
$env:APP_MODE='production'; C:\xampp\php\php.exe database\migrate.php --only=YYYY-MM-DD-target-migration.sql
```

If the database already received older SQL files manually before this runner
existed, baseline the historical files first, then run pending migrations:

```powershell
$env:APP_MODE='production'; C:\xampp\php\php.exe database\migrate.php --baseline-before=YYYY-MM-DD-first-new-migration.sql
$env:APP_MODE='production'; C:\xampp\php\php.exe database\migrate.php
```

For an existing production database, do not remove historical migration files
until the database is verified to match them or `schema_migrations` has been
baselined intentionally.

Verify the production runtime:

```powershell
$env:APP_MODE='production'; C:\xampp\php\php.exe scripts\production-check.php
```

## 4. Cron

Schedule every 5-10 minutes:

```powershell
$env:APP_MODE='production'; C:\xampp\php\php.exe cron\run-plugins.php
```

Cron runtime guarantees:

- Structured per-job logs with start, finish, duration, status, and errors
- Overlap protection using file lock + DB lock
- Nonzero exit code when critical jobs fail (`stale_razorpay_release`)

Recommended crontab (Linux):

```bash
*/5 * * * * APP_MODE=production php /home/<user>/public_html/cron/run-plugins.php >> /var/log/amber-cron.log 2>&1
```

For HTTP cron, use only:

```text
https://yourdomain.com/cron/run-plugins.php?token=<CRON_RUN_TOKEN>
```

## 5. Monitoring And Backup

Before accepting orders:

- Daily database backup with 7-30 day retention
- One restore drill to a separate database
- Alert when `cron_last_run_at` is older than 15 minutes
- Alert on DB connection failures
- Alert on Razorpay signature mismatch
- Alert on payment verification failures
- Alert on webhook handler failures
- Alert when cron command exits nonzero
- Alert when no fresh `cron_finish` log appears within 15 minutes

Quick log checks:

```bash
tail -n 200 /var/log/amber-cron.log
grep '"event":"job_finish"' /var/log/amber-cron.log | tail -n 20
grep '"critical_jobs_failed":' /var/log/amber-cron.log | tail -n 20
```
