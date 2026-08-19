-- Align existing tickets table with TicketStatus enum and Ticket model workflow.
-- Admin replies set status='answered' and closing sets closed_at.

ALTER TABLE `tickets`
  MODIFY COLUMN `status` ENUM('open','pending','replied','answered','in_progress','on_hold','closed') NOT NULL DEFAULT 'open',
  ADD COLUMN IF NOT EXISTS `closed_at` TIMESTAMP NULL DEFAULT NULL AFTER `last_reply_at`;

CREATE INDEX IF NOT EXISTS `idx_tickets_status_updated` ON `tickets` (`status`, `updated_at`);
