# Repository architecture

## System shape

Amber Fabrics is a file-routed PHP ecommerce application. Apache rewrites clean browser URLs to PHP handlers; `router.php` mirrors those routes for PHP's built-in development server. There is no framework router or dependency-injection container.

```text
Browser / provider webhook / cron
        |
        v
.htaccess or router.php -> endpoint PHP file
        |
        v
includes/init.php -> config/db.php -> helpers and services -> plugin hooks
        |
        v
MySQL, session state, email, Razorpay, Bigship, generated feeds
```

## Runtime and dependencies

- PHP 8.2+
- MySQL/MariaDB via strict-reporting `mysqli`
- PHPMailer for SMTP/mail delivery
- Razorpay SDK plus local signature and order-state handling
- Picqer barcode generator for operational documents
- Bootstrap-oriented HTML/CSS and vanilla JavaScript

## Bootstrap and shared layers

- `config/db.php` selects local/production mode, loads server-only config/environment variables, validates production settings, and opens the database connection.
- `includes/init.php` loads common functions, customer auth, plugins, security headers/CSP, error policy, cart/wishlist session state, and production fatal logging.
- `includes/functions.php` loads services and narrowly grouped helpers.
- `includes/services/` owns cart, inventory, delivery estimate, email, payment, order access, product admin/import/variants, and site settings operations.
- `includes/hooks.php`, `includes/plugin-loader.php`, and `plugins/` provide additive feature hooks.
- Storefront pages share `includes/header.php` and `includes/footer.php`, which provide the skip link, single `main` landmark, navigation, consent UI, and storefront assets. Admin-only presentation and behavior live in `css/admin.css` and `js/admin.js`; public pages do not load those additions. These first-party assets are served directly without a Node build step, use component-scoped responsive rules, and initialize behavior through guarded, idempotent JavaScript entry points.

## Route groups

| Group | Examples | Typical response | Protection |
|---|---|---|---|
| Public pages | `/`, `/catalog`, `/fabric/{slug}`, `/cart`, `/checkout` | HTML | Public session, active-product filters |
| Public mutations | `add-to-cart.php`, `apply-coupon.php`, `shipping-rate.php`, `place-order.php` | Redirect or JSON | POST and CSRF |
| Customer | `customer/profile.php`, `customer/orders.php`, `customer/request-return.php` | HTML/redirect | Customer session, CSRF for mutations |
| Guest | `guest/order-access.php`, `guest/order.php`, `guest/account-activate.php` | HTML/redirect | Expiring hashed access token, CSRF for mutations |
| Admin | `admin/edit-fabric.php`, `admin/orders.php`, admin JSON handlers | HTML/JSON | Admin OTP session, CSRF for mutations |
| Payment | `payment/razorpay-create.php`, verify/failure/webhook | HTML/redirect/text | Customer ownership + CSRF, or Razorpay signature |
| Webhook | Razorpay, COD Guard, shipping courier | Text/JSON | Provider signature/shared-secret validation |
| Cron | `cron/run-plugins.php` | CLI output/text | CLI production mode or header-preferred cron token |

Clean routes and explicit handler routes coexist. Machine callbacks and mutations intentionally retain explicit `.php` paths.

## Authentication and security boundaries

- PHP sessions carry admin/customer identity, CSRF token, cart, wishlist, flash data, and checkout state.
- `require_admin()` and `require_customer()` enforce protected browser areas.
- Admin login uses email/passphrase gating plus OTP verification; customer login has concurrency-safe rate limiting and session fingerprint/expiry checks. Every customer-aware storefront request also verifies that the account remains active and that the session `auth_version` matches the customer row. Password resets increment this version to revoke every session; an authenticated password change advances the version while updating only the current session.
- Browser mutations use `verify_csrf()`.
- Guest order operations use database-backed, expiring, hashed access tokens.
- Razorpay verifies HMAC signatures. Courier and COD webhooks validate configured signature/token modes before processing.
- `includes/init.php` emits CSP, HSTS on HTTPS, frame, MIME, referrer, and permissions headers.
- `.htaccess` and `router.php` block direct access to config, database, includes, plugins, scripts, temporary files, dependencies, and logs.

## Core business flows

### Product and inventory

Products are simple or variable and sell by piece, set, or meter. `ProductAdminService`, `ProductVariantService`, and `InventoryService` coordinate publish readiness, SKU/slug uniqueness, variants, unit-aware stock, media, and availability. Historical variants may be archived instead of deleted when business records reference them.

### Cart and checkout

The cart is session-backed and can persist for authenticated customers. Checkout validates delivery details, coupon state, payment method, inventory, and a short-lived shipping quote token. `shipping-rate.php` calculates manual or courier-filtered rates; delivery estimates are snapshotted on orders.

### Orders and payments

`place-order.php` owns transaction-scoped order creation, inventory reservation, and coupon-capacity reservation. `coupon_usages` is the per-order reservation ledger for customers and guests, while `coupons.used_count` includes reserved and completed usage. Authoritative cancellation, signed payment failure, or expiry deletes that reservation and decrements capacity idempotently; a retry must reacquire capacity.

COD can support guests. Online payment proceeds through a claimed, reusable Razorpay order initialization, browser verification/failure callbacks, and the signed webhook. Browser failure or modal cancellation is informational only because a successful capture may arrive later; it leaves the order pending and keeps inventory and coupon reservations. Signed failure webhooks, explicit cancellation, and the 30-minute stale-order expiry perform transactional state changes, inventory restoration, coupon release, and activity logging. Capture records payment success independently of coupon bookkeeping.

### Order access and after-sales

Authenticated customers access their own orders. Guests receive expiring management/activation tokens. Cancellation, return, support, invoice, activation-email, and retry-payment flows retain order ownership or token checks.

### Courier lifecycle

The shipping plugin authenticates to Bigship server-side, caches reference/auth data, requests rates, creates and places shipments, stores AWB/courier metadata, and synchronizes tracking from cron. Credentials and bearer tokens never belong in browser responses.

### Scheduled operations

`cron/run-plugins.php` is the single scheduler entry point and should run every 10 minutes. A filesystem lock and a MySQL named lock prevent overlap across processes and hosts. `--check` validates readiness without processing records; `--local-smoke` is not a dry run and executes jobs against the configured local database. HTTP callers should use `X-Cron-Token`; the query token is retained only for deployment compatibility and responses are never cached.

Cron callbacks return structured success, skipped, degraded, or failed results. Razorpay expiry and COD expiry are critical and produce a nonzero exit when any record cannot be finalized. Recoverable mail, feed, courier, inventory, RTO, and support errors continue the remaining batch and mark the run degraded. The latest run status, last fully successful time, duration, and sanitized failure summary are persisted in `site_settings` and surfaced on the admin dashboard.

Abandoned-cart and back-in-stock mail use atomic claims, recover claims older than 15 minutes, and retry five times with 15, 30, 60, 120, and 240 minute delays. Product feeds are written to verified same-directory temporary files and atomically renamed so readers cannot observe partial files.

## Persistence

- `database/schema.sql` describes fresh installations.
- `database/setup.php` is CLI-only bootstrap/repair logic for fresh or controlled environments.
- `database/migrations/*.sql` are immutable, ordered production changes.
- `database/migrate.php` records filename and SHA-256 checksum in `schema_migrations` and supports `--only`/baseline modes.
- Important workflows use transactions and ledger/history tables to preserve order and inventory integrity.
- Public form and customer login limits use pre-created tables and row locks/atomic writes. Normal requests perform no DDL or global cleanup; bounded stale public-form cleanup runs from cron.

## Testing

Tests are standalone PHP programs invoked by `composer test`. They are source and invariant contracts so they can run without database credentials or third-party networks. They cover routing, shipping payloads, checkout state, product editing/import/variants, backend integrity, frontend regression markers, and compatibility-safe cleanup boundaries.

`composer test:integration` runs the live product-schema cleanup and cron named-lock/schema tests and therefore requires an authorized local MySQL database. It must not be pointed at production.

This suite should be supplemented with database integration, browser, Razorpay test-mode, courier sandbox, mail-delivery, cron, and production smoke tests.

## Change map

| Change | Inspect together |
|---|---|
| Route/request contract | endpoint, `.htaccess`, `router.php`, `openapi.yaml`, contract tests |
| Product schema | migration, schema/setup, services, import, admin, storefront, order snapshots |
| Checkout/payment | cart, checkout, shipping rate, place order, payment callbacks/webhook, inventory, coupons |
| Authentication | endpoint, auth helpers, session policy, CSRF, mail templates, rate limits |
| Plugin/integration | plugin loader/hooks, config validation, plugin migration, cron/webhook, tests |
