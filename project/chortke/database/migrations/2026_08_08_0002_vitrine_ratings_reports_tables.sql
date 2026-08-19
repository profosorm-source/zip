-- CHORTKE MIGRATION: ساخت جداول مفقودِ vitrine_ratings و vitrine_reports
--
-- ریشه‌ی باگ: Jobهای RateVitrineListingJob و ReportVitrineListingJob مستقیماً در این
-- دو جدول INSERT می‌کنند و مدل VitrineRating نیز به آن‌ها تکیه دارد، اما هیچ مایگریشنی
-- این جداول را نمی‌ساخت؛ یعنی روی یک استقرارِ تازه، امتیازدهی/گزارشِ ویترین در زمان اجرا
-- با خطای «جدول وجود ندارد» می‌شکست.
--
-- ضمناً قید UNIQUE روی (user_id, listing_id) در vitrine_ratings، شرطِ منطقیِ «هر کاربر فقط
-- یک امتیاز به هر آگهی» را در سطح دیتابیس تضمین می‌کند و مسابقه‌ی TOCTOU (چک سپس درج) را
-- که در RateVitrineListingJob وجود داشت به‌صورت ریشه‌ای می‌بندد.

CREATE TABLE IF NOT EXISTS `vitrine_ratings` (
    `id` INT(10) UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `user_id` INT(10) UNSIGNED NOT NULL,
    `listing_id` INT(10) UNSIGNED NOT NULL,
    `seller_id` INT(10) UNSIGNED NOT NULL,
    `stars` TINYINT(3) UNSIGNED NOT NULL,
    `comment` TEXT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY `uq_vitrine_rating_user_listing` (`user_id`, `listing_id`),
    KEY `idx_vitrine_rating_seller` (`seller_id`),
    KEY `idx_vitrine_rating_listing` (`listing_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `vitrine_reports` (
    `id` INT(10) UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `reporter_id` INT(10) UNSIGNED NOT NULL,
    `listing_id` INT(10) UNSIGNED NOT NULL,
    `seller_id` INT(10) UNSIGNED NOT NULL,
    `reason` VARCHAR(50) NOT NULL,
    `description` TEXT NULL,
    `status` VARCHAR(20) NOT NULL DEFAULT 'pending',
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    KEY `idx_vitrine_report_listing` (`listing_id`),
    KEY `idx_vitrine_report_reporter` (`reporter_id`),
    KEY `idx_vitrine_report_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
