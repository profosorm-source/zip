-- Compatibility columns used by UserSettingsService account deletion request/cancel flow.
ALTER TABLE `users`
  ADD COLUMN IF NOT EXISTS `account_deletion_requested_at` DATETIME NULL DEFAULT NULL AFTER `deleted_at`,
  ADD COLUMN IF NOT EXISTS `account_deletion_expires_at` DATETIME NULL DEFAULT NULL AFTER `account_deletion_requested_at`;

CREATE INDEX IF NOT EXISTS `idx_users_account_deletion_expires` ON `users` (`account_deletion_expires_at`);
