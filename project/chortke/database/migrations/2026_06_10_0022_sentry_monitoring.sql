-- CHORTKE MIGRATION PART 15: SENTRY & MONITORING
SET FOREIGN_KEY_CHECKS = 0;

-- [REMOVED DUPLICATE sentry_issues]
DROP TABLE IF EXISTS `sentry_events`;
CREATE TABLE `sentry_events` (
    `id` INT(10) UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `issue_id` INT(10) UNSIGNED NOT NULL,
    `event_id` VARCHAR(64) UNIQUE NOT NULL,
    `request_id` VARCHAR(128) DEFAULT NULL,
    `level` VARCHAR(20) DEFAULT NULL,
    `message` LONGTEXT,
    `exception_type` VARCHAR(255) DEFAULT NULL,
    `stack_trace` LONGTEXT DEFAULT NULL,
    `breadcrumbs` LONGTEXT DEFAULT NULL,
    `user_context` LONGTEXT DEFAULT NULL,
    `request_context` LONGTEXT DEFAULT NULL,
    `device_context` LONGTEXT DEFAULT NULL,
    `tags` LONGTEXT DEFAULT NULL,
    `extra` LONGTEXT DEFAULT NULL,
    `environment` VARCHAR(50) DEFAULT NULL,
    `release_version` VARCHAR(100) DEFAULT NULL,
    `payload` JSON,
    `ip_address` VARCHAR(45),
    `user_agent` TEXT,
    `user_id` INT(10) UNSIGNED,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    KEY `idx_se_issue` (`issue_id`),
    KEY `idx_se_user` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;-- [REMOVED DUPLICATE system_telemetry]


SET FOREIGN_KEY_CHECKS = 1;
