<?php

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

class SeoAdAdapter extends AdapterBase implements AdSystemContract
{
    private Ads $adModel;
    private WalletServiceInterface $walletService;

    public function __construct(
        Ads $adModel,
        WalletServiceInterface $walletService,
        Database $db,
        LoggerInterface $logger,
        AppSettings $appSettings,
        ValidatorFactoryInterface $validatorFactory,
        IdempotencyService $idempotencyService
    ) {
        $this->adModel = $adModel;
        $this->walletService = $walletService;
        unset($db, $idempotencyService);
        parent::__construct($logger, $appSettings, $validatorFactory);
    }

    public function getType(): string { return 'seo'; }

    public function create(int $userId, array $data): array
    {
        $data = $this->normalize($data);
        $validation = $this->validate($data);
        if (!$validation['valid']) {
            return $this->errorResponse('ورودی‌های تبلیغ سئو معتبر نیستند', $validation['errors']);
        }

        try {
            $adId = $this->adModel->create([
                'user_id' => $userId,
                'type' => 'seo',
                'title' => $data['title'],
                'site_url' => $data['site_url'],
                'target_url' => $data['target_url'],
                'link' => $data['target_url'],
                'keyword' => $data['keyword'],
                'description' => $data['description'] ?: null,
                'budget' => $data['budget'],
                'total_budget' => $data['budget'],
                'remaining_budget' => $data['budget'],
                'min_payout' => $data['min_payout'],
                'max_payout' => $data['max_payout'],
                'target_duration' => $data['target_duration'],
                'min_score' => $data['min_score'],
                'max_per_day' => $data['max_per_day'],
                'currency' => $data['currency'],
                'site_commission_percent' => $data['site_commission_percent'],
                'status' => $data['requires_admin_review'] ? 'pending_review' : 'active',
                'created_at' => date('Y-m-d H:i:s'),
                'updated_at' => date('Y-m-d H:i:s'),
            ]);

            if (!$adId) {
                return $this->errorResponse('خطا در ایجاد رکورد SEO');
            }
            $id = is_object($adId) ? (is_numeric(get_object_vars($adId)['id'] ?? null) ? (int)get_object_vars($adId)['id'] : 0) : (is_numeric($adId) ? (int)$adId : 0);
            return $this->successResponse('تبلیغ SEO ایجاد شد', ['id' => $id, 'ad_id' => $id]);
        } catch (\Throwable $e) {
            $this->logError('create', $e->getMessage());
            return $this->errorResponse('خطا در ایجاد تبلیغ SEO: ' . $e->getMessage());
        }
    }

    public function isExpired(int $adId): bool
    {
        $ad = $this->adModel->find($adId);
        if (!is_object($ad)) return true;
        $vars = get_object_vars($ad);
        $status = is_string($vars['status'] ?? null) ? $vars['status'] : '';
        $remaining = is_numeric($vars['remaining_budget'] ?? null) ? (float)$vars['remaining_budget'] : 0.0;
        return $status === 'expired' || $remaining <= 0;
    }

    public function calculateCost(string $amount, array $context = []): string
    {
        $feeValue = $this->appSettings->get('seo_ad_site_fee_percent', 15);
        $feePercent = is_numeric($feeValue) ? (string)$feeValue : '15';
        $currency = is_string($context['currency'] ?? null) ? $context['currency'] : 'irt';
        // float→decimal: محاسبهٔ کارمزد با Money/BCMath به‌جای float
        return Money::fromString($amount, $currency)->percentage($feePercent)->getAmount();
    }

    public function processPayment(int $adId, int $userId, string $amount, string $currency): array
    {
        $this->assertFraudAllowed($userId, 'ad.payment', ['amount' => $amount, 'currency' => $currency, 'ad_id' => $adId]);
        $result = $this->walletService->withdraw($userId, $amount, $currency, ['type' => 'seo_payment']);
        return $result ? $this->successResponse('پرداخت موفق', ['transaction_id' => $result]) : $this->errorResponse('خطا در پرداخت');
    }

    public function track(int $adId, string $eventType, ?int $userId = null): array
    {
        $this->logInfo('track', ['event' => $eventType, 'ad_id' => $adId, 'user_id' => $userId]);
        return $this->successResponse('رویداد ثبت شد');
    }

    public function getStatus(int $adId): ?array
    {
        $ad = $this->adModel->find($adId);
        if (!is_object($ad)) return null;
        $vars = get_object_vars($ad);
        return ['id' => is_numeric($vars['id'] ?? null) ? (int)$vars['id'] : 0, 'type' => 'seo', 'status' => is_string($vars['status'] ?? null) ? $vars['status'] : 'unknown'];
    }

    public function validate(array $data, bool $isUpdate = false): array
    {
        $data = $this->normalize($data);
        $errors = [];

        if (mb_strlen($data['title']) < 3) $errors[] = 'عنوان کمپین الزامی است.';
        if (!filter_var($data['site_url'], FILTER_VALIDATE_URL)) $errors[] = 'آدرس سایت معتبر نیست.';
        if (mb_strlen($data['keyword']) < 2) $errors[] = 'کلمه کلیدی الزامی است.';
        if (bccomp($data['budget'], '0', 8) <= 0) $errors[] = 'بودجه کل نامعتبر است.';
        if (bccomp($data['min_payout'], '0', 8) <= 0) $errors[] = 'حداقل پرداخت نامعتبر است.';
        if (bccomp($data['max_payout'], $data['min_payout'], 8) < 0) $errors[] = 'حداکثر پرداخت باید بیشتر یا برابر حداقل پرداخت باشد.';
        if (bccomp($data['budget'], $data['min_payout'], 8) < 0) $errors[] = 'بودجه باید حداقل به اندازه حداقل پرداخت باشد.';
        if ($data['target_duration'] < 10 || $data['target_duration'] > 900) $errors[] = 'زمان هدف باید بین ۱۰ تا ۹۰۰ ثانیه باشد.';
        if ($data['min_score'] < 1 || $data['min_score'] > 100) $errors[] = 'حداقل امتیاز باید بین ۱ تا ۱۰۰ باشد.';

        return ['valid' => empty($errors), 'errors' => $errors];
    }

    /**
     * @param array<string, mixed> $data
     * @return array{
     *   title: string,
     *   site_url: string,
     *   target_url: string,
     *   keyword: string,
     *   description: string,
     *   budget: string,
     *   min_payout: string,
     *   max_payout: string,
     *   target_duration: int,
     *   min_score: int,
     *   max_per_day: int,
     *   currency: string,
     *   site_commission_percent: string,
     *   requires_admin_review: bool
     * }
     */
    private function normalize(array $data): array
    {
        $toString = static fn(mixed $v): string => is_scalar($v) ? trim((string)$v) : '';
        $toInt = static fn(mixed $v): int => is_numeric($v) ? (int)$v : 0;
        $currency = strtolower($toString($data['currency'] ?? 'irt'));
        if (in_array($currency, ['irr', 'rial'], true)) $currency = 'irt';
        if (!in_array($currency, ['irt', 'usdt'], true)) $currency = 'irt';

        $siteUrl = $toString($data['site_url'] ?? $data['target_link'] ?? $data['target_url'] ?? $data['link'] ?? '');
        $targetUrl = $toString($data['target_url'] ?? $data['target_link'] ?? $data['site_url'] ?? $data['link'] ?? $siteUrl);
        // float→decimal: مبالغ به‌صورت رشتهٔ decimal نگه داشته می‌شوند
        $budgetInput = $data['budget'] ?? $data['total_budget'] ?? 0;
        $budget = is_numeric($budgetInput) ? (string)$budgetInput : '0';
        $minPayoutInput = $data['min_payout'] ?? $data['price_per_click'] ?? $data['price_per_task'] ?? 0;
        $minPayout = is_numeric($minPayoutInput) ? (string)$minPayoutInput : '0';
        $maxPayoutInput = $data['max_payout'] ?? null;
        if (is_numeric($maxPayoutInput)) {
            $maxPayout = (string)$maxPayoutInput;
        } else {
            $ppc = is_numeric($data['price_per_click'] ?? null) ? (string)$data['price_per_click'] : '0';
            $maxPayout = bccomp($minPayout, $ppc, 8) >= 0 ? $minPayout : $ppc;
        }
        $feeValue = $this->appSettings->get('seo_ad_site_fee_percent', 15);
        $feePercent = is_numeric($feeValue) ? (string)$feeValue : '15';

        return [
            'title' => $toString($data['title'] ?? ''),
            'site_url' => $siteUrl,
            'target_url' => $targetUrl,
            'keyword' => $toString($data['keyword'] ?? ''),
            'description' => $toString($data['description'] ?? ''),
            'budget' => $budget,
            'min_payout' => $minPayout,
            'max_payout' => $maxPayout,
            'target_duration' => max(10, min(900, $toInt($data['target_duration'] ?? 60))),
            'min_score' => max(1, min(100, $toInt($data['min_score'] ?? 40))),
            'max_per_day' => max(1, min(1000, $toInt($data['max_per_day'] ?? 10))),
            'currency' => $currency,
            'site_commission_percent' => $feePercent,
            'requires_admin_review' => (bool)$this->appSettings->get('seo_ad_requires_admin_review', 0),
        ];
    }
}
