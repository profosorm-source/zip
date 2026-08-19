<?php

declare(strict_types=1);

namespace App\Services\Investment;

use App\Contracts\LoggerInterface;
use App\Contracts\OutboxServiceInterface;
use App\Contracts\WalletServiceInterface;
use App\Events\InvestmentCreatedEvent;
use App\Models\Investment;
use App\Models\InvestmentWithdrawal;
use App\Services\AuditTrail;
use App\Services\SagaOrchestrator;
use App\Services\Settings\AppSettings;
use App\Jobs\Investment\CreateTradeJob;
use App\Jobs\Investment\CloseTradeJob;
use App\Jobs\Investment\ApplyWeeklyProfitLossJob;
use App\Jobs\Investment\ApplyProfitLossToBatchJob;
use App\Jobs\Investment\RequestWithdrawalJob;
use App\Jobs\Investment\RejectWithdrawalJob;
use Core\Database;
use Core\EventDispatcher;
use Core\ValueObjects\Money;

/**
 * InvestmentCommandService — عملیات نوشتن سرمایه‌گذاری
 *
 * مسئولیت‌ها:
 *   - ایجاد سرمایه‌گذاری (با Saga)
 *   - ثبت/بستن ترید
 *   - اعمال سود/ضرر هفتگی
 *   - تایید/رد برداشت
 *   - اعمال ضرر دستی
 *   - برداشت سود
 *
 * @phpstan-type CommandResult array<string, mixed>
 * @phpstan-type InvestmentInput array<string, mixed>
 * @phpstan-type TradingInput array<string, mixed>
 * @phpstan-type WithdrawalInput array<string, mixed>
 * @phpstan-type ProfitTier array{min: string, fee: string}
 */
class InvestmentCommandService
{
    private const RISK_WARNING = 'هشدار ریسک سرمایه‌گذاری: فعالیت در بازار فارکس/طلا با ریسک بالایی همراه است و ممکن است باعث از دست دادن سرمایه شما شود.';

    private CreateTradeJob $createTradeJob;
    private CloseTradeJob $closeTradeJob;
    private ApplyWeeklyProfitLossJob $applyWeeklyJob;
    private ApplyProfitLossToBatchJob $applyBatchJob;
    private RequestWithdrawalJob $requestWithdrawalJob;
    private RejectWithdrawalJob $rejectWithdrawalJob;

    private Database $db;
    private LoggerInterface $logger;
    private AppSettings $appSettings;
    private Investment $investmentModel;
    private InvestmentWithdrawal $withdrawalModel;
    private ?OutboxServiceInterface $outbox;
    private SagaOrchestrator $sagaOrchestrator;
    private WalletServiceInterface $walletService;

    public function __construct(
        Database $db,
        LoggerInterface $logger,
        AppSettings $appSettings,
        Investment $investmentModel,
        InvestmentWithdrawal $withdrawalModel,
        WalletServiceInterface $walletService,
        SagaOrchestrator $sagaOrchestrator,
        ?OutboxServiceInterface $outbox,
        CreateTradeJob $createTradeJob,
        CloseTradeJob $closeTradeJob,
        ApplyWeeklyProfitLossJob $applyWeeklyJob,
        ApplyProfitLossToBatchJob $applyBatchJob,
        RequestWithdrawalJob $requestWithdrawalJob,
        RejectWithdrawalJob $rejectWithdrawalJob
    ) {
        $this->db              = $db;
        $this->logger          = $logger;
        $this->appSettings     = $appSettings;
        $this->investmentModel = $investmentModel;
        $this->withdrawalModel = $withdrawalModel;
        $this->walletService   = $walletService;
        $this->sagaOrchestrator = $sagaOrchestrator;
        $this->outbox          = $outbox;
        $this->createTradeJob   = $createTradeJob;
        $this->closeTradeJob    = $closeTradeJob;
        $this->applyWeeklyJob   = $applyWeeklyJob;
        $this->applyBatchJob    = $applyBatchJob;
        $this->requestWithdrawalJob = $requestWithdrawalJob;
        $this->rejectWithdrawalJob = $rejectWithdrawalJob;
    }

    /**
     * ROOT FIX (principled): Centralized `toObject` helper (standard pattern).
     * Guarantees ?object from any Model find result.
     */
    private function toObject(mixed $data): ?\stdClass
    {
        if ($data instanceof \stdClass) return $data;
        if (is_array($data)) return (object)$data;
        return null;
    }

    /**
     * ایجاد سرمایه‌گذاری جدید با امنیت ساگا
     */
    /**
     * @param array<string, mixed> $options
     * @return CommandResult
     */
    public function create(int $userId, string $amount, string $currency = 'usdt', array $options = []): array
    {
        if ($this->investmentModel->hasActiveInvestment($userId)) {
            return ['success' => false, 'message' => 'شما یک سرمایه‌گذاری فعال دارید'];
        }

        try {
            $created = $this->db->transactional(function() use ($userId, $amount, $currency, $options) {
                // 🔐 M-21 FIX: enforce the "one active investment per user" invariant atomically.
                // The hasActiveInvestment() pre-check above is a fast UX guard only; two concurrent
                // create() calls could both pass it and both create an active investment (TOCTOU).
                // Take a locking read on this user's active investments inside the transaction and
                // re-check, serializing concurrent creates for the same user. (A partial UNIQUE
                // index on investments(user_id) WHERE status='active' would be the strongest
                // guarantee and is recommended as a follow-up migration.)
                $this->db->query(
                    "SELECT id FROM investments WHERE user_id = ? AND status = ? FOR UPDATE",
                    [$userId, Investment::STATUS_ACTIVE]
                )->fetch();
                if ($this->investmentModel->hasActiveInvestment($userId)) {
                    throw new \Core\Exceptions\InvalidStateException('شما یک سرمایه‌گذاری فعال دارید');
                }

                $orchestrator = $this->sagaOrchestrator;
                $walletService = $this->walletService;

                $result = $orchestrator
                    ->setSaga('investment_creation', array_merge(['user_id' => $userId, 'amount' => $amount, 'currency' => strtolower((string)$currency)], $options))
                    ->addStep(
                        'wallet_hold',
                        function($ctx) use ($walletService) {
                            assert_fraud_allowed(intval($ctx['user_id']), 'investment.pay', ['amount' => $ctx['amount']]);
                            $metadata = [
                                'type' => 'investment_creation',
                                'ref_type' => 'investment',
                            ];
                            $commandKey = trim(str_value($ctx['idempotency_key'] ?? ''));
                            if ($commandKey !== '') {
                                $metadata['idempotency_key'] = hash(
                                    'sha256',
                                    'investment.create|' . int_value($ctx['user_id']) . '|' . $commandKey
                                );
                            }
                            $res = $walletService->withdraw(
                                intval($ctx['user_id']),
                                strval($ctx['amount']),
                                strval($ctx['currency']),
                                $metadata
                            );
                            if (empty($res['success'])) {
                                throw new \Core\Exceptions\InsufficientBalanceException(is_string($res['message'] ?? null) ? $res['message'] : 'خطا در قفل موجودی سرمایه‌گذاری');
                            }
                            return ['tx_id' => $res['transaction_id'], 'user_id' => $ctx['user_id'], 'amount' => $ctx['amount'], 'currency' => $ctx['currency']];
                        },
                        function($err, $res) use ($walletService) {
                            if (isset($res['tx_id'], $res['user_id'], $res['amount'], $res['currency'])) {
                                $walletService->cancelWithdrawal(intval($res['user_id']), strval($res['amount']), strval($res['currency']), strval($res['tx_id']));
                            }
                        }
                    )
                    ->addStep(
                        'save_record',
                        function($ctx) {
                            if (!empty($ctx['force_fail_after_hold'])) {
                                throw new \RuntimeException('forced_investment_creation_failure');
                            }
                            $invId = $this->investmentModel->create([
                                'user_id' => $ctx['user_id'],
                                'amount' => $ctx['amount'],
                                'current_balance' => $ctx['amount'],
                                'status' => Investment::STATUS_ACTIVE,
                                'transaction_id' => $ctx['tx_id'],
                                'start_date' => date('Y-m-d H:i:s'),
                            ]);
                            if (!$invId) {
                                throw new \Core\Exceptions\ApplicationException('خطا در ثبت رکورد سرمایه‌گذاری');
                            }
                            $this->outbox?->recordEvent(new InvestmentCreatedEvent((int)$ctx['user_id'], (int)$invId, (string)$ctx['amount'], (string)$ctx['currency']));
                            return ['investment_id' => $invId];
                        }
                    )
                    ->addStep(
                        'settle_investment_capital',
                        function($ctx) use ($walletService) {
                            $spent = $walletService->spendLockedFunds(
                                (int)$ctx['user_id'],
                                (string)$ctx['amount'],
                                (string)$ctx['currency'],
                                [
                                    'type' => 'investment_capital_settlement',
                                    'description' => 'انتقال سرمایه به استخر سرمایه‌گذاری',
                                    'ref_id' => (int)$ctx['investment_id'],
                                    'ref_type' => 'investment',
                                    'ledger_credit_account' => 'investment_pool',
                                    'idempotency_key' => 'investment_capital_settlement_' . $ctx['investment_id'],
                                ]
                            );
                            if (empty($spent['success']) || !$walletService->finalizeLockedSpend((int)$ctx['user_id'], (string)$ctx['tx_id'])) {
                                throw new \Core\Exceptions\ApplicationException('تسویه سرمایه اولیه انجام نشد');
                            }
                            return ['capital_settlement_transaction_id' => $spent['transaction_id'] ?? null];
                        }
                    )
                    ->execute();
                if (!is_array($result)) {
                    throw new \UnexpectedValueException('Investment creation saga returned an invalid context.');
                }

                return [
                    'success' => true,
                    'message' => 'سرمایه‌گذاری با موفقیت ایجاد شد',
                    'investment_id' => $result['investment_id'] ?? null,
                    'transaction_id' => $result['tx_id'] ?? null,
                ];
            });
            return is_array($created) ? $created : ['success' => false, 'message' => 'پاسخ ایجاد سرمایه‌گذاری نامعتبر است'];
        } catch (\Throwable $e) {
            $mapped = $this->mapCreateExceptionToUserResponse($e);
            $this->logger->warning('investment.create_failed_user_safe', [
                'user_id' => $userId,
                'amount'  => $amount,
                'currency'=> $currency,
                'code'    => $mapped['error_code'] ?? 'INVESTMENT_CREATE_FAILED',
                'raw'     => $e->getMessage(),
            ]);
            \App\Services\Sentry\SentryExceptionHandler::captureException($e, $userId, [
                'operation' => 'investment.create',
                'amount'    => $amount,
                'currency'  => $currency,
            ]);
            return $mapped;
        }
    }

    /**
     * @param InvestmentInput $data
     * @return CommandResult
     */
    public function createInvestment(int $userId, array $data): array
    {
        if (empty($data['risk_accepted'])) {
            return ['success' => false, 'message' => 'پذیرش ریسک سرمایه‌گذاری الزامی است', 'error_code' => 'RISK_NOT_ACCEPTED'];
        }

        $amount = $this->normalizeAmountString($data['amount'] ?? '0');
        $validation = $this->validateCreatePreconditions($userId, $amount, 'usdt');
        if (!$validation['success']) {
            return $validation;
        }

        $idempotencyKey = trim(str_value($data['idempotency_key'] ?? ''));
        return $this->create(
            $userId,
            $amount,
            'usdt',
            $idempotencyKey !== '' ? ['idempotency_key' => $idempotencyKey] : []
        );
    }

    /**
     * @param TradingInput $data
     * @return CommandResult
     */
    public function createTrade(int $adminId, array $data): array
    {
        return $this->createTradeJob->handle($adminId, $data);
    }

    /**
     * @param TradingInput $data
     * @return CommandResult
     */
    public function closeTrade(int $tradeId, int $adminId, array $data): array
    {
        return $this->closeTradeJob->handle($tradeId, $adminId, $data);
    }

    /** @return CommandResult */
    public function applyWeeklyProfitLoss(int $adminId, int $tradingRecordId, string $profitLossPercent, string $period): array
    {
        return $this->applyWeeklyJob->handle($adminId, $tradingRecordId, $profitLossPercent, $period);
    }

    /**
     * @param list<int> $investmentIds
     * @return CommandResult
     */
    public function applyProfitLossToBatch(array $investmentIds, int $tradingRecordId, string $percent, string $period, int $adminId): array
    {
        return $this->applyBatchJob->handle($investmentIds, $tradingRecordId, $percent, $period, $adminId);
    }

    /**
     * @param WithdrawalInput $data
     * @return CommandResult
     */
    public function requestWithdrawal(int $userId, array $data): array
    {
        return $this->requestWithdrawalJob->handle($userId, $data);
    }

    /** @return CommandResult */
    public function approveWithdrawal(int $withdrawalId, int $adminId): array
    {
        $saga = $this->sagaOrchestrator;
        $wallet = $this->walletService;

        try {
            // BUGFIX-SAGA-TX-ROOT: مشابه FinancialEscrowService — بدون Transaction Root
            // اگر finalize_status بعد از واریز موفق fail شود، وجه به کاربر واریز شده
            // ولی وضعیت درخواست برداشت هرگز approved نمی‌شود (وضعیت ناقص).
            $approved = $this->db->transactional(function () use ($saga, $wallet, $withdrawalId, $adminId) {
            return $saga->setSaga('investment_withdrawal_approval', ['w_id' => $withdrawalId, 'admin_id' => $adminId])
                ->addStep(
                    'transfer_to_wallet',
                    function($ctx) use ($wallet) {
                        $w = $this->toObject($this->withdrawalModel->find(intval($ctx['w_id'])));
                        if (!$w || !isset($w->id) || $w->status !== 'pending') throw new \Core\Exceptions\InvalidStateException('درخواست نامعتبر');

                        $res = $wallet->deposit((int)$w->user_id, (string)$w->amount, 'usdt', [
                            'type' => 'investment_withdrawal',
                            'ref_id' => (int)$w->investment_id,
                            'ref_type' => 'investment',
                            'ledger_debit_account' => 'investment_pool',
                            'idempotency_key' => 'investment_withdrawal_' . $w->id,
                        ]);
                        if (empty($res['success'])) throw new \Core\Exceptions\ApplicationException(is_string($res['message'] ?? null) ? $res['message'] : 'خطا در واریز به کیف پول');

                        return array_merge($ctx, ['tx_id' => $res['transaction_id'], 'user_id' => $w->user_id]);
                    },
                    function($err, $res) use ($wallet) {
                        if (isset($res['tx_id'])) {
                            $wallet->reverseTransaction($res['tx_id'], null, 'لغو برداشت سرمایه');
                        }
                    }
                )
                ->addStep(
                    'finalize_status',
                    function($ctx) {
                        $withdrawal = $this->toObject($this->withdrawalModel->find(intval($ctx['w_id'])));
                        if (!$withdrawal || $withdrawal->status !== InvestmentWithdrawal::STATUS_PENDING) {
                            throw new \Core\Exceptions\InvalidStateException('درخواست برداشت دیگر در وضعیت قابل تأیید نیست.');
                        }
                        if (!$this->withdrawalModel->update(intval($ctx['w_id']), [
                            'status'       => InvestmentWithdrawal::STATUS_APPROVED,
                            'processed_at' => date('Y-m-d H:i:s'),
                            'processed_by' => intval($ctx['admin_id'])
                        ])) {
                            throw new \Core\Exceptions\ApplicationException('ثبت وضعیت نهایی برداشت انجام نشد.');
                        }
                        if ((string)$withdrawal->type === InvestmentWithdrawal::TYPE_FULL_CLOSE) {
                            if (!$this->investmentModel->update((int)$withdrawal->investment_id, [
                                'status' => Investment::STATUS_CLOSED,
                                'current_balance' => '0',
                                'last_withdrawal_date' => date('Y-m-d H:i:s'),
                            ])) {
                                throw new \Core\Exceptions\ApplicationException('بستن سرمایه‌گذاری پس از برداشت کامل انجام نشد.');
                            }
                        }
                        return $ctx;
                    }
                )
                ->execute();
            });
            return is_array($approved)
                ? array_merge($approved, ['success' => true, 'message' => 'برداشت سرمایه با موفقیت تأیید و پرداخت شد.'])
                : ['success' => false, 'message' => 'پاسخ تأیید برداشت نامعتبر است'];
        } catch (\Throwable $e) {
            \App\Services\Sentry\SentryExceptionHandler::captureException($e, null, [
                'operation' => 'investment.requestWithdrawal',
            ]);
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    /** @return CommandResult */
    public function rejectWithdrawal(int $withdrawalId, int $adminId, string $reason): array
    {
        return $this->rejectWithdrawalJob->handle($withdrawalId, $adminId, $reason);
    }

    public function applyLoss(int $investmentId, string $lossAmount, string $adminOperator): bool
    {
        $stmt = $this->db->prepare(
            "UPDATE investments
             SET current_balance = GREATEST(current_balance - ?, 0),
                 total_loss = total_loss + ?,
                 profit_earned = profit_earned - ?,
                 updated_at = NOW()
             WHERE id = ? AND status = ?"
        );
        $ok = $stmt->execute([$lossAmount, $lossAmount, $lossAmount, $investmentId, Investment::STATUS_ACTIVE]);
        if ($ok && $stmt->rowCount() > 0) {
            $this->db->query(
                "INSERT INTO investment_profits (investment_id, user_id, amount, net_amount, currency, profit_type, status, period_date, created_at, updated_at)
                 SELECT id, user_id, ?, ?, 'usdt', 'loss', 'paid', CURDATE(), NOW(), NOW() FROM investments WHERE id = ?",
                ['-' . ltrim($lossAmount, '-'), '-' . ltrim($lossAmount, '-'), $investmentId]
            );
            $this->logger->info('investment.loss_applied', ['investment_id' => $investmentId, 'loss' => $lossAmount, 'admin' => $adminOperator]);
            return true;
        }
        return false;
    }

    /** @return CommandResult */
    public function withdrawProfit(int $investmentId, int $userId): array
    {
        $withdrawn = $this->db->transactional(function() use ($investmentId, $userId) {
            $inv = $this->toObject($this->investmentModel->findForUpdate($investmentId));
            if (!$inv || !isset($inv->id) || bccomp((string)$inv->profit_earned, '0', 8) <= 0) {
                return ['success' => false, 'message' => 'سود قابل برداشتی یافت نشد.'];
            }
            $profitStr = (string)$inv->profit_earned;
            $this->db->query(
                "UPDATE investments SET profit_earned = 0, last_profit_date = NOW(), last_withdrawal_date = NOW(), deposit_lock_until = DATE_ADD(NOW(), INTERVAL ? DAY) WHERE id = ?",
                [Investment::DEPOSIT_LOCK_DAYS, $investmentId]
            );
            $depositResult = $this->walletService->deposit($userId, $profitStr, 'usdt', [
                'type' => 'investment_profit_withdrawal',
                'ref_id' => $investmentId,
                'ref_type' => 'investment',
                'description' => 'برداشت سود سرمایه‌گذاری',
                'ledger_debit_account' => 'investment_pool',
                'idempotency_key' => 'investment_profit_withdrawal_' . $investmentId
            ]);
            if (empty($depositResult['success'])) {
                throw new \Core\Exceptions\ApplicationException(is_string($depositResult['message'] ?? null) ? $depositResult['message'] : 'خطا در واریز سود به کیف پول');
            }
            return ['success' => true, 'amount' => $profitStr, 'deposit_lock_until' => date('Y-m-d H:i:s', time() + Investment::DEPOSIT_LOCK_DAYS * 86400)];
        });
        return is_array($withdrawn) ? $withdrawn : ['success' => false, 'message' => 'پاسخ برداشت سود نامعتبر است'];
    }

    public function getRiskWarning(): string
    {
        return self::RISK_WARNING;
    }

    /** @return array{min_amount: string, max_amount: string, site_fee_percent: string, tax_percent: string, withdrawal_cooldown: int, deposit_lock: int} */
    public function getSettings(): array
    {
        return [
            'min_amount'          => $this->normalizeAmountString($this->appSettings->get('investment_min_amount', '10')),
            'max_amount'          => $this->normalizeAmountString($this->appSettings->get('investment_max_amount', '10000')),
            'site_fee_percent'    => $this->normalizeAmountString($this->appSettings->get('investment_site_fee_percent', '10')),
            'tax_percent'         => $this->normalizeAmountString($this->appSettings->get('investment_tax_percent', '9')),
            'withdrawal_cooldown' => Investment::WITHDRAWAL_COOLDOWN_DAYS,
            'deposit_lock'        => Investment::DEPOSIT_LOCK_DAYS,
        ];
    }

    // ─── Private helpers ──────────────────────────────────────────────────

    /** @return CommandResult */
    private function validateCreatePreconditions(int $userId, string $amount, string $currency): array
    {
        if (bccomp($amount, '0', 8) <= 0) {
            return [
                'success' => false,
                'message' => 'مبلغ سرمایه‌گذاری باید بیشتر از صفر باشد.',
                'error_code' => 'INVALID_AMOUNT',
            ];
        }

        $settings = $this->getSettings();
        $minAmount = $this->normalizeAmountString($settings['min_amount']);
        $maxAmount = $this->normalizeAmountString($settings['max_amount']);

        if (bccomp($amount, $minAmount, 8) < 0) {
            return [
                'success' => false,
                'message' => 'حداقل مبلغ سرمایه‌گذاری ' . $this->formatUsdtForMessage($minAmount) . ' است.',
                'error_code' => 'BELOW_MIN_AMOUNT',
                'min_amount' => $minAmount,
            ];
        }

        if (bccomp($maxAmount, '0', 8) > 0 && bccomp($amount, $maxAmount, 8) > 0) {
            return [
                'success' => false,
                'message' => 'حداکثر مبلغ سرمایه‌گذاری ' . $this->formatUsdtForMessage($maxAmount) . ' است.',
                'error_code' => 'ABOVE_MAX_AMOUNT',
                'max_amount' => $maxAmount,
            ];
        }

        try {
            if ($this->walletService->isWalletFrozen($userId)) {
                return [
                    'success' => false,
                    'message' => 'کیف پول شما موقتاً مسدود است و امکان ایجاد پلن سرمایه‌گذاری وجود ندارد. لطفاً با پشتیبانی تماس بگیرید.',
                    'error_code' => 'WALLET_FROZEN',
                ];
            }

            $balance = $this->normalizeAmountString($this->walletService->getBalance($userId, $currency));
            if (bccomp($balance, $amount, 8) < 0) {
                return [
                    'success' => false,
                    'message' => 'موجودی کیف پول USDT شما برای ایجاد این پلن کافی نیست. لطفاً ابتدا کیف پول تتر خود را شارژ کنید.',
                    'error_code' => 'INSUFFICIENT_USDT_BALANCE',
                    'available_balance' => $balance,
                    'required_amount' => $amount,
                    'currency' => strtoupper((string)$currency),
                ];
            }
        } catch (\Throwable $e) {
            $this->logger->warning('investment.wallet_preflight_failed', [
                'user_id' => $userId,
                'amount'  => $amount,
                'currency'=> $currency,
                'raw'     => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'message' => 'در حال حاضر امکان بررسی موجودی کیف پول وجود ندارد. لطفاً چند لحظه بعد دوباره تلاش کنید.',
                'error_code' => 'WALLET_PREFLIGHT_FAILED',
            ];
        }

        return ['success' => true];
    }

    /** @return CommandResult */
    private function mapCreateExceptionToUserResponse(\Throwable $e): array
    {
        $raw = $this->collectThrowableMessages($e);
        $lower = strtolower((string)$raw);

        if (str_contains($lower, 'insufficient balance') || str_contains($lower, 'موجودی کافی')) {
            return [
                'success' => false,
                'message' => 'موجودی کیف پول USDT شما برای ایجاد این پلن کافی نیست. لطفاً ابتدا کیف پول تتر خود را شارژ کنید.',
                'error_code' => 'INSUFFICIENT_USDT_BALANCE',
            ];
        }

        if (str_contains($lower, 'wallet frozen') || str_contains($lower, 'کیف پول شما مسدود')) {
            return [
                'success' => false,
                'message' => 'کیف پول شما موقتاً مسدود است و امکان ایجاد پلن سرمایه‌گذاری وجود ندارد. لطفاً با پشتیبانی تماس بگیرید.',
                'error_code' => 'WALLET_FROZEN',
            ];
        }

        if (str_contains($lower, 'failed to acquire lock')) {
            return [
                'success' => false,
                'message' => 'درخواست قبلی شما هنوز در حال پردازش است. لطفاً چند لحظه بعد دوباره تلاش کنید.',
                'error_code' => 'CONCURRENT_REQUEST',
            ];
        }

        return [
            'success' => false,
            'message' => 'ایجاد پلن سرمایه‌گذاری در حال حاضر انجام نشد. لطفاً چند لحظه بعد دوباره تلاش کنید یا با پشتیبانی تماس بگیرید.',
            'error_code' => 'INVESTMENT_CREATE_FAILED',
        ];
    }

    private function collectThrowableMessages(\Throwable $e): string
    {
        $messages = [];
        do {
            $messages[] = $e->getMessage();
            $e = $e->getPrevious();
        } while ($e instanceof \Throwable);

        return implode(' | ', array_filter($messages));
    }

    private function normalizeAmountString(mixed $amount): string
    {
        if (!is_scalar($amount)) return '0';
        $decimal = str_replace(',', '', trim((string)$amount));
        return preg_match('/^-?(?:0|[1-9]\d*)(?:\.\d+)?$/', $decimal) === 1 ? $decimal : '0';
    }

    private function formatUsdtForMessage(string $amount): string
    {
        $normalized = $this->normalizeAmountString($amount);
        $trimmed = rtrim(rtrim($normalized, '0'), '.');
        return ($trimmed === '' || $trimmed === '-') ? '0' : $trimmed . ' USDT';
    }

    // ─── Profit calculation algorithms ────────────────────────────────────

    /** @return array{0: string, 1: string, 2: string} */
    protected function calculateNetProfitV1(
        string $profitLossAmount,
        string $siteFeePercent,
        string $taxPercent,
        string $currency = 'USDT'
    ): array {
        $profit   = Money::of($profitLossAmount, $currency);
        $siteFee  = $profit->percentage($siteFeePercent);
        $afterFee = $profit->subtract($siteFee);
        $tax      = $afterFee->percentage($taxPercent);
        $net      = $afterFee->subtract($tax);

        return [
            $siteFee->getAmount(),
            $tax->getAmount(),
            $net->getAmount(),
        ];
    }

    /**
     * @param list<ProfitTier> $tiers
     * @return array{0: string, 1: string, 2: string}
     */
    protected function calculateNetProfitV2(
        string $profitLossAmount,
        string $investAmount,
        string $baseSiteFeePercent,
        string $taxPercent,
        array  $tiers,
        string $currency = 'USDT'
    ): array {
        usort($tiers, static function (array $a, array $b): int {
            return bccomp(strval($a['min']), strval($b['min']), 8);
        });

        $effectiveFeePercent = $baseSiteFeePercent;
        foreach ($tiers as $tier) {
            $tierMin = strval($tier['min']);
            if (bccomp($investAmount, $tierMin, 8) >= 0) {
                $rawFee = strval($tier['fee']);
                $effectiveFeePercent = bccomp($rawFee, '0', 8) >= 0 ? $rawFee : '0';
            }
        }

        return $this->calculateNetProfitV1(
            $profitLossAmount,
            $effectiveFeePercent,
            $taxPercent,
            $currency
        );
    }
}
