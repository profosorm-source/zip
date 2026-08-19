-- Auditable and concurrency-safe investment profit/loss settlement contract.
ALTER TABLE `investment_profits`
  ADD COLUMN IF NOT EXISTS `gross_amount` DECIMAL(24,8) NOT NULL DEFAULT 0 AFTER `amount`,
  ADD COLUMN IF NOT EXISTS `site_fee_amount` DECIMAL(24,8) NOT NULL DEFAULT 0 AFTER `gross_amount`,
  ADD COLUMN IF NOT EXISTS `tax_amount` DECIMAL(24,8) NOT NULL DEFAULT 0 AFTER `site_fee_amount`,
  ADD COLUMN IF NOT EXISTS `balance_before` DECIMAL(24,8) NULL AFTER `net_amount`,
  ADD COLUMN IF NOT EXISTS `balance_after` DECIMAL(24,8) NULL AFTER `balance_before`,
  ADD COLUMN IF NOT EXISTS `period` VARCHAR(32) NULL AFTER `period_date`;

-- The service-level pre-check is not sufficient under concurrent workers. The
-- database must own the exactly-once invariant for one trade/investment pair.
CREATE UNIQUE INDEX IF NOT EXISTS `uq_investment_profit_trade_investment`
  ON `investment_profits` (`trading_record_id`, `investment_id`);
