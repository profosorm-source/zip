-- CHORTKE MIGRATION PART 18: TASK ACCOUNTING & ANALYTICS
SET FOREIGN_KEY_CHECKS = 0;

DROP TABLE IF EXISTS `custom_task_transactions`;
CREATE TABLE `custom_task_transactions` (
    `id` INT(10) UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `task_id` INT(10) UNSIGNED NOT NULL,
    `transaction_id` VARCHAR(64) UNIQUE,
    `amount` DECIMAL(24,4),
    `type` ENUM('payout', 'refund', 'fee'),
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `user_interactions`;
CREATE TABLE `user_interactions` (
    `id` INT(10) UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `user_id` INT(10) UNSIGNED NOT NULL,
    `target_type` VARCHAR(50),
    `target_id` INT(10) UNSIGNED,
    `interaction_type` VARCHAR(50) COMMENT 'click, view, hover',
    `duration_seconds` INT(10) UNSIGNED DEFAULT 0,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `analytics_snapshots`;
CREATE TABLE `analytics_snapshots` (
    `id` INT(10) UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `metric` VARCHAR(100) NOT NULL,
    `value` DECIMAL(24,4),
    `dimension` VARCHAR(50),
    `dimension_value` VARCHAR(255),
    `snapshot_date` DATE NOT NULL,
    UNIQUE KEY `metric_snapshot` (`metric`, `snapshot_date`, `dimension`, `dimension_value`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;
