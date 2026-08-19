-- Compatibility columns used by CaptchaLog::recordAttempt / CaptchaService::logAttempt
ALTER TABLE `captcha_attempts`
  ADD COLUMN IF NOT EXISTS `user_id` INT UNSIGNED NULL AFTER `id`,
  ADD COLUMN IF NOT EXISTS `session_id` VARCHAR(128) NULL AFTER `user_id`,
  ADD COLUMN IF NOT EXISTS `type` VARCHAR(50) NULL AFTER `session_id`,
  ADD COLUMN IF NOT EXISTS `challenge` VARCHAR(255) NULL AFTER `type`,
  ADD COLUMN IF NOT EXISTS `response` VARCHAR(255) NULL AFTER `challenge`,
  ADD COLUMN IF NOT EXISTS `user_agent` VARCHAR(500) NULL AFTER `ip_address`,
  ADD COLUMN IF NOT EXISTS `score` DECIMAL(8,4) NULL AFTER `is_success`,
  ADD COLUMN IF NOT EXISTS `solved_at` DATETIME NULL AFTER `score`;

CREATE INDEX IF NOT EXISTS `idx_captcha_user_created` ON `captcha_attempts` (`user_id`, `created_at`);
CREATE INDEX IF NOT EXISTS `idx_captcha_session_created` ON `captcha_attempts` (`session_id`, `created_at`);
