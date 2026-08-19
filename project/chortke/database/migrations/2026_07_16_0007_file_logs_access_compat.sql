-- Compatibility for FileAccess::logDeniedFileAccess / FileController denied access audit
ALTER TABLE `file_logs`
  ADD COLUMN IF NOT EXISTS `folder` VARCHAR(100) NULL AFTER `id`,
  ADD COLUMN IF NOT EXISTS `filename` VARCHAR(255) NULL AFTER `folder`,
  ADD COLUMN IF NOT EXISTS `viewer_id` INT UNSIGNED NULL AFTER `filename`;

CREATE INDEX IF NOT EXISTS `idx_file_logs_folder_file` ON `file_logs` (`folder`, `filename`);
CREATE INDEX IF NOT EXISTS `idx_file_logs_viewer` ON `file_logs` (`viewer_id`);
