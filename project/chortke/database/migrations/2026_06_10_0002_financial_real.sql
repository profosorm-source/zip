-- CHORTKE MIGRATION: FINANCIAL CORE (STRICT CODE-FIRST)
SET FOREIGN_KEY_CHECKS = 0;

-- Wallets table
DROP TABLE IF EXISTS `wallets`;
CREATE TABLE `wallets` (
    `id` INT(10) UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `user_id` INT(10) UNSIGNED UNIQUE NOT NULL,
    `balance_irt` DECIMAL(24,8) DEFAULT 0.00000000,
    `balance_usdt` DECIMAL(24,8) DEFAULT 0.00000000,
    `locked_irt` DECIMAL(24,8) DEFAULT 0.00000000,
    `locked_usdt` DECIMAL(24,8) DEFAULT 0.00000000,
    `is_frozen` TINYINT(1) DEFAULT 0,
    `last_withdrawal_at` TIMESTAMP NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Transactions table
DROP TABLE IF EXISTS `transactions`;
CREATE TABLE `transactions` (
    `id` INT(10) UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `transaction_id` VARCHAR(64) UNIQUE NOT NULL,
    `user_id` INT(10) UNSIGNED NOT NULL,
    `type` VARCHAR(50) NOT NULL,
    `currency` ENUM('irt', 'usdt') DEFAULT 'irt',
    `amount` DECIMAL(24,8) NOT NULL,
    `balance_before` DECIMAL(24,8),
    `balance_after` DECIMAL(24,8),
    `status` VARCHAR(20) DEFAULT 'pending',
    `description` TEXT,
    `gateway` VARCHAR(50),
    `gateway_transaction_id` VARCHAR(128),
    `ref_id` VARCHAR(128),
    `ref_type` VARCHAR(100),
    `request_id` VARCHAR(64),
    `ip_address` VARCHAR(45),
    `device_fingerprint` VARCHAR(128),
    `idempotency_key` VARCHAR(128) UNIQUE,
    `metadata` JSON,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    `completed_at` TIMESTAMP NULL,
    KEY `idx_tx_user` (`user_id`),
    KEY `idx_tx_type` (`type`),
    KEY `idx_tx_status` (`status`),
    KEY `idx_tx_created_at` (`created_at`)
    -- FK to users will be added later
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Ledger entries table
DROP TABLE IF EXISTS `ledger_entries`;
CREATE TABLE `ledger_entries` (
    `id` INT(10) UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `transaction_id` VARCHAR(64) NOT NULL,
    `account` VARCHAR(100) NOT NULL,
    `debit` DECIMAL(24,8) DEFAULT 0,
    `credit` DECIMAL(24,8) DEFAULT 0,
    `currency` VARCHAR(10) DEFAULT 'irt',
    `description` TEXT,
    `metadata` JSON,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY `idx_le_tx` (`transaction_id`)
    -- CONSTRAINT `fk_ledger_transaction` FOREIGN KEY (`transaction_id`) REFERENCES `transactions` (`transaction_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci; -- FK temporarily disabled

SET FOREIGN_KEY_CHECKS = 1;
