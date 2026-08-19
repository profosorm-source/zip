-- Link a user-submitted hash to the exact server-generated crypto intent.
-- Nullable for legacy deposits; new flow requires it at application level.
ALTER TABLE `crypto_deposits`
  ADD COLUMN IF NOT EXISTS `intent_id` INT(10) UNSIGNED NULL AFTER `user_id`,
  ADD INDEX IF NOT EXISTS `idx_crypto_deposit_intent` (`intent_id`);
