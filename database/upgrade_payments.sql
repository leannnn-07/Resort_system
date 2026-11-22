-- Upgrade existing database to support GCash & Maya downpayments with staff reference-number verification.
-- Safe to run more than once on MariaDB 10.2+ (XAMPP).

ALTER TABLE resort
  ADD COLUMN IF NOT EXISTS online_payment_enabled TINYINT(1) NOT NULL DEFAULT 1 AFTER allow_cancellation,
  ADD COLUMN IF NOT EXISTS downpayment_percent    TINYINT UNSIGNED NOT NULL DEFAULT 50 AFTER online_payment_enabled,
  ADD COLUMN IF NOT EXISTS gcash_name             VARCHAR(100) NULL AFTER downpayment_percent,
  ADD COLUMN IF NOT EXISTS gcash_number           VARCHAR(30)  NULL AFTER gcash_name,
  ADD COLUMN IF NOT EXISTS maya_name              VARCHAR(100) NULL AFTER gcash_number,
  ADD COLUMN IF NOT EXISTS maya_number            VARCHAR(30)  NULL AFTER maya_name;

ALTER TABLE reservation
  ADD COLUMN IF NOT EXISTS required_downpayment DECIMAL(12,2) NOT NULL DEFAULT 0.00 AFTER total_amount;

ALTER TABLE payment
  ADD COLUMN IF NOT EXISTS reference_no         VARCHAR(80)  NULL AFTER payment_status,
  ADD COLUMN IF NOT EXISTS sender_name          VARCHAR(100) NULL AFTER reference_no,
  ADD COLUMN IF NOT EXISTS proof_image          VARCHAR(255) NULL AFTER sender_name,
  ADD COLUMN IF NOT EXISTS source               VARCHAR(20)  NOT NULL DEFAULT 'Staff' AFTER sender_name,
  ADD COLUMN IF NOT EXISTS verified_at          DATETIME     NULL AFTER source,
  ADD COLUMN IF NOT EXISTS verified_by_staff_id INT          NULL AFTER verified_at,
  ADD COLUMN IF NOT EXISTS verification_note    VARCHAR(255) NULL AFTER verified_by_staff_id,
  ADD COLUMN IF NOT EXISTS recorded_by_staff_id INT          NULL AFTER verification_note;

CREATE INDEX IF NOT EXISTS idx_pay_ref ON payment (method, reference_no);
