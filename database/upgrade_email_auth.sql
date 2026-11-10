-- Upgrade for an EXISTING resort_db: adds the staff password-reset table (email features).
-- Run once in phpMyAdmin > SQL with resort_db selected. New installs do not need this (schema.sql has it).

CREATE TABLE IF NOT EXISTS password_reset (
  reset_id   INT AUTO_INCREMENT PRIMARY KEY,
  staff_id   INT NOT NULL,
  token_hash CHAR(64) NOT NULL,
  expires_at DATETIME NOT NULL,
  used_at    DATETIME NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT uk_reset_token UNIQUE (token_hash),
  CONSTRAINT fk_reset_staff FOREIGN KEY (staff_id) REFERENCES staff(staff_id) ON DELETE CASCADE
) ENGINE=InnoDB;
