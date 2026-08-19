<?php

declare(strict_types=1);

namespace App\Domain\Financial\Services;

use App\Contracts\WalletServiceInterface;
use App\Contracts\LoggerInterface;
use App\Contracts\AntiFraud\FraudGuardInterface;
use App\Services\EscrowService;
use App\Services\Settings\AppSettings;
use App\Services\SagaOrchestrator;
use Core\Database;
use App\Services\Shared\IdempotencyService;
use Core\ValueObjects\Money;

/**
 * FinancialEscrowService - Unified escrow management for all financial modules
 * 
 * Uses EscrowService as foundation + module-specific business logic
 * Modules: SocialTask (advertiser→executor), Influencer (buyer→seller), Vitrine (buyer→seller)
 */
/**
 * @phpstan-type FinancialResult array<string, mixed>
 * @phpstan-type EscrowRow object{
 *     id: int|string,
 *     order_id: int|string,
 *     order_type: string,
 *     buyer_id: int|string,
 *     seller_id: int|string,
 *     amount: int|float|string,
 *     currency: string,
 *     status: string
 * }
 * @phpstan-type EscrowHoldRow object{transaction_id: string, amount: int|float|string}
 */
class FinancialEscrowService
{
    private EscrowService $escrow;
    private WalletServiceInterface $wallet;
    protected Database $db;
    private LoggerInterface $logger;
    private AppSettings $appSettings;
    private SagaOrchestrator $saga;
    private FraudGuardInterface $fraudGuard;
    private ?IdempotencyService $idempotencyService;

    public function __construct(
        EscrowService $escrow,
        WalletServiceInterface $wallet,
        AppSettings $appSettings,
        SagaOrchestrator $saga,
        Database $db,
        LoggerInterface $logger,
        FraudGuardInterface $fraudGuard,
        ?IdempotencyService $idempotencyService = null
    ) {
        $this->escrow = $escrow;
        $this->wallet = $wallet;
        $this->appSettings = $appSettings;
        $this->saga = $saga;
        $this->db = $db;
        $this->logger = $logger;
        $this->fraudGuard = $fraudGuard;
        $this->idempotencyService = $idempotencyService;
    }


    /** @param array<string, mixed> $context */
    private function captureEscrowException(\Throwable $e, string $operation, array $context = []): void
    {
        try {
            \App\Services\Sentry\SentryExceptionHandler::captureException($e, null, array_merge([
                'operation' => 'financial_escrow.' . $operation,
            ], $context));
        } catch (\Throwable $ignored) {
            // Sentry must never break financial fallback paths.
        }
    }

    /**
     * @param callable(): mixed $logic
     * @param array<string, mixed>|null $requestData
     * @return FinancialResult
     */
    private function executeWithIdempotency(?string $key, int $userId, string $action, callable $logic, ?array $requestData = null): array
    {
        if ($key && $this->idempotencyService) {
            // از IdempotencyService::execute() استفاده می‌کنیم — نقطه مرکزی idempotency.
            // قرارداد عمومی FinancialEscrowService حفظ می‌شود: در صورت خطا ['ok'=>false] برمی‌گردد.
            try {
                return $this->normalizeFinancialResult(
                    $this->idempotencyService->execute($action, $userId, $requestData ?? [], $logic, $key)
                );
            } catch (\Throwable $e) {
            $this->captureEscrowException($e, 'executeWithIdempotency');
                $this->logger->error('financial_escrow.operation_failed', ['action' => $action, 'error' => $e->getMessage()]);
                return ['ok' => false, 'error' => $e->getMessage()];
            }
        }

        // Fallback when idempotency service isn't available
        try {
            $result = $logic();
            return $this->normalizeFinancialResult($result);
        } catch (\Throwable $e) {
            $this->captureEscrowException($e, 'executeWithIdempotency');
            $this->logger->error('financial_escrow.operation_failed', ['error' => $e->getMessage()]);
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }

    /** @param array<string, mixed> $result */
    private function resultMessage(array $result, string $key, string $fallback): string
    {
        return is_string($result[$key] ?? null) ? $result[$key] : $fallback;
    }

    /**
     * @return FinancialResult
     */
    private function normalizeFinancialResult(mixed $result): array
    {
        if (!is_array($result)) {
            return ['ok' => (bool)$result];
        }

        foreach (array_keys($result) as $key) {
            if (!is_string($key)) {
                throw new \UnexpectedValueException('Financial operation result must be an associative array');
            }
        }

        /** @var FinancialResult $result */
        return $result;
    }

    // ──────────────────────────────────────────────────────────────────────────
    // Generic custom-deal escrow (Buyer → Seller)
    // ──────────────────────────────────────────────────────────────────────────

    /**
     * Canonical refund contract for escrow flows backed by a pending wallet hold.
     * It performs the wallet mutation and escrow state transition atomically.
     * @return FinancialResult
     */
    public function refundEscrowToBuyer(int $escrowId, int $buyerId, string $reason, string $initiatedBy = 'system', ?string $idempotencyKey = null): array
    {
        $payload=['escrow_id'=>$escrowId,'buyer_id'=>$buyerId,'reason'=>$reason,'initiated_by'=>$initiatedBy];
        return $this->executeWithIdempotency($idempotencyKey,$buyerId,"refund_escrow_{$escrowId}",function()use($escrowId,$buyerId,$reason,$initiatedBy){
            try{return $this->db->transactional(function()use($escrowId,$buyerId,$reason,$initiatedBy){
                $escrow=$this->db->fetch('SELECT * FROM escrow_transactions WHERE id=? FOR UPDATE',[$escrowId]);
                if(!$escrow)throw new \Core\Exceptions\NotFoundException('صندوق امانات یافت نشد');
                $escrow = $this->requireEscrowRow($escrow);
                if((int)$escrow->buyer_id!==$buyerId)throw new \Core\Exceptions\SecurityException('خریدار صندوق امانات مطابقت ندارد');
                if(!in_array((string)$escrow->status,['pending','in_escrow','partial','disputed'],true))throw new \Core\Exceptions\InvalidStateException('وضعیت صندوق امانات برای refund معتبر نیست');
                $holdType=match((string)$escrow->order_type){'custom_deal'=>'custom_deal_escrow','social_task_execution'=>'social_task_escrow','influencer_order'=>'influencer_escrow','vitrine_listing'=>'vitrine_escrow',default=>throw new \Core\Exceptions\InvalidStateException('نوع escrow برای refund یکپارچه پشتیبانی نمی‌شود')};
                $currency=strtolower((string)$escrow->currency)==='usdt'?'usdt':'irt';
                $hold=$this->findEscrowHoldTransaction($buyerId,$holdType,(string)$escrow->order_id,(string)$escrow->amount,$currency);
                if(!$hold)throw new \Core\Exceptions\NotFoundException('تراکنش hold متناظر یافت نشد');
                $refund=$this->escrow->refundFunds($escrowId,$buyerId,$reason,$initiatedBy,"refund_state_{$escrowId}");
                if(empty($refund['ok']))throw new \Core\Exceptions\ApplicationException($this->resultMessage($refund, 'error', 'تغییر وضعیت refund ناموفق بود'));
                $walletRefund=$this->wallet->releaseLockedFunds($buyerId,(string)$escrow->amount,$currency,['type'=>'escrow_refund','ref_id'=>$escrowId,'ref_type'=>'escrow','description'=>$reason,'idempotency_key'=>"escrow_refund_wallet_{$escrowId}"]);
                if(empty($walletRefund['success']))throw new \Core\Exceptions\ApplicationException($this->resultMessage($walletRefund, 'message', 'بازگشت وجه کیف پول ناموفق بود'));
                if(!$this->wallet->finalizeLockedRefund($buyerId,(string)$hold->transaction_id))throw new \Core\Exceptions\ApplicationException('نهایی‌سازی hold refund ناموفق بود');
                return ['ok'=>true,'amount'=>(string)$escrow->amount,'currency'=>$currency,'transaction_id'=>$walletRefund['transaction_id']??null];
            });}catch(\Throwable $e){$this->captureEscrowException($e,'refundEscrowToBuyer',['escrow_id'=>$escrowId]);return['ok'=>false,'error'=>$e->getMessage()];}
        },$payload);
    }

    /**
     * Creates and funds a user-to-user escrow atomically. The old controller
     * only created an escrow row; this flow also creates the buyer wallet hold
     * and confirms the escrow before returning success.
     * @return FinancialResult
     */
    public function holdCustomDealFunds(
        int $orderId,
        int $buyerId,
        int $sellerId,
        string $amount,
        ?string $idempotencyKey = null
    ): array {
        if ($buyerId <= 0 || $sellerId <= 0 || $buyerId === $sellerId) {
            return ['ok' => false, 'error' => 'طرفین معاملهٔ امانی نامعتبر هستند'];
        }
        if (bccomp($amount, '0', 8) <= 0) {
            return ['ok' => false, 'error' => 'مبلغ صندوق امانات باید بیشتر از صفر باشد'];
        }

        $payload = ['order_id' => $orderId, 'buyer_id' => $buyerId, 'seller_id' => $sellerId, 'amount' => $amount];
        return $this->executeWithIdempotency($idempotencyKey, $buyerId, "hold_custom_deal_{$orderId}", function () use ($orderId, $buyerId, $sellerId, $amount) {
            try {
                return $this->db->transactional(function () use ($orderId, $buyerId, $sellerId, $amount) {
                    $this->db->query('SELECT id FROM wallets WHERE user_id = ? FOR UPDATE', [$buyerId]);
                    $balance = $this->wallet->getBalanceForUpdate($buyerId, 'irt');
                    if (Money::fromString($amount, 'irt')->isGreaterThan(Money::fromString($balance, 'irt'))) {
                        throw new \Core\Exceptions\InsufficientBalanceException('موجودی کیف پول خریدار کافی نیست');
                    }

                    $record = $this->escrow->holdFunds(
                        $orderId,
                        'custom_deal',
                        $buyerId,
                        $sellerId,
                        $amount,
                        'IRT',
                        "custom_deal_escrow_record_{$orderId}"
                    );
                    if (empty($record['ok']) || empty($record['escrow_id'])) {
                        throw new \Core\Exceptions\ApplicationException($this->resultMessage($record, 'error', 'ایجاد صندوق امانات انجام نشد'));
                    }

                    $hold = $this->wallet->withdraw($buyerId, $amount, 'irt', [
                        'type' => 'custom_deal_escrow',
                        'order_id' => $orderId,
                        'ref_id' => $orderId,
                        'ref_type' => 'custom_deal',
                        'description' => "رزرو وجه معاملهٔ امن #{$orderId}",
                        'idempotency_key' => "custom_deal_hold_{$orderId}",
                    ]);
                    if (empty($hold['success'])) {
                        throw new \Core\Exceptions\ApplicationException($this->resultMessage($hold, 'message', 'قفل‌کردن وجه معاملهٔ امن انجام نشد'));
                    }

                    $confirmed = $this->escrow->confirmHold($orderId, 'custom_deal', $sellerId, "custom_deal_confirm_{$orderId}");
                    if (empty($confirmed['ok'])) {
                        throw new \Core\Exceptions\ApplicationException($this->resultMessage($confirmed, 'error', 'تأیید صندوق امانات انجام نشد'));
                    }

                    return ['ok' => true, 'escrow_id' => (int)$record['escrow_id']];
                });
            } catch (\Throwable $e) {
                $this->captureEscrowException($e, 'holdCustomDealFunds', ['order_id' => $orderId]);
                return ['ok' => false, 'error' => $e->getMessage()];
            }
        }, $payload);
    }

    /** Releases a fully-funded custom deal after buyer authorization.
     * @return FinancialResult
     */
    public function releaseCustomDealFunds(int $escrowId, int $buyerId, ?string $idempotencyKey = null): array
    {
        $payload = ['escrow_id' => $escrowId, 'buyer_id' => $buyerId];
        return $this->executeWithIdempotency($idempotencyKey, $buyerId, "release_custom_deal_{$escrowId}", function () use ($escrowId, $buyerId) {
            try {
                return $this->db->transactional(function () use ($escrowId, $buyerId) {
                    $escrow = $this->db->fetch('SELECT * FROM escrow_transactions WHERE id = ? FOR UPDATE', [$escrowId]);
                    if (!$escrow || (string)$escrow->order_type !== 'custom_deal') {
                        throw new \Core\Exceptions\NotFoundException('صندوق امانات معاملهٔ امن یافت نشد');
                    }
                    $escrow = $this->requireEscrowRow($escrow);
                    if ((int)$escrow->buyer_id !== $buyerId) {
                        throw new \Core\Exceptions\SecurityException('فقط خریدار می‌تواند وجه معاملهٔ امن را آزاد کند');
                    }
                    if ((string)$escrow->status !== 'in_escrow') {
                        throw new \Core\Exceptions\InvalidStateException('وضعیت صندوق امانات برای آزادسازی معتبر نیست');
                    }

                    $release = $this->escrow->releaseFunds((int)$escrow->id, (int)$escrow->seller_id, "buyer_{$buyerId}");
                    if (empty($release['ok'])) {
                        throw new \Core\Exceptions\ApplicationException($this->resultMessage($release, 'error', 'آزادسازی صندوق امانات انجام نشد'));
                    }
                    $this->settleEscrowHold($escrow, 'complete', (string)$escrow->amount);

                    $payout = $this->wallet->deposit((int)$escrow->seller_id, (string)$escrow->amount, strtolower((string)$escrow->currency), [
                        'type' => 'custom_deal_payout',
                        'ref_id' => $escrowId,
                        'ref_type' => 'custom_deal',
                        'description' => "دریافت وجه معاملهٔ امن #{$escrowId}",
                        'idempotency_key' => "custom_deal_payout_{$escrowId}",
                    ]);
                    if (empty($payout['success'])) {
                        throw new \Core\Exceptions\ApplicationException($this->resultMessage($payout, 'message', 'واریز وجه به فروشنده انجام نشد'));
                    }

                    return ['ok' => true, 'escrow_id' => $escrowId, 'transaction_id' => $payout['transaction_id'] ?? null];
                });
            } catch (\Throwable $e) {
                $this->captureEscrowException($e, 'releaseCustomDealFunds', ['escrow_id' => $escrowId]);
                return ['ok' => false, 'error' => $e->getMessage()];
            }
        }, $payload);
    }

    /** Cancels a custom deal and unlocks the buyer's original hold.
     * @return FinancialResult
     */
    private function refundOrderEscrow(int $orderId, string $orderType, int $buyerId, string $reason, string $initiatedBy, ?string $idempotencyKey): array
    {
        $escrow = $this->escrow->getByOrder($orderId, $orderType);
        if (!$escrow) {
            return ['ok' => false, 'error' => 'صندوق امانات یافت نشد'];
        }
        $escrow = $this->requireEscrowRow($escrow);
        return $this->refundEscrowToBuyer((int)$escrow->id, $buyerId, $reason, $initiatedBy, $idempotencyKey);
    }

    /** Compatibility entrypoint; canonical refund logic lives in refundEscrowToBuyer().
     * @return FinancialResult
     */
    public function refundCustomDealFunds(int $escrowId, int $buyerId, string $reason, ?string $idempotencyKey = null): array
    {
        return $this->refundEscrowToBuyer($escrowId, $buyerId, $reason, 'custom_deal', $idempotencyKey);
    }

    /**
     * درخواست نگهداری پول از تبلیغ‌دهنده برای اجرا
     * Flow: Executor submits → Escrow holds → Admin approves → Funds released
     * @return FinancialResult
     */
    public function holdSocialTaskFunds(
        int    $executionId,
        int    $executorId,
        int    $advertiserId,
        string $reward,
        ?string $idempotencyKey = null
    ): array {
        $payload = ['execution_id' => $executionId, 'executor_id' => $executorId, 'advertiser_id' => $advertiserId, 'amount' => $reward];
        return $this->executeWithIdempotency($idempotencyKey, $advertiserId, "hold_social_task_{$executionId}", function() use ($executionId, $executorId, $advertiserId, $reward, $payload) {
        try {
            // BUGFIX-SAGA-TX-ROOT: کامنت قدیمی می‌گفت beginTransaction عمداً حذف شده
            // تا Saga «توزیع‌شده‌ی واقعی» باشد، اما مکانیزم جبران (compensate) این
            // پروژه از Closure استفاده می‌کند که — طبق کد خودِ SagaRecoveryWorker —
            // اصلاً قابل بازیابی از دیتابیس نیست، و آن Worker هم هرگز در
            // Console\Kernel زمان‌بندی نشده. یعنی جبران واقعی فقط همان لحظه (in-process)
            // ممکن است و اگر بین دو Step کرش رخ دهد، وضعیت ناقص برای همیشه باقی می‌ماند.
            // راه‌حل: کل Saga داخل یک Transaction Root اجرا می‌شود (با پشتیبانی
            // SAVEPOINT از Core\Database برای سازگاری با تراکنش‌های بیرونی موجود
            // مثل DisputeCommandService::adminResolve).
            $result = $this->db->transactional(function () use ($executionId, $executorId, $advertiserId, $reward, $payload) {
            return $this->saga
                ->setSaga('hold_social_task_escrow', $payload)
                ->addStep(
                    'verify_and_lock_balance',
                    function () use ($advertiserId, $reward) {
                        $this->db->query("SELECT id FROM wallets WHERE user_id = ? FOR UPDATE", [(int)$advertiserId])->fetch();
                        $advertiserBalance = $this->wallet->getBalanceForUpdate($advertiserId, 'irt');

                        $advertiserMoney = Money::fromString($advertiserBalance, 'irt');
                        $rewardMoney = Money::fromString($reward, 'irt');

                        if ($rewardMoney->isGreaterThan($advertiserMoney)) {
                            throw new \Core\Exceptions\InsufficientBalanceException('موجودی کیف پول تبلیغ‌دهنده کافی نیست');
                        }
                        return true;
                    },
                    function () {
                        // هیچ نیازی به جبران برای مرحله فقط‌خواندنی/قفل‌گذاری نیست
                    }
                )
                ->addStep(
                    'create_escrow_record',
                    function () use ($executionId, $executorId, $advertiserId, $reward) {
                        $result = $this->escrow->holdFunds(
                            $executionId,
                            'social_task_execution',
                            $advertiserId,
                            $executorId,
                            $reward,
                            'IRT'
                        );
                        if (!$result['ok'] || !isset($result['escrow_id'])) {
                            throw new \Core\Exceptions\ApplicationException('خطا در ایجاد صندوق امانات: ' . ($result['error'] ?? 'خطای نامشخص'));
                        }
                        return $result['escrow_id'];
                    },
                    function ($error) use ($executionId) {
                        // Compensation: refund funds if escrow was partially created
                        $this->logger->warning('saga_compensate: cancelling escrow record', ['execution_id' => $executionId]);
                        $this->db->prepare("UPDATE escrow_transactions SET status = 'cancelled' WHERE order_id = ? AND order_type = ? AND status = 'pending'")
                                 ->execute([$executionId, 'social_task_execution']);
                    }
                )
                ->addStep(
                    'deduct_wallet_balance',
                    function ($escrowId) use ($executionId, $advertiserId, $reward) {
                        $this->assertFraudAllowed((int)$advertiserId, 'escrow.hold', []);
                        $this->wallet->withdraw($advertiserId, $reward, 'irt', [
                            'type' => 'social_task_escrow',
                            'execution_id' => $executionId,
                            'ref_id' => $executionId,
                            'ref_type' => 'social_task_execution'
                        ]);
                        return $escrowId;
                    },
                    function ($error) use ($executionId, $advertiserId, $reward) {
                        // Compensation: deposit funds back to wallet if deduction partially failed
                        $this->logger->warning('saga_compensate: reverting wallet deduction', ['advertiser_id' => $advertiserId]);
                        $this->wallet->deposit($advertiserId, $reward, 'irt', [
                            'type' => 'saga_compensation',
                            'execution_id' => $executionId
                        ]);
                    }
                )
                ->execute(); // اجرای تمام مراحل پشت سر هم
            });

            $this->logger->info('social_task.escrow_hold', [
                'execution_id' => $executionId,
                'executor_id' => $executorId,
                'adS_id' => $advertiserId,
                'amount' => $reward,
            ]);

            return ['ok' => true, 'escrow_id' => $result];

        } catch (\Throwable $e) {
            $this->captureEscrowException($e, 'holdSocialTaskFunds');
            $this->logger->error('social_task.escrow_hold.failed', ['error' => $e->getMessage()]);
            return ['ok' => false, 'error' => $e->getMessage()];
        }
        }, $payload);
    }

    /**
     * تایید و نگهداری مالی برای SocialTask
     * Admin approves execution → Move to in_escrow
     * @return FinancialResult
     */
    public function confirmSocialTaskEscrow(int $executionId, int $adviserId, ?string $idempotencyKey = null): array
    {
        $payload = ['execution_id' => $executionId, 'adviser_id' => $adviserId];
        return $this->executeWithIdempotency($idempotencyKey, $adviserId, "confirm_social_task_{$executionId}", function() use ($executionId, $adviserId, $payload) {
        try {
            // BUGFIX-SAGA-TX-ROOT + BUGFIX-SAGA-STALE-STEPS:
            // ۱) کل Saga داخل Transaction Root اجرا می‌شود (رجوع به توضیح holdSocialTaskFunds).
            // ۲) این متد قبلاً به‌جای setSaga()، مستقیم addStep() صدا می‌زد. چون
            //    SagaOrchestrator::execute() هرگز $this->steps را ریست نمی‌کند، اگر همین
            //    instance قبلاً برای Saga دیگری استفاده شده باشد، مراحلِ قدیمی هم دوباره
            //    اجرا می‌شدند (تأیید شده با تست). افزودن setSaga() این نشتِ state را می‌بندد.
            $result = $this->db->transactional(function () use ($executionId, $adviserId, $payload) {
                $stepResult = null;
                $this->saga
                    ->setSaga('confirm_social_task_escrow', $payload)
                    ->addStep(
                        'confirm_escrow_hold',
                        function () use ($executionId, $adviserId, &$stepResult) {
                            $stepResult = $this->escrow->confirmHold($executionId, 'social_task_execution', $adviserId);
                            if (!$stepResult['ok']) {
                                throw new \Core\Exceptions\ApplicationException($stepResult['error'] ?? 'خطا در تأیید صندوق امانات');
                            }
                            return true;
                        },
                        function () {}
                    )->execute();
                return $stepResult;
            });

            return $result;
        } catch (\Throwable $e) {
            $this->captureEscrowException($e, 'confirmSocialTaskEscrow');
            return ['ok' => false, 'error' => $e->getMessage()];
        }
        }, $payload);
    }

    /**
     * تحویل پول به executor
     * Admin releases → Transfer to executor wallet
     * @return FinancialResult
     */
    public function releaseSocialTaskFunds(
        int    $executionId,
        int    $executorId,
        int    $advertiserId,
        string $amount,
        ?string $idempotencyKey = null
    ): array {
        $payload = [
            'execution_id' => $executionId,
            'executor_id' => $executorId,
            'advertiser_id' => $advertiserId,
            'amount' => $amount,
        ];

        return $this->executeWithIdempotency(
            $idempotencyKey,
            $executorId,
            "release_social_task_{$executionId}",
            function () use ($executionId, $executorId, $advertiserId, $amount) {
                try {
                    return $this->db->transactional(function () use ($executionId, $executorId, $advertiserId, $amount) {
                        $escrow = $this->db->fetch(
                            "SELECT * FROM escrow_transactions
                             WHERE order_id = ? AND order_type = ? FOR UPDATE",
                            [$executionId, 'social_task_execution']
                        );
                        if (!$escrow || (string)$escrow->status !== 'in_escrow') {
                            throw new \Core\Exceptions\InvalidStateException('صندوق امانات در وضعیت مناسب نیست');
                        }
                        $escrow = $this->requireEscrowRow($escrow);
                        if ((int)$escrow->buyer_id !== $advertiserId || (int)$escrow->seller_id !== $executorId) {
                            throw new \Core\Exceptions\SecurityException('مالکیت صندوق امانات با تسک اجتماعی مطابقت ندارد');
                        }
                        if (bccomp((string)$escrow->amount, $amount, 8) !== 0) {
                            throw new \Core\Exceptions\InvalidStateException('مبلغ پاداش با مبلغ صندوق امانات مطابقت ندارد');
                        }

                        // Core service moves the state under the same transaction;
                        // settlement of the held wallet amount is explicit below.
                        $release = $this->escrow->releaseFunds((int)$escrow->id, $executorId, 'admin_release');
                        if (empty($release['ok'])) {
                            throw new \Core\Exceptions\ApplicationException($release['error'] ?? 'خطا در آزادسازی وجه امانی');
                        }

                        // The advertiser's existing hold is consumed, not refunded.
                        $this->settleEscrowHold($escrow, 'complete', (string)$escrow->amount);

                        $payout = $this->wallet->deposit($executorId, $amount, 'irt', [
                            'type' => 'social_task_reward',
                            'execution_id' => $executionId,
                            'ref_id' => $executionId,
                            'ref_type' => 'social_task_execution',
                            'description' => "پاداش اجرای تسک اجتماعی #{$executionId}",
                            'idempotency_key' => "social_task_payout_{$executionId}",
                        ]);
                        if (empty($payout['success'])) {
                            throw new \Core\Exceptions\ApplicationException($this->resultMessage($payout, 'message', 'واریز پاداش مجری انجام نشد'));
                        }

                        return [
                            'ok' => true,
                            'wallet_transaction' => $payout['transaction_id'] ?? null,
                            'escrow_id' => (int)$escrow->id,
                        ];
                    });
                } catch (\Throwable $e) {
                    $this->captureEscrowException($e, 'releaseSocialTaskFunds', ['execution_id' => $executionId]);
                    $this->logger->error('social_task.escrow_release.failed', ['execution_id' => $executionId, 'error' => $e->getMessage()]);
                    return ['ok' => false, 'error' => $e->getMessage()];
                }
            },
            $payload
        );
    }

    /**
     * بازگرداندی پول به تبلیغ‌دهنده (رد شدن، dispute)
     * @return FinancialResult
     */
    public function refundSocialTaskFunds(int $executionId, int $advertiserId, string $reason, ?string $idempotencyKey = null): array
    {
        return $this->refundOrderEscrow($executionId, 'social_task_execution', $advertiserId, $reason, 'admin_refund', $idempotencyKey);
    }

    // ──────────────────────────────────────────────────────────────────────────
    // Influencer Escrow (Buyer → Seller Payment)
    // ──────────────────────────────────────────────────────────────────────────

    /**
     * نگهداری پول برای سفارش اینفلوئنسر
     * @return FinancialResult
     */
    public function holdInfluencerOrderFunds(
        int    $orderId,
        int    $buyerId,
        int    $sellerId,
        string $amount,
        ?string $idempotencyKey = null
    ): array {
        $payload = ['order_id' => $orderId, 'buyer_id' => $buyerId, 'seller_id' => $sellerId, 'amount' => $amount];
        return $this->executeWithIdempotency($idempotencyKey, $buyerId, "hold_influencer_order_{$orderId}", function() use ($orderId, $buyerId, $sellerId, $amount, $payload) {
            // BUGFIX-SAGA-TX-ROOT: رجوع به توضیح کامل در holdSocialTaskFunds()
            $result = $this->db->transactional(function () use ($orderId, $buyerId, $sellerId, $amount, $payload) {
            return $this->saga
                ->setSaga('hold_influencer_order_escrow', $payload)
                ->addStep(
                    'verify_and_lock_buyer_balance',
                    function () use ($buyerId, $amount) {
                        // 🔒 Pessimistically lock the wallet row to prevent TOCTOU race conditions (BUG-02)
                        $this->db->query("SELECT id FROM wallets WHERE user_id = ? FOR UPDATE", [(int)$buyerId])->fetch();

                        // ✅ Verify buyer balance
                        $buyerBalance = $this->wallet->getBalanceForUpdate($buyerId, 'irt');

                        $buyerMoney = Money::fromString($buyerBalance, 'irt');
                        $amountMoney = Money::fromString($amount, 'irt');

                        if ($amountMoney->isGreaterThan($buyerMoney)) {
                            throw new \Core\Exceptions\InsufficientBalanceException('موجودی کیف پول خریدار کافی نیست');
                        }
                        return true;
                    },
                    function () {}
                )
                ->addStep(
                    'create_escrow_record',
                    function () use ($orderId, $buyerId, $sellerId, $amount) {
                        // ✅ Hold in escrow
                        $result = $this->escrow->holdFunds(
                            $orderId,
                            'influencer_order',
                            $buyerId,
                            $sellerId,
                            $amount,
                            'IRT'
                        );

                        if (!$result['ok'] || !isset($result['escrow_id'])) {
                            throw new \Core\Exceptions\ApplicationException('خطا در ایجاد صندوق امانات: ' . ($result['error'] ?? 'خطای نامشخص'));
                        }
                        return $result['escrow_id'];
                    },
                    function ($error) use ($orderId) {
                        $this->logger->warning('saga_compensate: cancelling influencer escrow record', ['order_id' => $orderId]);
                        $this->db->prepare("UPDATE escrow_transactions SET status = 'cancelled' WHERE order_id = ? AND order_type = ? AND status = 'pending'")
                                 ->execute([$orderId, 'influencer_order']);
                    }
                )
                ->addStep(
                    'deduct_wallet_balance',
                    function ($escrowId) use ($buyerId, $amount, $orderId) {
                        // ✅ Deduct from buyer wallet - calling withdraw since we are inside a database transaction
                        $this->assertFraudAllowed((int)$buyerId, 'escrow.hold', []);
                        $this->wallet->withdraw($buyerId, $amount, 'irt', [
                            'type' => 'influencer_escrow',
                            'order_id' => $orderId,
                            'ref_id' => $orderId,
                            'ref_type' => 'influencer_order'
                        ]);
                        return $escrowId;
                    },
                    function ($error) use ($buyerId, $amount, $orderId) {
                        $this->logger->warning('saga_compensate: reverting wallet deduction', ['buyer_id' => $buyerId]);
                        $this->wallet->deposit($buyerId, $amount, 'irt', [
                            'type' => 'saga_compensation',
                            'order_id' => $orderId
                        ]);
                    }
                )
                ->execute();
            });

            $escrow = $this->escrow->getByOrder($orderId, 'influencer_order');
            if ($escrow === null) {
                throw new \Core\Exceptions\ApplicationException('رکورد صندوق امانات اینفلوئنسر پس از نگهداری یافت نشد');
            }
            $escrow = $this->requireEscrowRow($escrow);
            return ['ok' => true, 'escrow_id' => (int)$escrow->id];
        }, $payload);
    }

    /**
     * تأیید نگهداری امن سفارش اینفلوئنسر پس از برداشت موفق از کیف پول خریدار.
     * وضعیت escrow از pending به in_escrow می‌رود تا فقط از مسیرهای release/refund حل شود.
     * @return FinancialResult
     */
    public function confirmInfluencerOrderFunds(int $orderId, int $sellerId, ?string $idempotencyKey = null): array
    {
        $payload = ['order_id' => $orderId, 'seller_id' => $sellerId];
        return $this->executeWithIdempotency($idempotencyKey, $sellerId, "confirm_influencer_order_{$orderId}", function() use ($orderId, $sellerId) {
            $result = $this->escrow->confirmHold($orderId, 'influencer_order', $sellerId);
            if (!empty($result['ok'])) {
                return $result;
            }

            $escrow = $this->escrow->getByOrder($orderId, 'influencer_order');
            if ($escrow !== null) {
                $escrow = $this->requireEscrowRow($escrow);
            }
            if ($escrow && in_array((string)$escrow->status, ['in_escrow', 'released', 'refunded', 'disputed'], true)) {
                return ['ok' => true, 'escrow_id' => (int)$escrow->id, 'already_confirmed' => true];
            }

            return ['ok' => false, 'error' => $result['error'] ?? 'Escrow confirm failed'];
        }, $payload);
    }

    /**
     * تحویل پول به فروشنده (اینفلوئنسر).
     *
     * نکته مالی مهم: مبلغ نگهداری‌شده در escrow برابر قیمت کامل سفارش است،
     * اما مبلغ واریزی به اینفلوئنسر بعد از کسر سهم سایت است. بنابراین هنگام
     * complete، hold کامل خریدار settle می‌شود و فقط sellerAmount به فروشنده واریز می‌شود.
     * @return FinancialResult
     */
    public function releaseInfluencerOrderFunds(int $orderId, int $sellerId, string $sellerAmount, ?string $idempotencyKey = null): array
    {
        $payload = ['order_id' => $orderId, 'seller_id' => $sellerId, 'seller_amount' => $sellerAmount];
        return $this->executeWithIdempotency($idempotencyKey, $sellerId, "release_influencer_order_{$orderId}", function () use ($orderId, $sellerId, $sellerAmount) {
            try {
                return $this->db->transactional(function () use ($orderId, $sellerId, $sellerAmount) {
                    $escrow = $this->db->fetch(
                        'SELECT * FROM escrow_transactions WHERE order_id = ? AND order_type = ? FOR UPDATE',
                        [$orderId, 'influencer_order']
                    );
                    if (!$escrow) throw new \Core\Exceptions\NotFoundException('صندوق امانات یافت نشد');
                    $escrow = $this->requireEscrowRow($escrow);
                    if ((int)$escrow->seller_id !== $sellerId) throw new \Core\Exceptions\SecurityException('عدم تطابق فروشنده با صندوق امانات');
                    if ((string)$escrow->status === 'released') return ['ok' => true, 'already_released' => true];
                    if (!in_array((string)$escrow->status, ['in_escrow', 'pending'], true)) throw new \Core\Exceptions\InvalidStateException('وضعیت صندوق امانات نامعتبر است');
                    if (bccomp($sellerAmount, '0', 8) < 0 || bccomp($sellerAmount, (string)$escrow->amount, 8) > 0) throw new \Core\Exceptions\InvalidStateException('مبلغ سهم فروشنده نامعتبر است');

                    $currency = strtolower((string)$escrow->currency) === 'usdt' ? 'usdt' : 'irt';
                    $platformAmount = bcsub((string)$escrow->amount, $sellerAmount, 8);
                    $holdType = 'influencer_escrow';
                    $hold = $this->findEscrowHoldTransaction((int)$escrow->buyer_id, $holdType, (string)$escrow->order_id, (string)$escrow->amount, $currency);
                    if (!$hold) throw new \Core\Exceptions\NotFoundException('تراکنش نگهداری وجه امانی یافت نشد');

                    $release = $this->escrow->releaseFunds((int)$escrow->id, $sellerId, 'influencer_order_complete');
                    if (empty($release['ok'])) throw new \Core\Exceptions\ApplicationException($release['error'] ?? 'خطا در آزادسازی وجه امانی');

                    if (bccomp($sellerAmount, '0', 8) > 0) {
                        $spent = $this->wallet->spendLockedFunds((int)$escrow->buyer_id, $sellerAmount, $currency, [
                            'type' => 'influencer_escrow_seller_spend', 'ref_id' => (int)$escrow->id, 'ref_type' => 'escrow',
                            'description' => "تسویه سهم اینفلوئنسر #{$orderId}", 'ledger_credit_account' => 'escrow_payout',
                            'idempotency_key' => "influencer_seller_spend_{$orderId}",
                        ]);
                        if (empty($spent['success'])) throw new \Core\Exceptions\ApplicationException($this->resultMessage($spent, 'message', 'مصرف وجه قفل‌شده انجام نشد'));
                        $payout = $this->wallet->deposit($sellerId, $sellerAmount, $currency, [
                            'type' => 'influencer_order_payment', 'order_id' => $orderId, 'ref_id' => $orderId, 'ref_type' => 'influencer_order',
                            'description' => "درآمد سفارش اینفلوئنسر #{$orderId}", 'ledger_debit_account' => 'escrow_payout',
                            'idempotency_key' => "influencer_payout_{$orderId}",
                        ]);
                        if (empty($payout['success'])) throw new \Core\Exceptions\ApplicationException($this->resultMessage($payout, 'message', 'واریز سهم فروشنده انجام نشد'));
                    }
                    if (bccomp($platformAmount, '0', 8) > 0) {
                        $platform = $this->wallet->spendLockedFunds((int)$escrow->buyer_id, $platformAmount, $currency, [
                            'type' => 'influencer_escrow_platform_fee', 'ref_id' => (int)$escrow->id, 'ref_type' => 'escrow',
                            'description' => "کارمزد سفارش اینفلوئنسر #{$orderId}", 'ledger_credit_account' => 'platform_revenue',
                            'idempotency_key' => "influencer_platform_fee_{$orderId}",
                        ]);
                        if (empty($platform['success'])) throw new \Core\Exceptions\ApplicationException($this->resultMessage($platform, 'message', 'مصرف کارمزد انجام نشد'));
                    }
                    if (!$this->wallet->finalizeLockedSpend((int)$escrow->buyer_id, (string)$hold->transaction_id)) throw new \Core\Exceptions\ApplicationException('نهایی‌سازی hold خریدار انجام نشد');
                    return ['ok' => true, 'seller_amount' => $sellerAmount, 'platform_amount' => $platformAmount];
                });
            } catch (\Throwable $e) {
                $this->captureEscrowException($e, 'releaseInfluencerOrderFunds', ['order_id' => $orderId]);
                return ['ok' => false, 'error' => $e->getMessage()];
            }
        }, $payload);
    }

    /**
     * بازگشت کامل مبلغ سفارش اینفلوئنسر به خریدار از escrow.
     * @return FinancialResult
     */
    public function refundInfluencerOrderFunds(int $orderId, int $buyerId, string $reason, ?string $idempotencyKey = null): array
    {
        return $this->refundOrderEscrow($orderId, 'influencer_order', $buyerId, $reason, 'influencer_order_refund', $idempotencyKey);
    }

    // ──────────────────────────────────────────────────────────────────────────
    // Vitrine Escrow (Buyer → Seller Payment)
    // ──────────────────────────────────────────────────────────────────────────

    /**
     * نگهداری پول برای آگهی ویترین
     * @return FinancialResult
     */
    public function holdVitrineFunds(
        int    $listingId,
        int    $buyerId,
        int    $sellerId,
        string $amount,
        ?string $idempotencyKey = null
    ): array {
        $payload = ['listing_id' => $listingId, 'buyer_id' => $buyerId, 'seller_id' => $sellerId, 'amount' => $amount];
        return $this->executeWithIdempotency($idempotencyKey, $buyerId, "hold_vitrine_{$listingId}", function() use ($listingId, $buyerId, $sellerId, $amount, $payload) {
            // BUGFIX-SAGA-TX-ROOT: رجوع به توضیح کامل در holdSocialTaskFunds()
            $result = $this->db->transactional(function () use ($listingId, $buyerId, $sellerId, $amount, $payload) {
            return $this->saga
                ->setSaga('hold_vitrine_escrow', $payload)
                ->addStep(
                    'verify_and_lock_buyer_balance',
                    function () use ($buyerId, $amount) {
                        // 🔒 Pessimistically lock the wallet row to prevent TOCTOU race conditions (BUG-02)
                        $this->db->query("SELECT id FROM wallets WHERE user_id = ? FOR UPDATE", [(int)$buyerId])->fetch();

                        // ✅ Verify buyer
                        $buyerBalance = $this->wallet->getBalanceForUpdate($buyerId, 'usdt');

                        $buyerMoney = Money::fromString($buyerBalance, 'usdt');
                        $amountMoney = Money::fromString($amount, 'usdt');

                        if ($amountMoney->isGreaterThan($buyerMoney)) {
                            throw new \Core\Exceptions\InsufficientBalanceException('موجودی حساب کافی نیست');
                        }
                        return true;
                    },
                    function () {}
                )
                ->addStep(
                    'create_escrow_record',
                    function () use ($listingId, $buyerId, $sellerId, $amount) {
                        // ✅ Hold escrow
                        $result = $this->escrow->holdFunds(
                            $listingId,
                            'vitrine_listing',
                            $buyerId,
                            $sellerId,
                            $amount,
                            'USDT'
                        );

                        if (!$result['ok'] || !isset($result['escrow_id'])) {
                            throw new \Core\Exceptions\ApplicationException('خطا در ایجاد صندوق امانات: ' . ($result['error'] ?? 'خطای نامشخص'));
                        }
                        return $result['escrow_id'];
                    },
                    function ($error) use ($listingId) {
                        $this->logger->warning('saga_compensate: cancelling vitrine escrow record', ['listing_id' => $listingId]);
                        $this->db->prepare("UPDATE escrow_transactions SET status = 'cancelled' WHERE order_id = ? AND order_type = ? AND status = 'pending'")
                                 ->execute([$listingId, 'vitrine_listing']);
                    }
                )
                ->addStep(
                    'deduct_wallet_balance',
                    function ($escrowId) use ($buyerId, $amount, $listingId) {
                        // ✅ Deduct from buyer - calling withdraw since we are inside a database transaction
                        $this->assertFraudAllowed((int)$buyerId, 'escrow.hold', []);
                        $this->wallet->withdraw($buyerId, $amount, 'usdt', [
                            'type' => 'vitrine_escrow',
                            'listing_id' => $listingId,
                            'ref_id' => $listingId,
                            'ref_type' => 'vitrine_listing'
                        ]);
                        return $escrowId;
                    },
                    function ($error) use ($buyerId, $amount, $listingId) {
                        $this->logger->warning('saga_compensate: reverting wallet deduction', ['buyer_id' => $buyerId]);
                        $this->wallet->deposit($buyerId, $amount, 'usdt', [
                            'type' => 'saga_compensation',
                            'listing_id' => $listingId
                        ]);
                    }
                )
                ->execute();
            });

            $escrow = $this->escrow->getByOrder($listingId, 'vitrine_listing');
            if ($escrow === null) {
                throw new \Core\Exceptions\ApplicationException('رکورد صندوق امانات ویترین پس از نگهداری یافت نشد');
            }
            $escrow = $this->requireEscrowRow($escrow);
            return ['ok' => true, 'escrow_id' => (int)$escrow->id];
        }, $payload);
    }

    /**
     * تحویل پول به فروشنده (ویترین)
     * @return FinancialResult
     */
    public function releaseVitrineFunds(int $listingId, int $sellerId, string $amount, ?string $idempotencyKey = null): array
    {
        $payload = ['listing_id' => $listingId, 'seller_id' => $sellerId, 'amount' => $amount];
        return $this->executeWithIdempotency($idempotencyKey, $sellerId, "release_vitrine_{$listingId}", function () use ($listingId, $sellerId, $amount) {
            try {
                return $this->db->transactional(function () use ($listingId, $sellerId, $amount) {
                    $escrow=$this->db->fetch('SELECT * FROM escrow_transactions WHERE order_id=? AND order_type=? FOR UPDATE',[$listingId,'vitrine_listing']);
                    if(!$escrow) throw new \Core\Exceptions\NotFoundException('صندوق امانات یافت نشد');
                    $escrow = $this->requireEscrowRow($escrow);
                    if((int)$escrow->seller_id!==$sellerId) throw new \Core\Exceptions\SecurityException('عدم تطابق فروشنده با صندوق امانات');
                    if((string)$escrow->status==='released') return ['ok'=>true,'already_released'=>true];
                    if((string)$escrow->status!=='in_escrow') throw new \Core\Exceptions\InvalidStateException('وضعیت صندوق امانات نامعتبر است');
                    if(bccomp((string)$escrow->amount,$amount,8)!==0) throw new \Core\Exceptions\InvalidStateException('مبلغ تسویه با مبلغ escrow مطابقت ندارد');
                    $currency=strtolower((string)$escrow->currency)==='usdt'?'usdt':'irt';
                    $commissionSetting = $this->appSettings->get('vitrine_commission_percent', '5');
                    if (!is_scalar($commissionSetting) || trim((string)$commissionSetting) === '') {
                        throw new \UnexpectedValueException('درصد کارمزد ویترین معتبر نیست');
                    }
                    $commissionPercent = (string)$commissionSetting;
                    $total=Money::fromString($amount,$currency);$commission=$total->percentage($commissionPercent);$net=$total->subtract($commission);
                    $hold=$this->findEscrowHoldTransaction((int)$escrow->buyer_id,'vitrine_escrow',(string)$escrow->order_id,(string)$escrow->amount,$currency);
                    if(!$hold) throw new \Core\Exceptions\NotFoundException('تراکنش نگهداری وجه امانی یافت نشد');
                    $release=$this->escrow->releaseFunds((int)$escrow->id,$sellerId,'vitrine_sale_complete');
                    if(empty($release['ok'])) throw new \Core\Exceptions\ApplicationException($this->resultMessage($release, 'error', 'خطا در آزادسازی وجه امانی'));
                    if(!$net->isZero()) {
                        $spend=$this->wallet->spendLockedFunds((int)$escrow->buyer_id,$net->getAmount(),$currency,['type'=>'vitrine_seller_spend','ref_id'=>(int)$escrow->id,'ref_type'=>'escrow','description'=>"تسویه فروش ویترین #{$listingId}",'ledger_credit_account'=>'escrow_payout','idempotency_key'=>"vitrine_seller_spend_{$listingId}"]);
                        if(empty($spend['success'])) throw new \Core\Exceptions\ApplicationException($this->resultMessage($spend, 'message', 'مصرف سهم فروشنده ناموفق بود'));
                        $payout=$this->wallet->deposit($sellerId,$net->getAmount(),$currency,['type'=>'vitrine_sale','listing_id'=>$listingId,'ref_id'=>$listingId,'ref_type'=>'vitrine_listing','description'=>"درآمد فروش ویترین #{$listingId}",'ledger_debit_account'=>'escrow_payout','idempotency_key'=>"vitrine_payout_{$listingId}"]);
                        if(empty($payout['success'])) throw new \Core\Exceptions\ApplicationException($this->resultMessage($payout, 'message', 'واریز فروشنده ناموفق بود'));
                    }
                    if(!$commission->isZero()) {
                        $fee=$this->wallet->spendLockedFunds((int)$escrow->buyer_id,$commission->getAmount(),$currency,['type'=>'vitrine_platform_fee','ref_id'=>(int)$escrow->id,'ref_type'=>'escrow','description'=>"کارمزد فروش ویترین #{$listingId}",'ledger_credit_account'=>'platform_revenue','idempotency_key'=>"vitrine_platform_fee_{$listingId}"]);
                        if(empty($fee['success'])) throw new \Core\Exceptions\ApplicationException($this->resultMessage($fee, 'message', 'مصرف کارمزد ناموفق بود'));
                    }
                    if(!$this->wallet->finalizeLockedSpend((int)$escrow->buyer_id,(string)$hold->transaction_id)) throw new \Core\Exceptions\ApplicationException('نهایی‌سازی hold خریدار ناموفق بود');
                    return ['ok'=>true,'net_amount'=>$net->getAmount(),'commission'=>$commission->getAmount()];
                });
            }catch(\Throwable $e){$this->captureEscrowException($e,'releaseVitrineFunds',['listing_id'=>$listingId]);return['ok'=>false,'error'=>$e->getMessage()];}
        },$payload);
    }

    /**
     * بازگرداندی پول به خریدار (ویترین)
     * @return FinancialResult
     */
    public function refundVitrineFunds(int $listingId, int $buyerId, string $reason, ?string $idempotencyKey = null): array
    {
        return $this->refundOrderEscrow($listingId, 'vitrine_listing', $buyerId, $reason, 'vitrine_refund', $idempotencyKey);
    }

    // ──────────────────────────────────────────────────────────────────────────
    // Common Dispute Handling
    // ──────────────────────────────────────────────────────────────────────────

    /**
     * Mark escrow as disputed (freezes funds)
     * @return FinancialResult
     */
    public function markEscrowDisputed(int $orderId, string $orderType, string $reason, ?string $idempotencyKey = null): array
    {
        $payload = ['order_id' => $orderId, 'order_type' => $orderType, 'reason' => $reason];
        $escrowForUser = $this->escrow->getByOrder($orderId, $orderType);
        $idempotencyUserId = $escrowForUser === null
            ? 1
            : (int)$this->requireEscrowRow($escrowForUser)->buyer_id;
        return $this->executeWithIdempotency($idempotencyKey, $idempotencyUserId, "mark_disputed_{$orderType}_{$orderId}", function() use ($orderId, $orderType, $reason, $payload) {
            try {
                // BUGFIX-SAGA-TX-ROOT: رجوع به توضیح کامل در holdSocialTaskFunds()
                $result = $this->db->transactional(function () use ($orderId, $orderType, $reason, $payload) {
                    $stepResult = null;
                    $this->saga->setSaga('mark_escrow_disputed', $payload)->addStep(
                        'mark_escrow_disputed',
                        function () use ($orderId, $orderType, $reason, &$stepResult) {
                            $escrow = $this->escrow->getByOrder($orderId, $orderType);
                            if (!$escrow) {
                                throw new \Core\Exceptions\NotFoundException('صندوق امانات یافت نشد');
                            }
                            $escrow = $this->requireEscrowRow($escrow);

                            $stepResult = $this->escrow->markAsDisputed((int)$escrow->id, $reason);
                            if (!$stepResult['ok']) {
                                throw new \Core\Exceptions\ApplicationException($stepResult['error'] ?? 'خطا در ثبت وضعیت اختلاف');
                            }
                            return true;
                        },
                        function () {}
                    )->execute();
                    return $stepResult;
                });

                return $result;
            } catch (\Throwable $e) {
            $this->captureEscrowException($e, 'markEscrowDisputed');
                return ['ok' => false, 'error' => $e->getMessage()];
            }
        }, $payload);
    }

    /**
     * Resolve dispute and release/refund based on verdict
     * @return FinancialResult
     */
    public function resolveDisputedEscrow(
        int    $orderId,
        string $orderType,
        string $verdict,
        string $refundPercent,
        ?string $idempotencyKey = null
    ): array {
        if (!is_numeric($refundPercent) || bccomp($refundPercent, '0', 8) < 0 || bccomp($refundPercent, '100', 8) > 0) {
            return ['ok' => false, 'error' => 'Refund percentage must be between 0 and 100'];
        }
        $payload = ['order_id' => $orderId, 'order_type' => $orderType, 'verdict' => $verdict, 'refund_percent' => $refundPercent];
        $escrowForUser = $this->escrow->getByOrder($orderId, $orderType);
        $idempotencyUserId = $escrowForUser === null
            ? 1
            : (int)$this->requireEscrowRow($escrowForUser)->buyer_id;
        return $this->executeWithIdempotency($idempotencyKey, $idempotencyUserId, "resolve_disputed_{$orderType}_{$orderId}", function() use ($orderId, $orderType, $verdict, $refundPercent, $payload) {
            try {
                // BUGFIX-SAGA-TX-ROOT: رجوع به توضیح کامل در holdSocialTaskFunds()
                $releaseAmount = null;
                $refundAmount = null;

                $this->db->transactional(function () use ($orderId, $orderType, $verdict, $refundPercent, $payload, &$releaseAmount, &$refundAmount) {
                return $this->saga->setSaga('resolve_disputed_escrow', $payload)->addStep(
                    'verify_and_resolve_dispute',
                    function () use ($orderId, $orderType, $verdict, $refundPercent, &$releaseAmount, &$refundAmount) {
                        $escrow = $this->escrow->getByOrder($orderId, $orderType);
                        if (!$escrow) {
                            throw new \Core\Exceptions\InvalidStateException('پرونده در وضعیت اختلاف نیست');
                        }
                        $escrow = $this->requireEscrowRow($escrow);
                        if ($escrow->status !== 'disputed') {
                            throw new \Core\Exceptions\InvalidStateException('پرونده در وضعیت اختلاف نیست');
                        }

                        $scale = strtolower((string)$escrow->currency) === 'usdt' ? 8 : 4;
                        $percent = bcdiv($refundPercent, '100', 8);
                        $refundAmount = \Core\ValueObjects\Money::fromString((string)((string)$escrow->amount))->multiply((string)($percent))->getAmount();
                        $releaseAmount = \Core\ValueObjects\Money::fromString((string)((string)$escrow->amount))->subtract(\Core\ValueObjects\Money::fromString((string)($refundAmount)))->getAmount();

                        $result = $this->escrow->resolveDisputePartial(
                            (int)$escrow->id,
                            (int)$escrow->buyer_id,
                            (int)$escrow->seller_id,
                            $refundAmount,
                            $releaseAmount,
                            'admin_dispute_resolution',
                            $verdict
                        );

                        if (!$result['ok']) {
                            throw new \Core\Exceptions\ApplicationException($result['error'] ?? 'خطا در حل‌وفصل اختلاف');
                        }
                        return ['escrow_id' => (int)$escrow->id];
                    },
                    function ($error) use ($orderId, $orderType) {
                        $this->logger->warning('saga_compensate: reverting dispute resolution', ['order_id' => $orderId, 'order_type' => $orderType]);
                        $this->db->prepare("UPDATE escrow_transactions SET status = 'disputed', released_at = NULL, released_by = NULL WHERE order_id = ? AND order_type = ?")
                                 ->execute([$orderId, $orderType]);
                    }
                )->addStep(
                    'deposit_resolved_funds',
                    function (array $ctx) use ($orderId, $orderType, &$releaseAmount) {
                        $escrow = $this->escrow->getByOrder($orderId, $orderType);
                        if (!$escrow) {
                            throw new \Core\Exceptions\NotFoundException('صندوق امانات یافت نشد');
                        }
                        $escrow = $this->requireEscrowRow($escrow);
                        $currency = strtoupper((string)$escrow->currency) === 'USDT' ? 'usdt' : 'irt';

                        // First unlock the original escrow hold back to buyer. Then, if the
                        // verdict releases part/all of the funds to seller, spend exactly that
                        // amount from buyer and credit seller. This keeps wallet locked balances
                        // consistent for partial dispute resolutions.
                        $this->settleEscrowHold($escrow, 'cancel', (string)$escrow->amount);

                        if (bccomp((string)$releaseAmount, '0', 8) > 0) {
                            $this->spendReleasedBuyerAmount($escrow, (string)$releaseAmount, $currency);
                            $this->wallet->deposit((int)$escrow->seller_id, (string)$releaseAmount, $currency, [
                                'type' => 'dispute_release',
                                'order_id' => $orderId
                            ]);
                        }
                        return true;
                    },
                    function (\Throwable $e) use ($orderId) {
                        $this->logger->warning('saga_compensate: reverting dispute resolution deposits', ['order_id' => $orderId]);
                    }
                )->execute();
                });

                return ['ok' => true, 'released' => $releaseAmount, 'refunded' => $refundAmount];

            } catch (\Throwable $e) {
            $this->captureEscrowException($e, 'resolveDisputedEscrow');
                return ['ok' => false, 'error' => $e->getMessage()];
            }
        }, $payload);
    }

    /**
     * Cron task to automatically release expired holds back to the advertiser.
     * CRITICAL-NEW-02: Prevent funds from being locked forever if executor never submits proof.
     */
    /**
     * انواع منقضی‌شونده بر اساس قرارداد واقعی order_type dispatch می‌شوند:
     * - social_task_execution / influencer_order / vitrine_listing / custom_deal
     *   از مسیر refund canonical خودشان
     * - custom_task / lottery_participation از مسیر generic locked-hold refund
     */
    public function releaseExpiredHolds(): int
    {
        $expirySetting = $this->appSettings->get('escrow_expiry_hours', 48);
        $expiredHours = is_scalar($expirySetting)
            ? max(1, min(8760, (int)$expirySetting))
            : 48;

        // The interval is materialized only after a strict integer bound, keeping
        // the SQL cutoff explicit without accepting a dynamic SQL fragment.
        // Concrete refund methods lock their individual escrow rows inside their
        // own transactions; FOR UPDATE on this discovery query would be released
        // immediately under autocommit and give a false sense of safety.
        $totalReleased = 0;
        $batchSize     = 200;
        $lastId        = 0;
        $guard         = 0;
        // cursor: هر batch حداکثر 200 ردیف؛ چون هر refund تراکنشی و سنگین است حافظه/زمان را
        // مهار می‌کنیم. ردیفِ refundشده دیگر status='pending' نیست و از فیلتر خارج می‌شود؛
        // ردیف‌های ناموفق pending می‌مانند، پس cursorِ id لازم است تا دوباره انتخاب/لوپ نشوند.
        do {
            if (++$guard > 100000) {
                $this->logger->warning('escrow.release_expired.guard_tripped', ['last_id' => $lastId]);
                break;
            }

            $expired = $this->db->fetchAll(
                "SELECT e.*
                 FROM escrow_transactions e
                 WHERE e.status = 'pending'
                   AND e.held_at < DATE_SUB(NOW(), INTERVAL {$expiredHours} HOUR)
                   AND e.id > ?
                 ORDER BY e.id ASC
                 LIMIT {$batchSize}",
                [$lastId]
            ) ?: [];
            $fetched = count($expired);

        foreach ($expired as $row) {
            try {
                $rawId = is_object($row) ? (int)($row->id ?? 0) : (int)(($row['id'] ?? 0));
                if ($rawId > $lastId) {
                    $lastId = $rawId;
                }
                $row = $this->requireEscrowRow($row);
                switch ($row->order_type) {
                    case 'social_task_execution':
                        $result = $this->refundSocialTaskFunds((int)$row->order_id, (int)$row->buyer_id, 'auto_expired_after_' . $expiredHours . 'h');
                        break;
                    case 'vitrine_listing':
                        $result = $this->refundVitrineFunds((int)$row->order_id, (int)$row->buyer_id, 'auto_expired_after_' . $expiredHours . 'h');
                        break;
                    case 'influencer_order':
                        $result = $this->refundInfluencerOrderFunds((int)$row->order_id, (int)$row->buyer_id, 'auto_expired_after_' . $expiredHours . 'h');
                        break;
                    case 'custom_deal':
                        $result = $this->refundCustomDealFunds((int)$row->id, (int)$row->buyer_id, 'auto_expired_after_' . $expiredHours . 'h');
                        break;
                    case 'custom_task':
                    case 'lottery_participation':
                        $this->refundGenericFunds((int)$row->id, (int)$row->buyer_id, $row->order_type, 'auto_expired_after_' . $expiredHours . 'h');
                        $result = ['ok' => true];
                        break;
                    default:
                        $this->logger->warning('escrow.unknown_order_type_on_release', [
                            'escrow_id' => $row->id,
                            'order_type' => $row->order_type,
                        ]);
                        continue 2;
                }

                if (!empty($result['ok'])) {
                    $totalReleased++;
                }
            } catch (\Throwable $e) {
                $this->captureEscrowException($e, 'releaseExpiredHolds');
                $this->logger->error('escrow.release_failed_for_row', [
                    'escrow_id' => $row->id ?? 0,
                    'order_type' => $row->order_type ?? 'unknown',
                    'error' => $e->getMessage(),
                ]);
            }
        }
        } while ($fetched === $batchSize);

        return $totalReleased;
    }

    /**
     * Generic refund for non-specific escrow types
     */
    /**
     * Generic refund for non-specific escrow types.
     * 🛡️ Atomicity: wrapped in DB transaction to prevent double-refund race conditions.
     * Matches the saga-based pattern used by refundSocialTaskFunds/refundVitrineFunds.
     */
    private function refundGenericFunds(
        int $escrowId,
        int $buyerId,
        string $orderType,
        string $reason
    ): void {
        $this->db->transactional(function () use ($escrowId, $buyerId, $orderType, $reason) {
            // 1. Lock escrow row FOR UPDATE to prevent concurrent refunds
            $escrow = $this->db->fetch(
                "SELECT * FROM escrow_transactions WHERE id = ? FOR UPDATE",
                [$escrowId]
            );

            if (!$escrow) {
                $this->logger->warning('escrow.refund_not_found', ['escrow_id' => $escrowId]);
                return;
            }
            $escrow = $this->requireEscrowRow($escrow);

            // 2. Guard: only refund pending or in_escrow rows
            if (!in_array((string)$escrow->status, ['pending', 'in_escrow'], true)) {
                $this->logger->warning('escrow.already_processed', [
                    'escrow_id' => $escrowId,
                    'status'    => $escrow->status,
                ]);
                return;
            }

            // 3. Settle the hold (cancel mode releases locked funds)
            $this->settleEscrowHold($escrow, 'cancel', (string)$escrow->amount);

            // 4. Update escrow status atomically
            $this->db->query(
                "UPDATE escrow_transactions
                 SET status = 'refunded', refunded_at = NOW(), refund_reason = ?,
                     refunded_by = 'escrow_timeout_job', updated_at = NOW()
                 WHERE id = ? AND status IN ('pending', 'in_escrow')",
                [$reason, $escrowId]
            );

            $this->logger->info('escrow.auto_released', [
                'escrow_id' => $escrowId,
                'order_type' => $orderType,
                'buyer_id' => $buyerId,
                'amount' => (string)$escrow->amount,
            ]);
        });
    }


    /**
     * Database rows are dynamic stdClass values. Validate the financial columns once
     * at the boundary, then use a precise contract throughout settlement code.
     *
     * @param object $escrow
     * @return EscrowRow
     */
    private function requireEscrowRow(object $escrow): object
    {
        foreach (['id', 'order_id', 'order_type', 'buyer_id', 'seller_id', 'amount', 'currency', 'status'] as $field) {
            if (!isset($escrow->{$field}) || !is_scalar($escrow->{$field})) {
                throw new \UnexpectedValueException("Invalid escrow row: missing or non-scalar {$field}");
            }
        }

        /** @var EscrowRow $escrow */
        return $escrow;
    }

    /**
     * @param object $transaction
     * @return EscrowHoldRow
     */
    private function requireEscrowHoldRow(object $transaction): object
    {
        if (!isset($transaction->transaction_id) || !is_string($transaction->transaction_id)
            || !isset($transaction->amount) || !is_scalar($transaction->amount)) {
            throw new \UnexpectedValueException('Invalid escrow hold transaction row');
        }

        /** @var EscrowHoldRow $transaction */
        return $transaction;
    }

    /** @param EscrowRow $escrow */
    private function settleEscrowHold(object $escrow, string $mode, ?string $amount = null): void
    {
        $currency = strtolower((string)$escrow->currency) === 'usdt' ? 'usdt' : 'irt';
        $holdType = match ((string)$escrow->order_type) {
            'influencer_order' => 'influencer_escrow',
            'vitrine_listing', 'vitrine' => 'vitrine_escrow',
            'social_task_execution' => 'social_task_escrow',
            'custom_deal' => 'custom_deal_escrow',
            default => 'escrow_hold',
        };

        $tx = $this->findEscrowHoldTransaction(
            (int)$escrow->buyer_id,
            $holdType,
            (string)$escrow->order_id,
            $amount ?? (string)$escrow->amount,
            $currency
        );

        if (!$tx) {
            throw new \Core\Exceptions\NotFoundException('تراکنش نگهداری وجه امانی یافت نشد');
        }

        if ($mode === 'complete') {
            if (!$this->wallet->completeWithdrawal((int)$escrow->buyer_id, (string)$tx->amount, $currency, (string)$tx->transaction_id)) {
                throw new \Core\Exceptions\ApplicationException('خطا در تکمیل تراکنش صندوق امانات');
            }
            return;
        }

        if ($mode === 'cancel') {
            if (!$this->wallet->cancelWithdrawal((int)$escrow->buyer_id, (string)$tx->amount, $currency, (string)$tx->transaction_id)) {
                throw new \Core\Exceptions\ApplicationException('خطا در لغو تراکنش صندوق امانات');
            }
            return;
        }

        throw new \InvalidArgumentException('Invalid escrow settlement mode');
    }

    /** @return EscrowHoldRow|null */
    private function findEscrowHoldTransaction(int $userId, string $type, string $orderId, string $amount, string $currency): ?object
    {
        $currency = strtolower($currency) === 'usdt' ? 'usdt' : 'irt';
        $transaction = $this->db->fetch(
            "SELECT * FROM transactions
             WHERE user_id = ? AND type = 'withdraw' AND currency = ?
               AND status IN ('pending', 'processing')
               AND ABS(amount - ?) < 0.00000001
               AND metadata LIKE ?
               AND (
                    ref_id = ?
                    OR metadata LIKE ?
                    OR metadata LIKE ?
                    OR metadata LIKE ?
               )
             ORDER BY id DESC LIMIT 1",
            [
                $userId,
                $currency,
                $amount,
                '%"type":"' . $type . '"%',
                $orderId,
                '%"order_id":' . $orderId . '%',
                '%"listing_id":' . $orderId . '%',
                '%"execution_id":' . $orderId . '%',
            ]
        );

        return $transaction === null ? null : $this->requireEscrowHoldRow($transaction);
    }

    /** @param EscrowRow $escrow */
    private function spendReleasedBuyerAmount(object $escrow, string $amount, string $currency): void
    {
        if (bccomp($amount, '0', 8) <= 0) {
            return;
        }

        $res = $this->wallet->withdraw((int)$escrow->buyer_id, $amount, $currency, [
            'type' => 'escrow_dispute_release_spend',
            'order_id' => (int)$escrow->order_id,
            'ref_id' => (string)$escrow->order_id . ':dispute_release',
            'ref_type' => (string)$escrow->order_type,
        ]);
        if (empty($res['success']) || empty($res['transaction_id'])) {
            throw new \Core\Exceptions\ApplicationException('خطا در رزرو مبلغ حل اختلاف');
        }
        $transactionId = $res['transaction_id'] ?? null;
        if (!is_string($transactionId) || !$this->wallet->completeWithdrawal((int)$escrow->buyer_id, $amount, $currency, $transactionId)) {
            throw new \Core\Exceptions\ApplicationException('خطا در تسویه مبلغ حل اختلاف');
        }
    }

    /**
     * INTERNAL_API: مصرف بخشی از یک بودجه قفل‌شده بدون واریز به کاربر دیگر.
     *
     * این متد ad-specific نیست؛ فقط escrow.amount و wallets.locked_* را اتمیک کم می‌کند.
     * برای درآمد پلتفرم/مصرف بودجه کمپین‌هایی استفاده می‌شود که seller user ندارند.
     * @return FinancialResult
     */
    public function consumeHeldBudget(
        int $escrowId,
        int $buyerId,
        string $amount,
        string $currency,
        string $reason,
        string $releasedBy = 'system',
        ?string $idempotencyKey = null
    ): array {
        $operation = function () use ($escrowId, $buyerId, $amount, $currency, $reason, $releasedBy, $idempotencyKey) {
            if (bccomp($amount, '0', 8) <= 0) {
                return ['ok' => true, 'released' => '0'];
            }
            $currency = strtolower((string)$currency) === 'usdt' ? 'usdt' : 'irt';

            $escrow = $this->db->fetch(
                "SELECT * FROM escrow_transactions WHERE id = ? AND buyer_id = ? AND status IN ('pending','in_escrow','partial') FOR UPDATE",
                [$escrowId, $buyerId]
            );
            if (!$escrow) {
                return ['ok' => false, 'error' => 'Escrow not found or not consumable'];
            }
            $escrow = $this->requireEscrowRow($escrow);
            if (bccomp((string)$escrow->amount, $amount, 8) < 0) {
                return ['ok' => false, 'error' => 'Escrow amount is insufficient'];
            }

            // Consumption is not a refund. It removes funds from locked balance
            // and settles them to platform revenue without crediting the buyer.
            $spendKey = $idempotencyKey ?: hash('sha256', implode('|', [
                'escrow.consume', $escrowId, (string)$escrow->amount, $amount, $reason, $releasedBy,
            ]));
            $spendResult = $this->wallet->spendLockedFunds(
                $buyerId,
                $amount,
                $currency,
                [
                    'type' => 'escrow_budget_consumption',
                    'ref_id' => $escrowId,
                    'ref_type' => 'escrow',
                    'description' => $reason,
                    'ledger_credit_account' => 'platform_revenue',
                    'idempotency_key' => $spendKey,
                ]
            );
            if (empty($spendResult['success'])) {
                throw new \Core\Exceptions\ApplicationException($this->resultMessage($spendResult, 'message', 'خطا در مصرف بودجه قفل‌شده کیف پول'));
            }

            $remaining = bcsub((string)$escrow->amount, $amount, 8);
            if (bccomp($remaining, '0', 8) < 0) {
                $remaining = '0';
            }
            $status = bccomp($remaining, '0', 8) <= 0 ? 'released' : 'partial';
            $this->db->query(
                "UPDATE escrow_transactions
                 SET status = ?, amount = ?, partial_released = COALESCE(partial_released, 0) + ?,
                     released_at = CASE WHEN ? = 'released' THEN NOW() ELSE released_at END,
                     released_by = CASE WHEN ? = 'released' THEN ? ELSE released_by END,
                     updated_at = NOW()
                 WHERE id = ?",
                [$status, $remaining, $amount, $status, $status, $releasedBy, $escrowId]
            );

            $this->logger->info('financial_escrow.held_budget_consumed', [
                'escrow_id' => $escrowId,
                'buyer_id' => $buyerId,
                'amount' => $amount,
                'reason' => $reason,
            ]);

            return ['ok' => true, 'released' => $amount, 'remaining' => $remaining, 'status' => $status];
        };

        if ($this->db->inTransaction()) {
            return $this->normalizeFinancialResult($operation());
        }
        return $this->normalizeFinancialResult($this->db->transactional(fn() => $operation()));
    }

    /**
     * INTERNAL_API: refund موجودی باقی‌مانده یک budget escrow به خریدار.
     * این متد عمومی escrow/wallet است و منطق جدول ads را نمی‌شناسد.
     * @return FinancialResult
     */
    public function refundHeldBudget(
        int $escrowId,
        int $buyerId,
        string $reason,
        string $refundedBy = 'system',
        ?string $idempotencyKey = null
    ): array {
        $operation = function () use ($escrowId, $buyerId, $reason, $refundedBy, $idempotencyKey) {
            $escrow = $this->db->fetch(
                "SELECT * FROM escrow_transactions WHERE id = ? AND buyer_id = ? AND status IN ('pending','in_escrow','partial') FOR UPDATE",
                [$escrowId, $buyerId]
            );
            if (!$escrow) {
                return ['ok' => false, 'error' => 'Escrow not found or not refundable'];
            }
            $escrow = $this->requireEscrowRow($escrow);
            $amount = (string)$escrow->amount;
            $currency = strtolower((string)$escrow->currency) === 'usdt' ? 'usdt' : 'irt';
            $balanceField = $currency === 'usdt' ? 'balance_usdt' : 'balance_irt';
            $lockedField = $currency === 'usdt' ? 'locked_usdt' : 'locked_irt';

            if (bccomp($amount, '0', 8) > 0) {
                $releaseResult = $this->wallet->releaseLockedFunds(
                    $buyerId, $amount, $currency,
                    ['type' => 'escrow_refund', 'ref_id' => $escrowId, 'description' => 'بازگشت وجه امانی', 'idempotency_key' => $idempotencyKey ?: ('escrow_budget_refund:' . $escrowId)]
                );
                if (empty($releaseResult['success'])) {
                    throw new \Core\Exceptions\ApplicationException('خطا در بازگشت بودجه قفل‌شده کیف پول');
                }
            }

            $this->db->query(
                "UPDATE escrow_transactions
                 SET status = 'refunded', amount = 0, refunded_at = NOW(), refund_reason = ?, refunded_by = ?, updated_at = NOW()
                 WHERE id = ?",
                [$reason, $refundedBy, $escrowId]
            );

            $this->logger->info('financial_escrow.held_budget_refunded', [
                'escrow_id' => $escrowId,
                'buyer_id' => $buyerId,
                'amount' => $amount,
                'reason' => $reason,
            ]);

            return ['ok' => true, 'amount' => $amount, 'currency' => $currency];
        };

        if ($this->db->inTransaction()) {
            return $this->normalizeFinancialResult($operation());
        }
        return $this->normalizeFinancialResult($this->db->transactional(fn() => $operation()));
    }

    /**
     * 🛡️ Fraud Policy Guard — بررسی ضدتقلب قبل از عملیات مالی escrow
     */
    /** @param array<string, mixed> $context */
    private function assertFraudAllowed(int $userId, string $action, array $context = []): void
    {
        try {
            $risk = $this->fraudGuard->checkAction($userId, $action, $context);

            if (empty($risk['allowed'])) {
                throw new \App\Exceptions\BusinessException(
                    'عملیات مالی به دلیل محدودیت‌های امنیتی مجاز نیست.'
                );
            }
        } catch (\App\Exceptions\BusinessException $e) {
            throw $e;
        } catch (\Throwable $e) {
            $this->captureEscrowException($e, 'assertFraudAllowed');
            $this->logger->warning('escrow.fraud_check_unavailable', [
                'user_id' => $userId, 'error' => $e->getMessage(),
            ]);
        }
    }
}

