-- Easy Builders Merchant Pro — Database Schema
SET NAMES utf8mb4;
SET time_zone = '+00:00';
SET foreign_key_checks = 0;

-- Settings
CREATE TABLE IF NOT EXISTS settings (
  id INT PRIMARY KEY AUTO_INCREMENT,
  shop_name VARCHAR(100),
  address_1 VARCHAR(100),
  address_2 VARCHAR(100),
  town VARCHAR(50),
  county VARCHAR(50),
  eircode VARCHAR(10),
  phone VARCHAR(20),
  email VARCHAR(100),
  vat_no VARCHAR(20),
  smtp_host VARCHAR(100),
  smtp_port INT DEFAULT 587,
  smtp_user VARCHAR(100),
  smtp_pass VARCHAR(100),
  smtp_from_name VARCHAR(100),
  invoice_prefix_falcarragh VARCHAR(10) DEFAULT 'FAL',
  invoice_prefix_gweedore VARCHAR(10) DEFAULT 'GWE',
  invoice_start_falcarragh INT DEFAULT 1001,
  invoice_start_gweedore INT DEFAULT 1001,
  stock_module_enabled TINYINT DEFAULT 0,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Stores
CREATE TABLE IF NOT EXISTS stores (
  id INT PRIMARY KEY AUTO_INCREMENT,
  code VARCHAR(20) UNIQUE NOT NULL,
  name VARCHAR(100) NOT NULL,
  address TEXT,
  phone VARCHAR(20),
  email VARCHAR(100)
);
INSERT IGNORE INTO stores (code, name) VALUES ('falcarragh', 'Falcarragh'), ('gweedore', 'Gweedore');

-- Users
CREATE TABLE IF NOT EXISTS users (
  id INT PRIMARY KEY AUTO_INCREMENT,
  username VARCHAR(50) UNIQUE NOT NULL,
  password_hash VARCHAR(255) NOT NULL,
  full_name VARCHAR(100),
  role ENUM('admin','manager','counter') DEFAULT 'counter',
  store_id INT,
  active TINYINT DEFAULT 1,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Login attempts (rate limiting)
CREATE TABLE IF NOT EXISTS login_attempts (
  id INT PRIMARY KEY AUTO_INCREMENT,
  ip_address VARCHAR(45) NOT NULL,
  attempted_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_ip_time (ip_address, attempted_at)
);

-- Customers
CREATE TABLE IF NOT EXISTS customers (
  id INT PRIMARY KEY AUTO_INCREMENT,
  account_no VARCHAR(20) UNIQUE,
  name VARCHAR(100) NOT NULL,
  address_1 VARCHAR(100),
  address_2 VARCHAR(100),
  address_3 VARCHAR(100),
  town VARCHAR(50),
  region VARCHAR(50),
  eircode VARCHAR(10),
  email VARCHAR(100),
  telephone VARCHAR(20),
  delivery_preference ENUM('email','post','both') DEFAULT 'email',
  is_cash_sale TINYINT DEFAULT 0,
  notes TEXT,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_name (name),
  INDEX idx_account (account_no)
);

-- Products
CREATE TABLE IF NOT EXISTS products (
  id INT PRIMARY KEY AUTO_INCREMENT,
  code VARCHAR(50),
  description VARCHAR(255) NOT NULL,
  unit_price DECIMAL(10,2) DEFAULT 0.00,
  vat_rate DECIMAL(5,2) DEFAULT 23.00,
  unit VARCHAR(20) DEFAULT 'each',
  active TINYINT DEFAULT 1,
  INDEX idx_code (code),
  FULLTEXT INDEX idx_description (description),
  INDEX idx_active (active)
);

-- Invoices
CREATE TABLE IF NOT EXISTS invoices (
  id INT PRIMARY KEY AUTO_INCREMENT,
  invoice_number VARCHAR(20) UNIQUE NOT NULL,
  store_id INT NOT NULL,
  invoice_type ENUM('invoice','quote','credit_note') DEFAULT 'invoice',
  status ENUM('draft','sent','part_paid','paid','overdue','cancelled') DEFAULT 'draft',
  customer_id INT,
  cash_sale_name VARCHAR(100),
  invoice_date DATE NOT NULL,
  due_date DATE,
  inv_name VARCHAR(100),
  inv_address_1 VARCHAR(100),
  inv_address_2 VARCHAR(100),
  inv_address_3 VARCHAR(100),
  inv_town VARCHAR(50),
  inv_region VARCHAR(50),
  inv_postcode VARCHAR(10),
  inv_telephone VARCHAR(20),
  email_address VARCHAR(100),
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
  notes TEXT,
  internal_notes TEXT,
  email_sent_at DATETIME,
  email_opened_at DATETIME,
  email_tracking_token VARCHAR(64),
  created_by INT,
  created_store_context VARCHAR(20),
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_store (store_id),
  INDEX idx_customer (customer_id),
  INDEX idx_date (invoice_date),
  INDEX idx_status (status),
  INDEX idx_number (invoice_number)
);

-- Invoice Line Items
CREATE TABLE IF NOT EXISTS invoice_items (
  id INT PRIMARY KEY AUTO_INCREMENT,
  invoice_id INT NOT NULL,
  sort_order INT DEFAULT 0,
  product_id INT,
  code VARCHAR(50),
  description VARCHAR(255),
  qty DECIMAL(10,3) DEFAULT 1.000,
  unit_price DECIMAL(10,2) DEFAULT 0.00,
  discount_pct DECIMAL(5,2) DEFAULT 0.00,
  vat_rate DECIMAL(5,2) DEFAULT 23.00,
  line_net DECIMAL(10,2) DEFAULT 0.00,
  line_vat DECIMAL(10,2) DEFAULT 0.00,
  line_total DECIMAL(10,2) DEFAULT 0.00,
  INDEX idx_invoice (invoice_id)
);

-- Payments
CREATE TABLE IF NOT EXISTS payments (
  id INT PRIMARY KEY AUTO_INCREMENT,
  invoice_id INT NOT NULL,
  amount DECIMAL(10,2) NOT NULL,
  payment_date DATE NOT NULL,
  method ENUM('cash','card','cheque','bank_transfer','other') DEFAULT 'cash',
  reference VARCHAR(100),
  notes TEXT,
  recorded_by INT,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_invoice (invoice_id)
);

-- Audit Trail
CREATE TABLE IF NOT EXISTS audit_log (
  id INT PRIMARY KEY AUTO_INCREMENT,
  user_id INT,
  user_name VARCHAR(100),
  store_context VARCHAR(20),
  action VARCHAR(50),
  entity_type VARCHAR(50),
  entity_id INT,
  old_values JSON,
  new_values JSON,
  ip_address VARCHAR(45),
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_entity (entity_type, entity_id),
  INDEX idx_user (user_id),
  INDEX idx_created (created_at)
);

-- Email Log
CREATE TABLE IF NOT EXISTS email_log (
  id INT PRIMARY KEY AUTO_INCREMENT,
  invoice_id INT,
  customer_id INT,
  to_email VARCHAR(100),
  subject VARCHAR(255),
  type ENUM('invoice','reminder','statement','quote') DEFAULT 'invoice',
  tracking_token VARCHAR(64) UNIQUE,
  sent_at DATETIME,
  opened_at DATETIME,
  bounced_at DATETIME,
  status ENUM('sent','opened','bounced','failed') DEFAULT 'sent',
  INDEX idx_invoice (invoice_id),
  INDEX idx_token (tracking_token)
);

-- Invoice number sequences
CREATE TABLE IF NOT EXISTS invoice_sequences (
  store_code VARCHAR(20) PRIMARY KEY,
  last_invoice_number INT DEFAULT 1000,
  last_quote_number INT DEFAULT 1000
);
INSERT IGNORE INTO invoice_sequences (store_code) VALUES ('falcarragh'), ('gweedore');

-- Offline sync queue
CREATE TABLE IF NOT EXISTS sync_queue (
  id INT PRIMARY KEY AUTO_INCREMENT,
  device_id VARCHAR(64),
  action VARCHAR(50),
  entity_type VARCHAR(50),
  entity_id VARCHAR(50),
  payload JSON,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  processed_at TIMESTAMP NULL,
  INDEX idx_device (device_id),
  INDEX idx_processed (processed_at)
);