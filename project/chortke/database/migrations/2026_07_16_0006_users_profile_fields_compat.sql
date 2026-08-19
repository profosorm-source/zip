-- Compatibility columns used by ProfileService::updateProfileWithValidation
ALTER TABLE `users`
  ADD COLUMN IF NOT EXISTS `national_id` VARCHAR(20) NULL AFTER `mobile`,
  ADD COLUMN IF NOT EXISTS `birth_date` DATE NULL AFTER `national_id`,
  ADD COLUMN IF NOT EXISTS `gender` VARCHAR(20) NULL AFTER `birth_date`,
  ADD COLUMN IF NOT EXISTS `address` VARCHAR(500) NULL AFTER `gender`,
  ADD COLUMN IF NOT EXISTS `website` VARCHAR(255) NULL AFTER `bio`,
  ADD COLUMN IF NOT EXISTS `location` VARCHAR(255) NULL AFTER `website`,
  ADD COLUMN IF NOT EXISTS `kyc_level` TINYINT UNSIGNED NOT NULL DEFAULT 0,
  ADD COLUMN IF NOT EXISTS `kyc_verified_at` DATETIME NULL DEFAULT NULL;

CREATE INDEX IF NOT EXISTS `idx_users_national_id` ON `users` (`national_id`);
