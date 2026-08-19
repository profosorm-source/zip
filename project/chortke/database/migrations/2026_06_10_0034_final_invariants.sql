-- CHORTKE MIGRATION PART 28: SYSTEM INVARIANTS (THE END)
-- Senior QA Architect Standardized
SET FOREIGN_KEY_CHECKS = 0;

DROP TABLE IF EXISTS `notification_templates`;
CREATE TABLE `notification_templates` (
    `id` INT(10) UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `slug` VARCHAR(100) UNIQUE NOT NULL,
    `title` VARCHAR(255),
    `body` TEXT NOT NULL,
    `sms_body` TEXT,
    `push_body` TEXT,
    `is_active` TINYINT(1) DEFAULT 1,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `dispute_evidence`;
CREATE TABLE `dispute_evidence` (
    `id` INT(10) UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `dispute_id` INT(10) UNSIGNED NOT NULL,
    `user_id` INT(10) UNSIGNED NOT NULL,
    `file_path` VARCHAR(255),
    `description` TEXT,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    KEY `idx_de_dispute` (`dispute_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `data_migration_logs`;
CREATE TABLE `data_migration_logs` (
    `id` INT(10) UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `migration_name` VARCHAR(255),
    `batch` INT(10) UNSIGNED,
    `applied_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `user_fraud_scores`;
CREATE TABLE `user_fraud_scores` (
    `id` INT(10) UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `user_id` INT(10) UNSIGNED NOT NULL,
    `category` VARCHAR(50) NOT NULL COMMENT 'velocity, proxy, behavior',
    `score_delta` DECIMAL(10,2),
    `reason` TEXT,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    KEY `idx_ufs_user` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;
