-- CHORTKE MIGRATION PART 40: INFRASTRUCTURE & EXTENDED ENTITIES (Standardized INT10)
SET FOREIGN_KEY_CHECKS = 0;

-- 1. OUTBOX EVENTS (Atomic Transactional Events)
DROP TABLE IF EXISTS `outbox_events`;
CREATE TABLE `outbox_events` (
    `id`              INT(10) UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `aggregate_type`  VARCHAR(80)     NOT NULL,
    `aggregate_id`    VARCHAR(128)    NOT NULL,
    `event_type`      VARCHAR(120)    NOT NULL,
    `payload`         LONGTEXT        NULL,
    `status`          ENUM('pending','processing','published','failed','dlq') NOT NULL DEFAULT 'pending',
    `attempts`        INT(10) UNSIGNED NOT NULL DEFAULT 0,
    `last_error`      VARCHAR(2000)   NULL,
    `available_at`    DATETIME        NOT NULL,
    `published_at`    DATETIME        NULL,
    `created_at`      TIMESTAMP       DEFAULT CURRENT_TIMESTAMP,
    `updated_at`      TIMESTAMP       DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY `idx_outbox_pickup`     (`status`, `available_at`, `attempts`),
    KEY `idx_outbox_aggregate`  (`aggregate_type`, `aggregate_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 2. SAGA EXECUTIONS (Distributed Transactions)
DROP TABLE IF EXISTS `saga_executions`;
CREATE TABLE `saga_executions` (
    `id`             VARCHAR(64) PRIMARY KEY,
    `saga_name`      VARCHAR(100) NOT NULL,
    `status`         VARCHAR(20) NOT NULL,
    `payload`        JSON,
    `executed_steps` JSON,
    `created_at`     TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at`     TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY `idx_saga_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 3. TWO-FACTOR CODES (Temporary & Recovery)
DROP TABLE IF EXISTS `two_factor_codes`;
CREATE TABLE `two_factor_codes` (
    `id` INT(10) UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `user_id` INT(10) UNSIGNED NOT NULL,
    `code` VARCHAR(255) NOT NULL,
    `used` TINYINT(1) DEFAULT 0,
    `expires_at` TIMESTAMP NOT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    KEY `idx_2fa_user_active` (`user_id`, `used`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 4. ADMIN ACCESS LOGS
DROP TABLE IF EXISTS `admin_access_logs`;
CREATE TABLE `admin_access_logs` (
    `id` INT(10) UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `user_id` INT(10) UNSIGNED NOT NULL,
    `action` VARCHAR(100),
    `resource` VARCHAR(100),
    `resource_id` VARCHAR(64),
    `ip_address` VARCHAR(45),
    `user_agent` TEXT,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    KEY `idx_admin_logs_user` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 5. VIDEO FINGERPRINTS (Anti-Fraud for Tube/Ads)
DROP TABLE IF EXISTS `video_fingerprints`;
CREATE TABLE `video_fingerprints` (
    `id` INT(10) UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `platform` VARCHAR(50) NOT NULL,
    `video_id` VARCHAR(255) NOT NULL,
    `url_hash` CHAR(64) NOT NULL,
    `metadata_hash` CHAR(64) NULL,
    `combined_hash` CHAR(64) NOT NULL,
    `method` VARCHAR(50) NOT NULL DEFAULT 'url_hash',
    `confidence` DECIMAL(3,2) NOT NULL DEFAULT 0.50,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY `uk_combined` (`combined_hash`),
    KEY `idx_v_platform_video` (`platform`, `video_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 6. REFERRAL EXTENSIONS
DROP TABLE IF EXISTS `referral_clicks`;
CREATE TABLE `referral_clicks` (
    `id` INT(10) UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `referrer_id` INT(10) UNSIGNED NOT NULL,
    `click_user_id` INT(10) UNSIGNED NULL,
    `ip_address` VARCHAR(45),
    `user_agent` TEXT,
    `referred_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `converted` TINYINT(1) DEFAULT 0,
    KEY `idx_rc_referrer` (`referrer_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `referral_milestones`;
CREATE TABLE `referral_milestones` (
    `id` INT(10) UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `title` VARCHAR(255) NOT NULL,
    `threshold_value` INT(10) UNSIGNED NOT NULL,
    `milestone_type` VARCHAR(50), -- e.g. 'referral_count', 'total_commission'
    `reward_amount` DECIMAL(24,8) DEFAULT 0,
    `currency` ENUM('irt', 'usdt') DEFAULT 'irt',
    `is_active` TINYINT(1) DEFAULT 1,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `referral_activity_logs`;
CREATE TABLE `referral_activity_logs` (
    `id` INT(10) UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `user_id` INT(10) UNSIGNED NOT NULL,
    `action` VARCHAR(50) NOT NULL,
    `ip_address` VARCHAR(45),
    `metadata` JSON,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    KEY `idx_ral_user` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 7. SECURITY & AUTH (Missing)
DROP TABLE IF EXISTS `password_resets`;
CREATE TABLE `password_resets` (
    `email` VARCHAR(255) NOT NULL,
    `token` VARCHAR(255) NOT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`email`),
    KEY `idx_reset_token` (`token`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `user_oauth`;
CREATE TABLE `user_oauth` (
    `id` INT(10) UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `user_id` INT(10) UNSIGNED NOT NULL,
    `provider` VARCHAR(50) NOT NULL,
    `provider_user_id` VARCHAR(191) NOT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY `uk_oauth_provider` (`provider`, `provider_user_id`),
    KEY `idx_oauth_user` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 8. APPEALS & REPORTS
DROP TABLE IF EXISTS `appeals`;
CREATE TABLE `appeals` (
    `id` INT(10) UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `user_id` INT(10) UNSIGNED NOT NULL,
    `subject` VARCHAR(255),
    `message` TEXT,
    `status` ENUM('pending', 'approved', 'rejected') DEFAULT 'pending',
    `reviewed_by` INT(10) UNSIGNED NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    KEY `idx_appeal_user` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `message_reports`;
CREATE TABLE `message_reports` (
    `id` INT(10) UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `reporter_id` INT(10) UNSIGNED NOT NULL,
    `message_id` INT(10) UNSIGNED NOT NULL,
    `reason` VARCHAR(255),
    `status` VARCHAR(20) DEFAULT 'pending',
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    KEY `idx_mr_reporter` (`reporter_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;
