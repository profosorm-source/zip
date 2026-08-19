SET FOREIGN_KEY_CHECKS = 0;

-- CHORTKE MIGRATION: Add kyc_status and tier to users for Vitrine
-- BUGFIX-VITRINE-USER-COLUMNS-2026-06
--
-- VitrineListing, VitrineRequest and several views select s.tier and s.kyc_status
-- from the users table. These columns are expected by the application code but
-- were missing from the users schema in this environment. This migration adds
-- them with sensible defaults so that /vitrine and related pages work.

SET NAMES utf8mb4;

ALTER TABLE `users`
    ADD COLUMN IF NOT EXISTS `kyc_status` VARCHAR(50) NULL DEFAULT 'unverified',
    ADD COLUMN IF NOT EXISTS `tier`       VARCHAR(50) NULL DEFAULT 'SILVER';

SET FOREIGN_KEY_CHECKS = 1;
