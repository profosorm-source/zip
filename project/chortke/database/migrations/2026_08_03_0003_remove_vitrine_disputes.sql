-- CHORTKE MIGRATION: حذف جدول موازی و مرده‌ی vitrine_disputes
-- ویترین حالا در جدول یکپارچه‌ی disputes (ref_type = 'vitrine_listing') ثبت می‌شود؛
-- vitrine_disputes فقط SELECT داشت و هیچ INSERT/مصرفی نداشت.
DROP TABLE IF EXISTS `vitrine_disputes`;
