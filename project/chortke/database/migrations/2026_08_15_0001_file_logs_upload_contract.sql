-- Complete the runtime contract used by UploadService::logUpload().
-- Existing access-audit columns are retained; these columns describe the uploaded object.
ALTER TABLE `file_logs`
  ADD COLUMN IF NOT EXISTS `mime_type` VARCHAR(191) NULL AFTER `action`,
  ADD COLUMN IF NOT EXISTS `size_bytes` BIGINT UNSIGNED NULL AFTER `mime_type`;

CREATE INDEX IF NOT EXISTS `idx_file_logs_upload_lookup`
  ON `file_logs` (`folder`, `filename`, `created_at`);
