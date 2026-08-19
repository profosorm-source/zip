-- =============================================================================
-- MIGRATION: Add refresh_token to api_tokens for Mobile App Support (Phase 1)
-- =============================================================================

ALTER TABLE `api_tokens`
  ADD COLUMN IF NOT EXISTS `refresh_token` VARCHAR(100) NULL UNIQUE AFTER `token`;

SET FOREIGN_KEY_CHECKS = 1;
