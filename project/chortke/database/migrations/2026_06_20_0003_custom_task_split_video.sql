-- CustomTask phase 4: split dispute resolution and video proof metadata

ALTER TABLE `custom_task_submissions`
  ADD COLUMN IF NOT EXISTS `paid_amount` DECIMAL(24,4) NULL DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS `resolution_type` VARCHAR(50) NULL DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS `resolution_note` TEXT NULL DEFAULT NULL;

CREATE INDEX IF NOT EXISTS `idx_cts_resolution_type` ON `custom_task_submissions` (`resolution_type`);
