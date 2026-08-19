-- Prediction Phase 1: finance correctness and transparent settlement rules
-- PRIMARY / FINANCIAL_COMPATIBILITY

ALTER TABLE `prediction_bets`
  ADD COLUMN IF NOT EXISTS `payment_transaction_id` VARCHAR(128) NULL AFTER `currency`,
  ADD COLUMN IF NOT EXISTS `payout_transaction_id` VARCHAR(128) NULL AFTER `payout_usdt`,
  ADD COLUMN IF NOT EXISTS `refund_transaction_id` VARCHAR(128) NULL AFTER `payout_transaction_id`;

ALTER TABLE `prediction_games`
  ADD COLUMN IF NOT EXISTS `description` TEXT NULL AFTER `team_away`,
  ADD COLUMN IF NOT EXISTS `created_by` INT(10) UNSIGNED NULL AFTER `description`,
  ADD COLUMN IF NOT EXISTS `bonus_pool_usdt` DECIMAL(24,8) NOT NULL DEFAULT 0.00000000 AFTER `total_pool`,
  ADD COLUMN IF NOT EXISTS `site_fee_usdt` DECIMAL(24,8) NOT NULL DEFAULT 0.00000000 AFTER `commission_percent`,
  ADD COLUMN IF NOT EXISTS `rollover_amount_usdt` DECIMAL(24,8) NOT NULL DEFAULT 0.00000000 AFTER `site_fee_usdt`,
  ADD COLUMN IF NOT EXISTS `settlement_policy` VARCHAR(80) NULL AFTER `rollover_amount_usdt`,
  ADD COLUMN IF NOT EXISTS `settlement_summary` LONGTEXT NULL AFTER `settlement_policy`;

INSERT INTO `system_settings` (`key`, `value`, `group`, `type`, `description`, `is_public`, `created_at`, `updated_at`)
VALUES
  ('prediction_rollover_reserve_usdt', '0', 'prediction', 'numeric', 'ذخیره انتقالی پیش‌بینی‌ها برای بازی‌های بعدی در حالت بدون برنده', 0, NOW(), NOW()),
  ('prediction_no_winner_rollover_percent', '50', 'prediction', 'numeric', 'درصد انتقال به چرخه بعدی وقتی هیچ برنده‌ای وجود ندارد', 0, NOW(), NOW()),
  ('prediction_no_winner_site_percent', '50', 'prediction', 'numeric', 'درصد سهم سایت وقتی هیچ برنده‌ای وجود ندارد', 0, NOW(), NOW())
ON DUPLICATE KEY UPDATE `updated_at` = VALUES(`updated_at`);

CREATE INDEX IF NOT EXISTS `idx_prediction_bets_payment_tx` ON `prediction_bets` (`payment_transaction_id`);
CREATE INDEX IF NOT EXISTS `idx_prediction_bets_game_status_prediction` ON `prediction_bets` (`game_id`, `status`, `prediction`);
