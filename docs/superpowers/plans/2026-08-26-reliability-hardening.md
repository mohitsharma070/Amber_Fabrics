# Reliability Hardening Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Keep remote analytics work out of the checkout transaction, make Razorpay webhook processing leases recoverable, and enforce the reliability contracts in CI.

**Architecture:** Keep the current transaction/outbox design. Move the COD Meta CAPI Purchase handler from `order.after_create` to the durable `order.after_commit` path, reconstruct retry-safe customer matching data from the persisted order, and isolate webhook claim leasing in a focused `WebhookLifecycleService`. Every claimed supported webhook that cannot be processed because its Razorpay order ID is absent must persist a failed lifecycle state before returning.

**Tech Stack:** PHP 8.2, MySQL/mysqli, Composer scripts, GitHub Actions.

**Spec:** Findings from the 2026-08-26 senior engineering review of this repository.

## Global Constraints

- Preserve the existing custom PHP architecture; do not introduce a framework rewrite.
- Keep external network I/O outside the checkout database transaction.
- Preserve webhook signature verification and the existing `payment_webhook_events` lifecycle table.
- Add regression contracts before production changes.
- Do not require production secrets for the source-contract CI job.
- Keep the existing migration filename/ledger identity unchanged in this PR; migration release naming needs deployment context and is not part of the payment reliability patch.

---

### Task 1: Regression contracts

**Files:**
- Create: `tests/reliability_release_blockers_contract_test.php`
- Modify: `composer.json`

**Interfaces:**
- Consumes: existing source-contract testing convention.
- Produces: `composer test` assertions for post-commit Meta delivery, durable customer matching data, webhook lease invariants, and malformed-event lifecycle handling.

- [x] **Step 1: Write the failing contract**

Assert the required Meta hook boundary, persisted customer matching fields, lease-safe duplicate claim SQL, endpoint use of the lifecycle service, and failed-state persistence before the missing-order-ID HTTP 400.

- [x] **Step 2: Add the contract to `composer test`**

Append the new test to the existing source-contract chain so CI enforces it.

### Task 2: Move Meta COD Purchase delivery post-commit

**Files:**
- Modify: `plugins/meta-capi/plugin.php`

**Interfaces:**
- Consumes: existing `order.after_commit` outbox hook context and persisted order customer fields.
- Produces: Meta COD Purchase delivery after the transaction commits, with existing outbox retry semantics and retry-safe email/phone/customer ID matching data.

- [x] **Step 1: Change the hook registration**

Register `meta_capi_handle_cod_purchase` on `order.after_commit` instead of `order.after_create`.

- [x] **Step 2: Rebuild matching data from the order**

Load `customer_email`, `customer_phone`, and `customer_id` with the purchase payload and merge them into the user-data context before delivery.

### Task 3: Repair Razorpay webhook lease and malformed-event lifecycle

**Files:**
- Create: `includes/services/WebhookLifecycleService.php`
- Modify: `payment/razorpay-webhook.php`

**Interfaces:**
- Consumes: `payment_webhook_events(provider,event_id)` uniqueness and existing lifecycle statuses.
- Produces: stale `processing` claims that can actually be reclaimed and claimed malformed events that cannot remain stranded in `processing`.

- [x] **Step 1: Preserve the existing lease timestamp on duplicate insert**

The lifecycle service may refresh signature/payload data on duplicate insert but does not mutate `updated_at` before locking the row and calculating staleness. Only a successful new/reclaimed processing claim refreshes `updated_at`.

- [x] **Step 2: Route Razorpay claims through the focused service**

Require `WebhookLifecycleService.php` in the endpoint and call `WebhookLifecycleService::beginProcessing(...)` for the claim.

- [x] **Step 3: Persist malformed claimed events as failed**

Before returning HTTP 400 for a supported signed event with no Razorpay order ID, call `PaymentService::payment_webhook_mark_failed(...)` with a concise diagnostic.

### Task 4: Add verification CI and prepare the PR

**Files:**
- Create: `.github/workflows/ci.yml`

**Interfaces:**
- Consumes: Composer metadata and `composer test`.
- Produces: automatic PHP 8.2 metadata validation, dependency installation, syntax checks, and source-contract verification on pushes and pull requests.

- [x] **Step 1: Add CI**

Use Ubuntu, PHP 8.2 with required extensions, `composer validate --strict --no-check-publish`, Composer install, PHP syntax checks excluding `vendor`, and `composer test`.

- [ ] **Step 2: Verify the branch workflow**

Require a completed successful branch run before opening the pull request.

- [ ] **Step 3: Review the final diff and open PR**

Compare the branch with `main`, record verification evidence and limitations, and open a PR without merging it.