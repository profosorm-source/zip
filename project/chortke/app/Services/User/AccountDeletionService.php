<?php
declare(strict_types=1);

namespace App\Services\User;

use App\Models\User;
use App\Models\AccountDeletionLog;
use Core\Database;
use App\Contracts\LoggerInterface;
use App\Services\CustomTask\AdminCustomTaskService;
use Core\EventDispatcher;
use App\Services\DistributedLockService;

use App\Models\Wallet;

/**
 * AccountDeletionService — حذف حساب کاربران
 */
class AccountDeletionService
{
    /**
     * Centralized toObject (root-cause normalization for DB results).
     * Guarantees object (never array/mixed) before any ->prop access.
     */
    private function toObject(mixed $data): ?\stdClass
    {
        if ($data === null || $data === false) return null;
        if (is_object($data)) {
            /** @var \stdClass $data */
            return $data;
        }
        if (is_array($data)) return (object)$data;
        return (object)(array)$data;
    }


    /** L-27 Fix: actor سیستمی برای حذف‌های خودکار (به‌جای deleted_by = NULL) جهت پاسخگویی حسابرسی */
    private const SYSTEM_ACTOR_ID = 0;

    private User $userModel;
    private AccountDeletionLog $deletionLogModel;

    private AdminCustomTaskService $customTaskService;

    private DistributedLockService $lockService;

    private \Core\Database $db;
    private \App\Contracts\LoggerInterface $logger;
    private \App\Services\SagaOrchestrator $sagaOrchestrator;

    public function __construct(
        \Core\Database $db,
        \App\Contracts\LoggerInterface $logger,
        User $userModel,
        AccountDeletionLog $deletionLogModel,
        AdminCustomTaskService $customTaskService,
        DistributedLockService $lockService,
        \App\Services\SagaOrchestrator $sagaOrchestrator
    ) {
        $this->db = $db;
        $this->logger = $logger;

        
        $this->userModel = $userModel;
        $this->deletionLogModel = $deletionLogModel;
        $this->customTaskService = $customTaskService;
        $this->lockService = $lockService;
        $this->sagaOrchestrator = $sagaOrchestrator;
    }

    /**
     * حذف خودکار درخواست‌های منقضی
     * این متد باید توسط Cron Job هر روز اجرا شود
     */
    public function processExpiredDeletionRequests(): int
    {
        try {
            // H23 Fix: ارتقای سیستم قفل به سرویس توزیع‌شده جهت تضمین ایمنی کلاستر و جلوگیری از Deadlock
            return $this->lockService->synchronized('cron_account_deletion_lock', function() {
                $expiredRequests = $this->deletionLogModel->getExpiredDeletionRequests();
                $deletedCount = 0;

                if (empty($expiredRequests)) {
                    return 0;
                }

                // ✅ N+1 FIX: یک query برای همه wallet‌ها به جای N query
                $userIds = array_map(static function (mixed $row): int {
                    $data = is_object($row) ? get_object_vars($row) : (is_array($row) ? $row : []);
                    return is_numeric($data['user_id'] ?? null) ? (int)$data['user_id'] : 0;
                }, $expiredRequests);
                $placeholders = implode(',', array_fill(0, count($userIds), '?'));
                $walletRows = $this->db->fetchAll(
                    "SELECT user_id, balance_irt, balance_usdt FROM wallets WHERE user_id IN ({$placeholders})",
                    $userIds
                );
                /** @var array<int, \stdClass> $walletsMap */
                $walletsMap = [];
                if (is_array($walletRows)) {
                    foreach ($walletRows as $w) {
                        $walletsMap[(int)$w->user_id] = $w;
                    }
                }
                // ───────────────────────────────────────────────────────

                foreach ($expiredRequests as $request) {
                    $requestData = is_object($request) ? get_object_vars($request) : (is_array($request) ? $request : []);
                    $userId = is_numeric($requestData['user_id'] ?? null) ? (int)$requestData['user_id'] : 0;

                    // Pre-check wallet balance از map — بدون query اضافه
                    $wallet = $walletsMap[$userId] ?? null;
                    if ($wallet && ((float)$wallet->balance_irt > 0 || (float)$wallet->balance_usdt > 0)) {
                        // Cancel the deletion request to preserve customer funds
                        $this->deletionLogModel->cancelDeletionRequest($userId);
                        $this->logger->warning('account_deletion.cancelled_due_to_positive_balance', [
                            'user_id'     => $userId,
                            'balance_irt'  => $wallet->balance_irt,
                            'balance_usdt' => $wallet->balance_usdt
                        ]);
                        continue;
                    }

                    // L-27 Fix: مهلت پیام از config و ثبت actor سیستمی (به‌جای NULL)
                    $graceDays = max(1, int_value(config('account.deletion_grace_days', 7)));
                    if ($this->deleteUserAccount($userId, "Automated deletion after {$graceDays}-day period", self::SYSTEM_ACTOR_ID)) {
                        $deletedCount++;
                    }
                }

                $this->logger->info('account_deletion.automated_completed', ['count' => $deletedCount]);
                return $deletedCount;
            }, ttl: 60, waitTimeout: 0);
        } catch (\RuntimeException $e) {
            $this->logger->warning('account_deletion.cron_skipped_mutex_busy', ['reason' => $e->getMessage()]);
            return 0;
        } catch (\Exception $e) {
            $this->logger->error('account_deletion.automated_failed', ['error' => $e->getMessage()]);
            return 0;
        }
    }

    /**
     * حذف کامل حساب کاربر با امنیت ساگا
     */
    public function deleteUserAccount(int $userId, ?string $reason = null, ?int $deletedBy = null): bool
    {
        $saga = $this->sagaOrchestrator;

        try {
            $user = $this->userModel->findById($userId);
            if (!$user) return false;

            // BUGFIX-SAGA-TX-ROOT: مشابه FinancialEscrowService — بدون Transaction Root
            // ممکن است بین حذف داده‌های کاربر (purge_user_data) و غیرفعال‌سازی حساب
            // (deactivate_user) خطا رخ دهد و حساب در وضعیت ناقص (داده‌ها حذف‌شده ولی
            // هنوز فعال) باقی بماند. کل Saga داخل یک تراکنش اتمیک اجرا می‌شود.
            $this->db->transactional(function () use ($saga, $userId, $reason, $deletedBy) {
            return $saga->setSaga('account_deletion', ['user_id' => $userId, 'reason' => $reason, 'deleted_by' => $deletedBy])
                ->addStep(
                    'cleanup_tasks_and_verify_balance',
                    function($ctx) {
                        $this->customTaskService->cancelActiveTasksForUser($ctx['user_id']);
                        $wallet = $this->toObject($this->db->selectOne("SELECT balance_irt, balance_usdt FROM wallets WHERE user_id = ? FOR UPDATE", [$ctx['user_id']]));
                        if ($wallet && ((float)$wallet->balance_irt > 0 || (float)$wallet->balance_usdt > 0)) {
                            throw new \Core\Exceptions\InvalidStateException('امکان حذف حساب با موجودی مثبت وجود ندارد');
                        }
                        return $ctx;
                    }
                )
                ->addStep(
                    'purge_user_data',
                    function($ctx) {
                        $uid = (int)$ctx['user_id'];
                        // Finding #2 Fix: Comprehensive purge of all personal data, tokens & credentials
                        $this->db->query("DELETE FROM user_sessions WHERE user_id = ?", [$uid]);
                        $this->db->query("DELETE FROM notifications WHERE user_id = ?", [$uid]);
                        $this->db->query("DELETE FROM bank_cards WHERE user_id = ?", [$uid]);
                        $this->db->query("DELETE FROM api_tokens WHERE user_id = ?", [$uid]);
                        $this->db->query("DELETE FROM user_devices WHERE user_id = ?", [$uid]);
                        $this->db->query("DELETE FROM trusted_devices WHERE user_id = ?", [$uid]);
                        $this->db->query("DELETE FROM user_oauth WHERE user_id = ?", [$uid]);
                        $this->db->query("DELETE FROM user_social_accounts WHERE user_id = ?", [$uid]);
                        $this->db->query("DELETE FROM social_accounts WHERE user_id = ?", [$uid]);
                        $this->db->query("DELETE FROM two_factor_codes WHERE user_id = ?", [$uid]);
                        $this->db->query("DELETE FROM password_resets WHERE user_id = ?", [$uid]);
                        $this->db->query("DELETE FROM data_exports WHERE user_id = ?", [$uid]);
                        $this->db->query("DELETE FROM search_projections WHERE user_id = ?", [$uid]);
                        $this->db->query("DELETE FROM user_typing_patterns WHERE user_id = ?", [$uid]);
                        $this->db->query("DELETE FROM user_fingerprints WHERE user_id = ?", [$uid]);
                        $this->db->query("DELETE FROM kyc_documents WHERE user_id = ?", [$uid]);
                        $this->db->query("DELETE FROM kyc_verifications WHERE user_id = ?", [$uid]);
                        return $ctx;
                    }
                )
                ->addStep(
                    'deactivate_user',
                    function($ctx) {
                        $uid = (int)$ctx['user_id'];
                        $suffix = bin2hex(random_bytes(4));
                        $anonMobile = '09' . str_pad((string)$uid, 9, '0', STR_PAD_LEFT);
                        // Finding #3 Fix: Complete PII anonymization in users table
                        $this->db->query(
                            "UPDATE users SET status = 'deleted', deleted_at = NOW(), 
                             email = CONCAT('deleted_', id, '_', ?, '@anon.chortke.ir'),
                             username = CONCAT('deleted_', id, '_', ?),
                             mobile = ?,
                             first_name = 'کاربر',
                             last_name = 'حذف‌شده',
                             national_id = NULL,
                             password = 'DELETED_ACCOUNT',
                             remember_token = NULL,
                             two_factor_secret = NULL,
                             two_factor_recovery_codes = NULL,
                             avatar = NULL,
                             bio = NULL,
                             ip_address = '0.0.0.0',
                             last_ip = '0.0.0.0',
                             account_deletion_requested_at = NULL,
                             account_deletion_expires_at = NULL
                             WHERE id = ?", 
                            [$suffix, $suffix, $anonMobile, $uid]
                        );
                        $this->deletionLogModel->recordDeletion($uid, $ctx['deleted_by'], $ctx['reason']);
                        return $ctx;
                    }
                )
                ->execute();
            });

            $this->logger->info('account_deletion.success', ['user_id' => $userId]);
            return true;

        } catch (\Throwable $e) {
            $this->logger->error('account_deletion.failed', ['user_id' => $userId, 'error' => $e->getMessage()]);
            return false;
        }
    }

    /**
     * ثبت درخواست حذف حساب برای API/User settings بدون اجرای حذف فوری.
     */
    /**
     * @return array<string, mixed>
     */
    public function requestDeletion(int $userId, string $reason = ''): array
    {
        if ($userId <= 0) {
            return ['success' => false, 'message' => 'کاربر نامعتبر است.'];
        }

        try {
            $user = $this->userModel->findById($userId);
            if (!$user) {
                return ['success' => false, 'message' => 'کاربر یافت نشد.'];
            }

            // L-27 Fix: ردیف معتبر account_deletion_logs باید ساخته شود؛ در غیر این صورت
            // کرون (که فقط جدول لاگ را می‌خواند) هرگز این درخواست را اجرا نمی‌کند.
            // همچنین مهلت از یک منبع واحد config می‌آید (پیش‌تر users=30روز و log=7روز ناسازگار بودند).
            $graceDays = max(1, int_value(config('account.deletion_grace_days', 7)));

            try {
                $this->deletionLogModel->createDeletionRequest($userId, $reason);
            } catch (\RuntimeException $e) {
                return ['success' => false, 'message' => 'درخواست حذف حساب قبلاً ثبت شده است.'];
            }

            // هم‌ترازسازی ستون‌های users با همان مهلت برای نمایش وضعیت به کاربر
            $this->db->query(
                "UPDATE users SET account_deletion_requested_at = NOW(), account_deletion_expires_at = DATE_ADD(NOW(), INTERVAL {$graceDays} DAY) WHERE id = ?",
                [$userId]
            );

            $this->logger->warning('account_deletion.requested', [
                'user_id' => $userId,
                'reason' => $reason,
                'grace_days' => $graceDays,
            ]);

            return ['success' => true, 'message' => 'درخواست حذف حساب ثبت شد.'];
        } catch (\Throwable $e) {
            $this->logger->error('account_deletion.request_failed', ['user_id' => $userId, 'error' => $e->getMessage()]);
            return ['success' => false, 'message' => 'خطا در ثبت درخواست حذف اکانت'];
        }
    }

    /**
     * بررسی اینکه حساب در انتظار حذف است یا نه
     */
    public function isPendingDeletion(int $userId): bool
    {
        $request = $this->deletionLogModel->getUserDeletionRequest($userId);
        return $request !== null && $request->status === 'requested';
    }

    /**
     * دریافت اطلاعات درخواست حذف
     */
    public function getDeletionRequest(int $userId): ?\stdClass
    {
        return $this->deletionLogModel->getUserDeletionRequest($userId);
    }

    /**
     * لغو درخواست حذف
     */
    public function cancelDeletion(int $userId): bool
    {
        try {
            // Finding #4 Fix: Cancel deletion request in both account_deletion_logs and users table
            $this->deletionLogModel->cancelDeletionRequest($userId);
            $this->db->query(
                "UPDATE users SET account_deletion_requested_at = NULL, account_deletion_expires_at = NULL WHERE id = ?",
                [$userId]
            );
            $this->logger->info('account_deletion.cancelled', ['user_id' => $userId]);
            return true;
        } catch (\Exception $e) {
            $this->logger->error('account_deletion.cancel_failed', [
                'user_id' => $userId,
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }

    /**
     * دریافت تاریخچه حذف‌ها برای ادمین
     */
    /**
     * @return list<\stdClass>
     */
    /** @return array<int, object> */
    public function getDeletionHistory(int $limit = 50, int $offset = 0): array
    {
        // LOW-02: Bound pagination inputs to defend against excessive memory usage
        $safeLimit = max(1, min(250, $limit));
        $safeOffset = max(0, $offset);

        return $this->deletionLogModel->getDeletionHistory($safeLimit, $safeOffset);
    }
}
