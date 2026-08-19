<?php

declare(strict_types=1);

namespace App\Jobs;

use Core\Database;
use App\Contracts\LoggerInterface;

/**
 * KycTimeoutJob
 * 
 * رد خودکار درخواست‌های KYC که بیش از X روز در وضعیت 'pending' یا 'under_review'
 * مانده‌اند و ادمین به آن‌ها رسیدگی نکرده است.
 * 
 * در Cron: هر ساعت اجرا می‌شود
 * 
 * 🛡️ Bug Fix: قبلاً هیچ cron ای برای timeout KYC وجود نداشت
 * و درخواست‌های KYC تا ابد pending می‌ماندند.
 */
class KycTimeoutJob
{
    #[ \Core\Attributes\Inject ]
    private Database $db;

    #[ \Core\Attributes\Inject ]
    private LoggerInterface $logger;

    #[ \Core\Attributes\Inject ]
    private ?\App\Contracts\OutboxServiceInterface $outbox = null;

    #[ \Core\Attributes\Inject ]
    private ?\App\Services\Notification\NotificationService $notificationService = null;

    private const EXPIRY_DAYS = 7;
    private const MAX_PROCESS = 50;
    private const REVIEW_HOURS = 72;

    public function __construct(
        Database $db,
        LoggerInterface $logger,
        ?\App\Contracts\OutboxServiceInterface $outbox = null,
        ?\App\Services\Notification\NotificationService $notificationService = null
    ) {
        $this->db = $db;
        $this->logger = $logger;
        if ($outbox !== null) {
            $this->outbox = $outbox;
        }
        if ($notificationService !== null) {
            $this->notificationService = $notificationService;
        }
    }

/**
 * @param array<string, mixed> $data
 * @return array<string, mixed>
 */
public function handle(array $data = []): array
    {
        $expiryDays = int_value($data['expiry_days'] ?? self::EXPIRY_DAYS);
        $reviewHours = int_value($data['review_hours'] ?? self::REVIEW_HOURS);
        $limit = int_value($data['limit'] ?? self::MAX_PROCESS);

        $autoRejected  = 0;
        $scanned       = 0;
        $pendingCursor = 0;
        $reviewCursor  = 0;
        $guard         = 0;
        try {
            // cursor: تا خالی‌شدنِ کاملِ صفِ KYCهای منقضی ادامه می‌دهیم تا backlog نماند.
            // هر batch همچنان محدود به $limit است (سقفِ حافظه)؛ cursorِ id تضمین می‌کند هیچ ردیفی
            // دوباره پردازش نشود. FOR UPDATE از SELECTِ کشف حذف شد چون تحتِ autocommit اثری نداشت؛
            // گاردِ واقعیِ همزمانی همان UPDATE شرطیِ «AND status = ?» در هر ردیف است.
            do {
                if (++$guard > 100000) {
                    $this->logger->warning('kyc.timeout.guard_tripped', ['pending_cursor' => $pendingCursor, 'review_cursor' => $reviewCursor]);
                    break;
                }

                // 1. KYCهایی که بیش از X روز در وضعیت pending مانده‌اند (کاربر هنوز مدارک کامل نفرستاده)
                $expiredPending = $this->db->query("
                    SELECT id, user_id, status, created_at
                    FROM kyc_verifications
                    WHERE status = 'pending'
                      AND created_at < DATE_SUB(NOW(), INTERVAL ? DAY)
                      AND id > ?
                    ORDER BY id ASC
                    LIMIT ?
                ", [$expiryDays, $pendingCursor, $limit])->fetchAll(\PDO::FETCH_OBJ);

                // 2. KYCهایی که بیش از X ساعت در وضعیت under_review مانده‌اند (ادمین بررسی نکرده)
                $expiredReview = $this->db->query("
                    SELECT id, user_id, status, created_at
                    FROM kyc_verifications
                    WHERE status = 'under_review'
                      AND created_at < DATE_SUB(NOW(), INTERVAL ? HOUR)
                      AND id > ?
                    ORDER BY id ASC
                    LIMIT ?
                ", [$reviewHours, $reviewCursor, $limit])->fetchAll(\PDO::FETCH_OBJ);

                $pendingCount = count($expiredPending);
                $reviewCount  = count($expiredReview);
                foreach ($expiredPending as $__r) { if ((int)$__r->id > $pendingCursor) $pendingCursor = (int)$__r->id; }
                foreach ($expiredReview as $__r) { if ((int)$__r->id > $reviewCursor) $reviewCursor = (int)$__r->id; }

                $allExpired = array_merge($expiredPending, $expiredReview);
                $scanned += count($allExpired);

            foreach ($allExpired as $kyc) {
                try {
                    $this->db->beginTransaction();

                    // به‌روزرسانی وضعیت KYC به rejected (منقضی شده)
                    $this->db->query(
                        "UPDATE kyc_verifications SET 
                         status = 'rejected', 
                         rejection_reason = 'auto_rejected_timeout',
                         reviewed_at = NOW(),
                         updated_at = NOW()
                         WHERE id = ? AND status = ?",
                        [(int)$kyc->id, $kyc->status]
                    );

                    // dispatch ایونت برای اطلاع‌رسانی
                    $this->outbox?->record('kyc', (int)$kyc->id, 'kyc.status_changed', [
                        'kyc_id'     => (int)$kyc->id,
                        'user_id'    => (int)$kyc->user_id,
                        'old_status' => $kyc->status,
                        'new_status' => 'rejected',
                        'reason'     => 'timeout',
                    ]);

                    $this->db->commit();
                    $this->notificationService?->kycRejected((int)$kyc->user_id, 'درخواست احراز هویت به دلیل عدم رسیدگی در بازه مجاز منقضی شد.');
                    $autoRejected++;

                } catch (\Throwable $e) {
                    $this->db->rollback();
                    $this->logger->error('kyc.timeout_reject_failed', [
                        'kyc_id' => $kyc->id,
                        'error' => $e->getMessage(),
                    ]);
                }
            }

            } while ($pendingCount === $limit || $reviewCount === $limit);

            if ($autoRejected > 0) {
                $this->logger->info('kyc.auto_rejected_timeout', [
                    'total_expired' => $scanned,
                    'auto_rejected' => $autoRejected,
                ]);
            }

            return [
                'scanned'       => $scanned,
                'auto_rejected' => $autoRejected,
                'message'       => "{$autoRejected} KYC به صورت خودکار رد شد",
            ];

        } catch (\Throwable $e) {
            $this->logger->error('kyc.timeout_scan_failed', ['error' => $e->getMessage()]);
            return ['scanned' => 0, 'auto_rejected' => 0, 'error' => $e->getMessage()];
        }
    }
}
