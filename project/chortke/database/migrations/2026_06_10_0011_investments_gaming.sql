-- CHORTKE MIGRATION PART 7: INVESTMENTS & GAMING
SET FOREIGN_KEY_CHECKS = 0;

DROP TABLE IF EXISTS `investments`;
CREATE TABLE `investments` (
    `id` INT(10) UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `user_id` INT(10) UNSIGNED NOT NULL,
    `amount` DECIMAL(24,4) NOT NULL,
    `plan_id` INT(10) UNSIGNED,
    `status` VARCHAR(20) DEFAULT 'active',
    `profit_earned` DECIMAL(24,4) DEFAULT 0,
    `expires_at` TIMESTAMP NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    KEY `idx_inv_user` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;-- [REMOVED DUPLICATE lottery_rounds]
-- [REMOVED DUPLICATE prediction_games]
-- [REMOVED DUPLICATE prediction_bets]


SET FOREIGN_KEY_CHECKS = 1;
