SET FOREIGN_KEY_CHECKS = 0;

-- Wave 3 state columns compatibility
ALTER TABLE `lottery_rounds` MODIFY COLUMN `status` VARCHAR(50) DEFAULT 'active' NULL;
ALTER TABLE `prediction_games` MODIFY COLUMN `status` VARCHAR(50) DEFAULT 'open' NULL;
ALTER TABLE `prediction_bets` MODIFY COLUMN `status` VARCHAR(50) DEFAULT 'pending' NULL;
ALTER TABLE `story_orders` MODIFY COLUMN `status` VARCHAR(50) DEFAULT 'pending' NULL;
ALTER TABLE `referral_commissions` MODIFY COLUMN `status` VARCHAR(50) DEFAULT 'pending' NULL;

SET FOREIGN_KEY_CHECKS = 1;
