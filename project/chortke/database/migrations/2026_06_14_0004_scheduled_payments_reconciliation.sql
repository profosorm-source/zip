SET FOREIGN_KEY_CHECKS = 0;

-- Scheduled payments schema reconciliation for SP-01..SP-04

ALTER TABLE `scheduled_payments`
  MODIFY COLUMN `frequency` VARCHAR(50) DEFAULT 'one_time',
  ADD COLUMN IF NOT EXISTS `description` VARCHAR(255) NULL,
  ADD COLUMN IF NOT EXISTS `metadata` LONGTEXT NULL,
  ADD COLUMN IF NOT EXISTS `idempotency_key` VARCHAR(128) NULL,
  ADD COLUMN IF NOT EXISTS `last_run_at` TIMESTAMP NULL DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS `processed_count` INT(10) UNSIGNED NOT NULL DEFAULT 0;

CREATE INDEX IF NOT EXISTS `idx_scheduled_payments_due` ON `scheduled_payments` (`status`, `next_run_at`);
CREATE UNIQUE INDEX IF NOT EXISTS `uniq_scheduled_payment_idempotency` ON `scheduled_payments` (`idempotency_key`);

SET FOREIGN_KEY_CHECKS = 1;
