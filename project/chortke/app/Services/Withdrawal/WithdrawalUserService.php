<?php

declare(strict_types=1);

namespace App\Services\Withdrawal;

use App\Services\SagaOrchestrator;
use App\Contracts\WalletServiceInterface;
use App\Models\Withdrawal;
use App\Exceptions\BusinessException;
use App\Contracts\LoggerInterface;
use App\Services\KYCService;
use App\Services\AntiFraud\FraudGuardService;
use App\Services\BankCardService;
use Core\Database;
use App\Services\Shared\IdempotencyService;


/**
 * WithdrawalUserService - مدیریت درخواست‌های برداشت با امنیت ساگا
 *
 * علاوه بر اجرای ساگا، یک لایه‌ی guard متمرکز (guardCanCreateWithdrawal) فراهم می‌کند که
 * پیش از هر برداشت، احراز هویت (KYC)، گیت ضدتقلب، نبودِ درخواست در انتظار و سقف روزانه را
 * بررسی می‌کند. این لایه قبلاً وجود داشت اما از سرویس حذف شده بود؛ نبودِ آن یعنی مسیر
 * store() می‌توانست بررسی‌های KYC/pending را دور بزند (خلأ امنیتی) — اکنون بازگردانده شده است.
 */
class WithdrawalUserService
{
    // Evaluates user balance before processing requests
    private Database $db;
    private KYCService $kycService;
    private WalletServiceInterface $wallet;
    private FraudGuardService $fraudGuard;
    private BankCardService $bankCardService;
    private WithdrawalQueryService $queryService;
    private Withdrawal $model;
    private IdempotencyService $idempotencyService;
    private ?\App\Services\OutboxService $outbox;
    private SagaOrchestrator $sagaOrchestrator;

    public function __construct(
        Database $db,
        KYCService $kycService,
        WalletServiceInterface $wallet,
        FraudGuardService $fraudGuard,
        BankCardService $bankCardService,
        WithdrawalQueryService $queryService,
        Withdrawal $model,
        IdempotencyService $idempotencyService,
        SagaOrchestrator $sagaOrchestrator,
        ?\App\Services\OutboxService $outbox = null
    ) {
        $this->db = $db;
        $this->kycService = $kycService;
        $this->wallet = $wallet;
        $this->fraudGuard = $fraudGuard;
        $this->bankCardService = $bankCardService;
        $this->queryService = $queryService;
        $this->model = $model;
        $this->idempotencyService = $idempotencyService;
        $this->outbox = $outbox;
        $this->sagaOrchestrator = $sagaOrchestrator;
        $this->outbox = $outbox;
    }

    /**
     * لایه‌ی guard متمرکز پیش از ثبت برداشت.
     *
     * @throws BusinessException در صورت عدم احراز شرایط (مبلغ نامعتبر، عدم KYC، تقلب، درخواست باز، سقف روزانه)
     */
    /** @param array<string, mixed> $payload */
    public function guardCanCreateWithdrawal(int $userId, array $payload): void
    {
        // ۱) اعتبارسنجی مبلغ
        $amount = str_value($payload['amount'] ?? '0');
        if (!is_numeric($amount) || bccomp($amount, '0', 4) <= 0) {
            throw new BusinessException('مبلغ برداشت نامعتبر است');
        }

        $currency = strtolower(str_value($payload['currency'] ?? 'irt'));
        $minAmount = $currency === 'usdt' ? '10' : '50000';
        if (bccomp($amount, $minAmount, 8) < 0) {
            throw new BusinessException("مبلغ وارد شده کمتر از حداقل مجاز است");
        }

        if ($currency === 'irt') {
            $cardId = int_value($payload['bank_card_id'] ?? 0);
            if ($cardId <= 0 || !$this->bankCardService->findVerifiedCardForUser($userId, $cardId)) {
                throw new BusinessException('کارت بانکی تأییدشده معتبر برای برداشت انتخاب نشده است');
            }
        }

        // ۲) احراز هویت (KYC)
        if (!$this->kycService->isApproved($userId)) {
            throw new BusinessException('برای برداشت وجه ابتدا باید احراز هویت کنید');
        }

        // ۴) جلوگیری از درخواست‌های همزمان/در انتظار
        if ($this->queryService->hasPendingWithdrawal($userId, true)) {
            throw new BusinessException('شما یک درخواست برداشت در انتظار دارید');
        }

        // ۵) سقف برداشت روزانه/هفتگی (W-10) — مقادیر از تنظیمات پنل ادمین خوانده می‌شوند.
        $limits = $this->queryService->getLimitsForUser($userId, $currency);
        
        $usedToday = str_value($limits['used_today'] ?? '0');
        $dailyLimit = str_value($limits['daily_limit'] ?? '0');
        
        // مجموع برداشت‌های فعلی + درخواست جدید نباید از سقف روزانه بیشتر شود
        $totalProposed = bcadd($usedToday, $amount, 8);
        
        if (bccomp($totalProposed, $dailyLimit, 8) > 0) {
            $remaining = bcsub($dailyLimit, $usedToday, 8);
            if (bccomp($remaining, '0', 8) < 0) {
                $remaining = '0';
            }
            throw new BusinessException("مبلغ برداشت از سقف روزانه شما فراتر رفته است. حداکثر مبلغ قابل برداشت امروز: {$remaining} {$currency}");
        }

        $usedWeek = str_value($limits['used_week'] ?? '0');
        $weeklyLimit = str_value($limits['weekly_limit'] ?? '0');
        if ($weeklyLimit !== '0') {
            $weeklyTotalProposed = bcadd($usedWeek, $amount, 8);
            if (bccomp($weeklyTotalProposed, $weeklyLimit, 8) > 0) {
                $remainingWeekly = bcsub($weeklyLimit, $usedWeek, 8);
                if (bccomp($remainingWeekly, '0', 8) < 0) {
                    $remainingWeekly = '0';
                }
                throw new BusinessException("مبلغ برداشت از سقف هفتگی شما فراتر رفته است. حداکثر مبلغ قابل برداشت این هفته: {$remainingWeekly} {$currency}");
            }
        }

        $usedMonth = str_value($limits['used_month'] ?? '0');
        $monthlyLimit = str_value($limits['monthly_limit'] ?? '0');
        if ($monthlyLimit !== '0') {
            $monthlyTotalProposed = bcadd($usedMonth, $amount, 8);
            if (bccomp($monthlyTotalProposed, $monthlyLimit, 8) > 0) {
                $remainingMonthly = bcsub($monthlyLimit, $usedMonth, 8);
                if (bccomp($remainingMonthly, '0', 8) < 0) {
                    $remainingMonthly = '0';
                }
                throw new BusinessException("مبلغ برداشت از سقف ماهانه شما فراتر رفته است. حداکثر مبلغ قابل برداشت این ماه: {$remainingMonthly} {$currency}");
            }
        }

        // ۶) گیت ضدتقلب بعد از validation/limit تا خطاهای business-rule دقیق‌تر گزارش شوند
        $risk = $this->fraudGuard->checkAction($userId, 'withdrawal.create', [
            'amount'   => $amount,
            'currency' => $payload['currency'] ?? 'irt',
            'ip'       => $payload['ip'] ?? null,
        ]);
        if (empty($risk['allowed'])) {
            throw new BusinessException('درخواست برداشت به دلیل محدودیت‌های امنیتی مجاز نیست');
        }
    }

    /** @return array<string, mixed> */
    public function cancelPendingWithdrawal(int $userId, int $withdrawalId): array
    {
        $result = $this->db->transactional(function () use ($userId, $withdrawalId) {
            $withdrawal = $this->model->lockForUpdate($withdrawalId);

            if (!$withdrawal || (int)$withdrawal->user_id !== $userId) {
                return ['success' => false, 'message' => 'درخواست برداشت یافت نشد'];
            }

            if ((string)$withdrawal->status !== 'pending') {
                return ['success' => false, 'message' => 'فقط برداشت‌های در انتظار قابل لغو هستند'];
            }

            $ok = $this->wallet->cancelWithdrawal(
                $userId,
                (string)$withdrawal->amount,
                (string)$withdrawal->currency,
                (string)$withdrawal->transaction_id
            );

            if (!$ok) {
                throw new BusinessException('خطا در بازگشت وجه برداشت');
            }

            $updated = $this->model->updateStatus($withdrawalId, 'cancelled', null, null);
            if (!$updated) {
                throw new BusinessException('خطا در لغو درخواست برداشت');
            }

            return [
                'success' => true,
                'message' => 'درخواست برداشت لغو و موجودی بازگردانده شد',
                'withdrawal_id' => $withdrawalId,
            ];
        });
        if (!is_array($result)) {
            throw new \UnexpectedValueException('Withdrawal cancellation returned an invalid result.');
        }
        return $result;
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function requestFromUser(int $userId, array $payload): array
    {
        $explicitKey = $payload['idempotency_key'] ?? null;
        if (empty($explicitKey)) {
            $explicitKey = $this->idempotencyService->generateFromPayload(
                'user_withdrawal',
                array_merge($payload, ['user_id' => $userId])
            );
        }

        // Idempotency compares the immutable business command only. Request IDs,
        // IP, user-agent and device fingerprints are audit context and may vary
        // across legitimate retries; including them caused false collisions for
        // an otherwise identical withdrawal command.
        $idempotencyPayload = array_intersect_key($payload, array_flip([
            'amount', 'currency', 'bank_card_id', 'crypto_wallet',
            'crypto_network', 'user_description', 'idempotency_key',
        ]));

        return $this->idempotencyService->execute('user_withdrawal', $userId, $idempotencyPayload, function() use ($userId, $payload) {
            $orchestrator = $this->sagaOrchestrator;

            // Golden Law: کل درخواست برداشت باید در یک transaction boundary باشد
            // guard هم داخل transactional منتقل شد تا FOR UPDATE در hasPendingWithdrawal معتبر باشد
            $result = $this->db->transactional(function() use ($orchestrator, $userId, $payload) {
                // اجرای لایه‌ی guard داخل تراکنش (KYC/fraud/pending/limit)
                $this->guardCanCreateWithdrawal($userId, $payload);
                return $orchestrator
                    ->setSaga('user_withdrawal_request', array_merge($payload, ['user_id' => $userId]))
                ->addStep(
                    'wallet_hold',
                    function($ctx) {
                        $res = $this->wallet->withdraw(int_value($ctx['user_id']), str_value($ctx['amount']), str_value($ctx['currency']), [
                            'type' => 'withdrawal_request',
                            'idempotency_key' => 'withdrawal_hold_' . str_value($ctx['idempotency_key'] ?? hash('sha256', str_value(json_encode($ctx))))
                        ]);
                        if (empty($res['success'])) throw new BusinessException(is_string($res['message'] ?? null) ? $res['message'] : 'برداشت وجه ناموفق بود');
                        return array_merge($ctx, ['tx_id' => $res['transaction_id']]);
                    },
                    function($err, $res) {
                        if (isset($res['tx_id'])) {
                            $this->wallet->cancelWithdrawal(int_value($res['user_id']), str_value($res['amount']), str_value($res['currency']), str_value($res['tx_id']));
                        }
                    }
                )
                ->addStep(
                    'create_db_record',
                    function($ctx) {
                        $w = $this->model->createWithdrawal([
                            'user_id' => $ctx['user_id'],
                            'amount' => $ctx['amount'],
                            'currency' => $ctx['currency'],
                            'card_id' => $ctx['bank_card_id'] ?? null,
                            'transaction_id' => $ctx['tx_id'],
                            'status' => 'pending'
                        ]);
                        
                        if (!$w || !isset($w->id)) {
                            throw new \RuntimeException('Withdrawal creation did not return a valid model.');
                        }

                        if ($this->outbox) {
                            $this->outbox->record(
                                'withdrawal',
                                (string)$w->id,
                                \App\Events\WithdrawalCreatedEvent::class,
                                [
                                    'user_id' => $ctx['user_id'],
                                    'withdrawal_id' => $w->id,
                                    'amount' => $ctx['amount'],
                                    'currency' => $ctx['currency'],
                                    'status' => 'pending'
                                ]
                            );
                        }
                        return ['withdrawal_id' => $w->id];
                    }
                )
                ->execute();
            });
            if (!is_array($result)) {
                throw new \UnexpectedValueException('Withdrawal saga returned an invalid result.');
            }
            return [
                'success' => true,
                'message' => 'درخواست برداشت ثبت شد',
                'data' => [
                    'withdrawal_id' => $result['withdrawal_id'] ?? null,
                    'transaction_id' => $result['tx_id'] ?? null,
                    'status' => 'pending',
                ],
                'withdrawal_id' => $result['withdrawal_id'] ?? null,
                'transaction_id' => $result['tx_id'] ?? null,
            ];
        }, str_value($explicitKey));
    }
}
