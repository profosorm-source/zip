SET FOREIGN_KEY_CHECKS = 0;

-- Finance deep-regression schema compatibility

ALTER TABLE `manual_deposits`
  ADD COLUMN IF NOT EXISTS `card_id` INT(10) UNSIGNED NULL AFTER `currency`,
  ADD COLUMN IF NOT EXISTS `bank_card_id` INT(10) UNSIGNED NULL AFTER `card_id`,
  ADD COLUMN IF NOT EXISTS `user_description` TEXT NULL AFTER `tracking_code`,
  ADD COLUMN IF NOT EXISTS `receipt_path` VARCHAR(255) NULL AFTER `user_description`,
  ADD COLUMN IF NOT EXISTS `admin_note` TEXT NULL AFTER `transaction_id`,
  ADD COLUMN IF NOT EXISTS `rejection_reason` TEXT NULL AFTER `admin_note`,
  ADD COLUMN IF NOT EXISTS `reviewed_by` INT(10) UNSIGNED NULL AFTER `rejection_reason`,
  ADD COLUMN IF NOT EXISTS `reviewed_at` TIMESTAMP NULL AFTER `reviewed_by`;

UPDATE `manual_deposits` SET `card_id` = COALESCE(`card_id`, `bank_card_id`), `bank_card_id` = COALESCE(`bank_card_id`, `card_id`) WHERE `card_id` IS NULL OR `bank_card_id` IS NULL;

ALTER TABLE `crypto_deposits`
  ADD COLUMN IF NOT EXISTS `verified_at` TIMESTAMP NULL,
  ADD COLUMN IF NOT EXISTS `explorer_data` LONGTEXT NULL,
  ADD COLUMN IF NOT EXISTS `rejection_reason` TEXT NULL,
  ADD COLUMN IF NOT EXISTS `reviewed_by` INT(10) UNSIGNED NULL,
  ADD COLUMN IF NOT EXISTS `reviewed_at` TIMESTAMP NULL,
  ADD COLUMN IF NOT EXISTS `verification_attempts` INT(10) UNSIGNED NOT NULL DEFAULT 0;
CREATE INDEX IF NOT EXISTS `idx_status_created` ON `crypto_deposits` (`verification_status`, `created_at`);

ALTER TABLE `payment_logs`
  ADD COLUMN IF NOT EXISTS `bank_card_id` INT(10) UNSIGNED NULL,
  ADD COLUMN IF NOT EXISTS `idempotency_key` VARCHAR(128) NULL;
CREATE INDEX IF NOT EXISTS `idx_payment_logs_idempotency` ON `payment_logs` (`idempotency_key`);

ALTER TABLE `payment_gateways`
  ADD COLUMN IF NOT EXISTS `callback_ips` LONGTEXT NULL,
  ADD COLUMN IF NOT EXISTS `updated_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP;

ALTER TABLE `bank_cards`
  ADD COLUMN IF NOT EXISTS `owner_name` VARCHAR(255) NULL,
  ADD COLUMN IF NOT EXISTS `iban` VARCHAR(30) NULL,
  ADD COLUMN IF NOT EXISTS `card_hash` VARCHAR(128) NULL,
  ADD COLUMN IF NOT EXISTS `verified_at` TIMESTAMP NULL,
  ADD COLUMN IF NOT EXISTS `reviewed_by` INT(10) UNSIGNED NULL,
  ADD COLUMN IF NOT EXISTS `rejection_reason` TEXT NULL;

SET FOREIGN_KEY_CHECKS = 1;
