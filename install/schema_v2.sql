-- Easy Builders Merchant Pro — Schema v2 additions
-- Run after schema.sql and schema_additions.sql
-- Adds missing columns to email_log and ensures all required tables exist

SET NAMES utf8mb4;

-- Ensure email_log has all required columns
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
  status ENUM('sent','opened','bounced','failed') DEFAULT 'sent',
  tracking_token VARCHAR(64) UNIQUE,
  INDEX idx_invoice (invoice_id),
  INDEX idx_token (tracking_token)
);

-- Add missing columns to email_log if upgrading from v1
ALTER TABLE email_log
  ADD COLUMN IF NOT EXISTS customer_id INT DEFAULT NULL AFTER invoice_id,
  ADD COLUMN IF NOT EXISTS type ENUM('invoice','reminder','statement','payment_link') DEFAULT 'invoice' AFTER subject,
  ADD COLUMN IF NOT EXISTS opened_count INT DEFAULT 0 AFTER sent_at,
  ADD COLUMN IF NOT EXISTS last_opened_at TIMESTAMP NULL AFTER opened_count,
  ADD INDEX IF NOT EXISTS idx_token (tracking_token);

-- Stock movements table (separate from products.stock_qty for audit trail)
CREATE TABLE IF NOT EXISTS tbl_stock (
  id INT PRIMARY KEY AUTO_INCREMENT,
  product_id INT NOT NULL,
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

-- Audit log
CREATE TABLE IF NOT EXISTS audit_log (
  id INT PRIMARY KEY AUTO_INCREMENT,
  user_id INT,
  username VARCHAR(100),
  store_code VARCHAR(10),
  action VARCHAR(100) NOT NULL,
  table_name VARCHAR(50),
  record_id INT,
  old_value JSON,
  new_value JSON,
  ip_address VARCHAR(45),
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_user (user_id),
  INDEX idx_action (action),
  INDEX idx_created (created_at)
);
