-- CHORTKE MIGRATION: حذف مکانیزم dispute ناقصِ task_disputes
-- وظیفه‌ی اجتماعی مکانیزمِ چرخشِ خودکار است (یا انجام می‌شود یا رد) و بخش داوری اعتراض ندارد؛
-- لذا جدول task_disputes (که تنها open+list بود و resolve نداشت) حذف می‌شود.
DROP TABLE IF EXISTS `task_disputes`;
