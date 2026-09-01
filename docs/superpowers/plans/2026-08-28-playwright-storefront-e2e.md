# Playwright Storefront E2E Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task. Delegation is not authorized for this task.

**Goal:** Add a guarded, deterministic Playwright harness covering the highest-value storefront flows on desktop and mobile.

**Architecture:** A small CommonJS Playwright setup validates an explicit loopback URL, a guarded PHP CLI seeder creates three products in a disposable local database, and browser specs exercise the real PHP router/session/AJAX behavior without submitting an order. Existing Composer tests remain independent.

**Tech Stack:** Node.js, `@playwright/test`, Chromium, PHP 8.2+, MySQL/MariaDB, existing PHP development router.

**Spec:** `docs/superpowers/specs/2026-08-28-playwright-storefront-e2e-design.md`

## Global Constraints

- Do not commit or push.
- Do not add a bundler, transpiler, framework, or unrelated frontend tooling.
- Reject every browser target except an explicitly supplied loopback HTTP origin.
- Fixture writes require `APP_MODE=local`, `E2E_FIXTURE_CONFIRM=1`, and a database ending `_test` or `_e2e`.
- Do not submit checkout, initialize Razorpay, create an order, or trigger courier mutations.
- Preserve all existing cart identity, quantity, variant, meter/cut, checkout, payment, and courier behavior.

---

### Task 1: URL Safety Policy and Minimal Package

**Files:**
- Create: `tests/e2e/safety.test.js`
- Create: `tests/e2e/safety.js`
- Create: `package.json`
- Generate: `package-lock.json`
- Create: `playwright.config.js`

**Interfaces:**
- Consumes: environment variable `E2E_BASE_URL`
- Produces: `requireLocalE2EBaseURL(rawValue)` returning a normalized loopback origin or throwing a generic configuration error

- [ ] **Step 1: Write the failing URL-policy test**

Create a Node built-in test that imports `requireLocalE2EBaseURL`, accepts `http://localhost:8000/`, `http://127.0.0.1:8000/`, and `http://[::1]:8000/`, and rejects missing values, remote hosts, HTTPS, credentials, query strings, fragments, and non-root paths.

- [ ] **Step 2: Run the test and verify RED**

Run: `node --test tests/e2e/safety.test.js`

Expected: failure because `tests/e2e/safety.js` does not exist.

- [ ] **Step 3: Implement the smallest URL validator**

Use the platform `URL` class, an explicit hostname allowlist, an `http:` protocol check, and exact checks for credentials, search, hash, and pathname. Return `url.origin`.

- [ ] **Step 4: Verify GREEN**

Run: `node --test tests/e2e/safety.test.js`

Expected: all URL-policy cases pass.

- [ ] **Step 5: Add Playwright-only package metadata and configuration**

Add scripts:

```json
{
  "test:e2e:preflight": "node --test tests/e2e/safety.test.js && php tests/e2e/fixture_policy_test.php",
  "test:e2e:seed": "php tests/e2e/fixtures.php",
  "test:e2e": "npm run test:e2e:preflight && npm run test:e2e:seed && playwright test",
  "test:e2e:install": "playwright install chromium"
}
```

Install `@playwright/test` plus Bootstrap 5.3.3 as a test-only mirror for the application's existing CDN assets. Configure desktop Chromium at 1440x900 and mobile Chromium at 360x800, `workers: 1`, failure-only traces/screenshots, and `baseURL` from `requireLocalE2EBaseURL(process.env.E2E_BASE_URL)`.

- [ ] **Step 6: Validate configuration discovery**

Run with a loopback value: `npx playwright test --list`

Expected: configuration loads; browser specs may not exist yet.

---

### Task 2: Guarded Deterministic Product Fixtures

**Files:**
- Create: `tests/e2e/fixture-policy.php`
- Create: `tests/e2e/fixture_policy_test.php`
- Create: `tests/e2e/fixtures.php`

**Interfaces:**
- Consumes: application local database configuration, `APP_MODE`, and `E2E_FIXTURE_CONFIRM`
- Produces: `e2e_fixture_policy_errors(string $mode, string $confirmation, string $databaseName): array`
- Produces fixture slugs: `e2e-simple-product`, `e2e-variant-product`, `e2e-meter-product`

- [ ] **Step 1: Write the failing fixture-policy test**

Assert literal error outcomes for production mode, missing confirmation, and ordinary database names. Assert no errors only for local mode plus confirmation and `_test`/`_e2e` database suffixes.

- [ ] **Step 2: Run the test and verify RED**

Run: `php tests/e2e/fixture_policy_test.php`

Expected: failure because `fixture-policy.php` does not exist.

- [ ] **Step 3: Implement the pure fixture policy**

Return generic messages without printing configuration values. Match database suffixes case-insensitively.

- [ ] **Step 4: Verify GREEN**

Run: `php tests/e2e/fixture_policy_test.php`

Expected: fixture-policy cases pass without opening MySQL.

- [ ] **Step 5: Implement the guarded seeder**

Before bootstrap, reject any request lacking explicit local mode or confirmation. After the read-only connection is established, query `SELECT DATABASE()` and re-run the policy with the connected name before beginning a transaction. Transactionally upsert the three reserved products, upsert required categories, and replace only the two variants belonging to `E2E-VARIANT`. Roll back and return nonzero on any error.

- [ ] **Step 6: Verify refusal against the current ordinary/unavailable environment**

Run without fixture confirmation: `php tests/e2e/fixtures.php`

Expected: nonzero refusal before database bootstrap or mutation.

---

### Task 3: Storefront Browser Flows

**Files:**
- Create: `tests/e2e/storefront.spec.js`

**Interfaces:**
- Consumes: the three fixture names/slugs and the loopback `baseURL`
- Produces: desktop smoke, mobile smoke, and meter/cut browser coverage

- [ ] **Step 1: Write desktop, mobile, and meter browser scenarios before changing storefront code**

Use `getByRole`, `getByLabel`, and `getByText`. Desktop covers homepage, Shop navigation, catalog filter submission, variant product opening, colour/size `aria-pressed` state, quantity increment, add-to-cart, cart rendering and increment, checkout navigation, delivery form completion, and visible Payment Method plus Review sections. Mobile covers homepage and bottom-navigation Shop/Cart behavior at 360px. Meter coverage selects 2.5m and two cuts, asserts the visible 5m summary, adds to cart, and asserts the cart's cut representation.

- [ ] **Step 2: Add provider-request detection**

Abort browser requests matching Razorpay, Bigship, Meta/Facebook, or Google Analytics hosts and assert the collection remains empty after each scenario. Fulfill the existing Bootstrap 5.3.3 CDN assets from the local test dependency. Do not mock checkout or cart endpoints.

- [ ] **Step 3: Verify test discovery and JavaScript syntax**

Run:

```text
node --check playwright.config.js
node --check tests/e2e/storefront.spec.js
E2E_BASE_URL=http://127.0.0.1:8000 npx playwright test --list
```

Expected: three scenarios are discovered in their intended projects.

- [ ] **Step 4: Run browser tests when infrastructure is available**

With the disposable database, local PHP server, and Chromium running, execute `npm run test:e2e`. If MySQL remains unavailable, record the blocker and still run preflight, syntax, and discovery checks.

---

### Task 4: Local Setup Documentation

**Files:**
- Modify: `README.md`

**Interfaces:**
- Consumes: the scripts and environment policy from Tasks 1-3
- Produces: reproducible PowerShell and shell-neutral E2E instructions

- [ ] **Step 1: Document prerequisites and disposable setup**

Describe `npm install`, `npm run test:e2e:install`, creating a disposable `_e2e`/`_test` database, running `php database/setup.php` only against that database, setting the existing integration-disable environment values, and starting `php -S 127.0.0.1:8000 router.php`.

- [ ] **Step 2: Document the single test command and exclusions**

Document required `E2E_BASE_URL`, `APP_MODE=local`, `E2E_FIXTURE_CONFIRM=1`, shared DB variables, and `npm run test:e2e`. State that order submission, Razorpay, courier mutations, analytics, and outbound mail are excluded.

- [ ] **Step 3: Review commands against actual scripts**

Run each non-mutating preflight/documented command available in the current environment and correct any mismatch.

---

### Task 5: Verification and Scoped Review

**Files:**
- Review all files created or modified by Tasks 1-4

**Interfaces:**
- Consumes: complete harness and existing PHP suite
- Produces: evidence-backed final report with blockers separated from failures

- [ ] **Step 1: Run focused safety checks**

Run Node URL-policy tests, PHP fixture-policy tests, JavaScript syntax checks, Playwright discovery, and the guarded seeder refusal case.

- [ ] **Step 2: Run the Playwright suite**

Run `npm run test:e2e` only with the approved disposable database and loopback server. Never weaken guards to make the run possible.

- [ ] **Step 3: Run existing PHP verification**

Run `composer test`. If Composer remains unavailable, execute the exact `composer.json` test script and report the substitution.

- [ ] **Step 4: Review the diff**

Run `git diff --check`, inspect the scoped diff, confirm no production business behavior changed, and leave all work uncommitted and unpushed.
