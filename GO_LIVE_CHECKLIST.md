# Amber Fabrics Go-Live Checklist

Use this document as the release gate. Do not go live until every required item is checked.

## Release Info

- Release date:
- Release owner:
- Target domain:
- Commit/tag to deploy:

---

## 1) Rotate Razorpay Secrets (Required)

Status: [ ] Not started  [ ] In progress  [ ] Done

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
git filter-repo --invert-paths --path .env --path-glob "tmp_sessions/*"
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

## Final Go/No-Go

- [ ] All 5 sections marked Done (or explicitly Not needed with reason)
- [ ] Release owner approval
- [ ] Business owner approval

Decision: [ ] GO LIVE  [ ] NO-GO

Approver(s):
- Engineering:
- Business:

