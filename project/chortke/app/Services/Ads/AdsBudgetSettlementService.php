<?php

declare(strict_types=1);

namespace App\Services\Ads;

use App\Contracts\LoggerInterface;
use App\Domain\Financial\Services\FinancialEscrowService;
use App\Services\Settings\AppSettings;
use App\Contracts\WalletServiceInterface;
use Core\Database;
use Core\ValueObjects\Money;
use PDO;

/**
 * AdsBudgetSettlementService
 *
 * INTERNAL_API — coordinator تخصصی بودجه و delivery تبلیغات.
 * این سرویس جایگزین FinancialEscrowService نیست و نباید منطق عمومی escrow/wallet
 * یا قوانین غیرتبلیغاتی را در خود نگه دارد. عملیات عمومی مالی باید تا حد امکان
 * به FinancialEscrowService / EscrowService / WalletService واگذار شود.
 *
 * مسئولیت‌های مجاز این سرویس:
 * - نمایش snapshot مالی/escrow/refund برای تبلیغ‌دهنده و ادمین
 * - orchestrate کردن actionهای ادمین روی ads با refund type-aware
 * - مصرف بودجه بعد از delivery واقعی برای banner / notification / adtube
 *
 * نکته معماری: برای banner/notification درآمد مستقیم به کیف پول کاربر دیگری واریز نمی‌شود؛
 * مبلغ مصرف‌شده + کارمزد متناظر از locked wallet تبلیغ‌دهنده آزاد و به عنوان spend/revenue
 * در delivery event و transaction ثبت می‌شود. برای AdTube، پاداش مجری همزمان واریز می‌شود.
 */
class AdsBudgetSettlementService
{
    /** @return ?\stdClass */
    private function toObject(mixed $data): ?\stdClass
    {
        if ($data instanceof \stdClass) return $data;
        if (is_array($data)) return (object)$data;
        return null;
    }

    private function decimalValue(mixed $value, string $default = '0'): string
    {
        if (!is_scalar($value) || !is_numeric((string)$value)) return $default;
        return (string)$value;
    }

    private function decimalSetting(string $key, string $default): string
    {
        return $this->decimalValue($this->settings->get($key, $default), $default);
    }

    private function rowString(object $row, string $field, string $default = ''): string
    {
        $value = get_object_vars($row)[$field] ?? null;
        return is_scalar($value) ? (string)$value : $default;
    }

    private function rowInt(object $row, string $field, int $default = 0): int
    {
        $value = get_object_vars($row)[$field] ?? null;
        if (is_int($value)) return $value;
        if (is_string($value) && preg_match('/^-?\d+$/', $value) === 1) return (int)$value;
        return $default;
    }

    private function requiredRowInt(object $row, string $field): int
    {
        $value = get_object_vars($row)[$field] ?? null;
        if (is_int($value) && $value > 0) return $value;
        if (is_string($value) && ctype_digit($value) && (int)$value > 0) return (int)$value;
        throw new \UnexpectedValueException("Ads row does not contain a positive integer {$field}");
    }

    /** @param array<string, mixed> $result */
    private function financialError(array $result, string $fallback): string
    {
        $error = $result['error'] ?? null;
        return is_string($error) && $error !== '' ? $error : $fallback;
    }

    private function amountFromRow(object $row, string $field): string
    {
        $values = get_object_vars($row);
        if (!array_key_exists($field, $values)) {
            throw new \UnexpectedValueException("Ads row does not contain {$field}");
        }
        return $this->decimalValue($values[$field]);
    }

    private function currencyFromRow(object $row): string
    {
        $values = get_object_vars($row);
        return $this->normalizeCurrency(is_string($values['currency'] ?? null) ? $values['currency'] : 'irt');
    }

    private function affordableUnits(string $remainingBudget, string $unitCost, float $requestedUnits): float
    {
        if (bccomp($unitCost, '0', 8) <= 0) return $requestedUnits;
        // Unit count is non-monetary; only this quotient is represented as float.
        $available = (float)bcdiv($remainingBudget, $unitCost, 8);
        return max(0.0, min($requestedUnits, floor($available)));
    }

    /** @param array<string, mixed> $refund */
    private function refundAmountForAds(array $refund): string
    {
        $amount = $refund['amount'] ?? null;
        $amount = $this->decimalValue($amount, '-1');
        if (bccomp($amount, '0', 8) < 0) {
            throw new \UnexpectedValueException('Refund result does not contain a non-negative decimal amount');
        }
        return $amount;
    }

    public function __construct(
        private Database $db,
        private LoggerInterface $logger,
        private AppSettings $settings,
        private FinancialEscrowService $financialEscrow,
        private WalletServiceInterface $walletService
    ) {}

    public function budgetOrderType(string $type): ?string
    {
        return match ($type) {
            'social_task' => 'social_task_budget',
            'adtube' => 'adtube_budget',
            'seo' => 'seo_ad_budget',
            'custom_task' => 'custom_task_budget',
            'banner' => 'banner_budget',
            'notification' => 'notification_ad_budget',
            default => null,
        };
    }

    /**
     * @return array{
     *     order_type: ?string,
     *     escrows: list<\stdClass>,
     *     active_escrow: ?\stdClass,
     *     delivery_summary: \stdClass,
     *     delivery_by_type: list<\stdClass>,
     *     transactions: list<\stdClass>
     * }
     */
    public function financeSnapshot(int $adId, string $type): array
    {
        $orderType = $this->budgetOrderType($type);
        $escrows = [];
        if ($orderType) {
            $escrows = $this->db->fetchAll(
                "SELECT id, order_id, order_type, buyer_id, seller_id, amount, currency, status,
                        partial_released, held_at, confirmed_at, released_at, released_by,
                        refunded_at, refund_reason, refunded_by, created_at, updated_at
                 FROM escrow_transactions
                 WHERE order_id = ? AND order_type = ?
                 ORDER BY id DESC",
                [(string)$adId, $orderType]
            );
        }

        $deliverySummary = $this->toObject($this->db->fetch(
            "SELECT
                COALESCE(SUM(amount), 0) AS spent_amount,
                COALESCE(SUM(platform_fee), 0) AS platform_fee,
                COALESCE(SUM(units), 0) AS delivered_units,
                COUNT(*) AS event_count,
                MAX(created_at) AS last_delivery_at
             FROM ad_delivery_events
             WHERE ad_id = ?",
            [$adId]
        ));

        $deliveryByType = $this->db->fetchAll(
            "SELECT event_type,
                    COALESCE(SUM(units), 0) AS units,
                    COALESCE(SUM(amount), 0) AS amount,
                    COALESCE(SUM(platform_fee), 0) AS platform_fee,
                    COUNT(*) AS event_count
             FROM ad_delivery_events
             WHERE ad_id = ?
             GROUP BY event_type
             ORDER BY event_type",
            [$adId]
        );

        $transactions = $this->db->fetchAll(
            "SELECT id, transaction_id, type, currency, amount, status, description, ref_id, ref_type,
                    metadata, created_at, completed_at
             FROM transactions
             WHERE ref_id = ?
                OR metadata LIKE ?
             ORDER BY created_at DESC
             LIMIT 30",
            [(string)$adId, '%"ad_id":' . $adId . '%']
        );

        $activeEscrow = null;
        if ($escrows !== []) {
            foreach ($escrows as $escrow) {
                if (in_array((string)$escrow->status, ['pending', 'in_escrow', 'partial'], true)) {
                    $activeEscrow = $escrow;
                    break;
                }
            }
        }

        return [
            'order_type' => $orderType,
            'escrows' => $escrows,
            'active_escrow' => $activeEscrow,
            'delivery_summary' => $deliverySummary ?: (object)[
                'spent_amount' => 0,
                'platform_fee' => 0,
                'delivered_units' => 0,
                'event_count' => 0,
                'last_delivery_at' => null,
            ],
            'delivery_by_type' => $deliveryByType,
            'transactions' => $transactions,
        ];
    }

    /** @return array<string, mixed> */
    public function deliveryQuote(int $adId, string $type, string $eventType, int $requestedUnits = 1): array
    {
        $requestedUnits = max(1, $requestedUnits);
        $ad = $this->toObject($this->db->fetch("SELECT * FROM ads WHERE id = ? AND type = ? LIMIT 1", [$adId, $type]));
        if ($ad === null) return ['success' => false, 'message' => 'آگهی یافت نشد.'];
        $currency = $this->currencyFromRow($ad);
        $unitCost = $this->decimalValue($this->deliveryUnitCost($type, $eventType, $ad));
        $feePercent = $this->decimalValue($this->feePercent($ad, $type));
        $remainingBudget = $this->amountFromRow($ad, 'remaining_budget');
        $affordableUnits = $this->affordableUnits($remainingBudget, $unitCost, (float)$requestedUnits);

        return [
            'success' => true,
            'unit_cost' => $unitCost,
            'fee_percent' => $feePercent,
            'requested_units' => $requestedUnits,
            'affordable_units' => (int)$affordableUnits,
            'remaining_budget' => $remainingBudget,
            'currency' => $currency,
        ];
    }

    /**
     * Consume delivery budget for banner / notification. Units remain a
     * non-monetary measurement; every monetary field is a decimal string.
     *
     * @param array<string, mixed> $metadata
     * @return array<string, mixed>
     */
    public function consumeDeliveryBudget(
        int $adId,
        string $type,
        string $eventType,
        float $units = 1.0,
        ?int $actorUserId = null,
        array $metadata = [],
        ?string $idempotencyKey = null,
        ?string $unitCostOverride = null
    ): array {
        $units = max(0.0, $units);
        if ($units <= 0) return ['success' => true, 'message' => 'واحدی برای ثبت وجود ندارد.', 'amount' => '0'];

        try {
            $this->db->beginTransaction();
            if ($idempotencyKey !== null && $idempotencyKey !== '') {
                $existing = $this->toObject($this->db->fetch(
                    "SELECT * FROM ad_delivery_events WHERE idempotency_key = ? LIMIT 1 FOR UPDATE", [$idempotencyKey]
                ));
                if ($existing !== null) {
                    $this->db->commit();
                    return ['success' => true, 'duplicate' => true, 'event_id' => (int)$existing->id];
                }
            }

            $ad = $this->toObject($this->db->fetch("SELECT * FROM ads WHERE id = ? AND type = ? FOR UPDATE", [$adId, $type]));
            if ($ad === null) {
                $this->db->rollback();
                return ['success' => false, 'message' => 'آگهی یافت نشد.'];
            }
            $adValues = get_object_vars($ad);
            $status = is_string($adValues['status'] ?? null) ? $adValues['status'] : '';
            $isActive = is_scalar($adValues['is_active'] ?? null) ? (int)$adValues['is_active'] : 1;
            if (!in_array($status, ['active', 'approved'], true) || $isActive !== 1) {
                $this->db->rollback();
                return ['success' => false, 'message' => 'این کمپین فعال نیست.'];
            }

            $currency = $this->currencyFromRow($ad);
            $unitCost = $this->decimalValue($unitCostOverride === null ? $this->deliveryUnitCost($type, $eventType, $ad) : $unitCostOverride);
            $feePercent = $this->decimalValue($this->feePercent($ad, $type));
            $remainingBudget = $this->amountFromRow($ad, 'remaining_budget');
            $billableUnits = $this->affordableUnits($remainingBudget, $unitCost, $units);
            if ($billableUnits <= 0) {
                $refundAmount = $this->closeAdAndRefundRemainderInTransaction($ad, 'completed', 'بودجه باقی‌مانده کمتر از هزینه هر delivery است.', 'delivery_pipeline');
                $this->db->commit();
                return ['success' => false, 'message' => 'بودجه کمپین برای delivery بعدی کافی نبود؛ کمپین بسته و مانده آزاد شد.', 'code' => 'budget_exhausted', 'refund_amount' => $refundAmount];
            }

            $amount = Money::fromString($unitCost, $currency)->multiply((string)$billableUnits)->getAmount();
            $platformFee = Money::fromString($amount, $currency)->percentage($feePercent)->getAmount();
            $totalDebit = Money::fromString($amount, $currency)->add(Money::fromString($platformFee, $currency))->getAmount();
            $orderType = $this->budgetOrderType($type);
            $escrow = $orderType ? $this->lockActiveEscrow($adId, $orderType) : null;
            if (bccomp($totalDebit, '0', 8) > 0 && $escrow === null) {
                throw new \RuntimeException('کمپین دارای مصرف مالی بدون escrow فعال است؛ ابتدا audit و migration مالی الزامی است.');
            }
            if ($escrow !== null && bccomp($this->amountFromRow($escrow, 'amount'), $totalDebit, 8) < 0) {
                $this->db->rollback();
                return ['success' => false, 'message' => 'موجودی escrow برای ثبت delivery کافی نیست.'];
            }

            $this->applyDeliveryAdUpdate($ad, $eventType, $billableUnits, $amount, $actorUserId);
            $advertiserId = $this->requiredRowInt($ad, 'user_id');
            $lockedAdId = $this->requiredRowInt($ad, 'id');
            if (bccomp($totalDebit, '0', 8) > 0 && $escrow !== null) {
                $this->debitAdvertiserLockedAndEscrow($escrow, $advertiserId, $currency, $totalDebit, 'delivery_pipeline', $idempotencyKey ?? 'delivery:' . $adId . ':' . hash('sha256', $eventType . '|' . $amount));
            }

            $eventId = $this->insertDeliveryEvent($lockedAdId, $type, $eventType, $actorUserId, $billableUnits, $unitCost, $amount, $platformFee, $currency, $metadata, $idempotencyKey);
            if (bccomp($totalDebit, '0', 8) > 0) {
                $this->recordTransaction($advertiserId, 'ad_delivery_spend', $currency, $totalDebit, 'مصرف بودجه تبلیغ پس از delivery واقعی', (string)$lockedAdId, $type, [
                    'ad_id' => $lockedAdId, 'ad_type' => $type, 'event_type' => $eventType,
                    'units' => $billableUnits, 'amount' => $amount, 'platform_fee' => $platformFee, 'total_debit' => $totalDebit,
                ] + $metadata);
            }
            $updated = $this->toObject($this->db->fetch("SELECT remaining_budget, spent_budget, status FROM ads WHERE id = ?", [$lockedAdId]));
            $this->db->commit();
            return [
                'success' => true, 'event_id' => $eventId, 'units' => $billableUnits,
                'unit_cost' => $unitCost, 'amount' => $amount, 'platform_fee' => $platformFee,
                'total_debit' => $totalDebit, 'remaining_budget' => $updated === null ? '0' : $this->amountFromRow($updated, 'remaining_budget'),
                'status' => $updated !== null && is_string($updated->status ?? null) ? $updated->status : $status,
            ];
        } catch (\Throwable $e) {
            if ($this->db->inTransaction()) $this->db->rollback();
            $this->logger->error('ads.delivery.consume_failed', ['ad_id' => $adId, 'type' => $type, 'event_type' => $eventType, 'error' => $e->getMessage()]);
            return ['success' => false, 'message' => 'ثبت مصرف بودجه انجام نشد: ' . $e->getMessage()];
        }
    }

    /**
     * تسویه‌ی «دیده‌شدن/خواندن نوتیفیکیشن تبلیغاتی» و واریز پاداش به بیننده.
     *
     * هم‌زمان در یک تراکنش:
     *  - بودجه‌ی تبلیغ‌دهنده برای delivery/click مصرف می‌شود
     *  - پاداشِ نمایش به کیف پولِ کاربرِ بیننده واریز می‌شود (الگوی adtube_reward)
     *  - idempotent است: هر نوتیف فقط یک‌بار پاداش می‌دهد.
     *
     * @return array<string, mixed>
     */
    public function settleNotificationView(
        int $adId,
        int $viewerUserId,
        string $eventType,
        ?int $notificationId = null,
        string $unitCostOverride = '0'
    ): array {
        $type = 'notification';
        try {
            $this->db->beginTransaction();

            $idempotencyKey = 'notification_reward:' . ($notificationId !== null ? (string)$notificationId : ($adId . ':' . $eventType . ':' . $viewerUserId));
            $existing = $this->toObject($this->db->fetch(
                "SELECT * FROM ad_delivery_events WHERE idempotency_key = ? LIMIT 1 FOR UPDATE", [$idempotencyKey]
            ));
            if ($existing !== null) {
                $this->db->commit();
                return ['success' => true, 'duplicate' => true, 'event_id' => (int)$existing->id];
            }

            $ad = $this->toObject($this->db->fetch("SELECT * FROM ads WHERE id = ? AND type = ? FOR UPDATE", [$adId, $type]));
            if ($ad === null) { $this->db->rollback(); return ['success' => false, 'message' => 'آگهی نوتیفیکیشن یافت نشد.']; }

            $adStatus = $this->rowString($ad, 'status');
            if (!in_array($adStatus, ['active', 'approved'], true) || $this->rowInt($ad, 'is_active') !== 1) {
                $this->db->rollback(); return ['success' => false, 'message' => 'کمپین نوتیفیکیشن فعال نیست.'];
            }

            $currency = $this->currencyFromRow($ad);
            $unitCost = $this->decimalValue($unitCostOverride === '0' ? $this->deliveryUnitCost($type, $eventType, $ad) : $unitCostOverride);
            $feePercent = $this->feePercent($ad, $type);
            $reward = $unitCost;
            $platformFee = Money::fromString($reward, $currency)->percentage($feePercent)->getAmount();
            $totalDebit = Money::fromString($reward, $currency)->add(Money::fromString($platformFee, $currency))->getAmount();

            $remainingBudget = $this->amountFromRow($ad, 'remaining_budget');
            if (bccomp($remainingBudget, $totalDebit, 8) < 0) {
                $refund = $this->closeAdAndRefundRemainderInTransaction($ad, 'completed', 'بودجه‌ی نوتیفیکیشن برای پرداخت کافی نبود.', 'notification_settlement');
                $this->db->commit();
                return ['success' => false, 'message' => 'بودجه‌ی کمپین کافی نبود؛ کمپین بسته شد.', 'refund_amount' => $refund];
            }

            $advertiserId = $this->requiredRowInt($ad, 'user_id');
            $adIdInt = $this->requiredRowInt($ad, 'id');
            $orderType = $this->budgetOrderType($type);
            $escrow = $orderType ? $this->lockActiveEscrow($adIdInt, $orderType) : null;
            if (bccomp($totalDebit, '0', 8) > 0 && $escrow === null) {
                throw new \RuntimeException('کمپین نوتیفیکیشن دارای مصرف مالی بدون escrow فعال است.');
            }
            if ($escrow !== null && bccomp($this->amountFromRow($escrow, 'amount'), $totalDebit, 8) < 0) {
                $this->db->rollback(); return ['success' => false, 'message' => 'موجودی escrow کافی نیست.'];
            }

            $this->applyDeliveryAdUpdate($ad, $eventType, 1.0, $reward, $viewerUserId);
            if ($escrow !== null) $this->debitAdvertiserLockedAndEscrow($escrow, $advertiserId, $currency, $totalDebit, 'notification_delivery', $idempotencyKey);

            $this->ensureWallet($viewerUserId);
            $rewardResult = $this->walletService->deposit($viewerUserId, $reward, $currency, [
                'type' => 'notification_reward',
                'ref_id' => $notificationId !== null ? (string)$notificationId : (string)$adIdInt,
                'ref_type' => 'notification_view',
                'description' => 'پاداش مشاهده‌ی نوتیفیکیشن تبلیغاتی',
                'idempotency_key' => $idempotencyKey,
            ]);
            if (empty($rewardResult['success'])) {
                throw new \RuntimeException(is_string($rewardResult['message'] ?? null) ? $rewardResult['message'] : 'واریز پاداش بیننده انجام نشد');
            }

            $eventId = $this->insertDeliveryEvent($adIdInt, $type, $eventType, $viewerUserId, 1.0, $unitCost, $reward, $platformFee, $currency, [
                'source' => 'notification_settlement',
                'notification_id' => $notificationId,
            ], $idempotencyKey);
            $this->recordTransaction($viewerUserId, 'notification_reward', $currency, $reward, 'پاداش مشاهده‌ی نوتیفیکیشن تبلیغاتی', $notificationId !== null ? (string)$notificationId : (string)$adIdInt, 'notification_view', ['ad_id' => $adIdInt]);
            $this->recordTransaction($advertiserId, 'ad_delivery_spend', $currency, $totalDebit, 'مصرف بودجه‌ی نوتیفیکیشن پس از نمایش واقعی', (string)$adIdInt, $type, ['ad_id' => $adIdInt, 'reward' => $reward, 'platform_fee' => $platformFee, 'total_debit' => $totalDebit]);

            $updated = $this->toObject($this->db->fetch("SELECT remaining_budget, status FROM ads WHERE id = ?", [$adIdInt]));
            $this->db->commit();
            return [
                'success' => true, 'event_id' => $eventId, 'reward' => $reward,
                'platform_fee' => $platformFee, 'total_debit' => $totalDebit,
                'remaining_budget' => $updated === null ? '0' : $this->amountFromRow($updated, 'remaining_budget'),
                'status' => $updated !== null && is_string($updated->status ?? null) ? $updated->status : $adStatus,
            ];
        } catch (\Throwable $e) {
            if ($this->db->inTransaction()) $this->db->rollback();
            $this->logger->error('ads.notification.settle_failed', ['ad_id' => $adId, 'error' => $e->getMessage()]);
            return ['success' => false, 'message' => 'تسویه‌ی نوتیفیکیشن ناموفق بود: ' . $e->getMessage()];
        }
    }

    /**
     * Atomic AdTube settlement. playback metrics remain float/int, while all
     * wallet, escrow, budget and ledger values remain decimal strings.
     *
     * @return array<string, mixed>
     */
    public function settleAdTubeView(int $executionId, int $executorId, int $watchTime, int $progressPercent, float $playbackSpeed): array
    {
        try {
            $this->db->beginTransaction();
            $execution = $this->toObject($this->db->fetch(
                "SELECT av.*, a.user_id AS advertiser_id, a.type AS ad_type, a.status AS ad_status,
                        a.is_active, a.price_per_task, a.remaining_budget, a.remaining_count,
                        a.completed_count, a.total_count, a.currency, a.site_commission_percent
                 FROM adtube_views av JOIN ads a ON a.id = av.ad_id
                 WHERE av.id = ? AND av.executor_id = ? FOR UPDATE",
                [$executionId, $executorId]
            ));
            if ($execution === null) { $this->db->rollback(); return ['success' => false, 'message' => 'اجرای AdTube یافت نشد.']; }
            $values = get_object_vars($execution);
            $executionStatus = is_string($values['status'] ?? null) ? $values['status'] : '';
            $adStatus = is_string($values['ad_status'] ?? null) ? $values['ad_status'] : '';
            if (!in_array($executionStatus, ['pending', 'watching'], true) || !in_array($adStatus, ['active', 'approved'], true) || $this->rowInt($execution, 'is_active') !== 1) {
                $this->db->rollback(); return ['success' => false, 'message' => 'این اجرا یا کمپین فعال نیست.'];
            }
            foreach (['ad_id','advertiser_id','price_per_task','remaining_budget','currency'] as $key) {
                if (!array_key_exists($key, $values) || !is_scalar($values[$key])) throw new \UnexpectedValueException("AdTube row missing {$key}");
            }
            $currency = $this->normalizeCurrency($this->rowString($execution, 'currency'));
            $reward = $this->decimalValue($this->rowString($execution, 'price_per_task'));
            $remainingBudget = $this->decimalValue($this->rowString($execution, 'remaining_budget'));
            if (bccomp($reward, '0', 8) <= 0 || bccomp($remainingBudget, $reward, 8) < 0) {
                $this->db->query("UPDATE adtube_views SET status = 'rejected', reject_reason = ?, updated_at = NOW() WHERE id = ?", ['بودجه کمپین برای پرداخت کافی نیست.', $executionId]);
                $refundAmount = $this->closeAdAndRefundRemainderInTransaction($execution, 'completed', 'بودجه باقی‌مانده AdTube کمتر از پاداش هر view است.', 'adtube_settlement');
                $this->db->commit();
                return ['success' => false, 'message' => 'بودجه کمپین برای پرداخت کافی نیست و مانده آزاد شد.', 'refund_amount' => $refundAmount];
            }
            $feePercent = $this->feePercent($execution, 'adtube');
            $platformFee = Money::fromString($reward, $currency)->percentage($feePercent)->getAmount();
            $totalDebit = Money::fromString($reward, $currency)->add(Money::fromString($platformFee, $currency))->getAmount();
            $adId = $this->requiredRowInt($execution, 'ad_id');
            $advertiserId = $this->requiredRowInt($execution, 'advertiser_id');
            $escrow = $this->lockActiveEscrow($adId, 'adtube_budget');
            if (bccomp($totalDebit, '0', 8) > 0 && $escrow === null) {
                throw new \RuntimeException('کمپین AdTube دارای مصرف مالی بدون escrow فعال است؛ ابتدا audit و migration مالی الزامی است.');
            }
            if ($escrow !== null && bccomp($this->amountFromRow($escrow, 'amount'), $totalDebit, 8) < 0) throw new \RuntimeException('موجودی escrow کمپین AdTube کافی نیست.');

            $newRemainingBudget = Money::fromString($remainingBudget, $currency)->subtract(Money::fromString($reward, $currency))->getAmount();
            if (bccomp($newRemainingBudget, '0', 8) < 0) $newRemainingBudget = '0';
            $newCompleted = $this->rowInt($execution, 'completed_count') + 1;
            $newRemainingCount = max(0, $this->rowInt($execution, 'remaining_count') - 1);
            $totalCount = $this->rowInt($execution, 'total_count');
            $newStatus = bccomp($newRemainingBudget, '0', 8) <= 0 || ($totalCount > 0 && $newCompleted >= $totalCount) ? 'completed' : 'active';
            $newActive = $newStatus === 'completed' ? 0 : 1;

            $this->db->query("UPDATE adtube_views SET status = 'completed', watch_time = ?, progress_percent = ?, playback_speed = ?, reward_amount = ?, reward_currency = ?, reward_paid = 1, completed_at = NOW(), updated_at = NOW() WHERE id = ? AND status IN ('pending','watching')", [$watchTime, $progressPercent, $playbackSpeed, $reward, $currency, $executionId]);
            $this->db->query("UPDATE ads SET impressions = impressions + 1, completed_count = ?, remaining_count = ?, remaining_budget = ?, spent_budget = COALESCE(spent_budget,0) + ?, status = ?, is_active = ?, last_delivery_at = NOW(), updated_at = NOW() WHERE id = ?", [$newCompleted, $newRemainingCount, $newRemainingBudget, $reward, $newStatus, $newActive, $adId]);
            if ($escrow !== null) $this->debitAdvertiserLockedAndEscrow($escrow, $advertiserId, $currency, $totalDebit, 'adtube_delivery', 'adtube_budget_'.$executionId);

            $rewardResult = $this->walletService->deposit($executorId, $reward, $currency, ['type'=>'adtube_reward','ref_id'=>$executionId,'ref_type'=>'adtube_execution','description'=>'پاداش تماشای ویدیوی AdTube','idempotency_key'=>'adtube_reward:'.$executionId]);
            if (empty($rewardResult['success'])) throw new \RuntimeException(is_string($rewardResult['message'] ?? null) ? $rewardResult['message'] : 'واریز پاداش مجری انجام نشد');
            $eventId = $this->insertDeliveryEvent($adId, 'adtube', 'completed_view', $executorId, 1.0, $reward, $reward, $platformFee, $currency, ['execution_id'=>$executionId,'watch_time'=>$watchTime,'progress_percent'=>$progressPercent,'playback_speed'=>$playbackSpeed], 'adtube_view_'.$executionId);
            $this->recordTransaction($executorId, 'adtube_reward', $currency, $reward, 'پاداش تماشای ویدیوی AdTube', (string)$executionId, 'adtube_view', ['ad_id'=>$adId,'execution_id'=>$executionId]);
            $this->recordTransaction($advertiserId, 'ad_delivery_spend', $currency, $totalDebit, 'مصرف بودجه AdTube پس از تکمیل view واقعی', (string)$adId, 'adtube', ['ad_id'=>$adId,'execution_id'=>$executionId,'reward'=>$reward,'platform_fee'=>$platformFee,'total_debit'=>$totalDebit]);
            $updated = $this->toObject($this->db->fetch("SELECT remaining_budget, status FROM ads WHERE id = ?", [$adId]));
            $this->db->commit();
            return ['success'=>true,'message'=>'ویدیو تأیید و پاداش مجری پرداخت شد.','event_id'=>$eventId,'reward'=>$reward,'platform_fee'=>$platformFee,'total_debit'=>$totalDebit,'remaining_budget'=>$updated === null ? '0' : $this->amountFromRow($updated,'remaining_budget'),'status'=>$updated !== null && is_string($updated->status ?? null) ? $updated->status : $newStatus];
        } catch (\Throwable $e) {
            if ($this->db->inTransaction()) $this->db->rollback();
            $this->logger->error('ads.adtube.settle_failed', ['execution_id'=>$executionId,'error'=>$e->getMessage()]);
            return ['success'=>false,'message'=>'تسویه AdTube ناموفق بود: '.$e->getMessage()];
        }
    }

    /** @return array<string, mixed> */
    public function applyAdminAction(int $adId, string $action, int $adminId, string $reason = ''): array
    {
        $action = strtolower(trim((string)$action));
        $reason = trim((string)$reason) !== '' ? trim((string)$reason) : 'اقدام مدیریتی';

        if (!in_array($action, ['approve', 'reject', 'pause', 'resume', 'cancel', 'delete'], true)) {
            return ['success' => false, 'message' => 'عملیات مدیریتی نامعتبر است.'];
        }

        if (in_array($action, ['reject', 'cancel', 'delete'], true)) {
            $targetStatus = $action === 'reject' ? 'rejected' : 'cancelled';
            $result = $this->refundRemainingBudget($adId, $targetStatus, $reason, 'admin_' . $adminId);
            if (!empty($result['success']) && $action === 'delete') {
                $this->db->query("UPDATE ads SET deleted_at = NOW(), updated_at = NOW() WHERE id = ?", [$adId]);
                $result['message'] = 'آگهی حذف نرم شد و بودجه قابل‌استرداد آزاد شد.';
            }
            return $result;
        }

        try {
            $this->db->beginTransaction();
            $ad = $this->toObject($this->db->fetch("SELECT * FROM ads WHERE id = ? FOR UPDATE", [$adId]));
            if (!$ad) {
                $this->db->rollback();
                return ['success' => false, 'message' => 'آگهی یافت نشد.'];
            }
            if (in_array((string)$ad->status, ['completed', 'cancelled', 'expired'], true)) {
                $this->db->rollback();
                return ['success' => false, 'message' => 'این آگهی در وضعیت نهایی است.'];
            }

            if ($action === 'approve') {
                $this->db->query(
                    "UPDATE ads
                     SET status = 'active', is_active = 1, approved_at = NOW(), reviewed_by = ?, reviewed_at = NOW(),
                         reject_reason = NULL, rejection_reason = NULL, updated_at = NOW()
                     WHERE id = ?",
                    [$adminId, $adId]
                );
                $message = 'آگهی تأیید و فعال شد.';
            } elseif ($action === 'pause') {
                if (!in_array((string)$ad->status, ['active', 'approved'], true)) {
                    $this->db->rollback();
                    return ['success' => false, 'message' => 'فقط کمپین فعال قابل توقف است.'];
                }
                $this->db->query("UPDATE ads SET status = 'paused', is_active = 0, updated_at = NOW() WHERE id = ?", [$adId]);
                $message = 'کمپین متوقف شد.';
            } else { // resume
                if ((string)$ad->status !== 'paused') {
                    $this->db->rollback();
                    return ['success' => false, 'message' => 'فقط کمپین متوقف قابل ازسرگیری است.'];
                }
                $this->db->query("UPDATE ads SET status = 'active', is_active = 1, updated_at = NOW() WHERE id = ?", [$adId]);
                $message = 'کمپین دوباره فعال شد.';
            }

            $this->recordAdminAudit($adId, $action, $adminId, $reason);
            $this->db->commit();
            return ['success' => true, 'message' => $message];
        } catch (\Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollback();
            }
            $this->logger->error('ads.admin_action_failed', ['ad_id' => $adId, 'action' => $action, 'error' => $e->getMessage()]);
            return ['success' => false, 'message' => 'عملیات مدیریتی انجام نشد: ' . $e->getMessage()];
        }
    }

    /**
     * Closes an ad and refunds only the remaining active budget escrow. The
     * optional owner check keeps user-initiated cancellation authorization in
     * the same transaction as the escrow/wallet mutation.
     *
     * @return array<string, mixed>
     */
    public function refundRemainingBudget(
        int $adId,
        string $targetStatus,
        string $reason,
        string $refundedBy,
        ?int $expectedAdvertiserId = null
    ): array
    {
        if (!in_array($targetStatus, ['cancelled', 'rejected', 'expired', 'completed'], true)) {
            return ['success' => false, 'message' => 'وضعیت نهایی کمپین نامعتبر است.'];
        }

        try {
            $this->db->beginTransaction();
            $ad = $this->toObject($this->db->fetch("SELECT * FROM ads WHERE id = ? FOR UPDATE", [$adId]));
            if ($ad === null) {
                $this->db->rollback();
                return ['success' => false, 'message' => 'آگهی یافت نشد.'];
            }

            $lockedAdId = $this->requiredRowInt($ad, 'id');
            $advertiserId = $this->requiredRowInt($ad, 'user_id');
            if ($expectedAdvertiserId !== null && $advertiserId !== $expectedAdvertiserId) {
                $this->db->rollback();
                return ['success' => false, 'message' => 'اجازهٔ لغو این آگهی را ندارید.'];
            }

            $adType = $this->rowString($ad, 'type');
            $pendingCount = $this->rowInt($ad, 'pending_count');
            if ($pendingCount > 0 && in_array($adType, ['custom_task', 'social_task', 'adtube'], true)) {
                $this->db->rollback();
                return ['success' => false, 'message' => 'این کمپین اجرای در انتظار دارد؛ برای اقدام نهایی از پنل تخصصی همان ماژول استفاده کنید.'];
            }

            $remainingBudget = $this->decimalValue($this->rowString($ad, 'remaining_budget'));
            $status = $this->rowString($ad, 'status');
            if (in_array($status, ['completed', 'cancelled', 'rejected', 'expired'], true) && bccomp($remainingBudget, '0', 8) <= 0) {
                $this->db->rollback();
                return ['success' => true, 'message' => 'این کمپین قبلاً بسته شده است.', 'refund_amount' => '0'];
            }

            $currency = $this->normalizeCurrency($this->rowString($ad, 'currency', 'irt'));
            $refundAmount = '0';
            $orderType = $this->budgetOrderType($adType);
            $escrow = $orderType === null ? null : $this->lockActiveEscrow($lockedAdId, $orderType);

            $this->ensureWallet($advertiserId);
            if ($escrow !== null) {
                $escrowId = $this->requiredRowInt($escrow, 'id');
                $refund = $this->financialEscrow->refundHeldBudget(
                    $escrowId,
                    $advertiserId,
                    $reason,
                    $refundedBy,
                    'ads_refund:' . $escrowId
                );
                if (empty($refund['ok'])) {
                    throw new \RuntimeException($this->financialError($refund, 'Failed to refund held ad budget'));
                }
                $refundAmount = $this->refundAmountForAds($refund);

                if (bccomp($refundAmount, '0', 8) > 0) {
                    $this->recordTransaction(
                        $advertiserId,
                        'ad_refund',
                        $currency,
                        $refundAmount,
                        'بازگشت بودجه باقی‌مانده تبلیغ: ' . $reason,
                        (string)$lockedAdId,
                        $adType,
                        ['ad_id' => $lockedAdId, 'ad_type' => $adType, 'reason' => $reason, 'refunded_by' => $refundedBy]
                    );
                }
            } elseif (bccomp($remainingBudget, '0', 8) > 0) {
                throw new \RuntimeException('کمپین legacy بدون escrow نیازمند audit و migration مالی است؛ refund مستقیم مجاز نیست.');
            }

            $updates = [
                'status' => $targetStatus,
                'is_active' => 0,
                'remaining_budget' => 0,
                'updated_at' => date('Y-m-d H:i:s'),
            ];
            if ($targetStatus === 'cancelled' || $targetStatus === 'expired') {
                $updates['cancelled_at'] = date('Y-m-d H:i:s');
            }
            if ($targetStatus === 'rejected') {
                $updates['reject_reason'] = $reason;
                $updates['rejection_reason'] = $reason;
                $updates['reviewed_at'] = date('Y-m-d H:i:s');
            }
            $this->updateAdsRow($lockedAdId, $updates);

            if (preg_match('/^admin_(\d+)$/', $refundedBy, $matches) === 1) {
                $this->recordAdminAudit($lockedAdId, $targetStatus, (int)$matches[1], $reason);
            }

            $this->db->commit();
            return ['success' => true, 'message' => 'کمپین بسته و بودجه قابل‌استرداد آزاد شد.', 'refund_amount' => $refundAmount, 'status' => $targetStatus];
        } catch (\Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollback();
            }
            $this->logger->error('ads.refund_remaining_failed', ['ad_id' => $adId, 'error' => $e->getMessage()]);
            return ['success' => false, 'message' => 'بازگشت بودجه انجام نشد: ' . $e->getMessage()];
        }
    }

    /** @return array<string, mixed> */
    public function reconcileLifecycle(int $limit = 100): array
    {
        $limit = max(1, min(500, $limit));
        $rows = $this->db->fetchAll(
            "SELECT id, type, status, remaining_budget, remaining_count, pending_count, end_date, deadline
             FROM ads
             WHERE deleted_at IS NULL
               AND status IN ('active','approved','paused','exhausted')
               AND COALESCE(pending_count, 0) = 0
               AND (
                    COALESCE(remaining_budget, 0) <= 0
                    OR (type = 'seo' AND COALESCE(min_payout, 0) > 0 AND COALESCE(remaining_budget, 0) < COALESCE(min_payout, 0))
                    OR (end_date IS NOT NULL AND end_date < NOW())
                    OR (deadline IS NOT NULL AND deadline < NOW())
                    OR (type IN ('social_task','custom_task','adtube') AND COALESCE(remaining_count, 0) <= 0)
               )
             ORDER BY updated_at ASC, id ASC
             LIMIT {$limit}"
        );

        $stats = ['checked' => count($rows), 'completed' => 0, 'expired' => 0, 'failed' => 0, 'items' => []];
        foreach ($rows as $row) {
            $endDate = $this->rowString($row, 'end_date');
            $deadline = $this->rowString($row, 'deadline');
            $isExpiredByDate = ($endDate !== '' && strtotime($endDate) < time())
                || ($deadline !== '' && strtotime($deadline) < time());
            $targetStatus = $isExpiredByDate ? 'expired' : 'completed';
            $reason = $isExpiredByDate
                ? 'بسته‌شدن خودکار به دلیل پایان تاریخ کمپین'
                : 'بسته‌شدن خودکار به دلیل اتمام بودجه/ظرفیت کمپین';

            $rowAdId = $this->requiredRowInt($row, 'id');
            $result = $this->refundRemainingBudget($rowAdId, $targetStatus, $reason, 'system_reconcile');
            if (!empty($result['success'])) {
                $stats[$targetStatus]++;
            } else {
                $stats['failed']++;
            }
            $stats['items'][] = [
                'ad_id' => $rowAdId,
                'type' => $this->rowString($row, 'type'),
                'target_status' => $targetStatus,
                'success' => !empty($result['success']),
                'message' => $result['message'] ?? null,
                'refund_amount' => $result['refund_amount'] ?? 0,
            ];
        }

        return $stats;
    }

    private function closeAdAndRefundRemainderInTransaction(object $ad, string $targetStatus, string $reason, string $actor): string
    {
        $adValues = get_object_vars($ad);
        $adId = $this->requiredRowInt($ad, array_key_exists('id', $adValues) ? 'id' : 'ad_id');
        $advertiserId = $this->requiredRowInt($ad, array_key_exists('user_id', $adValues) ? 'user_id' : 'advertiser_id');
        $type = $this->rowString($ad, array_key_exists('type', $adValues) ? 'type' : 'ad_type');
        if ($adId <= 0 || $advertiserId <= 0) {
            throw new \Core\Exceptions\InvalidStateException('اطلاعات آگهی برای تسویه/بازگشت بودجه نامعتبر است');
        }

        $currency = $this->normalizeCurrency($this->rowString($ad, 'currency', 'irt'));
        $refundAmount = '0';
        $this->ensureWallet($advertiserId);

        $orderType = $this->budgetOrderType($type);
        $escrow = $orderType ? $this->lockActiveEscrow($adId, $orderType) : null;
        if ($escrow) {
            $escrowId = $this->requiredRowInt($escrow, 'id');
            $refund = $this->financialEscrow->refundHeldBudget($escrowId, $advertiserId, $reason, $actor, 'ads_reconcile_refund:' . $escrowId);
            if (empty($refund['ok'])) {
                throw new \RuntimeException($this->financialError($refund, 'Failed to refund remaining held ad budget'));
            }
            $refundAmount = $this->refundAmountForAds($refund);
            
        } else {
            if (bccomp($this->decimalValue($this->rowString($ad, 'remaining_budget')), '0', 8) > 0) {
                throw new \RuntimeException('کمپین legacy بدون escrow نیازمند audit و migration مالی است؛ refund مستقیم مجاز نیست.');
            }
        }

        if (bccomp($refundAmount, '0', 8) > 0) {
            $this->recordTransaction(
                $advertiserId,
                'ad_refund',
                $currency,
                $refundAmount,
                'آزادسازی مانده کمپین تبلیغاتی: ' . $reason,
                (string)$adId,
                $type,
                ['ad_id' => $adId, 'ad_type' => $type, 'reason' => $reason, 'refunded_by' => $actor, 'reconcile' => true]
            );
        }

        $updates = [
            'status' => $targetStatus,
            'is_active' => 0,
            'remaining_budget' => 0,
            'updated_at' => date('Y-m-d H:i:s'),
        ];
        if ($targetStatus === 'expired') {
            $updates['cancelled_at'] = date('Y-m-d H:i:s');
        }
        $this->updateAdsRow($adId, $updates);

        return $refundAmount;
    }

    public function deliveryUnitCost(string $type, string $eventType, ?object $ad = null): string
    {
        $eventType = strtolower($eventType);
        $values = $ad === null ? [] : get_object_vars($ad);
        return match ($type) {
            'banner' => $eventType === 'click'
                ? (isset($values['price_per_click']) && is_scalar($values['price_per_click']) && bccomp((string)$values['price_per_click'], '0', 8) > 0
                    ? (string)$values['price_per_click'] : $this->decimalSetting('banner_click_cost', '500'))
                : $this->decimalSetting('banner_impression_cost', '10'),
            'notification' => in_array($eventType, ['click','clicked'], true)
                ? $this->decimalSetting('notification_ad_click_cost', '0')
                : $this->decimalSetting('notification_ad_delivery_cost', '25'),
            'adtube' => isset($values['price_per_task']) && is_scalar($values['price_per_task'])
                ? $this->decimalValue($values['price_per_task']) : $this->decimalSetting('adtube_min_price_per_view', '100'),
            default => '0',
        };
    }

    private function feePercent(object $ad, string $type): string
    {
        $values = get_object_vars($ad);
        if (isset($values['site_commission_percent']) && is_scalar($values['site_commission_percent']) && bccomp((string)$values['site_commission_percent'], '0', 8) >= 0) return (string)$values['site_commission_percent'];
        return match ($type) {
            'banner' => $this->decimalSetting('banner_fee_percent', '12'),
            'notification' => $this->decimalSetting('notification_ad_fee_percent', '15'),
            'adtube' => $this->decimalSetting('adtube_site_fee_percent', '10'),
            default => '0',
        };
    }

    private function applyDeliveryAdUpdate(object $ad, string $eventType, float $units, string $amount, ?int $actorUserId = null): void
    {
        $type = $this->rowString($ad, 'type');
        $eventType = strtolower((string)$eventType);
        $currentBudget = $this->amountFromRow($ad, 'remaining_budget');
        $currency = $this->currencyFromRow($ad);
        $newRemainingBudget = Money::fromString($currentBudget, $currency)->subtract(Money::fromString($amount, $currency))->getAmount();
        if (bccomp($newRemainingBudget, '0', 8) < 0) $newRemainingBudget = '0';
        $newStatus = bccomp($newRemainingBudget, '0', 8) <= 0 ? 'completed' : $this->rowString($ad, 'status');
        $newActive = $newStatus === 'completed' ? 0 : $this->rowInt($ad, 'is_active', 1);

        $impressionDelta = 0.0;
        $clickDelta = 0.0;
        $completedDelta = 0.0;
        $remainingCountDelta = 0.0;

        if ($type === 'banner') {
            if ($eventType === 'click') {
                $clickDelta = $units;
                $this->db->query(
                    "INSERT INTO banner_clicks (banner_id, user_id, ip_address, user_agent, referer, clicked_at)
                     VALUES (?, ?, ?, ?, ?, NOW())",
                    [
                        $this->requiredRowInt($ad, 'id'),
                        $actorUserId,
                        mb_substr(get_client_ip(), 0, 45),
                        mb_substr(get_user_agent(), 0, 500),
                        mb_substr(strval($_SERVER['HTTP_REFERER'] ?? ''), 0, 500),
                    ]
                );
            } else {
                $impressionDelta = $units;
            }
        } elseif ($type === 'notification') {
            if (in_array($eventType, ['click', 'clicked'], true)) {
                $clickDelta = $units;
            } else {
                $impressionDelta = $units;
            }
        }

        $this->db->query(
            "UPDATE ads
             SET impressions = impressions + ?,
                 clicks = clicks + ?,
                 clicks_count = clicks_count + ?,
                 completed_count = completed_count + ?,
                 remaining_count = GREATEST(COALESCE(remaining_count, 0) - ?, 0),
                 remaining_budget = ?,
                 spent_budget = COALESCE(spent_budget, 0) + ?,
                 status = ?,
                 is_active = ?,
                 last_delivery_at = NOW(),
                 ctr = CASE WHEN (impressions + ?) > 0 THEN ROUND(((clicks + ?) / (impressions + ?)) * 100, 2) ELSE 0 END,
                 updated_at = NOW()
             WHERE id = ?",
            [
                $impressionDelta,
                $clickDelta,
                $clickDelta,
                $completedDelta,
                $remainingCountDelta,
                $newRemainingBudget,
                $amount,
                $newStatus,
                $newActive,
                $impressionDelta,
                $clickDelta,
                $impressionDelta,
                $this->requiredRowInt($ad, 'id'),
            ]
        );
    }

    private function lockActiveEscrow(int $adId, string $orderType): ?object
    {
        return $this->db->fetch(
            "SELECT * FROM escrow_transactions
             WHERE order_id = ? AND order_type = ? AND status IN ('pending','in_escrow','partial')
             ORDER BY id DESC LIMIT 1 FOR UPDATE",
            [(string)$adId, $orderType]
        );
    }

    private function debitAdvertiserLockedAndEscrow(
        object $escrow,
        int $advertiserId,
        string $currency,
        string $totalDebit,
        string $releasedBy,
        string $idempotencyKey
    ): void
    {
        if (bccomp($totalDebit, '0', 8) <= 0) {
            return;
        }
        $currency = $this->normalizeCurrency($currency);
        $this->ensureWallet($advertiserId);

        $result = $this->financialEscrow->consumeHeldBudget(
            $this->requiredRowInt($escrow, 'id'),
            $advertiserId,
            $totalDebit,
            $currency,
            'ads_delivery_budget_consumption',
            $releasedBy,
            $idempotencyKey
        );
        if (empty($result['ok'])) {
            throw new \RuntimeException($this->financialError($result, 'Failed to consume held ad budget'));
        }
    }

    /** @param array<string, mixed> $metadata */
    private function insertDeliveryEvent(
        int $adId,
        string $type,
        string $eventType,
        ?int $actorUserId,
        float $units,
        string $unitCost,
        string $amount,
        string $platformFee,
        string $currency,
        array $metadata,
        ?string $idempotencyKey
    ): int {
        $this->db->query(
            "INSERT INTO ad_delivery_events
                (ad_id, ad_type, event_type, user_id, units, unit_cost, amount, platform_fee, currency,
                 ip_address, user_agent, reference_id, reference_type, metadata, idempotency_key, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())",
            [
                $adId,
                $type,
                $eventType,
                $actorUserId,
                $units,
                $unitCost,
                $amount,
                $platformFee,
                $this->normalizeCurrency($currency),
                mb_substr(get_client_ip(), 0, 45),
                mb_substr(get_user_agent(), 0, 500),
                is_scalar($metadata['execution_id'] ?? null) ? (string)$metadata['execution_id'] : (is_scalar($metadata['notification_batch'] ?? null) ? (string)$metadata['notification_batch'] : null),
                $metadata['reference_type'] ?? null,
                json_encode($metadata, JSON_UNESCAPED_UNICODE),
                $idempotencyKey,
            ]
        );
        return (int)$this->db->lastInsertId();
    }

    /** @param array<string, mixed> $updates */
    private function updateAdsRow(int $adId, array $updates): void
    {
        $allowed = ['status','is_active','remaining_budget','cancelled_at','reject_reason','rejection_reason','reviewed_at','deleted_at','updated_at'];
        $sets = [];
        $params = [];
        foreach ((array)$updates as $key => $value) {
            if (!in_array($key, $allowed, true)) continue;
            $sets[] = "`{$key}` = ?";
            $params[] = $value;
        }
        if (!$sets) return;
        $params[] = $adId;
        $this->db->query("UPDATE ads SET " . implode(', ', $sets) . " WHERE id = ?", $params);
    }

    private function ensureWallet(int $userId): void
    {
        if (!$this->walletService->getOrCreateWallet($userId)) {
            throw new \RuntimeException('ایجاد یا دریافت کیف پول انجام نشد.');
        }
    }

    /** @param array<string, mixed> $metadata */
    private function recordTransaction(int $userId, string $type, string $currency, string $amount, string $description, string $refId, string $refType, array $metadata = []): void
    {
        $this->db->query(
            "INSERT INTO transactions
                (transaction_id, user_id, type, currency, amount, balance_before, balance_after, status,
                 description, ref_id, ref_type, ip_address, device_fingerprint, metadata,
                 created_at, updated_at, completed_at)
             VALUES (?, ?, ?, ?, ?, 0, 0, 'completed', ?, ?, ?, ?, ?, ?, NOW(), NOW(), NOW())",
            [
                $this->transactionId($type),
                $userId,
                $type,
                $this->normalizeCurrency($currency),
                $amount,
                $description,
                $refId,
                $refType,
                mb_substr(get_client_ip(), 0, 45),
                'ads-finance',
                json_encode($metadata, JSON_UNESCAPED_UNICODE),
            ]
        );
    }

    private function recordAdminAudit(int $adId, string $action, int $adminId, string $reason): void
    {
        $this->logger->activity('admin.ads.' . $action, 'اقدام مدیریتی روی تبلیغ', $adminId, [
            'ad_id' => $adId,
            'reason' => $reason,
        ]);
    }

    private function transactionId(string $prefix): string
    {
        return substr($prefix, 0, 28) . '_' . bin2hex(random_bytes(8));
    }

    private function normalizeCurrency(string $currency): string
    {
        $currency = strtolower((string)$currency);
        return $currency === 'usdt' ? 'usdt' : 'irt';
    }
}
