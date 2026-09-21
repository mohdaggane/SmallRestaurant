-- ============================================================
-- Migration 003 — record the VAT rate on each order
-- Run ONCE on an existing install (back up first):
--   mysqldump -u root smallrest > backups/smallrest_before_003.sql
--   mysql -u root smallrest < db_Migration/003_vat_rate.sql
-- ============================================================

-- The rate the order was charged at, so a receipt reprinted after the shop
-- changes its VAT rate still says what the customer actually paid.
ALTER TABLE orders
  ADD COLUMN vat_rate DECIMAL(5,2) NOT NULL DEFAULT 0.00 AFTER discount;

-- Orders saved before this migration: derive the rate from what they were charged.
UPDATE orders
   SET vat_rate = ROUND(tax / (subtotal - discount) * 100, 2)
 WHERE tax > 0 AND subtotal - discount > 0;
