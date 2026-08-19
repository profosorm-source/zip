SET FOREIGN_KEY_CHECKS = 0;

-- =============================================================================
-- BUGFIX-USER-LEVEL-COLUMNS-2026-06
-- =============================================================================
--
-- The user-level subsystem (UserLevelQueryService, UserLevelCommandService,
-- ChangeUserLevelJob, UserDashboardService) reads & writes two columns on
-- the `users` table that no migration in this repository ever created:
--
--   * level_expires_at  TIMESTAMP NULL  — when a purchased level expires
--   * monthly_active_days INT(10) UNSIGNED — counter incremented daily
--
-- Without these columns the SELECT statement in
-- UserDashboardService::getStats() failed with
--   SQLSTATE[42S22]: Column not found: 1054 Unknown column 'level_expires_at'
-- and the surrounding try/catch swallowed the error, causing the dashboard
-- to silently render with `$data['level']` left at its hardcoded default
-- and the income / platform charts coming back empty (because the
-- exception aborted the whole getStats() function before it could compute
-- the chart series).
--
-- This migration adds both columns. There is also a legacy column called
-- `active_days_count` in the schema; we leave it alone (some admin views
-- read it) and instead add the canonical name expected by the level
-- services. The backfill clones any existing daily-counter value across
-- so historical data is preserved.
-- =============================================================================

ALTER TABLE `users`
  ADD COLUMN IF NOT EXISTS `level_expires_at`    TIMESTAMP NULL DEFAULT NULL
                                                 COMMENT 'When a purchased level expires (NULL = no expiry).',
  ADD COLUMN IF NOT EXISTS `monthly_active_days` INT(10) UNSIGNED NOT NULL DEFAULT 0
                                                 COMMENT 'Cumulative active-day counter, reset monthly by ChangeUserLevelJob.';

-- Backfill monthly_active_days from the legacy `active_days_count` if
-- present (information_schema check keeps this safe when the legacy
-- column was already removed by a future migration).
UPDATE `users` u
   SET u.monthly_active_days = u.active_days_count
 WHERE u.monthly_active_days = 0
   AND u.active_days_count > 0
   AND EXISTS (SELECT 1 FROM information_schema.COLUMNS
                WHERE TABLE_SCHEMA = DATABASE()
                  AND TABLE_NAME   = 'users'
                  AND COLUMN_NAME  = 'active_days_count');

CREATE INDEX IF NOT EXISTS `idx_users_level_expires_at` ON `users` (`level_expires_at`);

SET FOREIGN_KEY_CHECKS = 1;
