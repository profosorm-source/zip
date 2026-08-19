-- CHORTKE MIGRATION PART 25: DATA INTEGRITY & FINAL SNAPSHOTS
-- Senior QA Architect Standardized
SET FOREIGN_KEY_CHECKS = 0;

DROP TABLE IF EXISTS `financial_snapshots`;
CREATE TABLE `financial_snapshots` (
    `id` INT(10) UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `total_users_balance_irt` DECIMAL(30,4),
    `total_users_balance_usdt` DECIMAL(30,8),
    `system_revenue_total` DECIMAL(30,4),
    `ledger_balance_status` ENUM('balanced', 'unbalanced') DEFAULT 'balanced',
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `system_maintenance_log`;
CREATE TABLE `system_maintenance_log` (
    `id` INT(10) UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `action` VARCHAR(255) NOT NULL,
    `performed_by` INT(10) UNSIGNED,
    `details` JSON,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;
