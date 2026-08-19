<?php

declare(strict_types=1);

namespace App\Services\Lottery;

use Core\Database;
use App\Models\LotteryRound;
use App\Models\LotteryParticipation;
use App\Models\LotteryDailyNumber;
use App\Contracts\LoggerInterface;
use App\Contracts\WalletServiceInterface;
use App\Services\Shared\IdempotencyService;
use Core\EventDispatcher;

class LotteryCommandService
{
    /**
     * Centralized toObject (root-cause normalization for DB results).
     * Guarantees object (never array/mixed) before any ->prop access.
     */
    private function toObject(mixed $data): ?\stdClass
    {
        if ($data === null || $data === false) return null;
        if ($data instanceof \stdClass) return $data;
        if (is_object($data) || is_array($data)) return (object)(array)$data;
        return (object)[$data];
    }


    // 🛡️ Default weight for weighted random selection (fallback)
    private const DEFAULT_WEIGHT = 1.0;
    private Database $db;
    private LotteryRound $roundModel;
    private LotteryParticipation $participationModel;
    private LotteryDailyNumber $dailyModel;
    private LoggerInterface $logger;
    private EventDispatcher $eventDispatcher;
    private WalletServiceInterface $walletService;
    private IdempotencyService $idempotencyService;
    private ?\App\Contracts\OutboxServiceInterface $outbox = null;
    private \App\Services\SagaOrchestrator $sagaOrchestrator;

    public function __construct(
        Database $db,
        LotteryRound $roundModel,
        LotteryParticipation $participationModel,
        LotteryDailyNumber $dailyModel,
        LoggerInterface $logger,
        EventDispatcher $eventDispatcher,
        WalletServiceInterface $walletService,
        IdempotencyService $idempotencyService,
        \App\Services\SagaOrchestrator $sagaOrchestrator,
        ?\App\Contracts\OutboxServiceInterface $outbox = null
    ) {
        $this->eventDispatcher = $eventDispatcher;        $this->db = $db;
        $this->roundModel = $roundModel;
        $this->participationModel = $participationModel;
        $this->dailyModel = $dailyModel;
        $this->logger = $logger;
        $this->walletService = $walletService;
        $this->idempotencyService = $idempotencyService;
        $this->outbox = $outbox;
        $this->sagaOrchestrator = $sagaOrchestrator;
    }

    /**
     * @param array<string, mixed> $data
     * @return array{success: false, message: string}|array{success: true, message: string, round_id: int}
     */
    public function createRound(int $userId, array $data): array
    {
        try {
            $data['status'] = $data['status'] ?? 'active';
            $data['ticket_price'] = $data['ticket_price'] ?? ($data['entry_fee'] ?? 5000);
            $data['entry_fee'] = $data['entry_fee'] ?? $data['ticket_price'];
            $data['prize_amount'] = $data['prize_amount'] ?? ($data['prize_pool'] ?? 0);
            $data['prize_pool'] = $data['prize_pool'] ?? $data['prize_amount'];
            $data['currency'] = $data['currency'] ?? 'irt';
            $id = $this->roundModel->create($data);

            if (!$id) {
                return ['success' => false, 'message' => 'خطا در ثبت دوره قرعه‌کشی.'];
            }

            // Dispatch lottery.created event so it can be indexed in search!
            try {
                $this->eventDispatcher->dispatch('lottery.created', array_merge($data, ['id' => $id]));
            } catch (\Throwable $ignore) {}

            return ['success' => true, 'round_id' => $id, 'message' => 'دوره قرعه‌کشی با موفقیت ایجاد شد.'];
        } catch (\Throwable $e) {
            \App\Services\Sentry\SentryExceptionHandler::captureException($e, $userId, [
                'operation' => 'lottery.createRound',
            ]);
            return ['success' => false, 'message' => 'خطا در ثبت دوره: ' . $e->getMessage()];
        }
    }

    /** @return array<string, mixed> */
    public function generateDailyNumbers(int $roundId): array
    {
        try {
            $today = date('Y-m-d');
            $existing = $this->dailyModel->getByRoundAndDate($roundId, $today);

            if ($existing) {
                return ['success' => false, 'message' => 'اعداد روزانه امروز برای این دوره قبلاً تولید شده است.'];
            }

            // Generate 3 unique random numbers between 1 and 49
            $numbers = [];
            while (count($numbers) < 3) {
                $num = random_int(1, 49);
                if (!in_array($num, $numbers, true)) {
                    $numbers[] = $num;
                }
            }
            sort($numbers);

            $id = $this->dailyModel->create([
                'round_id' => $roundId,
                'date' => $today,
                'winning_number' => $numbers[0],
                'number1' => $numbers[0],
                'number2' => $numbers[1],
                'number3' => $numbers[2],
                'selected_number' => null,
                'seed_hash' => hash('sha256', $roundId . '|' . $today . '|' . implode(',', $numbers)),
                'status' => 'pending',
                'is_deleted' => 0
            ]);

            return ['success' => true, 'message' => 'اعداد روزانه با موفقیت تولید شدند.', 'daily_id' => $id, 'numbers' => $numbers];
        } catch (\Throwable $e) {
            \App\Services\Sentry\SentryExceptionHandler::captureException($e, null, [
                'operation' => 'lottery.generateDailyNumbers',
                'round_id'  => $roundId,
            ]);
            return ['success' => false, 'message' => 'خطا در تولید اعداد: ' . $e->getMessage()];
        }
    }

    /** @return array<string, mixed> */
    public function finalizeDailyNumber(int $dailyId): array
    {
        try {
            $ok = $this->dailyModel->update($dailyId, ['status' => 'finalized']);
            return ['success' => $ok, 'message' => $ok ? 'اعداد روزانه نهایی شدند.' : 'خطا در نهایی‌سازی اعداد.'];
        } catch (\Throwable $e) {
            \App\Services\Sentry\SentryExceptionHandler::captureException($e, null, [
                'operation' => 'lottery.finalizeDailyNumber',
                'daily_id'  => $dailyId,
            ]);
            return ['success' => false, 'message' => 'خطا در نهایی‌سازی: ' . $e->getMessage()];
        }
    }

    /** @return array<string, mixed> */
    public function participate(int $userId, int $roundId, ?string $idempotencyKey = null): array
    {
        $idempotencyKey = $idempotencyKey ?: 'lottery_participation_' . $userId . '_' . $roundId . '_' . uniqid('', true);

        try {
            return $this->idempotencyService->executeWithTransaction(
                'lottery.participate',
                $userId,
                ['round_id' => $roundId],
                function() use ($userId, $roundId, $idempotencyKey) {
                    // Execute absolute FOR UPDATE row lock on the Lottery Round (LT-03 ⭐)
                    $round = $this->toObject($this->db->fetch("SELECT * FROM lottery_rounds WHERE id = ? FOR UPDATE", [$roundId]));
                    if (!$round || $round->status !== 'active') {
                        throw new \RuntimeException('دوره قرعه‌کشی فعال یافت نشد.');
                    }

                    $ticketPrice = (string)$round->ticket_price;

                    // Check maximum capacity (LT-05)
                    $countRow = $this->db->fetch("SELECT COUNT(*) AS cnt FROM lottery_participations WHERE round_id = ? AND is_deleted = 0", [$roundId]);
                    $currentParticipationsCount = (int)($countRow?->cnt ?? 0);
                    $maxCapacity = (int)($round->max_capacity ?? 1000);
                    if ($maxCapacity > 0 && $currentParticipationsCount >= $maxCapacity) {
                        throw new \RuntimeException('ظرفیت شرکت در این دوره از قرعه‌کشی تکمیل شده است.');
                    }

                    // Enforce single participation per user under absolute FOR UPDATE lock (LT-03 ⭐)
                    $alreadyParticipated = $this->toObject($this->db->fetch("SELECT id FROM lottery_participations WHERE user_id = ? AND round_id = ? AND is_deleted = 0 FOR UPDATE", [$userId, $roundId]));
                    if ($alreadyParticipated) {
                        throw new \RuntimeException('شما قبلاً در این دوره از قرعه‌کشی شرکت کرده‌اید.');
                    }

                    assert_fraud_allowed($userId, 'lottery.participate', ['amount' => $ticketPrice]);
                    $tx = $this->walletService->withdraw($userId, $ticketPrice, 'irt', [
                        'type' => 'lottery_ticket',
                        'round_id' => $roundId,
                        'ref_id' => $roundId,
                        'ref_type' => 'lottery_round',
                        'description' => 'خرید بلیط قرعه‌کشی',
                        'idempotency_key' => $idempotencyKey . '_wallet',
                    ]);

                    if (empty($tx['success'])) {
                        throw new \RuntimeException('موجودی ناکافی برای خرید بلیط قرعه‌کشی.');
                    }

                    $ticketNumbers = [];
                    while (count($ticketNumbers) < 3) {
                        $num = random_int(1, 49);
                        if (!in_array($num, $ticketNumbers, true)) {
                            $ticketNumbers[] = $num;
                        }
                    }
                    sort($ticketNumbers);
                    $ticketNumber = implode(',', $ticketNumbers);

                    $ticketId = (int)$this->participationModel->create([
                        'user_id' => $userId,
                        'round_id' => $roundId,
                        'ticket_number' => $ticketNumber,
                        'chance_score' => 100.0,
                        'status' => 'active',
                        'is_deleted' => 0,
                        'created_at' => date('Y-m-d H:i:s')
                    ]);
                    $this->logger->info('lottery.participate_success', ['user_id' => $userId, 'round_id' => $roundId, 'ticket_id' => $ticketId]);

                    // Dispatch ایونت برای آپدیت lottery_chance_score
                    try {
                        $this->outbox?->recordEvent(new \Core\GenericEvent([
                            'user_id'          => $userId,
                            'round_id'         => $roundId,
                            'ticket_id'        => $ticketId,
                            'event_name'       => 'lottery.participated',
                            'chance_increment' => 1.0,
                        ]));
                    } catch (\Throwable $ignore) {}

                    return ['success' => true, 'message' => 'ثبت‌نام شما با موفقیت انجام شد.', 'code' => 'LT-' . $ticketId, 'ticket_number' => $ticketNumber];
                },
                $idempotencyKey
            );
        } catch (\Throwable $e) {
            \App\Services\Sentry\SentryExceptionHandler::captureException($e, $userId, [
                'operation' => 'lottery.participate',
                'round_id'  => $roundId,
            ]);
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    /** @return array<string, mixed> */
    public function selectWinner(int $roundId, int $adminId): array
    {
        $saga = $this->sagaOrchestrator;

        try {
            // BUGFIX-SAGA-TX-ROOT: مشابه FinancialEscrowService — الگوی جبران‌سازی این
            // Saga از Closure استفاده می‌کند که توسط SagaRecoveryWorker قابل بازیابی
            // نیست (و آن Worker هم هرگز زمان‌بندی نشده). برای جلوگیری از حالت ناقص
            // (مثلاً برنده انتخاب شود ولی پرداخت جایزه fail شود)، کل Saga داخل
            // Transaction Root اجرا می‌شود.
            $result = $this->db->transactional(function () use ($saga, $roundId, $adminId) {
            return $saga
            ->setSaga('lottery_winner_selection', ['round_id' => $roundId, 'admin_id' => $adminId])
            ->addStep(
                'lock_and_pick',
                function($ctx) {
                    $round = $this->toObject($this->db->selectOne("SELECT * FROM lottery_rounds WHERE id = ? FOR UPDATE", [$ctx['round_id']]));
                    if (!$round || $round->status !== 'active') throw new \Core\Exceptions\InvalidStateException('دوره فعال نیست');

                    $participants = $this->participationModel->getAllActiveByRound($ctx['round_id']);
                    if (empty($participants)) throw new \Core\Exceptions\NotFoundException('شرکت کننده‌ای یافت نشد');

                    // 🛡️ Bug Fix: Weighted Random Selection بر اساس chance_score
                    // قبلی: array_rand($participants) → شانس یکسان برای همه
                    // جدید: هر شرکت‌کننده با وزن chance_score خودش در انتخاب تأثیر دارد
                    $totalWeight = 0;
                    $weights = [];
                    foreach ($participants as $p) {
                        $weight = max(0.01, floatval($p->chance_score ?? self::DEFAULT_WEIGHT));
                        $weights[] = ['participant' => $p, 'weight' => $weight];
                        $totalWeight += $weight;
                    }

                    if ($totalWeight <= 0) {
                        // Fallback: اگر همه وزن‌ها صفر بودند، شانس یکسان با انتخاب ایمن رمزی
                        $winner = $participants[random_int(0, count($participants) - 1)];
                    } else {
                        // انتخاب تصادفی وزن‌دار ایمن رمزی
                        $randomValue = (random_int(0, PHP_INT_MAX) / PHP_INT_MAX) * $totalWeight;
                        $cumulative = 0;
                        $winner = null;
                        foreach ($weights as $item) {
                            $cumulative += $item['weight'];
                            if ($randomValue <= $cumulative) {
                                $winner = $item['participant'];
                                break;
                            }
                        }
                        if ($winner === null) {
                            $winner = end($participants);
                        }
                    }
                    
                    $this->roundModel->update($ctx['round_id'], [
                        'status' => 'completed',
                        'winner_user_id' => $winner->user_id
                    ]);

                    return array_merge($ctx, ['winner_id' => $winner->user_id, 'prize' => $round->prize_amount]);
                },
                function($err, $res) {
                    $this->roundModel->update($res['round_id'], ['status' => 'active', 'winner_user_id' => null]);
                }
            )
            ->addStep(
                'pay_prize',
                function($ctx) {
                    $participants = $this->participationModel->getAllActiveByRound((int)$ctx['round_id']);
                    foreach ($participants as $participant) {
                        $hold = $this->db->fetch(
                            "SELECT transaction_id, amount FROM transactions
                             WHERE user_id = ? AND type = 'withdraw'
                               AND status IN ('pending', 'processing')
                               AND ref_id = ? AND ref_type = 'lottery_round'
                             ORDER BY id DESC LIMIT 1 FOR UPDATE",
                            [(int)$participant->user_id, (int)$ctx['round_id']]
                        );
                        if (!$hold) {
                            throw new \RuntimeException('hold بلیط برای یکی از شرکت‌کنندگان یافت نشد؛ دوره نیازمند بررسی مالی است.');
                        }
                        $spent = $this->walletService->spendLockedFunds(
                            (int)$participant->user_id,
                            (string)$hold->amount,
                            'irt',
                            [
                                'type' => 'lottery_ticket_settlement',
                                'description' => 'انتقال وجه بلیط به استخر قرعه‌کشی',
                                'ref_id' => (int)$ctx['round_id'],
                                'ref_type' => 'lottery_round',
                                'participation_id' => (int)$participant->id,
                                'ledger_credit_account' => 'lottery_pool',
                                'idempotency_key' => 'lottery_ticket_settlement_' . $participant->id,
                            ]
                        );
                        if (empty($spent['success']) || !$this->walletService->finalizeLockedSpend((int)$participant->user_id, (string)$hold->transaction_id)) {
                            throw new \RuntimeException('تسویه وجه بلیط قرعه‌کشی انجام نشد.');
                        }
                    }

                    $res = $this->walletService->deposit(intval($ctx['winner_id']), strval($ctx['prize']), 'irt', [
                        'type' => 'lottery_prize',
                        'round_id' => $ctx['round_id'],
                        'ref_id' => (int)$ctx['round_id'],
                        'ref_type' => 'lottery_round',
                        'ledger_debit_account' => 'lottery_pool',
                        'idempotency_key' => 'lottery_prize_' . $ctx['round_id'],
                    ]);
                    if (empty($res['success'])) throw new \Core\Exceptions\ApplicationException((is_string($res['message'] ?? null) ? $res['message'] : 'خطا در پرداخت جایزه'));

                    // Record official Audit Log (LT-04 / Finding #12 Fix)
                    $auditTrail = app(\App\Models\AuditTrail::class);
                    $auditTrail->createEntry([
                        'user_id'  => intval($ctx['winner_id']),
                        'actor_id' => intval($ctx['admin_id']),
                        'event'    => 'lottery.winner_selected',
                        'context'  => ['round_id' => $ctx['round_id'], 'prize' => $ctx['prize'], 'admin_id' => $ctx['admin_id']],
                    ]);

                    return ['tx_id' => $res['transaction_id']];
                },
                function($err, $res) {
                    if (isset($res['tx_id'])) {
                        $this->walletService->reverseTransaction($res['tx_id'], null, 'سیستمی: لغو جایزه');
                    }
                }
            )
            ->execute();
            });

            if (is_array($result)) {
                $result['success'] = true;
                $result['message'] = 'برنده قرعه‌کشی با موفقیت تعیین و جایزه واریز شد.';
                return $result;
            }

            return ['success' => true, 'message' => 'برنده قرعه‌کشی با موفقیت تعیین و جایزه واریز شد.'];
        } catch (\Throwable $e) {
            \App\Services\Sentry\SentryExceptionHandler::captureException($e, $adminId, [
                'operation' => 'lottery.selectWinner',
                'round_id'  => $roundId,
            ]);
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    /** @return array<string, mixed> */
    public function cancelRound(int $roundId, int $adminId, string $reason = ''): array
    {
        $saga = $this->sagaOrchestrator;

        // BUGFIX-SAGA-TX-ROOT: مشابه selectWinner — بازپرداخت به چندین شرکت‌کننده در یک
        // حلقه باید atomic باشد؛ اگر بین بازپرداخت‌ها خطا رخ دهد، بدون Transaction Root
        // بعضی کاربران refund می‌گیرند و بعضی نه (و compensate این Saga از Closure است،
        // پس توسط SagaRecoveryWorker هم قابل جبران خودکار نیست).
        try {
            $result = $this->db->transactional(function () use ($saga, $roundId, $adminId) {
        return $saga
            ->setSaga('lottery_round_cancellation', ['round_id' => $roundId, 'admin_id' => $adminId])
            ->addStep(
                'refund_participants',
                function($ctx) {
                    $round = $this->db->fetch(
                        "SELECT status FROM lottery_rounds WHERE id = ? AND is_deleted = 0 FOR UPDATE",
                        [(int)$ctx['round_id']]
                    );
                    if (!$round || !in_array((string)$round->status, [LotteryRound::STATUS_ACTIVE, LotteryRound::STATUS_VOTING], true)) {
                        throw new \RuntimeException('فقط دوره فعال یا در حال رأی‌گیری قابل لغو است.');
                    }
                    $participants = $this->participationModel->getAllActiveByRound($ctx['round_id']);
                    $refunds = [];
                    foreach ($participants as $p) {
                        // Lottery tickets do not create a shared escrow. Each
                        // participant has an individual wallet hold, so refunding
                        // one round-level escrow was both ambiguous and wrong.
                        $hold = $this->db->fetch(
                            "SELECT transaction_id FROM transactions
                             WHERE user_id = ? AND type = 'withdraw'
                               AND status IN ('pending', 'processing')
                               AND ref_id = ? AND ref_type = 'lottery_round'
                             ORDER BY id DESC LIMIT 1 FOR UPDATE",
                            [(int)$p->user_id, (int)$ctx['round_id']]
                        );
                        if (!$hold) {
                            throw new \RuntimeException('hold بلیط برای یکی از شرکت‌کنندگان یافت نشد؛ دوره نیازمند بررسی مالی است.');
                        }
                        if (!$this->walletService->cancelWithdrawal((int)$p->user_id, '0', 'irt', (string)$hold->transaction_id)) {
                            throw new \RuntimeException('بازگشت وجه بلیط قرعه‌کشی انجام نشد.');
                        }
                        $refunds[] = (int)$p->user_id;
                    }
                    return array_merge($ctx, ['refunded_users' => $refunds]);
                }
            )
            ->addStep(
                'update_round_status',
                function($ctx) {
                    $this->roundModel->update($ctx['round_id'], ['status' => 'cancelled']);
                    return $ctx;
                }
            )
            ->execute();
            });

            return array_merge(
                is_array($result) ? $result : [],
                ['success' => true, 'message' => 'دوره لغو شد و وجوه بلیط‌ها بازگشت داده شد.']
            );
        } catch (\Throwable $e) {
            \App\Services\Sentry\SentryExceptionHandler::captureException($e, $adminId, [
                'operation' => 'lottery.cancelRound',
                'round_id' => $roundId,
            ]);
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }
}
