# Playwright Storefront E2E Design

## Goal

Add a minimal Playwright browser-test harness for high-value storefront behavior without introducing a frontend build system or replacing the existing PHP contract suite.

## Scope

The harness covers homepage rendering, desktop and mobile navigation, catalog loading and filtering, product navigation, variant selection, quantity controls, add-to-cart, cart rendering and quantity updates, checkout navigation, checkout address entry, and unlocking the payment/review sections. A dedicated meter-product scenario covers cut length, number of cuts, visible total meters, and the corresponding cart representation.

The harness stops before submitting the checkout form. It never creates an order, initializes Razorpay, completes a payment, or calls courier mutation APIs.

## Safety Boundary

Browser tests require `E2E_BASE_URL`; there is no default URL. Configuration accepts only plain HTTP URLs whose hostname is `localhost`, `127.0.0.1`, or `::1`. URLs containing credentials, query strings, fragments, or a non-root path are rejected.

Fixture writes require all of the following:

- `APP_MODE=local`
- `E2E_FIXTURE_CONFIRM=1`
- the connected database name ends with `_test` or `_e2e`

The fixture script checks these conditions before beginning a transaction. It seeds only reserved `E2E-` product codes and deterministic slugs. It does not run schema setup, migrations, or broad cleanup. Local setup must point the PHP application and fixture CLI at the same disposable database.

The documented server startup disables courier, analytics, and outbound mail integrations through existing environment settings. Playwright aborts and fails the run if the browser attempts a request to Razorpay, Bigship, Meta, or Google Analytics endpoints.

## Components

### Node Package Metadata

`package.json` contains `@playwright/test`, the application's existing Bootstrap 5.3.3 runtime as a test-only CDN mirror, and scripts for the safety unit test, Chromium installation, fixture seeding, and the E2E suite. `package-lock.json` pins the installed dependency graph. No bundler, transpiler, formatter, or general frontend toolchain is added.

### Playwright Configuration

`playwright.config.js` validates `E2E_BASE_URL`, uses Chromium, records traces and screenshots only on failure, rejects arbitrary sleeps through test design, and defines two projects:

- Desktop Chromium at 1440 by 900 pixels
- Mobile Chromium at 360 by 800 pixels with touch/mobile context

Tests run serially because fixture products are shared and cart/checkout flows are stateful within each isolated browser context.

### Fixture Seeder

`tests/e2e/fixtures.php` bootstraps the existing application in CLI local mode, verifies the database guard, and transactionally upserts:

- `E2E Simple Product`, a sellable piece product
- `E2E Variant Product`, a sellable variable piece product with deterministic colour and size variants
- `E2E Meter Product`, a sellable meter product with 1 m and 2.5 m cut options

The script reuses existing `fabrics`, `fabric_variants`, and category contracts. Tests discover products by visible names and clean slugs rather than relying on auto-increment IDs.

### Browser Specifications

`tests/e2e/storefront.spec.js` contains outcome-focused flows using role, label, and text locators. It fulfills the storefront's Bootstrap 5.3.3 CDN requests from the local test dependency so mobile layout and components remain deterministic without internet access:

1. Desktop smoke verifies the homepage, primary Shop navigation, catalog rendering, filter application, catalog-to-product navigation, variant pressed state, quantity changes, add-to-cart, cart quantity changes, checkout navigation, address completion, and payment/review visibility.
2. Mobile smoke verifies the homepage at 360 px, mobile bottom navigation, opening and applying the catalog filter drawer, and a safe product/cart path using the same semantic controls.
3. Meter flow selects a 2.5 m cut, sets two cuts, asserts the visible 5 m summary, adds the product, and asserts the cart shows the 2 by 2.5 m equals 5 m representation.

Each test receives a fresh browser context and session cart. Assertions use visible application output instead of duplicating PHP quantity or pricing calculations.

### Safety Unit Test

`tests/e2e/safety.test.js` exercises the URL validator directly. It proves that a missing URL, production-like host, HTTPS remote URL, credentials, query, fragment, or non-root path is rejected and that loopback HTTP origins are accepted. The test is written and observed failing before the validator is implemented.

### Documentation

`README.md` documents Node and Chromium installation, disposable database creation/schema setup, guarded fixture requirements, safe local server startup, `E2E_BASE_URL`, `E2E_FIXTURE_CONFIRM`, and `npm run test:e2e`. It explicitly lists Razorpay, real order submission, courier mutations, analytics, and outbound mail as excluded.

## Data and Request Flow

The operator creates an authorized disposable database and runs the existing setup command against it. The PHP server and fixture CLI receive the same local database environment. `npm run test:e2e` first runs the URL safety test, then the guarded fixture seeder, then Playwright against the explicitly configured loopback URL.

Storefront requests use the real PHP router, MySQL reads, CSRF tokens, PHP session cart, AJAX add-to-cart endpoint, cart update endpoint, and shipping quote endpoint. Checkout coverage stops after the shipping quote unlocks payment and review; it does not submit `place-order.php`.

## Failure Handling

Configuration errors fail before a browser opens. Fixture guard failures fail before SQL mutation. Fixture SQL errors roll back the transaction. Uncaught first-party page errors fail the relevant test; blocked provider requests are reported as explicit safety failures rather than silently ignored.

If MySQL, the local PHP server, Chromium, or required PHP extensions are unavailable, verification reports that infrastructure blocker while still running static configuration validation, the Node safety test, Playwright test discovery, and the existing Composer/PHP suite where possible.

## Compatibility

The harness does not change request fields, cart identity, quantity semantics, meter/cut calculations, checkout validation, payment flow, courier logic, or production runtime configuration. Existing Composer tests remain the primary lightweight suite and are not routed through Node.
