-- Package 143: Support ticket lifecycle compatibility.
-- Admin TicketController, TicketService and search filters use tickets.assigned_to.
-- Keep this schema column explicit so assignment/reply authorization cannot fail with Unknown column.

ALTER TABLE `tickets`
  ADD COLUMN IF NOT EXISTS `assigned_to` INT(10) UNSIGNED NULL DEFAULT NULL AFTER `metadata`;

CREATE INDEX IF NOT EXISTS `idx_tickets_assigned_to` ON `tickets` (`assigned_to`);
