-- CHORTKE MIGRATION PART 34: FINAL SCHEMA HARDENING & SYNC
-- Senior QA Architect Standardized
SET FOREIGN_KEY_CHECKS = 0;

-- 1. بهینه‌سازی و تکمیل ستون‌های جدول کاربران (Sync با فایل مخزن)
ALTER TABLE `users` 
    ADD COLUMN IF NOT EXISTS `level_type` ENUM('auto', 'purchased') DEFAULT 'auto' AFTER `level_slug`,
    ADD COLUMN IF NOT EXISTS `mobile_verified_at` TIMESTAMP NULL AFTER `email_verified_at`,
    ADD COLUMN IF NOT EXISTS `last_active_date` DATE NULL AFTER `last_user_agent`,
    ADD COLUMN IF NOT EXISTS `device_fingerprint` VARCHAR(64) NULL AFTER `fraud_score`,
    ADD COLUMN IF NOT EXISTS `active_days_count` INT(10) DEFAULT 0 AFTER `device_fingerprint`,
    ADD COLUMN IF NOT EXISTS `blacklist_reason` TEXT NULL AFTER `is_blacklisted`,
    ADD COLUMN IF NOT EXISTS `blacklisted_at` DATETIME NULL AFTER `blacklist_reason`,
    ADD COLUMN IF NOT EXISTS `timezone` VARCHAR(50) DEFAULT 'Asia/Tehran' AFTER `blacklisted_at`,
    MODIFY COLUMN `last_2fa_timeslice` BIGINT(20) UNSIGNED;

-- 2. تکمیل ستون‌های مفقود در جدول تراکنش‌ها
ALTER TABLE `transactions`
    ADD COLUMN IF NOT EXISTS `reference_id` VARCHAR(100) AFTER `idempotency_key`,
    ADD COLUMN IF NOT EXISTS `reference_type` VARCHAR(80) AFTER `reference_id`,
    ADD COLUMN IF NOT EXISTS `admin_note` TEXT AFTER `description`,
    ADD COLUMN IF NOT EXISTS `external_id` VARCHAR(128) AFTER `metadata`;

-- 3. جدول بازبینی برداشت‌های مشکوک (Withdrawal Reviews)
-- Senior QA: این جدول در مدل‌های پیشرفته برای جلوگیری از نشت مالی استفاده شده است
-- [REMOVED DUPLICATE withdrawal_reviews]
DROP TABLE IF EXISTS `ticket_status_history`;
CREATE TABLE `ticket_status_history` (
    `id` INT(10) UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `ticket_id` INT(10) UNSIGNED NOT NULL,
    `old_status` VARCHAR(50),
    `new_status` VARCHAR(50),
    `changed_by` INT(10) UNSIGNED NOT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `referral_tiers`;
CREATE TABLE `referral_tiers` (
    `id` INT(10) UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `user_id` INT(10) UNSIGNED NOT NULL,
    `tier_name` VARCHAR(50),
    `bonus_percent` DECIMAL(5,2),
    `is_active` TINYINT(1) DEFAULT 1,
    `upgraded_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;
