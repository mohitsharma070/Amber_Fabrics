# Amber Fabrics (PHP + MySQL)

Local setup guide for running this project safely on **XAMPP + phpMyAdmin**.

## 1) Configuration files and credentials

This project uses:

- `config/app-config.php` (local + production config map)
- `config/db.php` (loads active mode config and creates mysqli connection)

Expected DB variables:

- `DB_HOST`
- `DB_PORT`
- `DB_USER`
- `DB_PASSWORD`
- `DB_NAME`

Email-related variables are also read from `config/app-config.php`:

- `ADMIN_NOTIFICATION_EMAIL`
- `MAIL_FROM`
- `SMTP_HOST`
- `SMTP_PORT`
- `SMTP_PASSWORD`

## 2) How database connection is created

Database connection is centralized in `config/db.php`:

1. Loads `config/app-config.php`.
2. Selects `local` mode for localhost/CLI and `production` otherwise.
3. Keeps active values in the app config map for runtime access.
4. Creates connection using `new mysqli(...)`.
5. Sets charset to `utf8mb4`.

## 3) Can `schema.sql` be imported?

Yes. `database/schema.sql` is import-ready for phpMyAdmin.

It contains:

- `CREATE DATABASE IF NOT EXISTS fabric_export;`
- `USE fabric_export;`
- all required table `CREATE TABLE` statements

### Notes

- Requires MySQL/MariaDB with support for the `JSON` column type used in `orders.shipping_address`.
- If your hosting DB user cannot create databases, first create `fabric_export` manually in phpMyAdmin, then import the file.

## 4) Missing setup instructions identified

This repository was missing a top-level setup guide. Important steps that were not documented:

- XAMPP placement (`htdocs` path)
- phpMyAdmin schema import
- `config/app-config.php` creation/update from `config/app-config.example.php`
- Composer dependency install
- optional migration/setup script usage
- admin bootstrap credentials behavior

## 5) Local setup steps (XAMPP + phpMyAdmin)

## Prerequisites

- XAMPP installed (Apache + MySQL)
- PHP 8.1+ recommended
- Composer installed

## Step A: Place project in XAMPP

1. Copy project folder to:
   - `C:\xampp\htdocs\Amber Fabrics-Textiles`
2. Start **Apache** and **MySQL** from XAMPP Control Panel.

## Step B: Install PHP dependencies

From project root:

```bash
composer install
```

This installs:

- `phpmailer/phpmailer`
- `razorpay/razorpay`

## Step C: Configure environment

1. Copy `config/app-config.example.php` to `config/app-config.php`.
2. Edit the `local` section values:

```env
DB_HOST=localhost
DB_PORT=3306
DB_USER=root
DB_PASSWORD=
DB_NAME=fabric_export
```

Also set mail and SMTP values in the same `local` section if you want email features working.

For Razorpay test mode, also set:

```env
RAZORPAY_KEY_ID=rzp_test_xxxxxxxxxx
RAZORPAY_KEY_SECRET=xxxxxxxxxx
```

Get these from Razorpay Dashboard in **Test Mode**:

1. Login to Razorpay Dashboard
2. Enable Test Mode toggle
3. Go to `Settings -> API Keys`
4. Generate/get Test Key ID and Test Key Secret
5. Put them in `config/app-config.php` under `local`.

## Step D: Import database schema (phpMyAdmin)

1. Open `http://localhost/phpmyadmin`
2. Go to **Import**
3. Choose file: `database/schema.sql`
4. Click **Go**

This creates DB + tables.

## Step E: Optional setup script

One helper script exists:

- `database/setup.php` (CLI-only table ensure + bootstrap admin when admins table is empty)

Run from project root if needed:

```bash
php database/setup.php
```

## Step F: Open the site

- Frontend: `http://localhost/Amber Fabrics-Textiles/`
- Admin login: `http://localhost/Amber Fabrics-Textiles/admin/login.php`

If bootstrap admin was created by `setup.php`, credentials are printed in terminal output.

## 6) Safe run checklist

Before first real use:

1. Confirm `config/app-config.php` local DB credentials are correct.
2. Confirm schema import completed without SQL errors.
3. Confirm `vendor/` exists after `composer install`.
4. Confirm Apache rewrite/permissions are normal.
5. Rotate any default/bootstrap admin password immediately.
6. Confirm `RAZORPAY_KEY_ID` and `RAZORPAY_KEY_SECRET` are set before testing online payments.

## Razorpay test flow (local)

1. Add products to cart and go to checkout.
2. Select `Pay Online (Razorpay)`.
3. Place order to open Razorpay checkout.
4. Use Razorpay test payment credentials/methods.
5. After successful payment:
   - order `payment_status` becomes `paid`
   - payment IDs/signature are stored in `payments`
6. If payment fails or is closed:
   - order remains `payment_status = pending`
   - order is not marked paid

## 7) Troubleshooting

- **`Access denied for user`**: fix `DB_USER` / `DB_PASSWORD` in `config/app-config.php`.
- **`Unknown database fabric_export`**: import `database/schema.sql` or create DB manually.
- **`Class not found` (Razorpay/PHPMailer)**: run `composer install`.
- **Blank page / 500**: check XAMPP Apache/PHP error logs.
