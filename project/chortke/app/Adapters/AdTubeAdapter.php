<?php

namespace App\Adapters;

use Core\ValueObjects\Money;
use App\Contracts\AdSystemContract;
use App\Contracts\LoggerInterface;
use App\Contracts\ValidatorFactoryInterface;
use Core\Database;
use App\Services\Settings\AppSettings;
use App\Constants\PercentageConstants;
use App\Services\Shared\IdempotencyService;
use App\Models\Ads;

/**
 * AdTubeAdapter - creates AdTube campaign records only.
 * Financial hold/withdraw is handled centrally by AdSystemManager.
 *
 * @phpstan-type AdTubeCreateResult array{success: bool, message: string, data?: array<string, mixed>, errors?: array<int|string, mixed>}
 */
class AdTubeAdapter extends AdapterBase implements AdSystemContract
{
    public function __construct(
        private Ads $adModel,
        LoggerInterface $logger,
        AppSettings $appSettings,
        ValidatorFactoryInterface $validatorFactory
    ) {
        parent::__construct($logger, $appSettings, $validatorFactory);
    }

    public function getType(): string { return 'adtube'; }

    /**
     * @param array<string, mixed> $data
     * @return array{success: bool, message: string, data?: array<string, mixed>, errors?: array<int|string, mixed>}
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
                'type' => 'adtube',
                'platform' => 'youtube',
                'task_type' => 'view',
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

            if (!$ad) {
                return $this->errorResponse('خطا در ذخیره نهایی تبلیغ');
            }
            $adId = 0;
            if (is_object($ad)) {
                $adVars = get_object_vars($ad);
                $adId = is_numeric($adVars['id'] ?? null) ? (int)$adVars['id'] : 0;
            } elseif (is_numeric($ad)) {
                $adId = (int)$ad;
            }
            if ($adId <= 0) {
                return $this->errorResponse('شناسه آگهی ایجادشده نامعتبر است');
            }
            return $this->successResponse('تبلیغ AdTube با موفقیت ثبت شد', ['id' => $adId, 'ad_id' => $adId]);
        } catch (\Throwable $e) {
            $this->logError('create', $e->getMessage(), ['user_id' => $userId]);
            return $this->errorResponse('خطای سیستمی در ایجاد تبلیغ AdTube رخ داد.');
        }
    }

    public function validate(array $data, bool $isUpdate = false): array
    {
        $data = $this->normalize($data);
        $errors = [];
        if (mb_strlen($data['title']) < 3) $errors[] = 'عنوان تبلیغ الزامی است';
        if ($data['link'] === '') {
            $errors[] = 'لینک ویدیوی یوتیوب الزامی است';
        } elseif (!preg_match('/(youtube\.com|youtu\.be)/i', $data['link'])) {
            $errors[] = 'لینک وارد شده باید یک لینک معتبر یوتیوب باشد';
        }
        $minPriceValue = $this->appSettings->get('adtube_min_price_per_view', 100);
        $minPrice = is_numeric($minPriceValue) ? (string)$minPriceValue : '100';
        if (bccomp($data['price_per_task'], $minPrice, 8) < 0) {
            $errors[] = "حداقل هزینه هر نمایش {$minPrice} تومان می‌باشد";
        }
        if ($data['total_count'] < 1) $errors[] = 'تعداد نمایش‌های درخواستی باید حداقل ۱ عدد باشد';
        return ['valid' => empty($errors), 'errors' => $errors];
    }

    public function isExpired(int $adId): bool
    {
        $ad = $this->adModel->find($adId);
        if (!is_object($ad)) {
            return true;
        }
        $adVars = get_object_vars($ad);
        $status = is_string($adVars['status'] ?? null) ? $adVars['status'] : '';
        $remaining = is_numeric($adVars['remaining_budget'] ?? null) ? (float)$adVars['remaining_budget'] : 0.0;
        return in_array($status, ['expired', 'completed'], true) || $remaining <= 0;
    }

    public function calculateCost(string $amount, array $context = []): string
    {
        $feePercentValue = $this->appSettings->get('adtube_site_fee_percent', PercentageConstants::AD_TUBE_FEE_PERCENT);
        $feePercent = is_numeric($feePercentValue) ? (string)$feePercentValue : (string)PercentageConstants::AD_TUBE_FEE_PERCENT;
        $currency = is_string($context['currency'] ?? null) ? $context['currency'] : 'irt';
        // float→decimal: محاسبهٔ کارمزد با Money/BCMath به‌جای float برای جلوگیری از خطای اعشار
        return Money::fromString($amount, $currency)->percentage($feePercent)->getAmount();
    }

    public function processPayment(int $adId, int $userId, string $amount, string $currency): array
    {
        $this->assertFraudAllowed($userId, 'ad_tube_payment', ['ad_id' => $adId, 'amount' => $amount]);
        return $this->successResponse('پرداخت با بودجه اولیه و escrow مرکزی مدیریت می‌شود.');
    }

    public function track(int $adId, string $eventType, ?int $userId = null): array
    {
        $this->logInfo('track', ['event' => $eventType, 'ad_id' => $adId, 'user_id' => $userId]);
        return $this->successResponse('رویداد ثبت شد');
    }

    public function getStatus(int $adId): ?array
    {
        $ad = $this->adModel->find($adId);
        if (!is_object($ad)) {
            return null;
        }
        $adVars = get_object_vars($ad);
        return [
            'id' => is_numeric($adVars['id'] ?? null) ? (int)$adVars['id'] : 0,
            'type' => 'adtube',
            'status' => is_string($adVars['status'] ?? null) ? $adVars['status'] : 'unknown',
        ];
    }

    /**
     * @param array<string, mixed> $data
     * @return array{title: string, description: string, link: string, price_per_task: string, currency: string, total_count: int, total_budget: string, site_commission_percent: string, requires_admin_review: bool}
     */
    private function normalize(array $data): array
    {
        $toString = static fn(mixed $value): string => is_scalar($value) ? trim((string)$value) : '';
        $toInt = static fn(mixed $value): int => is_numeric($value) ? (int)$value : 0;

        $currency = strtolower($toString($data['currency'] ?? 'irt'));
        if (in_array($currency, ['irr', 'rial'], true)) $currency = 'irt';
        if (!in_array($currency, ['irt', 'usdt'], true)) $currency = 'irt';
        // float→decimal: قیمت به‌صورت رشتهٔ decimal نگه داشته می‌شود
        $priceInput = $data['price_per_task'] ?? 0;
        $price = is_numeric($priceInput) ? (string)$priceInput : '0';
        $count = max(1, $toInt($data['total_count'] ?? $data['quantity'] ?? 1));
        $feePercentValue = $this->appSettings->get('adtube_site_fee_percent', PercentageConstants::AD_TUBE_FEE_PERCENT);
        $feePercent = is_numeric($feePercentValue) ? (string)$feePercentValue : (string)PercentageConstants::AD_TUBE_FEE_PERCENT;
        return [
            'title' => $toString($data['title'] ?? ''),
            'description' => $toString($data['description'] ?? ''),
            'link' => $toString($data['link'] ?? $data['target_link'] ?? $data['target_url'] ?? ''),
            'price_per_task' => $price,
            'currency' => $currency,
            'total_count' => $count,
            'total_budget' => Money::fromString($price, $currency)->multiply((string)$count)->getAmount(),
            'site_commission_percent' => $feePercent,
            'requires_admin_review' => (bool)$this->appSettings->get('adtube_requires_admin_review', 0),
        ];
    }
}
