SET FOREIGN_KEY_CHECKS = 0;

-- 1. SOCIAL TASKS
DROP TABLE IF EXISTS `social_tasks`;
CREATE TABLE `social_tasks` (
    `id` INT(10) UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `creator_id` INT(10) UNSIGNED NOT NULL,
    `title` VARCHAR(255) NOT NULL,
    `description` TEXT,
    `platform` ENUM('instagram', 'telegram', 'youtube', 'twitter', 'tiktok', 'aparat', 'linkedin', 'other') NOT NULL,
    `task_type` VARCHAR(100),
    `target_url` VARCHAR(500) NOT NULL,
    `price_per_task` DECIMAL(24,4) NOT NULL,
    `total_budget` DECIMAL(24,4) NOT NULL,
    `remaining_budget` DECIMAL(24,4) NOT NULL,
    `total_count` INT UNSIGNED NOT NULL,
    `remaining_count` INT UNSIGNED NOT NULL,
    `status` VARCHAR(20) DEFAULT 'pending',
    `deleted_at` TIMESTAMP NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY `idx_st_creator` (`creator_id`),
    KEY `idx_st_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 2. CUSTOM TASKS
DROP TABLE IF EXISTS `custom_tasks`;
CREATE TABLE `custom_tasks` (
    `id` INT(10) UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `creator_id` INT(10) UNSIGNED NOT NULL,
    `title` VARCHAR(255) NOT NULL,
    `description` TEXT,
    `category` VARCHAR(100),
    `proof_type` ENUM('screenshot', 'text', 'video', 'code', 'file') DEFAULT 'screenshot',
    `price_per_task` DECIMAL(24,4) NOT NULL,
    `total_budget` DECIMAL(24,4) NOT NULL,
    `remaining_budget` DECIMAL(24,4) NOT NULL,
    `total_count` INT UNSIGNED NOT NULL,
    `remaining_count` INT UNSIGNED NOT NULL,
    `status` VARCHAR(20) DEFAULT 'pending',
    `deleted_at` TIMESTAMP NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY `idx_ct_creator` (`creator_id`),
    KEY `idx_ct_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 3. SEO TASKS
DROP TABLE IF EXISTS `seo_tasks`;
CREATE TABLE `seo_tasks` (
    `id` INT(10) UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `creator_id` INT(10) UNSIGNED NOT NULL,
    `title` VARCHAR(255) NOT NULL,
    `keyword` VARCHAR(100) NOT NULL,
    `target_url` VARCHAR(500) NOT NULL,
    `price_per_click` DECIMAL(24,4) NOT NULL,
    `total_budget` DECIMAL(24,4) NOT NULL,
    `remaining_budget` DECIMAL(24,4) NOT NULL,
    `status` VARCHAR(20) DEFAULT 'pending',
    `deleted_at` TIMESTAMP NULL,
    `deadline` TIMESTAMP NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY `idx_seo_creator` (`creator_id`),
    KEY `idx_seo_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 4. BANNER ADS
DROP TABLE IF EXISTS `banner_ads`;
CREATE TABLE `banner_ads` (
    `id` INT(10) UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `creator_id` INT(10) UNSIGNED NOT NULL,
    `title` VARCHAR(255) NOT NULL,
    `image_url` VARCHAR(500) NOT NULL,
    `link_url` VARCHAR(500) NOT NULL,
    `placement` VARCHAR(50) NOT NULL,
    `price_per_day` DECIMAL(24,4) NOT NULL,
    `total_budget` DECIMAL(24,4) NOT NULL,
    `remaining_budget` DECIMAL(24,4) NOT NULL,
    `start_date` TIMESTAMP NULL,
    `end_date` TIMESTAMP NULL,
    `status` VARCHAR(20) DEFAULT 'pending',
    `deleted_at` TIMESTAMP NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY `idx_banner_creator` (`creator_id`),
    KEY `idx_banner_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;
