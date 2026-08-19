-- CHORTKE MIGRATION PART 24: AUTH HARDENING
-- Senior QA Architect Standardized
SET FOREIGN_KEY_CHECKS = 0;

DROP TABLE IF EXISTS `login_attempts`;
CREATE TABLE `login_attempts` (
    `id` INT(10) UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `identifier` VARCHAR(191) NOT NULL COMMENT 'email or mobile',
    `ip_address` VARCHAR(45),
    `status` ENUM('success', 'failed') NOT NULL,
    `user_agent` TEXT,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    KEY `idx_login_id` (`identifier`),
    KEY `idx_login_ip` (`ip_address`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `trusted_devices`;
CREATE TABLE `trusted_devices` (
    `id` INT(10) UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `user_id` INT(10) UNSIGNED NOT NULL,
    `fingerprint` VARCHAR(128) NOT NULL,
    `device_name` VARCHAR(255),
    `last_used_at` TIMESTAMP NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY `user_device` (`user_id`, `fingerprint`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `blacklist`;
CREATE TABLE `blacklist` (
    `id` INT(10) UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `type` ENUM('ip', 'email', 'mobile', 'fingerprint') NOT NULL,
    `value` VARCHAR(191) NOT NULL,
    `reason` TEXT,
    `expires_at` TIMESTAMP NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY `blacklist_val` (`type`, `value`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;
