-- CHORTKE MIGRATION: FINAL REMAINING TABLES
SET FOREIGN_KEY_CHECKS = 0;

-- [REMOVED DUPLICATE account_deletion_logs]
DROP TABLE IF EXISTS `content_revenues`;
CREATE TABLE `content_revenues` (
    `id` INT(10) UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `user_id` INT(10) UNSIGNED NOT NULL,
    `content_id` INT(10) UNSIGNED NOT NULL,
    `amount` DECIMAL(24,8),
    `status` VARCHAR(20) DEFAULT 'pending',
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    -- CONSTRAINT `fk_content_rev_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci; -- FK temporarily disabled

DROP TABLE IF EXISTS `content_agreements`;
CREATE TABLE `content_agreements` (
    `id` INT(10) UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `user_id` INT(10) UNSIGNED NOT NULL,
    `content_id` INT(10) UNSIGNED NOT NULL,
    `agreed_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    KEY `idx_ca_user` (`user_id`)
    -- CONSTRAINT `fk_content_agr_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci; -- FK temporarily disabled

DROP TABLE IF EXISTS `coupon_redemptions`;
CREATE TABLE `coupon_redemptions` (
    `id` INT(10) UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `user_id` INT(10) UNSIGNED NOT NULL,
    `coupon_id` INT(10) UNSIGNED NOT NULL,
    `redeemed_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    KEY `idx_cr_user` (`user_id`)
    -- CONSTRAINT `fk_coupon_red_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci; -- FK temporarily disabled

DROP TABLE IF EXISTS `failed_jobs`;
CREATE TABLE `failed_jobs` (
    `id` INT(10) UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `connection` TEXT,
    `queue` TEXT,
    `payload` LONGTEXT,
    `exception` LONGTEXT,
    `failed_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `file_access_logs`;
CREATE TABLE `file_access_logs` (
    `id` INT(10) UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `user_id` INT(10) UNSIGNED,
    `file_path` VARCHAR(255),
    `access_type` VARCHAR(50),
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    -- CONSTRAINT `fk_file_access_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci; -- FK temporarily disabled

DROP TABLE IF EXISTS `investment_profits`;
CREATE TABLE `investment_profits` (
    `id` INT(10) UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `investment_id` INT(10) UNSIGNED NOT NULL,
    `amount` DECIMAL(24,8),
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `investment_withdrawals`;
CREATE TABLE `investment_withdrawals` (
    `id` INT(10) UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `investment_id` INT(10) UNSIGNED NOT NULL,
    `amount` DECIMAL(24,8),
    `status` VARCHAR(20) DEFAULT 'pending',
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `lottery_chance_logs`;
CREATE TABLE `lottery_chance_logs` (
    `id` INT(10) UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `user_id` INT(10) UNSIGNED NOT NULL,
    `change_amount` INT(11),
    `reason` VARCHAR(255),
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    -- CONSTRAINT `fk_lottery_chance_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci; -- FK temporarily disabled

DROP TABLE IF EXISTS `lottery_daily_numbers`;
CREATE TABLE `lottery_daily_numbers` (
    `id` INT(10) UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `winning_number` INT(11),
    `date` DATE UNIQUE,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- [REMOVED DUPLICATE notification_preferences]
DROP TABLE IF EXISTS `payment_gateways`;
CREATE TABLE `payment_gateways` (
    `id` INT(10) UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `name` VARCHAR(100),
    `slug` VARCHAR(50) UNIQUE,
    `config` JSON,
    `is_active` TINYINT(1) DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `payment_logs`;
CREATE TABLE `payment_logs` (
    `id` INT(10) UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `user_id` INT(10) UNSIGNED,
    `gateway` VARCHAR(50),
    `amount` DECIMAL(24,8),
    `payload` JSON,
    `status` VARCHAR(20),
    `authority` VARCHAR(128) UNIQUE,
    `ref_id` VARCHAR(128),
    `request_data` JSON,
    `response_data` JSON,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    `paid_at` TIMESTAMP NULL,
    KEY `idx_pay_user` (`user_id`),
    KEY `idx_pay_status` (`status`),
    KEY `idx_pay_auth` (`authority`)
    -- CONSTRAINT `fk_payment_logs_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci; -- FK temporarily disabled

DROP TABLE IF EXISTS `seo_executions`;
CREATE TABLE `seo_executions` (
    `id` INT(10) UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `ad_id` INT(10) UNSIGNED NOT NULL,
    `user_id` INT(10) UNSIGNED NOT NULL,
    `status` VARCHAR(20) DEFAULT 'pending',
    `time_score` DECIMAL(10,2) DEFAULT 0.00,
    `scroll_score` DECIMAL(10,2) DEFAULT 0.00,
    `interaction_score` DECIMAL(10,2) DEFAULT 0.00,
    `quality_score` DECIMAL(10,2) DEFAULT 0.00,
    `final_score` DECIMAL(10,2) DEFAULT 0.00,
    `payout_amount` DECIMAL(24,8) DEFAULT 0.00000000,
    `engagement_data` LONGTEXT,
    `fraud_flags` LONGTEXT,
    `ip_address` VARCHAR(45) DEFAULT NULL,
    `device_fingerprint` VARCHAR(191) DEFAULT NULL,
    `started_at` TIMESTAMP NULL DEFAULT NULL,
    `completed_at` TIMESTAMP NULL DEFAULT NULL,
    `updated_at` TIMESTAMP NULL DEFAULT NULL,
    `execution_date` DATE DEFAULT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    KEY `idx_se_user` (`user_id`)
    -- CONSTRAINT `fk_seo_exec_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci; -- FK temporarily disabled

-- [REMOVED DUPLICATE story_orders]
DROP TABLE IF EXISTS `user_settings`;
CREATE TABLE `user_settings` (
    `id` INT(10) UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `user_id` INT(10) UNSIGNED NOT NULL,
    `setting_key` VARCHAR(191),
    `setting_value` TEXT,
    UNIQUE KEY `us_unique` (`user_id`, `setting_key`)
    -- CONSTRAINT `fk_user_settings_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci; -- FK temporarily disabled

DROP TABLE IF EXISTS `user_vacations`;
CREATE TABLE `user_vacations` (
    `id` INT(10) UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `user_id` INT(10) UNSIGNED NOT NULL,
    `start_date` DATE,
    `end_date` DATE,
    `duration_days` INT(10) UNSIGNED DEFAULT 3,
    `cost_paid` DECIMAL(24,8) DEFAULT 0.00000000,
    `status` VARCHAR(50) DEFAULT 'active',
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    -- CONSTRAINT `fk_user_vacations_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci; -- FK temporarily disabled

DROP TABLE IF EXISTS `withdrawal_reviews`;
CREATE TABLE `withdrawal_reviews` (
    `id` INT(10) UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `withdrawal_id` INT(10) UNSIGNED NOT NULL,
    `status` VARCHAR(20) DEFAULT 'pending',
    `admin_id` INT(10) UNSIGNED,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `system_telemetry`;
CREATE TABLE `system_telemetry` (
    `id` INT(10) UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `metric_name` VARCHAR(100),
    `metric_value` DECIMAL(24,8),
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;
