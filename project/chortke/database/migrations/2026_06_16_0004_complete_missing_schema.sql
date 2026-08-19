-- ═══════════════════════════════════════════════════════════════════
-- CHORTKE COMPLETE SCHEMA RECONCILIATION
-- This file creates ALL missing critical tables based on actual Models
-- Run this after the main migration runner
-- ═══════════════════════════════════════════════════════════════════

SET FOREIGN_KEY_CHECKS = 0;

-- -------------------------------------------------------------------
-- 1. WALLETS (Critical - Required by Wallet Model)
-- -------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `wallets` (
    `id` INT(10) UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `user_id` INT(10) UNSIGNED NOT NULL,
    `balance_irt` DECIMAL(24,8) NOT NULL DEFAULT 0.00000000,
    `balance_usdt` DECIMAL(24,8) NOT NULL DEFAULT 0.00000000,
    `locked_irt` DECIMAL(24,8) NOT NULL DEFAULT 0.00000000,
    `locked_usdt` DECIMAL(24,8) NOT NULL DEFAULT 0.00000000,
    `is_frozen` TINYINT(1) NOT NULL DEFAULT 0,
    `last_withdrawal_at` TIMESTAMP NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY `uq_wallet_user` (`user_id`),
    KEY `idx_wallet_frozen` (`is_frozen`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -------------------------------------------------------------------
-- 2. TRANSACTIONS (Critical - Required by Transaction Model)
-- -------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `transactions` (
    `id` INT(10) UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `transaction_id` VARCHAR(64) NOT NULL UNIQUE,
    `user_id` INT(10) UNSIGNED NOT NULL,
    `type` VARCHAR(50) NOT NULL,
    `currency` ENUM('irt','usdt') DEFAULT 'irt',
    `amount` DECIMAL(24,8) NOT NULL,
    `balance_before` DECIMAL(24,8),
    `balance_after` DECIMAL(24,8),
    `status` VARCHAR(20) DEFAULT 'pending',
    `description` TEXT,
    `gateway` VARCHAR(50),
    `gateway_transaction_id` VARCHAR(128),
    `ref_id` VARCHAR(128),
    `ref_type` VARCHAR(100),
    `request_id` VARCHAR(64),
    `ip_address` VARCHAR(45),
    `device_fingerprint` VARCHAR(128),
    `idempotency_key` VARCHAR(128) UNIQUE,
    `metadata` JSON,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    `completed_at` TIMESTAMP NULL,
    KEY `idx_tx_user` (`user_id`),
    KEY `idx_tx_type` (`type`),
    KEY `idx_tx_status` (`status`),
    KEY `idx_tx_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -------------------------------------------------------------------
-- 3. ROLES (RBAC - Required by Role Model)
-- -------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `roles` (
    `id` INT(10) UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `name` VARCHAR(100) NOT NULL UNIQUE,
    `slug` VARCHAR(100) NOT NULL UNIQUE,
    `description` VARCHAR(255),
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -------------------------------------------------------------------
-- 4. PERMISSIONS (RBAC - Required by Permission Model)
-- -------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `permissions` (
    `id` INT(10) UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `name` VARCHAR(100) NOT NULL UNIQUE,
    `slug` VARCHAR(100) NOT NULL UNIQUE,
    `description` VARCHAR(255),
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -------------------------------------------------------------------
-- 5. USER_ROLES (RBAC Junction Table)
-- -------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `user_roles` (
    `user_id` INT(10) UNSIGNED NOT NULL,
    `role_id` INT(10) UNSIGNED NOT NULL,
    `granted_by` INT(10) UNSIGNED,
    `granted_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`user_id`, `role_id`),
    KEY `idx_ur_role` (`role_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -------------------------------------------------------------------
-- 6. ROLE_PERMISSIONS (RBAC Junction Table)
-- -------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `role_permissions` (
    `role_id` INT(10) UNSIGNED NOT NULL,
    `permission_id` INT(10) UNSIGNED NOT NULL,
    PRIMARY KEY (`role_id`, `permission_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -------------------------------------------------------------------
-- 7. TRANSACTION_EVENTS (Required by Transaction Model for Event Sourcing)
-- -------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `transaction_events` (
    `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `transaction_id` VARCHAR(64) NOT NULL,
    `event_type` VARCHAR(50) NOT NULL,
    `previous_status` VARCHAR(20),
    `new_status` VARCHAR(20),
    `reason` TEXT,
    `changed_by` INT(10) UNSIGNED,
    `metadata` JSON,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    KEY `idx_te_tx` (`transaction_id`),
    KEY `idx_te_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -------------------------------------------------------------------
-- 8. LEDGER_ENTRIES (Required by LedgerEntry Model)
-- -------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `ledger_entries` (
    `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `transaction_id` VARCHAR(64) NOT NULL,
    `account` VARCHAR(100) NOT NULL,
    `debit` DECIMAL(24,8) DEFAULT 0,
    `credit` DECIMAL(24,8) DEFAULT 0,
    `currency` VARCHAR(10) DEFAULT 'irt',
    `description` TEXT,
    `metadata` JSON,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY `idx_le_tx` (`transaction_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -------------------------------------------------------------------
-- 9. SCHEDULED_PAYMENTS (Required by ScheduledPayment Model)
-- -------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `scheduled_payments` (
    `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `user_id` INT(10) UNSIGNED NOT NULL,
    `amount` DECIMAL(24,8) NOT NULL,
    `currency` VARCHAR(10) DEFAULT 'irt',
    `frequency` VARCHAR(50) DEFAULT 'one_time',
    `next_run_at` DATETIME NOT NULL,
    `status` VARCHAR(50) DEFAULT 'active',
    `description` VARCHAR(255),
    `metadata` JSON,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY `idx_sp_user` (`user_id`),
    KEY `idx_sp_next` (`next_run_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- -------------------------------------------------------------------
-- 10. PHASE1 SCHEMA DRIFT COMPATIBILITY
-- -------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `activities` (
  `id` INT(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` INT(10) UNSIGNED NULL,
  `activity_type` VARCHAR(100) NULL,
  `title` VARCHAR(255) NULL,
  `description` TEXT NULL,
  `metadata` JSON NULL,
  `ip_address` VARCHAR(45) NULL,
  `created_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_activities_user` (`user_id`),
  KEY `idx_activities_type_created` (`activity_type`, `created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE `activity_logs`
  ADD COLUMN IF NOT EXISTS `action` VARCHAR(100) NULL AFTER `event`,
  ADD COLUMN IF NOT EXISTS `description` TEXT NULL AFTER `action`,
  ADD COLUMN IF NOT EXISTS `channel` VARCHAR(50) NULL DEFAULT 'system' AFTER `description`,
  ADD COLUMN IF NOT EXISTS `deleted_at` TIMESTAMP NULL DEFAULT NULL AFTER `created_at`;
UPDATE `activity_logs` SET `action` = COALESCE(`action`, `event`) WHERE `action` IS NULL;

ALTER TABLE `trading_records`
  ADD COLUMN IF NOT EXISTS `admin_id` INT(10) UNSIGNED NULL AFTER `user_id`,
  ADD COLUMN IF NOT EXISTS `investment_id` INT(10) UNSIGNED NULL AFTER `admin_id`,
  ADD COLUMN IF NOT EXISTS `direction` ENUM('buy','sell') NULL AFTER `type`,
  ADD COLUMN IF NOT EXISTS `pair` VARCHAR(20) NULL AFTER `symbol`,
  ADD COLUMN IF NOT EXISTS `open_price` DECIMAL(24,8) NULL AFTER `price`,
  ADD COLUMN IF NOT EXISTS `close_price` DECIMAL(24,8) NULL AFTER `open_price`,
  ADD COLUMN IF NOT EXISTS `stop_loss` DECIMAL(24,8) NULL AFTER `close_price`,
  ADD COLUMN IF NOT EXISTS `take_profit` DECIMAL(24,8) NULL AFTER `stop_loss`,
  ADD COLUMN IF NOT EXISTS `profit_loss_amount` DECIMAL(24,8) NOT NULL DEFAULT 0 AFTER `profit_loss`,
  ADD COLUMN IF NOT EXISTS `profit_loss_percent` DECIMAL(10,4) NULL DEFAULT 0 AFTER `profit_loss_amount`,
  ADD COLUMN IF NOT EXISTS `currency` VARCHAR(10) NULL DEFAULT 'usdt' AFTER `profit_loss_percent`,
  ADD COLUMN IF NOT EXISTS `reason` TEXT NULL AFTER `currency`,
  ADD COLUMN IF NOT EXISTS `updated_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP AFTER `created_at`,
  ADD COLUMN IF NOT EXISTS `closed_at` TIMESTAMP NULL DEFAULT NULL AFTER `close_time`;
UPDATE `trading_records` SET `profit_loss_amount` = COALESCE(NULLIF(`profit_loss_amount`, 0), `profit_loss`, 0);
UPDATE `trading_records` SET `direction` = COALESCE(`direction`, `type`), `pair` = COALESCE(`pair`, `symbol`), `open_price` = COALESCE(`open_price`, `price`);

ALTER TABLE `investments`
  ADD COLUMN IF NOT EXISTS `profit` DECIMAL(24,4) NOT NULL DEFAULT 0 AFTER `profit_earned`;
UPDATE `investments` SET `profit` = COALESCE(NULLIF(`profit`, 0), `profit_earned`, 0);

ALTER TABLE `sentry_events`
  ADD COLUMN IF NOT EXISTS `culprit` VARCHAR(255) NULL AFTER `message`;
UPDATE `sentry_events` SET `culprit` = COALESCE(`culprit`, `exception_type`, 'unknown') WHERE `culprit` IS NULL;

ALTER TABLE `alert_rules`
  ADD COLUMN IF NOT EXISTS `severity` ENUM('low','medium','high','warning','critical') NOT NULL DEFAULT 'medium' AFTER `condition`;

-- -------------------------------------------------------------------
-- notifications: ستون‌های مفقود analytics (click tracking + soft-delete)
-- مدل Notification متدهای analytics زنده‌ای دارد (getOverviewStats/
-- getFunnelStats/getFatigueSummary) و recordClick/restore که به
-- clicked_at / is_deleted / deleted_at ارجاع می‌دهند ولی هرگز در schema
-- اصلی ساخته نشده بودند. با مقدار پیش‌فرض ایمن اضافه می‌شوند تا همه‌ی
-- ردیف‌های فعلی سازگار بمانند.
-- -------------------------------------------------------------------
ALTER TABLE `notifications`
  ADD COLUMN IF NOT EXISTS `clicked_at` DATETIME NULL DEFAULT NULL AFTER `read_at`,
  ADD COLUMN IF NOT EXISTS `is_deleted` TINYINT(1) NOT NULL DEFAULT 0 AFTER `is_archived`,
  ADD COLUMN IF NOT EXISTS `deleted_at` DATETIME NULL DEFAULT NULL AFTER `archived_at`;

-- -------------------------------------------------------------------
-- user_score_adjustments: ستون‌های lifecycle مدیریت اصلاح امتیاز
-- کنترلر ScoreManagementController expiry و revoke مدیریت می‌کند ولی
-- جدول فقط ستون‌های پایه داشت. این ستون‌ها چرخه‌حیات فعال/منقضی/ابطال‌شده
-- را پشتیبانی می‌کنند. مقادیر پیش‌فرض ایمن: همه‌ی ردیف‌های فعلی 'active'.
-- -------------------------------------------------------------------
ALTER TABLE `user_score_adjustments`
  ADD COLUMN IF NOT EXISTS `status` ENUM('active','revoked') NOT NULL DEFAULT 'active' AFTER `domain`,
  ADD COLUMN IF NOT EXISTS `expires_at` DATETIME NULL DEFAULT NULL AFTER `status`,
  ADD COLUMN IF NOT EXISTS `revoked_by` INT(10) UNSIGNED NULL DEFAULT NULL AFTER `expires_at`,
  ADD COLUMN IF NOT EXISTS `revoked_at` DATETIME NULL DEFAULT NULL AFTER `revoked_by`,
  ADD COLUMN IF NOT EXISTS `revoked_reason` TEXT NULL AFTER `revoked_at`;

-- -------------------------------------------------------------------
-- bug_reports: جدول اختصاصی گزارش‌های باگ (مسیر اصلی TicketService)
-- TicketService در خواندن/نوشتن ابتدا bug_reports را بررسی می‌کند ولی این
-- جدول هرگز ساخته نشده بود (فقط bug_report_comments وجود داشت). ساختار
-- دقیقاً مطابق ستون‌های مصرف‌شده در submitBugReport/getBugReports/
-- getAdminBugReports/findBugReport و ویوی ادمین است.
-- -------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `bug_reports` (
    `id` INT(10) UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `user_id` INT(10) UNSIGNED NOT NULL,
    `page_url` VARCHAR(2048) NOT NULL DEFAULT '',
    `page_title` VARCHAR(255) NOT NULL DEFAULT '',
    `category` VARCHAR(50) NOT NULL DEFAULT 'other',
    `description` TEXT NOT NULL,
    `screenshot_path` VARCHAR(500) NULL,
    `screen_resolution` VARCHAR(30) NOT NULL DEFAULT '',
    `device_fingerprint` VARCHAR(255) NOT NULL DEFAULT '',
    `user_agent` VARCHAR(500) NOT NULL DEFAULT '',
    `ip_address` VARCHAR(45) NULL,
    `status` ENUM('open','answered','in_progress','on_hold','closed') NOT NULL DEFAULT 'open',
    `priority` ENUM('low','normal','high','urgent','critical') NOT NULL DEFAULT 'normal',
    `is_suspicious` TINYINT(1) NOT NULL DEFAULT 0,
    `admin_note` TEXT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY `idx_bug_report_user` (`user_id`),
    KEY `idx_bug_report_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -------------------------------------------------------------------
-- permissions: ۱۳ permission مفقود که کد به آن‌ها ارجاع می‌دهد ولی
-- هرگز در seed/migration اولیه ساخته نشده بودند. این باعث می‌شد
-- authorizeById برای admin/super_admin کاربران همیشه false برگرداند.
-- با ON DUPLICATE KEY UPDATE idempotent است.
-- -------------------------------------------------------------------
INSERT INTO permissions (`name`, `slug`, `group_name`, `description`, `created_at`, `updated_at`) VALUES
('خروجی کاربران', 'admin.export.users', 'admin', 'Export users data (CSV)', NOW(), NOW()),
('خروجی تراکنش‌ها', 'admin.export.transactions', 'admin', 'Export transactions data (CSV)', NOW(), NOW()),
('خروجی برداشت‌ها', 'admin.export.withdrawals', 'admin', 'Export withdrawals data (CSV)', NOW(), NOW()),
('خروجی حسابرسی', 'admin.export.audit', 'admin', 'Export audit trail (CSV)', NOW(), NOW()),
('مشاهده کرون‌جاب‌ها', 'admin.view_cron_jobs', 'admin', 'View cron job list', NOW(), NOW()),
('اجرای کرون‌جاب‌ها', 'admin.execute_cron_jobs', 'admin', 'Execute cron jobs manually', NOW(), NOW()),
('مشاهده گزارش‌های باگ', 'bug_reports.view', 'bug_reports', 'View bug reports', NOW(), NOW()),
('تغییر وضعیت گزارش باگ', 'bug_reports.update_status', 'bug_reports', 'Update bug report status', NOW(), NOW()),
('تغییر اولویت گزارش باگ', 'bug_reports.update_priority', 'bug_reports', 'Update bug report priority', NOW(), NOW()),
('مشاهده همه تیکت‌ها', 'tickets.view_all', 'tickets', 'View all tickets (not just own)', NOW(), NOW()),
('ارجاع تیکت', 'tickets.assign', 'tickets', 'Assign tickets to operators', NOW(), NOW()),
('مدیریت پیام‌ها', 'messages.moderate', 'messages', 'Moderate user messages', NOW(), NOW()),
('مدیریت شبکه‌های اجتماعی', 'user.manage_social_accounts', 'user', 'Manage user social accounts', NOW(), NOW())
ON DUPLICATE KEY UPDATE
  name = VALUES(name), group_name = VALUES(group_name), description = VALUES(description), updated_at = NOW();

-- اعطای همه permissionهای فوق به نقش‌های admin و super_admin
INSERT IGNORE INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id FROM permissions p
CROSS JOIN roles r
WHERE p.slug IN (
  'admin.export.users', 'admin.export.transactions', 'admin.export.withdrawals', 'admin.export.audit',
  'admin.view_cron_jobs', 'admin.execute_cron_jobs',
  'bug_reports.view', 'bug_reports.update_status', 'bug_reports.update_priority',
  'tickets.view_all', 'tickets.assign',
  'messages.moderate', 'user.manage_social_accounts'
)
AND r.slug IN ('admin', 'super_admin');

-- -------------------------------------------------------------------
-- user management + influencer + roles + settings + referrals permissions
-- مسیرهای ادمین به این permissionها نیاز دارند ولی در seed نبودند.
-- -------------------------------------------------------------------
INSERT INTO permissions (`name`, `slug`, `group_name`, `description`, `created_at`, `updated_at`) VALUES
('مدیریت کاربران — مشاهده', 'user.manage.view', 'admin', 'View user management pages', NOW(), NOW()),
('مدیریت کاربران — ویرایش', 'user.manage.edit', 'admin', 'Edit user profiles', NOW(), NOW()),
('مسدودسازی کاربران', 'user.manage.ban', 'admin', 'Ban/suspend/unsuspend users', NOW(), NOW()),
('ویرایش کاربران (legacy)', 'users.edit', 'admin', 'Edit users (legacy slug)', NOW(), NOW()),
('مشاهده تنظیمات', 'settings.view', 'admin', 'View system settings', NOW(), NOW()),
('ویرایش تنظیمات', 'settings.edit', 'admin', 'Edit system settings', NOW(), NOW()),
('مشاهده تنظیمات (legacy)', 'admin.view_settings', 'admin', 'View settings (legacy slug)', NOW(), NOW()),
('ویرایش تنظیمات (legacy)', 'admin.edit_settings', 'admin', 'Edit settings (legacy slug)', NOW(), NOW()),
('مشاهده اینفلوئنسرها', 'influencer.view', 'admin', 'View influencer management', NOW(), NOW()),
('مدیریت اینفلوئنسرها', 'influencer.manage', 'admin', 'Manage influencers', NOW(), NOW()),
('مشاهده نقش‌ها', 'roles.view', 'admin', 'View roles', NOW(), NOW()),
('مدیریت نقش‌ها', 'roles.manage', 'admin', 'Manage roles', NOW(), NOW()),
('مشاهده ارجاعات', 'referrals.view', 'admin', 'View referrals', NOW(), NOW()),
('مدیریت ارجاعات', 'referrals.manage', 'admin', 'Manage referrals', NOW(), NOW())
ON DUPLICATE KEY UPDATE name = VALUES(name), updated_at = NOW();

-- اعطای همه permissionهای user/roles/settings به admin و super_admin
INSERT IGNORE INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id FROM permissions p
CROSS JOIN roles r
WHERE p.slug IN (
  'user.manage.view', 'user.manage.edit', 'user.manage.ban', 'users.edit',
  'settings.view', 'settings.edit', 'admin.view_settings', 'admin.edit_settings',
  'influencer.view', 'influencer.manage',
  'roles.view', 'roles.manage',
  'referrals.view', 'referrals.manage'
)
AND r.slug IN ('admin', 'super_admin');

-- -------------------------------------------------------------------
-- account_deletion_logs: ستون deleted_by + مقدار enum 'deleted'
-- مدل AccountDeletionLog به‌طور سازگار از deleted_by و status='deleted'
-- استفاده می‌کند ولی schema این موارد را نداشت.
-- -------------------------------------------------------------------
ALTER TABLE `account_deletion_logs`
  ADD COLUMN IF NOT EXISTS `deleted_by` INT(10) UNSIGNED NULL DEFAULT NULL AFTER `deleted_at`;

ALTER TABLE `account_deletion_logs`
  MODIFY COLUMN `status` ENUM('pending','completed','cancelled','deleted','requested') NOT NULL DEFAULT 'pending';

-- -------------------------------------------------------------------
-- direct_messages: ستون‌های مفقود (read_at, updated_at)
-- MessageController به read_at ارجاع می‌دهد ولی جدول فقط is_read دارد.
-- -------------------------------------------------------------------
-- email_queue: ستون updated_at مفقود (کد retry به آن ارجاع می‌دهد)
ALTER TABLE `email_queue`
  ADD COLUMN IF NOT EXISTS `updated_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP AFTER `created_at`;

ALTER TABLE `direct_messages`
  ADD COLUMN IF NOT EXISTS `read_at` DATETIME NULL DEFAULT NULL AFTER `is_read`,
  ADD COLUMN IF NOT EXISTS `updated_at` TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP AFTER `created_at`;

SET FOREIGN_KEY_CHECKS = 1;

-- ═══════════════════════════════════════════════════════════════════
-- END OF COMPLETE SCHEMA RECONCILIATION
-- ═══════════════════════════════════════════════════════════════════