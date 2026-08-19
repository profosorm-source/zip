SET FOREIGN_KEY_CHECKS = 0;

-- =============================================================================
-- BUGFIX-KYC-LEVEL-COLUMNS-2026-06
-- =============================================================================
--
-- app/Listeners/KYCListener.php (line 58) and the unlockFeatures()/
-- updateWithdrawalLimits() helpers below it write to two columns on the
-- `users` table that the schema never actually had:
--
--   UPDATE users SET kyc_level = ?, kyc_verified_at = NOW() WHERE id = ?
--
-- Without these columns the UPDATE failed with a `Column not found` error
-- which the listener swallowed (the surrounding try/catch logs but does not
-- propagate), so KYC approvals were never persisted to the user row even
-- though `kyc_verifications.status` was correctly set to 'verified'. Two
-- consequences observed in production:
--
--   1. Downstream code that reads `users.kyc_status` still saw the right
--      value because that column IS populated elsewhere, but anything
--      that read a per-level capability matrix (withdrawal limits, etc.)
--      silently fell back to the default tier.
--
--   2. The dashboard's KYC banner had no way to know how recent the
--      verification was (it only knew yes/no).
--
-- This migration adds the missing columns with the correct types and
-- backfills `kyc_verified_at` from `kyc_verifications.verified_at` so
-- previously approved users get a sensible historical timestamp.
-- =============================================================================

ALTER TABLE `users`
  ADD COLUMN IF NOT EXISTS `kyc_level`       TINYINT(3) UNSIGNED NOT NULL DEFAULT 0
                                             COMMENT 'KYC tier: 0=unverified, 1=basic, 2=full, 3=enhanced',
  ADD COLUMN IF NOT EXISTS `kyc_verified_at` TIMESTAMP NULL DEFAULT NULL
                                             COMMENT 'Set by KYCListener on kyc.approved events.';

-- Backfill: any user already marked verified gets level=1 and an
-- approximate verified_at from kyc_verifications (best-effort; falls back
-- to the user's updated_at for rows without a matching kyc record).
UPDATE `users` u
  LEFT JOIN `kyc_verifications` kv ON kv.user_id = u.id AND kv.status = 'verified'
   SET u.kyc_level       = CASE WHEN u.kyc_status = 'verified' AND u.kyc_level = 0 THEN 1 ELSE u.kyc_level END,
       u.kyc_verified_at = COALESCE(u.kyc_verified_at, kv.verified_at, u.updated_at)
 WHERE u.kyc_status = 'verified'
   AND u.kyc_verified_at IS NULL;

CREATE INDEX IF NOT EXISTS `idx_users_kyc_level` ON `users` (`kyc_level`);

SET FOREIGN_KEY_CHECKS = 1;
