-- Compatibility columns required by SentryModel::storePerformanceTransaction
ALTER TABLE `performance_transactions`
  ADD COLUMN IF NOT EXISTS `request_id` VARCHAR(64) NULL AFTER `transaction_id`,
  ADD COLUMN IF NOT EXISTS `op` VARCHAR(100) NULL AFTER `name`,
  ADD COLUMN IF NOT EXISTS `memory_used` BIGINT NULL AFTER `duration`,
  ADD COLUMN IF NOT EXISTS `peak_memory` BIGINT NULL AFTER `memory_used`,
  ADD COLUMN IF NOT EXISTS `slow_queries_count` INT NULL DEFAULT 0 AFTER `query_count`,
  ADD COLUMN IF NOT EXISTS `spans` JSON NULL AFTER `status`,
  ADD COLUMN IF NOT EXISTS `queries` JSON NULL AFTER `spans`,
  ADD COLUMN IF NOT EXISTS `issues` JSON NULL AFTER `queries`,
  ADD COLUMN IF NOT EXISTS `context` JSON NULL AFTER `issues`;

CREATE INDEX IF NOT EXISTS `idx_perf_request_id` ON `performance_transactions` (`request_id`);
