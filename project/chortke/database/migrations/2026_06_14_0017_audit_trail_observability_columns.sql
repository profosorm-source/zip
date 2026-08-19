SET FOREIGN_KEY_CHECKS = 0;

-- =============================================================================
-- BUGFIX-AUDIT-TRAIL-COLUMNS-2026-06
-- =============================================================================
--
-- PersistAuditRecordListener and Core\Database->buildSqlErrorContext insert
-- rows into `audit_trail` with the following columns:
--
--   request_id, event, user_id, actor_id, context,
--   ip_address, user_agent, created_at, prev_hash, hash
--
-- The table only had: id, user_id, actor_id, event, context, created_at, hash.
-- Every authenticated request was therefore triggering a
--   SQLSTATE[42S22]: Column not found: 1054 Unknown column 'ip_address'
-- and the audit record was silently dropped. Two production impacts:
--
--   1. Compliance: GDPR / KYC investigations rely on audit_trail.ip_address
--      and audit_trail.user_agent. They were never recorded.
--
--   2. Forensic integrity: prev_hash is the chain link that lets auditors
--      verify the log was not tampered with. Without it, hash-chain
--      verification cannot run.
--
-- This migration adds the missing columns and indexes them where useful.
-- =============================================================================

ALTER TABLE `audit_trail`
  ADD COLUMN IF NOT EXISTS `request_id` VARCHAR(64) NULL DEFAULT NULL
                                       COMMENT 'Request correlation id (Sentry/trace).',
  ADD COLUMN IF NOT EXISTS `ip_address` VARCHAR(45) NULL DEFAULT NULL
                                       COMMENT 'IPv4 / IPv6 of the actor at audit time.',
  ADD COLUMN IF NOT EXISTS `user_agent` TEXT NULL DEFAULT NULL
                                       COMMENT 'Verbatim User-Agent header.',
  ADD COLUMN IF NOT EXISTS `prev_hash`  VARCHAR(128) NULL DEFAULT NULL
                                       COMMENT 'Chain link to previous audit row (hash integrity).';

SET FOREIGN_KEY_CHECKS = 1;
