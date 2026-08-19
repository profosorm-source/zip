SET FOREIGN_KEY_CHECKS = 0;

-- Lottery schema reconciliation for LT-01..LT-08
-- Aligns DB tables with LotteryCommandService / LotteryParticipationService expectations.

-- lottery_rounds: normalize legacy gaming schema into the service contract
ALTER TABLE `lottery_rounds` MODIFY COLUMN `status` VARCHAR(50) DEFAULT 'active' NULL;
ALTER TABLE `lottery_rounds`
  ADD COLUMN IF NOT EXISTS `is_deleted` TINYINT(1) NOT NULL DEFAULT 0,
  ADD COLUMN IF NOT EXISTS `start_date` TIMESTAMP NULL,
  ADD COLUMN IF NOT EXISTS `end_date` TIMESTAMP NULL,
  ADD COLUMN IF NOT EXISTS `prize_amount` DECIMAL(24,4) DEFAULT 0 NULL,
  ADD COLUMN IF NOT EXISTS `ticket_price` DECIMAL(24,4) DEFAULT 5000.0000,
  ADD COLUMN IF NOT EXISTS `max_capacity` INT(10) UNSIGNED DEFAULT 1000,
  ADD COLUMN IF NOT EXISTS `currency` VARCHAR(10) NOT NULL DEFAULT 'irt',
  ADD COLUMN IF NOT EXISTS `entry_fee` DECIMAL(24,4) NULL;

UPDATE `lottery_rounds`
SET
  `prize_amount` = CASE WHEN COALESCE(`prize_amount`, 0) = 0 THEN COALESCE(`prize_pool`, 0) ELSE `prize_amount` END,
  `entry_fee` = COALESCE(`entry_fee`, `ticket_price`),
  `status` = CASE
    WHEN `status` = 'open' THEN 'active'
    WHEN `status` = 'finished' THEN 'completed'
    WHEN `status` = 'closed' THEN 'cancelled'
    ELSE `status`
  END;

-- lottery_participations: fields used by commands, queries and vote state machine
ALTER TABLE `lottery_participations`
  ADD COLUMN IF NOT EXISTS `is_deleted` TINYINT(1) DEFAULT 0 NOT NULL,
  ADD COLUMN IF NOT EXISTS `status` VARCHAR(50) DEFAULT 'active',
  ADD COLUMN IF NOT EXISTS `chance_score` DECIMAL(24,4) DEFAULT 100.0000,
  ADD COLUMN IF NOT EXISTS `updated_at` TIMESTAMP NULL ON UPDATE CURRENT_TIMESTAMP;
CREATE UNIQUE INDEX IF NOT EXISTS `uniq_lottery_participation_user_round_active`
  ON `lottery_participations` (`user_id`, `round_id`, `is_deleted`);

-- lottery_daily_numbers: per-round daily numbers + state machine compatible fields
ALTER TABLE `lottery_daily_numbers`
  ADD COLUMN IF NOT EXISTS `round_id` INT(10) UNSIGNED NOT NULL DEFAULT 0,
  ADD COLUMN IF NOT EXISTS `number1` INT(11) NULL,
  ADD COLUMN IF NOT EXISTS `number2` INT(11) NULL,
  ADD COLUMN IF NOT EXISTS `number3` INT(11) NULL,
  ADD COLUMN IF NOT EXISTS `selected_number` INT(11) NULL,
  ADD COLUMN IF NOT EXISTS `status` VARCHAR(50) NOT NULL DEFAULT 'pending',
  ADD COLUMN IF NOT EXISTS `seed_hash` VARCHAR(128) NULL,
  ADD COLUMN IF NOT EXISTS `is_deleted` TINYINT(1) NOT NULL DEFAULT 0,
  ADD COLUMN IF NOT EXISTS `updated_at` TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP;

UPDATE `lottery_daily_numbers`
SET
  `number1` = COALESCE(`number1`, `winning_number`),
  `number2` = COALESCE(`number2`, `winning_number`),
  `number3` = COALESCE(`number3`, `winning_number`),
  `selected_number` = COALESCE(`selected_number`, `winning_number`)
WHERE `winning_number` IS NOT NULL;

DROP INDEX IF EXISTS `date` ON `lottery_daily_numbers`;
CREATE UNIQUE INDEX IF NOT EXISTS `uniq_lottery_daily_round_date_active`
  ON `lottery_daily_numbers` (`round_id`, `date`, `is_deleted`);
CREATE INDEX IF NOT EXISTS `idx_lottery_daily_round` ON `lottery_daily_numbers` (`round_id`);

-- lottery_votes: daily-number based voting with one active vote per user/day
ALTER TABLE `lottery_votes`
  ADD COLUMN IF NOT EXISTS `daily_number_id` INT(10) UNSIGNED NULL,
  ADD COLUMN IF NOT EXISTS `participation_id` INT(10) UNSIGNED NULL,
  ADD COLUMN IF NOT EXISTS `status` VARCHAR(50) NOT NULL DEFAULT 'cast',
  ADD COLUMN IF NOT EXISTS `is_deleted` TINYINT(1) NOT NULL DEFAULT 0,
  ADD COLUMN IF NOT EXISTS `updated_at` TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP;

CREATE INDEX IF NOT EXISTS `idx_lottery_votes_daily` ON `lottery_votes` (`daily_number_id`);
CREATE INDEX IF NOT EXISTS `idx_lottery_votes_user_daily` ON `lottery_votes` (`user_id`, `daily_number_id`);
CREATE UNIQUE INDEX IF NOT EXISTS `uniq_lottery_vote_user_daily_active`
  ON `lottery_votes` (`user_id`, `daily_number_id`, `is_deleted`);

SET FOREIGN_KEY_CHECKS = 1;
