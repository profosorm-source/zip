-- CHORTKE MIGRATION: Seed feature_flags registry from config defaults
-- BUGFIX-FEATURE-FLAGS-DB-SEED-2026-06
--
-- The feature_flags table is the runtime source of truth for the admin panel
-- and the RequireFeature middleware. On a fresh install the table is empty,
-- which causes /admin/features to show almost nothing and makes route-only
-- features such as crypto_deposit and vitrine_enabled permanently return 503.
-- This migration seeds the table with every feature flag declared in the
-- application config. Initial values are driven by the project .env defaults
-- so that a fresh install behaves exactly like the static configuration.
--
-- Dead flags (story_promotion, content_monetization) removed.
-- Missing flags (investment_fees, investment_v2_calculation) added.

SET FOREIGN_KEY_CHECKS = 0;
SET NAMES utf8mb4;

INSERT IGNORE INTO `feature_flags`
    (`name`, `description`, `enabled`, `enabled_percentage`, `priority`, `metadata`, `updated_at`)
VALUES
    ('lottery', 'فعال‌سازی سیستم قرعه‌کشی و بخت‌آزمایی برای کاربران.', 1, 100, 0, NULL, NOW()),
    ('investment', 'فعال‌سازی سیستم سرمایه‌گذاری و صندوق سوددهی.', 1, 100, 0, NULL, NOW()),
    ('tasks', 'فعال‌سازی سیستم تسک‌های شبکه‌های اجتماعی (Like، Follow، Comment).', 1, 100, 0, NULL, NOW()),
    ('referral', 'فعال‌سازی سیستم دعوت از دوستان و پاداش ارجاع.', 1, 100, 0, NULL, NOW()),
    ('coupons', 'فعال‌سازی سیستم کدهای تخفیف و کوپن‌های بازاریابی.', 0, 100, 0, NULL, NOW()),
    ('oauth_strict_ip_binding', 'فعال‌سازی strict IP binding برای ورود اجتماعی (OAuth) در محیط‌های امنیتی خاص.', 0, 100, 0, NULL, NOW()),
    ('crypto_wallet', 'فعال‌سازی کیف پول و نگهداری موجودی رمزارز (USDT).', 0, 100, 0, NULL, NOW()),
    ('crypto_deposit', 'فعال‌سازی قابلیت دریافت واریز رمزارز (crypto deposit) در مسیرهای کیف پول.', 0, 100, 0, NULL, NOW()),
    ('vitrine_enabled', 'فعال‌سازی ویترین و بازارچه کاربران (خرید/فروش بین کاربران).', 1, 100, 0, NULL, NOW()),
    ('investment_fees', 'فعال‌سازی سیستم کارمزد و مالیات محاسبه سود/ضرر سرمایه‌گذاری.', 1, 100, 0, NULL, NOW()),
    ('investment_v2_calculation', 'فعال‌سازی نسخه ۲ محاسبه سود/ضرر سرمایه‌گذاری (به‌صورت تدریجی per-user).', 0, 100, 0, NULL, NOW()),
    ('beta', 'فعال‌سازی مجموعه‌ی فیچرهای آزمایشی (Beta): AI task verification، auto KYC، gamification.', 0, 100, 0, NULL, NOW());

SET FOREIGN_KEY_CHECKS = 1;
