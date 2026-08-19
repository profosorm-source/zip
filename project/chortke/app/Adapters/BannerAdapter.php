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
 * BannerAdapter - creates Banner ad records only.
 * Financial hold/withdraw is handled centrally by AdSystemManager / Saga.
 */
class BannerAdapter extends AdapterBase implements AdSystemContract
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
        // Kept for constructor compatibility; settlement is centralized in
        // AdSystemManager and these legacy dependencies are intentionally not retained.
        unset($walletService, $db, $idempotencyService);
        parent::__construct($logger, $appSettings, $validatorFactory);
    }

    public function getType(): string
    {
        return 'banner';
    }

    public function create(int $userId, array $data): array
    {
        $data = $this->normalize($data);
        $valid = $this->validate($data, false);
        if (!$valid['valid']) {
            return $this->errorResponse('ورودی‌های بنر معتبر نیستند', $valid['errors']);
        }

        try {
            $adId = $this->adModel->create([
                'user_id'                 => $userId,
                'type'                    => 'banner',
                'title'                   => $data['title'],
                'description'             => $data['description'] ?: null,
                'link'                    => $data['link'],
                'target_url'              => $data['link'],
                'image_path'              => $data['image_path'] ?? null,
                'placement'               => $data['placement'],
                'price_per_task'          => 0,
                'currency'                => $data['currency'],
                'total_budget'            => $data['total_budget'],
                'remaining_budget'        => $data['total_budget'],
                'total_count'             => 0,
                'remaining_count'         => 0,
                'pending_count'           => 0,
                'completed_count'         => 0,
                'site_commission_percent' => $data['site_commission_percent'],
                'status'                  => $data['requires_admin_review'] ? 'pending_review' : 'active',
                'restrictions'            => json_encode([
                    'is_startup' => $data['is_startup'],
                    'target_devices' => $data['target_devices'],
                    'dimensions' => $this->inferDimensions($data['placement']),
                ], JSON_UNESCAPED_UNICODE),
                'created_by'              => $userId,
                'created_at'              => date('Y-m-d H:i:s'),
                'updated_at'              => date('Y-m-d H:i:s'),
            ]);

            if (!$adId) {
                return $this->errorResponse('خطا در نهایی‌سازی تبلیغ بنری.');
            }
            $id = 0;
            if (is_object($adId)) {
                $vars = get_object_vars($adId);
                $id = is_numeric($vars['id'] ?? null) ? (int)$vars['id'] : 0;
            } elseif (is_numeric($adId)) {
                $id = (int)$adId;
            }

            return $this->successResponse('تبلیغ بنری با موفقیت ثبت شد.', ['id' => $id, 'ad_id' => $id]);
        } catch (\Throwable $e) {
            $this->logError('banner_creation_fail', $e->getMessage());
            return $this->errorResponse('سیستم در حال حاضر امکان ثبت بنر ندارد: ' . $e->getMessage());
        }
    }

    public function validate(array $data, bool $isUpdate = false): array
    {
        $data = $this->normalize($data);
        $errors = [];

        if (mb_strlen($data['title']) < 3) {
            $errors[] = 'عنوان تبلیغ الزامی است.';
        }
        if ($data['placement'] === '') {
            $errors[] = 'انتخاب جایگاه تبلیغ الزامی است.';
        }
        if (!$isUpdate && empty($data['image_path'])) {
            $errors[] = 'فایل تصویر بنر الزامی است.';
        }
        if (!filter_var($data['link'], FILTER_VALIDATE_URL)) {
            $errors[] = 'لینک مقصد معتبر نیست.';
        }
        $minBudgetValue = $this->appSettings->get('banner_min_budget', 100);
        $minBudget = is_numeric($minBudgetValue) ? (string)$minBudgetValue : '100';
        if (bccomp($data['total_budget'], $minBudget, 8) < 0) {
            $errors[] = "حداقل بودجه بنر {$minBudget} تومان است.";
        }

        return ['valid' => empty($errors), 'errors' => $errors];
    }

    public function isExpired(int $adId): bool
    {
        $ad = $this->adModel->find($adId);
        if (!is_object($ad)) return true;
        $vars = get_object_vars($ad);
        $status = is_string($vars['status'] ?? null) ? $vars['status'] : '';
        $remaining = is_numeric($vars['remaining_budget'] ?? null) ? (float)$vars['remaining_budget'] : 0.0;
        return in_array($status, ['expired', 'completed'], true) || $remaining <= 0;
    }

    public function calculateCost(string $amount, array $context = []): string
    {
        $placementValue = $context['placement'] ?? 'general';
        $placement = is_string($placementValue) ? $placementValue : 'general';
        $isStartup = filter_var($context['is_startup'] ?? false, FILTER_VALIDATE_BOOLEAN);
        $feePercent = $this->calculateDynamicFeePercent($placement, $isStartup);
        $currency = is_string($context['currency'] ?? null) ? $context['currency'] : 'irt';
        // float→decimal: محاسبهٔ کارمزد با Money/BCMath به‌جای float
        return Money::fromString($amount, $currency)->percentage($feePercent)->getAmount();
    }

    public function processPayment(int $adId, int $userId, string $amount, string $currency): array
    {
        return $this->successResponse('پرداخت باا بودجه اولیه و escrow مرکزی مدیریت می‌شود.');
    }

    public function track(int $adId, string $eventType, ?int $userId = null): array
    {
        $finance = $this->adsBudgetSettlementService;
        $result = $finance->consumeDeliveryBudget($adId, 'banner', $eventType === 'click' ? 'click' : 'impression', 1, $userId, [
            'source' => 'banner_adapter.track',
        ]);
        return !empty($result['success'])
            ? $this->successResponse('آمار و مصرف بودجه بنر با موفقیت ثبت شد.', $result)
            : $this->errorResponse(is_string($result['message'] ?? null) ? $result['message'] : 'ثبت آمار بنر انجام نشد.');
    }

    public function getStatus(int $adId): ?array
    {
        $banner = $this->adModel->find($adId);
        if (!$banner) return null;
        $vars = get_object_vars($banner);
        return [
            'id' => is_numeric($vars['id'] ?? null) ? (int)$vars['id'] : 0,
            'type' => 'banner',
            'status' => is_string($vars['status'] ?? null) ? $vars['status'] : 'unknown',
            'budget_left' => is_numeric($vars['remaining_budget'] ?? null) ? (float)$vars['remaining_budget'] : 0.0
        ];
    }

    /**
     * @param array<string, mixed> $data
     * @return array{
     *   title: string,
     *   description: string,
     *   link: string,
     *   image_path: string,
     *   placement: string,
     *   is_startup: bool,
     *   target_devices: list<string>,
     *   total_budget: string,
     *   currency: string,
     *   site_commission_percent: string,
     *   requires_admin_review: bool
     * }
     */
    private function normalize(array $data): array
    {
        $currencyValue = $data['currency'] ?? 'irt';
        $currency = strtolower(is_scalar($currencyValue) ? (string)$currencyValue : 'irt');
        if (in_array($currency, ['irr', 'rial'], true)) $currency = 'irt';
        if (!in_array($currency, ['irt', 'usdt'], true)) $currency = 'irt';

        $placementValue = $data['placement'] ?? 'general';
        $placement = is_scalar($placementValue) ? trim((string)$placementValue) : 'general';
        $isStartup = $this->parseBoolean($data['is_startup'] ?? false, 'is_startup');
        $budgetValue = $data['budget'] ?? $data['total_budget'] ?? 0;
        // float→decimal: بودجه به‌صورت رشتهٔ decimal
        $budget = is_numeric($budgetValue) ? (string)$budgetValue : '0';
        $feePercent = $this->calculateDynamicFeePercent($placement, $isStartup);

        $targetDevices = $this->normalizeTargetDevices($data['target_devices'] ?? null);

        $linkValue = $data['link'] ?? $data['target_url'] ?? null;
        $link = is_scalar($linkValue) ? trim((string)$linkValue) : '';

        return [
            'title' => is_scalar($data['title'] ?? null) ? trim((string)$data['title']) : '',
            'description' => is_scalar($data['description'] ?? null) ? trim((string)$data['description']) : '',
            'link' => $link,
            'image_path' => is_scalar($data['image_path'] ?? null) ? trim((string)$data['image_path']) : '',
            'placement' => $placement,
            'is_startup' => $isStartup,
            'target_devices' => $targetDevices,
            'total_budget' => $budget,
            'currency' => $currency,
            'site_commission_percent' => $feePercent,
            'requires_admin_review' => $this->parseBoolean(
                $this->appSettings->get('banner_requires_admin_review', true),
                'banner_requires_admin_review'
            ),
        ];
    }

    /**
     * Parse booleans at the input/configuration boundary without silently
     * converting malformed values to false.
     */
    private function parseBoolean(mixed $value, string $field): bool
    {
        if (is_bool($value)) {
            return $value;
        }
        if (is_int($value) && ($value === 0 || $value === 1)) {
            return $value === 1;
        }
        if (is_string($value)) {
            $normalized = strtolower(trim($value));
            if ($normalized === 'true' || $normalized === '1') return true;
            if ($normalized === 'false' || $normalized === '0') return false;
        }
        throw new \InvalidArgumentException("{$field} must be a boolean.");
    }

    /** @return list<string> */
    private function normalizeTargetDevices(mixed $value): array
    {
        if ($value === null || $value === '') {
            return ['web', 'mobile'];
        }
        if (is_string($value)) {
            $decoded = json_decode($value, true);
            if (!is_array($decoded)) {
                throw new \InvalidArgumentException('target_devices must be a JSON array.');
            }
            $value = $decoded;
        }
        if (!is_array($value)) {
            throw new \InvalidArgumentException('target_devices must be an array of strings.');
        }

        $devices = [];
        foreach ($value as $device) {
            if (!is_string($device) || trim($device) === '') {
                throw new \InvalidArgumentException('target_devices must contain only non-empty strings.');
            }
            $devices[] = strtolower(trim($device));
        }
        return array_values(array_unique($devices));
    }

    private function calculateDynamicFeePercent(string $placement, bool $isStartup): string
    {
        $standardValue = $this->appSettings->get('banner_fee_percent', 12);
        $standardFee = is_numeric($standardValue) ? (string)$standardValue : '12';
        if ($placement === 'homepage_slider' && $isStartup) {
            $startupValue = $this->appSettings->get('banner_startup_slider_fee_percent', 2);
            return is_numeric($startupValue) ? (string)$startupValue : '2';
        }
        return $standardFee;
    }

    /** @return array{width: int, height: int} */
    private function inferDimensions(string $placement): array
    {
        return match($placement) {
            'homepage_slider' => ['width' => 1920, 'height' => 600],
            'sidebar'        => ['width' => 300, 'height' => 600],
            'footer'         => ['width' => 728, 'height' => 90],
            default          => ['width' => 800, 'height' => 400],
        };
    }
}
