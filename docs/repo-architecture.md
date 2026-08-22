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

- `config/db.php` selects local/production mode, loads server-only config/environment variables, optionally imports the two required identity secrets from a mode-`0600` file outside the application root on shared hosting, validates production settings, and opens the database connection.
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
- `require_admin()` enforces explicit route capabilities in addition to session validity. Viewers are read-only, catalog managers mutate catalog/review/coupon/feed data, operations managers mutate order/payment/return/customer/support/courier/expense data, and super administrators alone manage administrators, settings, sensitive configuration, and audit records. Navigation filtering is convenience only; every mutation is protected server-side.
- Admin login uses `AdminOtpService` to issue, resend, verify, limit, and atomically consume OTPs under database row locks. Production requires both the emailed OTP and an `ADMIN_LOGIN_PASSPHRASE` of at least 16 characters supplied by the server environment or protected shared-hosting runtime secret file; local mode may omit the passphrase and use the log-mail driver. Customer login has concurrency-safe rate limiting and session fingerprint/expiry checks. Every customer-aware storefront request also verifies that the account remains active and that the session `auth_version` matches the customer row. Password resets increment this version to revoke every session; an authenticated password change advances the version while updating only the current session.
- Browser mutations use `verify_csrf()`.
- When WhatsApp Cloud API and its approved utility template are configured, COD checkout requires explicit order-scoped consent only when the final amount selects WhatsApp or call confirmation; low-value auto-confirmed COD orders are not gated. The consent timestamp and copy version are stored with `cod_confirmations`; it never authorizes marketing messages. The template contains Confirm and Cancel quick-reply buttons whose per-order payload is checked against the stored response token and originating customer phone; typed YES/NO replies remain supported.
- Guest order operations use database-backed, expiring, hashed access tokens.
- Razorpay verifies HMAC signatures. Courier and COD webhooks validate configured signature/token modes before processing.
- `includes/init.php` emits CSP, HSTS on HTTPS, frame, MIME, referrer, and permissions headers.
- `.htaccess` and `router.php` block direct access to config, database, includes, plugins, scripts, temporary files, dependencies, and logs.

## Core business flows

### Product and inventory

Products are simple or variable and sell by piece, set, or meter. `ProductAdminService`, `ProductVariantService`, and `InventoryService` coordinate publish readiness, SKU/slug uniqueness, variants, unit-aware stock, media, and availability. Historical variants may be archived instead of deleted when business records reference them. Stock dashboards and alerts count simple-product inventory plus active variant SKUs; unused variable-parent stock is excluded. Alert cooldowns are keyed by product and nullable variant.

Product feeds retain their public XML/JSON locations and base fields. A simple product emits one stable `p-{product_id}` offer. A variable product emits one offer per active variant using `p-{product_id}-v-{variant_id}`, parent/item-group and variant identifiers, SKU, color, size, variant-image fallback, price-override fallback, unit type, and variant stock. Active zero-stock variants remain present as out-of-stock offers for restock consumers; archived variants are omitted.

### Cart and checkout

The cart is session-backed and can persist for authenticated customers. Checkout validates delivery details, coupon state, payment method, inventory, and a short-lived shipping quote token. `shipping-rate.php` calculates manual or courier-filtered rates; delivery estimates are snapshotted on orders.

### Orders and payments

`place-order.php` owns transaction-scoped order creation, inventory reservation, and coupon-capacity reservation. `coupon_usages` is the per-order reservation ledger for customers and guests, while `coupons.used_count` includes reserved and completed usage. Authenticated reuse remains keyed by customer. Guest reuse is keyed by the unique `(coupon_id, guest_identity_hash)` pair, where `guest_identity_hash` is an HMAC-SHA256 of normalized email and canonical phone digits using the immutable, environment-managed `APP_IDENTITY_HASH_KEY`. The key must not be rotated without a rehash migration. Authoritative cancellation, signed payment failure, or expiry deletes that reservation and decrements capacity idempotently; a retry must reacquire capacity with the same identity.

Order-commit, payment-success, confirmation-email, activation-email, COD, courier, and server integration work is recorded in the transactional outbox before the business transaction commits. The request attempts delivery after commit, but delivery failure never rolls the customer back to checkout. `commerce_outbox` provides deterministic event deduplication, claims, stale-claim recovery, one immediate attempt plus five bounded retries, and sanitized errors; `commerce_outbox_deliveries` records each hook callback independently so a successful handler is not rerun when another handler fails. Cron retries after 1, 5, 15, 60, and 240 minutes. Browser callbacks and Razorpay webhooks enqueue the same deterministic payment-success events.

COD can support guests. Online payment proceeds through a claimed, reusable Razorpay order initialization, browser verification/failure callbacks, and the signed webhook. Browser failure or modal cancellation is informational only because a successful capture may arrive later; it leaves the order pending and keeps inventory and coupon reservations. Signed failure webhooks, explicit cancellation, and the 30-minute stale-order expiry perform transactional state changes, inventory restoration, coupon release, and activity logging. Capture records payment success independently of coupon bookkeeping.

### Order access and after-sales

Authenticated customers access their own orders. Guests receive expiring management/activation tokens. Cancellation, return, support, invoice, activation-email, and retry-payment flows retain order ownership or token checks. Returns are refund-only and share one inclusive seven-calendar-day eligibility calculation from `shipments.delivered_at` in UTC. Guest order pages show the existing return, items, status, refund state, administrator message, and reverse-pickup tracking without permitting a duplicate request.

### Courier lifecycle

The shipping plugin authenticates to Bigship server-side, caches reference/auth data, requests rates, creates and places shipments, stores AWB/courier metadata, and synchronizes tracking from cron. Its existing entry point remains the compatibility layer while configuration, lifecycle callbacks, and hook registration live in focused modules; all existing function and hook names remain stable. Credentials and bearer tokens never belong in browser responses. Reverse pickup is capability-gated: creation is claimed atomically and only a registered adapter may execute create/sync/webhook operations. Bigship Unified Outbound declares no verified reverse-pickup capability, so approved Bigship returns clearly remain manual.

### Scheduled operations

`cron/run-plugins.php` is the single scheduler entry point and should run every 10 minutes. A filesystem lock and a MySQL named lock prevent overlap across processes and hosts. `--check` validates readiness without processing records; `--local-smoke` is not a dry run and executes jobs against the configured local database. HTTP callers should use `X-Cron-Token`; the query token is retained only for deployment compatibility and responses are never cached.

Cron callbacks return structured success, skipped, degraded, or failed results. Razorpay expiry and COD expiry are critical and produce a nonzero exit when any record cannot be finalized. Recoverable mail, feed, courier, inventory, RTO, and support errors continue the remaining batch and mark the run degraded. The latest run status, last fully successful time, duration, and sanitized failure summary are persisted in `site_settings` and surfaced on the admin dashboard. Compact sanitized histories are retained for 30 days in `cron_run_history`; bounded cleanup removes at most 500 expired rows per run. The Operations Center exposes cron callbacks, payment attempts, refund and stock ledgers, and super-admin audit records without raw payloads or secrets.

The readiness check validates the priority-remediation migration checksum, required outbox/coupon fields, production secret presence without returning secret values, and aggregate outbox health. Abandoned-cart and back-in-stock mail use atomic claims, recover claims older than 15 minutes, and retry five times with 15, 30, 60, 120, and 240 minute delays. Product feeds are written to verified same-directory temporary files and atomically renamed so readers cannot observe partial files.

COD Guard inbound WhatsApp messages are claimed by provider message ID in `cod_guard_webhook_events`. Processed or ignored IDs cannot change an order twice; active processing claims return a retryable failure instead of being acknowledged as complete, while failed or stale claims can be safely reclaimed without retaining raw webhook payloads. The order sidebar shows the latest matched customer reply and receipt time after removing the internal quick-reply payload/token. Cron deletes at most 5,000 ledger rows older than 90 days per run.

## Persistence

- `database/schema.sql` describes fresh installations.
- `database/setup.php` is CLI-only bootstrap/repair logic for fresh or controlled environments.
- `database/migrations/*.sql` are immutable, ordered production changes.
- `database/migrate.php` records filename and SHA-256 checksum in `schema_migrations` and supports `--only`/baseline modes.
- Important workflows use transactions and ledger/history tables to preserve order and inventory integrity.
- Newsletter runtime code and the fresh-install subscriber table have been retired. The 2026-08-22 migration removes the retired empty table; the historical newsletter migration remains immutable.
- Public form and customer login limits use pre-created tables and row locks/atomic writes. Normal requests perform no DDL or global cleanup; bounded stale public-form cleanup runs from cron.

## Testing

Tests are standalone PHP programs invoked by `composer test`. They are source and invariant contracts so they can run without database credentials or third-party networks. They cover routing, shipping payloads, checkout state, product editing/import/variants, backend integrity, frontend regression markers, and compatibility-safe cleanup boundaries.

`composer test:integration` runs live schema, named-lock, and concurrency tests and therefore requires an authorized disposable MySQL database. It must not be pointed at production. The guest coupon history tool `database/backfill-guest-coupon-identities.php` is dry-run by default; production apply additionally requires `--apply --confirm-production` after operator authorization.

This suite should be supplemented with database integration, browser, Razorpay test-mode, courier sandbox, mail-delivery, cron, and production smoke tests.

## Change map

| Change | Inspect together |
|---|---|
| Route/request contract | endpoint, `.htaccess`, `router.php`, `openapi.yaml`, contract tests |
| Product schema | migration, schema/setup, services, import, admin, storefront, order snapshots |
| Checkout/payment | cart, checkout, shipping rate, place order, payment callbacks/webhook, inventory, coupons |
| Authentication | endpoint, auth helpers, session policy, CSRF, mail templates, rate limits |
| Plugin/integration | plugin loader/hooks, config validation, plugin migration, cron/webhook, tests |
