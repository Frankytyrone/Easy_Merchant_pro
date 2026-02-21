-- Easy Builders Merchant Pro — Database Schema
SET NAMES utf8mb4;
SET time_zone = '+00:00';
SET foreign_key_checks = 0;

-- Stores
CREATE TABLE IF NOT EXISTS stores (
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
);
INSERT IGNORE INTO stores (code, name) VALUES
  ('FAL', 'Easy Builders Merchant — Falcarragh'),
  ('GWE', 'Easy Builders Merchant — Gweedore');

-- Settings (key-value store)
CREATE TABLE IF NOT EXISTS settings (
  id INT PRIMARY KEY AUTO_INCREMENT,
  `key` VARCHAR(100) UNIQUE NOT NULL,
  value TEXT
);

-- Users
CREATE TABLE IF NOT EXISTS users (
  id INT PRIMARY KEY AUTO_INCREMENT,
  username VARCHAR(50) UNIQUE NOT NULL,
  password_hash VARCHAR(255) NOT NULL,
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
);

-- Products
CREATE TABLE IF NOT EXISTS products (
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
);

-- Invoice sequences (per store, for atomic numbering)
CREATE TABLE IF NOT EXISTS invoice_sequences (
  store_code VARCHAR(10) UNIQUE NOT NULL,
  next_invoice_num INT DEFAULT 1001,
  next_quote_num INT DEFAULT 1001
);
INSERT IGNORE INTO invoice_sequences (store_code) VALUES ('FAL'), ('GWE');

-- Invoices
CREATE TABLE IF NOT EXISTS invoices (
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
);

-- Invoice Line Items
CREATE TABLE IF NOT EXISTS invoice_items (
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
);

-- Payments
CREATE TABLE IF NOT EXISTS payments (
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
);

-- Email Log
CREATE TABLE IF NOT EXISTS email_log (
  id INT PRIMARY KEY AUTO_INCREMENT,
  invoice_id INT,
  customer_id INT,
  to_email VARCHAR(100),
  subject VARCHAR(255),
  sent_at DATETIME,
  opened_at DATETIME,
  status ENUM('sent','opened','bounced','failed') DEFAULT 'sent',
  tracking_token VARCHAR(64) UNIQUE,
  INDEX idx_invoice (invoice_id)
);

-- Audit Log
CREATE TABLE IF NOT EXISTS audit_log (
  id INT PRIMARY KEY AUTO_INCREMENT,
  user_id INT,
  username VARCHAR(100),
  store_code VARCHAR(10),
  action VARCHAR(50),
  table_name VARCHAR(50),
  record_id INT,
  old_value JSON,
  new_value JSON,
  ip_address VARCHAR(45),
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_table_record (table_name, record_id),
  INDEX idx_created (created_at)
);

-- Sync Queue (offline support)
CREATE TABLE IF NOT EXISTS sync_queue (
  id INT PRIMARY KEY AUTO_INCREMENT,
  client_id VARCHAR(64),
  action VARCHAR(50),
  table_name VARCHAR(50),
  record_id VARCHAR(50),
  payload JSON,
  queued_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  processed_at TIMESTAMP NULL,
  status ENUM('pending','done','error') DEFAULT 'pending',
  INDEX idx_status (status)
);

-- Stock movements (disabled by default; enabled via settings)
CREATE TABLE IF NOT EXISTS tbl_stock (
  id INT PRIMARY KEY AUTO_INCREMENT,
  product_id INT,
  store_code VARCHAR(10),
  movement_type ENUM('in','out','adjust') DEFAULT 'in',
  quantity DECIMAL(10,3),
  reference VARCHAR(100),
  notes TEXT,
  created_by INT,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_product (product_id),
  INDEX idx_store (store_code)
);