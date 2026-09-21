-- ============================================================
-- Migration 002 — editable open orders
-- Run ONCE on an existing install (back up first):
--   mysqldump -u root smallrest > backups/smallrest_before_002.sql
--   mysql -u root smallrest < db_Migration/002_edit_orders.sql
-- Do NOT re-import database/schema.sql on a live install: it drops the database.
-- ============================================================

-- round: 1 for the original order, 2+ for items added later (kitchen shows it).
-- created_at: when this line was added, so the kitchen times an added round
-- from when it was ordered, not from when the table first sat down.
ALTER TABLE order_items
  ADD COLUMN round      TINYINT UNSIGNED NOT NULL DEFAULT 1 AFTER order_id,
  ADD COLUMN created_at DATETIME NULL AFTER needs_prep;

-- Existing lines were all ordered with their order.
UPDATE order_items oi
  JOIN orders o ON o.id = oi.order_id
   SET oi.created_at = o.created_at;

ALTER TABLE order_items
  MODIFY created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP;

-- Last time items were added to or removed from the order.
ALTER TABLE orders
  ADD COLUMN updated_at DATETIME NULL AFTER created_at;
