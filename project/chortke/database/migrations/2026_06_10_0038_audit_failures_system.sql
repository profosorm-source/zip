-- CHORTKE MIGRATION PART 32: AUDIT & SYSTEM UTILITIES
SET FOREIGN_KEY_CHECKS = 0;

DROP TABLE IF EXISTS `admin_audit_log`;
CREATE TABLE `admin_audit_log` (
  `id` INT(10) UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `admin_id` INT(10) UNSIGNED NOT NULL,
  `action` VARCHAR(100) NOT NULL,
  `entity_type` VARCHAR(50) NOT NULL,
  `entity_id` INT(10) UNSIGNED NOT NULL,
  `old_values` JSON DEFAULT NULL,
  `new_values` JSON DEFAULT NULL,
  `ip_address` VARCHAR(45) DEFAULT NULL,
  `user_agent` TEXT DEFAULT NULL,
  `session_id` VARCHAR(128) DEFAULT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `escrow_audit`;
CREATE TABLE `escrow_audit` (
  `id` INT(10) UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `escrow_id` INT(10) UNSIGNED NOT NULL,
  `action` VARCHAR(100) NOT NULL,
  `amount` DECIMAL(24,8) NOT NULL,
  `performed_by` VARCHAR(100) NOT NULL,
  `note` TEXT DEFAULT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `event_failures`;
CREATE TABLE `event_failures` (
  `id` INT(10) UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `event_name` VARCHAR(255) NOT NULL,
  `listener` VARCHAR(255) NOT NULL,
  `payload` JSON DEFAULT NULL,
  `error_message` TEXT NOT NULL,
  `failed_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- schema_migrations is an internal MigrationService tracking table and must not be dropped/recreated by business migrations.

DROP TABLE IF EXISTS `transactions_archive`;
CREATE TABLE `transactions_archive` (
  `id` INT(10) UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `transaction_id` VARCHAR(64) UNIQUE NOT NULL,
  `user_id` INT(10) UNSIGNED NOT NULL,
  `type` VARCHAR(50) NOT NULL,
  `amount` DECIMAL(24,8) NOT NULL,
  `currency` VARCHAR(10) NOT NULL,
  `status` VARCHAR(20) NOT NULL,
  `description` TEXT,
  `metadata` JSON,
  `idempotency_key` VARCHAR(128),
  `ip_address` VARCHAR(45),
  `device_fingerprint` VARCHAR(128),
  `completed_at` TIMESTAMP NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `feature_flag_history`;
CREATE TABLE `feature_flag_history` (
  `id` INT(10) UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `feature_name` VARCHAR(100) NOT NULL,
  `action` VARCHAR(50) NOT NULL,
  `old_values` JSON DEFAULT NULL,
  `new_values` JSON DEFAULT NULL,
  `changed_by` INT(10) UNSIGNED NOT NULL,
  `changed_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `feature_flag_metrics`;
CREATE TABLE `feature_flag_metrics` (
  `id` INT(10) UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `feature_name` VARCHAR(100) NOT NULL,
  `user_id` INT(10) UNSIGNED DEFAULT NULL,
  `check_result` TINYINT(1) NOT NULL,
  `check_reason` VARCHAR(50) DEFAULT NULL,
  `checked_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `response_time_ms` DECIMAL(10,2) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `feature_flag_cache`;
CREATE TABLE `feature_flag_cache` (
  `cache_key` VARCHAR(191) PRIMARY KEY,
  `is_enabled` TINYINT(1) NOT NULL,
  `cached_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `expires_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;
