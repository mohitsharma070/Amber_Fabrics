-- ============================================================
-- Amber Fabrics - Production Database Schema
-- Engine  : InnoDB | Charset : utf8mb4_unicode_ci
-- Import  : mysql -u <user> -p < database/schema.sql
-- After   : php database/setup.php   (CLI only - seeds admin)
-- ============================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

CREATE DATABASE IF NOT EXISTS fabric_export
    CHARACTER SET utf8mb4
    COLLATE utf8mb4_unicode_ci;
USE fabric_export;

-- Admins
CREATE TABLE IF NOT EXISTS admins (
    id                   INT          AUTO_INCREMENT PRIMARY KEY,
    name                 VARCHAR(255) NOT NULL,
    email                VARCHAR(255) UNIQUE NOT NULL,
    created_at           TIMESTAMP    DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Admin brute-force protection
CREATE TABLE IF NOT EXISTS admin_login_attempts (
    attempt_key   CHAR(64)  PRIMARY KEY,
    attempts      INT       NOT NULL DEFAULT 0,
    blocked_until DATETIME  DEFAULT NULL,
    updated_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS admin_login_otps (
    id                  INT AUTO_INCREMENT PRIMARY KEY,
    admin_id            INT NOT NULL,
    otp_hash            CHAR(64) NOT NULL,
    expires_at          DATETIME NOT NULL,
    attempts            INT NOT NULL DEFAULT 0,
    resend_available_at DATETIME NOT NULL,
    created_ip          VARCHAR(45) DEFAULT NULL,
    created_at          TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at          TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_admin_login_otps_admin_id (admin_id),
    CONSTRAINT fk_admin_login_otps_admin FOREIGN KEY (admin_id) REFERENCES admins(id) ON DELETE CASCADE,
    INDEX idx_admin_login_otps_expires_at (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Fabrics (Products)
CREATE TABLE IF NOT EXISTS fabrics (
    id               INT           AUTO_INCREMENT PRIMARY KEY,
    name             VARCHAR(255)  NOT NULL,
    sku              VARCHAR(100)  UNIQUE DEFAULT NULL,
    category         VARCHAR(100)  DEFAULT NULL,
    unit_type        ENUM('meter','piece','set') NOT NULL DEFAULT 'meter',
    meter_options    VARCHAR(100)  DEFAULT NULL,
    print_style      VARCHAR(100)  DEFAULT NULL,
    material         VARCHAR(255)  DEFAULT NULL,
    gsm              VARCHAR(50)   DEFAULT NULL,
    width            VARCHAR(50)   DEFAULT NULL,
    moq              VARCHAR(100)  DEFAULT NULL,
    lead_time        VARCHAR(100)  DEFAULT NULL,
    dispatch_time    VARCHAR(100)  DEFAULT NULL,
    size             VARCHAR(100)  DEFAULT NULL,
    color            VARCHAR(100)  DEFAULT NULL,
    description      TEXT,
    wash_care        TEXT,
    image            VARCHAR(255)  DEFAULT NULL,
    image2           VARCHAR(255)  DEFAULT NULL,
    image3           VARCHAR(255)  DEFAULT NULL,
    image4           VARCHAR(255)  DEFAULT NULL,
    video            VARCHAR(255)  DEFAULT NULL,
    price            DECIMAL(10,2) DEFAULT 0.00,
    sale_price       DECIMAL(10,2) DEFAULT NULL,
    cost_price       DECIMAL(10,2) DEFAULT 0.00,
    price_inr        DECIMAL(10,2) DEFAULT NULL,
    price_usd        DECIMAL(10,2) DEFAULT NULL,
    stock            DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    stock_meters     DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    min_order_meters DECIMAL(10,2) NOT NULL DEFAULT 1.00,
    qty_step         DECIMAL(10,4) DEFAULT 0.0000,
    is_featured      TINYINT(1)    DEFAULT 0,
    status           ENUM('active','inactive') DEFAULT 'active',
    is_available     TINYINT(1)    DEFAULT 1,
    created_at       TIMESTAMP     DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Product Categories
CREATE TABLE IF NOT EXISTS categories (
    id         INT          AUTO_INCREMENT PRIMARY KEY,
    name       VARCHAR(100) NOT NULL,
    slug       VARCHAR(120) UNIQUE NOT NULL,
    parent_id  INT          DEFAULT NULL,
    image      VARCHAR(255) DEFAULT NULL,
    status     ENUM('active','inactive') DEFAULT 'active',
    created_at TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP    DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (parent_id) REFERENCES categories(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Seed storefront taxonomy with explicit IDs so parent_id references are correct.
-- FK checks are already disabled at the top of this file.
INSERT INTO categories (id, name, slug, parent_id, status) VALUES
-- Fabric by Meter (id = 1)
(1,  'Fabric by Meter',    'fabric-by-meter',    NULL, 'active'),
(2,  'Floral Prints',      'floral-prints',       1,   'active'),
(3,  'Geometrical Prints', 'geometrical-prints',  1,   'active'),
(4,  'Traditional Prints', 'traditional-prints',  1,   'active'),
(5,  'Ajrakh Prints',      'ajrakh-prints',        1,   'active'),
(6,  'Bagru Prints',       'bagru-prints',         1,   'active'),
(7,  'Indigo Prints',      'indigo-prints',        1,   'active'),
(8,  'Cotton Fabric',      'cotton-fabric',        1,   'active'),
(9,  'Dress Material',     'dress-material',       1,   'active'),
-- Home Furnishing (id = 10)
(10, 'Home Furnishing',    'home-furnishing',     NULL, 'active'),
(11, 'Bedsheets',          'bedsheets',            10,  'active'),
(12, 'Table Covers',       'table-covers',         10,  'active'),
(13, 'Towels',             'towels',               10,  'active'),
(14, 'Cushion Covers',     'cushion-covers',       10,  'active'),
(15, 'Curtains',           'curtains',             10,  'active'),
(16, 'Napkins',            'napkins',              10,  'active'),
-- Ready Made (id = 17)
(17, 'Ready Made',         'ready-made',          NULL, 'active'),
(18, 'Kurtis',             'kurtis',               17,  'active'),
(19, 'Dupattas',           'dupattas',             17,  'active'),
(20, 'Sarees',             'sarees',               17,  'active')
ON DUPLICATE KEY UPDATE
    name      = VALUES(name),
    parent_id = VALUES(parent_id),
    status    = VALUES(status);

-- Customers
CREATE TABLE IF NOT EXISTS customers (
    id                  INT          AUTO_INCREMENT PRIMARY KEY,
    name                VARCHAR(255) NOT NULL,
    email               VARCHAR(255) UNIQUE NOT NULL,
    password_hash       VARCHAR(255) NOT NULL,
    phone               VARCHAR(30)  DEFAULT NULL,
    country             VARCHAR(100) DEFAULT NULL,
    is_active           TINYINT(1)   NOT NULL DEFAULT 1,
    email_verified      TINYINT(1)   DEFAULT 0,
    email_verify_token  VARCHAR(64)  DEFAULT NULL,
    email_verify_expires DATETIME    DEFAULT NULL,
    reset_token         VARCHAR(64)  DEFAULT NULL,
    reset_token_expires DATETIME     DEFAULT NULL,
    created_at          TIMESTAMP    DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS fabric_variants (
    id             INT           AUTO_INCREMENT PRIMARY KEY,
    fabric_id      INT           NOT NULL,
    color          VARCHAR(100)  NOT NULL DEFAULT '',
    size           VARCHAR(100)  NOT NULL DEFAULT '',
    sku            VARCHAR(100)  UNIQUE DEFAULT NULL,
    image          VARCHAR(255)  DEFAULT NULL,
    image2         VARCHAR(255)  DEFAULT NULL,
    image3         VARCHAR(255)  DEFAULT NULL,
    image4         VARCHAR(255)  DEFAULT NULL,
    video          VARCHAR(255)  DEFAULT NULL,
    pack_label     VARCHAR(120)  DEFAULT NULL,
    units_per_set  INT           DEFAULT NULL,
    price_override DECIMAL(10,2) DEFAULT NULL,
    stock          DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    stock_meters   DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    is_active      TINYINT(1)    NOT NULL DEFAULT 1,
    sort_order     SMALLINT      NOT NULL DEFAULT 0,
    created_at     TIMESTAMP     DEFAULT CURRENT_TIMESTAMP,
    updated_at     TIMESTAMP     DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_fv_fabric (fabric_id),
    UNIQUE KEY uq_fabric_color_size (fabric_id, color, size)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS customer_addresses (
    id                  INT AUTO_INCREMENT PRIMARY KEY,
    customer_id         INT NOT NULL,
    label               VARCHAR(80) DEFAULT NULL,
    full_name           VARCHAR(255) NOT NULL,
    phone               VARCHAR(30) DEFAULT NULL,
    address_line        TEXT NOT NULL,
    city                VARCHAR(120) NOT NULL,
    state               VARCHAR(120) DEFAULT NULL,
    pincode             VARCHAR(20) DEFAULT NULL,
    country             VARCHAR(120) NOT NULL DEFAULT 'India',
    is_default_shipping TINYINT(1) NOT NULL DEFAULT 0,
    created_at          TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at          TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_customer_addresses_customer (customer_id),
    INDEX idx_customer_addresses_default (customer_id, is_default_shipping),
    CONSTRAINT fk_customer_addresses_customer FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Customer brute-force protection
CREATE TABLE IF NOT EXISTS customer_login_attempts (
    attempt_key   CHAR(64)  PRIMARY KEY,
    attempts      INT       NOT NULL DEFAULT 0,
    blocked_until DATETIME  DEFAULT NULL,
    updated_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS public_form_attempts (
    attempt_key      CHAR(64) PRIMARY KEY,
    scope            VARCHAR(80) NOT NULL,
    ip_address       VARCHAR(45) NOT NULL,
    user_agent_hash  CHAR(16) NOT NULL,
    attempts         INT NOT NULL DEFAULT 0,
    window_started_at DATETIME NOT NULL,
    blocked_until    DATETIME DEFAULT NULL,
    updated_at       TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_public_form_attempts_scope_updated (scope, updated_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Persistent shopping cart (one per customer)
CREATE TABLE IF NOT EXISTS cart (
    id          INT       AUTO_INCREMENT PRIMARY KEY,
    customer_id INT       NOT NULL,
    created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_cart_customer (customer_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Cart line items
CREATE TABLE IF NOT EXISTS cart_items (
    id                 INT           AUTO_INCREMENT PRIMARY KEY,
    cart_id            INT           NOT NULL,
    product_id         INT           DEFAULT NULL,
    cart_key           VARCHAR(255)  DEFAULT NULL,
    selected_size      VARCHAR(100)  DEFAULT NULL,
    quantity           DECIMAL(10,2) NOT NULL DEFAULT 1.00,
    price              DECIMAL(10,2) DEFAULT 0.00,
    created_at         TIMESTAMP     DEFAULT CURRENT_TIMESTAMP,
    -- Legacy columns used by cart_save_to_db / cart_load_from_db
    fabric_id          INT           NOT NULL,
    variant_id         INT           DEFAULT NULL,
    quantity_meters    DECIMAL(10,2) NOT NULL DEFAULT 1.00,
    meter_length       DECIMAL(10,2) DEFAULT NULL,
    UNIQUE KEY uq_cart_key      (cart_id, cart_key),
    INDEX idx_cart_product      (cart_id, product_id),
    INDEX idx_cart_fabric       (cart_id, fabric_id),
    INDEX idx_cart_items_variant (variant_id),
    INDEX idx_cart_items_cart_id (cart_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS wishlist_items (
    id            INT AUTO_INCREMENT PRIMARY KEY,
    customer_id   INT NOT NULL,
    product_id    INT NOT NULL,
    cart_key      VARCHAR(255) NOT NULL,
    selected_size VARCHAR(100) DEFAULT NULL,
    quantity      DECIMAL(10,2) NOT NULL DEFAULT 1.00,
    meter_length  DECIMAL(10,2) DEFAULT NULL,
    created_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_wishlist_customer_key (customer_id, cart_key),
    INDEX idx_wishlist_customer (customer_id),
    INDEX idx_wishlist_product (product_id),
    CONSTRAINT fk_wishlist_customer FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE CASCADE,
    CONSTRAINT fk_wishlist_product FOREIGN KEY (product_id) REFERENCES fabrics(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Abandoned cart reminders
CREATE TABLE IF NOT EXISTS abandoned_cart_reminders (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    customer_id INT NOT NULL,
    customer_email VARCHAR(255) NOT NULL,
    customer_name VARCHAR(255) DEFAULT NULL,
    cart_hash CHAR(64) NOT NULL,
    items_count INT NOT NULL DEFAULT 0,
    subtotal_amount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    cart_summary TEXT,
    status ENUM('active','completed','recovered') NOT NULL DEFAULT 'active',
    emails_sent_count INT NOT NULL DEFAULT 0,
    next_send_at DATETIME DEFAULT NULL,
    last_sent_at DATETIME DEFAULT NULL,
    last_activity_at DATETIME DEFAULT NULL,
    recovered_at DATETIME DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_abandoned_cart_customer (customer_id),
    INDEX idx_abandoned_cart_status_next (status, next_send_at),
    CONSTRAINT fk_abandoned_cart_customer FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Inventory alert logs
CREATE TABLE IF NOT EXISTS inventory_alert_logs (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    product_id INT NOT NULL,
    unit_type ENUM('meter','piece','set') NOT NULL DEFAULT 'piece',
    stock_value DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    sent_at DATETIME NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_inventory_alert_product_sent (product_id, sent_at),
    CONSTRAINT fk_inventory_alert_product FOREIGN KEY (product_id) REFERENCES fabrics(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Shipping / RTO risk scoring
CREATE TABLE IF NOT EXISTS shipping_rto_risks (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    order_id INT NOT NULL,
    risk_score INT NOT NULL DEFAULT 0,
    risk_band ENUM('low','medium','high') NOT NULL DEFAULT 'low',
    reasons_json JSON DEFAULT NULL,
    signals_json JSON DEFAULT NULL,
    assessed_at DATETIME NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_shipping_rto_risks_order (order_id),
    INDEX idx_shipping_rto_risks_band_score (risk_band, risk_score)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Product reviews and ratings
CREATE TABLE IF NOT EXISTS product_reviews (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    product_id INT NOT NULL,
    customer_id INT NOT NULL,
    rating TINYINT NOT NULL,
    review_text TEXT NOT NULL,
    status ENUM('pending','approved','rejected') NOT NULL DEFAULT 'approved',
    reviewed_at DATETIME NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_product_review_customer (product_id, customer_id),
    INDEX idx_product_reviews_status_product (status, product_id),
    CONSTRAINT fk_product_reviews_product FOREIGN KEY (product_id) REFERENCES fabrics(id) ON DELETE CASCADE,
    CONSTRAINT fk_product_reviews_customer FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Coupons
CREATE TABLE IF NOT EXISTS coupons (
    id               INT           AUTO_INCREMENT PRIMARY KEY,
    code             VARCHAR(50)   NOT NULL UNIQUE,
    discount_type    ENUM('flat','percent') NOT NULL DEFAULT 'flat',
    discount_value   DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    min_order_amount DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    max_discount     DECIMAL(10,2) DEFAULT NULL,
    start_date       DATE          DEFAULT NULL,
    end_date         DATE          DEFAULT NULL,
    usage_limit      INT           NOT NULL DEFAULT 0,
    used_count       INT           NOT NULL DEFAULT 0,
    status           ENUM('active','inactive') NOT NULL DEFAULT 'active',
    created_at       TIMESTAMP     DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Orders
CREATE TABLE IF NOT EXISTS orders (
    id              INT           AUTO_INCREMENT PRIMARY KEY,
    order_number    VARCHAR(50)   UNIQUE NOT NULL,
    customer_name   VARCHAR(255)  NOT NULL,
    customer_phone  VARCHAR(30)   DEFAULT NULL,
    customer_email  VARCHAR(255)  DEFAULT NULL,
    address         TEXT,
    city            VARCHAR(120)  DEFAULT NULL,
    state           VARCHAR(120)  DEFAULT NULL,
    pincode         VARCHAR(20)   DEFAULT NULL,
    country         VARCHAR(120)  DEFAULT NULL,
    subtotal        DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    shipping_amount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    discount_amount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    total_amount    DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    payment_method  ENUM('cod','upi','razorpay') NOT NULL DEFAULT 'cod',
    payment_status  ENUM('pending','paid','failed','refunded') DEFAULT 'pending',
    order_status    ENUM('pending','confirmed','packed','shipped','delivered','cancelled','returned','refunded') DEFAULT 'pending',
    order_notes     TEXT,
    coupon_id       INT           DEFAULT NULL,
    coupon_code     VARCHAR(50)   DEFAULT NULL,
    coupon_discount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    shipping_quote_token VARCHAR(64) DEFAULT NULL,
    shipping_source VARCHAR(40)   DEFAULT NULL,
    courier_id      INT           DEFAULT NULL,
    courier_name    VARCHAR(255)  DEFAULT NULL,
    cod_fee         DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    base_shipping   DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    -- Legacy / compatibility columns
    customer_id     INT           DEFAULT NULL,
    payment_id      VARCHAR(255)  DEFAULT NULL,
    currency        ENUM('INR','USD') NOT NULL DEFAULT 'INR',
    shipping_cost   DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    total           DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    shipping_address JSON         DEFAULT NULL,
    notes           TEXT,
    admin_notes     TEXT,
    status          ENUM('pending','confirmed','processing','shipped','delivered','cancelled') DEFAULT 'pending',
    inventory_reserved_at DATETIME DEFAULT NULL,
    inventory_restored_at DATETIME DEFAULT NULL,
    created_at      TIMESTAMP     DEFAULT CURRENT_TIMESTAMP,
    updated_at      TIMESTAMP     DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_orders_customer_id    (customer_id),
    INDEX idx_orders_status         (status),
    INDEX idx_orders_order_status   (order_status),
    INDEX idx_orders_created_at     (created_at),
    INDEX idx_orders_customer_email (customer_email),
    INDEX idx_orders_coupon_id      (coupon_id),
    INDEX idx_orders_coupon_code    (coupon_code),
    INDEX idx_orders_shipping_quote_token (shipping_quote_token)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Order line items
CREATE TABLE IF NOT EXISTS order_items (
    id                   INT           AUTO_INCREMENT PRIMARY KEY,
    order_id             INT           NOT NULL,
    product_id           INT           DEFAULT NULL,
    product_name         VARCHAR(255)  NOT NULL,
    size                 VARCHAR(100)  DEFAULT NULL,
    color                VARCHAR(100)  DEFAULT NULL,
    -- 'set' added to match fabrics.unit_type and place-order.php / cancel-order.php logic
    unit_type            ENUM('meter','piece','set') NOT NULL DEFAULT 'meter',
    quantity             DECIMAL(10,2) NOT NULL DEFAULT 1.00,
    price                DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    total                DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    -- Legacy columns used by admin/orders.php and cancel-order.php
    fabric_id            INT           DEFAULT NULL,
    variant_id           INT           DEFAULT NULL,
    fabric_name_snapshot VARCHAR(255)  DEFAULT NULL,
    fabric_sku_snapshot  VARCHAR(50)   DEFAULT NULL,
    quantity_meters      DECIMAL(10,2) DEFAULT NULL,
    price_per_meter      DECIMAL(10,2) DEFAULT NULL,
    line_total           DECIMAL(12,2) DEFAULT NULL,
    cost_price_snapshot  DECIMAL(12,2) DEFAULT NULL,
    bundle_quantity      INT           DEFAULT NULL,
    meter_length         DECIMAL(10,2) DEFAULT NULL,
    pack_label           VARCHAR(120)  DEFAULT NULL,
    units_per_set        INT           DEFAULT NULL,
    taxable_amount       DECIMAL(12,2) DEFAULT NULL,
    discount_amount      DECIMAL(12,2) DEFAULT NULL,
    gst_rate_snapshot    DECIMAL(6,3)  DEFAULT NULL,
    gst_amount           DECIMAL(12,2) DEFAULT NULL,
    cgst_amount          DECIMAL(12,2) DEFAULT NULL,
    sgst_amount          DECIMAL(12,2) DEFAULT NULL,
    igst_amount          DECIMAL(12,2) DEFAULT NULL,
    tax_type             ENUM('none','cgst_sgst','igst') DEFAULT 'none',
    hsn_code_snapshot    VARCHAR(32)   DEFAULT NULL,
    INDEX idx_order_items_order_id   (order_id),
    INDEX idx_order_items_product_id (product_id),
    INDEX idx_order_items_variant (variant_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Payments
CREATE TABLE IF NOT EXISTS payments (
    id                  INT           AUTO_INCREMENT PRIMARY KEY,
    order_id            INT           NOT NULL,
    payment_method      ENUM('cod','upi','razorpay') NOT NULL,
    payment_status      ENUM('pending','paid','failed','refunded') DEFAULT 'pending',
    transaction_id      VARCHAR(255)  DEFAULT NULL,
    razorpay_order_id   VARCHAR(255)  DEFAULT NULL,
    razorpay_payment_id VARCHAR(255)  DEFAULT NULL,
    razorpay_signature  VARCHAR(255)  DEFAULT NULL,
    amount              DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    created_at          TIMESTAMP     DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_payments_order_method (order_id, payment_method),
    INDEX idx_payments_order_id       (order_id),
    INDEX idx_payments_transaction_id (transaction_id),
    INDEX idx_payments_razorpay_order_id (razorpay_order_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Shipments
CREATE TABLE IF NOT EXISTS shipments (
    id            INT           AUTO_INCREMENT PRIMARY KEY,
    order_id      INT           NOT NULL,
    courier_name  VARCHAR(255)  DEFAULT NULL,
    tracking_id   VARCHAR(255)  DEFAULT NULL,
    tracking_url  VARCHAR(500)  DEFAULT NULL,
    shipping_cost DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    shipped_at    DATETIME      DEFAULT NULL,
    delivered_at  DATETIME      DEFAULT NULL,
    created_at    TIMESTAMP     DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_shipments_order_id  (order_id),
    INDEX idx_shipments_tracking_id   (tracking_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Business Expenses
CREATE TABLE IF NOT EXISTS expenses (
    id           INT           AUTO_INCREMENT PRIMARY KEY,
    type         ENUM('Marketing','Packaging','Shipping','Product Purchase','Website','Other') NOT NULL DEFAULT 'Other',
    amount       DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    expense_date DATE          NOT NULL,
    note         TEXT,
    created_at   TIMESTAMP     DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_expenses_date (expense_date),
    INDEX idx_expenses_type (type)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Inquiries
CREATE TABLE IF NOT EXISTS inquiries (
    id                 INT          AUTO_INCREMENT PRIMARY KEY,
    inquiry_type       ENUM('general','export') DEFAULT 'general',
    name               VARCHAR(255) NOT NULL,
    company_name       VARCHAR(255) DEFAULT NULL,
    email              VARCHAR(255) NOT NULL,
    whatsapp_number    VARCHAR(30)  DEFAULT NULL,
    country            VARCHAR(255) DEFAULT NULL,
    product_interested VARCHAR(255) DEFAULT NULL,
    fabric_type        VARCHAR(255) DEFAULT NULL,
    quantity           VARCHAR(255) DEFAULT NULL,
    meters             VARCHAR(50)  DEFAULT NULL,
    incoterm           VARCHAR(20)  DEFAULT NULL,
    destination        VARCHAR(255) DEFAULT NULL,
    pincode            VARCHAR(20)  DEFAULT NULL,
    timeline           VARCHAR(255) DEFAULT NULL,
    message            TEXT,
    status             ENUM('new','qualified','quoted','won','lost','contacted') DEFAULT 'new',
    internal_note      TEXT,
    created_at         TIMESTAMP    DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Inquiry activity log
CREATE TABLE IF NOT EXISTS inquiry_activity_logs (
    id         INT          AUTO_INCREMENT PRIMARY KEY,
    inquiry_id INT          NOT NULL,
    admin_id   INT          DEFAULT NULL,
    actor_name VARCHAR(255) NOT NULL,
    action     VARCHAR(80)  NOT NULL,
    details    TEXT,
    created_at TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_inquiry_activity_inquiry_id (inquiry_id),
    INDEX idx_inquiry_activity_created_at (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Announcement Dismissals
CREATE TABLE IF NOT EXISTS announcement_dismissals (
    id               INT       AUTO_INCREMENT PRIMARY KEY,
    session_key      CHAR(64)  NOT NULL,
    customer_id      INT       DEFAULT NULL,
    announcement_key CHAR(32)  NOT NULL,
    dismissed_at     TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at       TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_announce_dismissal (session_key, announcement_key),
    INDEX idx_announce_customer_id  (customer_id),
    INDEX idx_announce_updated_at   (updated_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Site Settings (key-value store)
CREATE TABLE IF NOT EXISTS site_settings (
    setting_key   VARCHAR(120) PRIMARY KEY,
    setting_value TEXT,
    updated_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- About Page Media
CREATE TABLE IF NOT EXISTS about_media (
    id           INT          AUTO_INCREMENT PRIMARY KEY,
    media_type   ENUM('image','video') NOT NULL DEFAULT 'image',
    file_name    VARCHAR(255) NOT NULL,
    poster_image VARCHAR(255) DEFAULT NULL,
    alt_text     VARCHAR(255) DEFAULT NULL,
    sort_order   INT          NOT NULL DEFAULT 0,
    is_active    TINYINT(1)   NOT NULL DEFAULT 1,
    created_at   TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
    updated_at   TIMESTAMP    DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_about_media_active_sort (is_active, sort_order, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- Returns
CREATE TABLE IF NOT EXISTS returns (
    id               INT AUTO_INCREMENT PRIMARY KEY,
    return_number    VARCHAR(32) NOT NULL UNIQUE,
    order_id         INT NOT NULL,
    customer_id      INT NOT NULL,
    status           ENUM('requested','approved','rejected','pickup_scheduled','in_transit','received','refund_initiated','refund_completed','cancelled') NOT NULL DEFAULT 'requested',
    reason           VARCHAR(255) NOT NULL,
    customer_note    TEXT DEFAULT NULL,
    image_1          VARCHAR(255) DEFAULT NULL,
    image_2          VARCHAR(255) DEFAULT NULL,
    admin_note       TEXT DEFAULT NULL,
    refund_amount    DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    requested_at     TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    approved_at      DATETIME DEFAULT NULL,
    rejected_at      DATETIME DEFAULT NULL,
    received_at      DATETIME DEFAULT NULL,
    updated_at       TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_returns_order_id (order_id),
    CONSTRAINT fk_returns_order FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE,
    CONSTRAINT fk_returns_customer FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE CASCADE,
    INDEX idx_returns_order_id (order_id),
    INDEX idx_returns_customer_id (customer_id),
    INDEX idx_returns_status (status),
    INDEX idx_returns_requested_at (requested_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Payment webhook idempotency
CREATE TABLE IF NOT EXISTS payment_webhook_events (
    id          BIGINT AUTO_INCREMENT PRIMARY KEY,
    provider    VARCHAR(32)  NOT NULL,
    event_id    VARCHAR(191) NOT NULL,
    signature   VARCHAR(255) DEFAULT NULL,
    received_at TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_payment_webhook_event (provider, event_id),
    INDEX idx_payment_webhook_received_at (received_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Order lifecycle audit trail
CREATE TABLE IF NOT EXISTS order_activity_logs (
    id         BIGINT AUTO_INCREMENT PRIMARY KEY,
    order_id   INT          NOT NULL,
    action     VARCHAR(80)  NOT NULL,
    actor_type ENUM('system','customer','admin','webhook') NOT NULL DEFAULT 'system',
    actor_id   INT          DEFAULT NULL,
    actor_name VARCHAR(255) DEFAULT NULL,
    details    TEXT,
    created_at TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_order_activity_order_id (order_id),
    INDEX idx_order_activity_created_at (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Refund financial ledger
CREATE TABLE IF NOT EXISTS refund_ledger (
    id                BIGINT AUTO_INCREMENT PRIMARY KEY,
    order_id          INT           NOT NULL,
    payment_id        INT           NOT NULL,
    amount            DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    currency          VARCHAR(8)    NOT NULL DEFAULT 'INR',
    status            ENUM('initiated','processed','failed') NOT NULL DEFAULT 'initiated',
    gateway           VARCHAR(32)   DEFAULT NULL,
    gateway_refund_id VARCHAR(191)  DEFAULT NULL,
    notes             TEXT,
    created_at        TIMESTAMP     DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_refund_ledger_order_id (order_id),
    INDEX idx_refund_ledger_payment_id (payment_id),
    INDEX idx_refund_ledger_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS stock_ledger (
    id                BIGINT AUTO_INCREMENT PRIMARY KEY,
    order_id          INT DEFAULT NULL,
    order_item_id     INT DEFAULT NULL,
    return_id         INT DEFAULT NULL,
    return_item_id    INT DEFAULT NULL,
    fabric_id         INT DEFAULT NULL,
    variant_id        INT DEFAULT NULL,
    unit_type         ENUM('meter','piece','set') NOT NULL DEFAULT 'meter',
    quantity          DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    movement          ENUM('reserve','release','return_restock','adjustment') NOT NULL DEFAULT 'adjustment',
    direction         ENUM('in','out') NOT NULL DEFAULT 'in',
    source            VARCHAR(64) DEFAULT NULL,
    notes             TEXT,
    created_at        TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_stock_ledger_order (order_id),
    INDEX idx_stock_ledger_return (return_id),
    INDEX idx_stock_ledger_fabric_variant (fabric_id, variant_id),
    INDEX idx_stock_ledger_created_at (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS payment_attempts (
    id                BIGINT AUTO_INCREMENT PRIMARY KEY,
    order_id          INT DEFAULT NULL,
    payment_id        INT DEFAULT NULL,
    provider          VARCHAR(32)  NOT NULL,
    attempt_ref       VARCHAR(191) NOT NULL,
    status            VARCHAR(40)  NOT NULL DEFAULT 'created',
    source            VARCHAR(40)  NOT NULL DEFAULT 'create',
    gateway_payment_id VARCHAR(191) DEFAULT NULL,
    gateway_signature VARCHAR(255) DEFAULT NULL,
    error_code        VARCHAR(80)  DEFAULT NULL,
    error_message     TEXT,
    webhook_event_id  VARCHAR(191) DEFAULT NULL,
    webhook_signature VARCHAR(255) DEFAULT NULL,
    payload_json      LONGTEXT,
    retry_count       INT NOT NULL DEFAULT 0,
    first_seen_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    last_seen_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    created_at        TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at        TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_payment_attempt_provider_ref (provider, attempt_ref),
    INDEX idx_payment_attempt_order_id (order_id),
    INDEX idx_payment_attempt_payment_id (payment_id),
    INDEX idx_payment_attempt_status (status),
    INDEX idx_payment_attempt_webhook_event (webhook_event_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS shipping_quotes (
    id             BIGINT AUTO_INCREMENT PRIMARY KEY,
    quote_token    CHAR(32) NOT NULL UNIQUE,
    customer_id    INT DEFAULT NULL,
    subtotal       DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    country        VARCHAR(120) NOT NULL,
    pincode        VARCHAR(20) DEFAULT NULL,
    payment_method VARCHAR(32) NOT NULL,
    base_shipping  DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    cod_fee        DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    shipping_total DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    source         VARCHAR(32) NOT NULL DEFAULT 'manual',
    courier_name   VARCHAR(255) DEFAULT NULL,
    courier_id     INT DEFAULT NULL,
    expires_at     DATETIME NOT NULL,
    created_at     TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at     TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_shipping_quotes_customer_expires (customer_id, expires_at),
    INDEX idx_shipping_quotes_expires_at (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET @fk_exists := (
    SELECT COUNT(*)
    FROM information_schema.REFERENTIAL_CONSTRAINTS
    WHERE CONSTRAINT_SCHEMA = DATABASE()
      AND CONSTRAINT_NAME = 'fk_shipping_rto_risks_order'
      AND TABLE_NAME = 'shipping_rto_risks'
);
SET @fk_sql := IF(
    @fk_exists = 0,
    'ALTER TABLE shipping_rto_risks ADD CONSTRAINT fk_shipping_rto_risks_order FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE',
    'SELECT 1'
);
PREPARE stmt_add_fk_shipping_rto_risks_order FROM @fk_sql;
EXECUTE stmt_add_fk_shipping_rto_risks_order;
DEALLOCATE PREPARE stmt_add_fk_shipping_rto_risks_order;

-- COD confirmation gate for high-value cash orders
CREATE TABLE IF NOT EXISTS cod_confirmations (
    id           BIGINT AUTO_INCREMENT PRIMARY KEY,
    order_id     INT NOT NULL,
    channel      ENUM('auto','whatsapp','call') NOT NULL DEFAULT 'auto',
    status       ENUM('pending','confirmed','cancelled','auto_cancelled') NOT NULL DEFAULT 'pending',
    deadline_at  DATETIME DEFAULT NULL,
    attempts     INT NOT NULL DEFAULT 0,
    response_token CHAR(32) DEFAULT NULL,
    message_provider VARCHAR(40) DEFAULT NULL,
    message_id VARCHAR(191) DEFAULT NULL,
    message_status VARCHAR(40) DEFAULT 'queued',
    message_error TEXT,
    message_sent_at DATETIME DEFAULT NULL,
    message_attempts INT NOT NULL DEFAULT 0,
    last_inbound_message_id VARCHAR(191) DEFAULT NULL,
    last_inbound_text TEXT,
    last_inbound_at DATETIME DEFAULT NULL,
    notes        TEXT,
    confirmed_at DATETIME DEFAULT NULL,
    cancelled_at DATETIME DEFAULT NULL,
    created_at   TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at   TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_cod_confirmations_order_id (order_id),
    UNIQUE KEY uq_cod_confirmations_response_token (response_token),
    INDEX idx_cod_confirmations_status_deadline (status, deadline_at),
    INDEX idx_cod_confirmations_message_status (message_status, message_attempts),
    CONSTRAINT fk_cod_confirmations_order FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Marketing campaign attribution captured from UTM/ad click parameters
CREATE TABLE IF NOT EXISTS marketing_attributions (
    id           BIGINT AUTO_INCREMENT PRIMARY KEY,
    order_id     INT NOT NULL,
    customer_id  INT DEFAULT NULL,
    utm_source   VARCHAR(255) DEFAULT NULL,
    utm_medium   VARCHAR(255) DEFAULT NULL,
    utm_campaign VARCHAR(255) DEFAULT NULL,
    utm_term     VARCHAR(255) DEFAULT NULL,
    utm_content  VARCHAR(255) DEFAULT NULL,
    fbclid       VARCHAR(500) DEFAULT NULL,
    gclid        VARCHAR(500) DEFAULT NULL,
    landing_url  VARCHAR(1000) DEFAULT NULL,
    referrer     VARCHAR(1000) DEFAULT NULL,
    created_at   TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_marketing_attributions_order_id (order_id),
    INDEX idx_marketing_attributions_source_campaign (utm_source, utm_campaign),
    INDEX idx_marketing_attributions_customer_id (customer_id),
    CONSTRAINT fk_marketing_attributions_order FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS coupon_usages (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    coupon_id   INT NOT NULL,
    customer_id INT NOT NULL,
    order_id    INT NOT NULL,
    used_at     TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_coupon_usages_coupon_customer (coupon_id, customer_id),
    INDEX idx_coupon_usages_order_id (order_id),
    CONSTRAINT fk_coupon_usages_coupon FOREIGN KEY (coupon_id) REFERENCES coupons(id) ON DELETE CASCADE,
    CONSTRAINT fk_coupon_usages_customer FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE CASCADE,
    CONSTRAINT fk_coupon_usages_order FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS return_items (
    id                INT AUTO_INCREMENT PRIMARY KEY,
    return_id         INT NOT NULL,
    order_item_id     INT DEFAULT NULL,
    fabric_id         INT DEFAULT NULL,
    product_name      VARCHAR(255) NOT NULL,
    unit_type         ENUM('meter','piece','set') NOT NULL DEFAULT 'meter',
    quantity          DECIMAL(10,2) NOT NULL DEFAULT 1.00,
    variant_id        INT DEFAULT NULL,
    restocked_qty     DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    refund_amount     DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    restocked_at      DATETIME DEFAULT NULL,
    line_total        DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    created_at        TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_return_items_return FOREIGN KEY (return_id) REFERENCES returns(id) ON DELETE CASCADE,
    INDEX idx_return_items_return_id (return_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
SET FOREIGN_KEY_CHECKS = 1;

-- Bootstrap admin is created by database/setup.php when no admin exists.
-- Run from project root: php database/setup.php   (CLI only, never via browser)

