-- CHORTKE MIGRATION PART 21: FORENSIC & FORENSIC ANALYTICS
SET FOREIGN_KEY_CHECKS = 0;

-- [REMOVED DUPLICATE file_access_logs]
DROP TABLE IF EXISTS `influencer_verifications`;
CREATE TABLE `influencer_verifications` (
    `id` INT(10) UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `influencer_id` INT(10) UNSIGNED NOT NULL,
    `verification_type` VARCHAR(50),
    `proof_data` JSON,
    `status` VARCHAR(20) DEFAULT 'pending',
    `admin_id` INT(10) UNSIGNED,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    KEY `idx_iv_influencer` (`influencer_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;-- [REMOVED DUPLICATE lottery_chance_logs]
-- [REMOVED DUPLICATE lottery_daily_numbers]
DROP TABLE IF EXISTS `message_moderation_logs`;
CREATE TABLE `message_moderation_logs` (
    `id` INT(10) UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `message_type` VARCHAR(50) COMMENT 'dm, ticket, comment',
    `message_id` INT(10) UNSIGNED NOT NULL,
    `admin_id` INT(10) UNSIGNED NOT NULL,
    `action` ENUM('approved', 'rejected', 'hidden', 'edited'),
    `reason` TEXT,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `vitrine_requests`;
CREATE TABLE `vitrine_requests` (
    `id` INT(10) UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `user_id` INT(10) UNSIGNED NOT NULL,
    `listing_id` INT(10) UNSIGNED NOT NULL,
    `status` VARCHAR(20) DEFAULT 'pending',
    `message` TEXT,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    KEY `idx_vr_listing` (`listing_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `user_fraud_flags`;
CREATE TABLE `user_fraud_flags` (
    `id` INT(10) UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `user_id` INT(10) UNSIGNED NOT NULL,
    `flag_type` VARCHAR(100),
    `severity` INT(10) DEFAULT 1,
    `metadata` JSON,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    KEY `idx_uff_user` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;
