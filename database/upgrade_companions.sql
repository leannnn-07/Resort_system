-- Upgrade for an EXISTING resort_db: adds the table that stores the other guests on each reservation.
-- Run once in phpMyAdmin > SQL with resort_db selected. New installs do not need this (schema.sql has it).

CREATE TABLE IF NOT EXISTS reservation_companion (
  companion_id   INT AUTO_INCREMENT PRIMARY KEY,
  reservation_id INT NOT NULL,
  guest_type     VARCHAR(10) NOT NULL,
  first_name     VARCHAR(60) NOT NULL,
  last_name      VARCHAR(60) NOT NULL,
  age            INT NOT NULL,
  CONSTRAINT ck_rc_type CHECK (guest_type IN ('Adult','Senior','Child')),
  CONSTRAINT ck_rc_age  CHECK (age BETWEEN 0 AND 120),
  CONSTRAINT fk_rc_res  FOREIGN KEY (reservation_id) REFERENCES reservation(reservation_id) ON DELETE CASCADE,
  INDEX idx_rc_res (reservation_id)
) ENGINE=InnoDB;

-- If you ran an earlier version of this file, the table only allowed Adult and Child. This widens it to allow Senior.
-- (Safe to run more than once. Needs MariaDB, which XAMPP uses; on MySQL 8 run only the two lines that match your version.)
ALTER TABLE reservation_companion
  DROP CONSTRAINT IF EXISTS ck_rc_type,
  ADD CONSTRAINT ck_rc_type CHECK (guest_type IN ('Adult','Senior','Child'));

-- Remember whether the person who booked is an Adult or a Senior.
ALTER TABLE reservation ADD COLUMN IF NOT EXISTS booker_type VARCHAR(10) NOT NULL DEFAULT 'Adult';
