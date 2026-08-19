-- CHORTKE MIGRATION PART 13: EXTENDED REWARDS
SET FOREIGN_KEY_CHECKS = 0;

-- [REMOVED DUPLICATE lottery_participations]
-- [REMOVED DUPLICATE lottery_votes]
-- [REMOVED DUPLICATE investment_profits]
DROP TABLE IF EXISTS `user_level_history`;
CREATE TABLE `user_level_history` (
    `id` INT(10) UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `user_id` INT(10) UNSIGNED NOT NULL,
    `from_level` VARCHAR(50),
    `to_level` VARCHAR(50) NOT NULL,
    `change_type` VARCHAR(50),
    `reason` TEXT,
    `signature` VARCHAR(255),
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    KEY `idx_level_user` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;-- [REMOVED DUPLICATE ratings]


SET FOREIGN_KEY_CHECKS = 1;
