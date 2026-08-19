<?php

declare(strict_types=1);

namespace App\Services\CryptoDeposit;

use App\Adapters\CryptoVerificationAdapter;
use App\Contracts\NotificationServiceInterface;
use App\Contracts\WalletServiceInterface;
use App\Services\Settings\AppSettings;
use App\Services\ReconciliationService;
use App\Models\CryptoDepositIntent;
use App\Models\CryptoDeposit;
use Core\Database;
use App\Contracts\LoggerInterface;
use App\Services\OutboxService;

use App\Services\StateMachineService;
use Core\ValueObjects\Money;

class CryptoDepositService
{
    private CryptoDepositIntent $intentModel;
    private CryptoDeposit $depositModel;
    private WalletServiceInterface $wallet;
    private CryptoVerificationAdapter $verifier;
    private AppSettings $appSettings;
    private ?OutboxService $outbox;
    private StateMachineService $stateMachine;

    private \Core\Database $db;
    private \App\Contracts\LoggerInterface $logger;
    private NotificationServiceInterface $notifier;
        private \App\Services\Shared\IdempotencyService $idempotencyService;
    private \Core\TransactionWrapper $transactionWrapper;
    private ?\App\Jobs\Payment\CreateCryptoDepositIntentJob $createCryptoDepositIntentJob;

    /**
     * ROOT-CAUSE HELPER (principled)
     */
    private function toObject(mixed $data): ?\stdClass
    {
        if ($data === null || $data === false) {
            return null;
        }
        /** @var \stdClass $obj */
        $obj = is_object($data) ? $data : (object)(is_array($data) ? $data : (array)$data);
        return $obj;
    }

    /**
     * سازنده اصلاح‌شده:
     *  - قبلاً متغیرهای تعریف‌نشده‌ی $walletService / $verifier / $reconciliationService به
     *    propertyها انتساب داده می‌شدند (باگ واقعی → propertyهای typed مقداردهی نمی‌شدند).
     *  - حالا تمام وابستگی‌هایی که کلاس واقعاً استفاده می‌کند به‌صورت صریح تزریق می‌شوند.
     */
    #[\Core\Attributes\Inject]
    private \Core\Container $container;

    public function __construct(
        \Core\Database $db,
        WalletServiceInterface $walletService,
        NotificationServiceInterface $notifier,
        CryptoDepositIntent $intentModel,
        CryptoDeposit $depositModel,
        \App\Contracts\LoggerInterface $logger,
        CryptoVerificationAdapter $verifier,
        AppSettings $appSettings,
        \Core\TransactionWrapper $transactionWrapper,
        \App\Services\Shared\IdempotencyService $idempotencyService,
        ?\App\Jobs\Payment\CreateCryptoDepositIntentJob $createCryptoDepositIntentJob = null,
        ?StateMachineService $stateMachine = null,
        ?OutboxService $outbox = null,
    ) {
        $this->db = $db;
        $this->wallet = $walletService;
        $this->notifier = $notifier;
        $this->intentModel = $intentModel;
        $this->depositModel = $depositModel;
        $this->logger = $logger;
        $this->verifier = $verifier;
        $this->appSettings = $appSettings;
        $this->transactionWrapper = $transactionWrapper;
        $this->createCryptoDepositIntentJob = $createCryptoDepositIntentJob;
        // رفع باگ: ترتیب آرگومان‌های StateMachineService در نسخه‌ی قبلی برعکس بود
        // (logger, db) → خطای TypeError. ترتیب صحیح: (db, logger).
        $this->stateMachine = $stateMachine ?? new StateMachineService($this->db, $this->logger);
        $this->outbox = $outbox;
        $this->idempotencyService = $idempotencyService;
        $this->container = \Core\Container::getInstance();
    }

    /**
     * Create a new crypto deposit intent
     */
    /** @return array<string, mixed> */
    public function createIntent(
        int $userId,
        string $network,
        string $requestedAmount,
        ?string $ipAddress = null,
        ?string $userAgent = null
    ): array {
        /** @var \App\Jobs\Payment\CreateCryptoDepositIntentJob $job */
        $job = $this->createCryptoDepositIntentJob ?? $this->container->make(\App\Jobs\Payment\CreateCryptoDepositIntentJob::class);
        return $job->handle($userId, $network, $requestedAmount, $ipAddress, $userAgent);
    }

    /**
     * Create a new crypto deposit (direct store from user)
     */
    /** @param array<string, mixed> $data
     *  @return array<string, mixed> */
    public function createDeposit(int $userId, array $data, ?string $idempotencyKey = null): array
    {
        $explicitKey = $idempotencyKey !== null && $idempotencyKey !== '' ? $idempotencyKey : null;

        return $this->idempotencyService->execute('crypto_deposit_store', $userId, $data, function() use ($userId, $data) {
            try {
                return $this->getTransactionWrapper()->runWithRetry(function() use ($userId, $data) {
                    $intentId = isset($data['intent_id']) && is_numeric($data['intent_id']) ? (int)$data['intent_id'] : 0;
                    if ($intentId <= 0) {
                        throw new \RuntimeException('شناسه درخواست واریز معتبر نیست');
                    }
                    $intent = $this->intentModel->findOpenForUpdate($intentId, $userId);
                    if (!$intent) {
                        throw new \RuntimeException('درخواست واریز یافت نشد یا قبلاً استفاده شده است');
                    }
                    if (empty($intent->expires_at) || strtotime((string)$intent->expires_at) < time()) {
                        $this->intentModel->expireIfPassed($intentId);
                        throw new \RuntimeException('مهلت درخواست واریز تمام شده است');
                    }

                    $network = strtoupper(trim((string)(is_scalar($data['network'] ?? null) ? $data['network'] : '')));
                    if ($network === '' || $network !== strtoupper((string)$intent->network)) {
                        throw new \RuntimeException('شبکه تراکنش با درخواست واریز مطابقت ندارد');
                    }

                    $fromWallet = trim((string)(is_scalar($data['from_wallet'] ?? null) ? $data['from_wallet'] : ''));
                    if ($fromWallet !== '' && !$this->isValidWalletAddress($network, $fromWallet)) {
                        throw new \RuntimeException('فرمت آدرس کیف‌پول مبدا با شبکه انتخاب‌شده مطابقت ندارد');
                    }

                    // Pessimistic lock check on tx_hash and network to prevent race condition and cross-network bypass.
                    $existingDeposit = $this->depositModel->findByHashAndNetworkForUpdate((string)(is_scalar($data['tx_hash'] ?? null) ? $data['tx_hash'] : ''), $network);
                    if ($existingDeposit) {
                        throw new \RuntimeException('این هش تراکنش قبلاً ثبت شده است');
                    }

                    // Amount and destination are server-side intent snapshots; never trust form values for them.
                    $data['intent_id'] = $intentId;
                    $data['user_id'] = $userId;
                    $data['network'] = $network;
                    $data['from_wallet'] = $fromWallet !== '' ? $fromWallet : null;
                    $data['amount'] = (string)$intent->expected_amount;
                    $data['currency'] = 'usdt';
                    $data['wallet_address'] = (string)$intent->to_wallet;
                    $data['verification_status'] = 'pending';
                    $data['auto_check_deadline'] = (string)$intent->expires_at;
                    $data['auto_check_attempts'] = 0;
                    $data['created_at'] = \date('Y-m-d H:i:s');
                    $data['updated_at'] = \date('Y-m-d H:i:s');

                    $depositId = $this->depositModel->create($data);

                    if (!$depositId) {
                        throw new \RuntimeException('خطا در ثبت درخواست');
                    }
                    if (!$this->intentModel->claimForDeposit($intentId, $userId)) {
                        throw new \RuntimeException('ثبت درخواست همزمان انجام شد؛ دوباره تلاش کنید');
                    }
                    
                    $this->logger->activity('crypto_deposit_requested', "درخواست واریز {$data['amount']} USDT ({$data['network']})", $userId, ['deposit_id' => $depositId]);

                    return [
                        'success' => true,
                        'message' => 'درخواست واریز شما ثبت شد و در حال بررسی خودکار است',
                        'deposit_id' => $depositId
                    ];
                });
            } catch (\Exception $e) {
                // If it's a PDOException with code 23000 (Integrity constraint violation) or duplicate entry
                if ($e instanceof \PDOException && ($e->getCode() === '23000' || \str_contains($e->getMessage(), 'Duplicate entry'))) {
                    return ['success' => false, 'message' => 'این هش تراکنش در همین لحظه ثبت شد و امکان ثبت مجدد وجود ندارد.'];
                }
                return ['success' => false, 'message' => $e->getMessage()];
            }
        }, $explicitKey);
    }

    /**
     * Approve a crypto deposit (admin action)
     */

    /**
     * تأیید واریز کریپتو توسط ادمین.
     *
     * نسخه‌ی قبلی این متد به ApproveCryptoDepositJob واگذار می‌کرد که آن Job هم
     * به‌صورت خودبازگشتی (infinite recursion) خودش را صدا می‌زد → کرش در زمان اجرا.
     * این پیاده‌سازی، جریان واقعی را با اعتبارسنجی State Machine + تراکنش امن انجام می‌دهد.
     */
    /** @return array<string, mixed> */
    public function approve(int $adminId, int $depositId): array
    {
        $deposit = $this->toObject($this->depositModel->find($depositId));
        if (!$deposit) {
            return ['success' => false, 'message' => 'واریز کریپتو یافت نشد.'];
        }
        $currentStatus = $deposit->verification_status ?? 'pending';

        $this->db->beginTransaction();
        try {
            // اعتبارسنجی گذار وضعیت: فقط گذارهای مجاز (مثلاً manual_review → verified)
            if (!$this->stateMachine->canTransition('crypto_deposit', $currentStatus, 'verified')) {
                $this->db->rollback();
                return [
                    'success' => false,
                    'message' => "تغییر وضعیت از وضعیت فعلی ({$currentStatus}) به verified مجاز نیست",
                ];
            }

            // واریز به کیف پول کاربر
            $metadata = [
                'type'                   => 'crypto_deposit',
                'gateway'                => 'usdt_' . ($deposit->network ?? ''),
                'gateway_transaction_id' => $deposit->tx_hash ?? null,
                'network'                => $deposit->network ?? null,
                'tx_hash'                => $deposit->tx_hash ?? null,
                'deposit_id'             => $depositId,
                'approved_by'            => $adminId,
            ];

            $depositResult = $this->wallet->deposit(
                (int)$deposit->user_id,
                (string)$deposit->amount,
                'usdt',
                $metadata
            );

            if (empty($depositResult['success'])) {
                $this->db->rollback();
                return ['success' => false, 'message' => 'خطا در واریز به کیف پول'];
            }

            $walletTransaction = $depositResult['transaction_id'] ?? null;
            $walletTxId = is_string($walletTransaction) ? $walletTransaction : null;

            // به‌روزرسانی وضعیت واریز
            $this->depositModel->updateStatus($depositId, 'verified', null, null, $adminId, $walletTxId);

            // اطلاع‌رسانی به کاربر
            $this->notifier->send(
                (int)$deposit->user_id,
                'deposit',
                'واریز کریپتو تأیید شد',
                'تراکنش واریز شما در شبکه ' . strtoupper((string)($deposit->network ?? '')) . ' به مبلغ ' . $deposit->amount . ' USDT تأیید شد.',
                ['amount' => $deposit->amount, 'network' => $deposit->network ?? null, 'tx_hash' => $deposit->tx_hash ?? null]
            );

            $this->db->commit();

            $this->logger->info('crypto.deposit.approved', [
                'deposit_id'  => $depositId,
                'admin_id'    => $adminId,
                'from_status' => $currentStatus,
            ]);

            return ['success' => true, 'message' => 'واریز تأیید شد', 'deposit_id' => $depositId];
        } catch (\Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollback();
            }
            $this->logger->error('crypto.deposit.approve_failed', [
                'deposit_id' => $depositId,
                'error'      => $e->getMessage(),
            ]);
            return ['success' => false, 'message' => 'خطا در تأیید واریز'];
        }
    }

    /**
     * رد واریز کریپتو توسط ادمین.
     * (نسخه‌ی قبلی نیز دچار بازگشت بی‌نهایت از طریق RejectCryptoDepositJob بود.)
     */
    /** @return array<string, mixed> */
    public function reject(int $adminId, int $depositId, string $reason): array
    {
        $deposit = $this->toObject($this->depositModel->find($depositId));
        if (!$deposit) {
            return ['success' => false, 'message' => 'واریز کریپتو یافت نشد.'];
        }
        $currentStatus = $deposit->verification_status ?? 'pending';

        $this->db->beginTransaction();
        try {
            if (!$this->stateMachine->canTransition('crypto_deposit', $currentStatus, 'rejected')) {
                $this->db->rollback();
                return [
                    'success' => false,
                    'message' => "تغییر وضعیت از وضعیت فعلی ({$currentStatus}) به rejected مجاز نیست",
                ];
            }

            $this->depositModel->updateStatus($depositId, 'rejected', null, $reason, $adminId, null);

            $this->notifier->send(
                (int)$deposit->user_id,
                'deposit',
                'واریز کریپتو رد شد',
                'درخواست واریز شما رد شد. دلیل: ' . $reason,
                ['network' => $deposit->network ?? null, 'tx_hash' => $deposit->tx_hash ?? null]
            );

            $this->db->commit();

            $this->logger->info('crypto.deposit.rejected', [
                'deposit_id' => $depositId,
                'admin_id'   => $adminId,
                'reason'     => $reason,
            ]);

            return ['success' => true, 'message' => 'واریز رد شد', 'deposit_id' => $depositId];
        } catch (\Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollback();
            }
            $this->logger->error('crypto.deposit.reject_failed', [
                'deposit_id' => $depositId,
                'error'      => $e->getMessage(),
            ]);
            return ['success' => false, 'message' => 'خطا در رد واریز'];
        }
    }

    /**
     * سعی در بررسی خودکار تراکنش کریپتو (بسترسازی تسک کرون)
     */
    /** @return array<string, mixed> */
    public function tryAutoVerify(int $depositId): array
    {
        $deposit = $this->toObject($this->depositModel->find($depositId));
        if (!$deposit) {
            return ['success' => false, 'message' => 'واریز کریپتو یافت نشد.'];
        }
        if ($deposit->verification_status !== 'pending') {
            return ['success' => false, 'message' => 'وضعیت تراکنش در انتظار بررسی نیست', 'auto' => false];
        }

        $network = strtoupper(trim($deposit->network));
        $toWallet = $this->getSiteWallet($network);

        if (!$toWallet) {
            return ['success' => false, 'message' => 'کیف‌پول سایت برای این شبکه تنظیم نشده است', 'auto' => false];
        }

        // Increment attempt count
        $attempts = (int)($deposit->auto_check_attempts ?? 0) + 1;
        $maxAttempts = (int)(is_numeric($this->appSettings->get('crypto_max_auto_check_attempts', 5)) ? $this->appSettings->get('crypto_max_auto_check_attempts', 5) : 5);

        try {
            $verifyResult = $this->verifier->verify(
                $network,
                (string)$deposit->tx_hash,
                (string)($deposit->from_wallet ?? ''),
                $toWallet,
                (string)$deposit->amount
            );

            $status = $verifyResult['status'] ?? 'pending';

            if ($status === 'verified') {
                // Auto approve
                $approveResult = $this->approve(0, $depositId); // 0 acts as System/Cron admin ID
                if ($approveResult['success']) {
                    return ['success' => true, 'message' => 'بررسی خودکار با موفقیت انجام و تأیید شد', 'auto' => true];
                }
                return ['success' => false, 'message' => 'خطا در ثبت واریز سیستم: ' . $approveResult['message'], 'auto' => false];
            }

            if ($status === 'mismatch') {
                // A mismatch is returned only after a provider supplied a
                // parseable on-chain transaction whose contract/destination/
                // amount/receipt is definitively wrong. This is safe to reject
                // automatically; it is not merely a provider outage.
                $reason = is_scalar($verifyResult['reason'] ?? null) ? (string)$verifyResult['reason'] : 'مغایرت قطعی اطلاعات تراکنش';
                $rejectResult = $this->reject(0, $depositId, $reason);
                if ($rejectResult['success'] ?? false) {
                    return ['success' => false, 'message' => 'تراکنش با اطلاعات درخواست مطابقت ندارد و رد شد', 'auto' => true, 'rejected' => true];
                }
                return ['success' => false, 'message' => $rejectResult['message'] ?? 'رد خودکار تراکنش انجام نشد', 'auto' => false];
            }

            if ($status === 'error') {
                // Error means data/provider is unavailable or incomplete, not
                // proof that the user did not pay. Preserve funds and route it
                // to a manager for explorer-based manual review.
                $reason = is_string($verifyResult['reason'] ?? null)
                    ? $verifyResult['reason']
                    : 'دادهٔ provider برای تایید قطعی کافی نیست';
                $this->depositModel->updateStatus($depositId, 'manual_review', null, $reason, null, (string)$attempts);
                return ['success' => false, 'message' => 'داده برای تأیید قطعی کافی نیست؛ انتقال به بررسی دستی', 'auto' => false, 'manual_review' => true];
            }

            // Still pending or unavailable
            if ($attempts >= $maxAttempts) {
                // Exceeded max attempts, send to manual review
                $this->depositModel->updateStatus($depositId, 'manual_review', null, 'تجاوز از حداکثر دفعات تلاش خودکار', null, (string)$attempts);
                return ['success' => false, 'message' => 'تجاوز از دفعات بررسی خودکار؛ انتقال به بررسی دستی', 'auto' => false];
            }

            // Just update attempts count
            $this->db->query("UPDATE crypto_deposits SET auto_check_attempts = ?, updated_at = NOW() WHERE id = ?", [$attempts, $depositId]);
            return ['success' => false, 'message' => 'تراکنش هنوز تأیید نشده یا ناشناخته است. تلاش مجدد در چرخه بعد', 'auto' => false];

        } catch (\Throwable $e) {
            $this->logger->error('crypto.auto_verify_failed', ['deposit_id' => $depositId, 'error' => $e->getMessage()]);
            return ['success' => false, 'message' => 'خطا در بررسی تراکنش: ' . $e->getMessage(), 'auto' => false];
        }
    }

    private function isValidWalletAddress(string $network, string $address): bool
    {
        $network = strtoupper(trim($network));
        $address = trim($address);
        return match ($network) {
            'BNB20',
            'TRC20' => (bool)preg_match('/^T[1-9A-HJ-NP-Za-km-z]{33}$/', $address),
            // TON accepts raw workchain:hex and standard friendly base64url addresses.
            'TON' => (bool)preg_match('/^(?:0:-?[a-f0-9]{64}|[EU]Q[A-Za-z0-9_-]{46})$/', $address),
            'SOL' => (bool)preg_match('/^[1-9A-HJ-NP-Za-km-z]{32,44}$/', $address),
            default => false,
        };
    }

    /** @param list<mixed> $args */
    private function recordNotificationOutbox(int $depositId, string $eventType, string $method, array $args): void
    {
        if (!$this->outbox) {
            return;
        }

        $this->outbox->record('crypto_deposit', (string)$depositId, $eventType, [
            'notification' => [
                'method' => $method,
                'args' => $args,
            ],
        ]);
    }

    /**
     * Get site wallet for network
     */
    private function getSiteWallet(string $network): ?string
    {
        /** @var array<string, string|null> $wallets */
        $wallets = [
            'TRC20' => $this->appSettings->get('site_usdt_trc20_address'),
            'BNB20' => $this->appSettings->get('site_usdt_bnb20_address'),
            'TON' => $this->appSettings->get('site_usdt_ton_address'),
            'SOL' => $this->appSettings->get('site_usdt_sol_address'),
        ];

        return $wallets[$network] ?? null;
    }

    /**
     * Generate unique amount for deposit intent
     */
    public function generateUniqueAmount(string $network, string $requestedAmount): string
    {
        $maxAttempts = \App\Constants\CryptoConstants::MAX_UNIQUE_AMOUNT_ATTEMPTS;
        $attempt = 0;
        $cache = cache();

        do {
            // HIGH-07: Formulate higher precision entropy bounds (8 decimals) to dilute collision density
            $randomAddition = \bcdiv((string)\random_int(1, 9999999), '100000000', 8);
            $expected = Money::fromString($requestedAmount, 'USDT')
                ->add(Money::fromString($randomAddition, 'USDT'))
                ->getAmount();

            // Use distributed cache lock to prevent concurrent race condition between threads generating unique amount (C-03)
            $lockKey = "lock_intent_amount_" . md5($network . "_" . (string)$expected);
            if ($cache->lock($lockKey, 10, 2)) {
                // Check global - both open/active intents and recently claimed intents to prevent amount collision replay attacks (C-08 & C-02)
                $stmt = $this->db->prepare("
                    SELECT COUNT(*) FROM crypto_deposit_intents 
                    WHERE network = ? AND expected_amount = ? 
                    AND (status = 'open' OR (status = 'claimed' AND claimed_at > DATE_SUB(NOW(), INTERVAL 7 DAY)))
                ");
                $stmt->execute([$network, $expected]);
                $count = (int)$stmt->fetchColumn();

                if ($count === 0) {
                    $cache->forget($lockKey);
                    return $expected;
                }

                // If collision is detected, release the lock immediately so another attempt can be made
                $cache->forget($lockKey);
            }

            $attempt++;
        } while ($attempt < $maxAttempts);

        // HIGH-07: Assert an atomic failure state instead of emitting duplicate/colliding amounts which allows payment hijacking
        throw new \RuntimeException("امکان تولید شناسه واریز منحصر به فرد در این لحظه وجود ندارد. لطفاً دقایقی دیگر تلاش نمایید.");
    }

    /**
     * Cleanup expired intents (can be called via cron job)
     */

    public function cleanupExpiredIntents(): int
    {
        try {
            // اصلاح کلیدی عملکرد پایگاه داده در کرون‌جاب‌ها (Crypto Intent Table Lock Shield):
            // اعمال محدودیت دسته‌ای (LIMIT 1000) بر روی دستور UPDATE جهت جلوگیری از قفل شدن کل جدول دیتابیس در ترافیک بالای کاربران موبایل
            $stmt = $this->db->prepare("UPDATE crypto_deposit_intents SET status = 'expired', updated_at = NOW() WHERE status = 'open' AND expires_at < NOW() LIMIT 1000");
            $stmt->execute();
            return $stmt->rowCount();
        } catch (\Throwable) {
            return 0;
        }
    }

    /**
     * جستجوی سریع واریزهای کریپتو برای سیستم سرچ مرکزی
     */
    /** @return list<\stdClass> */
    public function quickSearchCryptoDeposits(string $term, int $limit = 5): array
    {
        $term = trim((string)$term);
        if (\strlen((string)$term) > 100) {
            return []; // Defensively reject overly long search terms to protect database performance (C-11 / C-14)
        }

        $query = $this->depositModel->query()
            ->selectRaw("crypto_deposits.id, crypto_deposits.amount, 'crypto' as type, crypto_deposits.verification_status as status, crypto_deposits.created_at, u.full_name, u.email")
            ->leftJoin('users as u', 'u.id', '=', 'crypto_deposits.user_id');

        $this->depositModel->applySearch($query, $term);

        if (!empty($term)) {
            $escaped = addcslashes($term, '%_');
            $like = "%{$escaped}%";
            $query->where(function($sub) use ($like) {
                $sub->orWhere('u.email', 'LIKE', $like);
            });
        }

        return $query->orderBy('crypto_deposits.created_at', 'DESC')
                     ->limit($limit)
                     ->get();
    }
    // --- SAGA STEP METHODS FOR APPROVE ---

    /** @param array<string, mixed> $payload
     *  @return array<string, mixed> */
    public function executeApproveValidate(array $payload): array
    {
        $depositId = (int)(is_numeric($payload['deposit_id'] ?? null) ? $payload['deposit_id'] : 0);
        $deposit = $this->toObject($this->depositModel->find($depositId));
        if (!$deposit) {
            throw new \Core\Exceptions\NotFoundException('واریز یافت نشد');
        }

        $currentStatus = $deposit->verification_status ?? 'pending';
        if (!$this->stateMachine->canTransition('crypto_deposit', $currentStatus, 'verified')) {
            throw new \Core\Exceptions\InvalidStateException("تغییر وضعیت از وضعیت فعلی ({$currentStatus}) به تأییدشده مجاز نیست");
        }
        $payload['user_id'] = $deposit->user_id;
        $payload['amount'] = $deposit->amount;
        $payload['network'] = $deposit->network;
        $payload['tx_hash'] = $deposit->tx_hash;
        $payload['current_status'] = $currentStatus;
        return $payload;
    }

    /** @param array<string, mixed> $payload */
    public function compensateApproveValidate(array $payload, mixed $result, \Throwable $e): void
    {
        // No action needed for validation
    }

    /** @param array<string, mixed> $payload
     *  @return array<string, mixed> */
    public function executeApproveWallet(array $payload): array
    {
        $depositId = (int)(is_numeric($payload['deposit_id'] ?? null) ? $payload['deposit_id'] : 0);
        $adminId = $payload['admin_id'];

        $metadata = [
            'type' => 'crypto_deposit',
            'gateway' => 'usdt_' . $payload['network'],
            'gateway_transaction_id' => $payload['tx_hash'],
            'description' => 'واریز USDT - ' . strtoupper((string)(is_scalar($payload['network'] ?? null) ? $payload['network'] : '')),
            'network' => $payload['network'],
            'tx_hash' => $payload['tx_hash'],
            'deposit_id' => $depositId,
            'approved_by' => $adminId,
        ];

        if ($this->outbox) {
            $ok = $this->outbox->record('crypto_deposit', $depositId, \App\Events\Registry\EventRegistry::CRYPTO_DEPOSIT_CONFIRMED, ['user_id' => $payload['user_id'], 'amount' => $payload['amount'], 'currency' => 'usdt', 'metadata' => $metadata]);
            if (!$ok) {
                throw new \Core\Exceptions\ApplicationException('خطا در ثبت رکورد خروجی برای واریز کریپتو');
            }
        } else {
            $depositResult = $this->wallet->deposit((int)(is_numeric($payload['user_id'] ?? null) ? $payload['user_id'] : 0), (string)(is_scalar($payload['amount'] ?? null) ? $payload['amount'] : ''), 'usdt', $metadata);
            if (!$depositResult['success']) {
                throw new \Core\Exceptions\ApplicationException('خطا در واریز به کیف پول');
            }
            $payload['wallet_transaction_id'] = $depositResult['transaction_id'] ?? null;
        }
        return $payload;
    }

    /** @param array<string, mixed> $payload */
    public function compensateApproveWallet(array $payload, mixed $result, \Throwable $e): void
    {
        /** @var array<string, mixed>|null $result */
        $this->logger->warning('saga.compensating.crypto_approve_wallet', ['deposit_id' => $payload['deposit_id']]);
        if (isset($result['wallet_transaction_id']) || !isset($payload['wallet_transaction_id'])) {
            $this->wallet->withdraw((int)(is_numeric($payload['user_id'] ?? null) ? $payload['user_id'] : 0), (string)(is_scalar($payload['amount'] ?? null) ? $payload['amount'] : ''), 'usdt', ['type' => 'saga_compensation', 'deposit_id' => $payload['deposit_id']]);
        }
    }

    /** @param array<string, mixed> $payload
     *  @return array<string, mixed> */
    public function executeApproveStatus(array $payload): array
    {
        $depositId = (int)(is_numeric($payload['deposit_id'] ?? null) ? $payload['deposit_id'] : 0);
        $adminId = is_numeric($payload['admin_id'] ?? null) ? (int)$payload['admin_id'] : null;
        $txIdValue = $payload['wallet_transaction_id'] ?? null;
        $txId = is_scalar($txIdValue) ? (string)$txIdValue : null;
        $this->depositModel->updateStatus($depositId, 'verified', null, null, $adminId, $txId);

        $this->logger->info('crypto.deposit.status_transition', [
            'deposit_id' => $depositId,
            'user_id' => $payload['user_id'],
            'from_status' => $payload['current_status'],
            'to_status' => 'verified',
            'operator_id' => $adminId,
            'triggered_by' => 'admin_approve',
        ]);

        $this->recordNotificationOutbox($depositId, 'notification.crypto_deposit_approved', 'send', [
            (int)(is_numeric($payload['user_id'] ?? null) ? $payload['user_id'] : 0),
            'deposit',
            'واریز کریپتو تأیید شد',
            'تراکنش واریز شما در شبکه ' . strtoupper((string)(is_scalar($payload['network'] ?? null) ? $payload['network'] : '')) . ' به مبلغ ' . $payload['amount'] . ' USDT تأیید شد.',
            ['amount' => $payload['amount'], 'network' => $payload['network'], 'tx_hash' => $payload['tx_hash']]
        ]);

        return $payload;
    }

    /** @param array<string, mixed> $payload */
    public function compensateApproveStatus(array $payload, mixed $result, \Throwable $e): void
    {
        $this->depositModel->updateStatus((int)(is_numeric($payload['deposit_id'] ?? null) ? $payload['deposit_id'] : 0), (string)(is_scalar($payload['current_status'] ?? null) ? $payload['current_status'] : ''), null, null, isset($payload['admin_id']) && is_numeric($payload['admin_id']) ? (int)$payload['admin_id'] : null, null);
    }

    private function getTransactionWrapper(): \Core\TransactionWrapper
    {
        return $this->transactionWrapper;
    }
}