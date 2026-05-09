-- Fabric Export schema bootstrap
CREATE DATABASE IF NOT EXISTS fabric_export;
USE fabric_export;

CREATE TABLE IF NOT EXISTS admins (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(255) NOT NULL,
    email VARCHAR(255) UNIQUE NOT NULL,
    password_hash VARCHAR(255) NOT NULL,
    force_password_reset TINYINT(1) DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS admin_login_attempts (
    attempt_key CHAR(64) PRIMARY KEY,
    attempts INT NOT NULL DEFAULT 0,
    blocked_until DATETIME DEFAULT NULL,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS fabrics (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(255) NOT NULL,
    sku VARCHAR(100) UNIQUE DEFAULT NULL,
    category VARCHAR(100) DEFAULT NULL,
    unit_type ENUM('meter','piece','set') NOT NULL DEFAULT 'meter',
    meter_options VARCHAR(100) DEFAULT NULL,
    print_style VARCHAR(100) DEFAULT NULL,
    material VARCHAR(255) DEFAULT NULL,
    gsm VARCHAR(50) DEFAULT NULL,
    width VARCHAR(50) DEFAULT NULL,
    moq VARCHAR(100) DEFAULT NULL,
    lead_time VARCHAR(100) DEFAULT NULL,
    dispatch_time VARCHAR(100) DEFAULT NULL,
    size VARCHAR(100) DEFAULT NULL,
    color VARCHAR(100) DEFAULT NULL,
    description TEXT,
    wash_care TEXT,
    image VARCHAR(255) DEFAULT NULL,
    image2 VARCHAR(255) DEFAULT NULL,
    image3 VARCHAR(255) DEFAULT NULL,
    image4 VARCHAR(255) DEFAULT NULL,
    video VARCHAR(255) DEFAULT NULL,
    price DECIMAL(10,2) DEFAULT 0.00,
    sale_price DECIMAL(10,2) DEFAULT NULL,
    cost_price DECIMAL(10,2) DEFAULT 0.00,
    price_inr DECIMAL(10,2) DEFAULT NULL,
    price_usd DECIMAL(10,2) DEFAULT NULL,
    stock DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    stock_meters DECIMAL(10,2) DEFAULT 0.00,
    min_order_meters DECIMAL(10,2) DEFAULT 1.00,
    qty_step DECIMAL(10,4) DEFAULT 0.0000,
    is_featured TINYINT(1) DEFAULT 0,
    status ENUM('active','inactive') DEFAULT 'active',
    is_available TINYINT(1) DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS categories (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    slug VARCHAR(120) UNIQUE NOT NULL,
    parent_id INT DEFAULT NULL,
    image VARCHAR(255) DEFAULT NULL,
    status ENUM('active','inactive') DEFAULT 'active',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (parent_id) REFERENCES categories(id) ON DELETE CASCADE
);

INSERT INTO categories (name, slug, parent_id, status) VALUES
-- Fabric by Meter (parent)
('Fabric by Meter', 'fabric-by-meter', NULL, 'active'),
('Floral Prints', 'floral-prints', 1, 'active'),
('Geometrical Prints', 'geometrical-prints', 1, 'active'),
('Traditional Prints', 'traditional-prints', 1, 'active'),
('Ajrakh Prints', 'ajrakh-prints', 1, 'active'),
('Bagru Prints', 'bagru-prints', 1, 'active'),
('Indigo Prints', 'indigo-prints', 1, 'active'),
('Cotton Fabric', 'cotton-fabric', 1, 'active'),
('Dress Material', 'dress-material', 1, 'active'),
-- Home Furnishing (parent)
('Home Furnishing', 'home-furnishing', NULL, 'active'),
('Bedsheets', 'bedsheets', 11, 'active'),
('Table Covers', 'table-covers', 11, 'active'),
('Towels', 'towels', 11, 'active'),
('Cushion Covers', 'cushion-covers', 11, 'active'),
('Curtains', 'curtains', 11, 'active'),
('Napkins', 'napkins', 11, 'active'),
-- Ready Made (parent)
('Ready Made', 'ready-made', NULL, 'active'),
('Kurtis', 'kurtis', 18, 'active'),
('Dupattas', 'dupattas', 18, 'active'),
('Sarees', 'sarees', 18, 'active')
ON DUPLICATE KEY UPDATE
    name = VALUES(name),
    status = VALUES(status);

-- Customer accounts
CREATE TABLE IF NOT EXISTS customers (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(255) NOT NULL,
    email VARCHAR(255) UNIQUE NOT NULL,
    password_hash VARCHAR(255) NOT NULL,
    phone VARCHAR(30) DEFAULT NULL,
    country VARCHAR(100) DEFAULT NULL,
    email_verified TINYINT(1) DEFAULT 0,
    email_verify_token VARCHAR(64) DEFAULT NULL,
    reset_token VARCHAR(64) DEFAULT NULL,
    reset_token_expires DATETIME DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Customer brute-force protection (mirrors admin_login_attempts)
CREATE TABLE IF NOT EXISTS customer_login_attempts (
    attempt_key CHAR(64) PRIMARY KEY,
    attempts INT NOT NULL DEFAULT 0,
    blocked_until DATETIME DEFAULT NULL,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

-- Shopping cart (one per customer, persists across logins)
CREATE TABLE IF NOT EXISTS cart (
    id INT AUTO_INCREMENT PRIMARY KEY,
    customer_id INT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_cart_customer (customer_id)
);

-- Session/customer carts for ecommerce flow
CREATE TABLE IF NOT EXISTS carts (
    id INT AUTO_INCREMENT PRIMARY KEY,
    session_id VARCHAR(128) DEFAULT NULL,
    customer_id INT DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_carts_session_id (session_id),
    INDEX idx_carts_customer_id (customer_id)
);

-- Items in a cart (supports both new ecommerce fields and existing fabric-based fields)
CREATE TABLE IF NOT EXISTS cart_items (
    id INT AUTO_INCREMENT PRIMARY KEY,
    cart_id INT NOT NULL,
    product_id INT DEFAULT NULL,
    quantity DECIMAL(10,2) NOT NULL DEFAULT 1.00,
    price DECIMAL(10,2) DEFAULT 0.00,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    -- Legacy compatibility columns used by current code
    fabric_id INT NOT NULL,
    quantity_meters DECIMAL(10,2) NOT NULL DEFAULT 1.00,
    price_snapshot_inr DECIMAL(10,2) DEFAULT NULL,
    price_snapshot_usd DECIMAL(10,2) DEFAULT NULL,
    added_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_cart_product (cart_id, product_id),
    UNIQUE KEY uq_cart_fabric (cart_id, fabric_id),
    INDEX idx_cart_items_cart_id (cart_id)
);

CREATE TABLE IF NOT EXISTS coupons (
    id INT AUTO_INCREMENT PRIMARY KEY,
    code VARCHAR(50) NOT NULL UNIQUE,
    discount_type ENUM('flat','percent') NOT NULL DEFAULT 'flat',
    discount_value DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    min_order_amount DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    max_discount DECIMAL(10,2) DEFAULT NULL,
    start_date DATE DEFAULT NULL,
    end_date DATE DEFAULT NULL,
    usage_limit INT NOT NULL DEFAULT 0,
    used_count INT NOT NULL DEFAULT 0,
    status ENUM('active','inactive') NOT NULL DEFAULT 'active',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Orders
CREATE TABLE IF NOT EXISTS orders (
    id INT AUTO_INCREMENT PRIMARY KEY,
    order_number VARCHAR(50) UNIQUE NOT NULL,
    customer_name VARCHAR(255) NOT NULL,
    customer_phone VARCHAR(30) DEFAULT NULL,
    customer_email VARCHAR(255) DEFAULT NULL,
    address TEXT,
    city VARCHAR(120) DEFAULT NULL,
    state VARCHAR(120) DEFAULT NULL,
    pincode VARCHAR(20) DEFAULT NULL,
    country VARCHAR(120) DEFAULT NULL,
    subtotal DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    shipping_amount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    discount_amount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    total_amount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    payment_method ENUM('cod','upi','razorpay') NOT NULL DEFAULT 'cod',
    payment_status ENUM('pending','paid','failed','refunded') DEFAULT 'pending',
    order_status ENUM('pending','confirmed','packed','shipped','delivered','cancelled','returned','refunded') DEFAULT 'pending',
    order_notes TEXT,
    -- Compatibility columns for existing code paths
    customer_id INT DEFAULT NULL,
    payment_id VARCHAR(255) DEFAULT NULL,
    currency ENUM('INR','USD') NOT NULL DEFAULT 'INR',
    shipping_cost DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    total DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    shipping_address JSON DEFAULT NULL,
    notes TEXT,
    admin_notes TEXT,
    status ENUM('pending','confirmed','processing','shipped','delivered','cancelled') DEFAULT 'pending',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_orders_customer_id (customer_id),
    INDEX idx_orders_status (status),
    INDEX idx_orders_order_status (order_status),
    INDEX idx_orders_created_at (created_at),
    INDEX idx_orders_customer_email (customer_email)
);

-- Line items in an order
CREATE TABLE IF NOT EXISTS order_items (
    id INT AUTO_INCREMENT PRIMARY KEY,
    order_id INT NOT NULL,
    product_id INT DEFAULT NULL,
    product_name VARCHAR(255) NOT NULL,
    size VARCHAR(100) DEFAULT NULL,
    color VARCHAR(100) DEFAULT NULL,
    unit_type ENUM('meter','piece') NOT NULL DEFAULT 'meter',
    quantity DECIMAL(10,2) NOT NULL DEFAULT 1.00,
    price DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    total DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    -- Compatibility columns for existing code paths
    fabric_id INT DEFAULT NULL,
    fabric_name_snapshot VARCHAR(255) DEFAULT NULL,
    fabric_sku_snapshot VARCHAR(50) DEFAULT NULL,
    quantity_meters DECIMAL(10,2) DEFAULT NULL,
    price_per_meter DECIMAL(10,2) DEFAULT NULL,
    line_total DECIMAL(12,2) DEFAULT NULL,
    INDEX idx_order_items_order_id (order_id),
    INDEX idx_order_items_product_id (product_id)
);

CREATE TABLE IF NOT EXISTS payments (
    id INT AUTO_INCREMENT PRIMARY KEY,
    order_id INT NOT NULL,
    payment_method ENUM('cod','upi','razorpay') NOT NULL,
    payment_status ENUM('pending','paid','failed','refunded') DEFAULT 'pending',
    transaction_id VARCHAR(255) DEFAULT NULL,
    razorpay_order_id VARCHAR(255) DEFAULT NULL,
    razorpay_payment_id VARCHAR(255) DEFAULT NULL,
    razorpay_signature VARCHAR(255) DEFAULT NULL,
    amount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_payments_order_id (order_id),
    INDEX idx_payments_transaction_id (transaction_id)
);

CREATE TABLE IF NOT EXISTS shipments (
    id INT AUTO_INCREMENT PRIMARY KEY,
    order_id INT NOT NULL,
    courier_name VARCHAR(255) DEFAULT NULL,
    tracking_id VARCHAR(255) DEFAULT NULL,
    tracking_url VARCHAR(500) DEFAULT NULL,
    shipping_cost DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    shipped_at DATETIME DEFAULT NULL,
    delivered_at DATETIME DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_shipments_order_id (order_id),
    INDEX idx_shipments_tracking_id (tracking_id)
);

CREATE TABLE IF NOT EXISTS expenses (
    id INT AUTO_INCREMENT PRIMARY KEY,
    type ENUM('Marketing','Packaging','Shipping','Product Purchase','Website','Other') NOT NULL DEFAULT 'Other',
    amount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    expense_date DATE NOT NULL,
    note TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_expenses_date (expense_date),
    INDEX idx_expenses_type (type)
);

CREATE TABLE IF NOT EXISTS inquiries (
    id INT AUTO_INCREMENT PRIMARY KEY,
    inquiry_type ENUM('general','export') DEFAULT 'general',
    name VARCHAR(255) NOT NULL,
    company_name VARCHAR(255) DEFAULT NULL,
    email VARCHAR(255) NOT NULL,
    whatsapp_number VARCHAR(30) DEFAULT NULL,
    country VARCHAR(255) DEFAULT NULL,
    product_interested VARCHAR(255) DEFAULT NULL,
    fabric_type VARCHAR(255) DEFAULT NULL,
    quantity VARCHAR(255) DEFAULT NULL,
    meters VARCHAR(50) DEFAULT NULL,
    incoterm VARCHAR(20) DEFAULT NULL,
    destination VARCHAR(255) DEFAULT NULL,
    pincode VARCHAR(20) DEFAULT NULL,
    timeline VARCHAR(255) DEFAULT NULL,
    message TEXT,
    status ENUM('new','qualified','quoted','won','lost','contacted') DEFAULT 'new',
    internal_note TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS inquiry_activity_logs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    inquiry_id INT NOT NULL,
    admin_id INT DEFAULT NULL,
    actor_name VARCHAR(255) NOT NULL,
    action VARCHAR(80) NOT NULL,
    details TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_inquiry_activity_inquiry_id (inquiry_id),
    INDEX idx_inquiry_activity_created_at (created_at)
);

CREATE TABLE IF NOT EXISTS announcement_dismissals (
    id INT AUTO_INCREMENT PRIMARY KEY,
    session_key CHAR(64) NOT NULL,
    customer_id INT DEFAULT NULL,
    announcement_key CHAR(32) NOT NULL,
    dismissed_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_announce_dismissal (session_key, announcement_key),
    INDEX idx_announce_customer_id (customer_id),
    INDEX idx_announce_updated_at (updated_at)
);

CREATE TABLE IF NOT EXISTS site_settings (
    setting_key VARCHAR(120) PRIMARY KEY,
    setting_value TEXT,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS about_media (
    id INT AUTO_INCREMENT PRIMARY KEY,
    media_type ENUM('image','video') NOT NULL DEFAULT 'image',
    file_name VARCHAR(255) NOT NULL,
    poster_image VARCHAR(255) DEFAULT NULL,
    alt_text VARCHAR(255) DEFAULT NULL,
    sort_order INT NOT NULL DEFAULT 0,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_about_media_active_sort (is_active, sort_order, id)
);
-- Bootstrap admin is created by database/setup.php when no admin exists.
