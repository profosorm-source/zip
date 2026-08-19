SET FOREIGN_KEY_CHECKS = 0;

-- Referral UI/Admin compatibility fixes discovered by browser E2E.

ALTER TABLE `users`
  ADD COLUMN IF NOT EXISTS `kyc_status` VARCHAR(50) NULL DEFAULT 'unverified' AFTER `deleted_at`,
  ADD COLUMN IF NOT EXISTS `tier` VARCHAR(50) NULL DEFAULT 'SILVER' AFTER `kyc_status`,
  ADD COLUMN IF NOT EXISTS `device_fingerprint` VARCHAR(64) NULL AFTER `fraud_score`,
  ADD COLUMN IF NOT EXISTS `active_days_count` INT(10) NULL DEFAULT 0 AFTER `device_fingerprint`,
  ADD COLUMN IF NOT EXISTS `blacklist_reason` TEXT NULL AFTER `is_blacklisted`,
  ADD COLUMN IF NOT EXISTS `blacklisted_at` DATETIME NULL AFTER `blacklist_reason`,
  ADD COLUMN IF NOT EXISTS `level_type` ENUM('auto','purchased') NULL DEFAULT 'auto' AFTER `level_slug`,
  ADD COLUMN IF NOT EXISTS `last_active_date` DATE NULL AFTER `last_user_agent`;

ALTER TABLE `audit_trail`
  ADD COLUMN IF NOT EXISTS `hash` VARCHAR(100) NULL;

ALTER TABLE `system_alerts`
  ADD COLUMN IF NOT EXISTS `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  ADD COLUMN IF NOT EXISTS `acknowledged_at` TIMESTAMP NULL DEFAULT NULL;

SET FOREIGN_KEY_CHECKS = 1;
