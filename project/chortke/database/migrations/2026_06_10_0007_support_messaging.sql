-- CHORTKE MIGRATION PART 5: SUPPORT & MESSAGING
SET FOREIGN_KEY_CHECKS = 0;

DROP TABLE IF EXISTS `ticket_categories`;
CREATE TABLE `ticket_categories` (
    `id` INT(10) UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `name` VARCHAR(100) NOT NULL,
    `slug` VARCHAR(100) UNIQUE NOT NULL,
    `is_active` TINYINT(1) DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;-- [REMOVED DUPLICATE tickets]
DROP TABLE IF EXISTS `ticket_messages`;
CREATE TABLE `ticket_messages` (
    `id` INT(10) UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `ticket_id` INT(10) UNSIGNED NOT NULL,
    `user_id` INT(10) UNSIGNED NOT NULL,
    `message` TEXT NOT NULL,
    `is_admin` TINYINT(1) DEFAULT 0,
    `attachments` JSON,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    KEY `idx_tm_ticket` (`ticket_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `direct_messages`;
CREATE TABLE `direct_messages` (
    `id` INT(10) UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `sender_id` INT(10) UNSIGNED NOT NULL,
    `recipient_id` INT(10) UNSIGNED NOT NULL,
    `message` TEXT NOT NULL,
    `is_read` TINYINT(1) DEFAULT 0,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    KEY `idx_dm_users` (`sender_id`, `recipient_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;
