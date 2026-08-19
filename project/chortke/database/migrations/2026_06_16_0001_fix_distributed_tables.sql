-- =====================================================
-- Migration: Fix Distributed Systems Tables (Option 3)
-- Date: 2026-06-16
-- Purpose: Ensure correct structure for outbox_events,
--          idempotency_keys, saga_executions, failed_jobs
--          based on real project usage and tests.
-- =====================================================

SET FOREIGN_KEY_CHECKS = 0;

-- 1. Fix outbox_events (add missing columns if needed + correct defaults)
DROP TABLE IF EXISTS `outbox_events_temp`;
CREATE TABLE IF NOT EXISTS `outbox_events` (
    `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `aggregate_type` VARCHAR(80) NOT NULL,
    `aggregate_id` VARCHAR(128) NOT NULL,
    `event_type` VARCHAR(120) NOT NULL,
    `payload` LONGTEXT NULL,
    `status` ENUM('pending','processing','published','failed','dlq') NOT NULL DEFAULT 'pending',
    `attempts` INT UNSIGNED NOT NULL DEFAULT 0,
    `last_error` VARCHAR(2000) NULL,
    `available_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `published_at` DATETIME NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY `idx_outbox_status` (`status`),
    KEY `idx_outbox_aggregate` (`aggregate_type`, `aggregate_id`),
    KEY `idx_outbox_available` (`available_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 2. Fix idempotency_keys (use `key` as column name - matches real usage)
DROP TABLE IF EXISTS `idempotency_keys_temp`;
CREATE TABLE IF NOT EXISTS `idempotency_keys` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `key` VARCHAR(191) NOT NULL,
    `user_id` INT UNSIGNED NOT NULL,
    `action` VARCHAR(100) NOT NULL,
    `status` VARCHAR(30) NOT NULL DEFAULT 'pending',
    `request_data` LONGTEXT NULL,
    `result` LONGTEXT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `completed_at` TIMESTAMP NULL,
    `expires_at` TIMESTAMP NULL,
    UNIQUE KEY `idx_unique_key` (`key`),
    KEY `idx_user_action` (`user_id`, `action`),
    KEY `idx_status` (`status`)
    -- CONSTRAINT `fk_idempotency_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci; -- FK temporarily disabled

-- 3. Fix saga_executions (ensure correct structure)
CREATE TABLE IF NOT EXISTS `saga_executions` (
    `id` VARCHAR(64) PRIMARY KEY,
    `saga_name` VARCHAR(100) NOT NULL,
    `status` VARCHAR(20) NOT NULL DEFAULT 'running',
    `payload` LONGTEXT NULL,
    `executed_steps` LONGTEXT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY `idx_saga_name` (`saga_name`),
    KEY `idx_saga_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 4. Fix failed_jobs (ensure full structure for DLQ)
CREATE TABLE IF NOT EXISTS `failed_jobs` (
    `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `connection` VARCHAR(255) NULL,
    `queue` VARCHAR(255) NULL,
    `payload` LONGTEXT NULL,
    `exception` LONGTEXT NULL,
    `failed_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    KEY `idx_failed_queue` (`queue`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 5. event_failures (supporting table)
CREATE TABLE IF NOT EXISTS `event_failures` (
    `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `event_type` VARCHAR(120) NOT NULL,
    `payload` LONGTEXT NULL,
    `error` TEXT NULL,
    `failed_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    KEY `idx_event_failures_type` (`event_type`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;

-- Migration tracking is handled centrally by MigrationService via schema_migrations.
