<?php
/**
 * Feature Flags Configuration
 *
 * Registry of all feature flags in the application.
 * Each entry must be an array containing at least:
 *   - enabled: bool (default runtime state)
 *   - description: string (shown in the admin panel)
 *
 * DB overrides these values at runtime via the admin panel (/admin/features).
 * The api_key entry is NOT a feature flag and is intentionally excluded from the registry.
 */

return [
    // قرعه‌کشی
    'lottery' => [
        'enabled' => env('FEATURE_LOTTERY_ENABLED', false),
        'description' => 'فعال‌سازی سیستم قرعه‌کشی و بخت‌آزمایی برای کاربران.',
        'min_participants' => env('LOTTERY_MIN_PARTICIPANTS', 10),
        'max_participants' => env('LOTTERY_MAX_PARTICIPANTS', 1000),
        'entry_price' => env('LOTTERY_ENTRY_PRICE', 10000), // تومان
    ],

    // سرمایه‌گذاری
    'investment' => [
        'enabled' => env('FEATURE_INVESTMENT_ENABLED', false),
        'description' => 'فعال‌سازی سیستم سرمایه‌گذاری و صندوق سوددهی.',
        'min_amount' => env('INVESTMENT_MIN_AMOUNT', 100000),
        'max_amount' => env('INVESTMENT_MAX_AMOUNT', 10000000),
        'commission_rate' => env('INVESTMENT_COMMISSION_RATE', 10), // درصد
    ],

    // تسک‌ها
    'tasks' => [
        'enabled' => env('FEATURE_TASKS_ENABLED', true),
        'description' => 'فعال‌سازی سیستم تسک‌های شبکه‌های اجتماعی (Like، Follow، Comment).',
        'platforms' => [
            'instagram' => true,
            'telegram' => true,
            'youtube' => true,
            'twitter' => true,
            'tiktok' => false,
        ],
    ],

    // سیستم ارجاع
    'referral' => [
        'enabled' => env('FEATURE_REFERRAL_ENABLED', true),
        'description' => 'فعال‌سازی سیستم دعوت از دوستان و پاداش ارجاع.',
        'commission_rates' => [
            'tasks' => 10, // درصد
            'investment' => 5,
            'lottery' => 3,
            'content' => 5,
        ],
    ],

    // سیستم تخفیف و کوپن
    'coupons' => [
        'enabled' => env('FEATURE_COUPONS_ENABLED', false),
        'description' => 'فعال‌سازی سیستم کدهای تخفیف و کوپن‌های بازاریابی.',
    ],

    // OAuth security extensions
    'oauth_strict_ip_binding' => [
        'enabled' => env('FEATURE_OAUTH_STRICT_IP_BINDING_ENABLED', false),
        'description' => 'فعال‌سازی strict IP binding برای ورود اجتماعی (OAuth) در محیط‌های امنیتی خاص. این گزینه برای جلوگیری از تکمیل callback توسط IP متفاوت استفاده می‌شود.',
    ],

    // کیف پول رمزارز
    'crypto_wallet' => [
        'enabled' => env('FEATURE_CRYPTO_ENABLED', false),
        'description' => 'فعال‌سازی کیف پول و نگهداری موجودی رمزارز (USDT).',
        'networks' => [
            'bnb20' => true,
            'trc20' => true,
            'ton' => false,
            'sol' => false,
        ],
    ],

    // واریز رمزارز (مسیر wallet.php)
    'crypto_deposit' => [
        'enabled' => env('FEATURE_CRYPTO_DEPOSIT_ENABLED', false),
        'description' => 'فعال‌سازی قابلیت دریافت واریز رمزارز (crypto deposit) در مسیرهای کیف پول.',
    ],

    // ویترین / بازارچه کاربران (مسیر missing.php)
    'vitrine_enabled' => [
        'enabled' => env('FEATURE_VITRINE_ENABLED', true),
        'description' => 'فعال‌سازی ویترین و بازارچه کاربران (خرید/فروش بین کاربران).',
    ],

    // تنظیمات کارمزد سرمایه‌گذاری
    'investment_fees' => [
        'enabled' => env('FEATURE_INVESTMENT_FEES_ENABLED', true),
        'description' => 'فعال‌سازی سیستم کارمزد و مالیات محاسبه سود/ضرر سرمایه‌گذاری.',
        'site_fee_percent' => env('INVESTMENT_SITE_FEE_PERCENT', 2),
        'tax_percent' => env('INVESTMENT_TAX_PERCENT', 0),
        'fee_tiers' => [],
    ],

    // نسخه دوم محاسبه سرمایه‌گذاری
    'investment_v2_calculation' => [
        'enabled' => env('FEATURE_INVESTMENT_V2_CALCULATION_ENABLED', false),
        'description' => 'فعال‌سازی نسخه ۲ محاسبه سود/ضرر سرمایه‌گذاری (به‌صورت تدریجی per-user).',
    ],

    // ویژگی‌های آزمایشی (Beta)
    'beta' => [
        'enabled' => env('FEATURE_BETA_ENABLED', false),
        'description' => 'فعال‌سازی مجموعه‌ی فیچرهای آزمایشی (Beta): AI task verification، auto KYC، gamification.',
        'ai_task_verification' => false,
        'auto_kyc_verification' => false,
        'gamification' => false,
    ],

    // کلید امنیتی دسترسی به API سوئیچ‌ها
    // این یک مقدار configuration است، نه یک feature flag.
    'api_key' => env('FEATURE_FLAG_API_KEY', 'FF_SECURE_KEY_REQUIRED_MIN_32_CHARS'),

    // 🛡️ Phase 2 (N+1 Detection): فعال‌سازی tracking queryها برای Sentry
    // priority بالاتر از env (DB_TRACK_QUERIES) — می‌توان در runtime بدون deploy تغییر داد
    // استفاده در admin panel: feature_flags.db_query_tracking
    'db_query_tracking' => [
        'enabled' => env('DB_TRACK_QUERIES', false),
        'description' => 'ارسال همه queryهای دیتابیس به Sentry Performance Monitor برای تشخیص N+1 patterns. Production: false پیش‌فرض.',
        'sample_rate' => env('DB_TRACK_QUERIES_SAMPLE_RATE', 1.0),
    ],

    // 🛡️ Cache برای Analytics queries (استفاده در AnalyticsService)
    'analytics_cache_enabled' => [
        'enabled' => env('ANALYTICS_CACHE_ENABLED', true),
        'description' => 'فعال‌سازی cache نتایج aggregate در Redis (dashboard queries). کاهش 10x فشار روی DB.',
    ],
];
