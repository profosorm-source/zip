<?php

declare(strict_types=1);

namespace App\Controllers\User;

use App\Controllers\BaseController;
use App\Services\AdSystemManager;
use App\Models\Ads;
use App\Services\Settings\AppSettings;
use Core\Request;

/**
 * AdsApiController — AJAX endpoints for the Unified Ad Wizard.
 * Provides progressive validation, cost preview, and type metadata.
 */
class AdsApiController extends BaseController
{
    private AdSystemManager $adManager;
    private AppSettings $appSettings;

    public function __construct(
        AdSystemManager $adManager,
        AppSettings $appSettings,
        ?\App\Contracts\LoggerInterface $logger = null
    ) {
        $this->adManager = $adManager;
        $this->appSettings = $appSettings;
        parent::__construct(null, null, null, null, $logger);
    }

    /**
     * GET /api/ads/type-info
     * Returns metadata, icon, description, and required fields for each ad type.
     */
    public function typeInfo(): void
    {
        header('Content-Type: application/json; charset=utf-8');

        $type = $this->request->query('type');
        $types = $this->buildTypeRegistry();

        if ($type && isset($types[$type])) {
            echo json_encode(['success' => true, 'data' => $types[$type]], JSON_UNESCAPED_UNICODE);
            return;
        }

        echo json_encode(['success' => true, 'types' => $types], JSON_UNESCAPED_UNICODE);
    }

    /**
     * POST /api/ads/validate-field
     * Real-time field validation (single-field AJAX check before full submit).
     */
    public function validateField(): void
    {
        header('Content-Type: application/json; charset=utf-8');
        $data = is_array($this->request->input()) ? $this->request->input() : [];

        $type = $data['ad_type'] ?? null;
        $field = $data['field'] ?? null;
        $value = $data['value'] ?? null;

        if (!$type || !$field) {
            echo json_encode(['success' => false, 'message' => 'نوع تبلیغ و نام فیلد الزامی است.']);
            return;
        }

        try {
            // File fields are validated in /ads/store after upload. For realtime wizard UX,
            // presence of a selected file is enough to unlock the next step.
            if ($type === 'banner' && $field === 'image' && trim((string)$value) !== '') {
                echo json_encode(['success' => true, 'field' => $field, 'errors' => [], 'message' => 'فایل انتخاب شد'], JSON_UNESCAPED_UNICODE);
                return;
            }

            $adapter = $this->adManager->getAdapter($type);
            // Perform full validation on the partial data available
            $partial = array_merge($data, [$field => $value]);
            $result = $adapter->validate($partial);

            $fieldErrors = [];
            if (!$result['valid']) {
                // Try to extract only errors related to this field
                $errors = is_array($result['errors'] ?? null) ? $result['errors'] : [];
                foreach ($errors as $err) {
                    if (is_string($err) && (str_contains($err, $field) || $this->fieldMatchesError($field, $err))) {
                        $fieldErrors[] = $err;
                    }
                }
            }

            echo json_encode([
                'success' => empty($fieldErrors),
                'field' => $field,
                'errors' => $fieldErrors,
                'message' => empty($fieldErrors) ? 'معتبر است' : null,
            ], JSON_UNESCAPED_UNICODE);
        } catch (\Exception $e) {
            $this->logger->warning('api.validate_field.failed', ['type' => $type, 'field' => $field, 'error' => $e->getMessage()]);
            $isDomain = $e instanceof \Core\Exceptions\BusinessException || $e instanceof \InvalidArgumentException;
            $msg = $isDomain ? $e->getMessage() : 'خطا در اعتبارسنجی فیلد';
            echo json_encode(['success' => false, 'message' => $msg], JSON_UNESCAPED_UNICODE);
        }
    }

    /**
     * POST /api/ads/preview-cost
     * Calculates budget, site fee, and total cost without side effects.
     */
    public function previewCost(): void
    {
        header('Content-Type: application/json; charset=utf-8');
        $data = is_array($this->request->input()) ? $this->request->input() : [];

        $type = $data['ad_type'] ?? null;
        $pricePerTask = (float) ($data['price_per_task'] ?? $data['min_payout'] ?? $data['price_per_click'] ?? 0);
        $quantity = (int) ($data['total_count'] ?? $data['quantity'] ?? $data['expected_users'] ?? 1);
        $budget = (float) ($data['budget'] ?? 0);

        if (!$type) {
            echo json_encode(['success' => false, 'message' => 'نوع تبلیغ الزامی است.']);
            return;
        }

        try {
            $adapter = $this->adManager->getAdapter($type);

            // Determine fee percent based on type
            $feePercent = match ($type) {
                'social_task' => float_value($this->appSettings->get('social_task_site_fee_percent', 10)),
                'adtube' => float_value($this->appSettings->get('adtube_site_fee_percent', 10)),
                'custom_task' => float_value($this->appSettings->get('custom_task_site_fee_percent', 10)),
                'seo' => float_value($this->appSettings->get('seo_ad_site_fee_percent', 10)),
                'banner' => float_value($this->appSettings->get('banner_fee_percent', 12)),
                default => 10,
            };

            if (in_array($type, ['seo', 'banner', 'notification'], true) && $budget > 0 && $pricePerTask <= 0) {
                // Budget-based campaigns use the declared budget directly.
                $totalBudget = $budget;
                $siteFee = $totalBudget * ($feePercent / 100);
                $totalWithFee = $totalBudget + $siteFee;
            } else {
                $totalBudget = $pricePerTask * $quantity;
                $siteFee = $totalBudget * ($feePercent / 100);
                $totalWithFee = $totalBudget + $siteFee;
            }

            // Estimate reach
            $estimatedReach = $this->estimateReach($type, $quantity, $budget, $pricePerTask);

            echo json_encode([
                'success' => true,
                'preview' => [
                    'base_budget' => round($totalBudget, 2),
                    'site_fee_percent' => $feePercent,
                    'site_fee_amount' => round($siteFee, 2),
                    'total_with_fee' => round($totalWithFee, 2),
                    'estimated_reach' => $estimatedReach,
                    'currency' => 'irt',
                ]
            ], JSON_UNESCAPED_UNICODE);
        } catch (\Exception $e) {
            $this->logger->warning('api.preview_cost.failed', ['type' => $type, 'error' => $e->getMessage()]);
            $isDomain = $e instanceof \Core\Exceptions\BusinessException || $e instanceof \InvalidArgumentException;
            $msg = $isDomain ? $e->getMessage() : 'خطا در محاسبه هزینه کمپین';
            echo json_encode(['success' => false, 'message' => $msg], JSON_UNESCAPED_UNICODE);
        }
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    /** @return array<string, array<string, mixed>> */
    private function buildTypeRegistry(): array
    {
        return [
            'social_task' => [
                'label' => 'شبکه‌های اجتماعی',
                'icon' => 'group',
                'description' => 'فالو، لایک، کامنت، عضویت و... در اینستاگرام، تلگرام و توییتر',
                'fields' => [
                    ['name' => 'title', 'type' => 'text', 'label' => 'عنوان تبلیغ', 'required' => true],
                    ['name' => 'platform', 'type' => 'select', 'label' => 'پلتفرم', 'options' => ['instagram', 'telegram', 'twitter', 'tiktok'], 'required' => true],
                    ['name' => 'task_type', 'type' => 'select', 'label' => 'نوع تسک', 'options' => ['follow', 'like', 'comment', 'share', 'join', 'subscribe'], 'required' => true],
                    ['name' => 'target_link', 'type' => 'text', 'label' => 'آیدی یا لینک صفحه', 'required' => true, 'placeholder' => '@username یا https://...'],
                    ['name' => 'price_per_task', 'type' => 'number', 'label' => 'پاداش هر تسک (تومان)', 'min' => 100, 'step' => 100, 'required' => true],
                    ['name' => 'total_count', 'type' => 'number', 'label' => 'تعداد تسک', 'min' => 1, 'required' => true],
                    ['name' => 'description', 'type' => 'textarea', 'label' => 'توضیحات تکمیلی', 'required' => false],
                ],
                'fee_percent_key' => 'social_task_site_fee_percent',
                'min_price_key' => 'social_task_min_price',
            ],
            'adtube' => [
                'label' => 'AdTube',
                'icon' => 'play_circle',
                'description' => 'نمایش ویدیوهای یوتیوب — کاربران برای تماشای ویدیو پاداش دریافت می‌کنند',
                'fields' => [
                    ['name' => 'title', 'type' => 'text', 'label' => 'عنوان ویدیو', 'required' => true],
                    ['name' => 'target_link', 'type' => 'url', 'label' => 'لینک ویدیوی یوتیوب', 'required' => true, 'placeholder' => 'https://youtube.com/watch?v=...'],
                    ['name' => 'price_per_task', 'type' => 'number', 'label' => 'پاداش هر نمایش (تومان)', 'min' => 100, 'step' => 100, 'required' => true],
                    ['name' => 'total_count', 'type' => 'number', 'label' => 'تعداد نمایش', 'min' => 1, 'required' => true],
                    ['name' => 'description', 'type' => 'textarea', 'label' => 'توضیحات', 'required' => false],
                ],
                'fee_percent_key' => 'adtube_site_fee_percent',
                'min_price_key' => 'adtube_min_price_per_view',
            ],
            'custom_task' => [
                'label' => 'تسک سفارشی',
                'icon' => 'assignment',
                'description' => 'ثبت‌نام، نصب اپ، کد، لینک، فایل یا هر مأموریت سفارشی با مدرک تعریف‌شده',
                'fields' => [
                    ['name' => 'title', 'type' => 'text', 'label' => 'عنوان تسک', 'required' => true],
                    ['name' => 'description', 'type' => 'textarea', 'label' => 'شرح تسک', 'required' => true],
                    ['name' => 'link', 'type' => 'url', 'label' => 'لینک هدف (اختیاری)', 'required' => false],
                    ['name' => 'proof_type', 'type' => 'select', 'label' => 'نوع مدرک', 'options' => ['text', 'code', 'url', 'screenshot', 'file', 'video'], 'required' => true],
                    ['name' => 'proof_description', 'type' => 'textarea', 'label' => 'دستورالعمل مدرک', 'required' => true, 'hint' => 'دقیق بنویسید چه چیزی قابل قبول است؛ مثال: کد پیگیری، لینک پروفایل، اسکرین‌شات، فایل PDF و ...'],
                    ['name' => 'price_per_task', 'type' => 'number', 'label' => 'پاداش هر تسک (تومان)', 'min' => 100, 'step' => 100, 'required' => true],
                    ['name' => 'total_count', 'type' => 'number', 'label' => 'تعداد درخواست', 'min' => 1, 'required' => true],
                    ['name' => 'deadline_hours', 'type' => 'number', 'label' => 'مهلت انجام (ساعت)', 'min' => 1, 'max' => 168, 'default' => 24, 'required' => false],
                ],
                'fee_percent_key' => 'custom_task_site_fee_percent',
                'min_price_key' => 'custom_task_min_price',
            ],
            'seo' => [
                'label' => 'سئو و کلیک',
                'icon' => 'search',
                'description' => 'جستجوی گوگل، بازدید سایت و کلیک هدفمند',
                'fields' => [
                    ['name' => 'title', 'type' => 'text', 'label' => 'عنوان کمپین', 'required' => true],
                    ['name' => 'target_link', 'type' => 'url', 'label' => 'آدرس سایت', 'required' => true],
                    ['name' => 'keyword', 'type' => 'text', 'label' => 'کلمه کلیدی', 'required' => true],
                    ['name' => 'budget', 'type' => 'number', 'label' => 'بودجه کل (تومان)', 'min' => 50000, 'step' => 1000, 'required' => true],
                    ['name' => 'min_payout', 'type' => 'number', 'label' => 'حداقل پرداخت (تومان)', 'min' => 1000, 'required' => true],
                    ['name' => 'max_payout', 'type' => 'number', 'label' => 'حداکثر پرداخت (تومان)', 'min' => 1000, 'required' => true],
                    ['name' => 'description', 'type' => 'textarea', 'label' => 'توضیحات', 'required' => false],
                ],
                'fee_percent_key' => 'seo_ad_site_fee_percent',
                'min_price_key' => 'seo_ad_min_payout',
            ],
            'notification' => [
                'label' => 'نوتیفیکیشن تبلیغاتی',
                'icon' => 'notifications_active',
                'description' => 'ارسال پیام تبلیغاتی هدفمند در مرکز اعلان‌ها و Push کاربران',
                'fields' => [
                    ['name' => 'title', 'type' => 'text', 'label' => 'عنوان پیام', 'required' => true],
                    ['name' => 'body', 'type' => 'textarea', 'label' => 'متن پیام', 'required' => true],
                    ['name' => 'target_link', 'type' => 'url', 'label' => 'لینک مقصد اختیاری', 'required' => false, 'placeholder' => 'https://...'],
                    ['name' => 'budget', 'type' => 'number', 'label' => 'بودجه کل (تومان)', 'min' => 1000, 'step' => 1000, 'required' => true],
                    ['name' => 'scheduled_time', 'type' => 'datetime-local', 'label' => 'زمان پیشنهادی ارسال', 'required' => false],
                ],
                'fee_percent_key' => 'notification_ad_fee_percent',
                'min_price_key' => 'notification_ad_min_budget',
            ],
            'banner' => [
                'label' => 'بنر',
                'icon' => 'image',
                'description' => 'نمایش بنر در سایت چرتکه',
                'fields' => [
                    ['name' => 'title', 'type' => 'text', 'label' => 'عنوان بنر', 'required' => true],
                    ['name' => 'placement', 'type' => 'select', 'label' => 'موقعیت نمایش', 'options' => ['header', 'sidebar', 'footer', 'inline', 'popup'], 'required' => true],
                    ['name' => 'target_link', 'type' => 'url', 'label' => 'لینک مقصد', 'required' => true],
                    ['name' => 'image', 'type' => 'file', 'label' => 'تصویر بنر', 'required' => true, 'accept' => 'image/*'],
                    ['name' => 'budget', 'type' => 'number', 'label' => 'بودجه کل (تومان)', 'min' => 100000, 'step' => 10000, 'required' => true],
                    ['name' => 'start_date', 'type' => 'datetime-local', 'label' => 'تاریخ شروع', 'required' => false],
                    ['name' => 'end_date', 'type' => 'datetime-local', 'label' => 'تاریخ پایان', 'required' => false],
                ],
                'fee_percent_key' => 'banner_fee_percent',
                'min_price_key' => 'banner_min_budget',
            ],
        ];
    }

    private function fieldMatchesError(string $field, string $error): bool
    {
        $map = [
            'title' => ['عنوان', 'title'],
            'platform' => ['پلتفرم', 'platform'],
            'task_type' => ['نوع تسک', 'task_type', 'task type'],
            'target_link' => ['لینک', 'link', 'آدرس', 'url'],
            'price_per_task' => ['قیمت', 'price', 'پاداش', 'budget'],
            'total_count' => ['تعداد', 'count', 'quantity'],
            'description' => ['توضیحات', 'description'],
            'link' => ['لینک', 'link'],
            'proof_type' => ['مدرک', 'proof'],
            'proof_description' => ['دستورالعمل', 'proof_description'],
            'deadline_hours' => ['مهلت', 'deadline'],
            'keyword' => ['کلمه کلیدی', 'keyword'],
            'min_payout' => ['حداقل پرداخت', 'min_payout'],
            'max_payout' => ['حداکثر پرداخت', 'max_payout'],
            'placement' => ['موقعیت', 'placement'],
            'image' => ['تصویر', 'image', 'banner'],
            'start_date' => ['تاریخ شروع', 'start_date'],
            'end_date' => ['تاریخ پایان', 'end_date'],
            'body' => ['متن', 'body', 'message'],
            'scheduled_time' => ['زمان ارسال', 'scheduled_time'],
        ];

        $keywords = $map[$field] ?? [$field];
        foreach ($keywords as $kw) {
            if (str_contains($error, $kw)) return true;
        }
        return false;
    }

    private function estimateReach(string $type, int $quantity, float $budget, float $price): int
    {
        if (in_array($type, ['seo', 'banner', 'notification'], true)) {
            return $type === 'seo' && $price > 0 && $budget > 0 ? max(1, (int)floor($budget / $price)) : max(1, $quantity);
        }
        return max(1, $quantity);
    }
}
