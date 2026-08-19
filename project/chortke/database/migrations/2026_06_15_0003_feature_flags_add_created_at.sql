SET FOREIGN_KEY_CHECKS = 0;

-- CHORTKE MIGRATION: Add created_at to feature_flags
-- BUGFIX-FEATURE-FLAGS-DB-SEED-2026-06
--
-- The FeatureFlag model and CLI commands expect a created_at column, but the
-- original table definition only included updated_at. This migration adds the
-- missing column so that feature:create and feature:status work correctly.

SET NAMES utf8mb4;

ALTER TABLE `feature_flags`
    ADD COLUMN IF NOT EXISTS `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP
    AFTER `updated_at`;

SET FOREIGN_KEY_CHECKS = 1;
