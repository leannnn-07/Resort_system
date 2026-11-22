-- Upgrade for an EXISTING resort_db: lets a guest book stays at several resorts in ONE checkout (a "trip").
-- Every stay is still its own booking at its own resort (each resort's staff see only their own); the stays of one
-- checkout share a trip code so the guest can open them all together.
-- Run once in phpMyAdmin > SQL with resort_db selected. New installs do not need this (schema.sql has it).
-- Safe to run more than once (needs MariaDB, which XAMPP uses). Until it is run, trips still book correctly but
-- the stays are not linked by a code in the database.

ALTER TABLE reservation ADD COLUMN IF NOT EXISTS trip_code VARCHAR(10) NULL;
ALTER TABLE reservation ADD INDEX IF NOT EXISTS idx_res_trip (trip_code);
