-- SEO Task reconciliation: creation schema, execution lifecycle, anti-fraud compatibility

ALTER TABLE `seo_executions`
  ADD COLUMN IF NOT EXISTS `session_id` VARCHAR(64) NULL DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS `target_keyword` VARCHAR(255) NULL DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS `rejection_reason` TEXT NULL DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS `cancel_reason` TEXT NULL DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS `fraud_score` INT(10) NULL DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS `client_mode` VARCHAR(50) NULL DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS `score_breakdown` LONGTEXT NULL DEFAULT NULL;

ALTER TABLE `user_sessions`
  ADD COLUMN IF NOT EXISTS `device_fingerprint` VARCHAR(191) NULL DEFAULT NULL;

CREATE INDEX IF NOT EXISTS `idx_seo_exec_ad_user_date` ON `seo_executions` (`ad_id`, `user_id`, `execution_date`);
CREATE INDEX IF NOT EXISTS `idx_seo_exec_status_date` ON `seo_executions` (`status`, `created_at`);
CREATE INDEX IF NOT EXISTS `idx_user_sessions_device_fp` ON `user_sessions` (`user_id`, `device_fingerprint`);
