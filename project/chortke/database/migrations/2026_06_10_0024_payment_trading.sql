-- CHORTKE MIGRATION PART 17: PAYMENT & TRADING
SET FOREIGN_KEY_CHECKS = 0;

-- [REMOVED DUPLICATE payment_gateways]
-- [REMOVED DUPLICATE payment_logs]
-- [REMOVED DUPLICATE scheduled_payments]
DROP TABLE IF EXISTS `trading_records`;
CREATE TABLE `trading_records` (
    `id` INT(10) UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `user_id` INT(10) UNSIGNED NOT NULL,
    `symbol` VARCHAR(20),
    `type` ENUM('buy', 'sell'),
    `amount` DECIMAL(24,8),
    `price` DECIMAL(24,8),
    `profit_loss` DECIMAL(24,8),
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    KEY `idx_trade_user` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;-- [REMOVED DUPLICATE investment_withdrawals]


SET FOREIGN_KEY_CHECKS = 1;
