-- Package 143: notification delete action is a real soft delete, not only archive.
-- Existing installs should have these columns; keep the migration idempotent for older snapshots.

ALTER TABLE `notifications`
  ADD COLUMN IF NOT EXISTS `is_deleted` TINYINT(1) NOT NULL DEFAULT 0 AFTER `is_archived`,
  ADD COLUMN IF NOT EXISTS `deleted_at` DATETIME NULL DEFAULT NULL AFTER `archived_at`;

CREATE INDEX IF NOT EXISTS `idx_notifications_deleted` ON `notifications` (`user_id`, `is_deleted`, `deleted_at`);
