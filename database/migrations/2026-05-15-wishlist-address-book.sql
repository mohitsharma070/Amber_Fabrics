-- Persistent wishlist + customer address book

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
