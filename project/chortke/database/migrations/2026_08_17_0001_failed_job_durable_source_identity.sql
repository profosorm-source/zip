-- Durable identity for idempotent Queue -> DLQ transfer across process crashes.
ALTER TABLE `failed_jobs`
    ADD COLUMN IF NOT EXISTS `source_driver` VARCHAR(16) NULL AFTER `id`,
    ADD COLUMN IF NOT EXISTS `source_job_key` CHAR(64) NULL AFTER `source_driver`;

CREATE UNIQUE INDEX IF NOT EXISTS `uq_failed_jobs_source_job_key`
    ON `failed_jobs` (`source_job_key`);
