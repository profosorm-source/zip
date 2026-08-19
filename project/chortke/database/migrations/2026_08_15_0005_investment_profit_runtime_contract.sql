-- Investment profit runtime contract used by InvestmentProfit and settlement jobs.
-- The earlier compatibility migration used CREATE TABLE IF NOT EXISTS after a
-- smaller table had already been created, so its missing columns were never added.
ALTER TABLE `investment_profits`
  ADD COLUMN IF NOT EXISTS `user_id` INT(10) UNSIGNED NULL AFTER `investment_id`,
  ADD COLUMN IF NOT EXISTS `currency` VARCHAR(10) NOT NULL DEFAULT 'usdt' AFTER `amount`,
  ADD COLUMN IF NOT EXISTS `profit_type` VARCHAR(20) NULL AFTER `currency`,
  ADD COLUMN IF NOT EXISTS `status` VARCHAR(20) NOT NULL DEFAULT 'paid' AFTER `profit_type`,
  ADD COLUMN IF NOT EXISTS `transaction_id` VARCHAR(64) NULL AFTER `status`,
  ADD COLUMN IF NOT EXISTS `trading_record_id` INT(10) UNSIGNED NULL AFTER `transaction_id`,
  ADD COLUMN IF NOT EXISTS `period_date` DATE NULL AFTER `trading_record_id`,
  ADD COLUMN IF NOT EXISTS `net_amount` DECIMAL(24,8) NULL AFTER `period_date`,
  ADD COLUMN IF NOT EXISTS `is_deleted` TINYINT(1) NOT NULL DEFAULT 0 AFTER `net_amount`,
  ADD COLUMN IF NOT EXISTS `updated_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP AFTER `created_at`;

CREATE INDEX IF NOT EXISTS `idx_investment_profits_investment` ON `investment_profits` (`investment_id`);
CREATE INDEX IF NOT EXISTS `idx_investment_profits_trade_investment` ON `investment_profits` (`trading_record_id`, `investment_id`);
