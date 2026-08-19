-- CHORTKE MIGRATION PART 22: SMART ALERTS & APM (FINAL INFRA)
-- Senior QA Architect Standardized
SET FOREIGN_KEY_CHECKS = 0;

-- 1. سیستم هشدارهای داخلی (System Alerts)
DROP TABLE IF EXISTS `system_alerts`;
CREATE TABLE `system_alerts` (
    `id` INT(10) UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `alert_type` VARCHAR(50) NOT NULL COMMENT 'security, performance, financial',
    `severity` ENUM('low', 'medium', 'high', 'critical') DEFAULT 'medium',
    `title` VARCHAR(255) NOT NULL,
    `message` TEXT,
    `metadata` JSON,
    `fingerprint` VARCHAR(64),
    `event_id` VARCHAR(64),
    `environment` VARCHAR(50),
    `is_sent` TINYINT(1) DEFAULT 0,
    `acknowledged_at` TIMESTAMP NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    KEY `idx_alert_severity` (`severity`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `alert_rules`;
CREATE TABLE `alert_rules` (
    `id` INT(10) UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `rule_name` VARCHAR(191) NOT NULL,
    `condition` VARCHAR(100) NOT NULL COMMENT 'threshold, percentage',
    `threshold_value` DECIMAL(10,2),
    `time_window_minutes` INT DEFAULT 60,
    `is_active` TINYINT(1) DEFAULT 1,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `notification_channels`;
CREATE TABLE `notification_channels` (
    `id` INT(10) UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `channel_type` ENUM('telegram', 'email', 'sms', 'webhook'),
    `config` JSON,
    `is_active` TINYINT(1) DEFAULT 1,
    `alert_levels` JSON DEFAULT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `notification_history`;
CREATE TABLE `notification_history` (
    `id` INT(10) UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `user_id` INT(10) UNSIGNED,
    `channel_id` INT(10) UNSIGNED,
    `status` ENUM('sent', 'failed', 'delivered'),
    `error_message` TEXT,
    `sent_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    KEY `idx_notif_hist_user` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `performance_transactions`;
CREATE TABLE `performance_transactions` (
    `id` INT(10) UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `transaction_id` VARCHAR(64) NOT NULL,
    `name` VARCHAR(255) NOT NULL COMMENT 'Route or Job Name',
    `duration_ms` DECIMAL(10,2),
    `db_queries_count` INT,
    `memory_peak_mb` DECIMAL(10,2),
    `status` VARCHAR(20) DEFAULT 'ok',
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    KEY `idx_perf_tx_id` (`transaction_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `sentry_breadcrumbs`;
CREATE TABLE `sentry_breadcrumbs` (
    `id` INT(10) UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `event_id` VARCHAR(64) NOT NULL,
    `category` VARCHAR(50),
    `message` TEXT,
    `data` JSON,
    `level` VARCHAR(20),
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    KEY `idx_breadcrumb_event` (`event_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `kpi_metrics`;
CREATE TABLE `kpi_metrics` (
    `id` INT(10) UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `metric_key` VARCHAR(100) NOT NULL,
    `metric_value` DECIMAL(24,4),
    `recorded_date` DATE NOT NULL,
    UNIQUE KEY `metric_day` (`metric_key`, `recorded_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `data_exports`;
CREATE TABLE `data_exports` (
    `id` INT(10) UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `user_id` INT(10) UNSIGNED NOT NULL,
    `format` ENUM('json', 'csv', 'excel') NOT NULL,
    `status` ENUM('pending', 'processing', 'completed', 'failed') DEFAULT 'pending',
    `file_path` VARCHAR(255),
    `expires_at` TIMESTAMP NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    KEY `idx_export_user` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;
