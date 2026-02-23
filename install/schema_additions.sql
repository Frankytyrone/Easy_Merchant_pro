-- Easy Builders Merchant Pro — Schema Additions
-- Run this AFTER the main schema.sql to add columns required by the import system
--
-- Note: ADD COLUMN IF NOT EXISTS requires MySQL 8.0.27+ or MariaDB 10.3.3+
-- For older versions, check column existence manually before running this file
-- or use the web installer which handles this via PHP/PDO.
SET NAMES utf8mb4;

-- Add barcode column and legacy_id to products if not present
ALTER TABLE products
  ADD COLUMN IF NOT EXISTS barcode VARCHAR(100) DEFAULT NULL AFTER product_code,
  ADD COLUMN IF NOT EXISTS stock_qty DECIMAL(10,3) DEFAULT 0.000 AFTER vat_rate,
  ADD COLUMN IF NOT EXISTS legacy_id INT DEFAULT NULL AFTER id,
  ADD INDEX IF NOT EXISTS idx_barcode (barcode),
  ADD INDEX IF NOT EXISTS idx_legacy (legacy_id);

-- Add legacy_id and extended delivery address to customers
ALTER TABLE customers
  ADD COLUMN IF NOT EXISTS legacy_id INT DEFAULT NULL AFTER id,
  ADD COLUMN IF NOT EXISTS phone VARCHAR(20) DEFAULT NULL AFTER inv_telephone,
  ADD COLUMN IF NOT EXISTS phone2 VARCHAR(20) DEFAULT NULL AFTER phone,
  ADD COLUMN IF NOT EXISTS town VARCHAR(50) DEFAULT NULL AFTER inv_town,
  ADD COLUMN IF NOT EXISTS county VARCHAR(50) DEFAULT NULL AFTER inv_region,
  ADD COLUMN IF NOT EXISTS postcode VARCHAR(10) DEFAULT NULL AFTER inv_postcode,
  ADD COLUMN IF NOT EXISTS account_ref VARCHAR(50) DEFAULT NULL AFTER account_no,
  ADD COLUMN IF NOT EXISTS del_client_name VARCHAR(100) DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS del_contact_name VARCHAR(100) DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS del_address_1 VARCHAR(100) DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS del_address_2 VARCHAR(100) DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS del_address_3 VARCHAR(100) DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS del_town VARCHAR(50) DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS del_county VARCHAR(50) DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS del_postcode VARCHAR(10) DEFAULT NULL,
  ADD INDEX IF NOT EXISTS idx_cust_legacy (legacy_id);

-- Add legacy_id and extra fields to invoices
ALTER TABLE invoices
  ADD COLUMN IF NOT EXISTS legacy_id INT DEFAULT NULL AFTER id,
  ADD COLUMN IF NOT EXISTS payment_method VARCHAR(50) DEFAULT NULL AFTER payment_terms,
  ADD COLUMN IF NOT EXISTS vat_number VARCHAR(50) DEFAULT NULL AFTER vat_total,
  ADD COLUMN IF NOT EXISTS delivery_charge DECIMAL(10,2) DEFAULT 0.00 AFTER vat_number,
  ADD COLUMN IF NOT EXISTS is_quote TINYINT DEFAULT 0 AFTER delivery_charge,
  ADD COLUMN IF NOT EXISTS legacy_client_id INT DEFAULT NULL,
  ADD INDEX IF NOT EXISTS idx_inv_legacy (legacy_id);

-- Add legacy_id to payments
ALTER TABLE payments
  ADD COLUMN IF NOT EXISTS legacy_id INT DEFAULT NULL AFTER id,
  ADD COLUMN IF NOT EXISTS legacy_invoice_id INT DEFAULT NULL AFTER legacy_id,
  ADD INDEX IF NOT EXISTS idx_pay_legacy (legacy_id);

-- Settings for Stripe / Revolut / SumUp
INSERT IGNORE INTO settings (`key`, value) VALUES
  ('stripe_publishable_key', ''),
  ('stripe_secret_key', ''),
  ('revolut_api_key', ''),
  ('payment_gateway', 'stripe'),
  ('sumup_api_key', '');
