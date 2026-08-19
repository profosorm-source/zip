<?php

namespace App\Services;

use Psr\Log\LoggerInterface;

/**
 * Central Log Service with correlation_id support for Distributed Tracing.
 */
class LogService implements LoggerInterface
{


    public const TYPE_SYSTEM = 'system';
    public const TYPE_ACTIVITY = 'activity';
    public const TYPE_SECURITY = 'security';
    public const TYPE_PERFORMANCE = 'performance';
    private ?string $correlationId = null;

    public function setCorrelationId(string $id): void
    {
        $this->correlationId = $id;
    }

    public function getCorrelationId(): ?string
    {
        $header = app()->request->header('x-request-id');
        return $this->correlationId ?? (is_string($header) ? $header : null);
    }

    public function emergency(string|\Stringable $message, array $context = []): void
    {
        $this->log('emergency', $message, $context);
    }

    public function alert(string|\Stringable $message, array $context = []): void
    {
        $this->log('alert', $message, $context);
    }

    public function critical(string|\Stringable $message, array $context = []): void
    {
        $this->log('critical', $message, $context);
    }

    public function error(string|\Stringable $message, array $context = []): void
    {
        $this->log('error', $message, $context);
    }

    public function warning(string|\Stringable $message, array $context = []): void
    {
        $this->log('warning', $message, $context);
    }

    public function notice(string|\Stringable $message, array $context = []): void
    {
        $this->log('notice', $message, $context);
    }

    public function info(string|\Stringable $message, array $context = []): void
    {
        $this->log('info', $message, $context);
    }

    public function debug(string|\Stringable $message, array $context = []): void
    {
        $this->log('debug', $message, $context);
    }

    public function log(mixed $level, string|\Stringable $message, array $context = []): void
    {
        $context['correlation_id'] = $this->getCorrelationId();

        // Simple implementation - in real project this would go to file, Sentry, etc.
        $logMessage = sprintf(
            "[%s] %s %s %s\n",
            strtoupper(str_value($level)),
            date('Y-m-d H:i:s'),
            (string)$message,
            json_encode($context) ?: '{}'
        );

        // For now just echo in CLI/tests, in production use proper logger
        if (PHP_SAPI === 'cli') {
            // اصلاح راهبردی لاگینگ در ورکرهای خط فرمان (Twelve-Factor App Non-TTY Log Stripping Shield):
            // پالایش خودکار خروجی در استریم‌های غیرتعاملی داکر و کوبرنتیز جهت جلوگیری از درج کاراکترهای فاسد در سیستم‌های لاگ ابری
            if (!stream_isatty(STDOUT)) {
                $logMessage = (string)preg_replace('/\033\[[0-9;]*m/', '', $logMessage);
            }
            echo $logMessage;
            if (defined('STDOUT')) { @fflush(STDOUT); }
        } else {
            @error_log($logMessage);
        }
    }


    /**
     * Query persisted logs for legacy admin log pages.
     * Keeps DB access out of views while tolerating older schemas.
     */
    /**
     * @param array<string, mixed> $filters
     * @return array<string, mixed>
     */
    public function query(array $filters = [], int $page = 1, int $perPage = 50): array
    {
        $type = str_value($filters['type'] ?? self::TYPE_ACTIVITY);
        $page = max(1, $page);
        $perPage = max(1, min(500, $perPage));
        $offset = ($page - 1) * $perPage;
        $db = db();
        $table = match ($type) {
            self::TYPE_SECURITY => 'security_logs',
            self::TYPE_PERFORMANCE => 'performance_logs',
            default => 'activity_logs',
        };

        if (!$this->tableExists($table)) {
            return ['rows' => [], 'total' => 0, 'page' => $page, 'perPage' => $perPage, 'totalPages' => 0];
        }

        $where = [];
        $params = [];
        if ($this->columnExists($table, 'deleted_at')) {
            $where[] = 'deleted_at IS NULL';
        }
        foreach (['user_id','action','channel','level'] as $field) {
            if (($filters[$field] ?? null) !== null && $this->columnExists($table, $field)) {
                $where[] = "`{$field}` = ?";
                $params[] = $filters[$field];
            }
        }
        if (!empty($filters['date_from']) && $this->columnExists($table, 'created_at')) {
            $where[] = 'created_at >= ?';
            $params[] = $filters['date_from'] . ' 00:00:00';
        }
        if (!empty($filters['date_to']) && $this->columnExists($table, 'created_at')) {
            $where[] = 'created_at <= ?';
            $params[] = $filters['date_to'] . ' 23:59:59';
        }
        if (!empty($filters['search'])) {
            $searchParts = [];
            foreach (['description','message','event','action'] as $field) {
                if ($this->columnExists($table, $field)) {
                    $searchParts[] = "`{$field}` LIKE ?";
                    $params[] = '%' . addcslashes(str_value($filters['search']), '%_') . '%';
                }
            }
            if ($searchParts) $where[] = '(' . implode(' OR ', $searchParts) . ')';
        }
        $whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';
        $totalRow = $db->fetch("SELECT COUNT(*) AS c FROM `{$table}` {$whereSql}", $params);
        $total = (int)($totalRow->c ?? 0);
        $rows = $db->fetchAll("SELECT * FROM `{$table}` {$whereSql} ORDER BY created_at DESC LIMIT {$perPage} OFFSET {$offset}", $params) ?: [];
        return ['rows' => $rows, 'total' => $total, 'page' => $page, 'perPage' => $perPage, 'totalPages' => (int)ceil($total / $perPage)];
    }

    public function findById(int $id, string $type = self::TYPE_ACTIVITY): ?object
    {
        $table = match ($type) {
            self::TYPE_SECURITY => 'security_logs',
            self::TYPE_PERFORMANCE => 'performance_logs',
            default => 'activity_logs',
        };
        if (!$this->tableExists($table)) return null;
        return db()->fetch("SELECT * FROM `{$table}` WHERE id = ? LIMIT 1", [$id]) ?: null;
    }

    /** @return array<string, mixed> */
    public function cleanup(int $days = 90): array
    {
        $cutoff = date('Y-m-d H:i:s', strtotime('-' . max(30, $days) . ' days') ?: time());
        $out = ['activity_logs' => 0, 'security_logs' => 0, 'performance_logs' => 0];
        foreach (array_keys($out) as $table) {
            if (!$this->tableExists($table) || !$this->columnExists($table, 'created_at')) continue;
            try {
                if ($this->columnExists($table, 'deleted_at')) {
                    db()->query("UPDATE `{$table}` SET deleted_at = NOW() WHERE created_at < ? AND deleted_at IS NULL", [$cutoff]);
                } else {
                    db()->query("DELETE FROM `{$table}` WHERE created_at < ?", [$cutoff]);
                }
                $out[$table] = 1;
            } catch (\Throwable $e) {
                @error_log('[LogService] cleanup failed for table: ' . $e->getMessage());
            }
        }
        return $out;
    }

    private function tableExists(string $table): bool
    {
        try { return (bool)db()->fetch('SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? LIMIT 1', [$table]); } catch (\Throwable) { return false; }
    }

    private function columnExists(string $table, string $column): bool
    {
        try { return (bool)db()->fetch('SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ? LIMIT 1', [$table, $column]); } catch (\Throwable) { return false; }
    }


    // ─────────────────────────────────────────────────────────────────────────
    // EXTENDED METHODS (مورد نیاز Logger facade)
    // ─────────────────────────────────────────────────────────────────────────

    /** @param array<string, mixed> $context */
    public function logSystem(string $level, string $message, array $context = []): void
    {
        $context['type'] = 'system';
        $this->log($level, $message, $context);
    }

    /** @param array<string, mixed> $metadata */
    public function logActivity(string $action, string $description, ?int $userId = null, array $metadata = []): void
    {
        $context = array_merge($metadata, [
            'type' => 'activity',
            'action' => $action,
            'description' => $description,
            'user_id' => $userId,
        ]);
        $this->log('info', "Activity: {$action} - {$description}", $context);
    }

    /** @param array<string, mixed> $context */
    public function logSecurity(string $type, string $message, string $level = 'warning', array $context = []): void
    {
        $context['type'] = 'security';
        $context['security_type'] = $type;
        $this->log($level, $message, $context);
    }

    /** @param array<string, mixed> $context */
    public function logPerformance(string $metric, float $value, array $context = []): void
    {
        $context['type'] = 'performance';
        $context['metric'] = $metric;
        $context['value'] = $value;
        $this->log('info', "Performance: {$metric} = {$value}", $context);
    }

    /** @param array<string, mixed> $metadata */
    public function activity(string $action, string $description, ?int $userId = null, array $metadata = []): void
    {
        $this->info("[activity] {$action}: {$description}", array_merge(['user_id' => $userId], $metadata));
    }
}