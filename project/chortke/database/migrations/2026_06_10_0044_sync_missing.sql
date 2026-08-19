-- CHORTKE MIGRATION PART 38: FINAL SYNC & MISSING ENTITIES
SET FOREIGN_KEY_CHECKS = 0;

DROP TABLE IF EXISTS `escrow_transactions`;
CREATE TABLE `escrow_transactions` (
  `id` INT(10) UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `order_id` VARCHAR(100) NOT NULL,
  `order_type` VARCHAR(100) NOT NULL,
  `buyer_id` INT(10) UNSIGNED NOT NULL,
  `seller_id` INT(10) UNSIGNED NOT NULL,
  `amount` DECIMAL(24,8) NOT NULL,
  `currency` VARCHAR(10) NOT NULL DEFAULT 'USDT',
  `status` VARCHAR(50) NOT NULL DEFAULT 'pending',
  `held_at` TIMESTAMP NULL,
  `confirmed_at` TIMESTAMP NULL,
  `released_at` TIMESTAMP NULL,
  `released_by` VARCHAR(100),
  `refunded_at` TIMESTAMP NULL,
  `refund_reason` TEXT,
  `refunded_by` VARCHAR(100),
  `disputed_at` TIMESTAMP NULL,
  `dispute_reason` TEXT,
  `expires_at` TIMESTAMP NULL,
  `partial_released` DECIMAL(24,8) NOT NULL DEFAULT 0.00000000,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `fraud_alerts`;
CREATE TABLE `fraud_alerts` (
  `id` INT(10) UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `alert_type` VARCHAR(100) NOT NULL,
  `severity` VARCHAR(20) NOT NULL,
  `user_id` INT(10) UNSIGNED DEFAULT NULL,
  `title` VARCHAR(255) NOT NULL,
  `description` TEXT,
  `details` JSON DEFAULT NULL,
  `status` VARCHAR(30) NOT NULL DEFAULT 'pending',
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `fraud_logs`;
CREATE TABLE `fraud_logs` (
  `id` INT(10) UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `user_id` INT(10) UNSIGNED DEFAULT NULL,
  `session_id` VARCHAR(255) DEFAULT NULL,
  `fraud_type` VARCHAR(50) NOT NULL,
  `risk_score` INT(10) NOT NULL,
  `details` JSON DEFAULT NULL,
  `action_taken` VARCHAR(50) DEFAULT NULL,
  `ip_address` VARCHAR(45),
  `user_agent` TEXT,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;-- [REMOVED DUPLICATE sentry_issue_events]
DROP TABLE IF EXISTS `seo_fraud_events`;
CREATE TABLE `seo_fraud_events` (
  `id` INT(10) UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `user_id` INT(10) UNSIGNED DEFAULT NULL,
  `session_id` VARCHAR(128) DEFAULT NULL,
  `ip_address` VARCHAR(45),
  `user_agent` TEXT,
  `referrer` VARCHAR(500),
  `landing_url` VARCHAR(500),
  `event_type` VARCHAR(100) NOT NULL,
  `risk_score` INT(10) NOT NULL DEFAULT 0,
  `is_bot` TINYINT(1) NOT NULL DEFAULT 0,
  `details` JSON DEFAULT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `social_ratings`;
CREATE TABLE `social_ratings` (
  `id` INT(10) UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `execution_id` INT(10) UNSIGNED NOT NULL,
  `rater_id` INT(10) UNSIGNED NOT NULL,
  `rated_id` INT(10) UNSIGNED NOT NULL,
  `rater_type` ENUM('executor','advertiser') NOT NULL,
  `stars` TINYINT UNSIGNED NOT NULL,
  `comment` TEXT,
  `status` ENUM('pending','approved','rejected') NOT NULL DEFAULT 'approved',
  `reviewed_by` INT(10) UNSIGNED DEFAULT NULL,
  `reviewed_at` TIMESTAMP NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `social_user_trust`;
CREATE TABLE `social_user_trust` (
  `user_id` INT(10) UNSIGNED PRIMARY KEY,
  `trust_score` DECIMAL(5,2) NOT NULL DEFAULT 50.00,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `task_rechecks`;
CREATE TABLE `task_rechecks` (
  `id` INT(10) UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `original_execution_id` INT(10) UNSIGNED NOT NULL,
  `advertisement_id` INT(10) UNSIGNED NOT NULL,
  `executor_id` INT(10) UNSIGNED NOT NULL,
  `status` ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending',
  `penalty_amount` DECIMAL(24,4) NOT NULL DEFAULT 0.0000,
  `penalty_currency` ENUM('irt','usdt') NOT NULL DEFAULT 'irt',
  `refunded_to_advertiser` TINYINT(1) NOT NULL DEFAULT 0,
  `checked_at` TIMESTAMP NULL,
  `admin_note` TEXT,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `user_score_events`;
CREATE TABLE `user_score_events` (
  `id` INT(10) UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `user_id` INT(10) UNSIGNED NOT NULL,
  `domain` VARCHAR(32) NOT NULL,
  `source` VARCHAR(64) NOT NULL,
  `delta` DECIMAL(24,4) NOT NULL DEFAULT 0.0000,
  `meta_json` JSON DEFAULT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;
