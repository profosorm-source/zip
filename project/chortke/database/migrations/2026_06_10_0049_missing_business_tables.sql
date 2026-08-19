-- CHORTKE MIGRATION: MISSING BUSINESS TABLES RECOVERY
-- Purpose: Fix missing tables identified during deep audit to ensure 100% system integrity.

SET FOREIGN_KEY_CHECKS = 0;

-- 1. Influencer Orders
DROP TABLE IF EXISTS `influencer_orders`;
CREATE TABLE `influencer_orders` (
    `id` INT(10) UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `buyer_id` INT(10) UNSIGNED NOT NULL,
    `influencer_id` INT(10) UNSIGNED NOT NULL,
    `amount` DECIMAL(24,8) NOT NULL,
    `influencer_earnings` DECIMAL(24,8) NOT NULL,
    `status` ENUM('pending_acceptance', 'pending_buyer_review', 'completed', 'cancelled') DEFAULT 'pending_acceptance',
    `deadline` TIMESTAMP NULL,
    `content_submitted_at` TIMESTAMP NULL,
    `completed_at` TIMESTAMP NULL,
    `cancelled_at` TIMESTAMP NULL,
    `cancellation_reason` VARCHAR(255),
    `auto_approved` TINYINT(1) DEFAULT 0,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    -- CONSTRAINT `fk_inf_order_buyer` FOREIGN KEY (`buyer_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_inf_order_influencer` FOREIGN KEY (`influencer_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci; -- FK temporarily disabled

-- 2. Task Ratings
DROP TABLE IF EXISTS `task_ratings`;
CREATE TABLE `task_ratings` (
    `id` INT(10) UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `task_id` INT(10) UNSIGNED NOT NULL,
    `user_id` INT(10) UNSIGNED NOT NULL,
    `rating` TINYINT NOT NULL,
    `comment` TEXT,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT `fk_task_rating_ad` FOREIGN KEY (`task_id`) REFERENCES `ads` (`id`) ON DELETE CASCADE
    -- CONSTRAINT `fk_task_rating_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci; -- FK temporarily disabled

-- 3. Fraud Reports
DROP TABLE IF EXISTS `fraud_reports`;
CREATE TABLE `fraud_reports` (
    `id` INT(10) UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `reporter_id` INT(10) UNSIGNED NOT NULL,
    `reported_user_id` INT(10) UNSIGNED NOT NULL,
    `reason` TEXT NOT NULL,
    `evidence` JSON,
    `status` ENUM('pending', 'reviewed', 'dismissed') DEFAULT 'pending',
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    -- CONSTRAINT `fk_fraud_rep_reporter` FOREIGN KEY (`reporter_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_fraud_rep_reported` FOREIGN KEY (`reported_user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci; -- FK temporarily disabled

-- 4. Social Camera Requests
DROP TABLE IF EXISTS `social_camera_requests`;
CREATE TABLE `social_camera_requests` (
    `id` INT(10) UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `execution_id` INT(10) UNSIGNED NOT NULL,
    `user_id` INT(10) UNSIGNED NOT NULL,
    `image_path` VARCHAR(255),
    `status` ENUM('pending', 'verified', 'rejected') DEFAULT 'pending',
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT `fk_camera_exec` FOREIGN KEY (`execution_id`) REFERENCES `social_task_executions` (`id`) ON DELETE CASCADE
    -- CONSTRAINT `fk_camera_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci; -- FK temporarily disabled

-- 5. Vitrine Disputes
DROP TABLE IF EXISTS `vitrine_disputes`;
CREATE TABLE `vitrine_disputes` (
    `id` INT(10) UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `listing_id` INT(10) UNSIGNED NOT NULL,
    `buyer_id` INT(10) UNSIGNED NOT NULL,
    `seller_id` INT(10) UNSIGNED NOT NULL,
    `reason` TEXT NOT NULL,
    `status` ENUM('open', 'resolved', 'cancelled') DEFAULT 'open',
    `resolved_at` TIMESTAMP NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT `fk_vit_disp_listing` FOREIGN KEY (`listing_id`) REFERENCES `vitrine_listings` (`id`) ON DELETE CASCADE,
    -- CONSTRAINT `fk_vit_disp_buyer` FOREIGN KEY (`buyer_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_vit_disp_seller` FOREIGN KEY (`seller_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci; -- FK temporarily disabled

-- 6. Vitrine Watchlist
DROP TABLE IF EXISTS `vitrine_watchlist`;
CREATE TABLE `vitrine_watchlist` (
    `id` INT(10) UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `user_id` INT(10) UNSIGNED NOT NULL,
    `listing_id` INT(10) UNSIGNED NOT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY `user_listing_unique` (`user_id`, `listing_id`),
    -- CONSTRAINT `fk_watchlist_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_watchlist_listing` FOREIGN KEY (`listing_id`) REFERENCES `vitrine_listings` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci; -- FK temporarily disabled

-- 7. Vitrine Category Alerts
DROP TABLE IF EXISTS `vitrine_category_alerts`;
CREATE TABLE `vitrine_category_alerts` (
    `id` INT(10) UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `user_id` INT(10) UNSIGNED NOT NULL,
    `category` VARCHAR(50) NOT NULL,
    `platform` VARCHAR(50),
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    -- CONSTRAINT `fk_alerts_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci; -- FK temporarily disabled

SET FOREIGN_KEY_CHECKS = 1;
