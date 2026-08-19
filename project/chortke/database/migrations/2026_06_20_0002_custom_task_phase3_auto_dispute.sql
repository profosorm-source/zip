-- CustomTask phase 3: auto-approve, expiration, and dispute support

ALTER TABLE `custom_task_submissions`
  ADD COLUMN IF NOT EXISTS `auto_approved_at` TIMESTAMP NULL DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS `dispute_id` INT(10) UNSIGNED NULL DEFAULT NULL;

CREATE INDEX IF NOT EXISTS `idx_disputes_ref_status` ON `disputes` (`ref_type`, `ref_id`, `status`);
CREATE INDEX IF NOT EXISTS `idx_disputes_user_status` ON `disputes` (`user_id`, `target_user_id`, `status`);
CREATE INDEX IF NOT EXISTS `idx_cts_auto_approve` ON `custom_task_submissions` (`status`, `submitted_at`);
CREATE INDEX IF NOT EXISTS `idx_cts_expire` ON `custom_task_submissions` (`status`, `deadline_at`);
