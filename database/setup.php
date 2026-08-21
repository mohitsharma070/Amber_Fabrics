<?php
require_once __DIR__ . '/../includes/init.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Forbidden');
}

/**
 * Ensure required tables exist without touching existing data.
 * Intended to be run manually during setup, not on every request.
 */
function ensure_tables(mysqli $conn): void
{
    $tableExists = static function (mysqli $conn, string $table): bool {
        $stmt = $conn->prepare("SELECT COUNT(*) AS total FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?");
        $stmt->bind_param('s', $table);
        $stmt->execute();
        $result = $stmt->get_result();
        $total = (int) (($result->fetch_assoc()['total'] ?? 0));
        $stmt->close();
        return $total > 0;
    };

    $columnExists = static function (mysqli $conn, string $table, string $column): bool {
        $stmt = $conn->prepare("SELECT COUNT(*) AS total FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?");
        $stmt->bind_param('ss', $table, $column);
        $stmt->execute();
        $result = $stmt->get_result();
        $total = (int) (($result->fetch_assoc()['total'] ?? 0));
        $stmt->close();
        return $total > 0;
    };

    $ensureColumns = static function (mysqli $conn, string $table, array $definitions) use ($columnExists): void {
        foreach ($definitions as $column => $definition) {
            if (!$columnExists($conn, $table, $column)) {
                $conn->query("ALTER TABLE {$table} ADD COLUMN {$column} {$definition}");
            }
        }
    };

    $indexExists = static function (mysqli $conn, string $table, string $index): bool {
        $stmt = $conn->prepare("SELECT COUNT(*) AS total FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ?");
        $stmt->bind_param('ss', $table, $index);
        $stmt->execute();
        return (int) ($stmt->get_result()->fetch_assoc()['total'] ?? 0) > 0;
    };

    // Fabrics table
    $conn->query(
        "CREATE TABLE IF NOT EXISTS fabrics (
            id INT AUTO_INCREMENT PRIMARY KEY,
            product_code VARCHAR(100) DEFAULT NULL,
            amazon_asin VARCHAR(20) DEFAULT NULL,
            name VARCHAR(255) NOT NULL,
            sku VARCHAR(100) DEFAULT NULL,
            category VARCHAR(100) DEFAULT NULL,
            product_type ENUM('simple','variable') NOT NULL DEFAULT 'simple',
            slug VARCHAR(191) DEFAULT NULL,
            UNIQUE KEY uq_fabrics_product_code (product_code),
            UNIQUE KEY uq_fabrics_sku (sku),
            UNIQUE KEY uq_fabrics_slug (slug),
            unit_type ENUM('meter','piece','set') NOT NULL DEFAULT 'meter',
            meter_options VARCHAR(100) DEFAULT NULL,
            size VARCHAR(100) DEFAULT NULL,
            color VARCHAR(100) DEFAULT NULL,
            description TEXT,
            catalog_data LONGTEXT DEFAULT NULL,
            hsn_code VARCHAR(8) DEFAULT NULL,
            gst_rate DECIMAL(5,2) DEFAULT NULL,
            shipping_weight_kg DECIMAL(10,3) DEFAULT NULL,
            parcel_length_cm DECIMAL(10,2) DEFAULT NULL,
            parcel_width_cm DECIMAL(10,2) DEFAULT NULL,
            parcel_height_cm DECIMAL(10,2) DEFAULT NULL,
            price DECIMAL(10,2) DEFAULT 0.00,
            sale_price DECIMAL(10,2) DEFAULT NULL,
            cost_price DECIMAL(10,2) DEFAULT 0.00,
            stock DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            stock_meters DECIMAL(10,2) DEFAULT 0.00,
            min_order_meters DECIMAL(10,2) DEFAULT 1.00,
            qty_step DECIMAL(10,4) DEFAULT 0.0000,
            status ENUM('draft','active','inactive') DEFAULT 'draft',
            is_available TINYINT(1) DEFAULT 1,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            published_at DATETIME DEFAULT NULL,
            published_by INT DEFAULT NULL,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );

    // Backfill product columns for existing installs created before ecommerce expansion.
    $fabricColumnDefinitions = [
        'product_code' => "VARCHAR(100) NULL DEFAULT NULL",
        'amazon_asin' => "VARCHAR(20) NULL DEFAULT NULL",
        'name' => "VARCHAR(255) NOT NULL",
        'price' => "DECIMAL(10,2) NOT NULL DEFAULT 0.00",
        'sale_price' => "DECIMAL(10,2) NULL DEFAULT NULL",
        'cost_price' => "DECIMAL(10,2) NOT NULL DEFAULT 0.00",
        'stock' => "DECIMAL(10,2) NOT NULL DEFAULT 0.00",
        'stock_meters' => "DECIMAL(10,2) NOT NULL DEFAULT 0.00",
        'low_stock_threshold_units' => "INT NULL DEFAULT NULL",
        'low_stock_threshold_meters' => "DECIMAL(10,2) NULL DEFAULT NULL",
        'min_order_meters' => "DECIMAL(10,2) NOT NULL DEFAULT 1.00",
        'qty_step' => "DECIMAL(10,4) NOT NULL DEFAULT 0.0000",
        'is_available' => "TINYINT(1) NOT NULL DEFAULT 1",
        'unit_type' => "ENUM('meter','piece','set') NOT NULL DEFAULT 'meter'",
        'meter_options' => "VARCHAR(100) NULL DEFAULT NULL",
        'sku' => "VARCHAR(100) NULL DEFAULT NULL",
        'size' => "VARCHAR(100) NULL DEFAULT NULL",
        'color' => "VARCHAR(100) NULL DEFAULT NULL",
        'category' => "VARCHAR(100) NULL DEFAULT NULL",
        'product_type' => "ENUM('simple','variable') NOT NULL DEFAULT 'simple'",
        'slug' => "VARCHAR(191) NULL DEFAULT NULL",
        'description' => "TEXT NULL",
        'catalog_data' => "LONGTEXT NULL DEFAULT NULL",
        'hsn_code' => "VARCHAR(8) NULL DEFAULT NULL",
        'gst_rate' => "DECIMAL(5,2) NULL DEFAULT NULL",
        'shipping_weight_kg' => "DECIMAL(10,3) NULL DEFAULT NULL",
        'parcel_length_cm' => "DECIMAL(10,2) NULL DEFAULT NULL",
        'parcel_width_cm' => "DECIMAL(10,2) NULL DEFAULT NULL",
        'parcel_height_cm' => "DECIMAL(10,2) NULL DEFAULT NULL",
        'dispatch_min_days' => "SMALLINT UNSIGNED NULL DEFAULT NULL",
        'dispatch_max_days' => "SMALLINT UNSIGNED NULL DEFAULT NULL",
        'status' => "ENUM('draft','active','inactive') NOT NULL DEFAULT 'draft'",
        'created_at' => "TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP",
        'published_at' => "DATETIME NULL DEFAULT NULL",
        'published_by' => "INT NULL DEFAULT NULL",
        'updated_at' => "TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP",
    ];

    $ensureColumns($conn, 'fabrics', $fabricColumnDefinitions);

    $conn->query(
        "UPDATE fabrics
         SET slug = CONCAT(
             TRIM(BOTH '-' FROM LOWER(REPLACE(REPLACE(REPLACE(TRIM(name), ' ', '-'), '/', '-'), '_', '-'))),
             '-', id
         )
         WHERE slug IS NULL OR TRIM(slug) = ''"
    );
    $conn->query("ALTER TABLE fabrics MODIFY COLUMN slug VARCHAR(191) NOT NULL");

    if ($columnExists($conn, 'fabrics', 'status')) {
        $conn->query("ALTER TABLE fabrics MODIFY COLUMN status ENUM('draft','active','inactive') NOT NULL DEFAULT 'draft'");
    }
    $conn->query(
        "CREATE TABLE IF NOT EXISTS fabric_media (
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
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );

    if ($columnExists($conn, 'fabrics', 'stock_meters')) {
        $conn->query("ALTER TABLE fabrics MODIFY COLUMN stock_meters DECIMAL(10,2) NOT NULL DEFAULT 0.00");
    }
    if ($columnExists($conn, 'fabrics', 'stock')) {
        $conn->query("ALTER TABLE fabrics MODIFY COLUMN stock DECIMAL(10,2) NOT NULL DEFAULT 0.00");
    }
    if ($columnExists($conn, 'fabrics', 'min_order_meters')) {
        $conn->query("ALTER TABLE fabrics MODIFY COLUMN min_order_meters DECIMAL(10,2) NOT NULL DEFAULT 1.00");
    }
    if (!$columnExists($conn, 'fabrics', 'qty_step')) {
        $conn->query("ALTER TABLE fabrics ADD COLUMN qty_step DECIMAL(10,4) NOT NULL DEFAULT 0.0000 AFTER min_order_meters");
    }
    if ($columnExists($conn, 'fabrics', 'unit_type')) {
        $conn->query("ALTER TABLE fabrics MODIFY COLUMN unit_type ENUM('meter','piece','set') NOT NULL DEFAULT 'meter'");
    }

    // Variant-level inventory
    $conn->query(
        "CREATE TABLE IF NOT EXISTS fabric_variants (
            id INT AUTO_INCREMENT PRIMARY KEY,
            fabric_id INT NOT NULL,
            color VARCHAR(100) NOT NULL DEFAULT '',
            size VARCHAR(100) NOT NULL DEFAULT '',
            sku VARCHAR(100) UNIQUE DEFAULT NULL,
            image VARCHAR(255) DEFAULT NULL,
            pack_label VARCHAR(120) DEFAULT NULL,
            units_per_set INT DEFAULT NULL,
            price_override DECIMAL(10,2) DEFAULT NULL,
            stock DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            stock_meters DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            sort_order SMALLINT NOT NULL DEFAULT 0,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_fv_fabric (fabric_id),
            UNIQUE KEY uq_fabric_color_size (fabric_id, color, size)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );
    if (!$columnExists($conn, 'fabric_variants', 'image')) {
        $conn->query("ALTER TABLE fabric_variants ADD COLUMN image VARCHAR(255) NULL DEFAULT NULL AFTER sku");
    }
    if (!$columnExists($conn, 'fabric_variants', 'image2')) {
        $conn->query("ALTER TABLE fabric_variants ADD COLUMN image2 VARCHAR(255) NULL DEFAULT NULL AFTER image");
    }
    if (!$columnExists($conn, 'fabric_variants', 'image3')) {
        $conn->query("ALTER TABLE fabric_variants ADD COLUMN image3 VARCHAR(255) NULL DEFAULT NULL AFTER image2");
    }
    if (!$columnExists($conn, 'fabric_variants', 'image4')) {
        $conn->query("ALTER TABLE fabric_variants ADD COLUMN image4 VARCHAR(255) NULL DEFAULT NULL AFTER image3");
    }
    if (!$columnExists($conn, 'fabric_variants', 'video')) {
        $conn->query("ALTER TABLE fabric_variants ADD COLUMN video VARCHAR(255) NULL DEFAULT NULL AFTER image4");
    }
    if (!$columnExists($conn, 'fabric_variants', 'pack_label')) {
        $conn->query("ALTER TABLE fabric_variants ADD COLUMN pack_label VARCHAR(120) NULL DEFAULT NULL AFTER video");
    }
    if (!$columnExists($conn, 'fabric_variants', 'units_per_set')) {
        $conn->query("ALTER TABLE fabric_variants ADD COLUMN units_per_set INT NULL DEFAULT NULL AFTER pack_label");
    }
    if ($columnExists($conn, 'fabric_variants', 'units_per_set')) {
        $conn->query(
            "UPDATE fabric_variants fv
             JOIN fabrics f ON f.id = fv.fabric_id
             SET fv.units_per_set = 1,
                 fv.pack_label = COALESCE(NULLIF(TRIM(fv.pack_label), ''), 'Pack of 1')
             WHERE f.unit_type = 'set' AND (fv.units_per_set IS NULL OR fv.units_per_set < 1)"
        );
    }

    if ($columnExists($conn, 'fabrics', 'sku')) {
        $skuIndexCheck = $conn->query("SELECT COUNT(*) AS total FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'fabrics' AND INDEX_NAME = 'uq_fabrics_sku'");
        $skuIndexExists = (int) (($skuIndexCheck->fetch_assoc()['total'] ?? 0)) > 0;
        if (!$skuIndexExists) {
            $conn->query("CREATE UNIQUE INDEX uq_fabrics_sku ON fabrics (sku)");
        }
    }
    if ($columnExists($conn, 'fabrics', 'product_code')) {
        $productCodeIndexCheck=$conn->query("SELECT COUNT(*) AS total FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='fabrics' AND INDEX_NAME='uq_fabrics_product_code'");
        if((int)(($productCodeIndexCheck->fetch_assoc()['total']??0))===0)$conn->query("CREATE UNIQUE INDEX uq_fabrics_product_code ON fabrics (product_code)");
    }
    if ($columnExists($conn, 'fabrics', 'slug')) {
        $slugIndexCheck=$conn->query("SELECT COUNT(*) AS total FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='fabrics' AND INDEX_NAME='uq_fabrics_slug'");
        if((int)(($slugIndexCheck->fetch_assoc()['total']??0))===0)$conn->query("CREATE UNIQUE INDEX uq_fabrics_slug ON fabrics (slug)");
    }

    // Product categories
    $conn->query(
        "CREATE TABLE IF NOT EXISTS categories (
            id INT AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(100) NOT NULL,
            slug VARCHAR(120) UNIQUE NOT NULL,
            parent_id INT DEFAULT NULL,
            image VARCHAR(255) DEFAULT NULL,
            status ENUM('active','inactive') DEFAULT 'active',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );

    // Add parent_id column if it doesn't exist (migration for existing dbs)
    if (!$columnExists($conn, 'categories', 'parent_id')) {
        $conn->query("ALTER TABLE categories ADD COLUMN parent_id INT DEFAULT NULL AFTER slug");
    }

    // Check if foreign key already exists
    $fkCheck = $conn->query("SELECT CONSTRAINT_NAME FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_NAME = 'categories' AND COLUMN_NAME = 'parent_id' AND REFERENCED_TABLE_NAME = 'categories'");
    $fkExists = ($fkCheck->num_rows ?? 0) > 0;

    // Disable foreign key checks to allow parent_id references before parent rows exist
    $conn->query("SET FOREIGN_KEY_CHECKS=0");
    
    // Curated storefront taxonomy (no parent categories):
    // Fabric by Meter, Bedsheets, Towels, Table Covers.
    $conn->query(
        "INSERT INTO categories (name, slug, parent_id, status)
         VALUES ('Fabric by Meter', 'fabric-by-meter', NULL, 'active')
         ON DUPLICATE KEY UPDATE name = VALUES(name), status = VALUES(status), parent_id = NULL"
    );
    $conn->query(
        "INSERT INTO categories (name, slug, parent_id, status)
         VALUES ('Bedsheets', 'bedsheets', NULL, 'active')
         ON DUPLICATE KEY UPDATE name = VALUES(name), status = VALUES(status), parent_id = NULL"
    );
    $conn->query(
        "INSERT INTO categories (name, slug, parent_id, status)
         VALUES ('Towels', 'towels', NULL, 'active')
         ON DUPLICATE KEY UPDATE name = VALUES(name), status = VALUES(status), parent_id = NULL"
    );
    $conn->query(
        "INSERT INTO categories (name, slug, parent_id, status)
         VALUES ('Table Covers', 'table-covers', NULL, 'active')
         ON DUPLICATE KEY UPDATE name = VALUES(name), status = VALUES(status), parent_id = NULL"
    );

    // Keep only curated categories active for storefront/admin selection.
    $conn->query(
        "UPDATE categories
         SET status = 'inactive'
         WHERE slug NOT IN ('fabric-by-meter', 'bedsheets', 'towels', 'table-covers')"
    );

    // Keep active products sellable under curated taxonomy.
    $conn->query(
        "UPDATE fabrics
         SET category = 'fabric-by-meter'
         WHERE status = 'active' AND (category IS NULL OR category = '' OR category NOT IN ('fabric-by-meter', 'bedsheets', 'towels', 'table-covers'))"
    );

    // Add foreign key constraint if it doesn't exist
    if (!$fkExists) {
        $conn->query("ALTER TABLE categories ADD CONSTRAINT fk_categories_parent FOREIGN KEY (parent_id) REFERENCES categories(id) ON DELETE CASCADE");
    }

    // Re-enable foreign key checks
    $conn->query("SET FOREIGN_KEY_CHECKS=1");

    // Customer and cart tables
    $conn->query(
        "CREATE TABLE IF NOT EXISTS customers (
            id INT AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(255) NOT NULL,
            email VARCHAR(255) UNIQUE NOT NULL,
            password_hash VARCHAR(255) NOT NULL,
            auth_version INT UNSIGNED NOT NULL DEFAULT 1,
            phone VARCHAR(30) DEFAULT NULL,
            country VARCHAR(100) DEFAULT NULL,
            email_verified TINYINT(1) DEFAULT 0,
            email_verify_token VARCHAR(64) DEFAULT NULL,
            email_verify_expires DATETIME DEFAULT NULL,
            reset_token VARCHAR(64) DEFAULT NULL,
            reset_token_expires DATETIME DEFAULT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );

    $ensureColumns($conn, 'customers', [
        'is_active' => "TINYINT(1) NOT NULL DEFAULT 1",
        'auth_version' => "INT UNSIGNED NOT NULL DEFAULT 1",
        'email_verify_expires' => "DATETIME NULL DEFAULT NULL",
    ]);

    $conn->query(
        "CREATE TABLE IF NOT EXISTS customer_addresses (
            id INT AUTO_INCREMENT PRIMARY KEY,
            customer_id INT NOT NULL,
            label VARCHAR(80) DEFAULT NULL,
            full_name VARCHAR(255) NOT NULL,
            phone VARCHAR(30) DEFAULT NULL,
            address_line TEXT NOT NULL,
            city VARCHAR(120) NOT NULL,
            state VARCHAR(120) DEFAULT NULL,
            pincode VARCHAR(20) DEFAULT NULL,
            country VARCHAR(120) NOT NULL DEFAULT 'India',
            is_default_shipping TINYINT(1) NOT NULL DEFAULT 0,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_customer_addresses_customer (customer_id),
            INDEX idx_customer_addresses_default (customer_id, is_default_shipping),
            CONSTRAINT fk_customer_addresses_customer FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );

    $conn->query(
        "CREATE TABLE IF NOT EXISTS cart (
            id INT AUTO_INCREMENT PRIMARY KEY,
            customer_id INT NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uq_cart_customer (customer_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );

    $conn->query(
        "CREATE TABLE IF NOT EXISTS cart_items (
            id INT AUTO_INCREMENT PRIMARY KEY,
            cart_id INT NOT NULL,
            product_id INT DEFAULT NULL,
            cart_key VARCHAR(255) DEFAULT NULL,
            selected_size VARCHAR(100) DEFAULT NULL,
            quantity DECIMAL(10,2) NOT NULL DEFAULT 1.00,
            price DECIMAL(10,2) DEFAULT 0.00,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            fabric_id INT NOT NULL,
            quantity_meters DECIMAL(10,2) NOT NULL DEFAULT 1.00,
            UNIQUE KEY uq_cart_key (cart_id, cart_key),
            INDEX idx_cart_product (cart_id, product_id),
            INDEX idx_cart_fabric (cart_id, fabric_id),
            INDEX idx_cart_items_cart_id (cart_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );

    if ($tableExists($conn, 'cart_items')) {
        if ($columnExists($conn, 'cart_items', 'quantity')) {
            $conn->query("ALTER TABLE cart_items MODIFY COLUMN quantity DECIMAL(10,2) NOT NULL DEFAULT 1.00");
        }
        if ($columnExists($conn, 'cart_items', 'quantity_meters')) {
            $conn->query("ALTER TABLE cart_items MODIFY COLUMN quantity_meters DECIMAL(10,2) NOT NULL DEFAULT 1.00");
        }
        if (!$columnExists($conn, 'cart_items', 'meter_length')) {
            $conn->query("ALTER TABLE cart_items ADD COLUMN meter_length DECIMAL(10,2) NULL DEFAULT NULL AFTER quantity_meters");
        }
        if (!$columnExists($conn, 'cart_items', 'variant_id')) {
            $conn->query("ALTER TABLE cart_items ADD COLUMN variant_id INT NULL DEFAULT NULL AFTER fabric_id");
        }
        if (!$columnExists($conn, 'cart_items', 'cart_key')) {
            $conn->query("ALTER TABLE cart_items ADD COLUMN cart_key VARCHAR(255) NULL DEFAULT NULL AFTER product_id");
        }
        if (!$columnExists($conn, 'cart_items', 'selected_size')) {
            $conn->query("ALTER TABLE cart_items ADD COLUMN selected_size VARCHAR(100) NULL DEFAULT NULL AFTER cart_key");
        }
        if ($columnExists($conn, 'cart_items', 'product_id')) {
            $conn->query("UPDATE cart_items SET cart_key = CONCAT(product_id, '::') WHERE (cart_key IS NULL OR cart_key = '') AND product_id IS NOT NULL");
        }
        $indexCheck = $conn->query("SELECT INDEX_NAME FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'cart_items' AND INDEX_NAME = 'uq_cart_product'");
        if (($indexCheck->num_rows ?? 0) > 0) {
            $conn->query("ALTER TABLE cart_items DROP INDEX uq_cart_product");
        }
        $indexCheck = $conn->query("SELECT INDEX_NAME FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'cart_items' AND INDEX_NAME = 'uq_cart_fabric'");
        if (($indexCheck->num_rows ?? 0) > 0) {
            $conn->query("ALTER TABLE cart_items DROP INDEX uq_cart_fabric");
        }
        $indexCheck = $conn->query("SELECT INDEX_NAME FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'cart_items' AND INDEX_NAME = 'uq_cart_key'");
        if (($indexCheck->num_rows ?? 0) === 0) {
            $conn->query("ALTER TABLE cart_items ADD UNIQUE INDEX uq_cart_key (cart_id, cart_key)");
        }
        $indexCheck = $conn->query("SELECT INDEX_NAME FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'cart_items' AND INDEX_NAME = 'idx_cart_items_variant'");
        if (($indexCheck->num_rows ?? 0) === 0) {
            $conn->query("CREATE INDEX idx_cart_items_variant ON cart_items (variant_id)");
        }
        if ($columnExists($conn, 'cart_items', 'price_snapshot_inr')) {
            $conn->query("ALTER TABLE cart_items DROP COLUMN price_snapshot_inr");
        }
        if ($columnExists($conn, 'cart_items', 'price_snapshot_usd')) {
            $conn->query("ALTER TABLE cart_items DROP COLUMN price_snapshot_usd");
        }
        if ($columnExists($conn, 'cart_items', 'added_at')) {
            $conn->query("ALTER TABLE cart_items DROP COLUMN added_at");
        }
    }

    $conn->query(
        "CREATE TABLE IF NOT EXISTS wishlist_items (
            id INT AUTO_INCREMENT PRIMARY KEY,
            customer_id INT NOT NULL,
            product_id INT NOT NULL,
            cart_key VARCHAR(255) NOT NULL,
            selected_size VARCHAR(100) DEFAULT NULL,
            quantity DECIMAL(10,2) NOT NULL DEFAULT 1.00,
            meter_length DECIMAL(10,2) DEFAULT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uq_wishlist_customer_key (customer_id, cart_key),
            INDEX idx_wishlist_customer (customer_id),
            INDEX idx_wishlist_product (product_id),
            CONSTRAINT fk_wishlist_customer FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE CASCADE,
            CONSTRAINT fk_wishlist_product FOREIGN KEY (product_id) REFERENCES fabrics(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );

    // Legacy carts table is unused; drop if present.
    if ($tableExists($conn, 'carts')) {
        $conn->query("DROP TABLE IF EXISTS carts");
    }

    // Abandoned cart reminders
    $conn->query(
        "CREATE TABLE IF NOT EXISTS abandoned_cart_reminders (
            id BIGINT AUTO_INCREMENT PRIMARY KEY,
            customer_id INT NOT NULL,
            customer_email VARCHAR(255) NOT NULL,
            customer_name VARCHAR(255) DEFAULT NULL,
            cart_hash CHAR(64) NOT NULL,
            items_count INT NOT NULL DEFAULT 0,
            subtotal_amount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            cart_summary TEXT,
            status ENUM('active','processing','completed','recovered','failed') NOT NULL DEFAULT 'active',
            emails_sent_count INT NOT NULL DEFAULT 0,
            delivery_attempts INT UNSIGNED NOT NULL DEFAULT 0,
            consecutive_failures INT UNSIGNED NOT NULL DEFAULT 0,
            next_send_at DATETIME DEFAULT NULL,
            last_sent_at DATETIME DEFAULT NULL,
            last_attempt_at DATETIME DEFAULT NULL,
            last_error VARCHAR(1000) DEFAULT NULL,
            last_activity_at DATETIME DEFAULT NULL,
            recovered_at DATETIME DEFAULT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uq_abandoned_cart_customer (customer_id),
            INDEX idx_abandoned_cart_status_next (status, next_send_at),
            INDEX idx_abandoned_cart_processing (status, updated_at),
            CONSTRAINT fk_abandoned_cart_customer FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );

    $ensureColumns($conn, 'abandoned_cart_reminders', [
        'delivery_attempts' => 'INT UNSIGNED NOT NULL DEFAULT 0 AFTER emails_sent_count',
        'consecutive_failures' => 'INT UNSIGNED NOT NULL DEFAULT 0 AFTER delivery_attempts',
        'last_attempt_at' => 'DATETIME NULL AFTER last_sent_at',
        'last_error' => 'VARCHAR(1000) NULL AFTER last_attempt_at',
    ]);
    $conn->query("ALTER TABLE abandoned_cart_reminders MODIFY COLUMN status ENUM('active','processing','completed','recovered','failed') NOT NULL DEFAULT 'active'");
    if (!$indexExists($conn, 'abandoned_cart_reminders', 'idx_abandoned_cart_processing')) {
        $conn->query("ALTER TABLE abandoned_cart_reminders ADD INDEX idx_abandoned_cart_processing (status, updated_at)");
    }

    // Inventory alert logs
    $conn->query(
        "CREATE TABLE IF NOT EXISTS inventory_alert_logs (
            id BIGINT AUTO_INCREMENT PRIMARY KEY,
            product_id INT NOT NULL,
            variant_id INT DEFAULT NULL,
            unit_type ENUM('meter','piece','set') NOT NULL DEFAULT 'piece',
            stock_value DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            sent_at DATETIME NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_inventory_alert_product_sent (product_id, sent_at),
            INDEX idx_inventory_alert_product_variant_sent (product_id, variant_id, sent_at),
            CONSTRAINT fk_inventory_alert_product FOREIGN KEY (product_id) REFERENCES fabrics(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );

    $conn->query(
        "CREATE TABLE IF NOT EXISTS back_in_stock_subscriptions (
            id BIGINT AUTO_INCREMENT PRIMARY KEY,
            product_id INT NOT NULL,
            variant_id INT DEFAULT NULL,
            customer_id INT DEFAULT NULL,
            email VARCHAR(255) NOT NULL,
            status ENUM('pending','processing','sent','cancelled','failed') NOT NULL DEFAULT 'pending',
            delivery_attempts INT UNSIGNED NOT NULL DEFAULT 0,
            unsubscribe_token CHAR(64) NOT NULL,
            requested_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            next_attempt_at DATETIME DEFAULT NULL,
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
            INDEX idx_bis_status_next_attempt (status, next_attempt_at, id),
            CONSTRAINT fk_bis_product FOREIGN KEY (product_id) REFERENCES fabrics(id) ON DELETE CASCADE,
            CONSTRAINT fk_bis_variant FOREIGN KEY (variant_id) REFERENCES fabric_variants(id) ON DELETE SET NULL,
            CONSTRAINT fk_bis_customer FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );
    $ensureColumns($conn, 'inventory_alert_logs', [
        'variant_id' => 'INT DEFAULT NULL AFTER product_id',
    ]);
    if (!$indexExists($conn, 'inventory_alert_logs', 'idx_inventory_alert_product_variant_sent')) {
        $conn->query("ALTER TABLE inventory_alert_logs ADD INDEX idx_inventory_alert_product_variant_sent (product_id, variant_id, sent_at)");
    }

    $ensureColumns($conn, 'back_in_stock_subscriptions', [
        'delivery_attempts' => 'INT UNSIGNED NOT NULL DEFAULT 0 AFTER status',
        'next_attempt_at' => 'DATETIME NULL AFTER requested_at',
    ]);
    $conn->query("ALTER TABLE back_in_stock_subscriptions MODIFY COLUMN status ENUM('pending','processing','sent','cancelled','failed') NOT NULL DEFAULT 'pending'");
    $conn->query("UPDATE back_in_stock_subscriptions SET next_attempt_at = DATE_ADD(requested_at, INTERVAL 1 HOUR) WHERE status = 'pending' AND next_attempt_at IS NULL");
    if (!$indexExists($conn, 'back_in_stock_subscriptions', 'idx_bis_status_next_attempt')) {
        $conn->query("ALTER TABLE back_in_stock_subscriptions ADD INDEX idx_bis_status_next_attempt (status, next_attempt_at, id)");
    }

    // Shipping / RTO risk table
    $conn->query(
        "CREATE TABLE IF NOT EXISTS shipping_rto_risks (
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
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );

    // Product reviews and ratings
    $conn->query(
        "CREATE TABLE IF NOT EXISTS product_reviews (
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
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );

    // Coupons
    $conn->query(
        "CREATE TABLE IF NOT EXISTS coupons (
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
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );

    // Orders
    $conn->query(
        "CREATE TABLE IF NOT EXISTS orders (
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
            customer_id INT DEFAULT NULL,
            payment_id VARCHAR(255) DEFAULT NULL,
            currency ENUM('INR','USD') NOT NULL DEFAULT 'INR',
            shipping_cost DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            total DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            shipping_address JSON DEFAULT NULL,
            notes TEXT,
            admin_notes TEXT,
            status ENUM('pending','confirmed','processing','shipped','delivered','cancelled') DEFAULT 'pending',
            inventory_reserved_at DATETIME DEFAULT NULL,
            inventory_restored_at DATETIME DEFAULT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_orders_customer_id (customer_id),
            INDEX idx_orders_status (status),
            INDEX idx_orders_order_status (order_status),
            INDEX idx_orders_created_at (created_at),
            INDEX idx_orders_customer_email (customer_email)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );
    $ensureColumns($conn, 'coupons', [
        'code' => "VARCHAR(50) NOT NULL UNIQUE",
        'discount_type' => "ENUM('flat','percent') NOT NULL DEFAULT 'flat'",
        'discount_value' => "DECIMAL(10,2) NOT NULL DEFAULT 0.00",
        'min_order_amount' => "DECIMAL(10,2) NOT NULL DEFAULT 0.00",
        'max_discount' => "DECIMAL(10,2) NULL DEFAULT NULL",
        'start_date' => "DATE NULL DEFAULT NULL",
        'end_date' => "DATE NULL DEFAULT NULL",
        'usage_limit' => "INT NOT NULL DEFAULT 0",
        'used_count' => "INT NOT NULL DEFAULT 0",
        'status' => "ENUM('active','inactive') NOT NULL DEFAULT 'active'",
        'created_at' => "TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP",
    ]);

    // Normalize legacy installs where payment_method enum may not include cod/upi.
    if ($columnExists($conn, 'orders', 'payment_method')) {
        $conn->query(
            "ALTER TABLE orders
             MODIFY COLUMN payment_method ENUM('cod','upi','razorpay') NOT NULL DEFAULT 'cod'"
        );
    }
    $ensureColumns($conn, 'orders', [
        'coupon_id' => "INT NULL DEFAULT NULL",
        'coupon_code' => "VARCHAR(50) NULL DEFAULT NULL",
        'coupon_discount' => "DECIMAL(12,2) NOT NULL DEFAULT 0.00",
        'shipping_quote_token' => "VARCHAR(64) NULL DEFAULT NULL",
        'shipping_source' => "VARCHAR(40) NULL DEFAULT NULL",
        'courier_id' => "INT NULL DEFAULT NULL",
        'courier_name' => "VARCHAR(255) NULL DEFAULT NULL",
        'cod_fee' => "DECIMAL(12,2) NOT NULL DEFAULT 0.00",
        'base_shipping' => "DECIMAL(12,2) NOT NULL DEFAULT 0.00",
        'account_activation_requested' => "TINYINT(1) NOT NULL DEFAULT 0",
        'account_activation_sent_at' => "DATETIME NULL DEFAULT NULL",
        'activation_email_status' => "ENUM('pending','processing','sent','failed') NOT NULL DEFAULT 'pending'",
        'activation_email_claimed_at' => "DATETIME NULL DEFAULT NULL",
        'activation_email_claim_token' => "CHAR(32) NULL DEFAULT NULL",
        'activation_email_attempts' => "INT UNSIGNED NOT NULL DEFAULT 0",
        'activation_email_last_error' => "VARCHAR(500) NULL DEFAULT NULL",
        'serviceability_status' => "ENUM('live','estimated','unavailable') NOT NULL DEFAULT 'estimated'",
        'estimated_dispatch_start' => "DATE NULL DEFAULT NULL",
        'estimated_dispatch_end' => "DATE NULL DEFAULT NULL",
        'estimated_delivery_start' => "DATE NULL DEFAULT NULL",
        'estimated_delivery_end' => "DATE NULL DEFAULT NULL",
    ]);
    $activationIndexCheck=$conn->query("SELECT COUNT(*) AS total FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='orders' AND INDEX_NAME='idx_orders_activation_email_delivery'");
    if((int)(($activationIndexCheck->fetch_assoc()['total']??0))===0)$conn->query("CREATE INDEX idx_orders_activation_email_delivery ON orders (account_activation_requested,activation_email_status,activation_email_claimed_at)");
    if ($columnExists($conn, 'orders', 'coupon_id')) {
        $indexCheck = $conn->query("SELECT INDEX_NAME FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'orders' AND INDEX_NAME = 'idx_orders_coupon_id'");
        if (($indexCheck->num_rows ?? 0) === 0) {
            $conn->query("CREATE INDEX idx_orders_coupon_id ON orders (coupon_id)");
        }
    }
    if ($columnExists($conn, 'orders', 'coupon_code')) {
        $indexCheck = $conn->query("SELECT INDEX_NAME FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'orders' AND INDEX_NAME = 'idx_orders_coupon_code'");
        if (($indexCheck->num_rows ?? 0) === 0) {
            $conn->query("CREATE INDEX idx_orders_coupon_code ON orders (coupon_code)");
        }
    }
    if ($columnExists($conn, 'orders', 'shipping_quote_token')) {
        $indexCheck = $conn->query("SELECT INDEX_NAME FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'orders' AND INDEX_NAME = 'idx_orders_shipping_quote_token'");
        if (($indexCheck->num_rows ?? 0) === 0) {
            $conn->query("CREATE INDEX idx_orders_shipping_quote_token ON orders (shipping_quote_token)");
        }
    }

    // Order line items
    $conn->query(
        "CREATE TABLE IF NOT EXISTS order_items (
            id INT AUTO_INCREMENT PRIMARY KEY,
            order_id INT NOT NULL,
            product_id INT DEFAULT NULL,
            product_name VARCHAR(255) NOT NULL,
            size VARCHAR(100) DEFAULT NULL,
            color VARCHAR(100) DEFAULT NULL,
            unit_type ENUM('meter','piece','set') NOT NULL DEFAULT 'meter',
            quantity DECIMAL(10,2) NOT NULL DEFAULT 1.00,
            price DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            total DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            fabric_id INT DEFAULT NULL,
            fabric_name_snapshot VARCHAR(255) DEFAULT NULL,
            fabric_sku_snapshot VARCHAR(50) DEFAULT NULL,
            quantity_meters DECIMAL(10,2) DEFAULT NULL,
            price_per_meter DECIMAL(10,2) DEFAULT NULL,
            line_total DECIMAL(12,2) DEFAULT NULL,
            cost_price_snapshot DECIMAL(12,2) DEFAULT NULL,
            bundle_quantity INT DEFAULT NULL,
            meter_length DECIMAL(10,2) DEFAULT NULL,
            pack_label VARCHAR(120) DEFAULT NULL,
            units_per_set INT DEFAULT NULL,
            variant_id INT DEFAULT NULL,
            taxable_amount DECIMAL(12,2) DEFAULT NULL,
            discount_amount DECIMAL(12,2) DEFAULT NULL,
            gst_rate_snapshot DECIMAL(6,3) DEFAULT NULL,
            gst_amount DECIMAL(12,2) DEFAULT NULL,
            cgst_amount DECIMAL(12,2) DEFAULT NULL,
            sgst_amount DECIMAL(12,2) DEFAULT NULL,
            igst_amount DECIMAL(12,2) DEFAULT NULL,
            tax_type ENUM('none','cgst_sgst','igst') DEFAULT 'none',
            hsn_code_snapshot VARCHAR(32) DEFAULT NULL,
            INDEX idx_order_items_order_id (order_id),
            INDEX idx_order_items_product_id (product_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );

    if ($tableExists($conn, 'order_items')) {
        if (!$columnExists($conn, 'order_items', 'unit_type')) {
            $conn->query("ALTER TABLE order_items ADD COLUMN unit_type ENUM('meter','piece','set') NOT NULL DEFAULT 'meter' AFTER color");
        }
        if ($columnExists($conn, 'order_items', 'unit_type')) {
            $conn->query("ALTER TABLE order_items MODIFY COLUMN unit_type ENUM('meter','piece','set') NOT NULL DEFAULT 'meter'");
        }
        if ($columnExists($conn, 'order_items', 'quantity')) {
            $conn->query("ALTER TABLE order_items MODIFY COLUMN quantity DECIMAL(10,2) NOT NULL DEFAULT 1.00");
        }
        if ($columnExists($conn, 'order_items', 'quantity_meters')) {
            $conn->query("ALTER TABLE order_items MODIFY COLUMN quantity_meters DECIMAL(10,2) NULL DEFAULT NULL");
        }
        if (!$columnExists($conn, 'order_items', 'bundle_quantity')) {
            $conn->query("ALTER TABLE order_items ADD COLUMN bundle_quantity INT NULL DEFAULT NULL AFTER line_total");
        }
        if (!$columnExists($conn, 'order_items', 'cost_price_snapshot')) {
            $conn->query("ALTER TABLE order_items ADD COLUMN cost_price_snapshot DECIMAL(12,2) NULL DEFAULT NULL AFTER line_total");
        }
        if (!$columnExists($conn, 'order_items', 'meter_length')) {
            $conn->query("ALTER TABLE order_items ADD COLUMN meter_length DECIMAL(10,2) NULL DEFAULT NULL AFTER bundle_quantity");
        }
        if (!$columnExists($conn, 'order_items', 'pack_label')) {
            $conn->query("ALTER TABLE order_items ADD COLUMN pack_label VARCHAR(120) NULL DEFAULT NULL AFTER meter_length");
        }
        if (!$columnExists($conn, 'order_items', 'units_per_set')) {
            $conn->query("ALTER TABLE order_items ADD COLUMN units_per_set INT NULL DEFAULT NULL AFTER pack_label");
        }
        if (!$columnExists($conn, 'order_items', 'variant_id')) {
            $conn->query("ALTER TABLE order_items ADD COLUMN variant_id INT NULL DEFAULT NULL AFTER fabric_id");
        }
        if (!$columnExists($conn, 'order_items', 'taxable_amount')) {
            $conn->query("ALTER TABLE order_items ADD COLUMN taxable_amount DECIMAL(12,2) NULL DEFAULT NULL AFTER units_per_set");
        }
        if (!$columnExists($conn, 'order_items', 'discount_amount')) {
            $conn->query("ALTER TABLE order_items ADD COLUMN discount_amount DECIMAL(12,2) NULL DEFAULT NULL AFTER taxable_amount");
        }
        if (!$columnExists($conn, 'order_items', 'gst_rate_snapshot')) {
            $conn->query("ALTER TABLE order_items ADD COLUMN gst_rate_snapshot DECIMAL(6,3) NULL DEFAULT NULL AFTER discount_amount");
        }
        if (!$columnExists($conn, 'order_items', 'gst_amount')) {
            $conn->query("ALTER TABLE order_items ADD COLUMN gst_amount DECIMAL(12,2) NULL DEFAULT NULL AFTER gst_rate_snapshot");
        }
        if (!$columnExists($conn, 'order_items', 'cgst_amount')) {
            $conn->query("ALTER TABLE order_items ADD COLUMN cgst_amount DECIMAL(12,2) NULL DEFAULT NULL AFTER gst_amount");
        }
        if (!$columnExists($conn, 'order_items', 'sgst_amount')) {
            $conn->query("ALTER TABLE order_items ADD COLUMN sgst_amount DECIMAL(12,2) NULL DEFAULT NULL AFTER cgst_amount");
        }
        if (!$columnExists($conn, 'order_items', 'igst_amount')) {
            $conn->query("ALTER TABLE order_items ADD COLUMN igst_amount DECIMAL(12,2) NULL DEFAULT NULL AFTER sgst_amount");
        }
        if (!$columnExists($conn, 'order_items', 'tax_type')) {
            $conn->query("ALTER TABLE order_items ADD COLUMN tax_type ENUM('none','cgst_sgst','igst') NOT NULL DEFAULT 'none' AFTER igst_amount");
        }
        if (!$columnExists($conn, 'order_items', 'hsn_code_snapshot')) {
            $conn->query("ALTER TABLE order_items ADD COLUMN hsn_code_snapshot VARCHAR(32) NULL DEFAULT NULL AFTER tax_type");
        }
        $indexCheck = $conn->query("SELECT INDEX_NAME FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'order_items' AND INDEX_NAME = 'idx_order_items_variant'");
        if (($indexCheck->num_rows ?? 0) === 0) {
            $conn->query("CREATE INDEX idx_order_items_variant ON order_items (variant_id)");
        }
    }

    $conn->query(
        "CREATE TABLE IF NOT EXISTS coupon_usages (
            id INT AUTO_INCREMENT PRIMARY KEY,
            coupon_id INT NOT NULL,
            customer_id INT DEFAULT NULL,
            order_id INT NOT NULL,
            used_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uq_coupon_usages_coupon_customer (coupon_id, customer_id),
            UNIQUE KEY uq_coupon_usages_order_id (order_id),
            CONSTRAINT fk_coupon_usages_coupon FOREIGN KEY (coupon_id) REFERENCES coupons(id) ON DELETE CASCADE,
            CONSTRAINT fk_coupon_usages_customer FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE CASCADE,
            CONSTRAINT fk_coupon_usages_order FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );

    // Payments
    $conn->query(
        "CREATE TABLE IF NOT EXISTS payments (
            id INT AUTO_INCREMENT PRIMARY KEY,
            order_id INT NOT NULL,
            payment_method ENUM('cod','upi','razorpay') NOT NULL,
            payment_status ENUM('pending','paid','failed','refunded') DEFAULT 'pending',
            transaction_id VARCHAR(255) DEFAULT NULL,
            razorpay_order_id VARCHAR(255) DEFAULT NULL,
            razorpay_create_claim_token CHAR(32) DEFAULT NULL,
            razorpay_create_claimed_at DATETIME DEFAULT NULL,
            razorpay_payment_id VARCHAR(255) DEFAULT NULL,
            razorpay_signature VARCHAR(255) DEFAULT NULL,
            amount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_payments_order_id (order_id),
            INDEX idx_payments_transaction_id (transaction_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );

    // Keep one payment row per (order_id, payment_method) to avoid duplicate state rows.
    $conn->query(
        "DELETE p1
         FROM payments p1
         JOIN payments p2
           ON p1.order_id = p2.order_id
          AND p1.payment_method = p2.payment_method
          AND p1.id < p2.id"
    );
    $uqOrderMethodCheck = $conn->query(
        "SELECT COUNT(*) AS total
         FROM information_schema.STATISTICS
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME = 'payments'
           AND INDEX_NAME = 'uq_payments_order_method'"
    );
    $uqOrderMethodExists = ((int) ($uqOrderMethodCheck->fetch_assoc()['total'] ?? 0)) > 0;
    if (!$uqOrderMethodExists) {
        $conn->query("ALTER TABLE payments ADD UNIQUE KEY uq_payments_order_method (order_id, payment_method)");
    }

    $rzpOrderIdxCheck = $conn->query(
        "SELECT COUNT(*) AS total
         FROM information_schema.STATISTICS
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME = 'payments'
           AND INDEX_NAME = 'idx_payments_razorpay_order_id'"
    );
    $rzpOrderIdxExists = ((int) ($rzpOrderIdxCheck->fetch_assoc()['total'] ?? 0)) > 0;
    if (!$rzpOrderIdxExists) {
        $conn->query("CREATE INDEX idx_payments_razorpay_order_id ON payments (razorpay_order_id)");
    }

    // Shipments
    $conn->query(
        "CREATE TABLE IF NOT EXISTS shipments (
            id INT AUTO_INCREMENT PRIMARY KEY,
            order_id INT NOT NULL,
            awb_code VARCHAR(255) DEFAULT NULL,
            courier_name VARCHAR(255) DEFAULT NULL,
            tracking_id VARCHAR(255) DEFAULT NULL,
            tracking_url VARCHAR(500) DEFAULT NULL,
            shipping_cost DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            shipped_at DATETIME DEFAULT NULL,
            delivered_at DATETIME DEFAULT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uq_shipments_order_id (order_id),
            INDEX idx_shipments_awb_code (awb_code),
            INDEX idx_shipments_tracking_id (tracking_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );

    $ensureColumns($conn, 'shipments', [
        'awb_code' => "VARCHAR(255) NULL DEFAULT NULL",
    ]);
    if ($columnExists($conn, 'shipments', 'awb_code')) {
        $awbIndexCheck = $conn->query(
            "SELECT COUNT(*) AS total
             FROM information_schema.STATISTICS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = 'shipments'
               AND INDEX_NAME = 'idx_shipments_awb_code'"
        );
        $awbIndexExists = ((int) ($awbIndexCheck->fetch_assoc()['total'] ?? 0)) > 0;
        if (!$awbIndexExists) {
            $conn->query("CREATE INDEX idx_shipments_awb_code ON shipments (awb_code)");
        }
    }

    $conn->query(
        "CREATE TABLE IF NOT EXISTS shipping_courier_shipments (
            id BIGINT AUTO_INCREMENT PRIMARY KEY,
            order_id INT NOT NULL,
            shipment_id INT NOT NULL,
            provider VARCHAR(64) NOT NULL,
            provider_order_id VARCHAR(191) DEFAULT NULL,
            provider_shipment_id VARCHAR(191) DEFAULT NULL,
            provider_status VARCHAR(80) DEFAULT NULL,
            label_url VARCHAR(500) DEFAULT NULL,
            raw_response_json JSON DEFAULT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uq_shipping_courier_shipment_provider (shipment_id, provider),
            INDEX idx_shipping_courier_order (order_id),
            INDEX idx_shipping_courier_provider_order (provider, provider_order_id),
            INDEX idx_shipping_courier_provider_shipment (provider, provider_shipment_id),
            INDEX idx_shipping_courier_status (provider, provider_status),
            INDEX idx_shipping_courier_provider_updated (provider, updated_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );

    $shippingCourierColumns = [];
    $shippingCourierColumnRes = $conn->query(
        "SELECT COLUMN_NAME
         FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME = 'shipping_courier_shipments'"
    );
    while ($row = $shippingCourierColumnRes ? $shippingCourierColumnRes->fetch_assoc() : null) {
        $shippingCourierColumns[(string) $row['COLUMN_NAME']] = true;
    }
    $shippingCourierColumnAdds = [
        'provider_order_id' => "ALTER TABLE shipping_courier_shipments ADD COLUMN provider_order_id VARCHAR(191) DEFAULT NULL AFTER provider",
        'provider_shipment_id' => "ALTER TABLE shipping_courier_shipments ADD COLUMN provider_shipment_id VARCHAR(191) DEFAULT NULL AFTER provider_order_id",
        'provider_status' => "ALTER TABLE shipping_courier_shipments ADD COLUMN provider_status VARCHAR(80) DEFAULT NULL AFTER provider_shipment_id",
        'label_url' => "ALTER TABLE shipping_courier_shipments ADD COLUMN label_url VARCHAR(500) DEFAULT NULL AFTER provider_status",
        'raw_response_json' => "ALTER TABLE shipping_courier_shipments ADD COLUMN raw_response_json JSON DEFAULT NULL AFTER label_url",
    ];
    foreach ($shippingCourierColumnAdds as $column => $ddl) {
        if (empty($shippingCourierColumns[$column])) {
            $conn->query($ddl);
        }
    }

    $shippingCourierIndexes = [];
    $shippingCourierIndexRes = $conn->query(
        "SELECT DISTINCT INDEX_NAME
         FROM information_schema.STATISTICS
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME = 'shipping_courier_shipments'"
    );
    while ($row = $shippingCourierIndexRes ? $shippingCourierIndexRes->fetch_assoc() : null) {
        $shippingCourierIndexes[(string) $row['INDEX_NAME']] = true;
    }
    if (empty($shippingCourierIndexes['uq_shipping_courier_shipment_provider'])) {
        $conn->query("ALTER TABLE shipping_courier_shipments ADD UNIQUE KEY uq_shipping_courier_shipment_provider (shipment_id, provider)");
    }
    if (empty($shippingCourierIndexes['idx_shipping_courier_order'])) {
        $conn->query("ALTER TABLE shipping_courier_shipments ADD INDEX idx_shipping_courier_order (order_id)");
    }
    if (empty($shippingCourierIndexes['idx_shipping_courier_provider_order'])) {
        $conn->query("ALTER TABLE shipping_courier_shipments ADD INDEX idx_shipping_courier_provider_order (provider, provider_order_id)");
    }
    if (empty($shippingCourierIndexes['idx_shipping_courier_provider_shipment'])) {
        $conn->query("ALTER TABLE shipping_courier_shipments ADD INDEX idx_shipping_courier_provider_shipment (provider, provider_shipment_id)");
    }
    if (empty($shippingCourierIndexes['idx_shipping_courier_status'])) {
        $conn->query("ALTER TABLE shipping_courier_shipments ADD INDEX idx_shipping_courier_status (provider, provider_status)");
    }
    if (empty($shippingCourierIndexes['idx_shipping_courier_provider_updated'])) {
        $conn->query("ALTER TABLE shipping_courier_shipments ADD INDEX idx_shipping_courier_provider_updated (provider, updated_at)");
    }

    $shippingCourierOrderFkCheck = $conn->query(
        "SELECT COUNT(*) AS total
         FROM information_schema.TABLE_CONSTRAINTS
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME = 'shipping_courier_shipments'
           AND CONSTRAINT_NAME = 'fk_shipping_courier_order'
           AND CONSTRAINT_TYPE = 'FOREIGN KEY'"
    );
    $shippingCourierOrderFkExists = ((int) ($shippingCourierOrderFkCheck->fetch_assoc()['total'] ?? 0)) > 0;
    if (!$shippingCourierOrderFkExists) {
        $conn->query(
            "DELETE scs
             FROM shipping_courier_shipments scs
             LEFT JOIN orders o ON o.id = scs.order_id
             WHERE o.id IS NULL"
        );
        $conn->query(
            "ALTER TABLE shipping_courier_shipments
             ADD CONSTRAINT fk_shipping_courier_order
             FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE"
        );
    }

    $shippingCourierShipmentFkCheck = $conn->query(
        "SELECT COUNT(*) AS total
         FROM information_schema.TABLE_CONSTRAINTS
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME = 'shipping_courier_shipments'
           AND CONSTRAINT_NAME = 'fk_shipping_courier_shipment'
           AND CONSTRAINT_TYPE = 'FOREIGN KEY'"
    );
    $shippingCourierShipmentFkExists = ((int) ($shippingCourierShipmentFkCheck->fetch_assoc()['total'] ?? 0)) > 0;
    if (!$shippingCourierShipmentFkExists) {
        $conn->query(
            "DELETE scs
             FROM shipping_courier_shipments scs
             LEFT JOIN shipments s ON s.id = scs.shipment_id
             WHERE s.id IS NULL"
        );
        $conn->query(
            "ALTER TABLE shipping_courier_shipments
             ADD CONSTRAINT fk_shipping_courier_shipment
             FOREIGN KEY (shipment_id) REFERENCES shipments(id) ON DELETE CASCADE"
        );
    }

    $conn->query(
        "CREATE TABLE IF NOT EXISTS shipping_courier_webhook_events (
            id BIGINT AUTO_INCREMENT PRIMARY KEY,
            provider VARCHAR(64) NOT NULL,
            event_id VARCHAR(191) NOT NULL,
            signature VARCHAR(255) DEFAULT NULL,
            payload_hash CHAR(64) DEFAULT NULL,
            raw_payload LONGTEXT DEFAULT NULL,
            status ENUM('received','processing','processed','failed') NOT NULL DEFAULT 'received',
            attempts INT NOT NULL DEFAULT 0,
            last_error TEXT DEFAULT NULL,
            processed_at DATETIME DEFAULT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uq_shipping_courier_webhook_event (provider, event_id),
            INDEX idx_shipping_courier_webhook_status (provider, status),
            INDEX idx_shipping_courier_webhook_processed (processed_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );
    $ensureColumns($conn, 'payments', [
        'razorpay_create_claim_token' => "CHAR(32) NULL DEFAULT NULL",
        'razorpay_create_claimed_at' => "DATETIME NULL DEFAULT NULL",
    ]);

    $conn->query(
        "CREATE TABLE IF NOT EXISTS shipping_courier_reference_cache (
            id BIGINT AUTO_INCREMENT PRIMARY KEY,
            provider VARCHAR(64) NOT NULL,
            reference_type VARCHAR(64) NOT NULL,
            segment_type VARCHAR(32) NOT NULL DEFAULT '',
            payload_json JSON NOT NULL,
            fetched_at DATETIME NOT NULL,
            expires_at DATETIME NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uq_shipping_courier_reference (provider, reference_type, segment_type),
            INDEX idx_shipping_courier_reference_expiry (expires_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );
    $conn->query("ALTER TABLE coupon_usages MODIFY COLUMN customer_id INT NULL");
    $conn->query(
        "DELETE duplicate_usage
         FROM coupon_usages duplicate_usage
         JOIN coupon_usages kept_usage
           ON kept_usage.order_id = duplicate_usage.order_id
          AND kept_usage.id < duplicate_usage.id"
    );
    $couponOrderIndex = $conn->query(
        "SELECT NON_UNIQUE
         FROM information_schema.STATISTICS
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME = 'coupon_usages'
           AND INDEX_NAME IN ('idx_coupon_usages_order_id', 'uq_coupon_usages_order_id')
         ORDER BY NON_UNIQUE ASC
         LIMIT 1"
    )->fetch_assoc();
    if (!$couponOrderIndex || (int) ($couponOrderIndex['NON_UNIQUE'] ?? 1) !== 0) {
        if ($couponOrderIndex) {
            $conn->query("ALTER TABLE coupon_usages DROP INDEX idx_coupon_usages_order_id");
        }
        $conn->query("ALTER TABLE coupon_usages ADD UNIQUE KEY uq_coupon_usages_order_id (order_id)");
    }
    $conn->query(
        "INSERT IGNORE INTO coupon_usages (coupon_id, customer_id, order_id, used_at)
         SELECT o.coupon_id, o.customer_id, o.id, o.created_at
         FROM orders o
         WHERE o.coupon_id IS NOT NULL
           AND o.coupon_id > 0
           AND o.payment_status IN ('pending', 'paid')
           AND o.order_status NOT IN ('cancelled', 'refunded')"
    );
    $conn->query(
        "UPDATE coupons c
         LEFT JOIN (
             SELECT coupon_id, COUNT(*) AS reservation_count
             FROM coupon_usages
             GROUP BY coupon_id
         ) usage_totals ON usage_totals.coupon_id = c.id
         SET c.used_count = COALESCE(usage_totals.reservation_count, 0)"
    );

    $conn->query(
        "CREATE TABLE IF NOT EXISTS shipping_courier_reverse_pickups (
            id BIGINT AUTO_INCREMENT PRIMARY KEY,
            return_id INT NOT NULL,
            order_id INT NOT NULL,
            provider VARCHAR(64) NOT NULL,
            initialization_status ENUM('idle','claiming','created','failed') NOT NULL DEFAULT 'idle',
            claim_token CHAR(32) DEFAULT NULL,
            claimed_at DATETIME DEFAULT NULL,
            attempt_count INT UNSIGNED NOT NULL DEFAULT 0,
            last_attempt_at DATETIME DEFAULT NULL,
            last_error VARCHAR(1000) DEFAULT NULL,
            provider_order_id VARCHAR(191) DEFAULT NULL,
            provider_pickup_id VARCHAR(191) DEFAULT NULL,
            provider_status VARCHAR(80) DEFAULT NULL,
            tracking_id VARCHAR(255) DEFAULT NULL,
            tracking_url VARCHAR(500) DEFAULT NULL,
            label_url VARCHAR(500) DEFAULT NULL,
            raw_response_json JSON DEFAULT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uq_shipping_courier_reverse_return_provider (return_id, provider),
            INDEX idx_shipping_courier_reverse_order (order_id),
            INDEX idx_shipping_courier_reverse_provider_order (provider, provider_order_id),
            INDEX idx_shipping_courier_reverse_pickup (provider, provider_pickup_id),
            INDEX idx_shipping_courier_reverse_status (provider, provider_status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );
    $ensureColumns($conn, 'shipping_courier_reverse_pickups', [
        'initialization_status' => "ENUM('idle','claiming','created','failed') NOT NULL DEFAULT 'idle' AFTER provider",
        'claim_token' => 'CHAR(32) DEFAULT NULL AFTER initialization_status',
        'claimed_at' => 'DATETIME DEFAULT NULL AFTER claim_token',
        'attempt_count' => 'INT UNSIGNED NOT NULL DEFAULT 0 AFTER claimed_at',
        'last_attempt_at' => 'DATETIME DEFAULT NULL AFTER attempt_count',
        'last_error' => 'VARCHAR(1000) DEFAULT NULL AFTER last_attempt_at',
    ]);
    if (!$indexExists($conn, 'shipping_courier_reverse_pickups', 'idx_shipping_courier_reverse_claim')) {
        $conn->query("ALTER TABLE shipping_courier_reverse_pickups ADD INDEX idx_shipping_courier_reverse_claim (initialization_status, claimed_at)");
    }
    if (!$indexExists($conn, 'shipping_courier_reverse_pickups', 'idx_shipping_courier_reverse_sync')) {
        $conn->query("ALTER TABLE shipping_courier_reverse_pickups ADD INDEX idx_shipping_courier_reverse_sync (provider, provider_status, updated_at)");
    }

    $conn->query(
        "CREATE TABLE IF NOT EXISTS cron_run_history (
            id BIGINT AUTO_INCREMENT PRIMARY KEY,
            started_at DATETIME NOT NULL,
            finished_at DATETIME NOT NULL,
            duration_ms INT UNSIGNED NOT NULL DEFAULT 0,
            status ENUM('success','degraded','failed','skipped') NOT NULL,
            jobs_total INT UNSIGNED NOT NULL DEFAULT 0,
            jobs_failed INT UNSIGNED NOT NULL DEFAULT 0,
            jobs_degraded INT UNSIGNED NOT NULL DEFAULT 0,
            critical_jobs_failed INT UNSIGNED NOT NULL DEFAULT 0,
            summary_json JSON DEFAULT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_cron_run_history_started (started_at),
            INDEX idx_cron_run_history_status_started (status, started_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );

    $returnsTableCheck = $conn->query(
        "SELECT COUNT(*) AS total
         FROM information_schema.TABLES
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME = 'returns'"
    );
    $returnsTableExists = ((int) ($returnsTableCheck->fetch_assoc()['total'] ?? 0)) > 0;
    if ($returnsTableExists) {
        $shippingCourierReverseReturnFkCheck = $conn->query(
            "SELECT COUNT(*) AS total
             FROM information_schema.TABLE_CONSTRAINTS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = 'shipping_courier_reverse_pickups'
               AND CONSTRAINT_NAME = 'fk_shipping_courier_reverse_return'
               AND CONSTRAINT_TYPE = 'FOREIGN KEY'"
        );
        $shippingCourierReverseReturnFkExists = ((int) ($shippingCourierReverseReturnFkCheck->fetch_assoc()['total'] ?? 0)) > 0;
        if (!$shippingCourierReverseReturnFkExists) {
            $conn->query(
                "DELETE scrp
                 FROM shipping_courier_reverse_pickups scrp
                 LEFT JOIN returns r ON r.id = scrp.return_id
                 WHERE r.id IS NULL"
            );
            $conn->query(
                "ALTER TABLE shipping_courier_reverse_pickups
                 ADD CONSTRAINT fk_shipping_courier_reverse_return
                 FOREIGN KEY (return_id) REFERENCES returns(id) ON DELETE CASCADE"
            );
        }
    }

    $shippingCourierReverseOrderFkCheck = $conn->query(
        "SELECT COUNT(*) AS total
         FROM information_schema.TABLE_CONSTRAINTS
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME = 'shipping_courier_reverse_pickups'
           AND CONSTRAINT_NAME = 'fk_shipping_courier_reverse_order'
           AND CONSTRAINT_TYPE = 'FOREIGN KEY'"
    );
    $shippingCourierReverseOrderFkExists = ((int) ($shippingCourierReverseOrderFkCheck->fetch_assoc()['total'] ?? 0)) > 0;
    if (!$shippingCourierReverseOrderFkExists) {
        $conn->query(
            "DELETE scrp
             FROM shipping_courier_reverse_pickups scrp
             LEFT JOIN orders o ON o.id = scrp.order_id
             WHERE o.id IS NULL"
        );
        $conn->query(
            "ALTER TABLE shipping_courier_reverse_pickups
             ADD CONSTRAINT fk_shipping_courier_reverse_order
             FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE"
        );
    }

    // Business expenses
    $conn->query(
        "CREATE TABLE IF NOT EXISTS expenses (
            id INT AUTO_INCREMENT PRIMARY KEY,
            type ENUM('Marketing','Packaging','Shipping','Product Purchase','Website','Other') NOT NULL DEFAULT 'Other',
            amount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            expense_date DATE NOT NULL,
            note TEXT,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_expenses_date (expense_date),
            INDEX idx_expenses_type (type)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );

    // Inquiries table
    $conn->query(
        "CREATE TABLE IF NOT EXISTS inquiries (
            id INT AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(255) NOT NULL,
            email VARCHAR(255) NOT NULL,
            country VARCHAR(255) DEFAULT NULL,
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
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );

    // Admins table with a default admin user if table is empty.
    $conn->query(
        "CREATE TABLE IF NOT EXISTS admins (
            id INT AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(255) NOT NULL,
            email VARCHAR(255) UNIQUE NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );
    if ($columnExists($conn, 'admins', 'force_password_reset')) {
        $conn->query("ALTER TABLE admins DROP COLUMN force_password_reset");
    }
    if ($columnExists($conn, 'admins', 'password_hash')) {
        $conn->query("ALTER TABLE admins DROP COLUMN password_hash");
    }

    $conn->query(
        "CREATE TABLE IF NOT EXISTS inquiry_activity_logs (
            id INT AUTO_INCREMENT PRIMARY KEY,
            inquiry_id INT NOT NULL,
            admin_id INT DEFAULT NULL,
            actor_name VARCHAR(255) NOT NULL,
            action VARCHAR(80) NOT NULL,
            details TEXT,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_inquiry_activity_inquiry_id (inquiry_id),
            INDEX idx_inquiry_activity_created_at (created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );

    $conn->query(
        "CREATE TABLE IF NOT EXISTS admin_login_attempts (
            attempt_key CHAR(64) PRIMARY KEY,
            attempts INT NOT NULL DEFAULT 0,
            blocked_until DATETIME DEFAULT NULL,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );
    $conn->query(
        "CREATE TABLE IF NOT EXISTS public_form_attempts (
            attempt_key CHAR(64) PRIMARY KEY,
            scope VARCHAR(80) NOT NULL,
            ip_address VARCHAR(45) NOT NULL,
            user_agent_hash CHAR(16) NOT NULL,
            attempts INT NOT NULL DEFAULT 0,
            window_started_at DATETIME NOT NULL,
            blocked_until DATETIME DEFAULT NULL,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_public_form_attempts_scope_updated (scope, updated_at),
            INDEX idx_public_form_attempts_updated (updated_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );
    if (!$indexExists($conn, 'public_form_attempts', 'idx_public_form_attempts_updated')) {
        $conn->query("ALTER TABLE public_form_attempts ADD INDEX idx_public_form_attempts_updated (updated_at)");
    }

    $conn->query(
        "CREATE TABLE IF NOT EXISTS payment_webhook_events (
            id BIGINT AUTO_INCREMENT PRIMARY KEY,
            provider VARCHAR(32) NOT NULL,
            event_id VARCHAR(191) NOT NULL,
            signature VARCHAR(255) DEFAULT NULL,
            received_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uq_payment_webhook_event (provider, event_id),
            INDEX idx_payment_webhook_received_at (received_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );

    $conn->query(
        "CREATE TABLE IF NOT EXISTS order_activity_logs (
            id BIGINT AUTO_INCREMENT PRIMARY KEY,
            order_id INT NOT NULL,
            action VARCHAR(80) NOT NULL,
            actor_type ENUM('system','customer','admin','webhook') NOT NULL DEFAULT 'system',
            actor_id INT DEFAULT NULL,
            actor_name VARCHAR(255) DEFAULT NULL,
            details TEXT,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_order_activity_order_id (order_id),
            INDEX idx_order_activity_created_at (created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );

    $conn->query(
        "CREATE TABLE IF NOT EXISTS refund_ledger (
            id BIGINT AUTO_INCREMENT PRIMARY KEY,
            order_id INT NOT NULL,
            payment_id INT NOT NULL,
            amount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            currency VARCHAR(8) NOT NULL DEFAULT 'INR',
            status ENUM('initiated','processed','failed') NOT NULL DEFAULT 'initiated',
            gateway VARCHAR(32) DEFAULT NULL,
            gateway_refund_id VARCHAR(191) DEFAULT NULL,
            notes TEXT,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_refund_ledger_order_id (order_id),
            INDEX idx_refund_ledger_payment_id (payment_id),
            INDEX idx_refund_ledger_status (status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );
    $conn->query(
        "CREATE TABLE IF NOT EXISTS stock_ledger (
            id BIGINT AUTO_INCREMENT PRIMARY KEY,
            order_id INT DEFAULT NULL,
            order_item_id INT DEFAULT NULL,
            return_id INT DEFAULT NULL,
            return_item_id INT DEFAULT NULL,
            fabric_id INT DEFAULT NULL,
            variant_id INT DEFAULT NULL,
            unit_type ENUM('meter','piece','set') NOT NULL DEFAULT 'meter',
            quantity DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            movement ENUM('reserve','release','return_restock','adjustment') NOT NULL DEFAULT 'adjustment',
            direction ENUM('in','out') NOT NULL DEFAULT 'in',
            source VARCHAR(64) DEFAULT NULL,
            notes TEXT,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_stock_ledger_order (order_id),
            INDEX idx_stock_ledger_return (return_id),
            INDEX idx_stock_ledger_fabric_variant (fabric_id, variant_id),
            INDEX idx_stock_ledger_created_at (created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );

    $conn->query(
        "CREATE TABLE IF NOT EXISTS payment_attempts (
            id BIGINT AUTO_INCREMENT PRIMARY KEY,
            order_id INT DEFAULT NULL,
            payment_id INT DEFAULT NULL,
            provider VARCHAR(32) NOT NULL,
            attempt_ref VARCHAR(191) NOT NULL,
            status VARCHAR(40) NOT NULL DEFAULT 'created',
            source VARCHAR(40) NOT NULL DEFAULT 'create',
            gateway_payment_id VARCHAR(191) DEFAULT NULL,
            gateway_signature VARCHAR(255) DEFAULT NULL,
            error_code VARCHAR(80) DEFAULT NULL,
            error_message TEXT,
            webhook_event_id VARCHAR(191) DEFAULT NULL,
            webhook_signature VARCHAR(255) DEFAULT NULL,
            payload_json LONGTEXT,
            retry_count INT NOT NULL DEFAULT 0,
            first_seen_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            last_seen_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uq_payment_attempt_provider_ref (provider, attempt_ref),
            INDEX idx_payment_attempt_order_id (order_id),
            INDEX idx_payment_attempt_payment_id (payment_id),
            INDEX idx_payment_attempt_status (status),
            INDEX idx_payment_attempt_webhook_event (webhook_event_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );
    $conn->query(
        "CREATE TABLE IF NOT EXISTS shipping_quotes (
            id BIGINT AUTO_INCREMENT PRIMARY KEY,
            quote_token CHAR(32) NOT NULL UNIQUE,
            customer_id INT DEFAULT NULL,
            subtotal DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            country VARCHAR(120) NOT NULL,
            pincode VARCHAR(20) DEFAULT NULL,
            payment_method VARCHAR(32) NOT NULL,
            base_shipping DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            cod_fee DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            shipping_total DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            source VARCHAR(32) NOT NULL DEFAULT 'manual',
            courier_name VARCHAR(255) DEFAULT NULL,
            courier_id INT DEFAULT NULL,
            expires_at DATETIME NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_shipping_quotes_customer_expires (customer_id, expires_at),
            INDEX idx_shipping_quotes_expires_at (expires_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );
    $ensureColumns($conn, 'shipping_quotes', [
        'serviceability_status' => "ENUM('live','estimated','unavailable') NOT NULL DEFAULT 'estimated'",
        'estimated_dispatch_start' => "DATE NULL DEFAULT NULL",
        'estimated_dispatch_end' => "DATE NULL DEFAULT NULL",
        'estimated_delivery_start' => "DATE NULL DEFAULT NULL",
        'estimated_delivery_end' => "DATE NULL DEFAULT NULL",
    ]);
    $conn->query(
        "CREATE TABLE IF NOT EXISTS guest_order_access_tokens (
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
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );
    $ensureColumns($conn, 'orders', [
        'inventory_reserved_at' => "DATETIME NULL DEFAULT NULL",
        'inventory_restored_at' => "DATETIME NULL DEFAULT NULL",
    ]);
    if ($columnExists($conn, 'orders', 'inventory_reserved_at')) {
        $conn->query(
            "UPDATE orders
             SET inventory_reserved_at = COALESCE(updated_at, created_at, NOW())
             WHERE inventory_reserved_at IS NULL
               AND order_status IN ('pending','confirmed','packed','shipped','delivered')"
        );
    }

    $rtoFkCheck = $conn->query(
        "SELECT COUNT(*) AS total
         FROM information_schema.TABLE_CONSTRAINTS
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME = 'shipping_rto_risks'
           AND CONSTRAINT_NAME = 'fk_shipping_rto_risks_order'
           AND CONSTRAINT_TYPE = 'FOREIGN KEY'"
    );
    $rtoFkExists = ((int) ($rtoFkCheck->fetch_assoc()['total'] ?? 0)) > 0;
    if (!$rtoFkExists) {
        $conn->query(
            "DELETE r
             FROM shipping_rto_risks r
             LEFT JOIN orders o ON o.id = r.order_id
             WHERE o.id IS NULL"
        );
        $conn->query(
            "ALTER TABLE shipping_rto_risks
             ADD CONSTRAINT fk_shipping_rto_risks_order
             FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE"
        );
    }

    $conn->query(
        "CREATE TABLE IF NOT EXISTS cod_confirmations (
            id BIGINT AUTO_INCREMENT PRIMARY KEY,
            order_id INT NOT NULL,
            channel ENUM('auto','whatsapp','call') NOT NULL DEFAULT 'auto',
            status ENUM('pending','confirmed','cancelled','auto_cancelled') NOT NULL DEFAULT 'pending',
            deadline_at DATETIME DEFAULT NULL,
            attempts INT NOT NULL DEFAULT 0,
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
            notes TEXT,
            confirmed_at DATETIME DEFAULT NULL,
            cancelled_at DATETIME DEFAULT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uq_cod_confirmations_order_id (order_id),
            UNIQUE KEY uq_cod_confirmations_response_token (response_token),
            INDEX idx_cod_confirmations_status_deadline (status, deadline_at),
            INDEX idx_cod_confirmations_message_status (message_status, message_attempts)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );

    $codGuardColumns = [];
    $codGuardColumnRes = $conn->query(
        "SELECT COLUMN_NAME
         FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME = 'cod_confirmations'"
    );
    while ($row = $codGuardColumnRes ? $codGuardColumnRes->fetch_assoc() : null) {
        $codGuardColumns[(string) $row['COLUMN_NAME']] = true;
    }
    $codGuardColumnAdds = [
        'response_token' => "ALTER TABLE cod_confirmations ADD COLUMN response_token CHAR(32) DEFAULT NULL AFTER attempts",
        'message_provider' => "ALTER TABLE cod_confirmations ADD COLUMN message_provider VARCHAR(40) DEFAULT NULL AFTER response_token",
        'message_id' => "ALTER TABLE cod_confirmations ADD COLUMN message_id VARCHAR(191) DEFAULT NULL AFTER message_provider",
        'message_status' => "ALTER TABLE cod_confirmations ADD COLUMN message_status VARCHAR(40) DEFAULT 'queued' AFTER message_id",
        'message_error' => "ALTER TABLE cod_confirmations ADD COLUMN message_error TEXT AFTER message_status",
        'message_sent_at' => "ALTER TABLE cod_confirmations ADD COLUMN message_sent_at DATETIME DEFAULT NULL AFTER message_error",
        'message_attempts' => "ALTER TABLE cod_confirmations ADD COLUMN message_attempts INT NOT NULL DEFAULT 0 AFTER message_sent_at",
        'last_inbound_message_id' => "ALTER TABLE cod_confirmations ADD COLUMN last_inbound_message_id VARCHAR(191) DEFAULT NULL AFTER message_attempts",
        'last_inbound_text' => "ALTER TABLE cod_confirmations ADD COLUMN last_inbound_text TEXT AFTER last_inbound_message_id",
        'last_inbound_at' => "ALTER TABLE cod_confirmations ADD COLUMN last_inbound_at DATETIME DEFAULT NULL AFTER last_inbound_text",
    ];
    foreach ($codGuardColumnAdds as $column => $ddl) {
        if (empty($codGuardColumns[$column])) {
            $conn->query($ddl);
        }
    }

    $codGuardIndexes = [];
    $codGuardIndexRes = $conn->query(
        "SELECT DISTINCT INDEX_NAME
         FROM information_schema.STATISTICS
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME = 'cod_confirmations'"
    );
    while ($row = $codGuardIndexRes ? $codGuardIndexRes->fetch_assoc() : null) {
        $codGuardIndexes[(string) $row['INDEX_NAME']] = true;
    }
    if (empty($codGuardIndexes['uq_cod_confirmations_response_token'])) {
        $conn->query("ALTER TABLE cod_confirmations ADD UNIQUE KEY uq_cod_confirmations_response_token (response_token)");
    }
    if (empty($codGuardIndexes['idx_cod_confirmations_message_status'])) {
        $conn->query("ALTER TABLE cod_confirmations ADD INDEX idx_cod_confirmations_message_status (message_status, message_attempts)");
    }

    $codGuardFkCheck = $conn->query(
        "SELECT COUNT(*) AS total
         FROM information_schema.TABLE_CONSTRAINTS
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME = 'cod_confirmations'
           AND CONSTRAINT_NAME = 'fk_cod_confirmations_order'
           AND CONSTRAINT_TYPE = 'FOREIGN KEY'"
    );
    $codGuardFkExists = ((int) ($codGuardFkCheck->fetch_assoc()['total'] ?? 0)) > 0;
    if (!$codGuardFkExists) {
        $conn->query(
            "DELETE cc
             FROM cod_confirmations cc
             LEFT JOIN orders o ON o.id = cc.order_id
             WHERE o.id IS NULL"
        );
        $conn->query(
            "ALTER TABLE cod_confirmations
             ADD CONSTRAINT fk_cod_confirmations_order
             FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE"
        );
    }

    $conn->query(
        "CREATE TABLE IF NOT EXISTS marketing_attributions (
            id BIGINT AUTO_INCREMENT PRIMARY KEY,
            order_id INT NOT NULL,
            customer_id INT DEFAULT NULL,
            utm_source VARCHAR(255) DEFAULT NULL,
            utm_medium VARCHAR(255) DEFAULT NULL,
            utm_campaign VARCHAR(255) DEFAULT NULL,
            utm_term VARCHAR(255) DEFAULT NULL,
            utm_content VARCHAR(255) DEFAULT NULL,
            fbclid VARCHAR(500) DEFAULT NULL,
            gclid VARCHAR(500) DEFAULT NULL,
            landing_url VARCHAR(1000) DEFAULT NULL,
            referrer VARCHAR(1000) DEFAULT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uq_marketing_attributions_order_id (order_id),
            INDEX idx_marketing_attributions_source_campaign (utm_source, utm_campaign),
            INDEX idx_marketing_attributions_customer_id (customer_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );

    $marketingFkCheck = $conn->query(
        "SELECT COUNT(*) AS total
         FROM information_schema.TABLE_CONSTRAINTS
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME = 'marketing_attributions'
           AND CONSTRAINT_NAME = 'fk_marketing_attributions_order'
           AND CONSTRAINT_TYPE = 'FOREIGN KEY'"
    );
    $marketingFkExists = ((int) ($marketingFkCheck->fetch_assoc()['total'] ?? 0)) > 0;
    if (!$marketingFkExists) {
        $conn->query(
            "DELETE ma
             FROM marketing_attributions ma
             LEFT JOIN orders o ON o.id = ma.order_id
             WHERE o.id IS NULL"
        );
        $conn->query(
            "ALTER TABLE marketing_attributions
             ADD CONSTRAINT fk_marketing_attributions_order
             FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE"
        );
    }

    $conn->query(
        "CREATE TABLE IF NOT EXISTS admin_login_otps (
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
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );

    $adminOtpFkCheck = $conn->query(
        "SELECT COUNT(*) AS total
         FROM information_schema.TABLE_CONSTRAINTS
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME = 'admin_login_otps'
           AND CONSTRAINT_NAME = 'fk_admin_login_otps_admin'
           AND CONSTRAINT_TYPE = 'FOREIGN KEY'"
    );
    $adminOtpFkExists = ((int) ($adminOtpFkCheck->fetch_assoc()['total'] ?? 0)) > 0;
    if (!$adminOtpFkExists) {
        $conn->query(
            "DELETE o
             FROM admin_login_otps o
             LEFT JOIN admins a ON a.id = o.admin_id
             WHERE a.id IS NULL"
        );
        $conn->query(
            "ALTER TABLE admin_login_otps
             ADD CONSTRAINT fk_admin_login_otps_admin
             FOREIGN KEY (admin_id) REFERENCES admins(id) ON DELETE CASCADE"
        );
    }

    $conn->query(
        "CREATE TABLE IF NOT EXISTS announcement_dismissals (
            id INT AUTO_INCREMENT PRIMARY KEY,
            session_key CHAR(64) NOT NULL,
            customer_id INT DEFAULT NULL,
            announcement_key CHAR(32) NOT NULL,
            dismissed_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uq_announce_dismissal (session_key, announcement_key),
            INDEX idx_announce_customer_id (customer_id),
            INDEX idx_announce_updated_at (updated_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );

    $conn->query(
        "CREATE TABLE IF NOT EXISTS site_settings (
            setting_key VARCHAR(120) PRIMARY KEY,
            setting_value TEXT,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );

    $conn->query(
        "CREATE TABLE IF NOT EXISTS about_media (
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
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );

    $conn->query(
        "CREATE TABLE IF NOT EXISTS returns (
            id INT AUTO_INCREMENT PRIMARY KEY,
            return_number VARCHAR(32) NOT NULL UNIQUE,
            order_id INT NOT NULL,
            customer_id INT NOT NULL,
            status ENUM('requested','approved','rejected','pickup_scheduled','in_transit','received','refund_initiated','refund_completed','cancelled') NOT NULL DEFAULT 'requested',
            reason VARCHAR(255) NOT NULL,
            customer_note TEXT DEFAULT NULL,
            image_1 VARCHAR(255) DEFAULT NULL,
            image_2 VARCHAR(255) DEFAULT NULL,
            admin_note TEXT DEFAULT NULL,
            refund_amount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            requested_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            approved_at DATETIME DEFAULT NULL,
            rejected_at DATETIME DEFAULT NULL,
            received_at DATETIME DEFAULT NULL,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uq_returns_order_id (order_id),
            INDEX idx_returns_order_id (order_id),
            INDEX idx_returns_customer_id (customer_id),
            INDEX idx_returns_status (status),
            INDEX idx_returns_requested_at (requested_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );
    $conn->query("ALTER TABLE returns MODIFY COLUMN customer_id INT NULL");

    // Keep one active return request row per order to prevent duplicate return submissions.
    $conn->query(
        "DELETE r1
         FROM returns r1
         JOIN returns r2
           ON r1.order_id = r2.order_id
          AND r1.id < r2.id"
    );
    $returnsOrderUqCheck = $conn->query(
        "SELECT COUNT(*) AS total
         FROM information_schema.STATISTICS
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME = 'returns'
           AND INDEX_NAME = 'uq_returns_order_id'"
    );
    $returnsOrderUqExists = ((int) ($returnsOrderUqCheck->fetch_assoc()['total'] ?? 0)) > 0;
    if (!$returnsOrderUqExists) {
        $conn->query("ALTER TABLE returns ADD UNIQUE KEY uq_returns_order_id (order_id)");
    }

    $conn->query(
        "CREATE TABLE IF NOT EXISTS return_items (
            id INT AUTO_INCREMENT PRIMARY KEY,
            return_id INT NOT NULL,
            order_item_id INT DEFAULT NULL,
            fabric_id INT DEFAULT NULL,
            product_name VARCHAR(255) NOT NULL,
            unit_type ENUM('meter','piece','set') NOT NULL DEFAULT 'meter',
            quantity DECIMAL(10,2) NOT NULL DEFAULT 1.00,
            variant_id INT DEFAULT NULL,
            restocked_qty DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            refund_amount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            restocked_at DATETIME DEFAULT NULL,
            line_total DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_return_items_return_id (return_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );
    $ensureColumns($conn, 'return_items', [
        'variant_id' => "INT NULL DEFAULT NULL",
        'restocked_qty' => "DECIMAL(10,2) NOT NULL DEFAULT 0.00",
        'refund_amount' => "DECIMAL(12,2) NOT NULL DEFAULT 0.00",
        'restocked_at' => "DATETIME NULL DEFAULT NULL",
    ]);

    $ensureColumns($conn, 'returns', [
        'image_1' => "VARCHAR(255) NULL DEFAULT NULL",
        'image_2' => "VARCHAR(255) NULL DEFAULT NULL",
    ]);

    if ($tableExists($conn, 'support_tickets')) {
        $conn->query("ALTER TABLE support_tickets MODIFY COLUMN customer_id INT NULL");
        $ensureColumns($conn, 'support_tickets', [
            'requester_name' => "VARCHAR(255) NULL DEFAULT NULL",
            'requester_email' => "VARCHAR(255) NULL DEFAULT NULL",
        ]);
        if (!$indexExists($conn, 'support_tickets', 'idx_support_tickets_status_updated')) {
            $conn->query("ALTER TABLE support_tickets ADD INDEX idx_support_tickets_status_updated (status, updated_at)");
        }
    }

    $returnsOrderFkCheck = $conn->query(
        "SELECT COUNT(*) AS total
         FROM information_schema.TABLE_CONSTRAINTS
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME = 'returns'
           AND CONSTRAINT_NAME = 'fk_returns_order'
           AND CONSTRAINT_TYPE = 'FOREIGN KEY'"
    );
    $returnsOrderFkExists = ((int) ($returnsOrderFkCheck->fetch_assoc()['total'] ?? 0)) > 0;
    if (!$returnsOrderFkExists) {
        $conn->query("ALTER TABLE returns ADD CONSTRAINT fk_returns_order FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE");
    }

    $returnsCustomerFkCheck = $conn->query(
        "SELECT COUNT(*) AS total
         FROM information_schema.TABLE_CONSTRAINTS
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME = 'returns'
           AND CONSTRAINT_NAME = 'fk_returns_customer'
           AND CONSTRAINT_TYPE = 'FOREIGN KEY'"
    );
    $returnsCustomerFkExists = ((int) ($returnsCustomerFkCheck->fetch_assoc()['total'] ?? 0)) > 0;
    if (!$returnsCustomerFkExists) {
        $conn->query("ALTER TABLE returns ADD CONSTRAINT fk_returns_customer FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE CASCADE");
    }

    $returnItemsFkCheck = $conn->query(
        "SELECT COUNT(*) AS total
         FROM information_schema.TABLE_CONSTRAINTS
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME = 'return_items'
           AND CONSTRAINT_NAME = 'fk_return_items_return'
           AND CONSTRAINT_TYPE = 'FOREIGN KEY'"
    );
    $returnItemsFkExists = ((int) ($returnItemsFkCheck->fetch_assoc()['total'] ?? 0)) > 0;
    if (!$returnItemsFkExists) {
        $conn->query("ALTER TABLE return_items ADD CONSTRAINT fk_return_items_return FOREIGN KEY (return_id) REFERENCES returns(id) ON DELETE CASCADE");
    }

    $reverseProviderOrderIdxCheck = $conn->query(
        "SELECT COUNT(*) AS total
         FROM information_schema.STATISTICS
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME = 'shipping_courier_reverse_pickups'
           AND INDEX_NAME = 'idx_shipping_courier_reverse_provider_order'"
    );
    $reverseProviderOrderIdxExists = ((int) ($reverseProviderOrderIdxCheck->fetch_assoc()['total'] ?? 0)) > 0;
    if (!$reverseProviderOrderIdxExists) {
        $conn->query("ALTER TABLE shipping_courier_reverse_pickups ADD INDEX idx_shipping_courier_reverse_provider_order (provider, provider_order_id)");
    }

    $shippingCourierReverseReturnFkCheck = $conn->query(
        "SELECT COUNT(*) AS total
         FROM information_schema.TABLE_CONSTRAINTS
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME = 'shipping_courier_reverse_pickups'
           AND CONSTRAINT_NAME = 'fk_shipping_courier_reverse_return'
           AND CONSTRAINT_TYPE = 'FOREIGN KEY'"
    );
    $shippingCourierReverseReturnFkExists = ((int) ($shippingCourierReverseReturnFkCheck->fetch_assoc()['total'] ?? 0)) > 0;
    if (!$shippingCourierReverseReturnFkExists) {
        $conn->query(
            "DELETE scrp
             FROM shipping_courier_reverse_pickups scrp
             LEFT JOIN returns r ON r.id = scrp.return_id
             WHERE r.id IS NULL"
        );
        $conn->query(
            "ALTER TABLE shipping_courier_reverse_pickups
             ADD CONSTRAINT fk_shipping_courier_reverse_return
             FOREIGN KEY (return_id) REFERENCES returns(id) ON DELETE CASCADE"
        );
    }

    $shippingCourierReverseOrderFkCheck = $conn->query(
        "SELECT COUNT(*) AS total
         FROM information_schema.TABLE_CONSTRAINTS
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME = 'shipping_courier_reverse_pickups'
           AND CONSTRAINT_NAME = 'fk_shipping_courier_reverse_order'
           AND CONSTRAINT_TYPE = 'FOREIGN KEY'"
    );
    $shippingCourierReverseOrderFkExists = ((int) ($shippingCourierReverseOrderFkCheck->fetch_assoc()['total'] ?? 0)) > 0;
    if (!$shippingCourierReverseOrderFkExists) {
        $conn->query(
            "DELETE scrp
             FROM shipping_courier_reverse_pickups scrp
             LEFT JOIN orders o ON o.id = scrp.order_id
             WHERE o.id IS NULL"
        );
        $conn->query(
            "ALTER TABLE shipping_courier_reverse_pickups
             ADD CONSTRAINT fk_shipping_courier_reverse_order
             FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE"
        );
    }

    $result = $conn->query("SELECT COUNT(*) AS total FROM admins");
    $count = (int) $result->fetch_assoc()['total'];
    if ($count === 0) {
        $stmt = $conn->prepare(
            "INSERT INTO admins (name, email) VALUES (?, ?)"
        );
        $bootstrapEmail = (string) ($GLOBALS['_app_config']['ADMIN_EMAIL'] ?? 'admin@example.com');
        $name = 'Site Admin';
        $stmt->bind_param('ss', $name, $bootstrapEmail);
        $stmt->execute();
        $stmt->close();

        echo "Database tables ensured.\n";
        echo "Bootstrap admin created.\n";
        echo "Admin login is email OTP-only; passwords are not used.\n";
        echo "Email: {$bootstrapEmail}\n";
        echo "Confirm mail settings so OTP delivery works before going live.\n";
        return;
    }

    echo "Database tables ensured.\n";
}

ensure_tables($conn);
