-- ============================================================
-- Migration 005 — Payment Requests
-- Restaurants can submit payment notifications from their
-- Billing page; the platform owner reviews and approves them,
-- which then records the payment and extends the subscription.
-- ============================================================

USE smallrest;

CREATE TABLE IF NOT EXISTS payment_requests (
  id           INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  company_id   INT UNSIGNED NOT NULL,
  plan_id      INT UNSIGNED NOT NULL,
  months       INT UNSIGNED NOT NULL DEFAULT 1,
  amount       DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  method       VARCHAR(30)  NOT NULL DEFAULT 'mobile',
  reference    VARCHAR(100) NULL,
  note         VARCHAR(255) NULL,
  -- pending: waiting for platform owner to review
  -- approved: platform owner recorded the payment
  -- dismissed: platform owner rejected / ignored
  status       ENUM('pending','approved','dismissed') NOT NULL DEFAULT 'pending',
  submitted_by INT UNSIGNED NULL,        -- users.id (admin who submitted)
  reviewed_by  INT UNSIGNED NULL,        -- platform_admins.id
  reviewed_at  DATETIME     NULL,
  created_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY ix_pr_company (company_id),
  KEY ix_pr_status  (status),
  CONSTRAINT fk_pr_company FOREIGN KEY (company_id)   REFERENCES companies(id)        ON DELETE CASCADE,
  CONSTRAINT fk_pr_plan    FOREIGN KEY (plan_id)       REFERENCES plans(id)            ON DELETE RESTRICT,
  CONSTRAINT fk_pr_user    FOREIGN KEY (submitted_by)  REFERENCES users(id)            ON DELETE SET NULL,
  CONSTRAINT fk_pr_admin   FOREIGN KEY (reviewed_by)   REFERENCES platform_admins(id)  ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
