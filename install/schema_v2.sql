-- Easy Builders Merchant Pro — Schema V2 Additions
-- Run this AFTER schema.sql to add/update tables for full feature support.
SET NAMES utf8mb4;

-- Email log table (extended version with type, opened tracking, customer link)
CREATE TABLE IF NOT EXISTS email_log (
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
);

-- Stock movements table
CREATE TABLE IF NOT EXISTS tbl_stock (
  id INT PRIMARY KEY AUTO_INCREMENT,
  product_id INT NOT NULL,
  quantity_change DECIMAL(10,3) NOT NULL,
  reason VARCHAR(100),
  adjusted_by INT,
  adjusted_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_product (product_id)
);

-- Audit log table (extended version)
CREATE TABLE IF NOT EXISTS audit_log (
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
);

-- Rate limit table (general-purpose request throttling)
CREATE TABLE IF NOT EXISTS rate_limit (
  id INT PRIMARY KEY AUTO_INCREMENT,
  ip_address VARCHAR(45) NOT NULL,
  action VARCHAR(50) NOT NULL,
  window_start TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_ip_action (ip_address, action),
  INDEX idx_window (window_start)
);

-- Quotes / Estimates
CREATE TABLE IF NOT EXISTS quotes (
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
);

CREATE TABLE IF NOT EXISTS quote_items (
  id INT PRIMARY KEY AUTO_INCREMENT,
  quote_id INT NOT NULL,
  product_id INT,
  description VARCHAR(255) NOT NULL,
  quantity DECIMAL(10,3) DEFAULT 1,
  unit_price DECIMAL(10,2) NOT NULL,
  vat_rate DECIMAL(5,2) DEFAULT 23.00,
  line_total DECIMAL(10,2) NOT NULL,
  FOREIGN KEY (quote_id) REFERENCES quotes(id) ON DELETE CASCADE,
  INDEX idx_quote (quote_id)
);

-- Recurring Invoices
CREATE TABLE IF NOT EXISTS recurring_invoices (
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
  INDEX idx_next_run (next_run_date, active)
);

CREATE TABLE IF NOT EXISTS recurring_invoice_items (
  id INT PRIMARY KEY AUTO_INCREMENT,
  recurring_id INT NOT NULL,
  product_id INT,
  description VARCHAR(255) NOT NULL,
  quantity DECIMAL(10,3) DEFAULT 1,
  unit_price DECIMAL(10,2) NOT NULL,
  vat_rate DECIMAL(5,2) DEFAULT 23.00,
  FOREIGN KEY (recurring_id) REFERENCES recurring_invoices(id) ON DELETE CASCADE
);

-- Expenses
CREATE TABLE IF NOT EXISTS expenses (
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
);
