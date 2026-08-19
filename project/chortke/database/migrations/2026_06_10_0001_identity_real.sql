-- CHORTKE MIGRATION: IDENTITY (STRICT CODE-FIRST)
SET FOREIGN_KEY_CHECKS = 0;

DROP TABLE IF EXISTS `users`;
CREATE TABLE `users` (
    `id` INT(10) UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `username` VARCHAR(64) UNIQUE,
    `email` VARCHAR(255) UNIQUE NOT NULL,
    `mobile` VARCHAR(15) UNIQUE,
    `full_name` VARCHAR(255),
    `password` VARCHAR(255) NULL DEFAULT NULL,
    `oauth_only` TINYINT(1) DEFAULT 0,
    `role` ENUM('user', 'admin', 'super_admin', 'support') DEFAULT 'user',
    `status` ENUM('active', 'inactive', 'suspended', 'locked', 'locked_2fa', 'banned', 'deleted') DEFAULT 'active',
    `is_admin` TINYINT(1) DEFAULT 0,
    `two_factor_enabled` TINYINT(1) DEFAULT 0,
    `two_factor_method` VARCHAR(50),
    `two_factor_secret` TEXT,
    `last_2fa_timeslice` BIGINT(20) UNSIGNED,
    `referral_code` VARCHAR(20) UNIQUE,
    `referred_by` INT(10) UNSIGNED,
    `email_verification_token` VARCHAR(128),
    `email_verified_at` TIMESTAMP NULL,
    `country_code` CHAR(2) DEFAULT 'IR',
    `country_name` VARCHAR(100) DEFAULT 'Iran',
    `avatar` VARCHAR(255),
    `bio` TEXT,
    `timezone` VARCHAR(50) DEFAULT 'Asia/Tehran',
    `fraud_score` INT DEFAULT 0,
    `is_blacklisted` TINYINT(1) DEFAULT 0,
    `level_slug` VARCHAR(60) DEFAULT 'silver',
    `remember_token` VARCHAR(100),
    `remember_expires_at` TIMESTAMP NULL,
    `last_login` TIMESTAMP NULL,
    `last_ip` VARCHAR(45),
    `last_user_agent` TEXT,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    `deleted_at` TIMESTAMP NULL,
    KEY `idx_user_email` (`email`),
    KEY `idx_user_mobile` (`mobile`),
    KEY `idx_user_status` (`status`),
    KEY `idx_user_referral` (`referral_code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `user_sessions`;
CREATE TABLE `user_sessions` (
    `id` INT(10) UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `user_id` INT(10) UNSIGNED NOT NULL,
    `session_id` VARCHAR(191) UNIQUE NOT NULL,
    `ip_address` VARCHAR(45),
    `user_agent` TEXT,
    `device_type` VARCHAR(50),
    `browser` VARCHAR(50),
    `os` VARCHAR(50),
    `country` CHAR(2),
    `city` VARCHAR(100),
    `fingerprint` VARCHAR(128),
    `last_activity` TIMESTAMP NULL,
    `is_active` TINYINT(1) DEFAULT 1,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY `idx_session_user` (`user_id`),
    KEY `idx_session_active` (`is_active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;
