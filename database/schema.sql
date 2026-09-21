-- ============================================================
-- Small Restaurant POS (Tea & Food) -- schema + seed data
-- Target: MariaDB 10.4 / MySQL 5.7+   Engine: InnoDB / utf8mb4
-- Import:  mysql -u root < database/schema.sql
-- ============================================================

DROP DATABASE IF EXISTS smallrest;
CREATE DATABASE smallrest DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE smallrest;

-- ============================================================
-- Multi-company (SaaS) tables. Every business table below carries
-- company_id and every query filters on it. Usernames stay unique
-- across the whole platform: login finds the company from the user.
-- ============================================================

-- ---------- plans: what a company pays for and what it may use ----------
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

-- ---------- companies: one restaurant each ----------
CREATE TABLE companies (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  name          VARCHAR(100) NOT NULL,
  slug          VARCHAR(40)  NOT NULL,
  phone         VARCHAR(40)  NULL,
  email         VARCHAR(120) NULL,
  plan_id       INT UNSIGNED NOT NULL,
  -- trial: free until trial_ends_at; active: paid until paid_until (NULL = no expiry)
  status        ENUM('trial','active','suspended') NOT NULL DEFAULT 'trial',
  trial_ends_at DATE         NULL,
  paid_until    DATE         NULL,
  -- per-company receipt counter, so each restaurant numbers its own bills
  order_seq     INT UNSIGNED NOT NULL DEFAULT 0,
  created_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_companies_slug (slug),
  CONSTRAINT fk_companies_plan FOREIGN KEY (plan_id) REFERENCES plans(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------- platform_admins: the owner of the platform (not a restaurant user) ----------
CREATE TABLE platform_admins (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  full_name     VARCHAR(100) NOT NULL,
  username      VARCHAR(50)  NOT NULL,
  password_hash VARCHAR(255) NOT NULL,
  is_active     TINYINT(1)   NOT NULL DEFAULT 1,
  created_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_platform_admins_username (username)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------- company_payments: recorded by the platform owner ----------
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

-- ---------- settings: shop-level config, one set per company ----------
CREATE TABLE settings (
  company_id    INT UNSIGNED NOT NULL,
  setting_key   VARCHAR(50)  NOT NULL,
  setting_value TEXT         NULL,
  PRIMARY KEY (company_id, setting_key),
  CONSTRAINT fk_settings_company FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------- users ----------
CREATE TABLE users (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  company_id    INT UNSIGNED NOT NULL,
  full_name     VARCHAR(100) NOT NULL,
  username      VARCHAR(50)  NOT NULL,
  password_hash VARCHAR(255) NOT NULL,
  role          ENUM('admin','cashier','waiter','kitchen') NOT NULL DEFAULT 'cashier',
  is_active     TINYINT(1)   NOT NULL DEFAULT 1,
  created_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_users_username (username),
  KEY ix_users_role (role),
  KEY ix_users_company (company_id),
  CONSTRAINT fk_users_company FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------- menu ----------
CREATE TABLE categories (
  id         INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  company_id    INT UNSIGNED NOT NULL,
  name       VARCHAR(60)  NOT NULL,
  sort_order INT          NOT NULL DEFAULT 0,
  is_active  TINYINT(1)   NOT NULL DEFAULT 1,
  UNIQUE KEY uq_categories_name (company_id, name),
  CONSTRAINT fk_categories_company FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE menu_items (
  id           INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  company_id    INT UNSIGNED NOT NULL,
  category_id  INT UNSIGNED NOT NULL,
  name         VARCHAR(100) NOT NULL,
  price        DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  cost_price   DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  -- kitchen: does this item need to be cooked/prepared and shown on the kitchen screen?
  needs_prep   TINYINT(1)   NOT NULL DEFAULT 1,
  is_available TINYINT(1)   NOT NULL DEFAULT 1,
  sort_order   INT          NOT NULL DEFAULT 0,
  created_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY ix_items_company (company_id),
  KEY ix_items_category (category_id),
  KEY ix_items_available (is_available),
  CONSTRAINT fk_items_category FOREIGN KEY (category_id) REFERENCES categories(id) ON DELETE RESTRICT,
  CONSTRAINT fk_items_company FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------- shifts (cash drawer) ----------
CREATE TABLE shifts (
  id              INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  company_id    INT UNSIGNED NOT NULL,
  user_id         INT UNSIGNED NOT NULL,
  opened_at       DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  opening_float   DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  closed_at       DATETIME     NULL,
  counted_cash    DECIMAL(10,2) NULL,
  expected_cash   DECIMAL(10,2) NULL,
  variance        DECIMAL(10,2) NULL,
  note            VARCHAR(255) NULL,
  status          ENUM('open','closed') NOT NULL DEFAULT 'open',
  KEY ix_shifts_company (company_id, status),
  KEY ix_shifts_user_status (user_id, status),
  CONSTRAINT fk_shifts_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE RESTRICT,
  CONSTRAINT fk_shifts_company FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------- orders ----------
CREATE TABLE orders (
  id             INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  company_id    INT UNSIGNED NOT NULL,
  order_no       VARCHAR(20)  NULL,
  order_type     ENUM('dine_in','takeaway') NOT NULL DEFAULT 'dine_in',
  table_label    VARCHAR(30)  NULL,
  status         ENUM('open','paid','void') NOT NULL DEFAULT 'open',
  subtotal       DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  discount       DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  -- VAT rate (%) the order was charged at; tax = VAT amount, added on top
  vat_rate       DECIMAL(5,2)  NOT NULL DEFAULT 0.00,
  tax            DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  total          DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  paid_amount    DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  change_amount  DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  payment_method ENUM('cash','mobile','card') NULL,
  note           VARCHAR(255) NULL,
  created_by     INT UNSIGNED NOT NULL,
  paid_by        INT UNSIGNED NULL,
  shift_id       INT UNSIGNED NULL,
  created_at     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  -- last time items were added to or removed from the open order
  updated_at     DATETIME     NULL,
  paid_at        DATETIME     NULL,
  voided_at      DATETIME     NULL,
  void_reason    VARCHAR(255) NULL,
  UNIQUE KEY uq_orders_no (company_id, order_no),
  KEY ix_orders_status (company_id, status),
  KEY ix_orders_created (company_id, created_at),
  KEY ix_orders_paid (company_id, paid_at),
  KEY ix_orders_shift (shift_id),
  CONSTRAINT fk_orders_creator FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE RESTRICT,
  CONSTRAINT fk_orders_payer   FOREIGN KEY (paid_by)    REFERENCES users(id) ON DELETE SET NULL,
  CONSTRAINT fk_orders_shift   FOREIGN KEY (shift_id)   REFERENCES shifts(id) ON DELETE SET NULL,
  CONSTRAINT fk_orders_company FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE order_items (
  id           INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  company_id    INT UNSIGNED NOT NULL,
  order_id     INT UNSIGNED NOT NULL,
  -- 1 = original order, 2+ = items added to the open order later
  round        TINYINT UNSIGNED NOT NULL DEFAULT 1,
  menu_item_id INT UNSIGNED NULL,
  -- name/price are copied at sale time so history survives menu edits
  item_name    VARCHAR(100) NOT NULL,
  unit_price   DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  unit_cost    DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  qty          INT UNSIGNED NOT NULL DEFAULT 1,
  line_total   DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  note         VARCHAR(120) NULL,
  kitchen_status ENUM('pending','preparing','served') NOT NULL DEFAULT 'pending',
  needs_prep   TINYINT(1)   NOT NULL DEFAULT 1,
  -- when this line was added; the kitchen times an added round from here
  created_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY ix_oi_order (order_id),
  KEY ix_oi_kitchen (company_id, kitchen_status),
  CONSTRAINT fk_oi_order FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE,
  CONSTRAINT fk_oi_item  FOREIGN KEY (menu_item_id) REFERENCES menu_items(id) ON DELETE SET NULL,
  CONSTRAINT fk_oi_company FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------- expenses ----------
CREATE TABLE expenses (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  company_id    INT UNSIGNED NOT NULL,
  spent_on    DATE         NOT NULL,
  category    VARCHAR(60)  NOT NULL DEFAULT 'General',
  description VARCHAR(255) NOT NULL,
  amount      DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  paid_from   ENUM('drawer','other') NOT NULL DEFAULT 'drawer',
  shift_id    INT UNSIGNED NULL,
  user_id     INT UNSIGNED NOT NULL,
  created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY ix_exp_date (company_id, spent_on),
  KEY ix_exp_shift (shift_id),
  CONSTRAINT fk_exp_shift FOREIGN KEY (shift_id) REFERENCES shifts(id) ON DELETE SET NULL,
  CONSTRAINT fk_exp_user  FOREIGN KEY (user_id)  REFERENCES users(id)  ON DELETE RESTRICT,
  CONSTRAINT fk_exp_company FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- Seed data
-- ============================================================

INSERT INTO plans (id, name, price_month, max_users, max_menu_items, sort_order) VALUES
  (1, 'Trial',  0.00,  3,    40,   1),
  (2, 'Basic', 10.00,  5,    150,  2),
  (3, 'Pro',   25.00,  NULL, NULL, 3);

-- Platform owner login for /platform/  (CHANGE THIS PASSWORD)
--   owner / platform123
INSERT INTO platform_admins (full_name, username, password_hash) VALUES
  ('Platform Owner', 'owner', '$2y$10$n5SnDbrNq.KeCK08RKet0.yF/2Eswq690Ji/.Znx9MRrTdrb9efem');

-- Demo restaurant. Paid plan, no expiry, so the seeded logins always work.
INSERT INTO companies (id, name, slug, plan_id, status, trial_ends_at, paid_until) VALUES
  (1, 'Small Restaurant', 'main', 3, 'active', NULL, NULL);

INSERT INTO settings (company_id, setting_key, setting_value) VALUES
  (1, 'shop_name',      'Small Restaurant'),
  (1, 'shop_tagline',   'Tea & Food'),
  (1, 'currency',       '$'),
  (1, 'tax_percent',    '0'),
  (1, 'receipt_footer', 'Thank you — come again!'),
  (1, 'shop_phone',     ''),
  (1, 'shop_address',   ''),
  -- Mobile money: bills and receipts print <prefix><merchant>*<amount>#
  -- e.g. *789*123456*6.00#  Blank merchant_id = nothing is printed.
  (1, 'merchant_name',       ''),
  (1, 'merchant_id',         ''),
  (1, 'ussd_prefix',         '*789*'),
  (1, 'merchant_on_receipt', '1');

-- Default logins (CHANGE THESE PASSWORDS after first login)
--   admin / admin123      cashier / cashier123
--   waiter / waiter123    kitchen / kitchen123
INSERT INTO users (company_id, full_name, username, password_hash, role) VALUES
  (1, 'System Administrator', 'admin',   '$2y$10$tKrPYcMFOY74w2zhx29aEewwOAvV6rlq9fjkYsAiASAmakHmyOM4S', 'admin'),
  (1, 'Front Cashier',        'cashier', '$2y$10$NODGHg3xlq.i1.Jcb2lbEeNxzsisRJ81iKSS7rUkMEnoTX2sTDLX.', 'cashier'),
  (1, 'Floor Waiter',         'waiter',  '$2y$10$1osTS0htBN5voYr2h0upP.a8HNNkqcLZXhByA.vWOsnDWw03t5bAi', 'waiter'),
  (1, 'Kitchen Station',      'kitchen', '$2y$10$du.Cjvwz3v0A6GmVh9Z8MOwknRrCbYeJT/uGlIhiYc7Dg/IYrzUQW', 'kitchen');

INSERT INTO categories (id, company_id, name, sort_order) VALUES
  (1, 1, 'Tea & Hot Drinks', 1),
  (2, 1, 'Cold Drinks',      2),
  (3, 1, 'Breakfast',        3),
  (4, 1, 'Main Dishes',      4),
  (5, 1, 'Snacks',           5);
INSERT INTO menu_items (company_id, category_id, name, price, cost_price, needs_prep, sort_order) VALUES
  (1, 1, 'Shaah Cadeys (Milk Tea)', 0.50, 0.20, 1, 1),
  (1, 1, 'Black Tea',               0.30, 0.10, 1, 2),
  (1, 1, 'Coffee',                  1.00, 0.40, 1, 3),
  (1, 1, 'Spiced Tea',              0.70, 0.25, 1, 4),
  (1, 2, 'Bottled Water',           0.50, 0.30, 0, 1),
  (1, 2, 'Soft Drink',              1.00, 0.60, 0, 2),
  (1, 2, 'Fresh Mango Juice',       1.50, 0.70, 1, 3),
  (1, 3, 'Canjeero with Tea',       1.50, 0.60, 1, 1),
  (1, 3, 'Omelette',                2.00, 0.90, 1, 2),
  (1, 3, 'Malawax',                 1.00, 0.40, 1, 3),
  (1, 4, 'Rice with Beef',          4.00, 2.00, 1, 1),
  (1, 4, 'Rice with Chicken',       4.50, 2.20, 1, 2),
  (1, 4, 'Pasta with Meat',         4.00, 1.90, 1, 3),
  (1, 4, 'Fish with Rice',          5.00, 2.60, 1, 4),
  (1, 5, 'Sambusa',                 0.50, 0.20, 1, 1),
  (1, 5, 'Bur (Doughnut)',          0.30, 0.10, 0, 2);
