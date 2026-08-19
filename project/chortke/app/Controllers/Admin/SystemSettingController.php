<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Services\Settings\AppSettings;
use App\Services\Settings\SettingsManager;
use App\Services\UploadService;

class SystemSettingController extends BaseAdminController
{
    private AppSettings $appSettings;
    private SettingsManager $settingsManager;
    private UploadService $uploadService;
    
    public function __construct(
        AppSettings $appSettings,
        SettingsManager $settingsManager,
        UploadService $uploadService,
        ?\App\Contracts\LoggerInterface $logger = null
    ) {
        parent::__construct(null, null, null, null, $logger);
        $this->appSettings = $appSettings;
        $this->settingsManager = $settingsManager;
        $this->uploadService = $uploadService;
    }
    
    /**
     * نمایش تنظیمات
     */
    public function index(): void
    {
        $category = $this->request->str('category', 'general');
        $settings = $this->appSettings->getByCategory($category);
        
        $categories = [
            'general' => 'عمومی',
            'banking' => 'بانکی',
            'task'    => 'تسک‌ها',
            'wallet'  => 'کیف پول',
            'security'=> 'امنیت',
            'contact' => 'تماس',
            'images'  => 'تصاویر و لوگو',
            'video_ads' => 'تبلیغات ویدیویی جایزه‌دار', // 🛡️ اضافه شدن تب مدیریت تعرفه‌های ۶ شبکه بومی و خارجی
        ];
        
        $this->view('admin/settings/index', [
            'settings' => $settings,
            'categories' => $categories,
            'currentCategory' => $category,
            'title' => 'تنظیمات سیستم'
        ]);
    }
    
    /**
     * بروزرسانی تنظیم
     */
    public function update(): void
    {
        $data = $this->request->body();
        $id    = int_value($data['id'] ?? 0);
        $key   = trim(str_value($data['key'] ?? ''));
        $value = str_value($data['value'] ?? '');

        if ($id <= 0 || $key === '') {
            $this->jsonError('درخواست نامعتبر است');
        }

        $oldSetting = $this->appSettings->find($id);
        $oldValue = $oldSetting->value ?? null;

        $ok = $this->settingsManager->updateById($id, $key, $value);

        if (!$ok) {
            $this->jsonError('تنظیمات یافت نشد یا کلید معتبر نیست');
        }

        // Log the change using robust Audit Trail
        $this->auditLog(
            'setting.updated',
            'setting',
            $id,
            ['key' => $key, 'value' => $oldValue],
            ['key' => $key, 'value' => $value]
        );

        $this->appSettings->clearCache();

        if (function_exists('settings')) {
            settings(true);
        }

        $this->jsonSuccess('تنظیمات ذخیره شد');
    }

    /**
     * آپلود تصویر برای تنظیمات
     */
    public function uploadImage(): void
    {
        if (!$this->request->hasFile('image')) {
            $this->jsonError('فایلی آپلود نشده است');
        }
        
        $settingId = $this->request->int('setting_id');
        $setting = $this->appSettings->find($settingId);
        
        if (!$setting || (($setting->group ?? '') !== 'images' && $setting->type !== 'image')) {
            $this->jsonError('تنظیم یافت نشد یا نوع آن تصویر نیست', [], 404);
        }
        
        $file = $this->request->file('image');
        if (!is_array($file)) {
            $this->jsonError('ساختار فایل آپلودشده نامعتبر است', [], 422);
            return;
        }

        $oldImagePath = isset($setting->value) && is_string($setting->value)
            ? $setting->value
            : null;
        $newImagePath = null;
        $settingUpdated = false;

        try {
            // UploadService آرگومان سوم را به‌صورت list<MIME> و محدودیت حجم را
            // در آرگومان چهارم دریافت می‌کند. SVG و ICO عمداً توسط سرویس امن
            // تصاویر پشتیبانی نمی‌شوند.
            $result = $this->uploadService->upload(
                $file,
                'site-images',
                ['image/jpeg', 'image/png', 'image/gif'],
                2 * 1024 * 1024
            );

            $newImagePath = $result['path'];

            // ابتدا reference دیتابیس به فایل جدید به‌روزرسانی می‌شود. فایل قبلی
            // فقط بعد از موفقیت update حذف می‌گردد تا شکست upload باعث data loss نشود.
            $updated = $this->settingsManager->updateValueById($settingId, $newImagePath);
            if (!$updated) {
                throw new \Core\Exceptions\ApplicationException('خطا در ذخیره اطلاعات در دیتابیس');
            }
            $settingUpdated = true;

            if ($oldImagePath !== null && $oldImagePath !== '' && $oldImagePath !== $newImagePath) {
                $this->uploadService->delete($oldImagePath);
            }

            // Log the change using robust Audit Trail
            $this->auditLog(
                'setting.image_uploaded',
                'setting',
                $settingId,
                ['key' => $setting->key ?? null, 'value' => $oldImagePath],
                ['key' => $setting->key ?? null, 'value' => $newImagePath]
            );

            $this->appSettings->clearCache();

            $this->jsonSuccess('تصویر با موفقیت آپلود شد', [
                'url' => url($newImagePath),
                'path' => $newImagePath,
            ]);

        } catch (\Throwable $e) {
            // اگر upload موفق ولی update دیتابیس ناموفق بود، فایل orphan جدید پاک شود.
            if (!$settingUpdated && $newImagePath !== null) {
                $this->uploadService->delete($newImagePath);
            }
            $this->logger->error('admin.settings.upload_failed', ['error' => $e->getMessage()]);
            $this->jsonError('خطا در آپلود تصویر: ' . $e->getMessage(), [], 500);
        }
    }

    /**
     * حذف تصویر
     */
    public function removeImage(): void
    {
        $data = $this->request->body();
        $settingId = int_value($data['setting_id'] ?? 0);
        
        $setting = $this->appSettings->find($settingId);
        if (!$setting) {
            $this->jsonError('تنظیم یافت نشد', [], 404);
        }
        
        try {
            $storedImagePath = $setting->value ?? null;
            if (is_string($storedImagePath) && $storedImagePath !== '') {
                $this->uploadService->delete($storedImagePath);
            }
            
            $this->settingsManager->updateValueById($settingId, '');

            // Log the change using robust Audit Trail
            $this->auditLog(
                'setting.image_removed',
                'setting',
                $settingId,
                ['key' => $setting->key ?? null, 'value' => $setting->value ?? null],
                ['key' => $setting->key ?? null, 'value' => '']
            );

            $this->appSettings->clearCache();
            
            $this->jsonSuccess('تصویر با موفقیت حذف شد');
            
        } catch (\Exception $e) {
            $this->jsonError('خطا در حذف تصویر: ' . $e->getMessage(), [], 500);
        }
    }
}