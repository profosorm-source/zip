-- CHORTKE MIGRATION: Create rate_limits table
-- BUGFIX-RATE-LIMITS-TABLE-2026-06
--
-- SystemTelemetryModel and AdvancedAnalytics reference a table called `rate_limits`.
-- The existing migrations only created `rate_limit_requests` and `rate_limit_whitelist`.
-- This migration adds the missing `rate_limits` table so that telemetry queries work.

SET FOREIGN_KEY_CHECKS = 0;
SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS `rate_limits` (
    `id` INT(10) UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `identifier_key` VARCHAR(191) NULL,
    `action` VARCHAR(100) NULL,
    `exceeded` TINYINT(1) NOT NULL DEFAULT 0,
    `ip_address` VARCHAR(45) NULL,
    `user_id` INT(10) UNSIGNED NULL,
    `route` VARCHAR(255) NULL,
    `method` VARCHAR(20) NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY `idx_rate_limits_created_at` (`created_at`),
    KEY `idx_rate_limits_exceeded` (`exceeded`),
    KEY `idx_rate_limits_identifier` (`identifier_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;
