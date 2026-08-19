<?php

declare(strict_types=1);

namespace App\Controllers\Api;

use App\Services\FeatureFlagService;

/**
 * AppConfigController — نقطه ورود یکپارچه پیکربندی اپلیکیشن موبایل
 *
 * GET /api/v1/config
 * مدیریت وضعیت نگهداری، الزام آپدیت اجباری (Force Update) و متغیرهای کلیدی پلتفرم
 */
class AppConfigController extends BaseApiController
{
    private FeatureFlagService $featureService;

    public function __construct(FeatureFlagService $featureService, ?\App\Contracts\LoggerInterface $logger = null) {
        parent::__construct(null, null, null, null, $logger);
        $this->featureService = $featureService;
    }

    /**
     * ارائه اطلاعات یکپارچه پیکربندی اپلیکیشن موبایل
     */
    public function config(): void
    {
        // 1. بررسی هدر نسخه کلاینت موبایل
        $clientVersion = str_value($this->request->header('X-App-Version')
            ?? $this->request->get('app_version')
            ?? '1.0.0');

        $minAppVersion = str_value(config('app.mobile.min_version', '1.0.0'));
        $latestAppVersion = str_value(config('app.mobile.latest_version', '1.2.0'));

        // محاسبه دینامیک الزام آپدیت اجباری (Force Update)
        $forceUpdate = version_compare($clientVersion, $minAppVersion, '<');

        // 2. وضعیت تعمیرات و نگهداری سیستم
        $maintenanceEnabled = (bool)config('maintenance.enabled', false);
        $maintenanceMessage = str_value(config('maintenance.message', 'سیستم در حال به‌روزرسانی و تعمیرات زیرساختی است. لطفاً شکیبا باشید.'));

        // 3. استخراج پرچم‌های ویژگی فعال (Feature Flags)
        $featuresList = [];
        try {
            $allFeatures = $this->featureService->getAll();
            foreach ($allFeatures as $f) {
                $featuresList[$f->name] = (bool)$f->enabled;
            }
        } catch (\Throwable $e) {
            $this->logger->warning('app_config.features_fetch_failed', ['error' => $e->getMessage()]);
        }

        // 4. تدوین پکیج یکپارچه پیکربندی
        $payload = [
            'app_versioning' => [
                'client_version' => $clientVersion,
                'min_app_version' => $minAppVersion,
                'latest_app_version' => $latestAppVersion,
                'force_update' => $forceUpdate,
                'update_url' => str_value(config('app.mobile.update_url', 'https://chortke.com/app/download')),
            ],
            'maintenance' => [
                'enabled' => $maintenanceEnabled,
                'message' => $maintenanceMessage,
            ],
            'features' => $featuresList,
            'settings' => [
                'locales' => ['fa', 'en'],
                'default_locale' => 'fa',
                'currency' => 'IRT',
                'deposit_min' => 1000,
                'deposit_max' => 50000000,
                'auth_modes' => ['email', 'google', 'otp'],
            ],
            'security' => [
                'auth_type' => 'Bearer',
                'refresh_token_rotation' => true,
                'rate_limit_max' => int_value(config('rate_limits.api.authenticated.max_attempts', 200)),
            ],
            'system_timestamp' => time(),
        ];

        $this->success($payload, 'پیکربندی اولیه اپلیکیشن موبایل با موفقیت بارگذاری شد', 200);
    }
}
