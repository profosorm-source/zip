-- Compatibility for admin analytics/system-health counters that aggregate HTTP error codes.

ALTER TABLE `error_logs`
  ADD COLUMN IF NOT EXISTS `status_code` SMALLINT UNSIGNED NULL DEFAULT NULL AFTER `status`;

CREATE INDEX IF NOT EXISTS `idx_error_logs_status_code` ON `error_logs` (`status_code`);
