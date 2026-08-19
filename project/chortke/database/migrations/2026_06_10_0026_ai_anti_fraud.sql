-- CHORTKE MIGRATION PART 19: AI & ANTI-FRAUD SPECIALIZED
SET FOREIGN_KEY_CHECKS = 0;

DROP TABLE IF EXISTS `fraud_analytics`;
CREATE TABLE `fraud_analytics` (
    `id` INT(10) UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `user_id` INT(10) UNSIGNED UNIQUE NOT NULL,
    `risk_score` DECIMAL(10,2) DEFAULT 0,
    `trust_level` ENUM('trusted', 'neutral', 'suspicious', 'banned') DEFAULT 'neutral',
    `last_check_at` TIMESTAMP NULL,
    `metadata` JSON,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `velocity_limits`;
CREATE TABLE `velocity_limits` (
    `id` INT(10) UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `identifier` VARCHAR(191) NOT NULL COMMENT 'ip, user_id, fingerprint',
    `action` VARCHAR(100) NOT NULL,
    `hit_count` INT(10) UNSIGNED DEFAULT 1,
    `decay_at` TIMESTAMP NOT NULL,
    UNIQUE KEY `velocity_unique` (`identifier`, `action`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `ip_intelligence`;
CREATE TABLE `ip_intelligence` (
    `id` INT(10) UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `ip_address` VARCHAR(45) UNIQUE NOT NULL,
    `country` CHAR(2),
    `is_vpn` TINYINT(1) DEFAULT 0,
    `is_proxy` TINYINT(1) DEFAULT 0,
    `is_tor` TINYINT(1) DEFAULT 0,
    `risk_score` INT(10) DEFAULT 0,
    `last_updated` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;
