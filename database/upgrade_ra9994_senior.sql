-- =====================================================================
-- Philippine Senior Citizen Act (RA 9994), BIR RR 7-2010 & Data Privacy Act (RA 10173)
-- Database Migration: Pro-Rated VAT-Exempt Billing, Physical ID Verification & Auto-Purge
-- =====================================================================

USE resort_db;

ALTER TABLE reservation
  ADD COLUMN IF NOT EXISTS has_senior_claim TINYINT(1) NOT NULL DEFAULT 0 AFTER required_downpayment,
  ADD COLUMN IF NOT EXISTS claimed_senior_count INT NOT NULL DEFAULT 0 AFTER has_senior_claim,
  ADD COLUMN IF NOT EXISTS verified_senior_count INT NOT NULL DEFAULT 0 AFTER claimed_senior_count,
  ADD COLUMN IF NOT EXISTS senior_verification_status VARCHAR(20) NULL DEFAULT NULL AFTER verified_senior_count,
  ADD COLUMN IF NOT EXISTS senior_full_name VARCHAR(255) NULL DEFAULT NULL AFTER senior_verification_status,
  ADD COLUMN IF NOT EXISTS osca_or_gov_id_number VARCHAR(160) NULL DEFAULT NULL AFTER senior_full_name,
  ADD COLUMN IF NOT EXISTS id_image_path VARCHAR(255) NULL DEFAULT NULL AFTER osca_or_gov_id_number,
  ADD COLUMN IF NOT EXISTS senior_physical_present TINYINT(1) NOT NULL DEFAULT 0 AFTER id_image_path,
  ADD COLUMN IF NOT EXISTS vat_exempt_sales DECIMAL(12,2) NOT NULL DEFAULT 0.00 AFTER senior_physical_present,
  ADD COLUMN IF NOT EXISTS vatable_sales DECIMAL(12,2) NOT NULL DEFAULT 0.00 AFTER vat_exempt_sales,
  ADD COLUMN IF NOT EXISTS vat_amount DECIMAL(12,2) NOT NULL DEFAULT 0.00 AFTER vatable_sales,
  ADD COLUMN IF NOT EXISTS senior_discount_amount DECIMAL(12,2) NOT NULL DEFAULT 0.00 AFTER vat_amount,
  ADD COLUMN IF NOT EXISTS overpayment_amount DECIMAL(12,2) NOT NULL DEFAULT 0.00 AFTER senior_discount_amount,
  ADD COLUMN IF NOT EXISTS overpayment_resolution VARCHAR(30) NULL DEFAULT NULL AFTER overpayment_amount;
