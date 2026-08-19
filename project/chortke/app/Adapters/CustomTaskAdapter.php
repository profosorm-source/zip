<?php

namespace App\Adapters;

use Core\ValueObjects\Money;
use App\Contracts\AdSystemContract;
use App\Contracts\LoggerInterface;
use App\Contracts\ValidatorFactoryInterface;
use App\Models\Ads;
use App\Contracts\WalletServiceInterface;
use Core\Database;
use App\Services\Settings\AppSettings;

/**
 * CustomTaskAdapter — creates the Ads row only. Financial hold is handled by AdSystemManager.
 */
class CustomTaskAdapter extends AdapterBase implements AdSystemContract
{
    private Ads $taskModel;

    public function __construct(
        Ads $taskModel,
        WalletServiceInterface $walletService,
        Database $db,
        LoggerInterface $logger,
        AppSettings $appSettings,
        ValidatorFactoryInterface $validatorFactory
    ) {
        $this->taskModel = $taskModel;
        unset($walletService, $db);
        parent::__construct($logger, $appSettings, $validatorFactory);
    }

    public function getType(): string { return 'custom_task'; }

    public function create(int $userId, array $data): array
    {
        try {
            $normalized = $this->normalize($data);
            $validation = $this->validate($normalized);
            if (!$validation['valid']) {
                return $this->errorResponse('اطلاعات تسک سفارشی معتبر نیست.', $validation['errors']);
            }

            $taskId = $this->taskModel->create([
                'user_id' => $userId,
                'type' => 'custom_task',
                'title' => $normalized['title'],
                'description' => $normalized['description'],
                'link' => $normalized['link'] ?: null,
                'target_url' => $normalized['link'] ?: null,
                'task_type' => $normalized['task_type'],
                'proof_type' => $normalized['proof_type'],
                'proof_description' => $normalized['proof_description'],
                'proof_schema' => json_encode($normalized['proof_schema'], JSON_UNESCAPED_UNICODE),
                'price_per_task' => $normalized['price_per_task'],
                'currency' => $normalized['currency'],
                'total_budget' => $normalized['total_budget'],
                'remaining_budget' => $normalized['total_budget'],
                'total_count' => $normalized['total_count'],
                'remaining_count' => $normalized['total_count'],
                'pending_count' => 0,
                'completed_count' => 0,
                'deadline_hours' => $normalized['deadline_hours'],
                'device_restriction' => $normalized['device_restriction'],
                'site_commission_percent' => $normalized['site_commission_percent'],
                'auto_approve_hours' => $normalized['auto_approve_hours'],
                'reject_rules' => json_encode($normalized['reject_rules'], JSON_UNESCAPED_UNICODE),
                'restrictions' => json_encode([
                    'daily_limit_per_user' => $normalized['daily_limit_per_user'],
                    'device_restriction' => $normalized['device_restriction'],
                    'proof_type' => $normalized['proof_type'],
                ], JSON_UNESCAPED_UNICODE),
                'status' => $normalized['requires_admin_review'] ? 'pending_review' : 'active',
                'created_at' => date('Y-m-d H:i:s'),
                'updated_at' => date('Y-m-d H:i:s'),
            ]);

            if (!$taskId) {
                return $this->errorResponse('خطا در ایجاد رکورد دیتابیس');
            }
                    $id = 0;
            if (is_object($taskId)) {
                $vars = get_object_vars($taskId);
                $id = is_numeric($vars['id'] ?? null) ? (int)$vars['id'] : 0;
            } elseif (is_numeric($taskId)) {
                $id = (int)$taskId;
            }
            return $this->successResponse('تسک سفارشی ایجاد شد', ['id' => $id, 'ad_id' => $id]);
        } catch (\Throwable $e) {
            $this->logError('create', $e->getMessage(), ['user_id' => $userId]);
            return $this->errorResponse('خطای سیستمی در ایجاد تسک سفارشی رخ داد.');
        }
    }

    public function validate(array $data, bool $isUpdate = false): array
    {
        $data = $this->normalize($data);
        $errors = [];

        if (mb_strlen($data['title']) < 5) $errors[] = 'عنوان تسک باید حداقل ۵ کاراکتر باشد.';
        if (mb_strlen($data['description']) < 20) $errors[] = 'شرح تسک باید حداقل ۲۰ کاراکتر باشد.';
        if (bccomp($data['price_per_task'], '0', 8) <= 0) $errors[] = 'پاداش هر تسک نامعتبر است.';
        if ($data['total_count'] <= 0) $errors[] = 'تعداد درخواست باید حداقل ۱ باشد.';
        if (!in_array($data['currency'], ['irt', 'usdt'], true)) $errors[] = 'ارز انتخاب‌شده معتبر نیست.';
        if (!in_array($data['proof_type'], ['screenshot', 'text', 'video', 'code', 'file', 'url'], true)) $errors[] = 'نوع مدرک معتبر نیست.';
        if (mb_strlen($data['proof_description']) < 5) $errors[] = 'دستورالعمل مدرک باید مشخص باشد.';

        $minPriceValue = $data['currency'] === 'usdt'
            ? $this->appSettings->get('custom_task_min_price_usdt', 0.5)
            : $this->appSettings->get('custom_task_min_price_irt', 5000);
        $minPrice = is_numeric($minPriceValue) ? (string)$minPriceValue : '0';
        if (bccomp($data['price_per_task'], $minPrice, 8) < 0) {
            $errors[] = 'پاداش هر تسک کمتر از حداقل مجاز است.';
        }

        return ['valid' => empty($errors), 'errors' => $errors];
    }

    public function calculateCost(string $amount, array $context = []): string
    {
        $baseAmount = is_numeric($amount) ? (string)$amount : '0';
        if (bccomp($baseAmount, '0', 8) <= 0) {
            $normalized = $this->normalize($context);
            $baseAmount = (string)$normalized['total_budget'];
        }
        $feePercentValue = $this->appSettings->get('custom_task_site_fee_percent', 10);
        $feePercent = is_numeric($feePercentValue) ? (string)$feePercentValue : '10';
        $currency = is_string($context['currency'] ?? null) ? $context['currency'] : 'irt';
        // float→decimal: محاسبهٔ کارمزد با Money/BCMath به‌جای float
        return Money::fromString($baseAmount, $currency)->percentage($feePercent)->getAmount();
    }

    /**
     * @param array<string, mixed> $data
     * @return array{
     *   title: string,
     *   description: string,
     *   link: string,
     *   task_type: string,
     *   proof_type: string,
     *   proof_description: string,
     *   proof_schema: array<string, mixed>,
     *   price_per_task: string,
     *   currency: string,
     *   total_count: int,
     *   total_budget: string,
     *   deadline_hours: int,
     *   device_restriction: string,
     *   daily_limit_per_user: int,
     *   site_commission_percent: string,
     *   auto_approve_hours: int,
     *   reject_rules: array<string, mixed>,
     *   requires_admin_review: bool
     * }
     */
    private function normalize(array $data): array
    {
        $toString = static fn(mixed $value): string => is_scalar($value) ? trim((string)$value) : '';
        $toInt = static fn(mixed $value): int => is_numeric($value) ? (int)$value : 0;
        $currency = strtolower($toString($data['currency'] ?? 'irt'));
        if ($currency === 'irr' || $currency === 'rial') $currency = 'irt';
        // float→decimal: قیمت به‌صورت رشتهٔ decimal
        $priceInput = $data['price_per_task'] ?? 0;
        $price = is_numeric($priceInput) ? (string)$priceInput : '0';
        $count = $toInt($data['total_count'] ?? $data['total_quantity'] ?? $data['quantity'] ?? 1);
        $proofType = strtolower($toString($data['proof_type'] ?? 'text'));
        $feePercentValue = $this->appSettings->get('custom_task_site_fee_percent', 10);
        $feePercent = is_numeric($feePercentValue) ? (string)$feePercentValue : '10';

        return [
            'title' => $toString($data['title'] ?? ''),
            'description' => $toString($data['description'] ?? ''),
            'link' => $toString($data['link'] ?? $data['target_link'] ?? $data['target_url'] ?? ''),
            'task_type' => strtolower($toString($data['task_type'] ?? 'custom')),
            'proof_type' => $proofType,
            'proof_description' => $toString($data['proof_description'] ?? $data['instructions'] ?? 'مدرک انجام تسک را طبق توضیح تسک ارسال کنید.'),
            'proof_schema' => [
                'type' => $proofType,
                'required' => true,
                'description' => $toString($data['proof_description'] ?? ''),
            ],
            'price_per_task' => $price,
            'currency' => $currency,
            'total_count' => max(1, $count),
            'total_budget' => Money::fromString($price, $currency)->multiply((string)max(1, $count))->getAmount(),
            'deadline_hours' => max(1, min(168, $toInt($data['deadline_hours'] ?? 24))),
            'device_restriction' => $toString($data['device_restriction'] ?? 'all'),
            'daily_limit_per_user' => max(1, $toInt($data['daily_limit_per_user'] ?? 1)),
            'site_commission_percent' => $feePercent,
            'auto_approve_hours' => $toInt($this->appSettings->get('custom_task_auto_approve_hours', 48)),
            'reject_rules' => [],
            'requires_admin_review' => (bool)$this->appSettings->get('custom_task_requires_admin_review', 0),
        ];
    }

    public function isExpired(int $adId): bool { return false; }
    public function processPayment(int $adId, int $userId, string $amount, string $currency): array { return []; }
    public function track(int $adId, string $eventType, ?int $userId = null): array { return []; }
    public function getStatus(int $adId): ?array { return null; }
}
