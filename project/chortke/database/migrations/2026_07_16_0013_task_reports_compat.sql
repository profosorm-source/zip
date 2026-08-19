-- Compatibility table for custom task report moderation pages.
CREATE TABLE IF NOT EXISTS `task_reports` (
  `id` INT(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `task_id` INT(10) UNSIGNED NOT NULL,
  `reporter_id` INT(10) UNSIGNED NOT NULL,
  `reason` VARCHAR(100) NOT NULL,
  `description` TEXT NULL,
  `status` VARCHAR(30) NOT NULL DEFAULT 'pending',
  `admin_id` INT(10) UNSIGNED NULL,
  `admin_note` TEXT NULL,
  `resolved_at` DATETIME NULL,
  `created_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_task_reports_task` (`task_id`),
  KEY `idx_task_reports_reporter` (`reporter_id`),
  KEY `idx_task_reports_status` (`status`),
  KEY `idx_task_reports_reason` (`reason`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
