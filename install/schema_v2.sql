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
