-- ============================================================
-- Migration 004 — multi-company (SaaS)
-- Run ONCE on an existing install, after 002 and 003 (back up first):
--   mysqldump -u root smallrest > backups/smallrest_before_004.sql
--   mysql -u root smallrest < db_Migration/004_multi_tenant.sql
-- Do NOT re-import database/schema.sql on a live install: it drops the database.
--
-- The existing restaurant becomes company 1 (slug "main") on the Pro plan
-- with no expiry, so every current login keeps working unchanged.
-- ============================================================

-- ---------- new tables ----------
CREATE TABLE plans (
  id             INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  name           VARCHAR(50)  NOT NULL,
  price_month    DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  -- NULL = unlimited
  max_users      INT UNSIGNED NULL,
  max_menu_items INT UNSIGNED NULL,
  is_active      TINYINT(1)   NOT NULL DEFAULT 1,
  sort_order     INT          NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE companies (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  name          VARCHAR(100) NOT NULL,
  slug          VARCHAR(40)  NOT NULL,
  phone         VARCHAR(40)  NULL,
  email         VARCHAR(120) NULL,
  plan_id       INT UNSIGNED NOT NULL,
  status        ENUM('trial','active','suspended') NOT NULL DEFAULT 'trial',
  trial_ends_at DATE         NULL,
  paid_until    DATE         NULL,
  order_seq     INT UNSIGNED NOT NULL DEFAULT 0,
  created_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_companies_slug (slug),
  CONSTRAINT fk_companies_plan FOREIGN KEY (plan_id) REFERENCES plans(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE platform_admins (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  full_name     VARCHAR(100) NOT NULL,
  username      VARCHAR(50)  NOT NULL,
  password_hash VARCHAR(255) NOT NULL,
  is_active     TINYINT(1)   NOT NULL DEFAULT 1,
  created_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_platform_admins_username (username)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE company_payments (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  company_id  INT UNSIGNED NOT NULL,
  plan_id     INT UNSIGNED NOT NULL,
  amount      DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  months      INT UNSIGNED NOT NULL DEFAULT 1,
  period_from DATE         NOT NULL,
  period_to   DATE         NOT NULL,
  method      VARCHAR(30)  NOT NULL DEFAULT 'mobile',
  reference   VARCHAR(100) NULL,
  recorded_by INT UNSIGNED NULL,
  created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY ix_cp_company (company_id),
  CONSTRAINT fk_cp_company FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE,
  CONSTRAINT fk_cp_plan    FOREIGN KEY (plan_id)    REFERENCES plans(id)     ON DELETE RESTRICT,
  CONSTRAINT fk_cp_admin   FOREIGN KEY (recorded_by) REFERENCES platform_admins(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO plans (id, name, price_month, max_users, max_menu_items, sort_order) VALUES
  (1, 'Trial',  0.00,  3,    40,   1),
  (2, 'Basic', 10.00,  5,    150,  2),
  (3, 'Pro',   25.00,  NULL, NULL, 3);

-- owner / platform123  (CHANGE THIS PASSWORD)
INSERT INTO platform_admins (full_name, username, password_hash) VALUES
  ('Platform Owner', 'owner', '$2y$10$n5SnDbrNq.KeCK08RKet0.yF/2Eswq690Ji/.Znx9MRrTdrb9efem');

-- The current restaurant. order_seq continues from the highest order id so
-- new receipt numbers never collide with the ones already printed.
INSERT INTO companies (id, name, slug, plan_id, status, order_seq)
SELECT 1,
       COALESCE((SELECT NULLIF(setting_value, '') FROM settings WHERE setting_key = 'shop_name'), 'Small Restaurant'),
       'main', 3, 'active',
       COALESCE((SELECT MAX(id) FROM orders), 0);

-- ---------- company_id on every business table (existing rows -> company 1) ----------
ALTER TABLE settings
  ADD COLUMN company_id INT UNSIGNED NOT NULL DEFAULT 1 FIRST,
  DROP PRIMARY KEY,
  ADD PRIMARY KEY (company_id, setting_key),
  ADD CONSTRAINT fk_settings_company FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE;

-- Usernames stay globally unique: login finds the company from the user.
ALTER TABLE users
  ADD COLUMN company_id INT UNSIGNED NOT NULL DEFAULT 1 AFTER id,
  ADD KEY ix_users_company (company_id),
  ADD CONSTRAINT fk_users_company FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE;

ALTER TABLE categories
  ADD COLUMN company_id INT UNSIGNED NOT NULL DEFAULT 1 AFTER id,
  DROP INDEX uq_categories_name,
  ADD UNIQUE KEY uq_categories_name (company_id, name),
  ADD CONSTRAINT fk_categories_company FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE;

ALTER TABLE menu_items
  ADD COLUMN company_id INT UNSIGNED NOT NULL DEFAULT 1 AFTER id,
  ADD KEY ix_items_company (company_id),
  ADD CONSTRAINT fk_items_company FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE;

ALTER TABLE shifts
  ADD COLUMN company_id INT UNSIGNED NOT NULL DEFAULT 1 AFTER id,
  ADD KEY ix_shifts_company (company_id, status),
  ADD CONSTRAINT fk_shifts_company FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE;

ALTER TABLE orders
  ADD COLUMN company_id INT UNSIGNED NOT NULL DEFAULT 1 AFTER id,
  DROP INDEX uq_orders_no,
  DROP INDEX ix_orders_status,
  DROP INDEX ix_orders_created,
  DROP INDEX ix_orders_paid,
  ADD UNIQUE KEY uq_orders_no (company_id, order_no),
  ADD KEY ix_orders_status (company_id, status),
  ADD KEY ix_orders_created (company_id, created_at),
  ADD KEY ix_orders_paid (company_id, paid_at),
  ADD CONSTRAINT fk_orders_company FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE;

ALTER TABLE order_items
  ADD COLUMN company_id INT UNSIGNED NOT NULL DEFAULT 1 AFTER id,
  DROP INDEX ix_oi_kitchen,
  ADD KEY ix_oi_kitchen (company_id, kitchen_status),
  ADD CONSTRAINT fk_oi_company FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE;

ALTER TABLE expenses
  ADD COLUMN company_id INT UNSIGNED NOT NULL DEFAULT 1 AFTER id,
  DROP INDEX ix_exp_date,
  ADD KEY ix_exp_date (company_id, spent_on),
  ADD CONSTRAINT fk_exp_company FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE;

-- The existing restaurant is already set up: skip the dashboard's getting-started list.
INSERT INTO settings (company_id, setting_key, setting_value) VALUES (1, 'settings_saved_at', NOW())
  ON DUPLICATE KEY UPDATE setting_value = setting_value;

-- The DEFAULT 1 was only for back-filling; new rows must say which company they belong to.
ALTER TABLE settings    ALTER COLUMN company_id DROP DEFAULT;
ALTER TABLE users       ALTER COLUMN company_id DROP DEFAULT;
ALTER TABLE categories  ALTER COLUMN company_id DROP DEFAULT;
ALTER TABLE menu_items  ALTER COLUMN company_id DROP DEFAULT;
ALTER TABLE shifts      ALTER COLUMN company_id DROP DEFAULT;
ALTER TABLE orders      ALTER COLUMN company_id DROP DEFAULT;
ALTER TABLE order_items ALTER COLUMN company_id DROP DEFAULT;
ALTER TABLE expenses    ALTER COLUMN company_id DROP DEFAULT;
