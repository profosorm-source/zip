-- Compatibility for Ticket model / TicketCommandService workflow
ALTER TABLE `tickets`
  ADD COLUMN IF NOT EXISTS `category_id` INT UNSIGNED NULL AFTER `user_id`,
  ADD COLUMN IF NOT EXISTS `metadata` JSON NULL AFTER `status`;

ALTER TABLE `tickets`
  MODIFY COLUMN `priority` ENUM('low','normal','medium','high','urgent','critical') DEFAULT 'normal';

CREATE INDEX IF NOT EXISTS `idx_tickets_category` ON `tickets` (`category_id`);
