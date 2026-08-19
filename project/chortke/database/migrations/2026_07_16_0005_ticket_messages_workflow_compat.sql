-- Compatibility columns used by TicketMessage model workflow
ALTER TABLE `ticket_messages`
  ADD COLUMN IF NOT EXISTS `ip_address` VARCHAR(45) NULL AFTER `is_admin`,
  ADD COLUMN IF NOT EXISTS `is_read` TINYINT(1) DEFAULT 0 AFTER `ip_address`,
  ADD COLUMN IF NOT EXISTS `read_at` DATETIME NULL AFTER `is_read`,
  ADD COLUMN IF NOT EXISTS `updated_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP AFTER `created_at`;

CREATE INDEX IF NOT EXISTS `idx_ticket_messages_read` ON `ticket_messages` (`ticket_id`, `is_admin`, `is_read`);
