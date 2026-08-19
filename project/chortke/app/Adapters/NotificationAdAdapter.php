<?php

declare(strict_types=1);

namespace App\Adapters;

use Core\ValueObjects\Money;
use App\Contracts\AdSystemContract;
use App\Contracts\LoggerInterface;
use App\Contracts\ValidatorFactoryInterface;
use App\Models\Ads;
use App\Contracts\WalletServiceInterface;
use App\Services\Shared\IdempotencyService;
use Core\Database;
use App\Services\Settings\AppSettings;

/**
 * NotificationAdAdapter - creates mass push-notification ad records only.
 * Financial hold/withdraw is handled centrally by AdSystemManager.
 */
class NotificationAdAdapter extends AdapterBase implements AdSystemContract
{
    public function __construct(
        private Ads $adModel,
        WalletServiceInterface $walletService,
        Database $db,
        LoggerInterface $logger,
        AppSettings $appSettings,
        ValidatorFactoryInterface $validatorFactory,
        IdempotencyService $idempotencyService,
        private \App\Services\Ads\AdsBudgetSettlementService $adsBudgetSettlementService
    ) {
        unset($walletService, $db, $idempotencyService);
        parent::__construct($logger, $appSettings, $validatorFactory);
    }

    public function getType(): string { return 'notification'; }

    public function create(int $userId, array $data): array
    {
        try {
            $data = $this->normalize($data);
            $validation = $this->validate($data);
            if (!$validation['valid']) {
                return $this->errorResponse('ورودی‌های آگهی معتبر نیستند.', $validation['errors']);
            }

            $adId = $this->adModel->create([
                'user_id' => $userId,
                'type' => 'notification',
                'title' => $data['title'],
                'description' => $data['body'],
                'budget' => $data['budget'],
                'total_budget' => $data['budget'],
                'remaining_budget' => $data['budget'],
                'site_commission_percent' => $data['site_commission_percent'],
                'status' => $data['requires_admin_review'] ? 'pending_review' : 'active',
                'is_active' => $data['requires_admin_review'] ? 0 : 1,
                'link' => $data['target_link'] ?: null,
                'target_url' => $data['target_link'] ?: null,
                'restrictions' => json_encode([
                    'push_body' => $data['body'],
                    'image_path' => $data['image_path'] ?: null,
                    'icon' => $data['icon'],
                    'scheduled_time' => $data['scheduled_time'] ?: null,
                ], JSON_UNESCAPED_UNICODE),
                'created_at' => date('Y-m-d H:i:s'),
                'updated_at' => date('Y-m-d H:i:s'),
            ]);

            if (!$adId) {
                return $this->errorResponse('خطا در ایجاد آگهی نوتیفیکیشن.');
            }
            $id = is_object($adId) ? (is_numeric(get_object_vars($adId)['id'] ?? null) ? (int)get_object_vars($adId)['id'] : 0) : (is_numeric($adId) ? (int)$adId : 0);
            return $this->successResponse('آگهی نوتیفیکیشنی شما ثبت شد.', ['id' => $id, 'ad_id' => $id]);
        } catch (\Throwable $e) {
            $this->logError('create', $e->getMessage(), ['user_id' => $userId]);
            return $this->errorResponse('خطای سیستمی در ایجاد آگهی نوتیفیکیشن رخ داد.');
        }
    }

    public function validate(array $data, bool $isUpdate = false): array
    {
        $data = $this->normalize($data);
        $errors = [];
        if (mb_strlen($data['title']) < 3) $errors[] = 'عنوان نوتیفیکیشن الزامی است.';
        if (mb_strlen($data['body']) < 10) $errors[] = 'متن پیام نوتیفیکیشن باید حداقل ۱۰ کاراکتر باشد.';
        if (bccomp($data['budget'], '1000', 8) < 0) $errors[] = 'بودجه نوتیفیکیشن باید حداقل ۱۰۰۰ تومان باشد.';
        if ($data['target_link'] !== '' && !filter_var($data['target_link'], FILTER_VALIDATE_URL)) $errors[] = 'لینک مقصد نوتیفیکیشن معتبر نیست.';
        return ['valid' => empty($errors), 'errors' => $errors];
    }

    public function isExpired(int $adId): bool
    {
        $ad = $this->adModel->find($adId);
        if (!is_object($ad)) return true;
        $vars = get_object_vars($ad);
        return !is_numeric($vars['remaining_budget'] ?? null) || (float)$vars['remaining_budget'] <= 0;
    }

    public function calculateCost(string $amount, array $context = []): string
    {
        $feeValue = $this->appSettings->get('notification_ad_fee_percent', 15);
        $fee = is_numeric($feeValue) ? (string)$feeValue : '15';
        $currency = is_string($context['currency'] ?? null) ? $context['currency'] : 'irt';
        // float→decimal: محاسبهٔ کارمزد با Money/BCMath به‌جای float
        return Money::fromString($amount, $currency)->percentage($fee)->getAmount();
    }

    public function processPayment(int $adId, int $userId, string $amount, string $currency): array
    {
        $this->assertFraudAllowed($userId, 'ad_notification_payment', ['ad_id' => $adId, 'amount' => $amount]);
        return $this->successResponse('پرداخت با بودجه اولیه و escrow مرکزی مدیریت می‌شود.');
    }

    public function track(int $adId, string $eventType, ?int $userId = null): array
    {
        $finance = $this->adsBudgetSettlementService;
        $result = $finance->consumeDeliveryBudget($adId, 'notification', $eventType === 'click' ? 'click' : 'delivery', 1, $userId, [
            'source' => 'notification_adapter.track',
        ]);
        return !empty($result['success'])
            ? $this->successResponse('آمار و مصرف بودجه نوتیفیکیشن ثبت شد.', $result)
            : $this->errorResponse(is_string($result['message'] ?? null) ? $result['message'] : 'ثبت آمار نوتیفیکیشن انجام نشد.');
    }

    public function getStatus(int $adId): ?array
    {
        $ad = $this->adModel->find($adId);
        if (!$ad) return null;
        $vars = get_object_vars($ad);
        return ['id' => is_numeric($vars['id'] ?? null) ? (int)$vars['id'] : 0, 'type' => 'notification', 'status' => is_string($vars['status'] ?? null) ? $vars['status'] : 'unknown', 'impressions' => is_numeric($vars['impressions'] ?? null) ? (int)$vars['impressions'] : 0, 'clicks' => is_numeric($vars['clicks'] ?? null) ? (int)$vars['clicks'] : 0];
    }

    /**
     * @param array<string, mixed> $data
     * @return array{
     *   title: string,
     *   body: string,
     *   budget: string,
     *   target_link: string,
     *   image_path: string,
     *   icon: string,
     *   scheduled_time: string,
     *   site_commission_percent: string,
     *   requires_admin_review: bool
     * }
     */
    private function normalize(array $data): array
    {
        $toString = static fn(mixed $v): string => is_scalar($v) ? trim((string)$v) : '';
        $feeValue = $this->appSettings->get('notification_ad_fee_percent', 15);
        $fee = is_numeric($feeValue) ? (string)$feeValue : '15';
        // float→decimal: بودجه به‌صورت رشتهٔ decimal
        $budgetInput = $data['budget'] ?? $data['total_budget'] ?? 0;
        return [
            'title' => $toString($data['title'] ?? ''),
            'body' => $toString($data['body'] ?? $data['description'] ?? ''),
            'budget' => is_numeric($budgetInput) ? (string)$budgetInput : '0',
            'target_link' => $toString($data['target_link'] ?? $data['link'] ?? $data['target_url'] ?? ''),
            'image_path' => $toString($data['image_path'] ?? ''),
            'icon' => $toString($data['icon'] ?? 'default_ad_icon'),
            'scheduled_time' => $toString($data['scheduled_time'] ?? ''),
            'site_commission_percent' => $fee,
            'requires_admin_review' => (bool)$this->appSettings->get('notification_ad_requires_admin_review', 1),
        ];
    }
}
