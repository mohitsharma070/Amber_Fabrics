-- ============================================================
-- Production Database Schema
-- Engine  : InnoDB | Charset : utf8mb4_unicode_ci
-- Import  : mysql -u <user> -p < database/schema.sql
-- After   : php database/setup.php   (CLI only - seeds admin)
-- ============================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

CREATE DATABASE IF NOT EXISTS u541103758_fabric_export
    CHARACTER SET utf8mb4
    COLLATE utf8mb4_unicode_ci;
USE u541103758_fabric_export;

CREATE TABLE IF NOT EXISTS schema_migrations (
    migration VARCHAR(191) PRIMARY KEY,
    checksum CHAR(64) NOT NULL,
    applied_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Admins
CREATE TABLE IF NOT EXISTS admins (
    id                   INT          AUTO_INCREMENT PRIMARY KEY,
    name                 VARCHAR(255) NOT NULL,
    email                VARCHAR(255) UNIQUE NOT NULL,
    role                 ENUM('viewer','catalog_manager','operations_manager','super_admin') NOT NULL DEFAULT 'viewer',
    is_active            TINYINT(1)   NOT NULL DEFAULT 1,
    last_login_at        DATETIME     DEFAULT NULL,
    last_login_ip        VARCHAR(45)  DEFAULT NULL,
    last_login_user_agent VARCHAR(500) DEFAULT NULL,
    created_at           TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_admins_role_active (role, is_active)
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
    product_code     VARCHAR(100)  DEFAULT NULL,
    amazon_asin      VARCHAR(20)   DEFAULT NULL,
    name             VARCHAR(255)  NOT NULL,
    sku              VARCHAR(100)  DEFAULT NULL,
    slug             VARCHAR(191)  NOT NULL,
    category         VARCHAR(100)  DEFAULT NULL,
    product_type     ENUM('simple','variable') NOT NULL DEFAULT 'simple',
    unit_type        ENUM('meter','piece','set') NOT NULL DEFAULT 'meter',
    meter_options    VARCHAR(100)  DEFAULT NULL,
    size             VARCHAR(100)  DEFAULT NULL,
    color            VARCHAR(100)  DEFAULT NULL,
    description      TEXT,
    catalog_data     LONGTEXT      DEFAULT NULL,
    hsn_code         VARCHAR(8)    DEFAULT NULL,
    gst_rate         DECIMAL(5,2)  DEFAULT NULL,
    shipping_weight_kg DECIMAL(10,3) DEFAULT NULL,
    parcel_length_cm DECIMAL(10,2) DEFAULT NULL,
    parcel_width_cm  DECIMAL(10,2) DEFAULT NULL,
    parcel_height_cm DECIMAL(10,2) DEFAULT NULL,
    dispatch_min_days SMALLINT UNSIGNED DEFAULT NULL,
    dispatch_max_days SMALLINT UNSIGNED DEFAULT NULL,
    price            DECIMAL(10,2) DEFAULT 0.00,
    sale_price       DECIMAL(10,2) DEFAULT NULL,
    cost_price       DECIMAL(10,2) DEFAULT 0.00,
    stock            DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    stock_meters     DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    low_stock_threshold_units INT DEFAULT NULL,
    low_stock_threshold_meters DECIMAL(10,2) DEFAULT NULL,
    min_order_meters DECIMAL(10,2) NOT NULL DEFAULT 1.00,
    qty_step         DECIMAL(10,4) DEFAULT 0.0000,
    status           ENUM('draft','active','inactive') NOT NULL DEFAULT 'draft',
    is_available     TINYINT(1)    DEFAULT 1,
    created_at       TIMESTAMP     DEFAULT CURRENT_TIMESTAMP,
    published_at     DATETIME      DEFAULT NULL,
    published_by     INT           DEFAULT NULL,
    updated_at       TIMESTAMP     DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_fabrics_product_code (product_code),
    UNIQUE KEY uq_fabrics_sku (sku),
    UNIQUE KEY uq_fabrics_slug (slug),
    INDEX idx_fabrics_storefront (status, category, is_available, created_at),
    -- Catalog listing: status/category + sort by created_at/id (newest/oldest)
    INDEX idx_fabrics_catalog_created (status, category, created_at, id),
    -- Catalog listing: name sorting for scoped category/status listings
    INDEX idx_fabrics_catalog_name (status, category, name, id),
    -- Catalog keyword search across storefront fields
    INDEX idx_fabrics_created_id (created_at, id),
    INDEX idx_fabrics_search (name, sku)
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
    INDEX idx_fv_fabric_active_sort (fabric_id, is_active, sort_order, id),
    -- Catalog join/filter path: active variants by fabric with color/size filters
    INDEX idx_fv_active_fabric_color_size (is_active, fabric_id, color, size, id),
    -- Catalog price/stock path from variant overrides
    INDEX idx_fv_active_fabric_price_stock (is_active, fabric_id, price_override, stock, stock_meters),
    -- Catalog keyword search on variant attributes
    FULLTEXT KEY ft_fv_catalog_search (color, size, sku, pack_label),
    UNIQUE KEY uq_fabric_color_size (fabric_id, color, size)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS fabric_media (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    fabric_id INT NOT NULL,
    media_type ENUM('image','video') NOT NULL DEFAULT 'image',
    filename VARCHAR(255) NOT NULL,
    alt_text VARCHAR(255) NOT NULL DEFAULT '',
    is_primary TINYINT(1) NOT NULL DEFAULT 0,
    sort_order SMALLINT NOT NULL DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_fabric_media_product_sort (fabric_id, media_type, sort_order),
    CONSTRAINT fk_fabric_media_fabric FOREIGN KEY (fabric_id) REFERENCES fabrics(id) ON DELETE CASCADE
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

CREATE TABLE IF NOT EXISTS back_in_stock_subscriptions (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    product_id INT NOT NULL,
    variant_id INT DEFAULT NULL,
    customer_id INT DEFAULT NULL,
    email VARCHAR(255) NOT NULL,
    status ENUM('pending','processing','sent','cancelled') NOT NULL DEFAULT 'pending',
    unsubscribe_token CHAR(64) NOT NULL,
    requested_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    notified_at DATETIME DEFAULT NULL,
    last_error TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_bis_unsubscribe_token (unsubscribe_token),
    INDEX idx_bis_product_status (product_id, status),
    INDEX idx_bis_variant_status (variant_id, status),
    INDEX idx_bis_customer (customer_id),
    INDEX idx_bis_email (email),
    INDEX idx_bis_status_requested (status, requested_at),
    CONSTRAINT fk_bis_product FOREIGN KEY (product_id) REFERENCES fabrics(id) ON DELETE CASCADE,
    CONSTRAINT fk_bis_variant FOREIGN KEY (variant_id) REFERENCES fabric_variants(id) ON DELETE SET NULL,
    CONSTRAINT fk_bis_customer FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ecommerce_event_logs (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    event_type VARCHAR(64) NOT NULL,
    customer_id INT DEFAULT NULL,
    order_id INT DEFAULT NULL,
    product_id INT DEFAULT NULL,
    unit_type ENUM('meter','piece','set') DEFAULT NULL,
    quantity DECIMAL(10,2) DEFAULT NULL,
    amount DECIMAL(12,2) DEFAULT NULL,
    payload_json JSON DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_event_type_created (event_type, created_at),
    INDEX idx_event_customer (customer_id),
    INDEX idx_event_order (order_id),
    INDEX idx_event_product (product_id)
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
    serviceability_status ENUM('live','estimated','unavailable') NOT NULL DEFAULT 'estimated',
    estimated_dispatch_start DATE DEFAULT NULL,
    estimated_dispatch_end DATE DEFAULT NULL,
    estimated_delivery_start DATE DEFAULT NULL,
    estimated_delivery_end DATE DEFAULT NULL,
    courier_id      INT           DEFAULT NULL,
    courier_name    VARCHAR(255)  DEFAULT NULL,
    cod_fee         DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    base_shipping   DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    account_activation_requested TINYINT(1) NOT NULL DEFAULT 0,
    account_activation_sent_at DATETIME DEFAULT NULL,
    activation_email_status ENUM('pending','processing','sent','failed') NOT NULL DEFAULT 'pending',
    activation_email_claimed_at DATETIME DEFAULT NULL,
    activation_email_claim_token CHAR(32) DEFAULT NULL,
    activation_email_attempts INT UNSIGNED NOT NULL DEFAULT 0,
    activation_email_last_error VARCHAR(500) DEFAULT NULL,
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
    INDEX idx_orders_payment_lifecycle (payment_method, payment_status, order_status, created_at),
    INDEX idx_orders_customer_created (customer_id, created_at),
    INDEX idx_orders_status_created (order_status, created_at),
    INDEX idx_orders_customer_email (customer_email),
    INDEX idx_orders_coupon_id      (coupon_id),
    INDEX idx_orders_coupon_code    (coupon_code),
    INDEX idx_orders_shipping_quote_token (shipping_quote_token),
    INDEX idx_orders_status_payment (order_status, payment_status),
    INDEX idx_orders_activation_email_delivery (account_activation_requested, activation_email_status, activation_email_claimed_at)
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
    INDEX idx_payments_razorpay_order_id (razorpay_order_id),
    INDEX idx_payments_method_status (payment_method, payment_status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Shipments
CREATE TABLE IF NOT EXISTS shipments (
    id            INT           AUTO_INCREMENT PRIMARY KEY,
    order_id      INT           NOT NULL,
    awb_code      VARCHAR(255)  DEFAULT NULL,
    courier_name  VARCHAR(255)  DEFAULT NULL,
    tracking_id   VARCHAR(255)  DEFAULT NULL,
    tracking_url  VARCHAR(500)  DEFAULT NULL,
    shipping_cost DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    shipped_at    DATETIME      DEFAULT NULL,
    delivered_at  DATETIME      DEFAULT NULL,
    created_at    TIMESTAMP     DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_shipments_order_id  (order_id),
    INDEX idx_shipments_awb_code (awb_code),
    INDEX idx_shipments_tracking_id   (tracking_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS shipping_courier_shipments (
    id                   BIGINT AUTO_INCREMENT PRIMARY KEY,
    order_id             INT NOT NULL,
    shipment_id          INT NOT NULL,
    provider             VARCHAR(64) NOT NULL,
    provider_order_id    VARCHAR(191) DEFAULT NULL,
    provider_shipment_id VARCHAR(191) DEFAULT NULL,
    provider_status      VARCHAR(80) DEFAULT NULL,
    label_url            VARCHAR(500) DEFAULT NULL,
    raw_response_json    JSON DEFAULT NULL,
    created_at           TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at           TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_shipping_courier_shipment_provider (shipment_id, provider),
    INDEX idx_shipping_courier_order (order_id),
    INDEX idx_shipping_courier_provider_order (provider, provider_order_id),
    INDEX idx_shipping_courier_provider_shipment (provider, provider_shipment_id),
    INDEX idx_shipping_courier_status (provider, provider_status),
    CONSTRAINT fk_shipping_courier_order FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE,
    CONSTRAINT fk_shipping_courier_shipment FOREIGN KEY (shipment_id) REFERENCES shipments(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS shipping_courier_webhook_events (
    id           BIGINT AUTO_INCREMENT PRIMARY KEY,
    provider     VARCHAR(64) NOT NULL,
    event_id     VARCHAR(191) NOT NULL,
    signature    VARCHAR(255) DEFAULT NULL,
    payload_hash CHAR(64) DEFAULT NULL,
    raw_payload  LONGTEXT DEFAULT NULL,
    status       ENUM('received','processing','processed','failed') NOT NULL DEFAULT 'received',
    attempts     INT NOT NULL DEFAULT 0,
    last_error   TEXT DEFAULT NULL,
    processed_at DATETIME DEFAULT NULL,
    created_at   TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at   TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_shipping_courier_webhook_event (provider, event_id),
    INDEX idx_shipping_courier_webhook_status (provider, status),
    INDEX idx_shipping_courier_webhook_processed (processed_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS shipping_courier_reference_cache (
    id             BIGINT AUTO_INCREMENT PRIMARY KEY,
    provider       VARCHAR(64) NOT NULL,
    reference_type VARCHAR(64) NOT NULL,
    segment_type   VARCHAR(32) NOT NULL DEFAULT '',
    payload_json   JSON NOT NULL,
    fetched_at     DATETIME NOT NULL,
    expires_at     DATETIME NOT NULL,
    created_at     TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at     TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_shipping_courier_reference (provider, reference_type, segment_type),
    INDEX idx_shipping_courier_reference_expiry (expires_at)
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
    customer_id      INT DEFAULT NULL,
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

CREATE TABLE IF NOT EXISTS shipping_courier_reverse_pickups (
    id                   BIGINT AUTO_INCREMENT PRIMARY KEY,
    return_id            INT NOT NULL,
    order_id             INT NOT NULL,
    provider             VARCHAR(64) NOT NULL,
    provider_order_id    VARCHAR(191) DEFAULT NULL,
    provider_pickup_id   VARCHAR(191) DEFAULT NULL,
    provider_status      VARCHAR(80) DEFAULT NULL,
    tracking_id          VARCHAR(255) DEFAULT NULL,
    tracking_url         VARCHAR(500) DEFAULT NULL,
    label_url            VARCHAR(500) DEFAULT NULL,
    raw_response_json    JSON DEFAULT NULL,
    created_at           TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at           TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_shipping_courier_reverse_return_provider (return_id, provider),
    INDEX idx_shipping_courier_reverse_order (order_id),
    INDEX idx_shipping_courier_reverse_provider_order (provider, provider_order_id),
    INDEX idx_shipping_courier_reverse_pickup (provider, provider_pickup_id),
    INDEX idx_shipping_courier_reverse_status (provider, provider_status),
    CONSTRAINT fk_shipping_courier_reverse_return FOREIGN KEY (return_id) REFERENCES returns(id) ON DELETE CASCADE,
    CONSTRAINT fk_shipping_courier_reverse_order FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Payment webhook idempotency
CREATE TABLE IF NOT EXISTS payment_webhook_events (
    id           BIGINT AUTO_INCREMENT PRIMARY KEY,
    provider     VARCHAR(32)  NOT NULL,
    event_id     VARCHAR(191) NOT NULL,
    signature    VARCHAR(255) DEFAULT NULL,
    payload_hash CHAR(64)     DEFAULT NULL,
    raw_payload  LONGTEXT     DEFAULT NULL,
    status       ENUM('received','processing','processed','failed') NOT NULL DEFAULT 'received',
    attempts     INT          NOT NULL DEFAULT 0,
    last_error   TEXT         DEFAULT NULL,
    processed_at DATETIME     DEFAULT NULL,
    created_at   TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
    updated_at   TIMESTAMP    DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_payment_webhook_event (provider, event_id),
    INDEX idx_payment_webhook_status (provider, status),
    INDEX idx_payment_webhook_processed_at (processed_at),
    INDEX idx_payment_webhook_created_at (created_at)
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

-- Admin activity audit log
CREATE TABLE IF NOT EXISTS admin_activity_logs (
    id          BIGINT AUTO_INCREMENT PRIMARY KEY,
    admin_id    INT NOT NULL,
    action      VARCHAR(120) NOT NULL,
    target_type VARCHAR(80) DEFAULT NULL,
    target_id   INT DEFAULT NULL,
    route       VARCHAR(255) DEFAULT NULL,
    request_ip  VARCHAR(45) DEFAULT NULL,
    user_agent  VARCHAR(500) DEFAULT NULL,
    status      ENUM('ok','failed','denied') NOT NULL DEFAULT 'ok',
    details     TEXT DEFAULT NULL,
    created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_admin_activity_admin_created (admin_id, created_at),
    INDEX idx_admin_activity_action_created (action, created_at),
    INDEX idx_admin_activity_status_created (status, created_at),
    CONSTRAINT fk_admin_activity_admin FOREIGN KEY (admin_id) REFERENCES admins(id) ON DELETE CASCADE
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
    serviceability_status ENUM('live','estimated','unavailable') NOT NULL DEFAULT 'estimated',
    estimated_dispatch_start DATE DEFAULT NULL,
    estimated_dispatch_end DATE DEFAULT NULL,
    estimated_delivery_start DATE DEFAULT NULL,
    estimated_delivery_end DATE DEFAULT NULL,
    expires_at     DATETIME NOT NULL,
    created_at     TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at     TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_shipping_quotes_customer_expires (customer_id, expires_at),
    INDEX idx_shipping_quotes_expires_at (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS guest_order_access_tokens (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    order_id INT NOT NULL,
    token_hash CHAR(64) NOT NULL,
    purpose ENUM('manage','activate') NOT NULL DEFAULT 'manage',
    expires_at DATETIME NOT NULL,
    consumed_at DATETIME DEFAULT NULL,
    created_ip VARCHAR(45) DEFAULT NULL,
    created_user_agent VARCHAR(255) DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_guest_order_access_token_hash (token_hash),
    INDEX idx_guest_order_access_order_purpose (order_id, purpose, expires_at),
    INDEX idx_guest_order_access_expiry (expires_at),
    CONSTRAINT fk_guest_order_access_order FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE
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
CREATE TABLE IF NOT EXISTS newsletter_subscribers (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    customer_id INT DEFAULT NULL,
    email VARCHAR(255) NOT NULL,
    email_normalized VARCHAR(255) GENERATED ALWAYS AS (LOWER(TRIM(email))) STORED,
    name VARCHAR(191) DEFAULT NULL,
    status ENUM('pending','subscribed','unsubscribed','bounced') NOT NULL DEFAULT 'pending',
    source VARCHAR(80) NOT NULL DEFAULT 'footer',
    consent_ip VARCHAR(45) DEFAULT NULL,
    consent_user_agent VARCHAR(255) DEFAULT NULL,
    confirmed_at DATETIME DEFAULT NULL,
    unsubscribed_at DATETIME DEFAULT NULL,
    unsubscribe_token CHAR(64) NOT NULL,
    verify_token CHAR(64) DEFAULT NULL,
    subscribed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    last_sent_at DATETIME DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_newsletter_email_normalized (email_normalized),
    UNIQUE KEY uq_newsletter_unsubscribe_token (unsubscribe_token),
    UNIQUE KEY uq_newsletter_verify_token (verify_token),
    INDEX idx_newsletter_customer (customer_id),
    INDEX idx_newsletter_status (status, created_at),
    CONSTRAINT fk_newsletter_customer FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS support_tickets (
    id               BIGINT AUTO_INCREMENT PRIMARY KEY,
    ticket_number    VARCHAR(32) NOT NULL,
    customer_id      INT DEFAULT NULL,
    requester_name   VARCHAR(255) DEFAULT NULL,
    requester_email  VARCHAR(255) DEFAULT NULL,
    order_id         INT DEFAULT NULL,
    subject          VARCHAR(160) NOT NULL,
    category         ENUM('order','shipping','payment','product','account','other') NOT NULL DEFAULT 'other',
    priority         ENUM('low','normal','high','urgent') NOT NULL DEFAULT 'normal',
    status           ENUM('open','waiting_customer','waiting_admin','resolved','closed') NOT NULL DEFAULT 'open',
    last_message_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    closed_at        DATETIME DEFAULT NULL,
    created_at       TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at       TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_support_tickets_number (ticket_number),
    INDEX idx_support_tickets_customer_status (customer_id, status, last_message_at),
    INDEX idx_support_tickets_order (order_id),
    INDEX idx_support_tickets_admin_queue (status, priority, last_message_at),
    CONSTRAINT fk_support_tickets_customer FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE CASCADE,
    CONSTRAINT fk_support_tickets_order FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS support_ticket_messages (
    id              BIGINT AUTO_INCREMENT PRIMARY KEY,
    ticket_id       BIGINT NOT NULL,
    sender_type     ENUM('customer','admin','system') NOT NULL,
    sender_id       INT DEFAULT NULL,
    sender_name     VARCHAR(255) DEFAULT NULL,
    message         TEXT NOT NULL,
    is_internal     TINYINT(1) NOT NULL DEFAULT 0,
    attachment_path VARCHAR(500) DEFAULT NULL,
    created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_support_ticket_messages_ticket_created (ticket_id, created_at),
    CONSTRAINT fk_support_ticket_messages_ticket FOREIGN KEY (ticket_id) REFERENCES support_tickets(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;

-- Pre-baseline all migrations so migrate.php skips them on fresh installs.
INSERT IGNORE INTO schema_migrations (migration, checksum) VALUES
('2026-05-23-p2-catalog-admin-indexes.sql',    '95b33d6c95ab616bb99ade5adf9359e62d9daf971f523b679c7784fd6c9d461a'),
('2026-06-20-shipping-courier-plugin.sql',      '727d08882652ff03c15b5b5fc7a184b1a820bdbc4723d195ede71b5e63d57f58'),
('2026-06-21-shipping-courier-reference-cache.sql', '1463cd07894b1f311a48b306322bf445404e6bea0e74d005862f26865221f015'),
('2026-06-27-back-in-stock-subscriptions.sql',  '83ffb78e4982e128f305a5fde6478162292599d470e27d948069e1df9c1bfdb2'),
('2026-06-27-newsletter-plugin.sql',            '04660f73a831007aaee4f8049161e14c3209a247b924a2d33151f4706e8b05be'),
('2026-06-27-support-tickets-plugin.sql',       '12ed1674e131c72f45e111cf2f9c129c1ac476299fd6d0464892a5606c244560'),
('2026-08-12-conversion-mvp.sql',               '0d17116ec5de063c8d88d7e36661378cf031178e593cc3a006a8dc0a908da58e'),
('2026-08-13-conversion-mvp-backend-fixes.sql', 'bd3dcfb2b95f7f7a551a7fe154b9ac023eae03511f330f529d93b6b4b83e1491'),
('2026-08-14-admin-product-editor-v2.sql',      '902a784ec97339b6a125f157d81738ee12404e5e650638c822cbb13fe874acc5'),
('2026-08-15-catalog-product-fields.sql',       '351ed8d1883dbb31adb7dcf0c921b01d6c88f8528f7f07e26e231e2ba232e625'),
('2026-08-16-remove-obsolete-product-fields.sql','c2ca92e4f7fea6433df41578dfc79124da7cf7333403871cd7e5970edc25a175'),
('2026-08-17-remove-legacy-placeholder-variants.sql','1c036a6635e64ffe2f191616630bfb08c9ed5389d7a556e20916e45845a1d3d0'),
('2026-08-18-purge-legacy-placeholder-variants.sql','7ff11fa4a42abc61052ac8b54a6614cbc06622c7b753599c7314d5bfadc46d6a'),
('2026-08-19-backend-integrity-hardening.sql',  'fedb5362871f1be607d033ebbb42346dcbde3f2e920bc1f4156a55cee1b1a75d');

-- Bootstrap admin is created by database/setup.php when no admin exists.
-- Run from project root: php database/setup.php   (CLI only, never via browser)

