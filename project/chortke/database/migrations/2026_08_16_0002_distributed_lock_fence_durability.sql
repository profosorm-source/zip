-- Durable fencing tokens survive Redis restart and prevent stale lock holders
-- from overwriting a newer holder after volatile lock state is lost.
CREATE TABLE IF NOT EXISTS `distributed_lock_fences` (
  `resource_hash` CHAR(64) NOT NULL,
  `resource_name` VARCHAR(255) NOT NULL,
  `next_fence` BIGINT UNSIGNED NOT NULL DEFAULT 0,
  `applied_fence` BIGINT UNSIGNED NOT NULL DEFAULT 0,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`resource_hash`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
