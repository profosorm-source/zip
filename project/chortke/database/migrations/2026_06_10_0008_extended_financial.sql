-- CHORTKE MIGRATION: EXTENDED FINANCIAL & BANKING
SET FOREIGN_KEY_CHECKS = 0;

DROP TABLE IF EXISTS `bank_cards`;
CREATE TABLE `bank_cards` (
    `id` INT(10) UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `user_id` INT(10) UNSIGNED NOT NULL,
    `card_number` VARCHAR(255) NOT NULL,
    `sheba` VARCHAR(30),
    `bank_name` VARCHAR(100),
    `status` ENUM('pending', 'verified', 'rejected') DEFAULT 'pending',
    `is_default` TINYINT(1) DEFAULT 0,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    `deleted_at` TIMESTAMP NULL,
    UNIQUE KEY `user_card_unique` (`user_id`, `card_number`)
    -- CONSTRAINT `fk_bank_cards_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci; -- FK temporarily disabled

DROP TABLE IF EXISTS `withdrawals`;
CREATE TABLE `withdrawals` (
    `id` INT(10) UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `user_id` INT(10) UNSIGNED NOT NULL,
    `transaction_id` VARCHAR(64) UNIQUE,
    `amount` DECIMAL(24,8) NOT NULL,
    `currency` VARCHAR(10) DEFAULT 'irt',
    `card_id` INT(10) UNSIGNED,
    `method` VARCHAR(50) COMMENT 'card, crypto, sheba',
    `status` ENUM('pending', 'processing', 'completed', 'rejected', 'cancelled') DEFAULT 'pending',
    `fee` DECIMAL(24,8) DEFAULT 0,
    `final_amount` DECIMAL(24,8),
    `tracking_code` VARCHAR(100),
    `admin_note` TEXT,
    `rejection_reason` TEXT,
    `processed_by` INT(10) UNSIGNED,
    `processed_at` TIMESTAMP NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY `idx_w_user` (`user_id`),
    KEY `idx_w_status` (`status`)
    -- CONSTRAINT `fk_withdrawals_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci; -- FK temporarily disabled

DROP TABLE IF EXISTS `withdrawal_limits`;
CREATE TABLE `withdrawal_limits` (
    `id` INT(10) UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `user_id` INT(10) UNSIGNED UNIQUE NOT NULL,
    `daily_limit` DECIMAL(24,8),
    `monthly_limit` DECIMAL(24,8),
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    -- CONSTRAINT `fk_withdraw_limits_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci; -- FK temporarily disabled

DROP TABLE IF EXISTS `manual_deposits`;
CREATE TABLE `manual_deposits` (
    `id` INT(10) UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `user_id` INT(10) UNSIGNED NOT NULL,
    `amount` DECIMAL(24,8) NOT NULL,
    `currency` VARCHAR(10) DEFAULT 'irt',
    `tracking_code` VARCHAR(128) UNIQUE,
    `status` ENUM('pending', 'approved', 'rejected') DEFAULT 'pending',
    `admin_id` INT(10) UNSIGNED,
    `transaction_id` VARCHAR(64),
    `approved_at` TIMESTAMP NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY `idx_md_user` (`user_id`)
    -- CONSTRAINT `fk_manual_deposits_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci; -- FK temporarily disabled

DROP TABLE IF EXISTS `crypto_deposits`;
CREATE TABLE `crypto_deposits` (
    `id` INT(10) UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `user_id` INT(10) UNSIGNED NOT NULL,
    `amount` DECIMAL(24,8) NOT NULL,
    `currency` VARCHAR(10) NOT NULL,
    `tx_hash` VARCHAR(255) UNIQUE,
    `network` VARCHAR(50),
    `status` VARCHAR(20) DEFAULT 'pending',
    `verification_status` VARCHAR(20) DEFAULT 'unverified',
    `transaction_id` VARCHAR(64),
    `confirmed_at` TIMESTAMP NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY `idx_cd_user` (`user_id`)
    -- CONSTRAINT `fk_crypto_deposits_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci; -- FK temporarily disabled

DROP TABLE IF EXISTS `scheduled_payments`;
CREATE TABLE `scheduled_payments` (
    `id` INT(10) UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `user_id` INT(10) UNSIGNED NOT NULL,
    `amount` DECIMAL(24,8) NOT NULL,
    `currency` VARCHAR(10) DEFAULT 'irt',
    `frequency` ENUM('once', 'daily', 'weekly', 'monthly'),
    `next_run_at` TIMESTAMP NULL,
    `status` VARCHAR(20) DEFAULT 'active',
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY `idx_sp_user` (`user_id`)
    -- CONSTRAINT `fk_scheduled_payments_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci; -- FK temporarily disabled

SET FOREIGN_KEY_CHECKS = 1;
