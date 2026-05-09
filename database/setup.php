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

    // Fabrics table
    $conn->query(
        "CREATE TABLE IF NOT EXISTS fabrics (
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
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );

    // Backfill product columns for existing installs created before ecommerce expansion.
    $fabricColumnDefinitions = [
        'price' => "DECIMAL(10,2) NOT NULL DEFAULT 0.00",
        'sale_price' => "DECIMAL(10,2) NULL DEFAULT NULL",
        'cost_price' => "DECIMAL(10,2) NOT NULL DEFAULT 0.00",
        'stock' => "DECIMAL(10,2) NOT NULL DEFAULT 0.00",
        'unit_type' => "ENUM('meter','piece','set') NOT NULL DEFAULT 'meter'",
        'meter_options' => "VARCHAR(100) NULL DEFAULT NULL",
        'print_style' => "VARCHAR(100) NULL DEFAULT NULL",
        'sku' => "VARCHAR(100) NULL DEFAULT NULL",
        'size' => "VARCHAR(100) NULL DEFAULT NULL",
        'color' => "VARCHAR(100) NULL DEFAULT NULL",
        'category' => "VARCHAR(100) NULL DEFAULT NULL",
        'status' => "ENUM('active','inactive') NOT NULL DEFAULT 'active'",
    ];

    foreach ($fabricColumnDefinitions as $column => $definition) {
        if (!$columnExists($conn, 'fabrics', $column)) {
            $conn->query("ALTER TABLE fabrics ADD COLUMN {$column} {$definition}");
        }
    }

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

    if ($columnExists($conn, 'fabrics', 'sku')) {
        $skuIndexCheck = $conn->query("SELECT COUNT(*) AS total FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'fabrics' AND INDEX_NAME = 'uq_fabrics_sku'");
        $skuIndexExists = (int) (($skuIndexCheck->fetch_assoc()['total'] ?? 0)) > 0;
        if (!$skuIndexExists) {
            $conn->query("CREATE UNIQUE INDEX uq_fabrics_sku ON fabrics (sku)");
        }
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
    
    $conn->query(
        "INSERT INTO categories (name, slug, parent_id, status) VALUES
            ('Fabric by Meter', 'fabric-by-meter', NULL, 'active'),
            ('Floral Prints', 'floral-prints', 1, 'active'),
            ('Geometrical Prints', 'geometrical-prints', 1, 'active'),
            ('Traditional Prints', 'traditional-prints', 1, 'active'),
            ('Ajrakh Prints', 'ajrakh-prints', 1, 'active'),
            ('Bagru Prints', 'bagru-prints', 1, 'active'),
            ('Indigo Prints', 'indigo-prints', 1, 'active'),
            ('Cotton Fabric', 'cotton-fabric', 1, 'active'),
            ('Dress Material', 'dress-material', 1, 'active'),
            ('Home Furnishing', 'home-furnishing', NULL, 'active'),
            ('Bedsheets', 'bedsheets', 11, 'active'),
            ('Table Covers', 'table-covers', 11, 'active'),
            ('Towels', 'towels', 11, 'active'),
            ('Cushion Covers', 'cushion-covers', 11, 'active'),
            ('Curtains', 'curtains', 11, 'active'),
            ('Napkins', 'napkins', 11, 'active'),
            ('Ready Made', 'ready-made', NULL, 'active'),
            ('Kurtis', 'kurtis', 18, 'active'),
            ('Dupattas', 'dupattas', 18, 'active'),
            ('Sarees', 'sarees', 18, 'active')
         ON DUPLICATE KEY UPDATE name = VALUES(name), status = VALUES(status), parent_id = VALUES(parent_id)"
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
            phone VARCHAR(30) DEFAULT NULL,
            country VARCHAR(100) DEFAULT NULL,
            email_verified TINYINT(1) DEFAULT 0,
            email_verify_token VARCHAR(64) DEFAULT NULL,
            reset_token VARCHAR(64) DEFAULT NULL,
            reset_token_expires DATETIME DEFAULT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
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
            quantity DECIMAL(10,2) NOT NULL DEFAULT 1.00,
            price DECIMAL(10,2) DEFAULT 0.00,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            fabric_id INT NOT NULL,
            quantity_meters DECIMAL(10,2) NOT NULL DEFAULT 1.00,
            price_snapshot_inr DECIMAL(10,2) DEFAULT NULL,
            price_snapshot_usd DECIMAL(10,2) DEFAULT NULL,
            added_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uq_cart_product (cart_id, product_id),
            UNIQUE KEY uq_cart_fabric (cart_id, fabric_id),
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
    }

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
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_orders_customer_id (customer_id),
            INDEX idx_orders_status (status),
            INDEX idx_orders_order_status (order_status),
            INDEX idx_orders_created_at (created_at),
            INDEX idx_orders_customer_email (customer_email)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );

    // Order line items
    $conn->query(
        "CREATE TABLE IF NOT EXISTS order_items (
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
            fabric_id INT DEFAULT NULL,
            fabric_name_snapshot VARCHAR(255) DEFAULT NULL,
            fabric_sku_snapshot VARCHAR(50) DEFAULT NULL,
            quantity_meters DECIMAL(10,2) DEFAULT NULL,
            price_per_meter DECIMAL(10,2) DEFAULT NULL,
            line_total DECIMAL(12,2) DEFAULT NULL,
            INDEX idx_order_items_order_id (order_id),
            INDEX idx_order_items_product_id (product_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );

    if ($tableExists($conn, 'order_items')) {
        if (!$columnExists($conn, 'order_items', 'unit_type')) {
            $conn->query("ALTER TABLE order_items ADD COLUMN unit_type ENUM('meter','piece') NOT NULL DEFAULT 'meter' AFTER color");
        }
        if ($columnExists($conn, 'order_items', 'quantity')) {
            $conn->query("ALTER TABLE order_items MODIFY COLUMN quantity DECIMAL(10,2) NOT NULL DEFAULT 1.00");
        }
        if ($columnExists($conn, 'order_items', 'quantity_meters')) {
            $conn->query("ALTER TABLE order_items MODIFY COLUMN quantity_meters DECIMAL(10,2) NULL DEFAULT NULL");
        }
    }

    // Payments
    $conn->query(
        "CREATE TABLE IF NOT EXISTS payments (
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
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );

    // Shipments
    $conn->query(
        "CREATE TABLE IF NOT EXISTS shipments (
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
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );

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
            password_hash VARCHAR(255) NOT NULL,
            force_password_reset TINYINT(1) DEFAULT 0,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );

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

    $result = $conn->query("SELECT COUNT(*) AS total FROM admins");
    $count = (int) $result->fetch_assoc()['total'];
    if ($count === 0) {
        $stmt = $conn->prepare(
            "INSERT INTO admins (name, email, password_hash, force_password_reset) VALUES (?, ?, ?, 1)"
        );
        $bootstrapEmail = getenv('ADMIN_EMAIL') ?: 'admin@example.com';
        $bootstrapPassword = getenv('ADMIN_PASSWORD') ?: bin2hex(random_bytes(8));
        $defaultHash = password_hash($bootstrapPassword, PASSWORD_DEFAULT);
        $name = 'Site Admin';
        $stmt->bind_param('sss', $name, $bootstrapEmail, $defaultHash);
        $stmt->execute();
        $stmt->close();

        echo "Database tables ensured.\n";
        echo "Bootstrap admin created.\n";
        echo "Email: {$bootstrapEmail}\n";
        echo "Password: {$bootstrapPassword}\n";
        echo "Rotate this password immediately after first login.\n";
        return;
    }

    echo "Database tables ensured.\n";
}

ensure_tables($conn);
