-- Compatibility fixes discovered by real E2E observability/admin Sentry scenarios.
-- Keeps the runtime schema aligned with SentryModel/Admin Log queries.

ALTER TABLE `error_logs`
  ADD COLUMN IF NOT EXISTS `user_id` INT(10) UNSIGNED NULL DEFAULT NULL AFTER `id`,
  ADD COLUMN IF NOT EXISTS `ip_address` VARCHAR(45) NULL DEFAULT NULL AFTER `line`,
  ADD COLUMN IF NOT EXISTS `url` VARCHAR(2048) NULL DEFAULT NULL AFTER `ip_address`,
  ADD COLUMN IF NOT EXISTS `method` VARCHAR(10) NULL DEFAULT NULL AFTER `url`,
  ADD COLUMN IF NOT EXISTS `user_agent` TEXT NULL DEFAULT NULL AFTER `method`,
  ADD COLUMN IF NOT EXISTS `resolved_by` INT(10) UNSIGNED NULL DEFAULT NULL AFTER `status`,
  ADD COLUMN IF NOT EXISTS `resolved_at` DATETIME NULL DEFAULT NULL AFTER `resolved_by`,
  ADD COLUMN IF NOT EXISTS `resolution_note` TEXT NULL DEFAULT NULL AFTER `resolved_at`;

CREATE INDEX IF NOT EXISTS `idx_error_logs_user_created` ON `error_logs` (`user_id`, `created_at`);
CREATE INDEX IF NOT EXISTS `idx_error_logs_status_updated` ON `error_logs` (`status`, `updated_at`);

ALTER TABLE `sentry_issues`
  ADD COLUMN IF NOT EXISTS `acknowledged_at` DATETIME NULL DEFAULT NULL AFTER `last_seen`,
  ADD COLUMN IF NOT EXISTS `acknowledged_by` INT(10) UNSIGNED NULL DEFAULT NULL AFTER `acknowledged_at`,
  ADD COLUMN IF NOT EXISTS `updated_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP AFTER `created_at`;

CREATE INDEX IF NOT EXISTS `idx_sentry_issues_acknowledged_at` ON `sentry_issues` (`acknowledged_at`);
CREATE INDEX IF NOT EXISTS `idx_sentry_issues_acknowledged_by` ON `sentry_issues` (`acknowledged_by`);

CREATE FULLTEXT INDEX IF NOT EXISTS `idx_audit_trail_event_fulltext` ON `audit_trail` (`event`);
