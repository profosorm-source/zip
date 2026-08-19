-- CHORTKE MIGRATION: SPECIALIZED ANALYTICS & PROFILES
SET FOREIGN_KEY_CHECKS = 0;

DROP TABLE IF EXISTS `talent_profiles`;
CREATE TABLE `talent_profiles` (
    `id` INT(10) UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `user_id` INT(10) UNSIGNED NOT NULL,
    `expertise` VARCHAR(255),
    `status` VARCHAR(20) DEFAULT 'pending',
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY `idx_tp_user` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `talent_contents`;
CREATE TABLE `talent_contents` (
    `id` INT(10) UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `user_id` INT(10) UNSIGNED NOT NULL,
    `title` VARCHAR(255),
    `url` VARCHAR(500),
    `status` VARCHAR(20) DEFAULT 'submitted',
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `online_listings`;
CREATE TABLE `online_listings` (
    `id` INT(10) UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `user_id` INT(10) UNSIGNED NOT NULL,
    `title` VARCHAR(255) NOT NULL,
    `description` TEXT,
    `price_usdt` DECIMAL(24,8),
    `status` VARCHAR(20) DEFAULT 'active',
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    KEY `idx_ol_user` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `social_task_analytics`;
CREATE TABLE `social_task_analytics` (
    `id` INT(10) UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `ad_id` INT(10) UNSIGNED NOT NULL,
    `total_views` INT(10) UNSIGNED DEFAULT 0,
    `total_completions` INT(10) UNSIGNED DEFAULT 0,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY `ad_analytics_unique` (`ad_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `custom_task_analytics`;
CREATE TABLE `custom_task_analytics` (
    `id` INT(10) UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `task_id` INT(10) UNSIGNED NOT NULL,
    `total_submissions` INT(10) UNSIGNED DEFAULT 0,
    `average_rating` DECIMAL(3,2) DEFAULT 0,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY `task_analytics_unique` (`task_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `mv_dashboard_stats`;
CREATE TABLE `mv_dashboard_stats` (
    `currency` VARCHAR(10) PRIMARY KEY,
    `total_deposits` DECIMAL(24,8) DEFAULT 0,
    `total_withdrawals` DECIMAL(24,8) DEFAULT 0,
    `today_deposits` DECIMAL(24,8) DEFAULT 0,
    `today_withdrawals` DECIMAL(24,8) DEFAULT 0,
    `pending_transactions` INT DEFAULT 0,
    `site_revenue` DECIMAL(24,8) DEFAULT 0,
    `today_revenue` DECIMAL(24,8) DEFAULT 0,
    `weekly_revenue` DECIMAL(24,8) DEFAULT 0,
    `monthly_revenue` DECIMAL(24,8) DEFAULT 0,
    `total_transactions` INT DEFAULT 0,
    `active_users` INT DEFAULT 0,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;
