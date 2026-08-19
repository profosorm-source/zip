-- CHORTKE MIGRATION: INFRASTRUCTURE & HARDENED LOGS
SET FOREIGN_KEY_CHECKS = 0;

-- [REMOVED DUPLICATE settings]
-- [REMOVED DUPLICATE feature_flags]
-- [REMOVED DUPLICATE api_tokens]
DROP TABLE IF EXISTS `kyc_verifications`;
CREATE TABLE `kyc_verifications` (
    `id` INT(10) UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `user_id` INT(10) UNSIGNED UNIQUE NOT NULL,
    `verification_image` VARCHAR(255),
    `national_code` VARCHAR(20),
    `birth_date` DATE,
    `status` ENUM('pending', 'under_review', 'verified', 'rejected') DEFAULT 'pending',
    `ip_address` VARCHAR(45),
    `user_agent` TEXT,
    `device_fingerprint` VARCHAR(128),
    `encryption_version` TINYINT DEFAULT 2,
    `encryption_algorithm` VARCHAR(50) DEFAULT 'AES-256-GCM',
    `rejection_reason` TEXT,
    `submitted_at` TIMESTAMP NULL,
    `reviewed_at` TIMESTAMP NULL,
    `verified_at` TIMESTAMP NULL,
    `expires_at` TIMESTAMP NULL,
    `documents_deleted` TINYINT(1) DEFAULT 0,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY `idx_kyc_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `performance_logs`;
CREATE TABLE `performance_logs` (
    `id` INT(10) UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `request_id` VARCHAR(64),
    `metric` VARCHAR(255),
    `value` VARCHAR(255),
    `context` TEXT,
    `duration_ms` INT(10),
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `sentry_issues`;
CREATE TABLE `sentry_issues` (
    `id` INT(10) UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `fingerprint` VARCHAR(64) NOT NULL,
    `title` VARCHAR(255),
    `culprit` VARCHAR(255),
    `level` VARCHAR(20),
    `environment` VARCHAR(50),
    `release_version` VARCHAR(100),
    `status` VARCHAR(20),
    `count` INT(10) UNSIGNED DEFAULT 1,
    `first_seen` TIMESTAMP NULL,
    `last_seen` TIMESTAMP NULL,
    `metadata` LONGTEXT,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY `idx_fingerprint` (`fingerprint`),
    KEY `idx_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `idempotency_keys`;
CREATE TABLE `idempotency_keys` (
    `id` INT(10) UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `key` VARCHAR(64) NOT NULL,
    `user_id` INT(10) UNSIGNED NOT NULL,
    `action` VARCHAR(100) NOT NULL,
    `status` VARCHAR(30) NOT NULL DEFAULT 'pending',
    `request_data` JSON,
    `result` LONGTEXT,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `completed_at` TIMESTAMP NULL,
    `expires_at` TIMESTAMP NULL,
    UNIQUE KEY `idx_unique_key` (`key`),
    KEY `idx_user_action` (`user_id`, `action`),
    KEY `idx_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `captcha_attempts`;
CREATE TABLE `captcha_attempts` (
    `id` INT(10) UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `ip_address` VARCHAR(45),
    `is_success` TINYINT(1) DEFAULT 0,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;
