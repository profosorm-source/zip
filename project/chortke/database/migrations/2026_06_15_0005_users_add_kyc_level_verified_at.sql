SET FOREIGN_KEY_CHECKS = 0;

-- CHORTKE MIGRATION: Add kyc_level and kyc_verified_at to users
-- BUGFIX-KYC-LEVEL-COLUMNS-2026-06
--
-- app/Listeners/KYCListener.php and several unlockFeatures()/updateWithdrawalLimits()
-- helpers write to users.kyc_level and users.kyc_verified_at. These columns were
-- expected by the code but missing from the schema. This migration adds them
-- and backfills verified_at from kyc_verifications where possible.

SET NAMES utf8mb4;

ALTER TABLE `users`
    ADD COLUMN IF NOT EXISTS `kyc_level`       TINYINT(3) UNSIGNED NOT NULL DEFAULT 0
                                               COMMENT 'KYC tier: 0=unverified, 1=basic, 2=full, 3=enhanced',
    ADD COLUMN IF NOT EXISTS `kyc_verified_at` TIMESTAMP NULL DEFAULT NULL
                                               COMMENT 'Set by KYCListener on kyc.approved events.';

-- Backfill: any user already marked verified gets level=1 and a verified_at
-- from kyc_verifications (best-effort; falls back to the user's updated_at).
UPDATE `users` u
  LEFT JOIN `kyc_verifications` kv ON kv.user_id = u.id AND kv.status = 'verified'
   SET u.kyc_level       = CASE WHEN u.kyc_status = 'verified' AND u.kyc_level = 0 THEN 1 ELSE u.kyc_level END,
       u.kyc_verified_at = COALESCE(u.kyc_verified_at, kv.verified_at, u.updated_at)
 WHERE u.kyc_status = 'verified'
   AND u.kyc_verified_at IS NULL;

CREATE INDEX IF NOT EXISTS `idx_users_kyc_level` ON `users` (`kyc_level`);

SET FOREIGN_KEY_CHECKS = 1;
