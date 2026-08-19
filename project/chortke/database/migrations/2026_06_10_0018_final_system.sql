-- CHORTKE MIGRATION: FINAL SYSTEM TABLES
SET FOREIGN_KEY_CHECKS = 0;

DROP TABLE IF EXISTS `dispute_messages`;
CREATE TABLE `dispute_messages` (
    `id` INT(10) UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `dispute_id` INT(10) UNSIGNED NOT NULL,
    `user_id` INT(10) UNSIGNED NOT NULL,
    `message` TEXT NOT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    KEY `idx_dm_dispute` (`dispute_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `backup_logs`;
CREATE TABLE `backup_logs` (
    `id` INT(10) UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `filename` VARCHAR(255),
    `status` VARCHAR(20),
    `request_id` VARCHAR(64) DEFAULT NULL,
    `type` VARCHAR(50) DEFAULT 'manual',
    `file_path` VARCHAR(255) DEFAULT NULL,
    `size_bytes` BIGINT UNSIGNED DEFAULT 0,
    `checksum` VARCHAR(64) DEFAULT NULL,
    `description` TEXT DEFAULT NULL,
    `completed_at` TIMESTAMP NULL DEFAULT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `cron_jobs`;
CREATE TABLE `cron_jobs` (
    `id` INT(10) UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `name` VARCHAR(100) UNIQUE NOT NULL,
    `last_run` TIMESTAMP NULL,
    `is_active` TINYINT(1) DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;-- [REMOVED DUPLICATE risk_policies]
DROP TABLE IF EXISTS `roles`;
CREATE TABLE `roles` (
    `id` INT(10) UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `name` VARCHAR(100) UNIQUE NOT NULL,
    `slug` VARCHAR(100) UNIQUE NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `permissions`;
CREATE TABLE `permissions` (
    `id` INT(10) UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `name` VARCHAR(100) UNIQUE NOT NULL,
    `slug` VARCHAR(100) UNIQUE NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `role_permissions`;
CREATE TABLE `role_permissions` (
    `role_id` INT(10) UNSIGNED NOT NULL,
    `permission_id` INT(10) UNSIGNED NOT NULL,
    PRIMARY KEY (`role_id`, `permission_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `user_roles`;
CREATE TABLE `user_roles` (
    `user_id` INT(10) UNSIGNED NOT NULL,
    `role_id` INT(10) UNSIGNED NOT NULL,
    PRIMARY KEY (`user_id`, `role_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;
