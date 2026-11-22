-- Adds the per-resort switch for online cancellation. Safe to run once.
-- (The website also does this by itself the first time it runs, so importing this file is optional.)
ALTER TABLE resort ADD COLUMN allow_cancellation TINYINT(1) NOT NULL DEFAULT 1 AFTER free_cancel_days;
