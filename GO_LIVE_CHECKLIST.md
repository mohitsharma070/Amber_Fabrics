# Go-Live Checklist

Use this document as the release gate. Do not go live until every required item is checked.

## Release Info

- Release date: 2026-07-11
- Release owner: Mohit
- Target domain: (fill your Hostinger production domain)
- Commit/tag to deploy: `67c033d` (branch: `agentic-ready-compliance`)

## Current Audit Snapshot (2026-07-11)

- Endpoint contract test: PASS (`php tests/endpoint_contract_test.php`) -> Checks: 30
- OpenAPI validation: PASS (`npx @apidevtools/swagger-cli validate openapi.yaml`)
- Release artifact build: PASS (`php scripts/build-release.php`) -> `dist/release-20260711-162505`
- Release artifact verify: PASS (`php scripts/verify-release.php dist/release-20260711-162505`)
- Production readiness check: FAIL (`APP_MODE=production php scripts/production-check.php`)
   - Missing/unsafe required production keys: `APP_URL`, `DB_HOST`, `DB_USER`, `DB_PASSWORD`, `DB_NAME`, `ADMIN_NOTIFICATION_EMAIL`, `MAIL_FROM`, `CRON_RUN_TOKEN`, `RAZORPAY_KEY_ID`, `RAZORPAY_KEY_SECRET`, `RAZORPAY_WEBHOOK_SECRET`, `SMTP_HOST`, `SMTP_PASSWORD`

Current technical decision: **NO-GO** until production config is complete and re-validated on Hostinger.

### Local Preflight Evidence (Completed)

- Checkout flow local E2E (add-to-cart -> login -> checkout -> place-order -> razorpay-create page): PASS
- Razorpay provider create call in local run: PASS after local troubleshooting override
- Smoke data cleanup after tests: PASS (no lingering `smoke+%@example.test` customers/orders)

---

## 1) Rotate Razorpay Secrets (Required)

Status: [x] Not started  [ ] In progress  [ ] Done

### Steps

1. Razorpay Dashboard -> `Settings` -> `API Keys` -> regenerate active **Key Secret**.
2. Razorpay Dashboard -> `Settings` -> `Webhooks` -> regenerate **Webhook Secret**.
3. Update production environment variables:
   - `RAZORPAY_KEY_ID`
   - `RAZORPAY_KEY_SECRET`
   - `RAZORPAY_WEBHOOK_SECRET`
4. Restart PHP/app runtime after env update.

### Validation

- [ ] Test mode payment still works on staging.
- [ ] Production webhook endpoint accepts valid signatures.
- [ ] Invalid signature returns HTTP 400.

### Evidence

- Razorpay key rotation timestamp:
- Webhook secret rotation timestamp:
- Tester:

---

## 2) Remove Sensitive Data from Git History (If repo was shared/pushed)

Status: [ ] Not needed (never shared)  [ ] In progress  [ ] Done

### Steps

1. Install `git-filter-repo`.
2. Run from repository root:

```powershell
git filter-repo --invert-paths --path .env --path secure-config.php --path config/app-config.php --path-glob "tmp_sessions/*"
git push --force --all
git push --force --tags
```

3. Inform all collaborators to re-clone.
4. Keep rotated secrets only (never reuse old ones).

### Validation

- [ ] `git log -- .env` shows no historical content.
- [ ] `git log -- tmp_sessions` shows no sensitive session artifacts.

### Evidence

- Performed by:
- Date:
- Remote(s) cleaned:

---

## 3) Staging Checkout + Razorpay Browser UAT

Status: [ ] Not started  [ ] In progress  [ ] Done

### Preconditions

- Staging uses production-like DB schema and app config.
- Staging uses Razorpay **test** keys.

### Test Cases

1. COD success
   - Add to cart -> checkout COD -> place order.
   - Expected: redirect to order success, `orders.payment_method='cod'`, `payments.payment_status='pending'`.

2. Razorpay success
   - Add to cart -> checkout Razorpay -> successful payment.
   - Expected: `orders.payment_status='paid'`, `orders.order_status='confirmed'`, stock decremented once.

3. Razorpay fail/cancel
   - Close/fail Razorpay modal.
   - Expected: order not marked paid.

4. Webhook replay idempotency
   - Replay same capture webhook payload.
   - Expected: response indicates already processed; no second stock decrement.

### Validation SQL (examples)

```sql
SELECT id, order_number, payment_method, payment_status, order_status
FROM orders
ORDER BY id DESC
LIMIT 10;

SELECT id, order_id, payment_method, payment_status, transaction_id, razorpay_order_id, razorpay_payment_id
FROM payments
ORDER BY id DESC
LIMIT 10;
```

### Evidence

- Tested by:
- Date:
- Order IDs used:
- Notes:

---

## 4) Backup, Monitoring, and Rollback Readiness

Status: [ ] Not started  [ ] In progress  [ ] Done

### Backup

- [ ] Daily DB backup job configured.
- [ ] Retention policy configured (7-30 days).
- [ ] One restore drill completed to separate DB.

Example backup command:

```powershell
mysqldump -u root -p fabric_export > C:\backups\fabric_export_%DATE%.sql
```

### Monitoring / Alerts

- [ ] PHP error logging enabled in production.
- [ ] Alerts configured for:
  - `razorpay-webhook failed`
  - `signature mismatch`
  - `DB connection failed`
  - repeated payment verification failures

### Rollback

- [ ] Previous release artifact available.
- [ ] DB snapshot taken before deployment.
- [ ] Rollback runbook documented and tested.

### Evidence

- Backup job ID/location:
- Restore drill date:
- Alerting tool/channel:

---

## 5) Admin Workflow UAT

Status: [ ] Not started  [ ] In progress  [ ] Done

### Scenarios

1. `pending -> confirmed`
2. `confirmed -> shipped` (tracking fields)
3. `shipped -> delivered`
4. Cancel paid order and verify refund queue handling
5. Customer order page reflects each update
6. Status emails send (if SMTP enabled)

### Validation

- [ ] Admin list/detail pages consistent.
- [ ] Customer order history/detail pages consistent.
- [ ] Emails delivered for status updates (or intentionally disabled and documented).

### Evidence

- Tested by:
- Date:
- Order IDs:
- Notes:

---

## 6) Hostinger Production Gate (Required)

Status: [ ] Not started  [x] In progress  [ ] Done

### Steps

1. Deploy only the artifact content from `dist/release-...` to Hostinger `public_html/`.
2. Store production secrets outside web root (example: `/home/<user>/secure/secure-config.php`) and set `APP_CONFIG_FILE` to that absolute path.
3. Confirm production does **not** use local debug/security bypass flags.
   - `RAZORPAY_HTTP_SKIP_TLS_VERIFY` must be unset or `0` in production.
4. Run on Hostinger shell:
   - `APP_MODE=production php scripts/production-check.php`
   - `APP_MODE=production php database/migrate.php`
5. Install dependencies on server:
   - `composer install --no-dev --optimize-autoloader --classmap-authoritative`
6. Configure Hostinger Cron (every 5-10 minutes):
   - `APP_MODE=production php /home/<user>/public_html/cron/run-plugins.php`
7. Configure Razorpay webhook endpoint and events:
   - URL: `https://<your-domain>/payment/razorpay-webhook.php`
   - Events: `payment.captured`, `payment.failed`, `order.paid`

### Hostinger SSH Command Worksheet

Run each command in Hostinger SSH from `~/public_html` and record the result.

1. [ ] Confirm app path
   - Command: `cd ~/public_html && pwd`
   - Expected: `/home/<user>/public_html`
   - Result:

2. [ ] Install production dependencies
   - Command: `cd ~/public_html && composer install --no-dev --optimize-autoloader --classmap-authoritative`
   - Expected: composer completes without errors
   - Result:

3. [ ] Confirm production env variables are present
   - Command: `php -r 'echo "APP_MODE=".(getenv("APP_MODE")?:"<empty>").PHP_EOL; echo "APP_ENV=".(getenv("APP_ENV")?:"<empty>").PHP_EOL; echo "APP_CONFIG_FILE=".(getenv("APP_CONFIG_FILE")?:"<empty>").PHP_EOL;'`
   - Expected: `APP_MODE=production`, `APP_ENV=production`, non-empty `APP_CONFIG_FILE` (or equivalent host env setup)
   - Result:

4. [ ] Confirm TLS skip is OFF in production
   - Command: `php -r 'echo "RAZORPAY_HTTP_SKIP_TLS_VERIFY=".(getenv("RAZORPAY_HTTP_SKIP_TLS_VERIFY")?:"<unset>").PHP_EOL;'`
   - Expected: `<unset>` or `0`
   - Result:

5. [ ] Run production readiness check
   - Command: `cd ~/public_html && APP_MODE=production php scripts/production-check.php; echo $?`
   - Expected: checks pass and exit code `0`
   - Result:

6. [ ] Run DB migrations
   - Command: `cd ~/public_html && APP_MODE=production php database/migrate.php; echo $?`
   - Expected: migrations complete and exit code `0`
   - Result:

7. [ ] Dry-run cron command manually
   - Command: `cd ~/public_html && APP_MODE=production php cron/run-plugins.php; echo $?`
   - Expected: command runs and exits `0`
   - Result:

8. [ ] Configure Hostinger Cron entry
   - Cron command: `APP_MODE=production php /home/<user>/public_html/cron/run-plugins.php`
   - Frequency: every 5-10 minutes
   - Result (job id/schedule):

9. [ ] Validate public webhook endpoint
   - URL: `https://<your-domain>/payment/razorpay-webhook.php`
   - Expected: reachable over HTTPS and signature check enforced (invalid signature rejected)
   - Result:

10. [ ] Production checkout smoke
   - Test A: COD order success
   - Test B: Razorpay success
   - Test C: Razorpay cancel/failure
   - Result (order numbers):

### Validation

- [ ] `scripts/production-check.php` exits `0` on Hostinger.
- [ ] Checkout COD flow passes on production domain.
- [ ] Razorpay success + failure/cancel flows pass on production domain.
- [ ] Webhook signature validation passes for valid payload and fails for invalid signature.
- [ ] Cron logs show successful `cron_finish` events.

### Evidence

- Hostinger deploy time:
- Hostinger shell user/path:
- Production check output summary:
- Migration output summary:
- Composer install output summary:
- Cron job ID + schedule:
- Webhook test evidence:
- Production checkout smoke order IDs (COD/Razorpay):
- Tester:

Local preflight summary:
- Artifact build + verify: PASS
- Endpoint contract test: PASS
- OpenAPI validate: PASS
- Production check in production mode: FAIL (expected until Hostinger production env vars/secrets are set)

---

## Final Go/No-Go

- [ ] All required sections marked Done (or explicitly Not needed with reason)
- [ ] Release owner approval
- [ ] Business owner approval

Decision: [ ] GO LIVE  [ ] NO-GO

Current state (2026-07-11): [ ] GO LIVE  [x] NO-GO
Reason: Required production configuration keys are missing and Hostinger production gate is incomplete.

Approver(s):
- Engineering:
- Business:

