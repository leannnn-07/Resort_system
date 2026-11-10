-- Upgrade for an EXISTING resort_db that already has the cottages and price list.
-- Run once in phpMyAdmin > SQL with resort_db selected. New installs do not need this (schema.sql has it all).
-- If it stops with an error about a CHECK constraint, an old row has a value the rules don't allow: fix that row first.

CREATE TABLE IF NOT EXISTS rate_limit (
  rate_limit_id INT AUTO_INCREMENT PRIMARY KEY,
  scope         VARCHAR(20) NOT NULL,
  ip            VARCHAR(45) NOT NULL,
  created_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_rate_lookup (scope, ip, created_at)
) ENGINE=InnoDB;

ALTER TABLE guest       ADD COLUMN created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP;
ALTER TABLE reservation ADD COLUMN created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP;
ALTER TABLE payment     ADD COLUMN created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP;

ALTER TABLE room ADD CONSTRAINT ck_room_status CHECK (status IN ('Available','Cleaning','Maintenance'));
ALTER TABLE reservation
  ADD CONSTRAINT ck_res_status CHECK (status IN ('Pending','Confirmed','Checked-in','Checked-out','Cancelled')),
  ADD CONSTRAINT ck_res_source CHECK (source IN ('Online','Staff')),
  ADD CONSTRAINT ck_res_total  CHECK (total_amount >= 0),
  ADD INDEX idx_res_status (status);
ALTER TABLE reservation_room ADD CONSTRAINT ck_rr_rate CHECK (rate_applied >= 0);
ALTER TABLE payment ADD CONSTRAINT ck_pay_status CHECK (payment_status IN ('Paid','Pending','Refunded','Voided'));
