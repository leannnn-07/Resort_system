-- Upgrade for an EXISTING resort_db: lets guests create an online account (email code + password).
-- Run once in phpMyAdmin > SQL with resort_db selected. New installs do not need this (schema.sql has it).
-- Safe to run more than once (needs MariaDB, which XAMPP uses).

ALTER TABLE guest ADD COLUMN IF NOT EXISTS password_hash VARCHAR(255) NULL;
ALTER TABLE guest ADD COLUMN IF NOT EXISTS email_verified_at DATETIME NULL;
