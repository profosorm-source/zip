SET FOREIGN_KEY_CHECKS = 0;

-- =============================================================================
-- CURRENCY-TAG-2026-08 - Add explicit per-row currency tag to money tables
-- that previously stored an amount without a currency column.
--
-- Rationale (audit): the platform has NO exchange rate and NO IRT<->USDT
-- conversion. currency_mode in appSettings is only a display MODE switch, not a
-- rate. Storing an amount without a currency tag makes it ambiguous if the
-- admin ever flips the mode. Base unit is Toman (IRT); the investment section
-- is denominated in USDT (see CurrencyService::getSectionCurrency).
--
-- This migration is additive and non-destructive: existing rows receive the
-- section-correct default currency (consistent with currency_normalize_to_irt).
-- =============================================================================

-- Investment section is denominated in USDT.
ALTER TABLE `investments`
  ADD COLUMN IF NOT EXISTS `currency` ENUM('irt','usdt') NOT NULL DEFAULT 'usdt' AFTER `amount`;
ALTER TABLE `investment_plans`
  ADD COLUMN IF NOT EXISTS `currency` ENUM('irt','usdt') NOT NULL DEFAULT 'usdt' AFTER `max_amount`;
ALTER TABLE `investment_withdrawals`
  ADD COLUMN IF NOT EXISTS `currency` ENUM('irt','usdt') NOT NULL DEFAULT 'usdt' AFTER `amount`;

-- Toman-denominated (platform base unit).
ALTER TABLE `influencer_orders`
  ADD COLUMN IF NOT EXISTS `currency` ENUM('irt','usdt') NOT NULL DEFAULT 'irt' AFTER `influencer_earnings`;
ALTER TABLE `custom_task_transactions`
  ADD COLUMN IF NOT EXISTS `currency` ENUM('irt','usdt') NOT NULL DEFAULT 'irt' AFTER `amount`;
ALTER TABLE `coupons`
  ADD COLUMN IF NOT EXISTS `currency` ENUM('irt','usdt') NOT NULL DEFAULT 'irt' AFTER `min_amount`;
ALTER TABLE `startup_banners`
  ADD COLUMN IF NOT EXISTS `currency` ENUM('irt','usdt') NOT NULL DEFAULT 'irt' AFTER `price`;
ALTER TABLE `social_ads`
  ADD COLUMN IF NOT EXISTS `currency` ENUM('irt','usdt') NOT NULL DEFAULT 'irt' AFTER `reward`;
ALTER TABLE `vitrine_requests`
  ADD COLUMN IF NOT EXISTS `currency` ENUM('irt','usdt') NOT NULL DEFAULT 'irt' AFTER `offer_price`;
ALTER TABLE `escrow_audit`
  ADD COLUMN IF NOT EXISTS `currency` ENUM('irt','usdt') NOT NULL DEFAULT 'irt' AFTER `amount`;
ALTER TABLE `payment_logs`
  ADD COLUMN IF NOT EXISTS `currency` ENUM('irt','usdt') NOT NULL DEFAULT 'irt' AFTER `amount`;

SET FOREIGN_KEY_CHECKS = 1;
