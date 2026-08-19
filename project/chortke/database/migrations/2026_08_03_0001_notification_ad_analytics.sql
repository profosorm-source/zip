-- CHORTKE MIGRATION: تبلیغ نوتیفیکیشنی + آنالیتیکس دقیق
-- 1) ستون‌های engagement برای نوتیف‌های تبلیغاتی (مدت خواندن، نمایش، بستن، dismiss)
-- 2) جدول تجمیعی notification_analytics (مقصد کرون aggregation ادمین)

ALTER TABLE `notifications`
    ADD COLUMN IF NOT EXISTS `ad_id` INT(10) UNSIGNED NULL DEFAULT NULL COMMENT 'کمپین تبلیغاتی (ads.id) که این نوتیف را ارسال کرده',
    ADD COLUMN IF NOT EXISTS `campaign_id` INT(10) UNSIGNED NULL DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS `shown_at` DATETIME NULL DEFAULT NULL COMMENT 'زمان نمایش نوتیف روی گوشی',
    ADD COLUMN IF NOT EXISTS `opened_at` DATETIME NULL DEFAULT NULL COMMENT 'زمان باز کردن / شروع خواندن',
    ADD COLUMN IF NOT EXISTS `closed_at` DATETIME NULL DEFAULT NULL COMMENT 'زمان بسته شدن / خروج از نوتیف',
    ADD COLUMN IF NOT EXISTS `dismissed_at` DATETIME NULL DEFAULT NULL COMMENT 'بسته شدن بدون تعامل (swipe away)',
    ADD COLUMN IF NOT EXISTS `read_duration_sec` INT UNSIGNED NULL DEFAULT NULL COMMENT 'مدت حضور در نوتیف (opened_at تا closed_at)',
    ADD COLUMN IF NOT EXISTS `engagement_source` VARCHAR(30) NULL DEFAULT NULL COMMENT 'منبع رویداد: app/mobile/web',
    ADD INDEX IF NOT EXISTS `idx_notif_ad_eng` (`ad_id`, `is_read`),
    ADD INDEX IF NOT EXISTS `idx_notif_created` (`created_at`);

-- جدول تجمیعی کرون ساعتیِ aggregation ادمین
CREATE TABLE IF NOT EXISTS `notification_analytics` (
    `date` DATE NOT NULL,
    `type` VARCHAR(50) NOT NULL,
    `channel` VARCHAR(50) NOT NULL DEFAULT 'in_app',
    `sent` INT(10) UNSIGNED NOT NULL DEFAULT 0,
    `read_count` INT(10) UNSIGNED NOT NULL DEFAULT 0,
    `click_count` INT(10) UNSIGNED NOT NULL DEFAULT 0,
    `unique_users` INT(10) UNSIGNED NOT NULL DEFAULT 0,
    `ad_sent` INT(10) UNSIGNED NOT NULL DEFAULT 0,
    `ad_read` INT(10) UNSIGNED NOT NULL DEFAULT 0,
    `ad_click` INT(10) UNSIGNED NOT NULL DEFAULT 0,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`date`, `type`, `channel`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
