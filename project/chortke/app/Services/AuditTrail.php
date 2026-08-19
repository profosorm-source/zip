<?php

declare(strict_types=1);

namespace App\Services;

use App\Contracts\LoggerInterface;
use App\Models\AuditTrail as AuditTrailModel;


class AuditTrail
{
    use \App\Traits\ClientInfoTrait;

    private ?LoggerInterface $logger;
    private AuditTrailModel $auditTrailModel;
    private \Core\PathResolver $paths;

    public function __construct(
        AuditTrailModel $auditTrailModel,
        \Core\PathResolver $paths,
        ?LoggerInterface $logger = null
    ) {
        $this->auditTrailModel = $auditTrailModel;
        $this->paths = $paths;
        $this->logger = $logger;
    }

    private function getLogger(): ?LoggerInterface
    {
        return $this->logger;
    }

    /** @param array<string, mixed> $context */
    /**
     * Backward-compatible entry: ثبت یک رویداد audit از یک array واحد.
     * این pattern در چند Listener استفاده شده و به record() نگاشت می‌شود.
     * @param array<string, mixed> $payload
     */
    public function logEvent(array $payload): void
    {
        $rawEvent = $payload['action'] ?? $payload['event'] ?? 'audit.record';
        $event = is_scalar($rawEvent) ? (string)$rawEvent : 'audit.record';
        $userId = isset($payload['user_id']) && (is_int($payload['user_id']) || is_string($payload['user_id']))
            ? (int)$payload['user_id'] : null;
        $context = [];
        foreach (['resource_id', 'metadata', 'details', 'ip', 'extra'] as $k) {
            if (array_key_exists($k, $payload)) {
                $context[$k] = $payload[$k];
            }
        }
        $this->record($event, $userId, $context);
    }

    /** @param array<string, mixed> $context */
    private function log(string $level, string $message, array $context = []): void
    {
        $l = $this->getLogger();
        if ($l !== null) {
            match($level) {
                'info'    => $l->info($message, $context),
                'warning' => $l->warning($message, $context),
                'error'   => $l->error($message, $context),
                'critical'=> $l->critical($message, $context),
                default   => $l->info($message, $context),
            };
        } else {
            error_log("[AuditTrail] [{$level}] {$message} " . json_encode($context, JSON_UNESCAPED_UNICODE));
        }
    }

    /** @param array<string, mixed> $context
     *  @return bool */
    public function record(
        string $event,
        ?int $userId = null,
        array $context = [],
        ?int $actorId = null
    ): bool {
        try {
            // Publish an audit-record event instead of writing directly.
            // A dedicated listener will persist the record to DB. This keeps audit as an event-driven source of truth.
            $eventObj = new \App\Events\AuditRecordedEvent($event, $userId, $context, $actorId);
            \Core\EventDispatcher::getInstance()->dispatch(\App\Events\AuditRecordedEvent::class, $eventObj);
            return true;
        } catch (\Throwable $e) {
            \App\Services\Sentry\SentryExceptionHandler::captureException($e, null, ['operation' => 'audit_trail.record']);
            $this->log('error', 'audit_trail.record.publish_failed', [
                'channel' => 'audit_trail',
                'event' => $event,
                'user_id' => $userId,
                'actor_id' => $actorId,
                'error' => $e->getMessage(),
                'exception' => get_class($e),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);
            return false;
        }
    }

    /**
     * Persist an audit record directly to storage. Intended for use by the audit event listener.
     */
    /** @param array<string, mixed> $context
     *  @return bool */
    public function persistRecord(
        string $event,
        ?int $userId = null,
        array $context = [],
        ?int $actorId = null
    ): bool {
        try {
            $safeEvent = $this->sanitizeEvent($event);
            $safeContext = $this->sanitizeContext($context);

            $result = $this->auditTrailModel->createEntry([
                'request_id' => app()->request->header('x-request-id'),
                'event' => $safeEvent,
                'user_id' => $userId,
                'actor_id' => $actorId ?? (int)$this->currentUserId(),
                'context' => json_encode($safeContext, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'ip_address' => $this->clientIp(),
                'user_agent' => $this->userAgent(),
                'created_at' => date('Y-m-d H:i:s'),
            ]);

            return (bool)$result;
        } catch (\Throwable $e) {
            $this->log('error', 'audit_trail.persist.failed', [
                'channel' => 'audit_trail',
                'event' => $event,
                'user_id' => $userId,
                'actor_id' => $actorId,
                'error' => $e->getMessage(),
                'exception' => get_class($e),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);
            return false;
        }
    }

    /**
     * ثبت لاگ تغییرات ادمین — یکپارچه شده با Event-Driven AuditTrail
     *
     * 🛡️ Audit Fix: به جای INSERT مستقیم به admin_audit_log (جدول موازی)،
     * از AuditTrail::record() استفاده می‌کند که از طریق AuditRecordedEvent
     * در audit_trail ذخیره می‌شود.
     */
    /**
     * @param array<string, mixed>|null $oldValues
     * @param array<string, mixed>|null $newValues
     * @return bool
     */
    public function logAdminAction(
        int $adminId,
        string $action,
        string $entityType,
        int $entityId,
        ?array $oldValues,
        ?array $newValues,
        string $ipAddress,
        string $userAgent,
        string $sessionId
    ): bool {
        try {
            return $this->record('admin.' . $action, null, [
                'admin_id' => $adminId,
                'entity_type' => $entityType,
                'entity_id' => $entityId,
                'old_values' => $oldValues,
                'new_values' => $newValues,
                'ip_address' => $ipAddress,
                'user_agent' => $userAgent,
                'session_id' => $sessionId,
            ], $adminId);
        } catch (\Throwable $e) {
            \App\Services\Sentry\SentryExceptionHandler::captureException($e, null, ['operation' => 'audit_trail.logAdminAction']);
            $this->log('error', 'audit_trail.admin_action.failed', ['error' => $e->getMessage()]);
            return false;
        }
    }

    /**
     * @param array<string, mixed> $before
     * @param array<string, mixed> $after
     * @param list<string> $ignore
     * @return bool
     */
    public function diff(
        string $event,
        ?int $userId,
        array $before,
        array $after,
        array $ignore = ['updated_at', 'created_at', 'password', 'remember_token']
    ): bool {
        $changes = [];

        // Check for modified or new keys
        foreach ((array)$after as $key => $newVal) {
            if (in_array($key, $ignore, true)) {
                continue;
            }
            $oldVal = $before[$key] ?? null;
            if ($oldVal !== $newVal) {
                $changes[$key] = ['from' => $oldVal, 'to' => $newVal];
            }
        }

        // Check for deleted keys (present in $before but missing in $after)
        foreach ((array)$before as $key => $oldVal) {
            if (in_array($key, $ignore, true)) {
                continue;
            }
            if (!array_key_exists($key, $after)) {
                $changes[$key] = ['from' => $oldVal, 'to' => null];
            }
        }

        if (empty($changes)) {
            return true;
        }

        return $this->record($event, $userId, ['changes' => $changes]);
    }

    /** @return array<string, mixed> */
    public function archiveOlderThan(int $days = 30, int $chunkSize = 2000): array
    {
        try {
            $cutoff = date('Y-m-d H:i:s', (strtotime("-{$days} days") ?: time()));
            $archiveDir = $this->paths->storage('audit-archives');

            if (!is_dir($archiveDir)) {
                if (!mkdir($archiveDir, 0755, true) && !is_dir($archiveDir)) {
                    throw new \RuntimeException("Failed to create directory: {$archiveDir}");
                }
            }

            // MED-05: اعمال File Lock برای جلوگیری از تضادی cron‌های همزمان
            $lockFile = $archiveDir . '/.archive.lock';
            $lock = fopen($lockFile, 'w');
            if (!$lock) {
                throw new \RuntimeException('Cannot create lock file');
            }

            // بگیرید exclusive non-blocking lock
            if (!flock($lock, LOCK_EX | LOCK_NB)) {
                fclose($lock);
                return [
                    'archived' => 0,
                    'deleted' => 0,
                    'file' => null,
                    'error' => 'Archive already running - lock acquired by another process'
                ];
            }

            try {
                $stamp = date('Ymd_His');
                $jsonlFile = $archiveDir . "/audit_{$stamp}.jsonl";
                $gzFile = $jsonlFile . '.gz';

                $fp = fopen($jsonlFile, 'ab');
                if (!$fp) {
                    throw new \RuntimeException('Cannot create archive file');
                }

                $total = 0;
                $lastId = 0;

                while (true) {
                    $rows = $this->auditTrailModel->fetchBatchOlderThan($cutoff, $lastId, $chunkSize);

                    if (empty($rows)) {
                        break;
                    }

                    foreach ($rows as $row) {
                        $rowArr = (array)$row;
                        fwrite($fp, json_encode($rowArr, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL);
                        $total++;
                        $lastId = intval($rowArr['id'] ?? $lastId);
                    }
                }

                fclose($fp);

                if ($total === 0) {
                    if (file_exists($jsonlFile)) {
                        unlink($jsonlFile);
                    }
                    return [
                        'archived' => 0,
                        'deleted' => 0,
                        'file' => null,
                        'cutoff' => $cutoff,
                    ];
                }

                $in = fopen($jsonlFile, 'rb');
                if (!$in) {
                    throw new \RuntimeException('Cannot open archive temp file');
                }

                $out = gzopen($gzFile, 'wb9');
                if (!$out) {
                    fclose($in);
                    throw new \RuntimeException('Cannot create gzip archive');
                }

                while (!feof($in)) {
                    $chunk = fread($in, 8192);
                    if ($chunk === false) {
                        gzclose($out);
                        fclose($in);
                        throw new \RuntimeException('Cannot read archive temp chunk');
                    }
                    gzwrite($out, $chunk);
                }

                gzclose($out);
                fclose($in);

                if (file_exists($jsonlFile)) {
                    unlink($jsonlFile);
                }

                if (!file_exists($gzFile) || filesize($gzFile) === 0) {
                    throw new \RuntimeException('Archive gzip file is invalid');
                }

                $deleted = 0;
                do {
                    $batch = $this->auditTrailModel->deleteOlderThan($cutoff, 5000, true);
                    $deleted += $batch;
                } while ($batch === 5000);

                // L-SRV-02 Fix: پاکسازی خودکار آرشیوهای فشرده قدیمی‌تر از ۹۰ روز جهت جلوگیری از پر شدن تدریجی هارد دیسک
                $purgedArchivesCount = $this->purgeOldArchiveFiles($archiveDir, 90);

                $this->log('info', 'audit_trail.archive.completed', [
                    'channel' => 'audit_trail',
                    'cutoff' => $cutoff,
                    'archived' => $total,
                    'deleted' => $deleted,
                    'file' => basename($gzFile),
                    'size' => filesize($gzFile),
                    'sha256' => hash_file('sha256', $gzFile),
                    'purged_archives' => $purgedArchivesCount,
                ]);

                return [
                    'archived' => $total,
                    'deleted' => $deleted,
                    'file' => $gzFile,
                    'cutoff' => $cutoff,
                    'size' => filesize($gzFile),
                    'purged_archives' => $purgedArchivesCount,
                ];
            } finally {
                // MED-05: همیشه lock را release کن
                flock($lock, LOCK_UN);
                fclose($lock);
            }
        } catch (\Throwable $e) {
            $this->log('error', 'audit_trail.archive.failed', [
                'channel' => 'audit_trail',
                'days' => $days,
                'error' => $e->getMessage(),
                'exception' => get_class($e),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);

            return [
                'archived' => 0,
                'deleted' => 0,
                'file' => null,
                'error' => $e->getMessage(),
            ];
        }
    }
    /** @return list<\stdClass> */
    public function getForUser(int $userId, int $limit = 50): array
    {
        try {
            return $this->auditTrailModel->getForUser($userId, $limit);
        } catch (\Throwable $e) {
            $this->log('error', 'audit_trail.get_for_user.failed', [
                'channel' => 'audit_trail',
                'user_id' => $userId,
                'limit' => $limit,
                'error' => $e->getMessage(),
            ]);
            return [];
        }
    }

    /** @return array<string, mixed> */
    public function getAll(
        int $page = 1,
        int $perPage = 50,
        ?string $event = null,
        ?int $userId = null,
        ?string $search = null,
        ?string $dateFrom = null,
        ?string $dateTo = null
    ): array {
        try {
            return $this->auditTrailModel->getAll($page, $perPage, $event, $userId, $search, $dateFrom, $dateTo);
        } catch (\Throwable $e) {
            $this->log('error', 'audit_trail.get_all.failed', [
                'channel' => 'audit_trail',
                'error' => $e->getMessage(),
            ]);
            return [
                'rows' => [],
                'total' => 0,
                'page' => $page,
                'totalPages' => 0,
            ];
        }
    }

    /**
     * لیست انواع eventهای موجود در audit_trail
     */
    /** @return list<\stdClass> */
    public function getEventTypes(): array
    {
        try {
            return $this->auditTrailModel->getEventTypes();
        } catch (\Throwable $e) {
            $this->log('error', 'audit_trail.get_event_types.failed', [
                'channel' => 'audit_trail',
                'error' => $e->getMessage(),
                'exception' => get_class($e),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);
            return [];
        }
    }

    /**
     * آمار کلی audit در بازه زمانی
     */
    /** @return array<string, mixed> */
    public function getStats(?string $dateFrom = null, ?string $dateTo = null): array
    {
        try {
            return $this->auditTrailModel->getStats($dateFrom, $dateTo);
        } catch (\Throwable $e) {
            $this->log('error', 'audit_trail.get_stats.failed', [
                'channel' => 'audit_trail',
                'date_from' => $dateFrom,
                'date_to' => $dateTo,
                'error' => $e->getMessage(),
                'exception' => get_class($e),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);
            return [
                'total' => 0,
                'unique_users' => 0,
                'unique_actors' => 0,
                'today' => 0,
            ];
        }
    }

    public function cleanup(int $days = 365, bool $bypassCompliance = false): int
    {
        try {
            return $this->auditTrailModel->cleanupOlderThan($days, $bypassCompliance);
        } catch (\Throwable $e) {
            $this->log('error', 'audit_trail.cleanup.failed', [
                'channel' => 'audit_trail',
                'days' => $days,
                'error' => $e->getMessage(),
            ]);
            return 0;
        }
    }

    private function sanitizeEvent(string $event): string
    {
        // حذف کاراکترهای خاص
        $event = preg_replace('/[^a-zA-Z0-9_.\-:]/', '', $event);
        
        // محدود کردن طول
        return substr((string)$event, 0, 100);
    }

    /** @param array<string, mixed> $context
     *  @return array<int|string, mixed> */
    private function sanitizeContext(array $context): array
    {
        $sensitiveKeys = ['password', 'token', 'secret', 'api_key', 'private_key', 'card_number', 'sheba', 'iban'];
        
        // Mask sensitive keys at any level recursively
        $masked = $context;
        array_walk_recursive($masked, function (&$value, $key) use ($sensitiveKeys) {
            $k = strtolower((string)$key);
            foreach ($sensitiveKeys as $field) {
                if (str_contains($k, $field)) {
                    $value = '[REDACTED]';
                    return;
                }
            }

            if (is_string($value) && mb_strlen((string)$value) > 2000) {
                $value = mb_substr($value, 0, 2000) . '...';
            }
        });

        // Limit array depth to prevent size explosion
        return $this->limitArrayDepth($masked, 5);
    }

    /** @param array<string, mixed> $array
     *  @return array<int|string, mixed> */
    private function limitArrayDepth(array $array, int $maxDepth, int $currentDepth = 0): array
    {
        if ($currentDepth >= $maxDepth) {
            return ['[MAX_DEPTH_REACHED]'];
        }
        
        $result = [];
        foreach ((array)$array as $key => $value) {
            if (is_array($value)) {
                $result[$key] = $this->limitArrayDepth($value, $maxDepth, $currentDepth + 1);
            } else {
                $result[$key] = $value;
            }
        }
        
        return $result;
    }

    /**
     * L-SRV-02 Fix: حذف فیزیکی فایل‌های فشرده آرشیو خیلی قدیمی جهت آزادسازی خودکار فضای دیسک
     */
    private function purgeOldArchiveFiles(string $archiveDir, int $retentionDays = 90): int
    {
        $purged = 0;
        try {
            if (!is_dir($archiveDir)) {
                return 0;
            }
            $files = glob($archiveDir . '/audit_*.jsonl.gz');
            if (!$files) {
                return 0;
            }
            $now = time();
            foreach ($files as $file) {
                $fileTime = filemtime($file);
                if (($now - $fileTime) > ($retentionDays * 86400)) {
                    if (@unlink($file)) {
                        $purged++;
                    }
                }
            }
        } catch (\Throwable $e) {
            $this->log('warning', 'audit_trail.purge_archives.failed', ['error' => $e->getMessage()]);
        }
        return $purged;
    }
}

