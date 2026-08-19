-- SocialTask mobile-first scoring / verification reconciliation
-- SocialTask is not proof/screenshot driven. It is scored by behavior/time/interaction
-- and optionally assisted by mobile camera frame analysis when suspicious.

ALTER TABLE `social_task_executions`
  ADD COLUMN IF NOT EXISTS `submitted_at` TIMESTAMP NULL DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS `completed_at` TIMESTAMP NULL DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS `client_mode` VARCHAR(50) NULL DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS `client_version` VARCHAR(50) NULL DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS `device_context` LONGTEXT NULL DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS `behavior_score` DECIMAL(5,2) NULL DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS `time_score` DECIMAL(5,2) NULL DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS `interaction_score` DECIMAL(5,2) NULL DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS `camera_score` DECIMAL(5,2) NULL DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS `final_score` DECIMAL(5,2) NULL DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS `score_breakdown` LONGTEXT NULL DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS `verification_required` TINYINT(1) NOT NULL DEFAULT 0,
  ADD COLUMN IF NOT EXISTS `verification_method` VARCHAR(50) NULL DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS `verification_requested_at` TIMESTAMP NULL DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS `verification_completed_at` TIMESTAMP NULL DEFAULT NULL;

ALTER TABLE `social_camera_requests`
  ADD COLUMN IF NOT EXISTS `method` VARCHAR(50) NOT NULL DEFAULT 'mobile_camera',
  ADD COLUMN IF NOT EXISTS `frame_count` INT(10) UNSIGNED NOT NULL DEFAULT 0,
  ADD COLUMN IF NOT EXISTS `frame_signals` LONGTEXT NULL DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS `raw_image_stored` TINYINT(1) NOT NULL DEFAULT 0,
  ADD COLUMN IF NOT EXISTS `client_context` LONGTEXT NULL DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS `verification_score` DECIMAL(5,2) NULL DEFAULT NULL;

CREATE INDEX IF NOT EXISTS `idx_ste_mobile_scoring` ON `social_task_executions` (`status`, `final_score`, `verification_required`);
CREATE INDEX IF NOT EXISTS `idx_scr_method_status` ON `social_camera_requests` (`method`, `status`, `expires_at`);
