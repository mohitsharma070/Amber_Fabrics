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

