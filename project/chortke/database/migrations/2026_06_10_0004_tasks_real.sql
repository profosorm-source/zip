-- CHORTKE MIGRATION: ADS & TASKS (STRICT CODE-FIRST)
SET FOREIGN_KEY_CHECKS = 0;

DROP TABLE IF EXISTS `ads`;
CREATE TABLE `ads` (
    `id` INT(10) UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `user_id` INT(10) UNSIGNED NOT NULL,
    `title` VARCHAR(255) NOT NULL,
    `description` TEXT,
    `image_path` VARCHAR(255) DEFAULT NULL,
    `keyword` VARCHAR(100),
    `type` ENUM('social', 'seo', 'custom_task', 'banner', 'youtube') NOT NULL,
    `platform` VARCHAR(50),
    `task_type` VARCHAR(100),
    `price_per_task` DECIMAL(24,4) DEFAULT 0,
    `price_per_click` DECIMAL(24,4) DEFAULT 0,
    `total_budget` DECIMAL(24,4) DEFAULT 0,
    `remaining_budget` DECIMAL(24,4) DEFAULT 0,
    `total_count` INT UNSIGNED DEFAULT 0,
    `remaining_count` INT UNSIGNED DEFAULT 0,
    `pending_count` INT UNSIGNED DEFAULT 0,
    `completed_count` INT UNSIGNED DEFAULT 0,
    `clicks_count` INT UNSIGNED DEFAULT 0,
    `impressions` INT UNSIGNED DEFAULT 0,
    `clicks` INT UNSIGNED DEFAULT 0,
    `ctr` DECIMAL(5,2) DEFAULT 0,
    `placement` VARCHAR(50),
    `status` VARCHAR(20) DEFAULT 'pending',
    `is_active` TINYINT(1) DEFAULT 1,
    `start_date` TIMESTAMP NULL,
    `end_date` TIMESTAMP NULL,
    `deadline` TIMESTAMP NULL,
    `metadata` JSON,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    `deleted_at` TIMESTAMP NULL,
    KEY `idx_ad_user` (`user_id`),
    KEY `idx_ad_type` (`type`),
    KEY `idx_ad_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `social_task_executions`;
CREATE TABLE `social_task_executions` (
    `id` INT(10) UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `ad_id` INT(10) UNSIGNED NOT NULL,
    `executor_id` INT(10) UNSIGNED NOT NULL,
    `status` ENUM('pending', 'submitted', 'approved', 'rejected', 'cancelled', 'expired', 'in_progress') DEFAULT 'pending',
    `proof_url` TEXT,
    `proof_text` TEXT,
    `reward_amount` DECIMAL(24,4),
    `reward_currency` VARCHAR(10) DEFAULT 'irt',
    `idempotency_key` VARCHAR(100) UNIQUE,
    `reminder_sent` TIMESTAMP NULL,
    `reviewed_at` TIMESTAMP NULL,
    `auto_approved_at` TIMESTAMP NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY `idx_ste_ad` (`ad_id`),
    KEY `idx_ste_user` (`executor_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `custom_task_submissions`;
CREATE TABLE `custom_task_submissions` (
    `id` INT(10) UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `task_id` INT(10) UNSIGNED NOT NULL,
    `worker_id` INT(10) UNSIGNED NOT NULL,
    `status` ENUM('pending', 'submitted', 'approved', 'rejected', 'cancelled', 'expired', 'in_progress') DEFAULT 'pending',
    `proof_url` TEXT,
    `proof_text` TEXT,
    `reward_amount` DECIMAL(24,4),
    `reward_currency` VARCHAR(10) DEFAULT 'irt',
    `idempotency_key` VARCHAR(100) UNIQUE,
    `submitted_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `approved_at` TIMESTAMP NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY `idx_cts_task` (`task_id`),
    KEY `idx_cts_worker` (`worker_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;
