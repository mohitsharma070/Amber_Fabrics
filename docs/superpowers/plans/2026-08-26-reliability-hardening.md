# Reliability Hardening Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Prevent analytics outages and Razorpay webhook retries from corrupting or stalling otherwise valid order/payment processing, and add CI coverage for these reliability boundaries.

**Architecture:** Keep the current transaction/outbox design. Move the COD Meta CAPI Purchase handler from the pre-commit `order.after_create` hook to the durable post-commit `order.after_commit` hook, and make webhook claiming preserve the previous lease timestamp until after the stale check. Every post-claim malformed webhook exit must persist a terminal/retryable lifecycle state before returning.

**Tech Stack:** PHP 8.2, MySQL/mysqli, Composer scripts, GitHub Actions.

**Spec:** Findings from the 2026-08-26 senior engineering review of this repository.

## Global Constraints

- Preserve the existing custom PHP architecture; do not introduce a framework rewrite.
- Keep external network I/O outside the checkout database transaction.
- Preserve webhook signature verification and the existing `payment_webhook_events` lifecycle table.
- Add regression contracts before changing production code.
- Do not require production secrets for the source-contract CI job.

---

### Task 1: Regression contracts

**Files:**
- Create: `tests/reliability_release_blockers_contract_test.php`
- Modify: `composer.json`

**Interfaces:**
- Consumes: existing source-contract testing convention.
- Produces: `composer test` assertions for post-commit Meta delivery and webhook lifecycle invariants.

- [ ] **Step 1: Write the failing test**

Assert that Meta COD Purchase subscribes to `order.after_commit` rather than `order.after_create`, that the webhook claim upsert does not refresh `updated_at` before the row lock/stale check, and that the missing-order-id exit marks the lifecycle failed.

- [ ] **Step 2: Run test to verify it fails**

Run: `php tests/reliability_release_blockers_contract_test.php`
Expected: FAIL on the current reviewed implementation.

- [ ] **Step 3: Add the contract to `composer test`**

Append the new test to the existing source-contract chain so CI enforces it.

- [ ] **Step 4: Commit**

Commit only the regression test and Composer script change.

### Task 2: Move Meta COD Purchase delivery post-commit

**Files:**
- Modify: `plugins/meta-capi/plugin.php`

**Interfaces:**
- Consumes: existing `order.after_commit` outbox hook context.
- Produces: Meta COD Purchase delivery after the order transaction has committed, with existing outbox retry semantics.

- [ ] **Step 1: Change the hook registration**

Replace `add_action('order.after_create', 'meta_capi_handle_cod_purchase', 30);` with `add_action('order.after_commit', 'meta_capi_handle_cod_purchase', 30);`.

- [ ] **Step 2: Run regression contracts**

Run: `composer test`.
Expected: Meta hook assertion passes; webhook assertions remain failing until Task 3.

- [ ] **Step 3: Commit**

Commit only the Meta hook boundary change.

### Task 3: Repair Razorpay webhook lease and malformed-event lifecycle

**Files:**
- Modify: `includes/services/PaymentService.php`
- Modify: `payment/razorpay-webhook.php`

**Interfaces:**
- Consumes: `payment_webhook_events(provider,event_id)` uniqueness and existing lifecycle statuses.
- Produces: stale `processing` claims that can actually be reclaimed and claimed malformed events that cannot remain stranded in `processing`.

- [ ] **Step 1: Preserve the existing lease timestamp on duplicate insert**

In `payment_webhook_begin_processing`, keep duplicate payload/signature refreshes if desired but remove the pre-select `updated_at = NOW()` mutation. The locked row must expose the timestamp from the previous processing attempt so the TTL calculation is meaningful.

- [ ] **Step 2: Persist malformed claimed events as failed**

Before returning HTTP 400 for a supported signed event with no Razorpay order ID, call `PaymentService::payment_webhook_mark_failed(...)` with a concise diagnostic.

- [ ] **Step 3: Run regression contracts**

Run: `composer test`.
Expected: PASS.

- [ ] **Step 4: Commit**

Commit the webhook lifecycle changes.

### Task 4: Correct release hygiene and CI

**Files:**
- Rename: `database/migrations/2026-08-27-category-default-unit-type.sql` to `database/migrations/2026-08-26-category-default-unit-type.sql`
- Create: `.github/workflows/ci.yml`

**Interfaces:**
- Consumes: migration runner lexicographic ordering and `composer test`.
- Produces: a migration name matching the actual merge/release date and automatic PHP 8.2 source-contract verification on pushes and pull requests.

- [ ] **Step 1: Rename the migration without changing SQL**

Use the current date because the migration has already landed on `main` on 2026-08-26 and the runner does not defer future-dated filenames.

- [ ] **Step 2: Add CI**

Use Ubuntu, PHP 8.2 with required extensions, Composer dependency installation, `composer validate --strict`, PHP syntax checks for tracked PHP files, and `composer test`.

- [ ] **Step 3: Verify workflow result**

Check the branch/PR workflow run and record any environment limitation in the PR if it cannot complete.

- [ ] **Step 4: Commit and open PR**

Open a PR to `main` with the two P0/P1 root causes, regression coverage, and verification evidence.