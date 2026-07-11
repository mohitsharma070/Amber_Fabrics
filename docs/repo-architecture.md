# Repository Architecture

## Overview

Amber Fabrics is a PHP + MySQL ecommerce application using file-based endpoints. Request routing is primarily direct to `*.php` files, with shared bootstrap and helpers loaded via includes.

## Runtime Stack

- PHP 8.2+ (see composer.json)
- MySQL/MariaDB via mysqli (`config/db.php`)
- Composer dependencies:
  - phpmailer/phpmailer
  - razorpay/razorpay
  - picqer/php-barcode-generator
- Frontend assets:
  - CSS in `css/style.css`
  - JavaScript in `js/script.js`

## Bootstrapping and Shared Layers

- `includes/init.php`: app bootstrap, security headers/CSP, plugin loading, session/cart bootstrap.
- `includes/functions.php`: common helpers including CSRF/session/utility functions.
- `includes/customer-auth.php`: customer auth guards and rate limiting.
- `includes/services/*`: domain services (cart, inventory, payments, email, settings).

## Endpoint Topology

### Public page endpoints (mostly GET)

- `index.php`, `catalog.php`, `fabric.php`, `cart.php`, `checkout.php`, and content pages (`about.php`, `faq.php`, etc.).

### Public mutation/API-like endpoints (POST-first)

- Cart and wishlist: `add-to-cart.php`, `update-cart.php`, `remove-cart.php`, `move-to-wishlist.php`, `move-to-cart.php`, `remove-wishlist.php`
- Coupon: `apply-coupon.php`, `remove-coupon.php`
- Checkout/order: `shipping-rate.php`, `place-order.php`, `retry-payment.php`
- Misc form/data: `contact.php`, `export-inquiry.php`, `review-rating-submit.php`
- JSON state endpoints: `announcement-dismiss.php`, `cookie-consent.php`

### Customer endpoints

- Auth/profile/orders under `customer/` (login/register/reset/logout/profile/orders/returns/support).

### Admin endpoints

- Admin interface under `admin/` with OTP login and CRUD/order settings workflows.

### Webhooks

- `payment/razorpay-webhook.php`
- `cod-guard-webhook.php`
- `shipping-courier-webhook.php`

These endpoints validate signatures/tokens before payload processing.

## Authentication and Authorization

- Session-cookie based auth for customer/admin.
- Customer guard: `require_customer()` in `includes/customer-auth.php`.
- Admin auth flow: OTP-based login in `admin/login.php` + `admin/verify-otp.php`.
- CSRF verification is used in state-changing browser POST flows.

## Data and Integration Flows

- Cart/session state in PHP session, with optional persistence for logged-in customers.
- Orders/payments managed in DB with payment workflow through Razorpay.
- Plugin hooks extend analytics, courier, COD guard, newsletters, reviews, and related features.

## Testing and Quality

Current repository baseline has lightweight contract-style tests in `tests/`.

- `tests/endpoint_contract_test.php`: validates expected endpoint guards and security checks exist, preserving current behavior contracts.

## Operational Notes

- Production/ops helpers exist under `scripts/`.
- Cron hooks run via `cron/run-plugins.php`.
- Release artifacts may exist under `dist/`; source-of-truth editing should target root files, not generated snapshots.
