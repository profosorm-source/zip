-- Queue/DLQ runtime contract required by Core\Queue::fail(), retry metrics and DlqWorker.
ALTER TABLE `failed_jobs`
    ADD COLUMN IF NOT EXISTS `error_classification` VARCHAR(32) NOT NULL DEFAULT 'unknown' AFTER `exception`,
    ADD COLUMN IF NOT EXISTS `status` VARCHAR(32) NOT NULL DEFAULT 'pending_analysis' AFTER `error_classification`,
    ADD COLUMN IF NOT EXISTS `retry_count` INT UNSIGNED NOT NULL DEFAULT 0 AFTER `status`,
    ADD COLUMN IF NOT EXISTS `next_retry_at` DATETIME NULL AFTER `retry_count`;

CREATE INDEX IF NOT EXISTS `idx_failed_jobs_retry_pickup`
    ON `failed_jobs` (`status`, `next_retry_at`, `failed_at`);
