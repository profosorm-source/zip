-- ═══════════════════════════════════════════════════════════════════
-- FINAL FOREIGN KEY RESTORATION - COMPLETE & CLEAN
-- Run this AFTER all tables exist
-- ═══════════════════════════════════════════════════════════════════

SET FOREIGN_KEY_CHECKS = 0;

-- -------------------------------------------------------------------
-- Wallets
-- -------------------------------------------------------------------
ALTER TABLE wallets 
ADD CONSTRAINT fk_wallets_user 
FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE;

-- -------------------------------------------------------------------
-- Transactions
-- -------------------------------------------------------------------
ALTER TABLE transactions 
ADD CONSTRAINT fk_transactions_user 
FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE;

-- -------------------------------------------------------------------
-- Social Accounts (user_social_accounts)
-- -------------------------------------------------------------------
ALTER TABLE user_social_accounts 
ADD CONSTRAINT fk_social_user 
FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE;

-- -------------------------------------------------------------------
-- Bank Cards
-- -------------------------------------------------------------------
ALTER TABLE bank_cards 
ADD CONSTRAINT fk_bank_user 
FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE;

-- -------------------------------------------------------------------
-- KYC Verifications
-- -------------------------------------------------------------------
ALTER TABLE kyc_verifications 
ADD CONSTRAINT fk_kyc_user 
FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE;

-- -------------------------------------------------------------------
-- User Roles (RBAC)
-- -------------------------------------------------------------------
ALTER TABLE user_roles 
ADD CONSTRAINT fk_ur_user 
FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE;

ALTER TABLE user_roles 
ADD CONSTRAINT fk_ur_role 
FOREIGN KEY (role_id) REFERENCES roles(id) ON DELETE CASCADE;

-- -------------------------------------------------------------------
-- Role Permissions (RBAC)
-- -------------------------------------------------------------------
ALTER TABLE role_permissions 
ADD CONSTRAINT fk_rp_role 
FOREIGN KEY (role_id) REFERENCES roles(id) ON DELETE CASCADE;

ALTER TABLE role_permissions 
ADD CONSTRAINT fk_rp_permission 
FOREIGN KEY (permission_id) REFERENCES permissions(id) ON DELETE CASCADE;

-- -------------------------------------------------------------------
-- Transaction Events
-- -------------------------------------------------------------------
ALTER TABLE transaction_events 
ADD CONSTRAINT fk_te_tx 
FOREIGN KEY (transaction_id) REFERENCES transactions(transaction_id) ON DELETE CASCADE;

-- -------------------------------------------------------------------
-- Ledger Entries
-- -------------------------------------------------------------------
ALTER TABLE ledger_entries 
ADD CONSTRAINT fk_le_tx 
FOREIGN KEY (transaction_id) REFERENCES transactions(transaction_id) ON DELETE CASCADE;

-- -------------------------------------------------------------------
-- Scheduled Payments
-- -------------------------------------------------------------------
ALTER TABLE scheduled_payments 
ADD CONSTRAINT fk_sp_user 
FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE;

-- -------------------------------------------------------------------
-- Withdrawals
-- -------------------------------------------------------------------
ALTER TABLE withdrawals 
ADD CONSTRAINT fk_withdrawal_user 
FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE;

-- -------------------------------------------------------------------
-- Investments
-- -------------------------------------------------------------------
ALTER TABLE investments 
ADD CONSTRAINT fk_investment_user 
FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE;

-- -------------------------------------------------------------------
-- Escrows
-- -------------------------------------------------------------------
ALTER TABLE escrow_transactions 
ADD CONSTRAINT fk_escrow_buyer 
FOREIGN KEY (buyer_id) REFERENCES users(id) ON DELETE CASCADE;

ALTER TABLE escrow_transactions 
ADD CONSTRAINT fk_escrow_seller 
FOREIGN KEY (seller_id) REFERENCES users(id) ON DELETE CASCADE;

SET FOREIGN_KEY_CHECKS = 1;

-- ═══════════════════════════════════════════════════════════════════
-- END OF FOREIGN KEY RESTORATION
-- ═══════════════════════════════════════════════════════════════════