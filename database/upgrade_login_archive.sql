-- Upgrade for an EXISTING resort_db: login on/off switch and archive for staff and guest accounts.
-- The site also adds these columns by itself the first time it runs, so this file is only needed if you prefer to do it by hand.
-- Run once in phpMyAdmin > SQL with resort_db selected. New installs do not need this (schema.sql has it).

ALTER TABLE staff ADD COLUMN login_enabled TINYINT(1) NOT NULL DEFAULT 1, ADD COLUMN archived_at DATETIME NULL;
ALTER TABLE guest ADD COLUMN login_enabled TINYINT(1) NOT NULL DEFAULT 1, ADD COLUMN archived_at DATETIME NULL;
