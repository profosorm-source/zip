-- CHORTKE MIGRATION: REFERRAL & LEVELS (STRICT CODE-FIRST)
SET FOREIGN_KEY_CHECKS = 0;

DROP TABLE IF EXISTS `referral_commissions`;
CREATE TABLE `referral_commissions` (
    `id` INT(10) UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `referrer_id` INT(10) UNSIGNED NOT NULL,
    `referred_id` INT(10) UNSIGNED NOT NULL,
    `amount` DECIMAL(24,8) NOT NULL,
    `commission_amount` DECIMAL(24,8) NOT NULL,
    `currency` VARCHAR(10) DEFAULT 'irt',
    `status` ENUM('pending', 'paid', 'cancelled') DEFAULT 'pending',
    `idempotency_key` VARCHAR(128) UNIQUE,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    KEY `idx_rc_ref` (`referrer_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `user_levels`;
CREATE TABLE `user_levels` (
    `id` INT(10) UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `name` VARCHAR(100) NOT NULL,
    `slug` VARCHAR(50) UNIQUE NOT NULL,
    `sort_order` INT(10) DEFAULT 0,
    `min_score` DECIMAL(24,4) DEFAULT 0,
    `is_active` TINYINT(1) DEFAULT 1,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `scores`;
CREATE TABLE `scores` (
    `id` INT(10) UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `user_id` INT(10) UNSIGNED NOT NULL,
    `domain` VARCHAR(50) NOT NULL,
    `score` DECIMAL(24,4) DEFAULT 0,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY `user_score_domain` (`user_id`, `domain`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;
