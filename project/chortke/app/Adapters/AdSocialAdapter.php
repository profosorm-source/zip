<?php

declare(strict_types=1);

namespace App\Adapters;

use App\Contracts\AdSystemContract;
use App\Contracts\LoggerInterface;
use App\Contracts\ValidatorFactoryInterface;
use Core\Database;
use App\Services\Settings\AppSettings;
use App\Constants\PercentageConstants;
use Core\ValueObjects\Money;
use App\Services\Shared\IdempotencyService;
use App\Models\Ads;

/**
 * AdSocialAdapter - creates SocialTask ad records only.
 * Financial hold/withdraw is handled centrally by AdSystemManager.
 */
/**
 * @phpstan-type SocialAdInput array<string, mixed>
 * @phpstan-type SocialAdNormalized array{
 *   platform: string, task_type: string, title: string, description: string,
 *   link: string, price_per_task: string, currency: string, total_count: int,
 *   total_budget: string, site_commission_percent: string, requires_admin_review: bool
 * }
 * @phpstan-type AdAdapterResult array<string, mixed>
 */
class AdSocialAdapter extends AdapterBase implements AdSystemContract
{
    public function __construct(
        private Ads $adModel,
        LoggerInterface $logger,
        AppSettings $appSettings,
        ValidatorFactoryInterface $validatorFactory
    ) {
        parent::__construct($logger, $appSettings, $validatorFactory);
    }

    private function decimalSetting(string $key, string $default): string
    {
        $value = $this->appSettings->get($key, $default);
        if (!is_scalar($value) || !is_numeric((string)$value)) {
            return $default;
        }
        return (string)$value;
    }

    public function getType(): string
    {
        return 'social_task';
    }

    /**
         * @param SocialAdInput $data
         * @return AdAdapterResult
         */
    public function create(int $userId, array $data): array
    {
        try {
            $data = $this->normalize($data);
            $valid = $this->validate($data);
            if (!$valid['valid']) {
                return $this->errorResponse('اطلاعات وارد شده معتبر نیست', $valid['errors']);
            }

            $ad = $this->adModel->create([
                'user_id' => $userId,
                'type' => 'social_task',
                'platform' => $data['platform'],
                'task_type' => $data['task_type'],
                'title' => $data['title'],
                'description' => $data['description'] ?: null,
                'link' => $data['link'],
                'target_url' => $data['link'],
                'price_per_task' => $data['price_per_task'],
                'currency' => $data['currency'],
                'total_budget' => $data['total_budget'],
                'remaining_budget' => $data['total_budget'],
                'total_count' => $data['total_count'],
                'remaining_count' => $data['total_count'],
                'pending_count' => 0,
                'completed_count' => 0,
                'site_commission_percent' => $data['site_commission_percent'],
                'status' => $data['requires_admin_review'] ? 'pending_review' : 'active',
                'created_by' => $userId,
                'created_at' => date('Y-m-d H:i:s'),
                'updated_at' => date('Y-m-d H:i:s'),
            ]);

            if (!is_int($ad) || $ad <= 0) {
                return $this->errorResponse('خطا در نهایی‌سازی تبلیغ در سیستم.');
            }
            $adId = $ad;

            return $this->successResponse('تسک شبکه اجتماعی با موفقیت ثبت شد.', ['id' => $adId, 'ad_id' => $adId]);
        } catch (\Throwable $e) {
            $this->logError('create', $e->getMessage(), ['user_id' => $userId]);
            return $this->errorResponse('خطای سیستمی در ایجاد تبلیغ شبکه اجتماعی رخ داد.');
        }
    }

    /**
         * @param SocialAdInput $data
         * @return array{valid: bool, errors: list<string>}
         */
    public function validate(array $data, bool $isUpdate = false): array
    {
        $data = $this->normalize($data);
        $errors = [];

        if (!in_array($data['platform'], ['instagram', 'telegram', 'twitter', 'tiktok', 'facebook', 'other'], true)) {
            $errors[] = 'پلتفرم نامعتبر است';
        }
        if ($data['platform'] === 'youtube') {
            $errors[] = 'YouTube باید از مسیر AdTube ثبت شود.';
        }
        if (!in_array($data['task_type'], ['follow', 'like', 'comment', 'share', 'view', 'join', 'subscribe', 'retweet', 'other'], true)) {
            $errors[] = 'نوع تسک نامعتبر است';
        }
        if (mb_strlen($data['title']) < 3) {
            $errors[] = 'عنوان تبلیغ نباید خالی باشد';
        }
        if ($data['link'] === '') {
            $errors[] = 'آدرس یا آیدی کانال/صفحه الزامی است';
        } elseif (!filter_var($data['link'], FILTER_VALIDATE_URL) && !preg_match('/^@?[a-zA-Z0-9_.]+$/', $data['link'])) {
            $errors[] = 'فرمت لینک یا آیدی وارد شده معتبر نیست';
        }
        $minPrice = $this->decimalSetting('social_task_min_price', '100');
        if (bccomp($data['price_per_task'], $minPrice, 8) < 0) {
            $errors[] = "حداقل قیمت هر تسک در شبکه‌های اجتماعی {$minPrice} تومان است";
        }
        if ($data['total_count'] < 1) {
            $errors[] = 'تعداد درخواست حداقل باید ۱ عدد باشد';
        }

        return ['valid' => empty($errors), 'errors' => $errors];
    }

    public function isExpired(int $adId): bool
    {
        $ad = $this->adModel->find($adId);
        if (!$ad || !isset($ad->status) || !is_string($ad->status)) return true;
        if (in_array($ad->status, ['expired', 'completed'], true)) return true;
        if (!isset($ad->remaining_budget) || !is_scalar($ad->remaining_budget)) return false;
        return Money::fromString((string)$ad->remaining_budget, isset($ad->currency) && is_string($ad->currency) ? $ad->currency : 'irt')->isZero();
    }

    /** @param SocialAdInput $context */
    public function calculateCost(string $amount, array $context = []): string
    {
        $feePercent = $this->decimalSetting('social_task_site_fee_percent', (string)PercentageConstants::SOCIAL_TASK_FEE_PERCENT);
        $currency = is_string($context['currency'] ?? null) ? $context['currency'] : 'irt';
        return Money::fromString($amount, $currency)->percentage($feePercent)->getAmount();
    }

    /** @return AdAdapterResult */
    public function processPayment(int $adId, int $userId, string $amount, string $currency): array
    {
        $this->assertFraudAllowed($userId, 'ad_social_payment', ['ad_id' => $adId, 'amount' => $amount]);
        return $this->successResponse('پرداخت با بودجه اولیه و escrow مرکزی مدیریت می‌شود.');
    }

    /** @return AdAdapterResult */
    public function track(int $adId, string $eventType, ?int $userId = null): array
    {
        $this->logInfo('track', ['event' => $eventType, 'ad_id' => $adId, 'user_id' => $userId]);
        return $this->successResponse('تراک رویداد انجام شد');
    }

    /** @return array{id: int, type: string, status: string}|null */
    public function getStatus(int $adId): ?array
    {
        $ad = $this->adModel->find($adId);
        if (!$ad) return null;
        $values = get_object_vars($ad);
        if (!isset($values['id'], $values['status']) || !is_scalar($values['id']) || !is_string($values['status'])) return null;
        return ['id' => (int)$values['id'], 'type' => 'social_task', 'status' => $values['status']];
    }

    /**
         * @param SocialAdInput $data
         * @return SocialAdNormalized
         */
    private function normalize(array $data): array
    {
        $currency = strtolower(is_scalar($data['currency'] ?? null) ? (string)$data['currency'] : 'irt');
        if (in_array($currency, ['irr', 'rial'], true)) $currency = 'irt';
        if (!in_array($currency, ['irt', 'usdt'], true)) $currency = 'irt';
        $priceInput = $data['price_per_task'] ?? null;
        $price = is_scalar($priceInput) && is_numeric((string)$priceInput) ? (string)$priceInput : '0';
        $quantityInput = $data['total_count'] ?? $data['quantity'] ?? 1;
        $count = is_scalar($quantityInput) && is_numeric((string)$quantityInput) ? max(1, (int)$quantityInput) : 1;
        $feePercent = $this->decimalSetting('social_task_site_fee_percent', (string)PercentageConstants::SOCIAL_TASK_FEE_PERCENT);
        $reviewSetting = $this->appSettings->get('social_task_requires_admin_review', 0);

        return [
            'platform' => strtolower(is_scalar($data['platform'] ?? null) ? (string)$data['platform'] : 'instagram'),
            'task_type' => strtolower(is_scalar($data['task_type'] ?? null) ? (string)$data['task_type'] : 'follow'),
            'title' => trim(is_scalar($data['title'] ?? null) ? (string)$data['title'] : ''),
            'description' => trim(is_scalar($data['description'] ?? null) ? (string)$data['description'] : ''),
            'link' => trim(is_scalar($data['link'] ?? null) ? (string)$data['link'] : (is_scalar($data['target_link'] ?? null) ? (string)$data['target_link'] : (is_scalar($data['target_url'] ?? null) ? (string)$data['target_url'] : ''))),
            'price_per_task' => $price,
            'currency' => $currency,
            'total_count' => $count,
            'total_budget' => Money::fromString($price, $currency)->multiply((string)$count)->getAmount(),
            'site_commission_percent' => $feePercent,
            'requires_admin_review' => is_scalar($reviewSetting) && (bool)$reviewSetting,
        ];
    }
}
