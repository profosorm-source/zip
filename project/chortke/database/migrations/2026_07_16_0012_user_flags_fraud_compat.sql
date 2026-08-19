-- Fraud risk-report compatibility table.
-- VelocityAndScoreModel::getUserFlags() reads this table for manual/admin fraud flags.
CREATE TABLE IF NOT EXISTS `user_flags` (
  `id` INT(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` INT(10) UNSIGNED NOT NULL,
  `requires_review` TINYINT(1) NOT NULL DEFAULT 0,
  `requires_kyc` TINYINT(1) NOT NULL DEFAULT 0,
  `requires_manual_review` TINYINT(1) NOT NULL DEFAULT 0,
  `is_blacklisted` TINYINT(1) NOT NULL DEFAULT 0,
  `blacklist_reason` TEXT NULL,
  `created_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_user_flags_user` (`user_id`),
  KEY `idx_user_flags_review` (`requires_review`, `requires_manual_review`),
  KEY `idx_user_flags_blacklisted` (`is_blacklisted`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
