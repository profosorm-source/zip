-- CHORTKE MIGRATION PART 27: PLANS & VERIFICATIONS
-- Senior QA Architect Standardized
SET FOREIGN_KEY_CHECKS = 0;

DROP TABLE IF EXISTS `investment_plans`;
CREATE TABLE `investment_plans` (
    `id` INT(10) UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `title` VARCHAR(255) NOT NULL,
    `min_amount` DECIMAL(24,4) NOT NULL,
    `max_amount` DECIMAL(24,4),
    `profit_percent` DECIMAL(5,2) NOT NULL,
    `duration_days` INT(10) UNSIGNED NOT NULL,
    `risk_level` ENUM('low', 'medium', 'high'),
    `is_active` TINYINT(1) DEFAULT 1,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `user_verifications`;
CREATE TABLE `user_verifications` (
    `id` INT(10) UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `user_id` INT(10) UNSIGNED NOT NULL,
    `type` ENUM('email', 'mobile', 'identity') NOT NULL,
    `token` VARCHAR(128) NOT NULL,
    `code` VARCHAR(10),
    `expires_at` TIMESTAMP NOT NULL,
    `verified_at` TIMESTAMP NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    KEY `idx_uv_token` (`token`),
    KEY `idx_uv_user` (`user_id`, `type`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `influencer_categories`;
CREATE TABLE `influencer_categories` (
    `id` INT(10) UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `name` VARCHAR(100) NOT NULL,
    `slug` VARCHAR(100) UNIQUE NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `kyc_documents`;
CREATE TABLE `kyc_documents` (
    `id` INT(10) UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `kyc_id` INT(10) UNSIGNED NOT NULL,
    `doc_type` VARCHAR(50),
    `file_path` VARCHAR(255),
    `ocr_data` JSON,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    KEY `idx_kd_kyc` (`kyc_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;
