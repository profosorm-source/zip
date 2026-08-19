<?php

declare(strict_types=1);

namespace App\Services;

use App\Contracts\AdSystemContract;
use App\Contracts\AdsRepositoryInterface;
use App\Contracts\LoggerInterface;
use Core\Database;
use RuntimeException;
use App\Services\EscrowService;
use Core\ValueObjects\Money;
use App\Services\Ads\AdsBudgetSettlementService;

/**
 * AdSystemManager — مدیریت یکپارچه تمام سیستم‌های تبلیغاتی با الگوی ساگا و اکسرو
 */
/**
 * @phpstan-type AdCreationInput array<string, mixed>
 * @phpstan-type AdCreationResult array{ad_id: int, escrow_id: int, total_amount: string, currency: string, ...}
 */
class AdSystemManager
{
    /** @var array<string, AdSystemContract> */
    private array $adapters = [];
    private AdsRepositoryInterface $adsRepository;
    private Database $db;
    private LoggerInterface $logger;
    private EscrowService $escrow;
    private \App\Services\SagaOrchestrator $sagaOrchestrator;
    private \App\Contracts\WalletServiceInterface $walletService;
    private AdsBudgetSettlementService $adsBudgetSettlement;

    /** @return ?\stdClass */
    private function toObject(mixed $data): ?\stdClass
    {
        if ($data instanceof \stdClass) return $data;
        if (is_array($data)) return (object)$data;
        return null;
    }

    /** @param array<string, AdSystemContract> $adapters */
    public function __construct(
        Database $db,
        LoggerInterface $logger,
        array $adapters,
        AdsRepositoryInterface $adsRepository,
        EscrowService $escrow,
        \App\Services\SagaOrchestrator $sagaOrchestrator,
        \App\Contracts\WalletServiceInterface $walletService,
        AdsBudgetSettlementService $adsBudgetSettlement
    ) {
        $this->db = $db;
        $this->logger = $logger;
        $this->adapters = $this->setAdapters($adapters);
        $this->adsRepository = $adsRepository;
        $this->escrow = $escrow;
        $this->sagaOrchestrator = $sagaOrchestrator;
        $this->walletService = $walletService;
        $this->adsBudgetSettlement = $adsBudgetSettlement;
    }

    /**
     * @param array<string, AdSystemContract> $adapters
     * @return array<string, AdSystemContract>
     */
    private function setAdapters(array $adapters): array
    {
        foreach ($adapters as $type => $adapter) {
            if (!is_string($type) || !$adapter instanceof AdSystemContract) {
                throw new \InvalidArgumentException('Ad adapter map is invalid');
            }
        }
        return $adapters;
    }

    /** @param AdCreationInput $data */
    private function decimalInput(array $data, string $key, string $fallback = '0'): string
    {
        $value = $data[$key] ?? null;
        return is_scalar($value) && is_numeric((string)$value) ? (string)$value : $fallback;
    }

    /**
     * ایجاد آگهی جدید با امنیت اتمیک (ساگا)
     */
    /**
     * @param AdCreationInput $data
     * @return AdCreationResult
     */
    public function create(string $type, int $userId, array $data): array
    {
        $adapter = $this->getAdapter($type);
        $orchestrator = $this->sagaOrchestrator;
        $walletService = $this->walletService;

        $self = $this;
        // BUGFIX-SAGA-TX-ROOT: قبلاً فقط مرحله‌ی اول (calculate_and_hold_funds) با
        // beginTransaction/commit مجزا محافظت می‌شد؛ مراحل بعدی (create_ad_record،
        // bind_ad_escrow) کاملاً بدون تراکنش بودند. اگر یکی از آن‌ها fail می‌شد، وجه
        // قبلاً از کیف پول کسر و escrow ایجاد شده بود و فقط با جبران‌سازی جداگانه
        // (نه atomic) برگردانده می‌شد. اکنون کل Saga در یک تراکنش واحد اجرا می‌شود.
        $result = $this->db->transactional(function () use ($orchestrator, $walletService, $type, $userId, $data, $self) {
        return $orchestrator
            ->setSaga("ad_creation_{$type}", array_merge($data, [
                'user_id' => $userId,
                'ad_type' => $type
            ]))
            ->addStep(
                'calculate_and_hold_funds',
                function($ctx) use ($self, $userId, $walletService) {
                    $adapter = $self->getAdapter($ctx['ad_type']);
                    $amountStr = $self->decimalInput($ctx, 'total_budget', $self->decimalInput($ctx, 'budget'));
                    if (bccomp($amountStr, '0', 8) <= 0) {
                        $quantityValue = $ctx['total_count'] ?? $ctx['total_quantity'] ?? $ctx['quantity'] ?? 1;
                        $quantity = is_scalar($quantityValue) && is_numeric((string)$quantityValue) ? max(1, (int)$quantityValue) : 1;
                        $price = $self->decimalInput($ctx, 'price_per_task');
                        $amountStr = Money::fromString($price, 'irt')->multiply((string)$quantity)->getAmount();
                    }
                    $currencyValue = $ctx['currency'] ?? 'irt';
                    $currency = is_string($currencyValue) ? strtolower($currencyValue) : 'irt';
                    if ($currency === 'irr' || $currency === 'rial') $currency = 'irt';
                    $money = Money::fromString($amountStr, $currency);
                    $feeMoney = Money::fromString($adapter->calculateCost($amountStr, $ctx), $currency);
                    $total = $money->add($feeMoney)->getAmount();

                    // 1. Create Escrow Record
                    $res = $self->escrow->holdFunds(
                        $ctx['saga_execution_id'], 
                        "ad_creation_{$ctx['ad_type']}",
                        $userId,
                        $userId,
                        (string)$total,
                        $currency
                    );

                    if (empty($res['ok'])) throw new \Core\Exceptions\ApplicationException(is_string($res['error'] ?? null) ? $res['error'] : 'خطا در رزرو وجه');

                    // 2. Anti-fraud check before financial operation
                    assert_fraud_allowed($userId, 'ad.budget_withdraw', ['amount' => (string)$total, 'ad_type' => $ctx['ad_type']]);

                    // 3. Actually deduct funds from wallet
                    $withdrawal = $walletService->withdraw($userId, (string)$total, $currency, [
                        'type' => 'ad_creation_hold',
                        'saga_id' => $ctx['saga_execution_id'],
                        'ad_type' => $ctx['ad_type']
                    ]);

                    if (!$withdrawal['success']) {
                        throw new \Core\Exceptions\InsufficientBalanceException(is_string($withdrawal['message'] ?? null) ? $withdrawal['message'] : 'موجودی کیف پول کافی نیست');
                    }

                    if (!isset($res['escrow_id']) || (!is_int($res['escrow_id']) && !(is_string($res['escrow_id']) && ctype_digit($res['escrow_id'])))) throw new \Core\Exceptions\ApplicationException('شناسه escrow ایجادشده معتبر نیست');
                    return ['escrow_id' => (int)$res['escrow_id'], 'total_amount' => $total, 'currency' => $currency];
                },
                function($err, $res) use ($self, $userId) {
                    // The whole creation saga is inside Database::transactional().
                    // Wallet hold, escrow row and idempotency record are rolled back
                    // together by the outer transaction. Compensating with deposit
                    // or state-only refund here would double-credit balance or leave
                    // locked funds inconsistent.
                    $self->logger->warning('ad_creation.financial_compensation_deferred_to_transaction_rollback', [
                        'user_id' => $userId,
                        'escrow_id' => $res['escrow_id'] ?? null,
                        'error' => $err->getMessage(),
                    ]);
                }
            )
            ->addStep(
                'create_ad_record',
                function($ctx) use ($self, $userId) {
                    $adapter = $self->getAdapter($ctx['ad_type']);
                    // فراخوانی آداپتر برای ایجاد رکورد (آداپترها باید برای عدم مدیریت تراکنش اصلاح شوند)
                    $result = $adapter->create($userId, $ctx);
                    if (empty($result['success'])) throw new \Core\Exceptions\ApplicationException(is_string($result['message'] ?? null) ? $result['message'] : 'خطا در ثبت آگهی');
                    $resultData = is_array($result['data'] ?? null) ? $result['data'] : [];
                    $adId = $resultData['id'] ?? $result['ad_id'] ?? null;
                    if (!is_int($adId) && !(is_string($adId) && ctype_digit($adId))) throw new \Core\Exceptions\ApplicationException('شناسه آگهی ایجادشده معتبر نیست');
                    return ['ad_id' => (int)$adId];
                },
                function($err, $res) use ($self) {
                    if (isset($res['ad_id'])) {
                        $self->adsRepository->update($res['ad_id'], ['status' => 'rejected']);
                    }
                }
            )
            ->addStep(
                'bind_ad_escrow',
                function($ctx) use ($self) {
                    if (!empty($ctx['escrow_id']) && !empty($ctx['ad_id'])) {
                        $orderType = match (is_string($ctx['ad_type'] ?? null) ? $ctx['ad_type'] : '') {
                            'custom_task' => 'custom_task_budget',
                            'seo' => 'seo_ad_budget',
                            'social_task' => 'social_task_budget',
                            'adtube' => 'adtube_budget',
                            'banner' => 'banner_budget',
                            'notification' => 'notification_ad_budget',
                            default => 'ad_creation_' . (is_string($ctx['ad_type'] ?? null) ? $ctx['ad_type'] : 'unknown'),
                        };
                        if (in_array($orderType, ['custom_task_budget', 'seo_ad_budget', 'social_task_budget', 'adtube_budget', 'banner_budget', 'notification_ad_budget'], true)) {
                            $self->db->query(
                                "UPDATE escrow_transactions SET order_id = ?, order_type = ?, updated_at = NOW() WHERE id = ?",
                                [(string)$ctx['ad_id'], $orderType, (int)$ctx['escrow_id']]
                            );
                        }
                    }
                    return [];
                },
                null
            )
            ->execute();
        });
        if (!is_array($result) || !isset($result['ad_id'], $result['escrow_id'], $result['total_amount'], $result['currency'])
            || !is_int($result['ad_id']) || !is_int($result['escrow_id']) || !is_string($result['total_amount']) || !is_string($result['currency'])) {
            throw new \RuntimeException('Ad creation saga returned an invalid result');
        }
        /** @var AdCreationResult $result */
        return $result;
    }

    /**
     * دریافت Adapter برای نوع سیستم
     */
    public function getAdapter(string $type): AdSystemContract
    {
        if (!isset($this->adapters[$type])) {
            throw new RuntimeException("نوع سیستم تبلیغاتی '{$type}' نامعتبر است.");
        }
        return $this->adapters[$type];
    }

    // سایر متدها (بدون تغییر منطق قبلی برای حفظ سازگاری)
    /**
     * @param AdCreationInput $data
     * @return array<string, mixed>
     */
    public function validateAd(string $type, array $data, bool $isUpdate = false): array { return $this->getAdapter($type)->validate($data, $isUpdate); }
    public function isExpired(string $type, int $adId): bool { return $this->getAdapter($type)->isExpired($adId); }
    /** @param AdCreationInput $context */
    public function calculateCost(string $type, string $amount, array $context = []): string { return $this->getAdapter($type)->calculateCost($amount, $context); }
    /** @return list<\stdClass> */
    public function getUserAds(int $userId): array { return $this->adsRepository->where('user_id', '=', $userId)->whereNull('deleted_at')->orderBy('created_at', 'DESC')->get() ?: []; }
    /** @return array{success: bool, message: string, is_active?: int, status?: string} */
    public function toggleAdStatus(int $adId, int $userId): array {
        $ad = $this->toObject($this->adsRepository->find($adId));
        if (!$ad || !isset($ad->id) || (int)$ad->user_id !== $userId) return ['success' => false, 'message' => 'آگهی یافت نشد.'];
        if (in_array((string)$ad->status, ['completed', 'rejected', 'cancelled', 'expired'], true)) {
            return ['success' => false, 'message' => 'این کمپین قابل توقف/فعال‌سازی نیست.'];
        }
        $isPaused = (string)$ad->status === 'paused' || (int)($ad->is_active ?? 1) === 0;
        $newStatus = $isPaused ? 'active' : 'paused';
        $newActive = $isPaused ? 1 : 0;
        $this->adsRepository->update($adId, ['is_active' => $newActive, 'status' => $newStatus, 'updated_at' => date('Y-m-d H:i:s')]);
        return ['success' => true, 'message' => $newActive ? 'کمپین فعال شد.' : 'کمپین متوقف شد.', 'is_active' => $newActive, 'status' => $newStatus];
    }

    /**
     * User cancellation is authorized and settled by the same canonical budget
     * path used by administrator actions and lifecycle reconciliation. This
     * prevents the manager from duplicating wallet/locked/escrow mutations.
     *
     * @return array{success: bool, message: string, refund_amount?: string}
     */
    public function cancelAd(int $adId, int $userId, string $reason = 'لغو توسط تبلیغ‌دهنده'): array
    {
        /** @var array{success: bool, message: string, refund_amount?: string} $result */
        $result = $this->adsBudgetSettlement->refundRemainingBudget(
            $adId,
            'cancelled',
            $reason,
            'user_' . $userId,
            $userId
        );
        return $result;
    }

    /**
     * دریافت آمار خلاصه آگهی‌های کاربر (برای داشبورد تبلیغ‌دهنده)
     */
    /** @return array<string, mixed> */
    public function getAdSummary(int $userId): array
    {
        // Compatibility: older installations may not have the phase-4 spent_budget column yet.
        // UI summary must not fail before migrations are applied; fall back to total_budget - remaining_budget.
        $spentExpr = $this->adsColumnExists('spent_budget')
            ? 'COALESCE(spent_budget, total_budget - remaining_budget)'
            : '(total_budget - remaining_budget)';

        $stats = $this->toObject($this->db->fetch(
            "SELECT 
                COUNT(*) AS total,
                SUM(CASE WHEN status IN ('active','approved') THEN 1 ELSE 0 END) AS active,
                SUM(CASE WHEN status IN ('pending','pending_review') THEN 1 ELSE 0 END) AS pending,
                SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) AS completed,
                SUM(CASE WHEN status = 'paused' THEN 1 ELSE 0 END) AS paused,
                SUM(CASE WHEN status IN ('rejected','cancelled','expired') THEN 1 ELSE 0 END) AS closed,
                COALESCE(SUM(total_budget), 0) AS total_budget,
                COALESCE(SUM(remaining_budget), 0) AS remaining_budget,
                COALESCE(SUM({$spentExpr}), 0) AS spent_budget,
                COALESCE(SUM(impressions), 0) AS total_impressions,
                COALESCE(SUM(clicks), 0) AS total_clicks
             FROM ads WHERE user_id = ? AND deleted_at IS NULL",
            [$userId]
        ));
        return [
            'total'             => (int)($stats->total ?? 0),
            'active'            => (int)($stats->active ?? 0),
            'pending'           => (int)($stats->pending ?? 0),
            'completed'         => (int)($stats->completed ?? 0),
            'paused'            => (int)($stats->paused ?? 0),
            'closed'            => (int)($stats->closed ?? 0),
            'total_budget'      => floatval($stats->total_budget ?? 0),
            'total_invested'    => floatval($stats->total_budget ?? 0),
            'remaining_budget'  => floatval($stats->remaining_budget ?? 0),
            'spent_budget'      => floatval($stats->spent_budget ?? 0),
            'total_impressions' => (int)($stats->total_impressions ?? 0),
            'total_clicks'      => (int)($stats->total_clicks ?? 0),
        ];
    }

    private function adsColumnExists(string $column): bool
    {
        static $cache = [];
        $column = (string)preg_replace('/[^a-zA-Z0-9_]/', '', $column);
        if ($column === '') return false;
        if (array_key_exists($column, $cache)) return $cache[$column];
        try {
            $exists = (int)$this->db->fetchColumn(
                "SELECT COUNT(*) FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'ads' AND COLUMN_NAME = ?",
                [$column]
            ) > 0;
            return $cache[$column] = $exists;
        } catch (\Throwable) {
            return $cache[$column] = false;
        }
    }

    /**
     * دریافت اجراها/کلیک‌ها/submissionهای یک آگهی بر اساس نوع
     */
    /** @return list<\stdClass> */
    public function getAdExecutions(int $adId, string $type): array
    {
        try {
            return match ($type) {
                'custom_task' => $this->db->query(
                    "SELECT s.*, u.username, u.full_name, COALESCE(u.full_name, u.username, CONCAT('کاربر #', s.worker_id)) AS executor 
                     FROM custom_task_submissions s
                     LEFT JOIN users u ON u.id = s.worker_id
                     WHERE s.task_id = ? ORDER BY s.created_at DESC LIMIT 100",
                    [$adId]
                )->fetchAll(\PDO::FETCH_OBJ) ?: [],
                'social_task' => $this->db->query(
                    "SELECT e.*, u.username, u.full_name
                     FROM social_task_executions e
                     LEFT JOIN users u ON u.id = e.executor_id
                     WHERE e.ad_id = ? ORDER BY e.created_at DESC LIMIT 100",
                    [$adId]
                )->fetchAll(\PDO::FETCH_OBJ) ?: [],
                'seo' => $this->db->query(
                    "SELECT ex.*, u.username, u.full_name
                     FROM seo_executions ex
                     LEFT JOIN users u ON u.id = ex.user_id
                     WHERE ex.ad_id = ? ORDER BY ex.created_at DESC LIMIT 100",
                    [$adId]
                )->fetchAll(\PDO::FETCH_OBJ) ?: [],
                'adtube' => $this->db->query(
                    "SELECT av.*, u.username, u.full_name
                     FROM adtube_views av
                     LEFT JOIN users u ON u.id = av.executor_id
                     WHERE av.ad_id = ? ORDER BY av.created_at DESC LIMIT 100",
                    [$adId]
                )->fetchAll(\PDO::FETCH_OBJ) ?: [],
                'banner' => $this->db->query(
                    "SELECT bc.*, u.username, u.full_name
                     FROM banner_clicks bc
                     LEFT JOIN users u ON u.id = bc.user_id
                     WHERE bc.banner_id = ? ORDER BY bc.clicked_at DESC LIMIT 100",
                    [$adId]
                )->fetchAll(\PDO::FETCH_OBJ) ?: [],
                default => [],
            };
        } catch (\Throwable $e) {
            $this->logger->warning('ad_system.get_executions_failed', [
                'ad_id' => $adId, 'type' => $type, 'error' => $e->getMessage()
            ]);
            return [];
        }
    }
}
