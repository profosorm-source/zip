-- CustomTask workflow reconciliation
-- CustomTask remains contract/proof-schema driven (unlike SocialTask).

ALTER TABLE `custom_task_submissions`
  MODIFY COLUMN `status` VARCHAR(50) DEFAULT 'pending',
  ADD COLUMN IF NOT EXISTS `proof_code` VARCHAR(255) NULL DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS `proof_data` LONGTEXT NULL DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS `reviewed_by` INT(10) UNSIGNED NULL DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS `auto_approved_at` TIMESTAMP NULL DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS `dispute_id` INT(10) UNSIGNED NULL DEFAULT NULL;

ALTER TABLE `ads`
  ADD COLUMN IF NOT EXISTS `proof_schema` LONGTEXT NULL DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS `auto_approve_hours` INT(10) UNSIGNED NULL DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS `reject_rules` LONGTEXT NULL DEFAULT NULL;

CREATE INDEX IF NOT EXISTS `idx_cts_status_deadline` ON `custom_task_submissions` (`status`, `deadline_at`);
CREATE INDEX IF NOT EXISTS `idx_cts_worker_status` ON `custom_task_submissions` (`worker_id`, `status`);
CREATE INDEX IF NOT EXISTS `idx_cts_task_worker_status` ON `custom_task_submissions` (`task_id`, `worker_id`, `status`);
