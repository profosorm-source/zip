-- CHORTKE MIGRATION PART 11: ESCROW & FINAL FINANCIALS
SET FOREIGN_KEY_CHECKS = 0;

DROP TABLE IF EXISTS `escrows`;
CREATE TABLE `escrows` (
    `id` INT(10) UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `transaction_id` VARCHAR(64) UNIQUE,
    `buyer_id` INT(10) UNSIGNED NOT NULL,
    `seller_id` INT(10) UNSIGNED NOT NULL,
    `amount` DECIMAL(24,8) NOT NULL,
    `currency` VARCHAR(10) DEFAULT 'irt',
    `status` ENUM('held', 'released', 'refunded', 'disputed') DEFAULT 'held',
    `release_reason` VARCHAR(255),
    `released_at` TIMESTAMP NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    KEY `idx_escrow_users` (`buyer_id`, `seller_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;-- [REMOVED DUPLICATE bank_cards]
DROP TABLE IF EXISTS `bulk_operations`;
CREATE TABLE `bulk_operations` (
    `id` INT(10) UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `admin_id` INT(10) UNSIGNED NOT NULL,
    `type` VARCHAR(50) NOT NULL COMMENT 'payout, message, status_update',
    `total_items` INT UNSIGNED,
    `processed_items` INT UNSIGNED DEFAULT 0,
    `status` ENUM('pending', 'processing', 'completed', 'failed') DEFAULT 'pending',
    `log_file` VARCHAR(255),
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;
