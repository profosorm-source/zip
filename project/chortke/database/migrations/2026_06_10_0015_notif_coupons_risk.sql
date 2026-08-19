-- CHORTKE MIGRATION PART 10: NOTIFICATIONS & HARDENING
SET FOREIGN_KEY_CHECKS = 0;

DROP TABLE IF EXISTS `notifications`;
CREATE TABLE `notifications` (
    `id` INT(10) UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `user_id` INT(10) UNSIGNED NOT NULL,
    `type` VARCHAR(50),
    `title` VARCHAR(255),
    `message` TEXT,
    `data` JSON,
    `action_url` VARCHAR(2048),
    `action_text` VARCHAR(255),
    `priority` VARCHAR(20) DEFAULT 'normal',
    `is_read` TINYINT(1) DEFAULT 0,
    `is_archived` TINYINT(1) DEFAULT 0,
    `expires_at` DATETIME DEFAULT NULL,
    `channel` VARCHAR(50) DEFAULT 'in_app',
    `read_at` DATETIME DEFAULT NULL,
    `sent_at` DATETIME DEFAULT NULL,
    `archived_at` DATETIME DEFAULT NULL,
    `updated_at` DATETIME DEFAULT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    KEY `idx_notif_user_read` (`user_id`, `is_read`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `notification_preferences_v2`;
CREATE TABLE `notification_preferences_v2` (
    `user_id` INT(10) UNSIGNED PRIMARY KEY,
    `in_app_enabled` TINYINT(1) DEFAULT 1,
    `email_enabled` TINYINT(1) DEFAULT 1,
    `push_enabled` TINYINT(1) DEFAULT 1,
    `sms_enabled` TINYINT(1) DEFAULT 0,
    `dnd_enabled` TINYINT(1) DEFAULT 0,
    `dnd_start` TIME DEFAULT '23:00:00',
    `dnd_end` TIME DEFAULT '07:00:00',
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;-- [REMOVED DUPLICATE coupons]
DROP TABLE IF EXISTS `account_deletion_logs`;
CREATE TABLE `account_deletion_logs` (
    `id` INT(10) UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `user_id` INT(10) UNSIGNED NOT NULL,
    `reason` TEXT,
    `requested_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `expires_at` TIMESTAMP NULL DEFAULT NULL,
    `deleted_at` TIMESTAMP NULL,
    `status` ENUM('pending', 'completed', 'cancelled') DEFAULT 'pending'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `risk_policies`;
CREATE TABLE `risk_policies` (
    `id` INT(10) UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `domain` VARCHAR(50) NOT NULL COMMENT 'fraud, payment, auth',
    `key_name` VARCHAR(100) NOT NULL,
    `value` TEXT,
    `value_type` VARCHAR(20) NOT NULL DEFAULT 'string',
    `description` TEXT,
    `updated_by` INT(10) UNSIGNED DEFAULT NULL,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY `risk_key` (`domain`, `key_name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;
