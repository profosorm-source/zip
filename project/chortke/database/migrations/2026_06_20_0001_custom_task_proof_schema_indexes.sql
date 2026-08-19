-- CustomTask proof schema strict validation support

ALTER TABLE `custom_task_submissions`
  ADD COLUMN IF NOT EXISTS `proof_code` VARCHAR(255) NULL DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS `proof_data` LONGTEXT NULL DEFAULT NULL;

CREATE INDEX IF NOT EXISTS `idx_cts_task_proof_code` ON `custom_task_submissions` (`task_id`, `proof_code`);
CREATE INDEX IF NOT EXISTS `idx_cts_task_proof_url` ON `custom_task_submissions` (`task_id`, `proof_url`(191));
