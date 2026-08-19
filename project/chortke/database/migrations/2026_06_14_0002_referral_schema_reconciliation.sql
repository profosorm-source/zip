SET FOREIGN_KEY_CHECKS = 0;

-- Referral schema reconciliation for RF-01..RF-05

ALTER TABLE `users`
  ADD COLUMN IF NOT EXISTS `referral_quality_score` DECIMAL(5,2) NOT NULL DEFAULT 85.00 AFTER `referred_by`;

ALTER TABLE `referral_commissions`
  ADD COLUMN IF NOT EXISTS `referred_user_id` INT(10) UNSIGNED NULL,
  ADD COLUMN IF NOT EXISTS `source_type` VARCHAR(50) DEFAULT 'general',
  ADD COLUMN IF NOT EXISTS `context` LONGTEXT NULL,
  ADD COLUMN IF NOT EXISTS `updated_at` TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  ADD COLUMN IF NOT EXISTS `paid_at` TIMESTAMP NULL DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS `commission_date` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP;

UPDATE `referral_commissions`
SET
  `referred_user_id` = COALESCE(`referred_user_id`, `referred_id`),
  `commission_date` = COALESCE(`commission_date`, `created_at`)
WHERE `referred_user_id` IS NULL OR `commission_date` IS NULL;

CREATE INDEX IF NOT EXISTS `idx_referral_commissions_referred` ON `referral_commissions` (`referred_user_id`);
CREATE INDEX IF NOT EXISTS `idx_referral_commissions_status_currency` ON `referral_commissions` (`status`, `currency`);

SET FOREIGN_KEY_CHECKS = 1;
