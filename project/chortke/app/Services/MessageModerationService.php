<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\InteractionModel;
use App\Models\MessageModerationModel;
use Core\Database;
use App\Contracts\LoggerInterface;
use App\Contracts\NotificationServiceInterface;
class MessageModerationService
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


    private InteractionModel $interactionModel;
    private MessageModerationModel $moderationModel;
    private ?\App\Services\Settings\AppSettings $appSettings;
    private ?\App\Services\User\UserService $userService;

    private \Core\EventDispatcher $eventDispatcher;
    private \Core\Cache $cache;
    private \Core\Database $db;
    private \App\Contracts\LoggerInterface $logger;
    private ?\App\Contracts\OutboxServiceInterface $outbox = null;
    private \App\Services\AuditTrail $auditTrail;
    public function __construct(
        \Core\EventDispatcher $eventDispatcher,
        \Core\Cache $cache,
        \Core\Database $db,
        \App\Contracts\LoggerInterface $logger,
        InteractionModel $interactionModel,
        MessageModerationModel $moderationModel,
        \App\Services\AuditTrail $auditTrail,
        ?\App\Services\Settings\AppSettings $appSettings = null,
        ?\App\Services\User\UserService $userService = null,
        ?\App\Contracts\OutboxServiceInterface $outbox = null
    ) {        $this->eventDispatcher = $eventDispatcher;
        $this->cache = $cache;
        $this->db = $db;
        $this->logger = $logger;
        $this->outbox = $outbox;
        $this->interactionModel = $interactionModel;
        $this->moderationModel = $moderationModel;
        $this->appSettings = $appSettings;
        $this->eventDispatcher = $eventDispatcher;
        $this->userService = $userService;
        $this->auditTrail = $auditTrail;
    }

    /** @return array{reports: list<\stdClass>, total: int} */
    public function getReports(string $status, int $limit, int $offset): array
    {
        return [
            'reports' => $this->interactionModel->findMessageReportsPaginated($limit, $offset, $status),
            'total' => $this->interactionModel->countMessageReports($status),
        ];
    }

    /** @return array<string, mixed>|null */
    public function getReportDetail(int $id): ?array
    {
        $res = $this->interactionModel->findMessageReportById($id);
        return is_array($res) ? $res : null;
    }

    /** @return list<\stdClass> */
    public function getUserMessages(int $senderId, int $limit = 10): array
    {
        return $this->moderationModel->getUserMessages($senderId, $limit);
    }

    /** @return array<string, mixed> */
    public function approveReport(int $reportId, string $action, int $adminId): array
    {
        try {
            $this->db->beginTransaction();

            // 🛡️ CRIT-06: ترتیب قفل‌گذاری ثابت جهت جلوگیری از Deadlock
            // ابتدا دریافت مشخصات پایه‌ای گزارش بدون قفل برای پیدا کردن فرستنده
            $baseReport = $this->toObject($this->db->query(
                "SELECT dm.sender_id FROM message_reports mr JOIN direct_messages dm ON mr.message_id = dm.id WHERE mr.id = ?",
                [$reportId]
            )->fetch(\PDO::FETCH_ASSOC));

            if ($baseReport && in_array($action, ['warn', 'ban'], true)) {
                $this->db->query("SELECT id FROM users WHERE id = ? FOR UPDATE", [(int)$baseReport->sender_id]);
            }

            // H13 Fix: قفل بدبینانه روی سطر گزارش
            $report = $this->toObject($this->db->query(
                "SELECT mr.status as report_status, dm.sender_id, dm.id as message_id 
                 FROM message_reports mr
                 JOIN direct_messages dm ON mr.message_id = dm.id
                 WHERE mr.id = ? FOR UPDATE",
                [$reportId]
            )->fetch(\PDO::FETCH_ASSOC));

            if (!$report) {
                $this->db->rollback();
                return ['success' => false, 'message' => 'گزارش یافت نشد'];
            }

            if ($report->report_status !== 'pending') {
                $this->db->rollback();
                return ['success' => false, 'message' => 'این گزارش قبلاً تعیین تکلیف و نهایی شده است'];
            }

            switch ($action) {
                case 'warn':
                    $this->warnUser((int)$report->sender_id, $adminId, $reportId);
                    break;
                case 'delete':
                    $this->deleteMessage((int)$report->message_id, $adminId, $reportId);
                    break;
                case 'ban':
                    $this->banUser((int)$report->sender_id, $adminId, $reportId);
                    break;
                default:
                    $this->db->rollback();
                    return ['success' => false, 'message' => 'اقدام نامعتبر است'];
            }

            // 🛡️ FAIL-03: بررسی موفقیت آپدیت وضعیت
            $ok = $this->moderationModel->updateReportStatus($reportId, 'resolved', $adminId);
            if (!$ok) {
                throw new \Core\Exceptions\ApplicationException('خطا در بروزرسانی وضعیت گزارش');
            }

            $this->db->commit();
            $this->eventDispatcher->dispatch('cache.invalidate', ['key' => 'message_moderation_stats_v2']);
            return ['success' => true, 'message' => 'گزارش تایید شد'];
        } catch (\Throwable $e) {
            $this->db->rollback();
            $this->logger->error('message_moderation.approve_failed', [
                'report_id' => $reportId,
                'action' => $action,
                'error' => $e->getMessage(),
            ]);
            return ['success' => false, 'message' => 'خطا در پردازش'];
        }
    }

    public function dismissReport(int $reportId, int $adminId): bool
    {
        try {
            $this->db->beginTransaction();
            $status = $this->db->query("SELECT status FROM message_reports WHERE id = ? FOR UPDATE", [$reportId])->fetchColumn();
            if ($status !== 'pending') {
                $this->db->rollback();
                return false;
            }
            $ok = $this->moderationModel->updateReportStatus($reportId, 'dismissed', $adminId);
            $this->db->commit();
            $this->eventDispatcher->dispatch('cache.invalidate', ['key' => 'message_moderation_stats_v2']);
            return $ok;
        } catch (\Throwable) {
            $this->db->rollback();
            return false;
        }
    }

    /** @return array<int, array<string, mixed>> */
    public function getBlockedUsers(int $limit, int $offset): array
    {
        $blocked = $this->moderationModel->getBlockedUsers($limit, $offset);
        
        // 🛡️ MEDIUM-08: Redaction of sensitive PII in the service layer instead of view/controller
        foreach ($blocked as &$user) {
            if (!empty($user['blocked_email'])) {
                $email = str_value($user['blocked_email']);
                $parts = explode('@', $email);
                if (count($parts) === 2) {
                    $user['blocked_email'] = substr($parts[0], 0, 2) . '***@' . $parts[1];
                }
            }
            if (!empty($user['mobile'])) {
                $mobile = str_value($user['mobile']);
                $user['mobile'] = substr($mobile, 0, 3) . '***' . substr($mobile, -2);
            }
            if (!empty($user['national_id'])) {
                $user['national_id'] = '***' . substr(str_value($user['national_id']), -3);
            }
            if (!empty($user['ip_address'])) {
                $user['ip_address'] = preg_replace('/\d+$/', '***', str_value($user['ip_address']));
            }
        }
        
        return $blocked;
    }

    public function getBlockedUsersCount(): int
    {
        $countRow = $this->db->query("SELECT COUNT(*) as cnt FROM user_blocks")->fetch(\PDO::FETCH_OBJ);
        $count = $countRow instanceof \stdClass ? $countRow : null;
        return int_value($count->cnt ?? 0);
    }

    /** @return array<string, mixed> */
    public function getStats(): array
    {
        $cacheKey = 'message_moderation_stats_v2';
        
        // M16 Fix: کش کردن موقت آمار سنگین به مدت ۵ دقیقه جهت ممانعت از قفل شدن سرور دیتابیس
        $cached = $this->cache->get($cacheKey);
        if ($cached !== null && is_array($cached)) {
            return $cached;
        }

        $today = date('Y-m-d 00:00:00');

        // Combine all COUNT queries into single query to avoid N+1 problem
        $statsResult = $this->db->query(
            "SELECT
                (SELECT COUNT(*) FROM direct_messages) as total_messages,
                (SELECT COUNT(*) FROM message_reports) as total_reports,
                (SELECT COUNT(*) FROM message_reports WHERE status = 'pending') as pending_reports,
                (SELECT COUNT(*) FROM user_blocks) as total_blocks,
                (SELECT COUNT(*) FROM direct_messages WHERE created_at >= ?) as today_messages,
                (SELECT COUNT(*) FROM message_reports WHERE created_at >= ?) as today_reports",
            [$today, $today]
        )->fetch(\PDO::FETCH_ASSOC);

        $statsRow = is_array($statsResult) ? $statsResult : [];
        $stats = [];
        foreach (['total_messages', 'total_reports', 'pending_reports', 'total_blocks', 'today_messages', 'today_reports'] as $key) {
            $value = $statsRow[$key] ?? 0;
            $stats[$key] = is_int($value) || (is_string($value) && is_numeric($value))
                ? (int)$value
                : 0;
        }

        $topReporters = $this->db->query(
            "SELECT u.full_name, u.id, COUNT(*) as count
             FROM message_reports mr
             JOIN users u ON mr.reporter_id = u.id
             GROUP BY mr.reporter_id
             ORDER BY count DESC
             LIMIT 5"
        )->fetchAll(\PDO::FETCH_ASSOC) ?: [];

        $result = [
            'stats' => $stats,
            'top_reporters' => $topReporters,
        ];

        // ذخیره سازی کش به مدت ۵ دقیقه (۳۰۰ ثانیه = ۵ دقیقه در درایور رِگولار)
        $this->cache->put($cacheKey, $result, 5);

        return $result;
    }

    private function deleteMessage(int $messageId, int $adminId, int $reportId): void
    {
        $this->db->query(
            "UPDATE direct_messages
             SET message = '[پیام حذف‌شده توسط مدیریت]', deleted_at = NOW(), deleted_by = ?
             WHERE id = ?",
            [(string)$adminId, $messageId]
        );
        
        $this->logger->info('message_deleted_by_admin', [
            'message_id' => $messageId,
            'admin_id' => $adminId,
            'report_id' => $reportId
        ]);
        
        $this->eventDispatcher->dispatch('cache.invalidate', ['key' => 'message_moderation_stats_v2']);
    }

    private function warnUser(int $userId, int $adminId, int $reportId): void
    {
        if ($this->userService) {
            $this->userService->incrementWarningCount($userId);
            $newWarningCount = $this->userService->getWarningCount($userId);
        } else {
            $this->db->query(
                "UPDATE users SET warning_count = warning_count + 1 WHERE id = ?",
                [$userId]
            );
            $countResult = $this->toObject($this->db->query("SELECT warning_count FROM users WHERE id = ?", [$userId])->fetch(\PDO::FETCH_ASSOC));
            $newWarningCount = $countResult ? (int)$countResult->warning_count : 1;
        }

        $this->logger->info('user_warned', [
            'user_id' => $userId,
            'admin_id' => $adminId,
            'report_id' => $reportId,
            'warning_count' => $newWarningCount
        ]);

        // 🛡️ Audit Fix: استفاده از AuditTrail یکپارچه (Event-Driven) به جای INSERT مستقیم
        try {
            $auditTrail = $this->auditTrail;
            $auditTrail->record('admin.user_warned', $userId, [
                'admin_id' => $adminId,
                'report_id' => $reportId,
                'warning_count' => $newWarningCount,
                'reason' => 'Inappropriate messaging',
                'ip_address' => get_client_ip(),
                'user_agent' => get_user_agent(),
            ], $adminId);
        } catch (\Throwable $e) {
            $this->logger->error('audit_log_failed', ['action' => 'user_warned', 'error' => $e->getMessage()]);
        }

        // 🛡️ CRITICAL-16: Alert the user via an in-app notification when a warning is issued
        $this->outbox?->record('notification', $userId, 'notification.requested', [
            'user_id' => $userId,
            'type' => \App\Models\Notification::TYPE_SECURITY,
            'title' => 'اخطار مدیریت پیام‌ها',
            'message' => 'کاربر گرامی، شما یک اخطار به دلیل گزارش‌های دریافتی از پیام‌هایتان دریافت کرده‌اید (' . $newWarningCount . '/3). لطفاً قوانین سایت را رعایت کنید.',
            'data' => [],
            'priority' => \App\Models\Notification::PRIORITY_HIGH
        ]);

        if ($newWarningCount >= 3) {
            $this->banUser($userId, $adminId, $reportId);
        }

        $this->eventDispatcher->dispatch('cache.invalidate', ['key' => 'message_moderation_stats_v2']);
    }

    private function banUser(int $userId, int $adminId, int $reportId): void
    {
        $this->db->query(
            "UPDATE users
             SET status = 'banned', banned_reason = 'Inappropriate messaging'
             WHERE id = ?",
            [$userId]
        );

        $this->logger->info('user_banned', [
            'user_id' => $userId,
            'admin_id' => $adminId,
            'report_id' => $reportId,
            'reason' => 'Inappropriate messaging'
        ]);

        // 🛡️ Audit Fix: استفاده از AuditTrail یکپارچه (Event-Driven) به جای INSERT مستقیم
        try {
            $auditTrail = $this->auditTrail;
            $auditTrail->record('admin.user_banned', $userId, [
                'admin_id' => $adminId,
                'report_id' => $reportId,
                'reason' => 'Inappropriate messaging',
                'ip_address' => get_client_ip(),
                'user_agent' => get_user_agent(),
            ], $adminId);
        } catch (\Throwable $e) {
            $this->logger->error('audit_log_failed', ['action' => 'user_banned', 'error' => $e->getMessage()]);
        }

        try {
            $this->db->query(
                "INSERT INTO admin_audit_log (admin_id, action, entity_type, entity_id, old_values, new_values, ip_address, user_agent, session_id)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)",
                [
                    $adminId,
                    'user_banned',
                    'user',
                    $userId,
                    json_encode(['status' => 'active']),
                    json_encode(['status' => 'banned', 'reason' => 'Inappropriate messaging', 'report_id' => $reportId]),
                    get_client_ip(),
                    get_user_agent(),
                    session_id() ?: ''
                ]
            );
        } catch (\Throwable $e) {
            $this->logger->error('audit_log_failed', ['action' => 'user_banned', 'error' => $e->getMessage()]);
        }

        // 🛡️ CRITICAL-16: Alert the user via an in-app notification when they are banned
        $this->outbox?->record('notification', $userId, 'notification.requested', [
            'user_id' => $userId,
            'type' => \App\Models\Notification::TYPE_SECURITY,
            'title' => 'مسدودسازی حساب کاربری',
            'message' => 'حساب کاربری شما به دلیل نقض مکرر قوانین در سیستم پیام‌رسانی مسدود شد.',
            'data' => [],
            'priority' => \App\Models\Notification::PRIORITY_URGENT
        ]);

        $this->eventDispatcher->dispatch('cache.invalidate', ['key' => 'message_moderation_stats_v2']);
    }

    /**
     * صفحہ بندی کے ساتھ رپورٹس حاصل کریں
     */
    /** @return list<\stdClass> */
    public function getReportsPaginated(string $status = 'all', int $limit = 20, int $offset = 0): array
    {
        if ($status === 'all') {
            return $this->interactionModel->findMessageReportsPaginated($limit, $offset, null);
        }
        return $this->interactionModel->findMessageReportsPaginated($limit, $offset, $status);
    }

    /**
     * کل رپورٹس گنتی کریں
     */
    public function countReports(string $status = 'all'): int
    {
        if ($status === 'all') {
            return $this->interactionModel->countMessageReports(null);
        }
        return $this->interactionModel->countMessageReports($status);
    }

    /**
     * کاربر کو مسدود کریں
     */
    /** @return array<string, mixed> */
    public function blockUser(int $userId, string $reason, int $adminId): array
    {
        try {
            $this->db->beginTransaction();

            // Check if user exists
            $user = $this->toObject($this->db->query("SELECT id FROM users WHERE id = ?", [$userId])->fetch(\PDO::FETCH_OBJ));
            if (!$user) {
                $this->db->rollback();
                return ['success' => false, 'message' => 'کاربر یافت نشد'];
            }

            // Block the user
            $this->db->query(
                "INSERT INTO user_blocks (user_id, blocked_reason, blocked_by, blocked_at) VALUES (?, ?, ?, NOW())
                 ON DUPLICATE KEY UPDATE blocked_reason = VALUES(blocked_reason), blocked_at = NOW()",
                [$userId, $reason, $adminId]
            );

            $this->db->commit();
            $this->eventDispatcher->dispatch('cache.invalidate', ['key' => 'message_moderation_stats_v2']);
            $this->logger->info('user.blocked', ['user_id' => $userId, 'admin_id' => $adminId, 'reason' => $reason]);
            return ['success' => true, 'message' => 'کاربر مسدود شد'];
        } catch (\Throwable $e) {
            $this->db->rollback();
            $this->logger->error('user.block.failed', ['user_id' => $userId, 'error' => $e->getMessage()]);
            return ['success' => false, 'message' => 'خطا در مسدود کردن'];
        }
    }

    /**
     * کاربر کو رہا کریں (unblock)
     */
    public function unblockUser(int $userId): bool
    {
        try {
            $this->db->query("DELETE FROM user_blocks WHERE user_id = ?", [$userId]);
            $this->eventDispatcher->dispatch('cache.invalidate', ['key' => 'message_moderation_stats_v2']);
            $this->logger->info('user.unblocked', ['user_id' => $userId]);
            return true;
        } catch (\Exception $e) {
            $this->logger->error('user.unblock.failed', ['user_id' => $userId, 'error' => $e->getMessage()]);
            return false;
        }
    }

    /**
     * پیام کو براہ راست حاصل کریں
     */
    /** @return array<string, mixed>|null */
    public function getMessage(int $messageId): ?array
    {
        $row = $this->db->query(
            "SELECT * FROM direct_messages WHERE id = ?",
            [$messageId]
        )->fetch(\PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }

    /**
     * 🛡️ HIGH-04: واکشی پیام گزارش شده به همراه پنجره محدودی از پیام‌های قبل و بعد جهت حفظ حریم خصوصی
     */
    /** @return array<int, array<string, mixed>> */
    public function getReportedMessageThread(int $senderId, int $recipientId, int $limit = 5): array
    {
        // 1. پیدا کردن شناسه پیام گزارش شده در این مکالمه
        $reportedMsgId = (int)$this->db->query(
            "SELECT mr.message_id 
             FROM message_reports mr
             JOIN direct_messages dm ON mr.message_id = dm.id
             WHERE (dm.sender_id = ? AND dm.recipient_id = ?) OR (dm.sender_id = ? AND dm.recipient_id = ?)
             ORDER BY mr.created_at DESC LIMIT 1",
            [$senderId, $recipientId, $recipientId, $senderId]
        )->fetchColumn();

        if (!$reportedMsgId) {
            return [];
        }

        // 2. واکشی ۲ پیام قبل از پیام گزارش شده
        $prevMessages = $this->db->query(
            "SELECT * FROM direct_messages 
             WHERE ((sender_id = ? AND recipient_id = ?) OR (sender_id = ? AND recipient_id = ?))
               AND id < ?
             ORDER BY id DESC LIMIT 2",
            [$senderId, $recipientId, $recipientId, $senderId, $reportedMsgId]
        )->fetchAll(\PDO::FETCH_ASSOC) ?: [];

        // 3. واکشی خود پیام گزارش شده
        $targetMessage = $this->db->query(
            "SELECT * FROM direct_messages WHERE id = ?",
            [$reportedMsgId]
        )->fetchAll(\PDO::FETCH_ASSOC) ?: [];

        // 4. واکشی ۲ پیام بعد از پیام گزارش شده
        $nextMessages = $this->db->query(
            "SELECT * FROM direct_messages 
             WHERE ((sender_id = ? AND recipient_id = ?) OR (sender_id = ? AND recipient_id = ?))
               AND id > ?
             ORDER BY id ASC LIMIT 2",
            [$senderId, $recipientId, $recipientId, $senderId, $reportedMsgId]
        )->fetchAll(\PDO::FETCH_ASSOC) ?: [];

        // ادغام و مرتب‌سازی صعودی
        $merged = array_merge($prevMessages, $targetMessage, $nextMessages);
        
        usort($merged, function ($a, $b) {
            return intval($a['id']) <=> intval($b['id']);
        });

        // 5. رمزگشایی در صورت لزوم
        return array_map(function ($msg) {
            $msgContent = $msg['message'] ?? '';
            $isEncrypted = (bool)($msg['is_encrypted'] ?? false);
            if ($isEncrypted) {
                try {
                    $msgContent = $this->decryptMessage($msgContent);
                } catch (\Throwable) {
                    $msgContent = '[رمزگشایی ناموفق]';
                }
            }
            return [
                'id' => intval($msg['id']),
                'sender_id' => intval($msg['sender_id']),
                'message' => $msgContent,
                'is_encrypted' => $isEncrypted,
                'created_at' => $msg['created_at'] ?? '',
            ];
        }, $merged);
    }

    private function decryptMessage(string $encrypted): string
    {
        try {
            if (!$this->appSettings) {
                return '[خطا در دیکریپت - سرویس تنظیمات موجود نیست]';
            }
            
            $encryptionKey = $this->appSettings->get('dm_encryption_key');
            if (!$encryptionKey) {
                return '[خطا در دیکریپت - تنظیمات کلید یافت نشد]';
            }
            
            $decoded = base64_decode($encrypted);
            if ($decoded === false) {
                return '[خطا در دیکریپت]';
            }
            $nonce = mb_substr($decoded, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES, '8bit');
            $ciphertext = mb_substr($decoded, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES, null, '8bit');
            
            $decrypted = sodium_crypto_secretbox_open($ciphertext, $nonce, base64_decode(str_value($encryptionKey)));
            
            return $decrypted !== false ? $decrypted : '[خطا در دیکریپت]';
        } catch (\Exception $e) {
            return '[خطا در دیکریپت]';
        }
    }
}