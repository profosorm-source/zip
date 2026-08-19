<?php

declare(strict_types=1);

/**
 * نقشهٔ متمرکز مجوزهای پنل مدیریت (RBAC — deny-by-default)
 * -------------------------------------------------------------------
 * منبعِ حقیقتِ واحد برای «هر Controller@action ادمین چه permissionی لازم دارد».
 * توسط App\Middleware\AdminPermissionGuard خوانده می‌شود که روی کل گروه $admin
 * در routes/admin.php اجرا می‌شود.
 *
 * قواعد:
 *  - کلیدها = نامِ کوتاهِ کلاسِ کنترلر (short class name)، نه FQN، تا مستقل از
 *    aliasهای use در routes/admin.php باشد.
 *  - برای هر کنترلر یک 'default' (کمترین سطح = مشاهده) و overrideهای per-method
 *    برای اکشن‌های نویسنده/حساس تعریف می‌شود.
 *  - هر مسیرِ ادمینی که کنترلر/اکشنش اینجا نگاشت نشده باشد، برای کاربرِ غیر
 *    super_admin به‌صورت پیش‌فرض «رد» می‌شود (fail-closed) و در لاگ ثبت می‌گردد.
 *  - super_admin در خودِ گارد/PolicyService بایپس می‌شود و به این نقشه وابسته نیست.
 *
 * گروه‌بندی slugها با ستون group_name جدول permissions هم‌تراز است
 * (به migration مربوطه: 2026_08_08_0001_rbac_full_permissions.sql مراجعه شود).
 */

return [
    // ── تبلیغات (داشبورد یکپارچه) ─────────────────────────────────────
    'AdminAdsController' => [
        'default' => 'ads.view',
        'methods' => [
            'bulkAction' => 'ads.manage',
            'action'     => 'ads.manage',
        ],
    ],

    // ── داشبورد ───────────────────────────────────────────────────────
    'DashboardController' => [
        'default' => 'dashboard.view',
    ],

    // ── کاربران ────────────────────────────────────────────────────────
    'UserController' => [
        'default' => 'user.manage.view',
        'methods' => [
            'create'    => 'user.manage.edit',
            'store'     => 'user.manage.edit',
            'edit'      => 'user.manage.edit',
            'update'    => 'user.manage.edit',
            'ban'       => 'user.manage.ban',
            'unban'     => 'user.manage.ban',
            'suspend'   => 'user.manage.ban',
            'unsuspend' => 'user.manage.ban',
            'verifyEmail'             => 'user.manage.verify_email',
            'resendVerificationEmail' => 'user.manage.verify_email',
        ],
    ],

    // ── احراز هویت (KYC) ───────────────────────────────────────────────
    'KYCController' => [
        'default' => 'kyc.view',
        'methods' => [
            'verify'          => 'kyc.manage',
            'reject'          => 'kyc.manage',
            'markAsReviewing' => 'kyc.manage',
            'deleteImage'     => 'kyc.manage',
        ],
    ],

    // ── لاگ‌ها ──────────────────────────────────────────────────────────
    'LogController' => [
        'default' => 'logs.view',
        'methods' => [
            'cleanup'      => 'logs.manage',
            'resolveError' => 'logs.manage',
            'saveChannel'  => 'logs.manage',
            'testChannel'  => 'logs.manage',
        ],
    ],

    // ── اعلان‌ها ─────────────────────────────────────────────────────────
    'NotificationController' => [
        'default' => 'notifications.view',
        'methods' => [
            'showSend'       => 'notifications.send',
            'send'           => 'notifications.send',
            'saveTemplate'   => 'notifications.manage',
            'deleteTemplate' => 'notifications.manage',
            'markAsRead'     => 'notifications.manage',
            'markAllAsRead'  => 'notifications.manage',
        ],
    ],

    // ── مالی: کارت بانکی ────────────────────────────────────────────────
    'BankCardController' => [
        'default' => 'finance.card.view',
        'methods' => [
            'verify' => 'finance.card.approve',
            'reject' => 'finance.card.approve',
        ],
    ],

    // ── مالی: واریز دستی ────────────────────────────────────────────────
    'ManualDepositController' => [
        'default' => 'finance.deposit.view',
        'methods' => [
            'verify' => 'finance.deposit.approve',
            'reject' => 'finance.deposit.approve',
        ],
    ],

    // ── مالی: واریز کریپتو ──────────────────────────────────────────────
    'CryptoDepositController' => [
        'default' => 'finance.crypto.view',
        'methods' => [
            'verify' => 'finance.crypto.approve',
            'reject' => 'finance.crypto.approve',
        ],
    ],

    // ── مالی: برداشت ────────────────────────────────────────────────────
    'WithdrawalController' => [
        'default' => 'finance.withdrawal.view',
        'methods' => [
            'process' => 'finance.withdrawal.approve',
            'reject'  => 'finance.withdrawal.approve',
        ],
    ],

    // ── مالی: تراکنش‌ها ─────────────────────────────────────────────────
    'TransactionController' => [
        'default' => 'finance.transaction.view',
        'methods' => [
            'reverse' => 'finance.transaction.approve',
        ],
    ],

    // ── مالی: پرداخت درگاه ──────────────────────────────────────────────
    'OnlinePaymentController' => [
        'default' => 'finance.transaction.view',
        'methods' => [
            'verify' => 'finance.transaction.approve',
        ],
    ],

    // ── حساب‌های اجتماعی ────────────────────────────────────────────────
    'SocialAccountController' => [
        'default' => 'social_accounts.view',
        'methods' => [
            'verify' => 'social_accounts.manage',
            'reject' => 'social_accounts.manage',
        ],
    ],

    // ── وظایف سفارشی (تعریف/نمایش) ──────────────────────────────────────
    'AdTaskController' => [
        'default' => 'custom_tasks.view',
        'methods' => [
            'approve' => 'custom_tasks.manage',
        ],
    ],

    // ── وظایف سفارشی (مجری/داوری) ───────────────────────────────────────
    'ExecutorTaskController' => [
        'default' => 'custom_tasks.view',
        'methods' => [
            'replyDispute'          => 'custom_tasks.manage',
            'forceApproveSubmission'=> 'custom_tasks.manage',
            'forceRejectSubmission' => 'custom_tasks.manage',
            'resolveDispute'        => 'custom_tasks.manage',
        ],
    ],

    // ── نقش‌ها ───────────────────────────────────────────────────────────
    'RoleController' => [
        'default' => 'roles.view',
        'methods' => [
            'create' => 'roles.manage',
            'store'  => 'roles.manage',
            'edit'   => 'roles.manage',
            'update' => 'roles.manage',
            'delete' => 'roles.manage',
            'toggle' => 'roles.manage',
        ],
    ],

    // ── معرف‌ها (رفرال) ─────────────────────────────────────────────────
    'ReferralController' => [
        'default' => 'referrals.view',
        'methods' => [
            'settings'     => 'referrals.manage',
            'saveSettings' => 'referrals.manage',
            'cancel'       => 'referrals.manage',
            'batchPay'     => 'referrals.manage',
        ],
    ],

    // ── سطوح ──────────────────────────────────────────────────────────────
    'LevelController' => [
        'default' => 'settings.view',
        'methods' => [
            'create'          => 'settings.edit',
            'store'           => 'settings.edit',
            'edit'            => 'settings.edit',
            'update'          => 'settings.edit',
            'destroy'         => 'settings.edit',
            'changeUserLevel' => 'users.edit',
        ],
    ],

    // ── اینفلوئنسر ──────────────────────────────────────────────────────
    'InfluencerController' => [
        'default' => 'influencer.manage',
        'methods' => [
            'orders' => 'influencer.view',
        ],
    ],

    // ── محتوا ─────────────────────────────────────────────────────────────
    'ContentController' => [
        'default' => 'content.view',
        'methods' => [
            'bulkApprove'    => 'content.manage',
            'bulkReject'     => 'content.manage',
            'approve'        => 'content.manage',
            'reject'         => 'content.manage',
            'publish'        => 'content.manage',
            'suspend'        => 'content.manage',
            'createRevenue'  => 'content.manage',
            'storeRevenue'   => 'content.manage',
            'revenueApprove' => 'content.manage',
            'revenuePay'     => 'content.manage',
        ],
    ],

    // ── سرمایه‌گذاری ────────────────────────────────────────────────────
    'InvestmentController' => [
        'default' => 'investment.view',
        'methods' => [
            'tradeCreate'       => 'investment.manage',
            'tradeStore'        => 'investment.manage',
            'tradeClose'        => 'investment.manage',
            'applyProfitForm'   => 'investment.manage',
            'applyProfit'       => 'investment.manage',
            'withdrawalApprove' => 'investment.manage',
            'withdrawalReject'  => 'investment.manage',
            'suspend'           => 'investment.manage',
        ],
    ],

    // ── قرعه‌کشی ─────────────────────────────────────────────────────────
    'LotteryController' => [
        'default' => 'lottery.view',
        'methods' => [
            'create'          => 'lottery.manage',
            'store'           => 'lottery.manage',
            'generateNumbers' => 'lottery.manage',
            'finalizeDaily'   => 'lottery.manage',
            'selectWinner'    => 'lottery.manage',
            'cancel'          => 'lottery.manage',
        ],
    ],

    // ── بنرها ─────────────────────────────────────────────────────────────
    'BannerController' => [
        'default' => 'banners.view',
        'methods' => [
            'togglePlacement' => 'banners.manage',
            'updatePlacement' => 'banners.manage',
            'store'           => 'banners.manage',
            'update'          => 'banners.manage',
            'approve'         => 'banners.manage',
            'reject'          => 'banners.manage',
            'delete'          => 'banners.manage',
            'toggle'          => 'banners.manage',
        ],
    ],

    // ── تبلیغات SEO ─────────────────────────────────────────────────────
    'SeoAdController' => [
        'default' => 'seo.view',
        'methods' => [
            'approve' => 'seo.manage',
            'reject'  => 'seo.manage',
            'pause'   => 'seo.manage',
        ],
    ],

    // ── گزارش‌های باگ ───────────────────────────────────────────────────
    'BugReportController' => [
        'default' => 'bug_reports.view',
        'methods' => [
            'updateStatus'     => 'bug_reports.update_status',
            'updatePriority'   => 'bug_reports.update_priority',
            'addComment'       => 'bug_reports.manage',
            'toggleSuspicious' => 'bug_reports.manage',
            'delete'           => 'bug_reports.manage',
        ],
    ],

    // ── KPI ───────────────────────────────────────────────────────────────
    'KpiController' => [
        'default' => 'kpi.view',
        'methods' => [
            'exportUsers'        => 'kpi.export',
            'exportTransactions' => 'kpi.export',
            'exportSummary'      => 'kpi.export',
        ],
    ],

    // ── تنظیمات سیستم ───────────────────────────────────────────────────
    'SystemSettingController' => [
        'default' => 'admin.view_settings',
        'methods' => [
            'update'      => 'admin.edit_settings',
            'uploadImage' => 'admin.edit_settings',
            'removeImage' => 'admin.edit_settings',
        ],
    ],

    // ── تیکت‌ها ──────────────────────────────────────────────────────────
    'TicketController' => [
        'default' => 'tickets.view_all',
        'methods' => [
            'reply'        => 'tickets.reply',
            'changeStatus' => 'tickets.manage',
            'assign'       => 'tickets.assign',
        ],
    ],

    // ── کوپن‌ها ──────────────────────────────────────────────────────────
    'CouponController' => [
        'default' => 'coupons.view',
        'methods' => [
            'create'       => 'coupons.manage',
            'store'        => 'coupons.manage',
            'delete'       => 'coupons.manage',
            'toggleActive' => 'coupons.manage',
            'update'       => 'coupons.manage',
        ],
    ],

    // ── ضدتقلب: داشبورد ─────────────────────────────────────────────────
    'FraudDashboardController' => [
        'default' => 'fraud.view',
    ],

    // ── ضدتقلب: مدیریت لیست سیاه ────────────────────────────────────────
    'FraudManagementController' => [
        'default' => 'fraud.view',
        'methods' => [
            'blockIP'         => 'fraud.manage',
            'unblockIP'       => 'fraud.manage',
            'blockDevice'     => 'fraud.manage',
            'unblockDevice'   => 'fraud.manage',
            'resetFraudScore' => 'fraud.manage',
        ],
    ],

    // ── ضدتقلب: امتیاز ریسک ─────────────────────────────────────────────
    'FraudController' => [
        'default' => 'fraud.view',
        'methods' => [
            'recalculateScore' => 'fraud.manage',
            'executeActions'   => 'fraud.manage',
            'clearFlags'       => 'fraud.manage',
            'suspendUser'      => 'fraud.manage',
            'unsuspendUser'    => 'fraud.manage',
        ],
    ],

    // ── فیچرفلگ‌ها ───────────────────────────────────────────────────────
    'FeatureFlagController' => [
        'default' => 'features.view',
        'methods' => [
            'toggle'         => 'features.manage',
            'create'         => 'features.manage',
            'update'         => 'features.manage',
            'advancedUpdate' => 'features.manage',
            'delete'         => 'features.manage',
        ],
    ],

    // ── ردگیری حسابرسی ──────────────────────────────────────────────────
    'AuditTrailController' => [
        'default' => 'audit.view',
        'methods' => [
            'export' => 'audit.export',
        ],
    ],

    // ── خروجی‌گیری ───────────────────────────────────────────────────────
    'AdminExportController' => [
        'default' => 'export.view',
        'methods' => [
            'users'        => 'admin.export.users',
            'transactions' => 'admin.export.transactions',
            'withdrawals'  => 'admin.export.withdrawals',
            'auditTrail'   => 'admin.export.audit',
        ],
    ],

    // ── حذف حساب ─────────────────────────────────────────────────────────
    'AccountDeletionManagementController' => [
        'default' => 'account_deletion.view',
        'methods' => [
            'forceDelete'    => 'account_deletion.manage',
            'cancelDeletion' => 'account_deletion.manage',
        ],
    ],

    // ── پشتیبان‌گیری ─────────────────────────────────────────────────────
    'BackupManagementController' => [
        'default' => 'backups.view',
        'methods' => [
            'createBackup'  => 'backups.manage',
            'restoreBackup' => 'backups.manage',
            'verifyBackup'  => 'backups.manage',
            'cleanup'       => 'backups.manage',
        ],
    ],

    // ── سلامت پایگاه‌داده ───────────────────────────────────────────────
    'DatabaseHealthController' => [
        'default' => 'database.view',
    ],

    // ── جستجوی ادمین ─────────────────────────────────────────────────────
    'SearchController' => [
        'default' => 'search.admin',
    ],

    // ── سیاست‌های ریسک ──────────────────────────────────────────────────
    'RiskPolicyController' => [
        'default' => 'risk_policies.view',
        'methods' => [
            'update' => 'risk_policies.manage',
        ],
    ],

    // ── مدیریت امتیاز کاربران ───────────────────────────────────────────
    'ScoreManagementController' => [
        'default' => 'scores.view',
        'methods' => [
            'adjustScore'      => 'scores.manage',
            'revokeAdjustment' => 'scores.manage',
        ],
    ],

    // ── Sentry / پایش خطا ───────────────────────────────────────────────
    'SentryAdminController' => [
        'default' => 'sentry.view',
        'methods' => [
            'resolveIssue'     => 'sentry.manage',
            'muteIssue'        => 'sentry.manage',
            'retryFailedJob'   => 'sentry.manage',
            'forgetFailedJob'  => 'sentry.manage',
            'acknowledgeAlert' => 'sentry.manage',
            'exportAudit'      => 'sentry.manage',
            'generateReport'   => 'sentry.manage',
        ],
    ],

    // ── آنالیتیکس ────────────────────────────────────────────────────────
    'AdminAnalyticsController' => [
        'default' => 'analytics.view',
        'methods' => [
            'exportReport' => 'analytics.export',
        ],
    ],

    // ── وظایف اجتماعی (Social Task) ─────────────────────────────────────
    'SocialTaskController' => [
        'default' => 'social_tasks.view',
        'methods' => [
            'approve'          => 'social_tasks.manage',
            'reject'           => 'social_tasks.manage',
            'pause'            => 'social_tasks.manage',
            'resume'           => 'social_tasks.manage',
            'cancel'           => 'social_tasks.manage',
            'flagExecution'    => 'social_tasks.manage',
            'overrideDecision' => 'social_tasks.manage',
            'moderateRating'   => 'social_tasks.manage',
            'adjustTrust'      => 'social_tasks.manage',
        ],
    ],

    // ── نظارت بر پیام‌ها ────────────────────────────────────────────────
    'MessageModerationController' => [
        'default' => 'messages.moderate',
    ],
];
