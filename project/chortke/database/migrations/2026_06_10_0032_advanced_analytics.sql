-- CHORTKE MIGRATION PART 26: ANALYTICS & SOCIAL ENGINE
-- Senior QA Architect Standardized
SET FOREIGN_KEY_CHECKS = 0;

-- [REMOVED DUPLICATE social_task_analytics]
-- [REMOVED DUPLICATE custom_task_analytics]
DROP TABLE IF EXISTS `platform_stats`;
CREATE TABLE `platform_stats` (
    `id` INT(10) UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `platform` VARCHAR(50) NOT NULL,
    `metric_name` VARCHAR(100) NOT NULL,
    `metric_value` DECIMAL(24,4) DEFAULT 0,
    `recorded_at` DATE NOT NULL,
    UNIQUE KEY `idx_ps_plat_metric` (`platform`, `metric_name`, `recorded_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;
