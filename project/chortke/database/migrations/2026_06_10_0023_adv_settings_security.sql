-- CHORTKE MIGRATION PART 16: ADVANCED SETTINGS & SESSIONS
SET FOREIGN_KEY_CHECKS = 0;

DROP TABLE IF EXISTS `system_settings`;
CREATE TABLE `system_settings` (
    `id` INT(10) UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `key` VARCHAR(191) UNIQUE NOT NULL,
    `value` LONGTEXT,
    `group` VARCHAR(50) DEFAULT 'general',
    `type` VARCHAR(20) DEFAULT 'string',
    `description` TEXT,
    `is_public` TINYINT(1) NOT NULL DEFAULT 0,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `security_events`;
CREATE TABLE `security_events` (
    `id` INT(10) UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `user_id` INT(10) UNSIGNED,
    `event_type` VARCHAR(100) NOT NULL,
    `severity` ENUM('low', 'medium', 'high', 'critical'),
    `details` JSON,
    `ip_address` VARCHAR(45),
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    KEY `idx_sec_event_user` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `settings_audit_trail`;
CREATE TABLE `settings_audit_trail` (
    `id` INT(10) UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `admin_id` INT(10) UNSIGNED NOT NULL,
    `setting_key` VARCHAR(191) NOT NULL,
    `old_value` LONGTEXT,
    `new_value` LONGTEXT,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;-- [REMOVED DUPLICATE user_vacations]


SET FOREIGN_KEY_CHECKS = 1;
