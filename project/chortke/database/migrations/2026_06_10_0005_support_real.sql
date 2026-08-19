-- CHORTKE MIGRATION: SUPPORT & DISPUTES (STRICT CODE-FIRST)
SET FOREIGN_KEY_CHECKS = 0;

DROP TABLE IF EXISTS `tickets`;
CREATE TABLE `tickets` (
    `id` INT(10) UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `user_id` INT(10) UNSIGNED NOT NULL,
    `ticket_id` VARCHAR(20) UNIQUE NOT NULL,
    `subject` VARCHAR(255) NOT NULL,
    `priority` ENUM('low', 'medium', 'high', 'critical') DEFAULT 'medium',
    `status` ENUM('open', 'pending', 'replied', 'closed') DEFAULT 'open',
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY `idx_tk_user` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;-- [REMOVED DUPLICATE disputes]


SET FOREIGN_KEY_CHECKS = 1;
