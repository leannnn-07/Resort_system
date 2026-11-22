-- Upgrade for an EXISTING resort_db: guests are no longer asked for an exact age.
-- Run once in phpMyAdmin > SQL with resort_db selected. New installs do not need this (schema.sql has it).
-- Until you run it, bookings still work (the site stores 0 in the old age column).

ALTER TABLE reservation_companion
  DROP CONSTRAINT IF EXISTS ck_rc_age,
  MODIFY age INT NULL,
  ADD CONSTRAINT ck_rc_age CHECK (age IS NULL OR age BETWEEN 0 AND 120);
