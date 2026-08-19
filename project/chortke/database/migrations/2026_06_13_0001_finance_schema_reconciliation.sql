-- CHORTKE MIGRATION: Finance/Admin schema reconciliation
-- Purpose: align database schema with existing Wallet/Withdrawal/ManualDeposit/CryptoDeposit/RBAC code paths.

SET FOREIGN_KEY_CHECKS = 0;

-- ─────────────────────────────────────────────────────────────────────────────
-- RBAC compatibility
-- ─────────────────────────────────────────────────────────────────────────────
ALTER TABLE `permissions`
  ADD COLUMN IF NOT EXISTS `group_name` VARCHAR(100) NULL DEFAULT 'other' AFTER `slug`,
  ADD COLUMN IF NOT EXISTS `description` TEXT NULL AFTER `group_name`,
  ADD COLUMN IF NOT EXISTS `created_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP AFTER `description`,
  ADD COLUMN IF NOT EXISTS `updated_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP AFTER `created_at`;

ALTER TABLE `roles`
  ADD COLUMN IF NOT EXISTS `description` TEXT NULL AFTER `slug`,
  ADD COLUMN IF NOT EXISTS `is_system` TINYINT(1) NOT NULL DEFAULT 0 AFTER `description`,
  ADD COLUMN IF NOT EXISTS `is_active` TINYINT(1) NOT NULL DEFAULT 1 AFTER `is_system`,
  ADD COLUMN IF NOT EXISTS `created_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP AFTER `is_active`,
  ADD COLUMN IF NOT EXISTS `updated_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP AFTER `created_at`,
  ADD COLUMN IF NOT EXISTS `deleted_at` TIMESTAMP NULL DEFAULT NULL AFTER `updated_at`;

ALTER TABLE `users`
  ADD COLUMN IF NOT EXISTS `role_id` INT(10) UNSIGNED NULL AFTER `role`,
  ADD COLUMN IF NOT EXISTS `mobile_verified_at` TIMESTAMP NULL DEFAULT NULL AFTER `email_verified_at`,
  ADD COLUMN IF NOT EXISTS `device_fingerprint` VARCHAR(64) NULL AFTER `fraud_score`,
  ADD COLUMN IF NOT EXISTS `active_days_count` INT(10) NULL DEFAULT 0 AFTER `device_fingerprint`,
  ADD COLUMN IF NOT EXISTS `blacklist_reason` TEXT NULL AFTER `is_blacklisted`,
  ADD COLUMN IF NOT EXISTS `blacklisted_at` DATETIME NULL AFTER `blacklist_reason`,
  ADD COLUMN IF NOT EXISTS `level_type` ENUM('auto','purchased') NULL DEFAULT 'auto' AFTER `level_slug`,
  ADD COLUMN IF NOT EXISTS `last_active_date` DATE NULL AFTER `last_user_agent`,
  ADD COLUMN IF NOT EXISTS `kyc_status` VARCHAR(50) NULL DEFAULT 'unverified' AFTER `deleted_at`,
  ADD COLUMN IF NOT EXISTS `tier` VARCHAR(50) NULL DEFAULT 'SILVER' AFTER `kyc_status`;

INSERT INTO roles (`name`, `slug`, `description`, `is_system`, `is_active`, `created_at`, `updated_at`) VALUES
('کاربر عادی', 'user', 'Default user role', 1, 1, NOW(), NOW()),
('مدیر', 'admin', 'Administrator role', 1, 1, NOW(), NOW()),
('سوپر مدیر', 'super_admin', 'Super administrator role', 1, 1, NOW(), NOW())
ON DUPLICATE KEY UPDATE
  name = VALUES(name), description = VALUES(description), is_system = VALUES(is_system), is_active = VALUES(is_active), updated_at = NOW();

-- Fill users.role_id for legacy code paths while user_roles remains source of truth for multi-role.
UPDATE users u
JOIN roles r ON r.slug = u.role
SET u.role_id = r.id
WHERE u.role_id IS NULL;

-- Core finance permissions used by routes/admin.php
INSERT INTO permissions (`name`, `slug`, `group_name`, `description`, `created_at`, `updated_at`) VALUES
('مشاهده واریز دستی', 'finance.deposit.view', 'finance', 'View manual deposits', NOW(), NOW()),
('تأیید واریز دستی', 'finance.deposit.approve', 'finance', 'Approve/reject manual deposits', NOW(), NOW()),
('مشاهده واریز کریپتو', 'finance.crypto.view', 'finance', 'View crypto deposits', NOW(), NOW()),
('تأیید واریز کریپتو', 'finance.crypto.approve', 'finance', 'Approve/reject crypto deposits', NOW(), NOW()),
('مشاهده برداشت‌ها', 'finance.withdrawal.view', 'finance', 'View withdrawals', NOW(), NOW()),
('تأیید برداشت‌ها', 'finance.withdrawal.approve', 'finance', 'Approve/reject withdrawals', NOW(), NOW()),
('مشاهده تراکنش‌ها', 'finance.transaction.view', 'finance', 'View transactions', NOW(), NOW()),
('تأیید/برگشت تراکنش‌ها', 'finance.transaction.approve', 'finance', 'Approve/reverse transactions', NOW(), NOW()),
('مشاهده کارت‌ها', 'finance.card.view', 'finance', 'View bank cards', NOW(), NOW())
ON DUPLICATE KEY UPDATE
  name = VALUES(name), group_name = VALUES(group_name), description = VALUES(description), updated_at = NOW();

-- grant finance permissions to admin and super_admin
INSERT IGNORE INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id
FROM roles r
JOIN permissions p ON p.slug IN (
  'finance.deposit.view','finance.deposit.approve','finance.crypto.view','finance.crypto.approve',
  'finance.withdrawal.view','finance.withdrawal.approve','finance.transaction.view','finance.transaction.approve',
  'finance.card.view'
)
WHERE r.slug IN ('admin', 'super_admin');

-- ─────────────────────────────────────────────────────────────────────────────
-- Transaction status event log required by App\Models\Transaction::recordStatusChange
-- ─────────────────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `transaction_events` (
  `id` INT(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `transaction_id` VARCHAR(64) NOT NULL,
  `event_type` VARCHAR(80) NOT NULL,
  `previous_status` VARCHAR(30) NULL,
  `new_status` VARCHAR(30) NOT NULL,
  `reason` TEXT NULL,
  `changed_by` INT(10) UNSIGNED NULL,
  `metadata` LONGTEXT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_transaction_events_tx` (`transaction_id`),
  KEY `idx_transaction_events_status` (`new_status`),
  KEY `idx_transaction_events_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─────────────────────────────────────────────────────────────────────────────
-- Bank cards compatibility
-- ─────────────────────────────────────────────────────────────────────────────
ALTER TABLE `bank_cards`
  ADD COLUMN IF NOT EXISTS `owner_name` VARCHAR(255) NULL AFTER `card_number`,
  ADD COLUMN IF NOT EXISTS `iban` VARCHAR(30) NULL AFTER `sheba`;

-- ─────────────────────────────────────────────────────────────────────────────
-- Manual deposits: align table with User/Admin ManualDeposit services and views.
-- ─────────────────────────────────────────────────────────────────────────────
ALTER TABLE `kyc_verifications`
  ADD COLUMN IF NOT EXISTS `admin_note` TEXT NULL AFTER `updated_at`,
  ADD COLUMN IF NOT EXISTS `first_name` VARCHAR(100) NULL AFTER `admin_note`,
  ADD COLUMN IF NOT EXISTS `last_name` VARCHAR(100) NULL AFTER `first_name`,
  ADD COLUMN IF NOT EXISTS `national_code_hash` VARCHAR(128) NULL AFTER `last_name`,
  ADD COLUMN IF NOT EXISTS `under_review_by` INT(10) UNSIGNED NULL AFTER `national_code_hash`,
  ADD COLUMN IF NOT EXISTS `review_started_at` TIMESTAMP NULL DEFAULT NULL AFTER `under_review_by`,
  ADD COLUMN IF NOT EXISTS `reviewed_by` INT(10) UNSIGNED NULL AFTER `review_started_at`;

ALTER TABLE `manual_deposits`
  ADD COLUMN IF NOT EXISTS `card_id` INT(10) UNSIGNED NULL AFTER `user_id`,
  ADD COLUMN IF NOT EXISTS `bank_card_id` INT(10) UNSIGNED NULL AFTER `card_id`,
  ADD COLUMN IF NOT EXISTS `user_description` TEXT NULL AFTER `tracking_code`,
  ADD COLUMN IF NOT EXISTS `receipt_path` VARCHAR(255) NULL AFTER `user_description`,
  ADD COLUMN IF NOT EXISTS `rejection_reason` TEXT NULL AFTER `transaction_id`,
  ADD COLUMN IF NOT EXISTS `reviewed_by` INT(10) UNSIGNED NULL AFTER `admin_id`,
  ADD COLUMN IF NOT EXISTS `reviewed_at` TIMESTAMP NULL DEFAULT NULL AFTER `approved_at`,
  ADD COLUMN IF NOT EXISTS `admin_note` TEXT NULL AFTER `reviewed_at`;

UPDATE manual_deposits SET card_id = COALESCE(card_id, bank_card_id), bank_card_id = COALESCE(bank_card_id, card_id);

-- ─────────────────────────────────────────────────────────────────────────────
-- Crypto deposit intents/deposits: align model/job/service expectations.
-- ─────────────────────────────────────────────────────────────────────────────
ALTER TABLE `crypto_deposit_intents`
  ADD COLUMN IF NOT EXISTS `network` VARCHAR(20) NULL AFTER `user_id`,
  ADD COLUMN IF NOT EXISTS `requested_amount` DECIMAL(24,8) NULL AFTER `currency`,
  ADD COLUMN IF NOT EXISTS `expected_amount` DECIMAL(24,8) NULL AFTER `requested_amount`,
  ADD COLUMN IF NOT EXISTS `to_wallet` VARCHAR(255) NULL AFTER `expected_amount`,
  ADD COLUMN IF NOT EXISTS `expires_at` DATETIME NULL AFTER `status`,
  ADD COLUMN IF NOT EXISTS `claimed_at` DATETIME NULL AFTER `expires_at`,
  ADD COLUMN IF NOT EXISTS `ip_address` VARCHAR(45) NULL AFTER `claimed_at`,
  ADD COLUMN IF NOT EXISTS `user_agent` TEXT NULL AFTER `ip_address`,
  ADD COLUMN IF NOT EXISTS `updated_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP AFTER `created_at`;

CREATE INDEX IF NOT EXISTS `idx_crypto_intents_open_user` ON `crypto_deposit_intents` (`user_id`, `status`, `expires_at`);
CREATE INDEX IF NOT EXISTS `idx_crypto_intents_amount` ON `crypto_deposit_intents` (`network`, `expected_amount`, `status`);

ALTER TABLE `crypto_deposits`
  ADD COLUMN IF NOT EXISTS `wallet_address` VARCHAR(255) NULL AFTER `network`,
  ADD COLUMN IF NOT EXISTS `deposit_date` DATE NULL AFTER `wallet_address`,
  ADD COLUMN IF NOT EXISTS `deposit_time` TIME NULL AFTER `deposit_date`,
  ADD COLUMN IF NOT EXISTS `auto_check_deadline` DATETIME NULL AFTER `verification_status`,
  ADD COLUMN IF NOT EXISTS `auto_check_attempts` INT(10) UNSIGNED NOT NULL DEFAULT 0 AFTER `auto_check_deadline`,
  ADD COLUMN IF NOT EXISTS `verified_at` TIMESTAMP NULL DEFAULT NULL AFTER `confirmed_at`,
  ADD COLUMN IF NOT EXISTS `reviewed_by` INT(10) UNSIGNED NULL AFTER `verified_at`,
  ADD COLUMN IF NOT EXISTS `reviewed_at` TIMESTAMP NULL DEFAULT NULL AFTER `reviewed_by`,
  ADD COLUMN IF NOT EXISTS `rejection_reason` TEXT NULL AFTER `reviewed_at`,
  ADD COLUMN IF NOT EXISTS `explorer_data` LONGTEXT NULL AFTER `rejection_reason`;

-- ─────────────────────────────────────────────────────────────────────────────
-- Withdrawal timeout review queue compatibility
-- ─────────────────────────────────────────────────────────────────────────────
ALTER TABLE `withdrawal_reviews`
  ADD COLUMN IF NOT EXISTS `user_id` INT(10) UNSIGNED NULL AFTER `withdrawal_id`,
  ADD COLUMN IF NOT EXISTS `amount` DECIMAL(24,8) NULL AFTER `user_id`,
  ADD COLUMN IF NOT EXISTS `currency` VARCHAR(10) NULL AFTER `amount`,
  ADD COLUMN IF NOT EXISTS `stuck_hours` INT(10) UNSIGNED NULL AFTER `status`,
  ADD COLUMN IF NOT EXISTS `detected_at` DATETIME NULL AFTER `stuck_hours`;

-- ─────────────────────────────────────────────────────────────────────────────
-- Admin dashboard/sidebar compatibility
-- ─────────────────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `bug_report_comments` (
  `id` INT(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `bug_report_id` INT(10) UNSIGNED NULL,
  `user_id` INT(10) UNSIGNED NULL,
  `comment` TEXT NULL,
  `created_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_bug_report_comments_report` (`bug_report_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE `system_alerts`
  ADD COLUMN IF NOT EXISTS `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  ADD COLUMN IF NOT EXISTS `acknowledged_at` TIMESTAMP NULL DEFAULT NULL;

SET FOREIGN_KEY_CHECKS = 1;
