-- Ads phase 4: unified admin finance + delivery budget pipeline

ALTER TABLE `ads`
  ADD COLUMN IF NOT EXISTS `spent_budget` DECIMAL(24,8) NOT NULL DEFAULT 0.00000000 AFTER `remaining_budget`,
  ADD COLUMN IF NOT EXISTS `last_delivery_at` TIMESTAMP NULL DEFAULT NULL AFTER `spent_budget`,
  ADD COLUMN IF NOT EXISTS `delivery_pricing` LONGTEXT NULL DEFAULT NULL AFTER `last_delivery_at`,
  ADD COLUMN IF NOT EXISTS `approved_at` TIMESTAMP NULL DEFAULT NULL AFTER `reviewed_at`,
  ADD COLUMN IF NOT EXISTS `cancelled_at` TIMESTAMP NULL DEFAULT NULL AFTER `approved_at`,
  ADD COLUMN IF NOT EXISTS `rejection_reason` TEXT NULL DEFAULT NULL AFTER `reject_reason`;

CREATE TABLE IF NOT EXISTS `ad_delivery_events` (
  `id` INT(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `ad_id` INT(10) UNSIGNED NOT NULL,
  `ad_type` VARCHAR(50) NOT NULL,
  `event_type` VARCHAR(50) NOT NULL,
  `user_id` INT(10) UNSIGNED NULL DEFAULT NULL,
  `units` DECIMAL(16,4) NOT NULL DEFAULT 1.0000,
  `unit_cost` DECIMAL(24,8) NOT NULL DEFAULT 0.00000000,
  `amount` DECIMAL(24,8) NOT NULL DEFAULT 0.00000000,
  `platform_fee` DECIMAL(24,8) NOT NULL DEFAULT 0.00000000,
  `currency` VARCHAR(10) NOT NULL DEFAULT 'irt',
  `ip_address` VARCHAR(45) NULL DEFAULT NULL,
  `user_agent` VARCHAR(500) NULL DEFAULT NULL,
  `reference_id` VARCHAR(100) NULL DEFAULT NULL,
  `reference_type` VARCHAR(100) NULL DEFAULT NULL,
  `metadata` LONGTEXT NULL DEFAULT NULL,
  `idempotency_key` VARCHAR(128) NULL DEFAULT NULL,
  `created_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_ad_delivery_idempotency` (`idempotency_key`),
  KEY `idx_ad_delivery_ad` (`ad_id`, `event_type`, `created_at`),
  KEY `idx_ad_delivery_type` (`ad_type`, `event_type`),
  KEY `idx_ad_delivery_user` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE INDEX IF NOT EXISTS `idx_ads_phase4_finance` ON `ads` (`type`, `status`, `remaining_budget`, `last_delivery_at`);
