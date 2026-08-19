-- =====================================================
-- FINAL FOREIGN KEY RESTORATION
-- Run this AFTER all migrations complete
-- =====================================================
SET FOREIGN_KEY_CHECKS = 0;

-- Wallets and transactions foreign keys are restored in 2026_06_16_9998_add_all_foreign_keys.sql.
-- This final migration keeps only relationships not covered there.

-- Social Accounts → Users
ALTER TABLE social_accounts 
ADD CONSTRAINT fk_social_accounts_user 
FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE;

SET FOREIGN_KEY_CHECKS = 1;