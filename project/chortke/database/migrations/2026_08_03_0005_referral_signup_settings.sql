-- CHORTKE MIGRATION: seed تنظیمات بونوس ثبت‌نام رفرال (هماهنگ با کلیدهای پنل ادمین)
INSERT INTO system_settings (`key`, `value`, `group`, `type`, `is_public`) VALUES
    ('referral_signup_bonus', '1000', 'referral', 'string', 0),
    ('referral_signup_bonus_usdt', '0', 'referral', 'string', 0)
ON DUPLICATE KEY UPDATE `value` = VALUES(`value`);
