-- Compatibility columns required by DirectMessage model/service flows.
-- Fresh installs previously created direct_messages without is_encrypted and
-- soft-delete metadata, while runtime code selects/inserts/updates them.
ALTER TABLE `direct_messages`
  ADD COLUMN IF NOT EXISTS `is_encrypted` TINYINT(1) NOT NULL DEFAULT 0 AFTER `message`,
  ADD COLUMN IF NOT EXISTS `deleted_by` INT(10) UNSIGNED NULL DEFAULT NULL AFTER `read_at`,
  ADD COLUMN IF NOT EXISTS `deleted_at` DATETIME NULL DEFAULT NULL AFTER `deleted_by`;

CREATE INDEX IF NOT EXISTS `idx_direct_messages_recipient_read` ON `direct_messages` (`recipient_id`, `read_at`);
CREATE INDEX IF NOT EXISTS `idx_direct_messages_deleted_by` ON `direct_messages` (`deleted_by`);
