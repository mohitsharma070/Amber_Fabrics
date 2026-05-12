-- Admin OTP auth migration (hosting-safe)
CREATE TABLE IF NOT EXISTS admin_login_attempts (
    attempt_key CHAR(64) PRIMARY KEY,
    attempts INT NOT NULL DEFAULT 0,
    blocked_until DATETIME DEFAULT NULL,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS admin_login_otps (
    id INT AUTO_INCREMENT PRIMARY KEY,
    admin_id INT NOT NULL,
    otp_hash CHAR(64) NOT NULL,
    expires_at DATETIME NOT NULL,
    attempts INT NOT NULL DEFAULT 0,
    resend_available_at DATETIME NOT NULL,
    created_ip VARCHAR(45) DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_admin_login_otps_admin_id (admin_id),
    INDEX idx_admin_login_otps_expires_at (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Remove orphan rows before adding FK to avoid migration failure.
DELETE o
FROM admin_login_otps o
LEFT JOIN admins a ON a.id = o.admin_id
WHERE a.id IS NULL;

SET @fk_exists := (
    SELECT COUNT(*)
    FROM information_schema.TABLE_CONSTRAINTS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'admin_login_otps'
      AND CONSTRAINT_NAME = 'fk_admin_login_otps_admin'
      AND CONSTRAINT_TYPE = 'FOREIGN KEY'
);
SET @fk_sql := IF(
    @fk_exists = 0,
    'ALTER TABLE admin_login_otps ADD CONSTRAINT fk_admin_login_otps_admin FOREIGN KEY (admin_id) REFERENCES admins(id) ON DELETE CASCADE',
    'SELECT 1'
);
PREPARE stmt FROM @fk_sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
