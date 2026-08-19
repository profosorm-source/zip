-- CHORTKE MIGRATION PART 3: INFLUENCERS & DISPUTES
SET FOREIGN_KEY_CHECKS = 0;

DROP TABLE IF EXISTS `influencer_profiles`;
CREATE TABLE `influencer_profiles` (
    `id` INT(10) UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `user_id` INT(10) UNSIGNED UNIQUE NOT NULL,
    `username` VARCHAR(100) NOT NULL,
    `platform` VARCHAR(50) NOT NULL,
    `followers_count` INT(10) UNSIGNED DEFAULT 0,
    `price_story` DECIMAL(24,4),
    `status` VARCHAR(20) DEFAULT 'pending',
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY `idx_inf_user` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `story_orders`;
CREATE TABLE `story_orders` (
    `id` INT(10) UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `customer_id` INT(10) UNSIGNED NOT NULL,
    `influencer_id` INT(10) UNSIGNED NOT NULL,
    `influencer_user_id` INT(10) UNSIGNED NOT NULL,
    `status` VARCHAR(50) DEFAULT 'pending_payment',
    `price` DECIMAL(24,4) NOT NULL,
    `currency` VARCHAR(10) DEFAULT 'irt',
    `verification_code` VARCHAR(50),
    `idempotency_key` VARCHAR(128) UNIQUE,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY `idx_so_customer` (`customer_id`),
    KEY `idx_so_influencer` (`influencer_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `disputes`;
CREATE TABLE `disputes` (
    `id` INT(10) UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `ref_type` VARCHAR(50) NOT NULL,
    `ref_id` INT(10) UNSIGNED NOT NULL,
    `user_id` INT(10) UNSIGNED NOT NULL,
    `target_user_id` INT(10) UNSIGNED,
    `status` VARCHAR(50) DEFAULT 'open',
    `reason` TEXT NOT NULL,
    `admin_id` INT(10) UNSIGNED,
    `resolved_at` TIMESTAMP NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY `idx_d_ref` (`ref_type`, `ref_id`),
    KEY `idx_d_user` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;
