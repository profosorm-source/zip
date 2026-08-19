-- =====================================================================
-- RBAC Full Permissions — M-10 / M-12 پشتیبانِ گاردِ متمرکزِ پنل مدیریت
-- ---------------------------------------------------------------------
-- این مهاجرت:
--   ۱) تمامی slugهای مجوزِ پنل (هم‌تراز با config/admin_permissions.php) را idempotent درج می‌کند.
--   ۲) تمامی مجوزها را به نقش‌های admin و super_admin اعطا می‌کند (★ ضدِ قفل‌شدن:
--      رفتارِ فعلی که admin به همه‌چیز دسترسی دارد حفظ می‌شود؛ محدودسازیِ بعدی از طریق
--      ویرایش role_permissions در پنل نقش‌ها انجام می‌شود).
--   ۳) یک زیرمجموعهٔ منطقی را به نقش support اعطا می‌کند.
--   ۴) ★ CRITICAL: user_roles را از روی ستون users.role بک‌فیل می‌کند؛ زیرا User::hasPermission
--      از جدول user_roles join می‌گیرد. اگر ادمینی فقط users.role='admin' داشته ولی ردیف
--      user_roles نداشته باشد، پس از فعال‌شدنِ enforcement قفل می‌شد.
--
-- همهٔ عبارات idempotent هستند (ON DUPLICATE KEY UPDATE / INSERT IGNORE).
-- =====================================================================

-- ① درج/به‌روزرسانی تمامی slugهای مجوزِ پنل مدیریت
INSERT INTO permissions (`name`, `slug`, `group_name`, `description`, `created_at`, `updated_at`) VALUES
('تبلیغات — مشاهده', 'ads.view', 'ads', 'View ads dashboard', NOW(), NOW()),
('تبلیغات — مدیریت', 'ads.manage', 'ads', 'Manage/bulk actions on ads', NOW(), NOW()),
('داشبورد — مشاهده', 'dashboard.view', 'dashboard', 'View admin dashboard', NOW(), NOW()),
('احراز هویت — مشاهده', 'kyc.view', 'kyc', 'View KYC requests', NOW(), NOW()),
('احراز هویت — مدیریت', 'kyc.manage', 'kyc', 'Verify/reject KYC', NOW(), NOW()),
('لاگ‌ها — مشاهده', 'logs.view', 'logs', 'View system/audit/security logs', NOW(), NOW()),
('لاگ‌ها — مدیریت', 'logs.manage', 'logs', 'Manage/cleanup logs & channels', NOW(), NOW()),
('اعلان‌ها — مشاهده', 'notifications.view', 'notifications', 'View notifications & templates', NOW(), NOW()),
('اعلان‌ها — ارسال', 'notifications.send', 'notifications', 'Send notifications', NOW(), NOW()),
('اعلان‌ها — مدیریت', 'notifications.manage', 'notifications', 'Manage templates & read state', NOW(), NOW()),
('حساب‌های اجتماعی — مشاهده', 'social_accounts.view', 'social_accounts', 'View social accounts', NOW(), NOW()),
('حساب‌های اجتماعی — مدیریت', 'social_accounts.manage', 'social_accounts', 'Verify/reject social accounts', NOW(), NOW()),
('وظایف سفارشی — مشاهده', 'custom_tasks.view', 'custom_tasks', 'View custom tasks & disputes', NOW(), NOW()),
('وظایف سفارشی — مدیریت', 'custom_tasks.manage', 'custom_tasks', 'Approve/resolve custom tasks', NOW(), NOW()),
('محتوا — مشاهده', 'content.view', 'content', 'View content & revenues', NOW(), NOW()),
('محتوا — مدیریت', 'content.manage', 'content', 'Approve/publish/revenue content', NOW(), NOW()),
('سرمایه‌گذاری — مشاهده', 'investment.view', 'investment', 'View investments & trades', NOW(), NOW()),
('سرمایه‌گذاری — مدیریت', 'investment.manage', 'investment', 'Manage trades/profit/withdrawals', NOW(), NOW()),
('قرعه‌کشی — مشاهده', 'lottery.view', 'lottery', 'View lotteries', NOW(), NOW()),
('قرعه‌کشی — مدیریت', 'lottery.manage', 'lottery', 'Create/finalize lotteries', NOW(), NOW()),
('بنرها — مشاهده', 'banners.view', 'banners', 'View banners & placements', NOW(), NOW()),
('بنرها — مدیریت', 'banners.manage', 'banners', 'Manage banners & placements', NOW(), NOW()),
('تبلیغات SEO — مشاهده', 'seo.view', 'seo', 'View SEO ads', NOW(), NOW()),
('تبلیغات SEO — مدیریت', 'seo.manage', 'seo', 'Approve/reject/pause SEO ads', NOW(), NOW()),
('گزارش باگ — مدیریت', 'bug_reports.manage', 'bug_reports', 'Comment/suspicious/delete bug reports', NOW(), NOW()),
('KPI — مشاهده', 'kpi.view', 'kpi', 'View KPI dashboards', NOW(), NOW()),
('KPI — خروجی', 'kpi.export', 'kpi', 'Export KPI data', NOW(), NOW()),
('تیکت — پاسخ', 'tickets.reply', 'tickets', 'Reply to tickets', NOW(), NOW()),
('تیکت — مدیریت', 'tickets.manage', 'tickets', 'Change ticket status', NOW(), NOW()),
('کوپن‌ها — مشاهده', 'coupons.view', 'coupons', 'View coupons & redemptions', NOW(), NOW()),
('کوپن‌ها — مدیریت', 'coupons.manage', 'coupons', 'Create/update/delete coupons', NOW(), NOW()),
('ضدتقلب — مشاهده', 'fraud.view', 'fraud', 'View fraud dashboards & logs', NOW(), NOW()),
('ضدتقلب — مدیریت', 'fraud.manage', 'fraud', 'Block/unblock/suspend & recompute', NOW(), NOW()),
('فیچرفلگ — مشاهده', 'features.view', 'features', 'View feature flags', NOW(), NOW()),
('فیچرفلگ — مدیریت', 'features.manage', 'features', 'Toggle/update feature flags', NOW(), NOW()),
('حسابرسی — مشاهده', 'audit.view', 'audit', 'View audit trail', NOW(), NOW()),
('حسابرسی — خروجی', 'audit.export', 'audit', 'Export audit trail', NOW(), NOW()),
('خروجی‌گیری — مشاهده', 'export.view', 'export', 'View export center', NOW(), NOW()),
('حذف حساب — مشاهده', 'account_deletion.view', 'account_deletion', 'View account deletion requests', NOW(), NOW()),
('حذف حساب — مدیریت', 'account_deletion.manage', 'account_deletion', 'Force/cancel account deletion', NOW(), NOW()),
('پشتیبان‌گیری — مشاهده', 'backups.view', 'backups', 'View backups', NOW(), NOW()),
('پشتیبان‌گیری — مدیریت', 'backups.manage', 'backups', 'Create/restore/verify backups', NOW(), NOW()),
('سلامت پایگاه‌داده — مشاهده', 'database.view', 'database', 'View database health', NOW(), NOW()),
('جستجوی ادمین', 'search.admin', 'search', 'Use admin search', NOW(), NOW()),
('سیاست ریسک — مشاهده', 'risk_policies.view', 'risk_policies', 'View risk policies', NOW(), NOW()),
('سیاست ریسک — مدیریت', 'risk_policies.manage', 'risk_policies', 'Update risk policies', NOW(), NOW()),
('امتیاز کاربر — مشاهده', 'scores.view', 'scores', 'View user scores', NOW(), NOW()),
('امتیاز کاربر — مدیریت', 'scores.manage', 'scores', 'Adjust/revoke user scores', NOW(), NOW()),
('Sentry — مشاهده', 'sentry.view', 'sentry', 'View Sentry issues & jobs', NOW(), NOW()),
('Sentry — مدیریت', 'sentry.manage', 'sentry', 'Resolve/retry/report Sentry', NOW(), NOW()),
('آنالیتیکس — مشاهده', 'analytics.view', 'analytics', 'View analytics', NOW(), NOW()),
('آنالیتیکس — خروجی', 'analytics.export', 'analytics', 'Export analytics report', NOW(), NOW()),
('وظایف اجتماعی — مشاهده', 'social_tasks.view', 'social_tasks', 'View social tasks & executions', NOW(), NOW()),
('وظایف اجتماعی — مدیریت', 'social_tasks.manage', 'social_tasks', 'Approve/moderate social tasks', NOW(), NOW()),
('کاربران — تأیید/ارسال ایمیل', 'user.manage.verify_email', 'users', 'Manually verify or resend user email verification', NOW(), NOW())
ON DUPLICATE KEY UPDATE
  `name` = VALUES(`name`), `group_name` = VALUES(`group_name`), `description` = VALUES(`description`), `updated_at` = NOW();

-- ② ★ ضدِ قفل‌شدن: اعطای همهٔ مجوزها به admin و super_admin
--   (رفتارِ فعلی حفظ می‌شود؛ محدودسازی بعداً از پنل نقش‌ها قابل اعمال است)
INSERT IGNORE INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id
FROM roles r
CROSS JOIN permissions p
WHERE r.slug IN ('admin', 'super_admin');

-- ③ زیرمجموعهٔ منطقی برای نقش support
INSERT IGNORE INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id
FROM roles r
JOIN permissions p ON p.slug IN (
  -- ▼ مشاهدهٔ همهٔ صفحات پنل (طبق درخواست کاربر: support همه را می‌بیند)
  'dashboard.view', 'user.manage.view', 'kyc.view', 'logs.view', 'notifications.view',
  'social_accounts.view', 'social_tasks.view', 'custom_tasks.view', 'content.view',
  'investment.view', 'lottery.view', 'banners.view', 'seo.view', 'kpi.view',
  'coupons.view', 'fraud.view', 'features.view', 'audit.view', 'export.view',
  'account_deletion.view', 'backups.view', 'database.view', 'search.admin',
  'risk_policies.view', 'scores.view', 'sentry.view', 'analytics.view',
  'referrals.view', 'roles.view', 'settings.view', 'admin.view_settings',
  'ads.view', 'bug_reports.view', 'tickets.view_all', 'messages.moderate',
  'influencer.manage',
  'finance.card.view', 'finance.crypto.view', 'finance.deposit.view',
  'finance.transaction.view', 'finance.withdrawal.view',
  -- ▼ عملیاتِ مجاز برای support (طبق انتخاب کاربر: بن/ویرایش/تأیید ایمیل/تیکت/مودریشن)
  'user.manage.ban', 'user.manage.edit', 'user.manage.verify_email',
  'tickets.reply', 'tickets.assign', 'tickets.manage',
  'bug_reports.update_status', 'bug_reports.update_priority', 'bug_reports.manage'
)
WHERE r.slug = 'support';

-- ④ ★ CRITICAL — backfill user_roles از روی users.role
--   بدون این، ادمین‌هایی که فقط users.role دارند (و ردیف user_roles ندارند)
--   پس از فعال‌شدن enforcement قفل می‌شدند (User::hasPermission از user_roles join می‌گیرد).
INSERT IGNORE INTO user_roles (user_id, role_id)
SELECT u.id, r.id
FROM users u
JOIN roles r ON r.slug = u.role
WHERE u.role IN ('admin', 'super_admin', 'support');
