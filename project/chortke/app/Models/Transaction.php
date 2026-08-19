<?php

namespace App\Models;

use Core\Model;
use App\Traits\Filterable;

class Transaction extends Model
{
    use Filterable;

    protected static string $table = 'transactions';

    /**
     * Relations برای eager loading با allWith/loadRelations
     *
     * استفاده:
     *   // لیست تراکنش‌ها با کاربر مربوطه
     *   $transactions = $transactionModel->allWith(['user'], 50);
     *   echo $transactions[0]->user->full_name;
     *
     *   // یک تراکنش با اطلاعات کامل کاربر و کیف‌پول
     *   $tx = $transactionModel->findWith($id, ['user', 'wallet']);
     */
    protected array $relations = [
        'user'   => [\App\Models\User::class,   'user_id', 'one'],
        'wallet' => [\App\Models\Wallet::class,  'user_id', 'one'],
    ];
    protected static array $searchable = ['t.reference_id', 't.description'];

    /** @var array<string, array{string, string}> */
    protected static array $filterable = [
        'status' => ['t.status', '='],
        'type' => ['t.type', '='],
        'currency' => ['t.currency', '='],
        'user_id' => ['t.user_id', '='],
    ];

    protected \App\Contracts\LoggerInterface $logger;

    public function __construct(\Core\Database $db, \App\Contracts\LoggerInterface $logger) {
        parent::__construct($db);
        $this->logger = $logger;
    }

    /**
     * ایجاد تراکنش جدید
     * M37: Balance validation removed - responsibility of TransactionService
     */
    /** @param array<string, mixed> $data */
    public function createTransaction(array $data): ?\stdClass
    {
        $type = str_value($data['type'] ?? '');

        // 1. Check duplicate with idempotency_key
        if (isset($data['idempotency_key']) && $data['idempotency_key'] !== '') {
            $existing = $this->findByIdempotencyKey(str_value($data['idempotency_key'] ?? ''));
            if ($existing) {
                return $existing;
            }
        } elseif (\in_array($type, ['deposit', 'withdraw'], true)) {
            $data['idempotency_key'] = $this->generateIdempotencyKey($data);
        }

        try {
            // M37: Service layer handles database transaction management and balance validation.
            // Model only persists data within existing transaction context to avoid nested transaction anomalies.

            if (!isset($data['transaction_id']) || $data['transaction_id'] === '') {
                $data['transaction_id'] = $this->generateUUID();
            }

            // M-02: IP and Fingerprint must be passed from Controller/Service, not fetched from globals
            // This ensures testability and proper separation of concerns
            if (!isset($data['ip_address'])) {
                throw new \InvalidArgumentException('ip_address must be provided');
            }
            if (!isset($data['device_fingerprint'])) {
                throw new \InvalidArgumentException('device_fingerprint must be provided');
            }

            if (isset($data['metadata']) && \is_array($data['metadata'])) {
                $data['metadata'] = \json_encode($data['metadata'], JSON_UNESCAPED_UNICODE);
            }

            $now = \date('Y-m-d H:i:s');
            $data['created_at'] = $data['created_at'] ?? $now;
            $data['updated_at'] = $data['updated_at'] ?? $now;

            $idOrBool = parent::create($data);

            if (!\is_int($idOrBool)) {
                return null;
            }

            return $this->find($idOrBool);

        } catch (\Exception $e) {
            if (isset($data['idempotency_key']) && $data['idempotency_key'] !== '') {
                if ($e->getCode() === '23000' || \strpos($e->getMessage(), '23000') !== false || \strpos($e->getMessage(), '1062') !== false) {
                    $existing = $this->findByIdempotencyKey(str_value($data['idempotency_key'] ?? ''));
                    if ($existing) {
                        return $existing;
                    }
                }
            }
            $this->logger->error('transaction.create.failed', [
                'channel' => 'transaction',
                'error' => $e->getMessage(),
                'data' => $data
            ]);
            throw $e;
        }
    }

    /**
     * دریافت تراکنش‌های اخیر کاربر
     */
    /** @return list<array<string, mixed>> */
    public function getRecentByUserId(int $userId, int $limit = 100): array
    {
        $stmt = $this->db->query(
            "SELECT id, type, amount, status, created_at FROM `" . static::$table . "` WHERE user_id = ? ORDER BY created_at DESC LIMIT ?",
            [$userId, $limit]
        );

        return $this->fetchAssocList($stmt);
    }

    /**
     * ثبت تغییر وضعیت در transaction_events (Immutable Audit Trail)
     * 
     * این متد اصل Event Sourcing را رعایت می‌کند و هیچ‌وقت داده‌های قبلی را UPDATE نمی‌کند.
     * 
     * @param string $transactionId شناسه یکتا تراکنش
     * @param string $newStatus وضعیت جدید
     * @param string|null $reason دلیل تغییر
     * @param int|null $changedBy
     * @param array<string, mixed>|null $eventMetadata
     * @return bool
     */
    public function recordStatusChange(
        string $transactionId,
        string $newStatus,
        ?string $reason = null,
        ?int $changedBy = null,
        ?array $eventMetadata = null
    ): bool {
        $startedTransaction = !$this->db->inTransaction();
        try {
            if ($startedTransaction) {
                $this->db->beginTransaction();
            }

            // دریافت تراکنش و وضعیت فعلی
            $transaction = $this->findByTransactionId($transactionId);
            
            if (!$transaction) {
                if ($startedTransaction) { 
                    $this->db->rollback(); 
                }
                $this->logger->warning('transaction.not_found', [
                    'channel' => 'transaction',
                    'transaction_id' => $transactionId,
                ]);
                return false;
            }
            
            $previousStatus = $transaction->status;
            
            // اگر وضعیت تغییری نکرده، نیازی به ثبت event نیست
            if ($previousStatus === $newStatus) {
                if ($startedTransaction) { 
                    $this->db->commit(); 
                }
                $this->logger->info('transaction.status.unchanged', [
                    'channel' => 'transaction',
                    'transaction_id' => $transactionId,
                    'status' => $newStatus,
                ]);
                return true;
            }
            $eventMetadata = $eventMetadata ?? [];

            // ۱. ثبت event در transaction_events (Immutable)
            $eventSql = "INSERT INTO transaction_events (
                transaction_id, 
                event_type, 
                previous_status, 
                new_status, 
                reason, 
                changed_by, 
                metadata,
                created_at
            ) VALUES (
                :transaction_id,
                :event_type,
                :previous_status,
                :new_status,
                :reason,
                :changed_by,
                :metadata,
                NOW()
            )";
            
            $eventParams = [
                'transaction_id' => $transactionId,
                'event_type' => 'status_change',
                'previous_status' => $previousStatus,
                'new_status' => $newStatus,
                'reason' => $reason,
                'changed_by' => $changedBy,
                'metadata' => \json_encode($eventMetadata, JSON_UNESCAPED_UNICODE)
            ];
            
            $eventStmt = $this->db->prepare($eventSql);
            $eventStmt->execute($eventParams);
            
            // ۲. UPDATE تراکنش (برای backward compatibility و query performance)
            $updateSql = "UPDATE `" . static::$table . "` 
                          SET status = :status, updated_at = NOW()";
            
            $updateParams = [
                'transaction_id' => $transactionId,
                'status' => $newStatus
            ];
            
            // اگر وضعیت completed شد، زمان completion ثبت می‌شود
            if ($newStatus === 'completed') {
                $updateSql .= ", completed_at = NOW()";
            }
            
            $updateSql .= " WHERE transaction_id = :transaction_id";
            
            $updateStmt = $this->db->prepare($updateSql);
            $updateStmt->execute($updateParams);
            
            if ($startedTransaction) {
                $this->db->commit();
            }

            // لاگ موفقیت
            $this->logger->info('transaction.status.changed', [
                'channel' => 'transaction',
                'transaction_id' => $transactionId,
                'from' => $previousStatus,
                'to' => $newStatus,
                'reason' => $reason,
            ]);
            return true;
            
        } catch (\Throwable $e) {
            if ($startedTransaction && $this->db->inTransaction()) {
                $this->db->rollback();
            }
            $this->logger->error('transaction.status_change.record.failed', [
                'channel' => 'transaction',
                'transaction_id' => $transactionId,
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }
    
    /**
     * دریافت تاریخچه تغییرات یک تراکنش (Event History)
     * 
     * @param string $transactionId
     * @return array
     */
    /** @return list<array<string, mixed>> */
    public function getStatusHistory(string $transactionId): array
    {
        try {
            $sql = "SELECT 
                        event_type,
                        previous_status,
                        new_status,
                        reason,
                        changed_by,
                        NULL AS ip_address,
                        metadata,
                        created_at
                    FROM transaction_events
                    WHERE transaction_id = :transaction_id
                    ORDER BY created_at ASC";
            
            $stmt = $this->db->prepare($sql);
            $stmt->execute(['transaction_id' => $transactionId]);
            
            return $stmt->fetchAll(\PDO::FETCH_ASSOC);
            
        } catch (\PDOException $e) {
            $this->logger->error('transaction.status_history.fetch.failed', [
    'channel' => 'transaction',
    'transaction_id' => $transactionId,
    'error' => $e->getMessage(),
]);
            return [];
        }
    }
    
    /**
     * دریافت آخرین event یک تراکنش
     * 
     * @param string $transactionId
     * @return array|null
     */
    /** @return array<string, mixed>|null */
    public function getLatestEvent(string $transactionId): ?array
    {
        try {
            $sql = "SELECT *
                    FROM transaction_events
                    WHERE transaction_id = :transaction_id
                    ORDER BY created_at DESC
                    LIMIT 1";
            
            $stmt = $this->db->prepare($sql);
            $stmt->execute(['transaction_id' => $transactionId]);
            
            $result = $this->fetchAssoc($stmt);
            return $result !== [] ? $result : null;
            
        } catch (\PDOException $e) {
            $this->logger->error('transaction.latest_event.fetch.failed', [
    'channel' => 'transaction',
    'transaction_id' => $transactionId,
    'error' => $e->getMessage(),
]);
            return null;
        }
    }

    /**
     * دریافت تراکنش بر اساس transaction_id
     */
    public function findByTransactionId(string $transactionId): ?\stdClass
    {
        $sql = "SELECT * FROM `" . static::$table . "` WHERE transaction_id = :transaction_id LIMIT 1";
        $stmt = $this->db->prepare($sql);
        $stmt->execute(['transaction_id' => $transactionId]);
        $result = $this->fetchObject($stmt);
        return $result;
    }

    /**
     * BUGFIX-CTRL-RAW-SQL-2026-06.
     *
     * Lookup by surrogate primary key. Distinct from findByTransactionId()
     * which uses the business identifier transaction_id (UUID-like).
     * Added because Admin\TransactionController::reverse() needed to fetch
     * a row by its integer id without going through the controller-side
     * raw SQL it used to inline.
     */
    public function findById(int $id): ?\stdClass
    {
        $sql = "SELECT * FROM `" . static::$table . "` WHERE id = :id LIMIT 1";
        $stmt = $this->db->prepare($sql);
        $stmt->execute(['id' => $id]);
        $result = $this->fetchObject($stmt);
        return $result;
    }

    /**
     * یافتن آخرین تراکنش موفق مربوط به یک مرجع (ID & Type)
     */
    public function findCompletedByReference(string $refId, string $refType): ?\stdClass
    {
        return $this->db->table(static::$table)
            ->where('ref_id', '=', $refId)
            ->where('ref_type', '=', $refType)
            ->where('status', '=', 'completed')
            ->orderBy('created_at', 'DESC')
            ->first();
    }

    /**
     * دریافت تراکنش بر اساس شناسه خارجی یا گیت‌وی
     */
    public function findByExternalId(string $externalId): ?\stdClass
    {
        // جستجو در هر دو ستون محتمل external_id و gateway_transaction_id
        $sql = "SELECT * FROM `" . static::$table . "` 
                WHERE external_id = :ext_id 
                OR gateway_transaction_id = :ext_id 
                LIMIT 1";
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute(['ext_id' => $externalId]);
        $result = $this->fetchObject($stmt);
        return $result;
    }

    /**
     * دریافت تراکنش بر اساس Idempotency Key
     */
    public function findByIdempotencyKey(string $key): ?\stdClass
    {
        $sql = "SELECT * FROM `" . static::$table . "` WHERE idempotency_key = :k LIMIT 1";
        $stmt = $this->db->prepare($sql);
        $stmt->execute(['k' => $key]);
        $result = $this->fetchObject($stmt);
        return $result;
    }

    /**
     * دریافت تراکنش‌های کاربر
     * @return list<\stdClass>
     */
    public function getUserTransactions(
        int $userId,
        ?string $type = null,
        ?string $currency = null,
        int $limit = 50,
        int $offset = 0
    ): array {
        $limit  = \max(1, (int)$limit);
        $offset = \max(0, (int)$offset);

        $sql = "SELECT * FROM `" . static::$table . "` WHERE user_id = :user_id";
        $params = ['user_id' => $userId];

        if ($type) {
            $sql .= " AND type = :type";
            $params['type'] = $type;
        }

        if ($currency) {
            $sql .= " AND currency = :currency";
            $params['currency'] = $currency;
        }

        $sql .= " ORDER BY created_at DESC LIMIT :limit OFFSET :offset";

        $stmt = $this->db->prepare($sql);
        foreach ((array)$params as $key => $val) {
            $stmt->bindValue(':' . \ltrim($key, ':'), $val);
        }
        $stmt->bindValue(':limit', $limit, \PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, \PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll(\PDO::FETCH_OBJ);
    }

    /**
     * شمارش تراکنش‌های کاربر
     */
    public function countUserTransactions(int $userId, ?string $type = null, ?string $currency = null): int
    {
        $sql = "SELECT COUNT(*) as count FROM `" . static::$table . "` WHERE user_id = :user_id";
        $params = ['user_id' => $userId];

        if ($type) {
            $sql .= " AND type = :type";
            $params['type'] = $type;
        }

        if ($currency) {
            $sql .= " AND currency = :currency";
            $params['currency'] = $currency;
        }

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        $result = $this->fetchObject($stmt);
        return int_value($result?->count ?? 0);
    }

    /**
     * @deprecated CQRS: Use TransactionQuery::getUserStats instead
     */
    public function getUserStats(int $userId): object
    {
        throw new \RuntimeException("CQRS Violation: Do not use Transaction model for heavy reporting. Use TransactionQuery.");
    }

    /**
     * دریافت تمام تراکنش‌ها (ادمین)
     */
    /** @return list<\stdClass> */
    public function getAll(?string $status = null, ?string $type = null, ?string $currency = null, int $limit = 50, int $offset = 0): array
    {
        $limit  = \max(1, (int)$limit);
        $offset = \max(0, (int)$offset);

        $sql = "SELECT t.*, u.full_name, u.email
                FROM `" . static::$table . "` t
                LEFT JOIN users u ON t.user_id = u.id
                WHERE 1=1";
        $params = [];

        if ($status) {
            $sql .= " AND t.status = :status";
            $params['status'] = $status;
        }
        if ($type) {
            $sql .= " AND t.type = :type";
            $params['type'] = $type;
        }
        if ($currency) {
            $sql .= " AND t.currency = :currency";
            $params['currency'] = $currency;
        }

        $sql .= " ORDER BY t.created_at DESC LIMIT :limit OFFSET :offset";

        $stmt = $this->db->prepare($sql);
        foreach ((array)$params as $key => $val) {
            $stmt->bindValue(':' . \ltrim($key, ':'), $val);
        }
        $stmt->bindValue(':limit', $limit, \PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, \PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll(\PDO::FETCH_OBJ);
    }

    /**
     * شمارش کل تراکنش‌ها (ادمین)
     */
    public function countAll(?string $status = null, ?string $type = null, ?string $currency = null): int
    {
        $sql = "SELECT COUNT(*) as count FROM `" . static::$table . "` WHERE 1=1";
        $params = [];

        if ($status) {
            $sql .= " AND status = :status";
            $params['status'] = $status;
        }
        if ($type) {
            $sql .= " AND type = :type";
            $params['type'] = $type;
        }
        if ($currency) {
            $sql .= " AND currency = :currency";
            $params['currency'] = $currency;
        }

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        $result = $this->fetchObject($stmt);
        return int_value($result?->count ?? 0);
    }

    private function generateUUID(): string
    {
        $data = \random_bytes(16);
        $data[6] = \chr(\ord($data[6]) & 0x0f | 0x40); // Set version to 4 (0100)
        $data[8] = \chr(\ord($data[8]) & 0x3f | 0x80); // Set bits 6-7 to 10
        
        return \vsprintf('%s%s-%s-%s-%s-%s%s%s', \str_split(\bin2hex($data), 4));
    }

    /** @param array<string, mixed> $data */
    private function generateIdempotencyKey(array $data): string
    {
        $seed = \implode('|', [
            str_value($data['user_id'] ?? ''),
            str_value($data['type'] ?? ''),
            str_value($data['amount'] ?? ''),
            str_value($data['currency'] ?? ''),
            str_value($data['ref_id'] ?? ''),
            str_value($data['ref_type'] ?? ''),
            str_value($data['request_id'] ?? ''),
        ]);
        return \hash('sha256', $seed);
    }

    /**
     * بروزرسانی وضعیت تراکنش با شناسه UUID (برای completeWithdrawal و cancelWithdrawal)
     */
    public function updateStatusByTransactionId(string $transactionId, int $userId, string $status): bool
    {
        $transaction = $this->findByTransactionId($transactionId);
        if (!$transaction || (int)$transaction->user_id !== $userId) {
            return false;
        }

        return $this->recordStatusChange(
            $transactionId,
            $status,
            null,
            null,
            ['updated_by' => $userId]
        );
    }

    /**
     * H-01: ادعای اتمیک یک تراکنش completed برای reversal.
     *
     * ریشه‌ی باگ double-reversal: جریان قبلی status='completed' را می‌خواند و
     * فقط در انتها status='reversed' را می‌نوشت، پس دو فراخوان هم‌زمان (یا retry)
     * می‌توانستند هر دو گارد را رد کنند و کاربر را دوبار credit کنند. این متد یک
     * UPDATE شرطی (guard = status='completed') اجرا می‌کند و تعداد ردیف متأثر را به‌عنوان
     * compare-and-swap تلقی می‌کند: دقیقاً یک فراخوان می‌تواند completed -> reversed را انجام دهد.
     * باید داخل تراکنش DB فراخوان‌کننده اجرا شود تا claim با تغییر موجودی و ثبت
     * تراکنش reversal به‌صورت اتمیک commit/rollback شود.
     *
     * @return bool true اگر این فراخوان برنده‌ی claim شد؛ false اگر قبلاً reversed شده یا completed نیست.
     */
    public function claimForReversal(string $transactionId, int $userId, ?int $changedBy = null): bool
    {
        $affected = $this->db->execute(
            "UPDATE `" . static::$table . "`
             SET status = 'reversed', updated_at = NOW()
             WHERE transaction_id = :transaction_id
               AND user_id = :user_id
               AND status = 'completed'",
            ['transaction_id' => $transactionId, 'user_id' => $userId]
        );

        if ($affected !== 1) {
            return false;
        }

        // ثبت رویداد تغییر وضعیت (Immutable audit trail) — هم‌راستا با recordStatusChange
        $this->db->execute(
            "INSERT INTO transaction_events
                (transaction_id, event_type, previous_status, new_status, reason, changed_by, metadata, created_at)
             VALUES
                (:transaction_id, 'status_change', 'completed', 'reversed', :reason, :changed_by, :metadata, NOW())",
            [
                'transaction_id' => $transactionId,
                'reason'         => 'reversal_claim',
                'changed_by'     => $changedBy,
                'metadata'       => \json_encode(['claimed_for' => 'reversal'], JSON_UNESCAPED_UNICODE),
            ]
        );

        return true;
    }

    /**
     * H-02: آیا این تراکنش تحت مدیریت زیرسیستم Escrow است؟
     *
     * چنین تراکنش‌هایی (اعم از hold اولیه یا leg تسویه/حل‌اختلاف) نباید از مسیر
     * reverseTransaction عمومی برگردانده شوند، چون hold مربوط به آن‌ها ممکن است
     * قبلاً در payout/refund مصرف شده باشد؛ reverse عمومی وجهِ تسویه‌شده را دوباره
     * اعتبار می‌دهد و invariant کیف پول/escrow را می‌شکند. حل‌وفصل باید از مسیر
     * canonical خود escrow (refund/dispute) انجام شود.
     *
     * تشخیص بر پایهٔ ref_type='escrow' یا metadata.type با الگوهای escrow است:
     *   *_escrow (custom_deal_escrow, social_task_escrow, influencer_escrow, vitrine_escrow)
     *   escrow_* (escrow_hold, escrow_refund, escrow_dispute_*)
     *   *_escrow_* (influencer_escrow_seller_spend, influencer_escrow_platform_fee)
     *
     * @param object $transaction ردیف تراکنش (stdClass) با فیلدهای ref_type و metadata.
     */
    public function isEscrowManaged(object $transaction): bool
    {
        $refType = strtolower(trim((string)($transaction->ref_type ?? '')));
        if ($refType === 'escrow') {
            return true;
        }

        $metaType = '';
        $rawMeta = $transaction->metadata ?? null;
        if (is_array($rawMeta)) {
            $metaType = strtolower((string)($rawMeta['type'] ?? ''));
        } elseif (is_string($rawMeta) && $rawMeta !== '') {
            $decoded = json_decode($rawMeta, true);
            if (is_array($decoded) && isset($decoded['type']) && is_string($decoded['type'])) {
                $metaType = strtolower($decoded['type']);
            }
        }

        if ($metaType === '') {
            return false;
        }

        return $metaType === 'escrow_hold'
            || str_starts_with($metaType, 'escrow_')
            || str_ends_with($metaType, '_escrow')
            || str_contains($metaType, '_escrow_');
    }

    /**
     * دریافت device fingerprint های شناخته‌شده کاربر (30 روز اخیر)
     */
    /** @return list<array<string, mixed>> */
    public function getKnownDeviceFingerprints(int $userId, int $days = 30, int $limit = 5): array
    {
        $sql = "SELECT DISTINCT device_fingerprint, COUNT(*) as usage_count
                FROM `" . static::$table . "`
                WHERE user_id = :user_id
                  AND device_fingerprint IS NOT NULL
                  AND created_at >= DATE_SUB(NOW(), INTERVAL :days DAY)
                GROUP BY device_fingerprint
                ORDER BY usage_count DESC
                LIMIT :lim";

        $stmt = $this->db->prepare($sql);
        $stmt->bindValue(':user_id', $userId, \PDO::PARAM_INT);
        $stmt->bindValue(':days', $days, \PDO::PARAM_INT);
        $stmt->bindValue(':lim', $limit, \PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll(\PDO::FETCH_COLUMN);
    }

    /** @return list<array<string, mixed>> */
    public function getStuckTransactions(int $hours = 1): array
    {
        $sql = "SELECT * FROM `" . static::$table . "`
                WHERE status = 'processing'
                  AND created_at < DATE_SUB(NOW(), INTERVAL ? HOUR)";

        $stmt = $this->db->prepare($sql);
        $stmt->execute([$hours]);
        return $stmt->fetchAll(\PDO::FETCH_OBJ);
    }

    public function getCompletedVolumeSince(int $days = 7): float
    {
        $sql = "SELECT COALESCE(SUM(amount), 0) as total FROM `" . static::$table . "`
                WHERE created_at >= DATE_SUB(NOW(), INTERVAL ? DAY)
                  AND status = 'completed'";

        $stmt = $this->db->prepare($sql);
        $stmt->execute([$days]);
        $row = $this->fetchObject($stmt);
        return float_value($row?->total ?? 0);
    }

    public function getDailyReport(string $currency, string $date): object
    {
        $sql = "SELECT
                COUNT(*) AS total_count,
                SUM(CASE WHEN type='deposit'  AND status='completed' THEN amount ELSE 0 END) AS total_deposits,
                SUM(CASE WHEN type='withdraw' AND status='completed' THEN amount ELSE 0 END) AS total_withdrawals,
                COUNT(CASE WHEN type='deposit'  THEN 1 END) AS deposit_count,
                COUNT(CASE WHEN type='withdraw' THEN 1 END) AS withdrawal_count
             FROM `" . static::$table . "`
             WHERE currency = :currency AND DATE(created_at) = :date";

        $stmt = $this->db->prepare($sql);
        $stmt->execute(['currency' => $currency, 'date' => $date]);
        $result = $this->fetchObject($stmt);

        return $result ?? (object)[
            'total_count' => 0,
            'total_deposits' => 0,
            'total_withdrawals' => 0,
            'deposit_count' => 0,
            'withdrawal_count' => 0,
        ];
    }

    // ==================== ANALYTICS METHODS ====================

    /**
     * @deprecated CQRS: Use TransactionQuery::getFinancialStats instead
     */
    /** @return array<string, mixed> */
    public function getFinancialStats(string $currency = 'irt'): array
    {
        throw new \RuntimeException("CQRS Violation: Do not use Transaction model for heavy reporting. Use TransactionQuery.");
    }

    /**
     * Native modern searching backed by central Filterable Trait.
     * @param array<string, mixed> $filters
     * @return array{total: int, items: list<\stdClass>}
     */
    public function searchNative(string $q, array $filters, int $limit, int $offset, string $sortDir = 'DESC'): array
    {
        $query = $this->db->table('transactions as t')
            ->select('t.*', 'u.full_name as user_name', 'u.email as user_email')
            ->leftJoin('users as u', 'u.id', '=', 't.user_id');

        if (!empty($q)) {
            $query = $this->applySearch($query, $q);
        }

        $query = $this->applyFilters($query, $filters);

        $dir = strtoupper((string)$sortDir) === 'ASC' ? 'ASC' : 'DESC';

        return [
            'total' => $query->count(),
            'items' => (clone $query)->orderBy("t.created_at", $dir)->limit($limit)->offset($offset)->get()
        ];
    }
}
