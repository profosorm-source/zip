-- CHORTKE MIGRATION PART 39: FINAL MISSING TABLES (Senior QA Architect)
SET FOREIGN_KEY_CHECKS = 0;

DROP TABLE IF EXISTS `analytics`;
CREATE TABLE `analytics` (
    `id` INT(10) UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `metric_name` VARCHAR(100) NOT NULL,
    `metric_value` DECIMAL(24,8),
    `dimension` VARCHAR(100),
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `crypto_deposit_intents`;
CREATE TABLE `crypto_deposit_intents` (
    `id` INT(10) UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `user_id` INT(10) UNSIGNED NOT NULL,
    `currency` VARCHAR(10) NOT NULL,
    `amount` DECIMAL(24,8),
    `address` VARCHAR(255),
    `tag` VARCHAR(100),
    `status` VARCHAR(20) DEFAULT 'pending',
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    KEY `idx_cdi_user` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `file_logs`;
CREATE TABLE `file_logs` (
    `id` INT(10) UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `file_id` INT(10) UNSIGNED,
    `user_id` INT(10) UNSIGNED,
    `action` VARCHAR(50),
    `ip_address` VARCHAR(45),
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `score_events`;
CREATE TABLE `score_events` (
    `id` INT(10) UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `entity_id` INT(10) UNSIGNED NOT NULL,
    `domain` VARCHAR(50) NOT NULL,
    `entity_type` VARCHAR(50) NOT NULL DEFAULT 'user',
    `delta` DECIMAL(24,8) NOT NULL,
    `reason` VARCHAR(255),
    `metadata` JSON,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    KEY `idx_se_entity` (`entity_id`, `entity_type`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `system_logs`;
CREATE TABLE `system_logs` (
    `id` INT(10) UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `level` VARCHAR(20),
    `channel` VARCHAR(50),
    `message` TEXT,
    `context` JSON,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `task_analytics`;
CREATE TABLE `task_analytics` (
    `id` INT(10) UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `task_id` INT(10) UNSIGNED NOT NULL,
    `views` INT(10) UNSIGNED DEFAULT 0,
    `completions` INT(10) UNSIGNED DEFAULT 0,
    `last_activity_at` TIMESTAMP NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY `uk_task_id` (`task_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `task_favorites`;
CREATE TABLE `task_favorites` (
    `id` INT(10) UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `user_id` INT(10) UNSIGNED NOT NULL,
    `task_id` INT(10) UNSIGNED NOT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY `uk_user_task` (`user_id`, `task_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `transaction_queries`;
CREATE TABLE `transaction_queries` (
    `id` INT(10) UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `user_id` INT(10) UNSIGNED NOT NULL,
    `query_hash` VARCHAR(64),
    `results_count` INT(10) UNSIGNED,
    `execution_time_ms` INT(10),
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `vitrine_listings`;
CREATE TABLE `vitrine_listings` (
    `id` INT(10) UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `user_id` INT(10) UNSIGNED NOT NULL,
    `title` VARCHAR(255) NOT NULL,
    `price` DECIMAL(24,8),
    `status` VARCHAR(20) DEFAULT 'active',
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;
