SET FOREIGN_KEY_CHECKS = 0;

-- Migration: Create social_accounts table (April 28, 2026)

CREATE TABLE IF NOT EXISTS `social_accounts` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT PRIMARY KEY,
  
  -- صارف کی شناخت
  `user_id` bigint unsigned NOT NULL,
  
  -- OAuth Provider
  `provider` enum('google', 'facebook') NOT NULL DEFAULT 'google',
  `provider_id` varchar(255) NOT NULL COMMENT 'Provider میں صارف کی ID',
  `provider_email` varchar(255) COLLATE utf8mb4_unicode_ci,
  `provider_name` varchar(255) COLLATE utf8mb4_unicode_ci,
  
  -- تمام provider data (JSON میں)
  `data` json,
  
  -- Timestamps
  `linked_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  
  -- Indexes
  UNIQUE KEY `uq_provider_id` (`provider`, `provider_id`),
  KEY `idx_user_id` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- OAuth-only account compatibility is defined directly in the users base schema
-- (2026_06_10_0001_identity_real.sql) to avoid dynamic PREPARE/EXECUTE result sets
-- during MigrationService execution.

SET FOREIGN_KEY_CHECKS = 1;
