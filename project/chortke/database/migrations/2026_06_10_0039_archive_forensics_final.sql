-- CHORTKE MIGRATION PART 33: TRANSACTIONS ARCHIVE & FORENSIC VIEWS
-- Senior QA Architect Standardized
SET FOREIGN_KEY_CHECKS = 0;

-- 1. جدول آرشیو تراکنش‌ها (برای سبک نگه داشتن جدول اصلی)
-- [REMOVED DUPLICATE transactions_archive]
DROP TABLE IF EXISTS `sentry_issue_events`;
CREATE TABLE `sentry_issue_events` (
    `id` INT(10) UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `issue_id` INT(10) UNSIGNED NOT NULL,
    `event_type` VARCHAR(50) NOT NULL COMMENT 'assigned, resolved, reopened',
    `details` JSON,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    KEY `idx_sie_issue` (`issue_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `seo_executions_legacy_bak`;
CREATE TABLE `seo_executions_legacy_bak` (
    `id` INT(10) UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `ad_id` INT(10) UNSIGNED,
    `user_id` INT(10) UNSIGNED,
    `earned_amount` DECIMAL(24,4),
    `created_at` TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 4. ویوی شناسایی تقلب‌های تکرار شده (Fraud Correlation View)
-- این ویو به ادمین کمک می‌کند تا ارتباط بین کاربران مشکوک را پیدا کند
DROP VIEW IF EXISTS `v_fraud_correlation_analysis`;
CREATE VIEW `v_fraud_correlation_analysis` AS
SELECT 
    f1.user_id as user_a, 
    f2.user_id as user_b, 
    f1.fingerprint, 
    f1.ip_address,
    u1.full_name as name_a,
    u2.full_name as name_b
FROM user_sessions f1
JOIN user_sessions f2 ON f1.fingerprint = f2.fingerprint AND f1.user_id < f2.user_id
JOIN users u1 ON f1.user_id = u1.id
JOIN users u2 ON f2.user_id = u2.id
WHERE f1.is_active = 1 AND f2.is_active = 1;

SET FOREIGN_KEY_CHECKS = 1;
