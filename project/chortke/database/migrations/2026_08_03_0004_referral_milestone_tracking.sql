-- CHORTKE MIGRATION: ردیابی دستیابی کاربران به referral milestones + seed تنظیمات و milestones

-- جدول ردیابی دستیابی کاربر به milestone (برای idempotency و جلوگیری از اهدای دوباره)
CREATE TABLE IF NOT EXISTS `referral_user_milestones` (
    `id` INT(10) UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `user_id` INT(10) UNSIGNED NOT NULL,
    `milestone_id` INT(10) UNSIGNED NOT NULL,
    `reward_amount` DECIMAL(24,8) NOT NULL DEFAULT 0,
    `currency` ENUM('irt','usdt') NOT NULL DEFAULT 'irt',
    `awarded_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY `uq_user_milestone` (`user_id`, `milestone_id`),
    KEY `idx_um_user` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- seed تنظیمات بونوس تأیید محتوا (اگر از قبل نباشند)
INSERT INTO system_settings (`key`, `value`, `group`, `type`, `is_public`) VALUES
    ('referral_content_approval_amount', '5000', 'referral', 'string', 0),
    ('referral_content_approval_currency', 'irt', 'referral', 'string', 0),
    ('referral_commission_percent', '5', 'referral', 'string', 0),
    ('referral_daily_limit', '50', 'referral', 'string', 0),
    ('referral_signup_bonus', '1000', 'referral', 'string', 0),
    ('referral_signup_bonus_usdt', '0', 'referral', 'string', 0)
ON DUPLICATE KEY UPDATE `value` = VALUES(`value`);

-- seed برخی milestones واقعی (اگر جدول خالی باشد)
INSERT INTO referral_milestones (`title`, `threshold_value`, `milestone_type`, `reward_amount`, `currency`, `is_active`) VALUES
    ('اولین معرف موفق', 1, 'referrals', 10000, 'irt', 1),
    ('۵ معرف موفق', 5, 'referrals', 25000, 'irt', 1),
    ('۱۰ معرف موفق', 10, 'referrals', 50000, 'irt', 1),
    ('۵۰ معرف موفق', 50, 'referrals', 150000, 'irt', 1)
ON DUPLICATE KEY UPDATE `title` = VALUES(`title`);
