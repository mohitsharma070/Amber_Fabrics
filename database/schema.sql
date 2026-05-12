-- ============================================================
-- Amber Fabrics â€“ Production Database Schema
-- Engine  : InnoDB | Charset : utf8mb4_unicode_ci
-- Import  : mysql -u <user> -p < database/schema.sql
-- After   : php database/setup.php   (CLI only â€“ seeds admin)
-- ============================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

CREATE DATABASE IF NOT EXISTS fabric_export
    CHARACTER SET utf8mb4
    COLLATE utf8mb4_unicode_ci;
USE fabric_export;

-- â”€â”€â”€ Admins â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
CREATE TABLE IF NOT EXISTS admins (
    id                   INT          AUTO_INCREMENT PRIMARY KEY,
    name                 VARCHAR(255) NOT NULL,
    email                VARCHAR(255) UNIQUE NOT NULL,
    password_hash        VARCHAR(255) NOT NULL,
    force_password_reset TINYINT(1)   DEFAULT 0,
    created_at           TIMESTAMP    DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- â”€â”€â”€ Admin brute-force protection â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
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

-- â”€â”€â”€ Fabrics (Products) â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
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

-- â”€â”€â”€ Product Categories â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
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

-- â”€â”€â”€ Customers â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
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

-- â”€â”€â”€ Customer brute-force protection â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
CREATE TABLE IF NOT EXISTS customer_login_attempts (
    attempt_key   CHAR(64)  PRIMARY KEY,
    attempts      INT       NOT NULL DEFAULT 0,
    blocked_until DATETIME  DEFAULT NULL,
    updated_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- â”€â”€â”€ Persistent shopping cart (one per customer) â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
CREATE TABLE IF NOT EXISTS cart (
    id          INT       AUTO_INCREMENT PRIMARY KEY,
    customer_id INT       NOT NULL,
    created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_cart_customer (customer_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- â”€â”€â”€ Session / guest cart (legacy ecommerce flow) â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
CREATE TABLE IF NOT EXISTS carts (
    id          INT          AUTO_INCREMENT PRIMARY KEY,
    session_id  VARCHAR(128) DEFAULT NULL,
    customer_id INT          DEFAULT NULL,
    created_at  TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_carts_session_id  (session_id),
    INDEX idx_carts_customer_id (customer_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- â”€â”€â”€ Cart line items â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
CREATE TABLE IF NOT EXISTS cart_items (
    id                 INT           AUTO_INCREMENT PRIMARY KEY,
    cart_id            INT           NOT NULL,
    product_id         INT           DEFAULT NULL,
    quantity           DECIMAL(10,2) NOT NULL DEFAULT 1.00,
    price              DECIMAL(10,2) DEFAULT 0.00,
    created_at         TIMESTAMP     DEFAULT CURRENT_TIMESTAMP,
    -- Legacy columns used by cart_save_to_db / cart_load_from_db
    fabric_id          INT           NOT NULL,
    quantity_meters    DECIMAL(10,2) NOT NULL DEFAULT 1.00,
    price_snapshot_inr DECIMAL(10,2) DEFAULT NULL,
    price_snapshot_usd DECIMAL(10,2) DEFAULT NULL,
    added_at           TIMESTAMP     DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_cart_product  (cart_id, product_id),
    UNIQUE KEY uq_cart_fabric   (cart_id, fabric_id),
    INDEX idx_cart_items_cart_id (cart_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- â”€â”€â”€ Coupons â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
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

-- â”€â”€â”€ Orders â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
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
    created_at      TIMESTAMP     DEFAULT CURRENT_TIMESTAMP,
    updated_at      TIMESTAMP     DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_orders_customer_id    (customer_id),
    INDEX idx_orders_status         (status),
    INDEX idx_orders_order_status   (order_status),
    INDEX idx_orders_created_at     (created_at),
    INDEX idx_orders_customer_email (customer_email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- â”€â”€â”€ Order line items â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
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
    fabric_name_snapshot VARCHAR(255)  DEFAULT NULL,
    fabric_sku_snapshot  VARCHAR(50)   DEFAULT NULL,
    quantity_meters      DECIMAL(10,2) DEFAULT NULL,
    price_per_meter      DECIMAL(10,2) DEFAULT NULL,
    line_total           DECIMAL(12,2) DEFAULT NULL,
    INDEX idx_order_items_order_id   (order_id),
    INDEX idx_order_items_product_id (product_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- â”€â”€â”€ Payments â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
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
    INDEX idx_payments_order_id       (order_id),
    INDEX idx_payments_transaction_id (transaction_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- â”€â”€â”€ Shipments â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
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

-- â”€â”€â”€ Business Expenses â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
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

-- â”€â”€â”€ Inquiries â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
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

-- â”€â”€â”€ Inquiry activity log â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
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

-- â”€â”€â”€ Announcement Dismissals â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
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

-- â”€â”€â”€ Site Settings (key-value store) â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
CREATE TABLE IF NOT EXISTS site_settings (
    setting_key   VARCHAR(120) PRIMARY KEY,
    setting_value TEXT,
    updated_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- â”€â”€â”€ About Page Media â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
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
    CONSTRAINT fk_returns_order FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE,
    CONSTRAINT fk_returns_customer FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE CASCADE,
    INDEX idx_returns_order_id (order_id),
    INDEX idx_returns_customer_id (customer_id),
    INDEX idx_returns_status (status),
    INDEX idx_returns_requested_at (requested_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS return_items (
    id                INT AUTO_INCREMENT PRIMARY KEY,
    return_id         INT NOT NULL,
    order_item_id     INT DEFAULT NULL,
    fabric_id         INT DEFAULT NULL,
    product_name      VARCHAR(255) NOT NULL,
    unit_type         ENUM('meter','piece','set') NOT NULL DEFAULT 'meter',
    quantity          DECIMAL(10,2) NOT NULL DEFAULT 1.00,
    line_total        DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    created_at        TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_return_items_return FOREIGN KEY (return_id) REFERENCES returns(id) ON DELETE CASCADE,
    INDEX idx_return_items_return_id (return_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
SET FOREIGN_KEY_CHECKS = 1;

-- Bootstrap admin is created by database/setup.php when no admin exists.
-- Run from project root: php database/setup.php   (CLI only, never via browser)

