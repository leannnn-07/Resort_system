-- Upgrade for an EXISTING resort_db: guests get an ID type, and ID numbers are stored encrypted.
-- Run once in phpMyAdmin > SQL with resort_db selected. New installs do not need this (schema.sql has it).
-- Afterwards run:  php database/encrypt_ids.php   to encrypt ID numbers that were saved as plain text.

ALTER TABLE guest
  MODIFY id_number VARCHAR(255) NULL,
  ADD COLUMN id_type VARCHAR(40) NULL AFTER address;
