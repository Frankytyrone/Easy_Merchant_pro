<?php
/**
 * system_fix.php — System Auto-Fix
 *
 * POST — creates missing tables/columns, inserts default rows (admin only)
 */
require_once __DIR__ . '/common.php';

setCorsHeaders();
header('Content-Type: application/json; charset=utf-8');

$auth = requireAuth();
if ($auth['role'] !== 'admin') {
    jsonResponse(['success' => false, 'error' => 'Admin access required'], 403);
}

$fixed = [];

try {
    $pdo = getDb();

    // ── Helper: run DDL silently ──────────────────────────────
    $exec = function (string $sql) use ($pdo, &$fixed) {
        try {
            $pdo->exec($sql);
        } catch (Throwable $e) {
            // Ignore "already exists" type errors, log others
            error_log('system_fix DDL error: ' . $e->getMessage() . ' | SQL: ' . substr($sql, 0, 200));
        }
    };

    // ── 1. Base tables (schema.sql) ───────────────────────────

    $exec("CREATE TABLE IF NOT EXISTS stores (
      id INT PRIMARY KEY AUTO_INCREMENT,
      code VARCHAR(10) UNIQUE NOT NULL,
      name VARCHAR(100) NOT NULL,
      address_1 VARCHAR(100),
      address_2 VARCHAR(100),
      town VARCHAR(50),
      county VARCHAR(50),
      eircode VARCHAR(10),
      phone VARCHAR(20),
      invoice_prefix VARCHAR(10) DEFAULT 'INV',
      quote_prefix VARCHAR(10) DEFAULT 'QUO',
      next_invoice_num INT DEFAULT 1001,
      next_quote_num INT DEFAULT 1001
    )");

    $exec("CREATE TABLE IF NOT EXISTS settings (
      id INT PRIMARY KEY AUTO_INCREMENT,
      `key` VARCHAR(100) UNIQUE NOT NULL,
      value TEXT
    )");

    $exec("CREATE TABLE IF NOT EXISTS users (
      id INT PRIMARY KEY AUTO_INCREMENT,
      username VARCHAR(50) UNIQUE NOT NULL,
      password_hash VARCHAR(255) NOT NULL,
      role ENUM('admin','manager','counter') DEFAULT 'counter',
      store_id INT,
      active TINYINT DEFAULT 1,
      created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )");

    $exec("CREATE TABLE IF NOT EXISTS login_attempts (
      id INT PRIMARY KEY AUTO_INCREMENT,
      ip_address VARCHAR(45) NOT NULL,
      attempted_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
      INDEX idx_ip_time (ip_address, attempted_at)
    )");

    $exec("CREATE TABLE IF NOT EXISTS customers (
      id INT PRIMARY KEY AUTO_INCREMENT,
      customer_code VARCHAR(20),
      company_name VARCHAR(100),
      contact_name VARCHAR(100),
      address_1 VARCHAR(100),
      address_2 VARCHAR(100),
      address_3 VARCHAR(100),
      inv_town VARCHAR(50),
      inv_region VARCHAR(50),
      inv_postcode VARCHAR(10),
      email_address VARCHAR(100),
      inv_telephone VARCHAR(20),
      account_no VARCHAR(20),
      vat_registered TINYINT DEFAULT 0,
      payment_terms VARCHAR(50),
      notes TEXT,
      store_id INT,
      created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
      updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      INDEX idx_company (company_name),
      INDEX idx_account (account_no)
    )");

    $exec("CREATE TABLE IF NOT EXISTS products (
      id INT PRIMARY KEY AUTO_INCREMENT,
      product_code VARCHAR(50),
      description VARCHAR(255) NOT NULL,
      category VARCHAR(100),
      price DECIMAL(10,2) DEFAULT 0.00,
      vat_rate DECIMAL(5,2) DEFAULT 23.00,
      unit VARCHAR(20) DEFAULT 'each',
      active TINYINT DEFAULT 1,
      store_id INT,
      created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
      FULLTEXT INDEX idx_ft (description, product_code),
      INDEX idx_active (active)
    )");

    $exec("CREATE TABLE IF NOT EXISTS invoice_sequences (
      store_code VARCHAR(10) UNIQUE NOT NULL,
      next_invoice_num INT DEFAULT 1001,
      next_quote_num INT DEFAULT 1001
    )");

    $exec("CREATE TABLE IF NOT EXISTS invoices (
      id INT PRIMARY KEY AUTO_INCREMENT,
      invoice_number VARCHAR(30) UNIQUE NOT NULL,
      invoice_type ENUM('invoice','quote','credit') DEFAULT 'invoice',
      store_code VARCHAR(10) NOT NULL,
      customer_id INT,
      invoice_date DATE NOT NULL,
      due_date DATE,
      inv_town VARCHAR(50),
      inv_region VARCHAR(50),
      inv_postcode VARCHAR(10),
      email_address VARCHAR(100),
      inv_telephone VARCHAR(20),
      inv_del_client_name VARCHAR(100),
      inv_del_alternative_name VARCHAR(100),
      inv_del_address_1 VARCHAR(100),
      inv_del_address_2 VARCHAR(100),
      inv_del_address_3 VARCHAR(100),
      inv_del_town VARCHAR(50),
      inv_del_region VARCHAR(50),
      subtotal DECIMAL(10,2) DEFAULT 0.00,
      vat_total DECIMAL(10,2) DEFAULT 0.00,
      total DECIMAL(10,2) DEFAULT 0.00,
      amount_paid DECIMAL(10,2) DEFAULT 0.00,
      balance DECIMAL(10,2) DEFAULT 0.00,
      status ENUM('draft','pending','part_paid','paid','overdue','cancelled') DEFAULT 'draft',
      payment_terms VARCHAR(50),
      notes TEXT,
      is_backdated TINYINT DEFAULT 0,
      email_sent_at DATETIME,
      email_opened_at DATETIME,
      reminder_sent_at DATETIME,
      created_by INT,
      updated_by INT,
      created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
      updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      INDEX idx_store (store_code),
      INDEX idx_customer (customer_id),
      INDEX idx_date (invoice_date),
      INDEX idx_status (status)
    )");

    $exec("CREATE TABLE IF NOT EXISTS invoice_items (
      id INT PRIMARY KEY AUTO_INCREMENT,
      invoice_id INT NOT NULL,
      line_order INT DEFAULT 0,
      product_code VARCHAR(50),
      description VARCHAR(255),
      vat_rate DECIMAL(5,2) DEFAULT 23.00,
      quantity DECIMAL(10,3) DEFAULT 1.000,
      unit_price DECIMAL(10,2) DEFAULT 0.00,
      discount_pct DECIMAL(5,2) DEFAULT 0.00,
      line_total DECIMAL(10,2) DEFAULT 0.00,
      vat_amount DECIMAL(10,2) DEFAULT 0.00,
      INDEX idx_invoice (invoice_id)
    )");

    $exec("CREATE TABLE IF NOT EXISTS payments (
      id INT PRIMARY KEY AUTO_INCREMENT,
      invoice_id INT NOT NULL,
      payment_date DATE NOT NULL,
      amount DECIMAL(10,2) NOT NULL,
      method ENUM('cash','card','cheque','bank_transfer','other') DEFAULT 'cash',
      reference VARCHAR(100),
      notes TEXT,
      created_by INT,
      created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
      INDEX idx_invoice (invoice_id)
    )");

    $exec("CREATE TABLE IF NOT EXISTS email_log (
      id INT PRIMARY KEY AUTO_INCREMENT,
      invoice_id INT,
      customer_id INT,
      to_email VARCHAR(100),
      subject VARCHAR(255),
      type ENUM('invoice','reminder','statement','payment_link') DEFAULT 'invoice',
      sent_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
      opened_count INT DEFAULT 0,
      last_opened_at TIMESTAMP NULL,
      tracking_token VARCHAR(64) UNIQUE,
      status ENUM('sent','opened','bounced','failed') DEFAULT 'sent',
      INDEX idx_invoice (invoice_id),
      INDEX idx_customer (customer_id),
      INDEX idx_token (tracking_token)
    )");

    $exec("CREATE TABLE IF NOT EXISTS audit_log (
      id INT PRIMARY KEY AUTO_INCREMENT,
      user_id INT,
      action VARCHAR(100) NOT NULL,
      entity_type VARCHAR(50),
      entity_id INT,
      details TEXT,
      ip_address VARCHAR(45),
      created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
      INDEX idx_user (user_id),
      INDEX idx_action (action),
      INDEX idx_created (created_at)
    )");

    $exec("CREATE TABLE IF NOT EXISTS sync_queue (
      id INT PRIMARY KEY AUTO_INCREMENT,
      table_name VARCHAR(50),
      record_id INT,
      action VARCHAR(20),
      store_code VARCHAR(10),
      payload JSON,
      created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
      INDEX idx_store (store_code)
    )");

    $exec("CREATE TABLE IF NOT EXISTS tbl_stock (
      id INT PRIMARY KEY AUTO_INCREMENT,
      product_id INT NOT NULL,
      quantity_change DECIMAL(10,3) NOT NULL,
      reason VARCHAR(100),
      adjusted_by INT,
      adjusted_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
      INDEX idx_product (product_id)
    )");

    $fixed[] = 'Base tables ensured';

    // ── 2. schema_additions.sql columns ──────────────────────

    // Products additions
    $exec("ALTER TABLE products ADD COLUMN IF NOT EXISTS legacy_id INT DEFAULT NULL AFTER id");
    $exec("ALTER TABLE products ADD COLUMN IF NOT EXISTS barcode VARCHAR(100) DEFAULT NULL AFTER product_code");
    $exec("ALTER TABLE products ADD COLUMN IF NOT EXISTS stock_qty DECIMAL(10,3) DEFAULT 0.000 AFTER vat_rate");
    $exec("ALTER TABLE products ADD INDEX IF NOT EXISTS idx_barcode (barcode)");
    $exec("ALTER TABLE products ADD INDEX IF NOT EXISTS idx_legacy (legacy_id)");

    // Customers additions
    $exec("ALTER TABLE customers ADD COLUMN IF NOT EXISTS legacy_id INT DEFAULT NULL AFTER id");
    $exec("ALTER TABLE customers ADD COLUMN IF NOT EXISTS phone VARCHAR(20) DEFAULT NULL AFTER inv_telephone");
    $exec("ALTER TABLE customers ADD COLUMN IF NOT EXISTS phone2 VARCHAR(20) DEFAULT NULL AFTER phone");
    $exec("ALTER TABLE customers ADD COLUMN IF NOT EXISTS town VARCHAR(50) DEFAULT NULL AFTER inv_town");
    $exec("ALTER TABLE customers ADD COLUMN IF NOT EXISTS county VARCHAR(50) DEFAULT NULL AFTER inv_region");
    $exec("ALTER TABLE customers ADD COLUMN IF NOT EXISTS postcode VARCHAR(10) DEFAULT NULL AFTER inv_postcode");
    $exec("ALTER TABLE customers ADD COLUMN IF NOT EXISTS account_ref VARCHAR(50) DEFAULT NULL AFTER account_no");
    $exec("ALTER TABLE customers ADD COLUMN IF NOT EXISTS del_client_name VARCHAR(100) DEFAULT NULL");
    $exec("ALTER TABLE customers ADD COLUMN IF NOT EXISTS del_contact_name VARCHAR(100) DEFAULT NULL");
    $exec("ALTER TABLE customers ADD COLUMN IF NOT EXISTS del_address_1 VARCHAR(100) DEFAULT NULL");
    $exec("ALTER TABLE customers ADD COLUMN IF NOT EXISTS del_address_2 VARCHAR(100) DEFAULT NULL");
    $exec("ALTER TABLE customers ADD COLUMN IF NOT EXISTS del_address_3 VARCHAR(100) DEFAULT NULL");
    $exec("ALTER TABLE customers ADD COLUMN IF NOT EXISTS del_town VARCHAR(50) DEFAULT NULL");
    $exec("ALTER TABLE customers ADD COLUMN IF NOT EXISTS del_county VARCHAR(50) DEFAULT NULL");
    $exec("ALTER TABLE customers ADD COLUMN IF NOT EXISTS del_postcode VARCHAR(10) DEFAULT NULL");
    $exec("ALTER TABLE customers ADD INDEX IF NOT EXISTS idx_cust_legacy (legacy_id)");

    // Invoices additions
    $exec("ALTER TABLE invoices ADD COLUMN IF NOT EXISTS legacy_id INT DEFAULT NULL AFTER id");
    $exec("ALTER TABLE invoices ADD COLUMN IF NOT EXISTS payment_method VARCHAR(50) DEFAULT NULL AFTER payment_terms");
    $exec("ALTER TABLE invoices ADD COLUMN IF NOT EXISTS vat_number VARCHAR(50) DEFAULT NULL AFTER vat_total");
    $exec("ALTER TABLE invoices ADD COLUMN IF NOT EXISTS delivery_charge DECIMAL(10,2) DEFAULT 0.00 AFTER vat_number");
    $exec("ALTER TABLE invoices ADD COLUMN IF NOT EXISTS is_quote TINYINT DEFAULT 0 AFTER delivery_charge");
    $exec("ALTER TABLE invoices ADD COLUMN IF NOT EXISTS legacy_client_id INT DEFAULT NULL");
    $exec("ALTER TABLE invoices ADD INDEX IF NOT EXISTS idx_inv_legacy (legacy_id)");

    // Payments additions
    $exec("ALTER TABLE payments ADD COLUMN IF NOT EXISTS legacy_id INT DEFAULT NULL AFTER id");
    $exec("ALTER TABLE payments ADD COLUMN IF NOT EXISTS legacy_invoice_id INT DEFAULT NULL AFTER legacy_id");
    $exec("ALTER TABLE payments ADD INDEX IF NOT EXISTS idx_pay_legacy (legacy_id)");

    $fixed[] = 'Schema additions applied';

    // ── 3. schema_v2.sql tables ───────────────────────────────

    $exec("CREATE TABLE IF NOT EXISTS rate_limit (
      id INT PRIMARY KEY AUTO_INCREMENT,
      ip_address VARCHAR(45) NOT NULL,
      action VARCHAR(50) NOT NULL,
      window_start TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
      INDEX idx_ip_action (ip_address, action),
      INDEX idx_window (window_start)
    )");

    $exec("CREATE TABLE IF NOT EXISTS quotes (
      id INT PRIMARY KEY AUTO_INCREMENT,
      quote_number VARCHAR(30) NOT NULL UNIQUE,
      customer_id INT NOT NULL,
      store_id INT NOT NULL,
      status ENUM('draft','sent','accepted','declined','expired') DEFAULT 'draft',
      quote_date DATE NOT NULL,
      expiry_date DATE,
      subtotal DECIMAL(10,2) DEFAULT 0,
      vat_amount DECIMAL(10,2) DEFAULT 0,
      total DECIMAL(10,2) DEFAULT 0,
      notes TEXT,
      created_by INT,
      created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
      updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      INDEX idx_customer (customer_id),
      INDEX idx_store (store_id),
      INDEX idx_status (status)
    )");

    $exec("CREATE TABLE IF NOT EXISTS quote_items (
      id INT PRIMARY KEY AUTO_INCREMENT,
      quote_id INT NOT NULL,
      product_id INT,
      description VARCHAR(255) NOT NULL,
      quantity DECIMAL(10,3) DEFAULT 1,
      unit_price DECIMAL(10,2) NOT NULL,
      vat_rate DECIMAL(5,2) DEFAULT 23.00,
      line_total DECIMAL(10,2) NOT NULL,
      INDEX idx_quote (quote_id)
    )");

    $exec("CREATE TABLE IF NOT EXISTS recurring_invoices (
      id INT PRIMARY KEY AUTO_INCREMENT,
      customer_id INT NOT NULL,
      store_id INT NOT NULL,
      frequency ENUM('weekly','monthly','quarterly','yearly') NOT NULL,
      next_run_date DATE NOT NULL,
      last_run_date DATE,
      active TINYINT(1) DEFAULT 1,
      notes TEXT,
      created_by INT,
      created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
      INDEX idx_customer (customer_id),
      INDEX idx_next_run (active, next_run_date)
    )");

    $exec("CREATE TABLE IF NOT EXISTS recurring_invoice_items (
      id INT PRIMARY KEY AUTO_INCREMENT,
      recurring_id INT NOT NULL,
      product_id INT,
      description VARCHAR(255) NOT NULL,
      quantity DECIMAL(10,3) DEFAULT 1,
      unit_price DECIMAL(10,2) NOT NULL,
      vat_rate DECIMAL(5,2) DEFAULT 23.00
    )");

    $exec("CREATE TABLE IF NOT EXISTS expenses (
      id INT PRIMARY KEY AUTO_INCREMENT,
      store_id INT NOT NULL,
      expense_date DATE NOT NULL,
      category ENUM('Materials','Fuel','Tools','Subcontractors','Office','Telephone','Insurance','Other') NOT NULL,
      description VARCHAR(255) NOT NULL,
      amount DECIMAL(10,2) NOT NULL,
      vat_rate DECIMAL(5,2) DEFAULT 0,
      vat_amount DECIMAL(10,2) DEFAULT 0,
      supplier VARCHAR(100),
      receipt_ref VARCHAR(50),
      created_by INT,
      created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
      INDEX idx_store (store_id),
      INDEX idx_date (expense_date),
      INDEX idx_category (category)
    )");

    $fixed[] = 'V2 tables ensured';

    // ── 4. Default data ───────────────────────────────────────

    // Default stores
    $pdo->exec("INSERT IGNORE INTO stores (code, name) VALUES
      ('FAL', 'Easy Builders Merchant — Falcarragh'),
      ('GWE', 'Easy Builders Merchant — Gweedore')");

    // Default invoice sequences
    $pdo->exec("INSERT IGNORE INTO invoice_sequences (store_code) VALUES ('FAL'), ('GWE')");

    // Default settings
    $pdo->exec("INSERT IGNORE INTO settings (`key`, value) VALUES
      ('stripe_publishable_key', ''),
      ('stripe_secret_key', ''),
      ('revolut_api_key', ''),
      ('payment_gateway', 'stripe'),
      ('sumup_api_key', '')");

    $fixed[] = 'Default data inserted';

    // ── 5. Clear lockout tables ───────────────────────────────
    $pdo->exec("DELETE FROM login_attempts");
    $pdo->exec("DELETE FROM rate_limit");
    $fixed[] = 'Lockout tables cleared (login_attempts, rate_limit)';

} catch (Throwable $e) {
    jsonResponse(['success' => false, 'error' => $e->getMessage(), 'fixed' => $fixed], 500);
}

jsonResponse(['success' => true, 'fixed' => $fixed]);

