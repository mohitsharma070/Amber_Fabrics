CREATE TABLE IF NOT EXISTS support_tickets (
    id               BIGINT AUTO_INCREMENT PRIMARY KEY,
    ticket_number    VARCHAR(32) NOT NULL,
    customer_id      INT NOT NULL,
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
