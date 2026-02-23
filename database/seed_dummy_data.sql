-- Easy Builders Merchant Pro — Dummy Seed Data
-- Run AFTER schema.sql and schema_additions.sql
-- Provides realistic Irish hardware/agricultural merchant test data

SET NAMES utf8mb4;
SET foreign_key_checks = 0;

-- ── Products (20 items — mix of hardware/farm/agricultural) ──────────────────
INSERT IGNORE INTO products (product_code, barcode, description, category, price, vat_rate, stock_qty, unit, active) VALUES
('TIM001', '5391234560001', 'Treated Timber 100x50x3600mm', 'Timber', 8.50, 23.00, 450.000, 'each', 1),
('TIM002', '5391234560002', 'Treated Timber 47x100x2400mm', 'Timber', 5.20, 23.00, 320.000, 'each', 1),
('NAI001', '5391234560010', 'Round Wire Nails 100mm 5kg Box', 'Fasteners', 14.99, 23.00, 85.000, 'box', 1),
('NAI002', '5391234560011', 'Galvanised Clout Nails 40mm 1kg', 'Fasteners', 4.50, 23.00, 120.000, 'bag', 1),
('SCR001', '5391234560020', 'Decking Screws Stainless 50mm 200pk', 'Fasteners', 9.99, 23.00, 200.000, 'pack', 1),
('CEM001', '5391234560030', 'Cement 25kg Bag', 'Aggregate & Cement', 7.80, 23.00, 600.000, 'bag', 1),
('SAN001', '5391234560031', 'Building Sand 25kg Bag', 'Aggregate & Cement', 4.20, 23.00, 800.000, 'bag', 1),
('PAI001', '5391234560040', 'Dulux Weathershield Masonry Paint White 10L', 'Paints', 54.99, 23.00, 40.000, 'tin', 1),
('PAI002', '5391234560041', 'Dulux Trade Vinyl Matt Brilliant White 10L', 'Paints', 38.50, 23.00, 30.000, 'tin', 1),
('PLU001', '5391234560050', '22mm Copper Pipe 3m Length', 'Plumbing', 18.75, 23.00, 95.000, 'length', 1),
('PLU002', '5391234560051', '15mm Copper Elbow 90 Degree', 'Plumbing', 1.25, 23.00, 500.000, 'each', 1),
('ELE001', '5391234560060', 'Cable 2.5mm Twin & Earth 25m Drum', 'Electrical', 45.00, 23.00, 25.000, 'drum', 1),
('INS001', '5391234560070', 'Rockwool Insulation 100mm 2.16m2 Roll', 'Insulation', 29.99, 23.00, 60.000, 'roll', 1),
('AGR001', '5391234560080', 'Beet Pulp Pellets 25kg Bag', 'Animal Feed', 12.50, 0.00, 150.000, 'bag', 1),
('AGR002', '5391234560081', 'Horse & Pony Nuts 25kg', 'Animal Feed', 18.99, 0.00, 200.000, 'bag', 1),
('AGR003', '5391234560082', 'Grass Seed Amenity Mix 20kg', 'Seeds', 32.00, 0.00, 45.000, 'bag', 1),
('FER001', '5391234560090', 'Calcium Ammonium Nitrate 25kg', 'Fertiliser', 28.50, 0.00, 120.000, 'bag', 1),
('TOO001', '5391234560100', 'Plasterer Trowel 16 inch Stainless Steel', 'Tools', 22.99, 23.00, 35.000, 'each', 1),
('TOO002', '5391234560101', 'Wheelbarrow 90L Galvanised Steel', 'Tools', 69.99, 23.00, 12.000, 'each', 1),
('GAT001', '5391234560110', 'Farm Gate 10ft Steel 5-Bar Galvanised', 'Fencing', 89.00, 23.00, 8.000, 'each', 1);

-- ── Customers (10 realistic Donegal businesses/individuals) ──────────────────
INSERT IGNORE INTO customers
  (account_no, company_name, contact_name, address_1, address_2, inv_town, inv_region, inv_postcode, email_address, inv_telephone, payment_terms, notes, store_id)
VALUES
('CUS-0001', 'Bonner''s Hardware Ltd', 'Seamus Bonner', '14 Main Street', NULL, 'Letterkenny', 'Co. Donegal', 'F92 PY62', 'seamus@bonnershardware.ie', '074 9124567', 'Strictly 30 days', 'Long-standing account customer', 1),
('CUS-0002', 'McGee Builders', 'Patrick McGee', 'Drumhome', NULL, 'Ballyshannon', 'Co. Donegal', 'F94 X2R1', 'pat@mcgeebuilders.ie', '071 9851234', 'Strictly 30 days', NULL, 1),
('CUS-0003', 'Sweeney Farm Supplies', 'Mary Sweeney', 'Crocknalaragagh', NULL, 'Falcarragh', 'Co. Donegal', 'F92 W680', 'mary@sweeneyfarm.ie', '074 9135890', 'Strictly 30 days', 'Regular bulk orders', 1),
('CUS-0004', 'Gallagher''s Agricultural', 'Declan Gallagher', '2 Industrial Estate', NULL, 'Dungloe', 'Co. Donegal', 'F94 HK22', 'declan@gallaghersag.ie', '074 9521456', 'Pro-Forma', NULL, 2),
('CUS-0005', 'O''Donnell Construction', 'Fionnuala O''Donnell', 'Glasserchoo Road', NULL, 'Gweedore', 'Co. Donegal', 'F92 DC34', 'info@odonnellconstruction.ie', '074 9531234', 'Strictly 30 days', NULL, 2),
('CUS-0006', 'Doherty Roofing', 'Brendan Doherty', 'Annagry', NULL, 'Annagry', 'Co. Donegal', 'F92 Y4X2', 'brendan@dohertyroofing.ie', '087 6543210', 'Strictly 30 days', 'Trade account', 2),
('CUS-0007', 'Maguire Home Improvements', 'Tony Maguire', '5 Rosemount Park', NULL, 'Falcarragh', 'Co. Donegal', 'F92 A123', 'tony@maguirehome.ie', '085 1234567', NULL, NULL, 1),
('CUS-0008', 'Coll''s Plant Hire', 'Cathal Coll', 'Meenlaragh', NULL, 'Gortahork', 'Co. Donegal', 'F92 X567', 'cathal@collsplant.ie', '074 9165432', 'Strictly 30 days', NULL, 1),
('CUS-0009', 'Friel''s Agri Store', 'Noel Friel', 'Cloughaneely Road', NULL, 'Falcarragh', 'Co. Donegal', 'F92 B890', 'noel@frielsagri.ie', '074 9136789', 'Strictly 30 days', 'Bulk fertiliser orders spring/autumn', 1),
('CUS-0010', 'Byrne Self Build', 'Siobhan Byrne', 'Magheraroarty', NULL, 'Letterkenny', 'Co. Donegal', 'F92 CC12', 'siobhan@byrneselfbuild.ie', '086 9876543', NULL, 'New build project', 1);

-- ── Invoice sequences (start just above old Easy Invoicing numbers) ───────────
UPDATE invoice_sequences SET next_invoice_num = 41808, next_quote_num = 1395 WHERE store_code = 'FAL';
UPDATE invoice_sequences SET next_invoice_num = 41808, next_quote_num = 1395 WHERE store_code = 'GWE';

-- ── Invoices (15 dummy invoices) ─────────────────────────────────────────────
INSERT IGNORE INTO invoices
  (invoice_number, invoice_type, store_code, customer_id, invoice_date, due_date, subtotal, vat_total, total, amount_paid, balance, status, payment_terms, notes)
VALUES
-- FAL invoices
('FAL-41807', 'invoice', 'FAL', 1, DATE_SUB(CURDATE(), INTERVAL 85 DAY), DATE_SUB(CURDATE(), INTERVAL 55 DAY), 280.00, 64.40, 344.40, 344.40, 0.00, 'paid', 'Strictly 30 days', NULL),
('FAL-41810', 'invoice', 'FAL', 2, DATE_SUB(CURDATE(), INTERVAL 60 DAY), DATE_SUB(CURDATE(), INTERVAL 30 DAY), 520.00, 119.60, 639.60, 639.60, 0.00, 'paid', 'Strictly 30 days', NULL),
('FAL-41815', 'invoice', 'FAL', 3, DATE_SUB(CURDATE(), INTERVAL 45 DAY), DATE_SUB(CURDATE(), INTERVAL 15 DAY), 145.00, 0.00, 145.00, 0.00, 145.00, 'overdue', 'Strictly 30 days', NULL),
('FAL-41820', 'invoice', 'FAL', 7, DATE_SUB(CURDATE(), INTERVAL 30 DAY), DATE_SUB(CURDATE(), INTERVAL 0 DAY), 89.99, 20.70, 110.69, 50.00, 60.69, 'part_paid', NULL, NULL),
('FAL-41825', 'invoice', 'FAL', 8, DATE_SUB(CURDATE(), INTERVAL 20 DAY), DATE_ADD(CURDATE(), INTERVAL 10 DAY), 375.50, 86.37, 461.87, 0.00, 461.87, 'pending', 'Strictly 30 days', NULL),
('FAL-41830', 'invoice', 'FAL', 9, DATE_SUB(CURDATE(), INTERVAL 10 DAY), DATE_ADD(CURDATE(), INTERVAL 20 DAY), 285.00, 0.00, 285.00, 285.00, 0.00, 'paid', 'Strictly 30 days', 'Fertiliser order'),
('FAL-Q-1393', 'quote', 'FAL', 10, DATE_SUB(CURDATE(), INTERVAL 5 DAY), DATE_ADD(CURDATE(), INTERVAL 25 DAY), 1250.00, 287.50, 1537.50, 0.00, 1537.50, 'draft', NULL, 'Self build project quote'),
('FAL-41835', 'invoice', 'FAL', 1, CURDATE(), DATE_ADD(CURDATE(), INTERVAL 30 DAY), 620.00, 142.60, 762.60, 0.00, 762.60, 'pending', 'Strictly 30 days', NULL),
-- GWE invoices
('GWE-41807', 'invoice', 'GWE', 4, DATE_SUB(CURDATE(), INTERVAL 75 DAY), DATE_SUB(CURDATE(), INTERVAL 45 DAY), 195.00, 44.85, 239.85, 239.85, 0.00, 'paid', 'Pro-Forma', NULL),
('GWE-41810', 'invoice', 'GWE', 5, DATE_SUB(CURDATE(), INTERVAL 55 DAY), DATE_SUB(CURDATE(), INTERVAL 25 DAY), 780.00, 179.40, 959.40, 400.00, 559.40, 'part_paid', 'Strictly 30 days', NULL),
('GWE-41815', 'invoice', 'GWE', 6, DATE_SUB(CURDATE(), INTERVAL 40 DAY), DATE_SUB(CURDATE(), INTERVAL 10 DAY), 340.00, 78.20, 418.20, 0.00, 418.20, 'overdue', 'Strictly 30 days', 'Roofing materials'),
('GWE-41820', 'invoice', 'GWE', 4, DATE_SUB(CURDATE(), INTERVAL 25 DAY), DATE_ADD(CURDATE(), INTERVAL 5 DAY), 162.50, 37.38, 199.88, 199.88, 0.00, 'paid', 'Pro-Forma', NULL),
('GWE-41825', 'invoice', 'GWE', 5, DATE_SUB(CURDATE(), INTERVAL 12 DAY), DATE_ADD(CURDATE(), INTERVAL 18 DAY), 450.00, 103.50, 553.50, 0.00, 553.50, 'pending', 'Strictly 30 days', NULL),
('GWE-41830', 'invoice', 'GWE', 6, DATE_SUB(CURDATE(), INTERVAL 3 DAY), DATE_ADD(CURDATE(), INTERVAL 27 DAY), 220.00, 50.60, 270.60, 0.00, 270.60, 'draft', 'Strictly 30 days', NULL),
('GWE-Q-1393', 'quote', 'GWE', 5, DATE_SUB(CURDATE(), INTERVAL 7 DAY), DATE_ADD(CURDATE(), INTERVAL 23 DAY), 950.00, 218.50, 1168.50, 0.00, 1168.50, 'draft', NULL, 'Extension project');

-- ── Invoice Line Items ────────────────────────────────────────────────────────
-- FAL-41807 (customer 1 — timber & nails)
INSERT IGNORE INTO invoice_items (invoice_id, line_order, product_code, description, vat_rate, quantity, unit_price, discount_pct, line_total, vat_amount)
SELECT id, 1, 'TIM001', 'Treated Timber 100x50x3600mm', 23.00, 20, 8.50, 0.00, 208.10, 38.10 FROM invoices WHERE invoice_number = 'FAL-41807' LIMIT 1;
INSERT IGNORE INTO invoice_items (invoice_id, line_order, product_code, description, vat_rate, quantity, unit_price, discount_pct, line_total, vat_amount)
SELECT id, 2, 'NAI001', 'Round Wire Nails 100mm 5kg Box', 23.00, 3, 14.99, 0.00, 55.13, 10.13 FROM invoices WHERE invoice_number = 'FAL-41807' LIMIT 1;
INSERT IGNORE INTO invoice_items (invoice_id, line_order, product_code, description, vat_rate, quantity, unit_price, discount_pct, line_total, vat_amount)
SELECT id, 3, 'SCR001', 'Decking Screws Stainless 50mm 200pk', 23.00, 5, 9.99, 0.00, 61.44, 11.44 FROM invoices WHERE invoice_number = 'FAL-41807' LIMIT 1;

-- FAL-41825 (customer 8 — plumbing)
INSERT IGNORE INTO invoice_items (invoice_id, line_order, product_code, description, vat_rate, quantity, unit_price, discount_pct, line_total, vat_amount)
SELECT id, 1, 'PLU001', '22mm Copper Pipe 3m Length', 23.00, 10, 18.75, 0.00, 230.63, 43.13 FROM invoices WHERE invoice_number = 'FAL-41825' LIMIT 1;
INSERT IGNORE INTO invoice_items (invoice_id, line_order, product_code, description, vat_rate, quantity, unit_price, discount_pct, line_total, vat_amount)
SELECT id, 2, 'PLU002', '15mm Copper Elbow 90 Degree', 23.00, 30, 1.25, 0.00, 46.13, 8.63 FROM invoices WHERE invoice_number = 'FAL-41825' LIMIT 1;
INSERT IGNORE INTO invoice_items (invoice_id, line_order, product_code, description, vat_rate, quantity, unit_price, discount_pct, line_total, vat_amount)
SELECT id, 3, 'ELE001', 'Cable 2.5mm Twin & Earth 25m Drum', 23.00, 4, 45.00, 0.00, 221.40, 41.40 FROM invoices WHERE invoice_number = 'FAL-41825' LIMIT 1;

-- FAL-41830 (customer 9 — animal feed/fertiliser — zero VAT)
INSERT IGNORE INTO invoice_items (invoice_id, line_order, product_code, description, vat_rate, quantity, unit_price, discount_pct, line_total, vat_amount)
SELECT id, 1, 'FER001', 'Calcium Ammonium Nitrate 25kg', 0.00, 10, 28.50, 0.00, 285.00, 0.00 FROM invoices WHERE invoice_number = 'FAL-41830' LIMIT 1;

-- GWE-41815 (customer 6 — roofing)
INSERT IGNORE INTO invoice_items (invoice_id, line_order, product_code, description, vat_rate, quantity, unit_price, discount_pct, line_total, vat_amount)
SELECT id, 1, 'INS001', 'Rockwool Insulation 100mm 2.16m2 Roll', 23.00, 6, 29.99, 5.00, 170.94, 31.94 FROM invoices WHERE invoice_number = 'GWE-41815' LIMIT 1;
INSERT IGNORE INTO invoice_items (invoice_id, line_order, product_code, description, vat_rate, quantity, unit_price, discount_pct, line_total, vat_amount)
SELECT id, 2, 'SCR001', 'Decking Screws Stainless 50mm 200pk', 23.00, 10, 9.99, 5.00, 116.38, 21.38 FROM invoices WHERE invoice_number = 'GWE-41815' LIMIT 1;
INSERT IGNORE INTO invoice_items (invoice_id, line_order, product_code, description, vat_rate, quantity, unit_price, discount_pct, line_total, vat_amount)
SELECT id, 3, 'PAI001', 'Dulux Weathershield Masonry Paint White 10L', 23.00, 2, 54.99, 0.00, 135.18, 25.18 FROM invoices WHERE invoice_number = 'GWE-41815' LIMIT 1;

-- ── Payments (10 dummy payments linked to paid/part-paid invoices) ─────────────
INSERT IGNORE INTO payments (invoice_id, payment_date, amount, method, reference, notes, created_at)
SELECT id, DATE_SUB(CURDATE(), INTERVAL 60 DAY), 344.40, 'bank_transfer', 'BTR-001', 'Full settlement', NOW() FROM invoices WHERE invoice_number = 'FAL-41807' LIMIT 1;

INSERT IGNORE INTO payments (invoice_id, payment_date, amount, method, reference, notes, created_at)
SELECT id, DATE_SUB(CURDATE(), INTERVAL 45 DAY), 639.60, 'bank_transfer', 'BTR-002', 'Full settlement', NOW() FROM invoices WHERE invoice_number = 'FAL-41810' LIMIT 1;

INSERT IGNORE INTO payments (invoice_id, payment_date, amount, method, reference, notes, created_at)
SELECT id, DATE_SUB(CURDATE(), INTERVAL 15 DAY), 50.00, 'cash', NULL, 'Part payment', NOW() FROM invoices WHERE invoice_number = 'FAL-41820' LIMIT 1;

INSERT IGNORE INTO payments (invoice_id, payment_date, amount, method, reference, notes, created_at)
SELECT id, DATE_SUB(CURDATE(), INTERVAL 8 DAY), 285.00, 'bank_transfer', 'BTR-003', NULL, NOW() FROM invoices WHERE invoice_number = 'FAL-41830' LIMIT 1;

INSERT IGNORE INTO payments (invoice_id, payment_date, amount, method, reference, notes, created_at)
SELECT id, DATE_SUB(CURDATE(), INTERVAL 65 DAY), 239.85, 'cash', NULL, 'Pro-forma paid before delivery', NOW() FROM invoices WHERE invoice_number = 'GWE-41807' LIMIT 1;

INSERT IGNORE INTO payments (invoice_id, payment_date, amount, method, reference, notes, created_at)
SELECT id, DATE_SUB(CURDATE(), INTERVAL 40 DAY), 400.00, 'bank_transfer', 'BTR-004', 'Partial payment received', NOW() FROM invoices WHERE invoice_number = 'GWE-41810' LIMIT 1;

INSERT IGNORE INTO payments (invoice_id, payment_date, amount, method, reference, notes, created_at)
SELECT id, DATE_SUB(CURDATE(), INTERVAL 20 DAY), 199.88, 'cash', NULL, NULL, NOW() FROM invoices WHERE invoice_number = 'GWE-41820' LIMIT 1;

INSERT IGNORE INTO payments (invoice_id, payment_date, amount, method, reference, notes, created_at)
SELECT id, DATE_SUB(CURDATE(), INTERVAL 2 DAY), 100.00, 'card', 'CARD-001', 'Card terminal', NOW() FROM invoices WHERE invoice_number = 'FAL-41835' LIMIT 1;

INSERT IGNORE INTO payments (invoice_id, payment_date, amount, method, reference, notes, created_at)
SELECT id, DATE_SUB(CURDATE(), INTERVAL 1 DAY), 200.00, 'cash', NULL, NULL, NOW() FROM invoices WHERE invoice_number = 'FAL-41835' LIMIT 1;

INSERT IGNORE INTO payments (invoice_id, payment_date, amount, method, reference, notes, created_at)
SELECT id, DATE_SUB(CURDATE(), INTERVAL 10 DAY), 199.88, 'other', 'STRIPE-abc123', 'Online Stripe payment', NOW() FROM invoices WHERE invoice_number = 'GWE-41820' LIMIT 1;

SET foreign_key_checks = 1;
