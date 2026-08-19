<?php

declare(strict_types=1);

namespace App\Services;

use App\Contracts\MetricsCollectorInterface;
use App\Contracts\LoggerInterface;
use Core\Cache;
use Core\Database;

/**
 * EmailDeliveryStore — ذخیره‌سازی و مدیریت وضعیت ایمیل‌ها
 *
 * این کلاس Queue Engine **نیست** — فقط Email Storage + Status Tracking هست.
 * تمام dispatch/retry/backpressure از core/Queue رد میشه.
 *
 * Refactored from RedisEmailQueueService:
 * - push() → save() (فقط ذخیره، بدون queue logic)
 * - pop() → getPending() (فقط خواندن pending)
 * - claim() → findAndLock() (فقط lock status)
 * - Queue Engine logic (Lua, ZADD, ZRANGEBYSCORE) حذف شد
 */
class EmailDeliveryStore
{
    private function toRow(mixed $row): ?\stdClass
    {
        if ($row instanceof \stdClass) {
            return $row;
        }
        if (is_array($row)) {
            return (object)$row;
        }
        return null;
    }

    /** @return list<\stdClass> */
    private function toRows(mixed $rows): array
    {
        if (!is_array($rows)) {
            return [];
        }

        $result = [];
        foreach ($rows as $row) {
            $normalized = $this->toRow($row);
            if ($normalized !== null) {
                $result[] = $normalized;
            }
        }
        return $result;
    }

    private ?\Redis $redisClient = null;
    private string $metaPrefix = 'email:meta:';

    private Cache $cache;
    private Database $db;
    private LoggerInterface $logger;
    private \Core\PathResolver $paths;

    public function __construct(
        Cache $cache,
        Database $db,
        LoggerInterface $logger,
        \Core\PathResolver $paths
    ) {
        $this->cache = $cache;
        $this->db = $db;
        $this->logger = $logger;
        $this->paths = $paths;
        $this->redisClient = $this->cache->redis();

        $prefix = config('redis.prefix', 'chortke');
        if (!is_string($prefix) || trim($prefix) === '') {
            throw new \InvalidArgumentException('Redis prefix must be a non-empty string.');
        }
        $this->metaPrefix = rtrim($prefix, ':') . ':email:meta:';
    }

    private function redis(): \Redis
    {
        if ($this->redisClient === null) {
            throw new \LogicException('Redis email metadata operation attempted without Redis.');
        }
        return $this->redisClient;
    }

    /**
     * ذخیره ایمیل جدید — فقط storage (بدون queue dispatch)
     *
     * @return string|false email ID
     */
    /** @param array<string, mixed> $emailData */
    public function save(array $emailData): string|false
    {
        $emailId = $this->generateEmailId();
        $payload = [
            'id'           => $emailId,
            'to'           => $emailData['to'],
            'subject'      => $emailData['subject'],
            'body'         => $emailData['body'],
            'priority'     => $emailData['priority'] ?? 'normal',
            'user_id'      => $emailData['user_id'] ?? null,
            'template'     => $emailData['template'] ?? null,
            'variables'    => $emailData['variables'] ?? [],
            'attempts'     => 0,
            'status'       => 'pending',
            'created_at'   => time(),
            'scheduled_at' => $emailData['scheduled_at'] ?? time(),
        ];

        // ذخیره metadata در Redis (برای خواندن سریع)
        if ($this->redisClient !== null) {
            try {
                $this->redis()->setEx(
                    $this->metaPrefix . $emailId,
                    86400 * 7,
                    json_encode($payload, JSON_THROW_ON_ERROR)
                );
            } catch (\Throwable $e) {
                $this->logger->warning('email.store.redis_meta_failed', ['error' => $e->getMessage()]);
            }
        }

        // ذخیره در DB (source of truth)
        $dbResult = $this->saveToDatabase($payload);
        if ($dbResult) {
            $this->logger->info('email.store.saved', ['email_id' => $emailId]);
            return $emailId;
        }

        // Fallback to file
        $fileResult = $this->saveToFile($payload);
        return $fileResult ?: false;
    }

    /**
     * یافتن ایمیل و قفل آن برای ارسال (SELECT FOR UPDATE)
     */
    /** @return array<string, mixed>|null */
    public function findAndLock(string $emailId): ?array
    {
        try {
            $numericId = is_numeric($emailId) ? (int)$emailId : null;

            if ($numericId) {
                $row = $this->toRow($this->db->selectOne(
                    "SELECT * FROM email_queue WHERE id = ? AND status = 'pending' FOR UPDATE",
                    [$numericId]
                ));
            } else {
                // Redis-generated ID — check meta
                if ($this->redisClient !== null) {
                    $meta = $this->redis()->get($this->metaPrefix . $emailId);
                    if (is_string($meta) && $meta !== '') {
                        $data = json_decode($meta, true, 512, JSON_THROW_ON_ERROR);
                        if (!is_array($data) || ($data['status'] ?? '') !== 'pending') {
                            return null;
                        }
                        return $data;
                    }
                }
                return null;
            }

            if (!$row) return null;

            $this->db->execute(
                "UPDATE email_queue SET status = 'sending', updated_at = NOW() WHERE id = ?",
                [$numericId]
            );

            return (array)$row;
        } catch (\Throwable $e) {
            $this->logger->error('email.store.lock_failed', ['email_id' => $emailId, 'error' => $e->getMessage()]);
            \App\Services\Sentry\SentryExceptionHandler::captureException($e, null, ['operation' => 'email_delivery.lockRecord', 'email_id' => $emailId]);
            return null;
        }
    }

    /**
     * علامت‌گذاری به عنوان ارسال شده
     */
    public function markAsSent(string $emailId): bool
    {
        $this->updateStatus($emailId, 'sent');

        if ($this->redisClient !== null) {
            try {
                $this->redis()->del($this->metaPrefix . $emailId);
            } catch (\Throwable $e) {
            $this->logger->warning('emaildeliverystore.operation_failed', ['error' => $e->getMessage()]);
        }
        }

        $this->logger->info('email.store.sent', ['email_id' => $emailId]);
        return true;
    }

    /**
     * علامت‌گذاری به عنوان ناموفق
     */
    public function markAsFailed(string $emailId, string $error): bool
    {
        $numericId = is_numeric($emailId) ? (int)$emailId : null;

        if ($numericId) {
            $row = $this->toRow($this->db->selectOne(
                "SELECT attempts, user_id, to_email, subject, body, template, variables, priority FROM email_queue WHERE id = ?",
                [$numericId]
            ));
            $attempts = int_value($row?->attempts ?? 0) + 1;
            $maxAttempts = 3;

            if ($attempts >= $maxAttempts) {
                $this->db->execute(
                    "UPDATE email_queue SET status = 'failed', last_error = ?, attempts = ?, updated_at = NOW() WHERE id = ?",
                    [mb_substr($error, 0, 500), $attempts, $numericId]
                );
            } else {
                $this->db->execute(
                    "UPDATE email_queue SET status = 'pending', last_error = ?, attempts = ?, updated_at = NOW() WHERE id = ?",
                    [mb_substr($error, 0, 500), $attempts, $numericId]
                );
            }
        }

        if ($this->redisClient !== null) {
            try {
                $meta = $this->redis()->get($this->metaPrefix . $emailId);
                if (is_string($meta) && $meta !== '') {
                    $data = json_decode($meta, true, 512, JSON_THROW_ON_ERROR);
                    if (!is_array($data)) {
                        throw new \UnexpectedValueException('Redis email metadata must be a JSON object.');
                    }
                    $data['status'] = 'failed';
                    $data['error'] = $error;
                    $data['attempts'] = int_value($data['attempts'] ?? 0) + 1;
                    $this->redis()->setEx(
                        $this->metaPrefix . $emailId,
                        86400,
                        json_encode($data, JSON_THROW_ON_ERROR)
                    );
                }
            } catch (\Throwable $e) {
            $this->logger->warning('emaildeliverystore.operation_failed', ['error' => $e->getMessage()]);
        }
        }

        $this->logger->warning('email.store.failed', ['email_id' => $emailId, 'error' => $error]);
        return true;
    }

    /**
     * لیست ایمیل‌های pending (برای cron backup)
     */
    /** @return list<\stdClass> */
    public function getPending(int $limit = 10): array
    {
        // اصلاح کلیدی معماری همزمانی در صف ارسال ایمیل (Email Queue Concurrency Guard):
        // استفاده از قفل‌گذاری اتمیک FOR UPDATE جهت جلوگیری از واکشی موازی توسط ورکرهای کرون و ارسال ایمیل‌های تکراری به کاربران
        $rows = $this->toRow($this->db->fetchAll(
            "SELECT * FROM email_queue WHERE status = 'pending' AND scheduled_at <= NOW() ORDER BY priority DESC, created_at ASC LIMIT ? FOR UPDATE",
            [$limit]
        ));
        return $rows ? (array) $rows : [];
    }

    /**
     * آمار صف
     */
    /** @return array<string, mixed> */
    public function getStats(): array
    {
        $rows = $this->db->fetchAll("SELECT status, COUNT(*) as cnt FROM email_queue GROUP BY status") ?: [];
        $stats = [];
        foreach ($rows as $row) {
            $stats[$row->status] = (int)$row->cnt;
        }
        return $stats;
    }

    /**
     * پاکسازی ایمیل‌های قدیمی
     */
    public function cleanup(int $days = 7): int
    {
        // 1. Purge sent and failed queue records older than $days
        $deleted = (int)$this->db->execute(
            "DELETE FROM email_queue WHERE status IN ('sent', 'failed') AND updated_at < DATE_SUB(NOW(), INTERVAL ? DAY)",
            [$days]
        );

        // 2. Purge stuck pending/sending emails older than 24 hours
        $this->db->execute(
            "DELETE FROM email_queue WHERE status IN ('pending', 'sending') AND updated_at < DATE_SUB(NOW(), INTERVAL 24 HOUR)"
        );

        // 3. Cleanup unrecovered fallback files older than $days
        $this->cleanupFileFallbacks($days);

        return $deleted;
    }

    /**
     * پاکسازی فایل‌های قدیمی fallback
     */
    public function cleanupFileFallbacks(int $retentionDays = 7): int
    {
        $dir = $this->paths->storage('email_fallback');
        if (!is_dir($dir)) return 0;

        $cutoff = time() - ($retentionDays * 86400);
        $deleted = 0;
        foreach ((glob("{$dir}/*.json") ?: []) as $file) {
            if (filemtime($file) < $cutoff) {
                if (@unlink($file)) {
                    $deleted++;
                }
            }
        }
        return $deleted;
    }

    /**
     * لیست ایمیل‌ها برای پنل ادمین
     * @return array{items: list<\stdClass>, total: int, page: int, per_page: int}
     */
    public function getEmailsForAdmin(
        int $page = 1,
        int $perPage = 20,
        ?string $status = null,
        ?string $search = null,
        ?string $sortBy = null,
        string $sortDir = 'DESC'
    ): array {
        $offset = ($page - 1) * $perPage;
        $where = [];
        $params = [];

        if ($status) {
            $where[] = "status = ?";
            $params[] = $status;
        }
        if ($search) {
            $where[] = "(to_email LIKE ? OR subject LIKE ?)";
            $params[] = "%{$search}%";
            $params[] = "%{$search}%";
        }

        $whereStr = $where ? 'WHERE ' . implode(' AND ', $where) : '';
        $sortBy = in_array($sortBy, ['created_at', 'updated_at', 'priority', 'status'], true) ? $sortBy : 'created_at';
        $sortDir = strtoupper((string)$sortDir) === 'ASC' ? 'ASC' : 'DESC';

        $items = $this->toRows($this->db->fetchAll(
            "SELECT * FROM email_queue {$whereStr} ORDER BY {$sortBy} {$sortDir} LIMIT ? OFFSET ?",
            array_merge($params, [$perPage, $offset])));

        $total = (int)$this->db->fetchColumn(
            "SELECT COUNT(*) FROM email_queue {$whereStr}",
            $params
        );

        return ['items' => $items, 'total' => $total, 'page' => $page, 'per_page' => $perPage];
    }

    /**
     * retry ایمیل failed (re-queue via core/Queue)
     */
    public function retryEmail(int $id): bool
    {
        return (bool)$this->db->execute(
            "UPDATE email_queue SET status = 'pending', last_error = NULL, updated_at = NOW() WHERE id = ? AND status = 'failed'",
            [$id]
        );
    }

    /**
     * retry همه failed ها
     */
    public function retryAllFailed(): int
    {
        return (int)$this->db->execute(
            "UPDATE email_queue SET status = 'pending', last_error = NULL, updated_at = NOW() WHERE status = 'failed'"
        );
    }

    /**
     * بازیابی file fallback ها (Findings #10 & #11 Fix)
     */
    public function recoverFileFallbacks(): int
    {
        $dir = $this->paths->storage('email_fallback');
        if (!is_dir($dir)) return 0;

        $quarantineDir = $dir . '/quarantine';
        if (!is_dir($quarantineDir)) {
            @mkdir($quarantineDir, 0700, true);
        }
        @chmod($quarantineDir, 0700);

        $count = 0;
        $maxAge = 86400 * 3; // 3 days max age for recovery attempts

        foreach ((glob("{$dir}/*.json") ?: []) as $file) {
            try {
                $raw = file_get_contents($file);
                $data = is_string($raw) ? json_decode($raw, true) : null;

                if (!is_array($data) || filemtime($file) < (time() - $maxAge)) {
                    // Corrupted or expired fallback file -> move to Quarantine
                    @rename($file, $quarantineDir . '/' . basename($file));
                    continue;
                }

                if ($this->saveToDatabase($data)) {
                    @unlink($file);
                    $count++;
                }
            } catch (\Throwable $e) {
                $this->logger->warning('email.store.file_recovery_failed', ['file' => $file, 'error' => $e->getMessage()]);
                @rename($file, $quarantineDir . '/' . basename($file));
            }
        }
        return $count;
    }

    // ─── Private helpers ────────────────────────────────────────

    private function generateEmailId(): string
    {
        return uniqid('email_', true);
    }

    private function updateStatus(string $emailId, string $status): void
    {
        $numericId = is_numeric($emailId) ? (int)$emailId : null;
        if ($numericId) {
            $this->db->execute(
                "UPDATE email_queue SET status = ?, updated_at = NOW() WHERE id = ?",
                [$status, $numericId]
            );
        }
    }

    /** @param array<string, mixed> $payload */
    private function saveToDatabase(array $payload): bool|string
    {
        try {
            $rawPriority = $payload['priority'] ?? 0;
            $priorityVal = is_numeric($rawPriority) ? (int)$rawPriority : ($rawPriority === 'high' ? 10 : 0);

            $stmt = $this->db->prepare(
                "INSERT INTO email_queue
                    (user_id, to_email, subject, body, template, variables, priority, status, attempts, created_at, updated_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, 'pending', 0, NOW(), NOW())"
            );
            $stmt->execute([
                $payload['user_id'] ?? null,
                $payload['to'] ?? '',
                $payload['subject'] ?? '',
                $payload['body'] ?? '',
                $payload['template'] ?? null,
                isset($payload['vars']) ? json_encode($payload['vars'], JSON_UNESCAPED_UNICODE) : null,
                $priorityVal
            ]);
            return (string)$this->db->lastInsertId();
        } catch (\Throwable $e) {
            $this->logger->error('email.store.db_save_failed', ['error' => $e->getMessage()]);
            \App\Services\Sentry\SentryExceptionHandler::captureException($e, null, ['operation' => 'email_delivery.saveToDatabase']);
            return false;
        }
    }

    /** @param array<string, mixed> $payload */
    private function saveToFile(array $payload): string|false
    {
        try {
            $dir = $this->paths->storage('email_fallback');
            if (!is_dir($dir)) {
                mkdir($dir, 0700, true);
            }
            @chmod($dir, 0700);

            // Redact raw sensitive tokens from variables
            $sensitiveKeys = ['token', 'verification_token', 'reset_token', 'password', 'code', 'otp', 'secret', 'api_key'];
            if (isset($payload['variables']) && is_array($payload['variables'])) {
                foreach ($payload['variables'] as $k => $v) {
                    if (is_string($k) && in_array(strtolower($k), $sensitiveKeys, true)) {
                        $payload['variables'][$k] = '[REDACTED]';
                    }
                }
            }

            $emailId = $payload['id'] ?? null;
            if (!is_string($emailId) || $emailId === '') {
                throw new \UnexpectedValueException('Email fallback payload is missing its string ID.');
            }
            $encodedPayload = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
            $file = $dir . DIRECTORY_SEPARATOR . $emailId . '.json';
            $tempFile = tempnam($dir, 'tmp_ef_');
            if ($tempFile !== false) {
                file_put_contents($tempFile, $encodedPayload);
                chmod($tempFile, 0600);
                rename($tempFile, $file);
                chmod($file, 0600);
            } else {
                file_put_contents($file, $encodedPayload);
                chmod($file, 0600);
            }
            return $emailId;
        } catch (\Throwable $e) {
            $this->logger->error('email.store.file_save_failed', ['error' => $e->getMessage()]);
            \App\Services\Sentry\SentryExceptionHandler::captureException($e, null, ['operation' => 'email_delivery.saveToFile']);
            return false;
        }
    }
}
