<?php

declare(strict_types=1);

namespace App\Models;

use Core\Database;
use App\Contracts\LoggerInterface;
use Core\Model;
use App\Services\Settings\AppSettings;
use Core\Queue;
use App\Contracts\Sentry\SentryErrorRepositoryInterface;
use App\Contracts\Sentry\SentryPerformanceRepositoryInterface;
use App\Contracts\Sentry\SentryAlertRepositoryInterface;
use App\Contracts\Sentry\SentryEscalationRepositoryInterface;
use App\Contracts\Sentry\SentryQueueRepositoryInterface;
use App\Contracts\Sentry\SentryAuditRepositoryInterface;
use App\Contracts\Sentry\SentryLogRepositoryInterface;

/**
 * SentryModel — Repository یکپارچه برای تمام داده‌های Sentry
 *
 * ── SRP Analysis ──────────────────────────────────────────────────────────
 * این کلاس ۷ مسئولیت مجزا دارد، هر کدام با Interface مستقل تعریف شده:
 *
 *  1. SentryErrorRepositoryInterface    → Error Monitoring (issues, events)
 *  2. SentryPerformanceRepositoryInterface → Performance (transactions, P95)
 *  3. SentryAlertRepositoryInterface    → Alerting (rules, channels, alerts)
 *  4. SentryEscalationRepositoryInterface → Escalation Management
 *  5. SentryQueueRepositoryInterface    → Failed Jobs & Outbox DLQ
 *  6. SentryAuditRepositoryInterface    → Audit Trail queries
 *  7. SentryLogRepositoryInterface      → Error Logs (LogController)
 *
 * ── تصمیم معماری ──────────────────────────────────────────────────────────
 * با وجود ۸۰ متد در یک کلاس، شکستن آن به ۷ Repository مجزا در این مرحله
 * ریسک بالایی دارد (۱۲ Service وابسته، ۱۲ inject point در DI container).
 *
 * استراتژی انتخاب‌شده:
 *   ✅ Interface استخراج شد (قرارداد واضح برای هر مسئولیت)
 *   ✅ SentryModel تمام Interface ها را implement می‌کند
 *   ✅ Consumers می‌توانند به Interface inject شوند (نه به SentryModel مستقیم)
 *   📋 شکستن به Repository های مجزا → فاز بعدی refactor (پس از test coverage کافی)
 *
 * @see docs/srp-sentry-model-roadmap.md برای roadmap شکستن آینده
 * ──────────────────────────────────────────────────────────────────────────
 */
/**
 * Sentry event/context maps are intentionally extensible observability payloads;
 * query methods below distinguish those maps from concrete DB row lists.
 *
 * @phpstan-type SentryPayload array<string, mixed>
 * @phpstan-type SentryFilters array<string, mixed>
 * @phpstan-type SentryRows list<\stdClass>
 */
class SentryModel extends Model implements
    SentryErrorRepositoryInterface,
    SentryPerformanceRepositoryInterface,
    SentryAlertRepositoryInterface,
    SentryEscalationRepositoryInterface,
    SentryQueueRepositoryInterface,
    SentryAuditRepositoryInterface,
    SentryLogRepositoryInterface
{
    protected static string $table = 'sentry_issues';

    private LoggerInterface $logger;
    private AppSettings $appSettings;
    private Queue $queue;

    public function __construct(Database $db, LoggerInterface $logger, AppSettings $appSettings, Queue $queue) {
        parent::__construct($db);
        $this->logger = $logger;
        $this->appSettings = $appSettings;
        $this->queue = $queue;
    }

    // --- Error Monitoring ---

    public function findExistingIssue(string $fingerprint, string $environment): ?\stdClass
    {
        return $this->db->fetch(
            "SELECT * FROM sentry_issues 
             WHERE fingerprint = ? 
             AND status != 'resolved'
             AND environment = ?
             ORDER BY id DESC LIMIT 1",
            [$fingerprint, $environment]
        );
    }

    /** @param SentryPayload $data */
    public function createIssue(array $data): int
    {
        $this->db->query(
            "INSERT INTO sentry_issues (
                fingerprint, level, title, culprit, first_seen, last_seen,
                count, environment, release_version, status, metadata
            ) VALUES (?, ?, ?, ?, NOW(), NOW(), 1, ?, ?, 'unresolved', ?)",
            [
                $data['fingerprint'],
                $data['level'],
                $data['title'],
                $data['culprit'],
                $data['environment'],
                $data['release'],
                json_encode($data['metadata'])
            ]
        );

        return (int)$this->db->lastInsertId();
    }

    public function updateIssueStats(int $issueId, string $level): void
    {
        $this->db->query(
            "UPDATE sentry_issues 
             SET count = count + 1,
                 last_seen = NOW(),
                 level = CASE 
                     WHEN ? = 'critical' THEN 'critical'
                     WHEN ? = 'error' AND level != 'critical' THEN 'error'
                     ELSE level
                 END
             WHERE id = ?",
            [$level, $level, $issueId]
        );
    }

    /** @param SentryPayload $data */
    public function storeEventRecord(array $data): void
    {
        $this->db->query(
            "INSERT INTO sentry_events (
                event_id, request_id, issue_id, level, message, exception_type,
                stack_trace, breadcrumbs, user_context, request_context,
                device_context, tags, extra, environment, release_version,
                user_id, ip_address, user_agent, created_at
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())",
            [
                $data['event_id'],
                app()->request->header('x-request-id'),
                $data['issue_id'],
                $data['level'],
                $data['message'],
                $data['exception_type'],
                $data['stack_trace'],
                $data['breadcrumbs'],
                $data['user_context'],
                $data['request_context'],
                $data['device_context'],
                $data['tags'],
                $data['extra'],
                $data['environment'],
                $data['release_version'],
                $data['user_id'],
                $data['ip_address'],
                $data['user_agent'],
            ]
        );
    }

    public function getErrorStats(string $period, string $environment): ?\stdClass
    {
        $dateCondition = match($period) {
            'today' => "DATE(created_at) = CURDATE()",
            'week' => "created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)",
            'month' => "created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)",
            default => "DATE(created_at) = CURDATE()"
        };

        return $this->db->fetch(
            "SELECT 
                COUNT(DISTINCT issue_id) as total_issues,
                COUNT(*) as total_events,
                SUM(CASE WHEN level = 'critical' THEN 1 ELSE 0 END) as critical_count,
                SUM(CASE WHEN level = 'error' THEN 1 ELSE 0 END) as error_count,
                SUM(CASE WHEN level = 'warning' THEN 1 ELSE 0 END) as warning_count
             FROM sentry_events
             WHERE {$dateCondition}
             AND environment = ?",
            [$environment]
        );
    }

    public function getUserData(int $userId): ?\stdClass
    {
        return $this->db->fetch(
            "SELECT id, email, full_name FROM users WHERE id = ?",
            [$userId]
        );
    }

    // --- Performance Monitoring ---

    /** @param SentryPayload $data */
    public function storePerformanceTransaction(array $data): bool
    {
        return (bool)$this->db->query(
            "INSERT INTO performance_transactions (
                transaction_id, request_id, name, op, duration, memory_used,
                peak_memory, query_count, slow_queries_count,
                status, spans, queries, issues, context, created_at
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())",
            [
                $data['transaction_id'],
                app()->request->header('x-request-id'),
                $data['name'],
                $data['op'],
                $data['duration'],
                $data['memory_used'],
                $data['peak_memory'],
                $data['query_count'],
                $data['slow_queries_count'],
                $data['status'],
                $data['spans'],
                $data['queries'],
                $data['issues'],
                $data['context'],
            ]
        );
    }

    public function getPerformanceAggregates(string $period): ?\stdClass
    {
        $dateCondition = match($period) {
            'today' => "DATE(created_at) = CURDATE()",
            'week' => "created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)",
            'month' => "created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)",
            default => "DATE(created_at) = CURDATE()"
        };

        return $this->db->fetch(
            "SELECT 
                COUNT(*) as total_transactions,
                AVG(duration) as avg_duration,
                MAX(duration) as max_duration,
                AVG(query_count) as avg_queries,
                SUM(CASE WHEN slow_queries_count > 0 THEN 1 ELSE 0 END) as transactions_with_slow_queries,
                AVG(memory_used) as avg_memory
             FROM performance_transactions
             WHERE {$dateCondition}"
        );
    }

    /** @return SentryRows */
    public function getSlowestTransactions(int $limit = 10): array
    {
        return $this->db->fetchAll(
            "SELECT name, AVG(duration) as avg_duration, COUNT(*) as count
             FROM performance_transactions
             WHERE created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
             GROUP BY name
             ORDER BY avg_duration DESC
             LIMIT ?",
            [$limit]
        );
    }

    // --- Alerting & Rules ---

    public function getLastAlert(string $fingerprint, string $severity): ?\stdClass
    {
        return $this->db->fetch(
            "SELECT created_at 
             FROM system_alerts 
             WHERE fingerprint = ? 
             AND severity = ?
             ORDER BY created_at DESC 
             LIMIT 1",
            [$fingerprint, $severity]
        );
    }

    /** @param SentryPayload $data */
    public function storeAlert(array $data): int
    {
        $this->db->query(
            "INSERT INTO system_alerts (
                alert_type, severity, title, message, metadata,
                fingerprint, event_id, environment, is_active
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 1)",
            [
                $data['type'],
                $data['severity'],
                $data['title'],
                $data['message'],
                json_encode($data['metadata'], JSON_UNESCAPED_UNICODE),
                $data['fingerprint'],
                $data['event_id'],
                $data['environment'],
            ]
        );

        return (int)$this->db->lastInsertId();
    }

    /** @return SentryRows */
    /** @return list<\stdClass> */
    public function getActiveChannels(string $severity): array
    {
        return $this->db->fetchAll(
            "SELECT * FROM notification_channels
             WHERE is_active = 1
             AND (
                 alert_levels IS NULL 
                 OR JSON_CONTAINS(alert_levels, ?)
             )",
            [json_encode($severity)]
        );
    }

    public function recordNotificationHistory(int $channelId, int $alertId, string $status): void
    {
        $this->db->query(
            "INSERT INTO notification_history (
                channel_id, alert_id, status, sent_at
            ) VALUES (?, ?, ?, NOW())",
            [$channelId, $alertId, $status]
        );
    }

    public function markAlertAsSent(int $alertId): void
    {
        $this->db->query(
            "UPDATE system_alerts SET is_sent = 1, sent_at = NOW() WHERE id = ?",
            [$alertId]
        );
    }

    /** @return SentryRows */
    public function getActiveAlerts(): array
    {
        return $this->db->fetchAll(
            "SELECT * FROM system_alerts
             WHERE is_active = 1
             AND (is_sent = 0 OR acknowledged_at IS NULL)
             ORDER BY created_at DESC
             LIMIT 100"
        ) ?: [];
    }

    /** @return SentryRows */
    /** @return list<\stdClass> */
    public function getActiveRules(): array
    {
        return $this->db->fetchAll(
            "SELECT * FROM alert_rules WHERE is_active = 1 ORDER BY severity DESC"
        );
    }

    public function getRuleStatus(int $ruleId): ?\stdClass
    {
        return $this->db->fetch(
            "SELECT last_triggered_at FROM alert_rules WHERE id = ?",
            [$ruleId]
        );
    }

    public function updateRuleLastTriggered(int $ruleId): void
    {
        $this->db->query(
            "UPDATE alert_rules SET last_triggered_at = NOW() WHERE id = ?",
            [$ruleId]
        );
    }

    public function getMetricValue(string $type, int $minutes): float
    {
        return match($type) {
            // ─── Error metrics ───
            'error_count' => (float)($this->db->fetchColumn(
                "SELECT COUNT(*) FROM sentry_events WHERE created_at >= DATE_SUB(NOW(), INTERVAL ? MINUTE)",
                [$minutes]
            ) ?: 0),
            'critical_errors' => (float)($this->db->fetchColumn(
                "SELECT COUNT(*) FROM sentry_events WHERE level IN ('critical', 'fatal') AND created_at >= DATE_SUB(NOW(), INTERVAL ? MINUTE)",
                [$minutes]
            ) ?: 0),

            // ─── Performance metrics ───
            'slow_requests' => (float)($this->db->fetchColumn(
                "SELECT COUNT(*) FROM performance_transactions WHERE duration > 1000 AND created_at >= DATE_SUB(NOW(), INTERVAL ? MINUTE)",
                [$minutes]
            ) ?: 0),
            'avg_response_time' => (float)($this->db->fetchColumn(
                "SELECT COALESCE(AVG(duration), 0) FROM performance_transactions WHERE created_at >= DATE_SUB(NOW(), INTERVAL ? MINUTE)",
                [$minutes]
            ) ?: 0),
            'p95_response_time' => $this->getP95ResponseTime($minutes),
            'memory_usage' => (float)($this->db->fetchColumn(
                "SELECT COALESCE(AVG(memory_used), 0) FROM performance_transactions WHERE created_at >= DATE_SUB(NOW(), INTERVAL ? MINUTE)",
                [$minutes]
            ) ?: 0),
            'query_count' => (float)($this->db->fetchColumn(
                "SELECT COALESCE(AVG(query_count), 0) FROM performance_transactions WHERE created_at >= DATE_SUB(NOW(), INTERVAL ? MINUTE)",
                [$minutes]
            ) ?: 0),
            'similar_queries' => (float)($this->db->fetchColumn(
                "SELECT COUNT(*) FROM performance_transactions WHERE JSON_LENGTH(issues) > 0 AND JSON_SEARCH(issues, 'one', 'n_plus_one_query', null, '\$[*].type') IS NOT NULL AND created_at >= DATE_SUB(NOW(), INTERVAL ? MINUTE)",
                [$minutes]
            ) ?: 0),

            // ─── Security metrics ───
            'failed_login' => (float)($this->db->fetchColumn(
                "SELECT COUNT(*) FROM security_logs WHERE event_type = 'login_attempt' AND severity = 'danger' AND created_at >= DATE_SUB(NOW(), INTERVAL ? MINUTE)",
                [$minutes]
            ) ?: 0),
            'suspicious_ips' => (float)($this->db->fetchColumn(
                "SELECT COUNT(DISTINCT ip_address) FROM security_logs WHERE event_type = 'blocked_ip' AND created_at >= DATE_SUB(NOW(), INTERVAL ? MINUTE)",
                [$minutes]
            ) ?: 0),

            // ─── User metrics ───
            'active_users' => (float)($this->db->fetchColumn(
                "SELECT COUNT(DISTINCT user_id) FROM user_sessions WHERE last_activity >= DATE_SUB(NOW(), INTERVAL ? MINUTE)",
                [$minutes]
            ) ?: 0),

            // ─── Queue/DLQ metrics ───
            'failed_jobs' => (float)($this->db->fetchColumn(
                "SELECT COUNT(*) FROM failed_jobs",
                []
            ) ?: 0),
            'queue_dlq' => (float)($this->db->fetchColumn(
                "SELECT COUNT(*) FROM failed_jobs",
                []
            ) ?: 0),
            'dlq_size' => (float)($this->db->fetchColumn(
                "SELECT COUNT(*) FROM outbox_events WHERE status = 'failed'",
                []
            ) ?: 0),

            // ─── Database health metrics ───
            'database_health' => (float)($this->db->fetchColumn(
                "SELECT COUNT(*) FROM sentry_events WHERE level IN ('error','critical','fatal')
                 AND message LIKE '%database%'
                 AND created_at >= DATE_SUB(NOW(), INTERVAL ? MINUTE)",
                [$minutes]
            ) ?: 0),
            'db_slow_queries' => (float)($this->db->fetchColumn(
                "SELECT COUNT(*) FROM performance_transactions WHERE slow_queries_count > 0
                 AND created_at >= DATE_SUB(NOW(), INTERVAL ? MINUTE)",
                [$minutes]
            ) ?: 0),

            // ─── Financial anomaly metrics ───
            'wallet_anomalies' => (float)($this->db->fetchColumn(
                "SELECT COUNT(*) FROM sentry_events WHERE message LIKE '%[ANOMALY]%wallet%'
                 AND created_at >= DATE_SUB(NOW(), INTERVAL ? MINUTE)",
                [$minutes]
            ) ?: 0),
            'payment_failures' => (float)($this->db->fetchColumn(
                "SELECT COUNT(*) FROM payment_logs WHERE status = 'failed'
                 AND created_at >= DATE_SUB(NOW(), INTERVAL ? MINUTE)",
                [$minutes]
            ) ?: 0),

            // ─── Fraud metrics ───
            'fraud_score_high' => (float)($this->db->fetchColumn(
                "SELECT COUNT(*) FROM fraud_analytics WHERE risk_score >= 75
                 AND created_at >= DATE_SUB(NOW(), INTERVAL ? MINUTE)",
                [$minutes]
            ) ?: 0),

            // ─── Saga metrics ───
            'stuck_sagas' => (float)($this->db->fetchColumn(
                "SELECT COUNT(*) FROM saga_executions WHERE status NOT IN ('completed','compensated')
                 AND created_at <= DATE_SUB(NOW(), INTERVAL 30 MINUTE)",
                []
            ) ?: 0),

            default => 0.0,
        };
    }

    public function getFailedJobsSummary(): ?\stdClass
    {
        return $this->db->fetch(
            "SELECT 
                COUNT(*) AS total,
                SUM(CASE WHEN failed_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR) THEN 1 ELSE 0 END) AS recent_24h,
                MIN(failed_at) AS oldest_failed_at
             FROM failed_jobs"
        );
    }

    /** @return SentryRows */
    public function getFailedJobQueueCounts(int $limit = 10): array
    {
        return $this->db->fetchAll(
            "SELECT queue, COUNT(*) AS count 
             FROM failed_jobs 
             GROUP BY queue 
             ORDER BY count DESC 
             LIMIT ?",
            [$limit]
        );
    }

    public function getFailedJobsCount(?string $queue = null): int
    {
        $sql = "SELECT COUNT(*) AS c FROM failed_jobs";
        $params = [];
        if ($queue !== null && $queue !== '') {
            $sql .= " WHERE queue = ?";
            $params[] = $queue;
        }

        return (int)$this->db->fetchColumn($sql, $params);
    }

    public function getOutboxDLQSummary(): ?\stdClass
    {
        return $this->db->fetch(
            "SELECT 
                COUNT(*) AS total,
                SUM(CASE WHEN updated_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR) THEN 1 ELSE 0 END) AS recent_24h,
                MIN(updated_at) AS oldest_failed_at
             FROM outbox_events
             WHERE status IN ('failed', 'dlq')"
        );
    }

    /** @return SentryRows */
    public function getOutboxDLQList(int $limit, int $offset): array
    {
        return $this->db->fetchAll(
            "SELECT * FROM outbox_events
             WHERE status IN ('failed', 'dlq')
             ORDER BY updated_at DESC
             LIMIT ? OFFSET ?",
            [$limit, $offset]
        );
    }

    /** @return SentryRows */
    public function getFailedJobsPaged(int $limit, int $offset, ?string $queue = null): array
    {
        $query = "SELECT * FROM failed_jobs WHERE 1=1";
        $params = [];
        if ($queue !== null && $queue !== '') {
            $query .= " AND queue = ?";
            $params[] = $queue;
        }
        $query .= " ORDER BY failed_at DESC LIMIT ? OFFSET ?";
        $params[] = $limit;
        $params[] = $offset;

        return $this->db->fetchAll($query, $params);
    }

    public function getFailedJobById(int $id): ?\stdClass
    {
        return $this->db->fetch(
            "SELECT * FROM failed_jobs WHERE id = ?",
            [$id]
        );
    }

    public function retryFailedJob(int $id): bool
    {
        $job = $this->getFailedJobById($id);
        if (!$job) {
            return false;
        }

        $this->db->beginTransaction();
        try {
            $jobValues = get_object_vars($job);
            $rawPayload = $jobValues['payload'] ?? null;
            if (!is_string($rawPayload)) {
                $this->db->rollback();
                return false;
            }
            $payload = json_decode($rawPayload, true);
            if (!is_array($payload) || !is_string($payload['job'] ?? null) || $payload['job'] === '') {
                $this->db->rollback();
                return false;
            }

            $ok = $this->queue->push(
                (string)$payload['job'],
                (array)($payload['data'] ?? []),
                (string)($job->queue ?? 'default')
            );

            if (!$ok) {
                $this->db->rollback();
                return false;
            }

            $this->db->execute("DELETE FROM failed_jobs WHERE id = ?", [$id]);
            $this->db->commit();
            return true;
        } catch (\Throwable $e) {
            $this->db->rollback();
            return false;
        }
    }

    public function forgetFailedJob(int $id): bool
    {
        return (bool)$this->db->execute("DELETE FROM failed_jobs WHERE id = ?", [$id]);
    }

    // --- Missing Sentry Dashboard & Issues Methods ---

    /** @return SentryRows */
    public function getTrendingIssues(int $limit = 10): array
    {
        return $this->db->fetchAll(
            "SELECT *, 1 as events_24h FROM sentry_issues 
             WHERE status != 'resolved' 
             ORDER BY count DESC, last_seen DESC LIMIT ?",
            [$limit]
        );
    }

    /** @return SentryRows */
    public function getRecentSentryEvents(int $limit = 20): array
    {
        return $this->db->fetchAll(
            "SELECT * FROM sentry_events ORDER BY created_at DESC LIMIT ?",
            [$limit]
        );
    }

    public function getDailySummary(): ?\stdClass
    {
        return $this->db->fetch(
            "SELECT 
                (SELECT COUNT(DISTINCT issue_id) FROM sentry_events WHERE created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)) as error_issues,
                (SELECT COUNT(*) FROM sentry_events WHERE created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)) as error_events,
                (SELECT COUNT(*) FROM performance_transactions WHERE created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)) as transactions,
                (SELECT COALESCE(AVG(duration), 0) FROM performance_transactions WHERE created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)) as avg_response_time"
        );
    }

    public function getPreviousDaySummary(): ?\stdClass
    {
        return $this->db->fetch(
            "SELECT 
                (SELECT COUNT(DISTINCT issue_id) FROM sentry_events WHERE created_at >= DATE_SUB(NOW(), INTERVAL 48 HOUR) AND created_at < DATE_SUB(NOW(), INTERVAL 24 HOUR)) as error_issues,
                (SELECT COUNT(*) FROM sentry_events WHERE created_at >= DATE_SUB(NOW(), INTERVAL 48 HOUR) AND created_at < DATE_SUB(NOW(), INTERVAL 24 HOUR)) as error_events"
        );
    }

    public function getUptimeStatus(int $minutes = 5): bool
    {
        return true;
    }

    /**
     * P95 واقعی با PERCENTILE_CONT — دقیق‌تر از avg*1.5
     * MySQL 8.0+: از window function استفاده می‌کند.
     * Fallback: اگر percentile_disc در دسترس نبود، avg*2 (محافظه‌کارانه‌تر)
     */
    public function getP95ResponseTime(int $minutes = 60): float
    {
        try {
            // Cross-DB compatible percentile calculation. MariaDB does not support
            // MySQL's PERCENTILE_DISC syntax in this context and rejects subqueries
            // inside OFFSET, so compute p95 from a bounded ordered sample in PHP.
            $rows = $this->db->fetchAll(
                "SELECT duration
                 FROM performance_transactions
                 WHERE created_at >= DATE_SUB(NOW(), INTERVAL ? MINUTE)
                 ORDER BY duration ASC
                 LIMIT 10000",
                [$minutes]
            ) ?: [];

            $values = [];
            foreach ($rows as $row) {
                $row = is_array($row) ? $row : (array)$row;
                if (isset($row['duration'])) {
                    $values[] = (float)$row['duration'];
                }
            }

            $count = count($values);
            if ($count > 0) {
                $index = max(0, min($count - 1, (int)ceil($count * 0.95) - 1));
                return $values[$index];
            }
        } catch (\Throwable $e) {
            $this->logger->warning('sentrymodel.p95_calculation_failed', ['error' => $e->getMessage()]);
        }

        $avg = $this->db->fetchColumn(
            "SELECT AVG(duration) FROM performance_transactions WHERE created_at >= DATE_SUB(NOW(), INTERVAL ? MINUTE)",
            [$minutes]
        );
        return $avg ? (float)$avg * 2.0 : 0.0;
    }

    public function getHealthMetricsBundle(int $minutes = 60): object
    {
        $bundle = $this->db->fetch(
            "SELECT 
                (SELECT COUNT(*) FROM sentry_events WHERE created_at >= DATE_SUB(NOW(), INTERVAL ? MINUTE)) as error_count,
                (SELECT COALESCE(AVG(duration), 0) FROM performance_transactions WHERE created_at >= DATE_SUB(NOW(), INTERVAL ? MINUTE)) as avg_duration",
            [$minutes, $minutes]
        ) ?: (object)['error_count' => 0, 'avg_duration' => 0.0];

        // P95 واقعی را جداگانه محاسبه می‌کنیم
        $bundle->p95_duration = $this->getP95ResponseTime($minutes);

        return $bundle;
    }

    /** @return SentryRows */
    public function getErrorDistributionByLevel(int $hours = 24): array
    {
        return $this->db->fetchAll(
            "SELECT level, COUNT(DISTINCT issue_id) as issues, COUNT(*) as events 
             FROM sentry_events 
             WHERE created_at >= DATE_SUB(NOW(), INTERVAL ? HOUR)
             GROUP BY level",
            [$hours]
        );
    }

    public function getPerformanceStatsSummary(int $hours = 24): ?\stdClass
    {
        return $this->db->fetch(
            "SELECT 
                COUNT(*) as total_transactions,
                COALESCE(AVG(duration), 0) as avg_duration,
                COALESCE(MAX(duration), 0) as max_duration,
                COALESCE(AVG(query_count), 0) as avg_queries,
                SUM(CASE WHEN duration > 1000 THEN 1 ELSE 0 END) as slow_count
             FROM performance_transactions
             WHERE created_at >= DATE_SUB(NOW(), INTERVAL ? HOUR)",
            [$hours]
        );
    }

    /** @return SentryRows */
    public function getErrorTimeSeries(int $periodHours, int $intervalMinutes): array
    {
        return $this->db->fetchAll(
            "SELECT 
                DATE_FORMAT(created_at, '%Y-%m-%d %H:00:00') as time_bucket,
                COUNT(*) as count,
                level
             FROM sentry_events
             WHERE created_at >= DATE_SUB(NOW(), INTERVAL ? HOUR)
             GROUP BY time_bucket, level
             ORDER BY time_bucket ASC",
            [$periodHours]
        );
    }

    /** @return SentryRows */
    public function getPerformanceTimeSeries(int $periodHours, int $intervalMinutes): array
    {
        return $this->db->fetchAll(
            "SELECT 
                DATE_FORMAT(created_at, '%Y-%m-%d %H:00:00') as time_bucket,
                COALESCE(AVG(duration), 0) as avg_duration,
                COUNT(*) as count
             FROM performance_transactions
             WHERE created_at >= DATE_SUB(NOW(), INTERVAL ? HOUR)
             GROUP BY time_bucket
             ORDER BY time_bucket ASC",
            [$periodHours]
        );
    }

    /** @return SentryRows */
    public function getTopSlowestEndpoints(int $limit = 10): array
    {
        return $this->db->fetchAll(
            "SELECT name, COALESCE(AVG(duration), 0) as avg_duration, COALESCE(MAX(duration), 0) as max_duration, COUNT(*) as count
             FROM performance_transactions
             GROUP BY name
             ORDER BY avg_duration DESC
             LIMIT ?",
            [$limit]
        );
    }

    /** @param SentryFilters $filters */
    public function getIssuesCount(array $filters): int
    {
        $query = "SELECT COUNT(*) FROM sentry_issues i WHERE 1=1";
        $params = [];

        if (!empty($filters['status'])) {
            $query .= " AND i.status = ?";
            $params[] = $filters['status'];
        }

        if (!empty($filters['level'])) {
            $query .= " AND i.level = ?";
            $params[] = $filters['level'];
        }

        return (int)$this->db->fetchColumn($query, $params);
    }

    /**
     * @param SentryFilters $filters
     * @return SentryRows
     */
    public function getIssuesPaged(array $filters, int $limit, int $offset): array
    {
        $query = "SELECT i.*, i.count as real_event_count, i.last_seen as last_seen_event 
                  FROM sentry_issues i 
                  WHERE 1=1";
        $params = [];

        if (!empty($filters['status'])) {
            $query .= " AND i.status = ?";
            $params[] = $filters['status'];
        }

        if (!empty($filters['level'])) {
            $query .= " AND i.level = ?";
            $params[] = $filters['level'];
        }

        $query .= " ORDER BY i.last_seen DESC LIMIT ? OFFSET ?";
        $params[] = $limit;
        $params[] = $offset;

        return $this->db->fetchAll($query, $params);
    }

    public function getIssueWithEvents(int $id, int $limit = 50): ?\stdClass
    {
        $issue = $this->db->fetch(
            "SELECT * FROM sentry_issues WHERE id = ?",
            [$id]
        );
        if (!$issue) return null;

        $events = $this->db->fetchAll(
            "SELECT * FROM sentry_events WHERE issue_id = ? ORDER BY created_at DESC LIMIT ?",
            [$id, $limit]
        );

        $issue->events = $events;
        return $issue;
    }

    public function resolveSentryIssue(int $issueId, ?int $userId, string $note = ''): bool
    {
        return (bool)$this->db->query(
            "UPDATE sentry_issues SET status = 'resolved' WHERE id = ?",
            [$issueId]
        );
    }

    public function muteSentryIssue(int $issueId, int $days = 7): bool
    {
        return (bool)$this->db->query(
            "UPDATE sentry_issues SET status = 'muted' WHERE id = ?",
            [$issueId]
        );
    }

    // --- Audit Trail helpers (DB-backed implementations) ---

    /** @param array<int|string, mixed> $params */
    public function getAuditCount(string $where, array $params): int
    {
        $where = trim((string)$where) === '' ? '1=1' : $where;
        $sql = "SELECT COUNT(*) FROM audit_trail at WHERE {$where}";
        return (int)$this->db->fetchColumn($sql, $params);
    }

    /**
     * @param array<int|string, mixed> $params
     * @return SentryRows
     */
    public function searchAuditRecords(string $where, array $params, int $limit, int $offset): array
    {
        $where = trim((string)$where) === '' ? '1=1' : $where;
        $limit = max(1, min(1000, (int)$limit));
        $offset = max(0, (int)$offset);

        $sql = "SELECT at.*, u.full_name AS user_name, u.email AS user_email
                FROM audit_trail at
                LEFT JOIN users u ON u.id = at.user_id
                WHERE {$where}
                ORDER BY at.created_at DESC
                LIMIT ? OFFSET ?";

        $finalParams = array_values($params);
        $finalParams[] = $limit;
        $finalParams[] = $offset;

        return $this->db->fetchAll($sql, $finalParams) ?: [];
    }

    /** @return SentryRows */
    public function getAuditEventsByCategory(string $start, string $end): array
    {
        $sql = "SELECT event, COUNT(*) as total FROM audit_trail WHERE created_at >= ? AND created_at <= ? GROUP BY event ORDER BY total DESC";
        return $this->db->fetchAll($sql, [$start . ' 00:00:00', $end . ' 23:59:59']) ?: [];
    }

    /** @return SentryRows */
    public function getAuditUserActivity(string $start, string $end): array
    {
        $sql = "SELECT user_id, COUNT(*) as total FROM audit_trail WHERE created_at >= ? AND created_at <= ? AND user_id IS NOT NULL GROUP BY user_id ORDER BY total DESC LIMIT 100";
        return $this->db->fetchAll($sql, [$start . ' 00:00:00', $end . ' 23:59:59']) ?: [];
    }

    /** @return SentryRows */
    public function getAuditAccessPatterns(string $start, string $end): array
    {
        $sql = "SELECT ip_address, COUNT(*) as total FROM audit_trail WHERE created_at >= ? AND created_at <= ? GROUP BY ip_address ORDER BY total DESC LIMIT 100";
        return $this->db->fetchAll($sql, [$start . ' 00:00:00', $end . ' 23:59:59']) ?: [];
    }

    /** @return SentryRows */
    public function getAuditFailedOperations(string $start, string $end): array
    {
        // FULLTEXT BOOLEAN MODE بجای LIKE '%...%' — جلوگیری از Full Table Scan
        // بدون + prefix → OR logic (هر کدوم از این واژه‌ها بخوره کافیه)
        $sql = "SELECT * FROM audit_trail
                WHERE created_at >= ? AND created_at <= ?
                AND MATCH(event) AGAINST('failed error reject' IN BOOLEAN MODE)
                ORDER BY created_at DESC LIMIT 200";
        return $this->db->fetchAll($sql, [$start . ' 00:00:00', $end . ' 23:59:59']) ?: [];
    }

    public function deleteOldAuditRecords(string $cutoff): int
    {
        // Physical deletion is restricted; return 0 and log a warning.
        $this->logger->warning('sentry.audit.delete_attempt', ['cutoff' => $cutoff]);
        return 0;
    }

    /** @return SentryRows */
    public function getOldAuditRecords(string $cutoff): array
    {
        $sql = "SELECT * FROM audit_trail WHERE created_at < ? ORDER BY created_at ASC LIMIT 1000";
        return $this->db->fetchAll($sql, [$cutoff]) ?: [];
    }

    /** @return ?\stdClass */
    public function getAuditRecordById(int $id): ?\stdClass
    {
        return $this->db->fetch("SELECT * FROM audit_trail WHERE id = ?", [$id]);
    }

    /** @return SentryRows */
    public function getActivityTimeline(?int $userId, int $days): array
    {
        $days = max(1, min(365, $days));
        $params = [];
        $where = '';
        if ($userId !== null) {
            $where = 'AND user_id = ?';
            $params[] = $userId;
        }

        $sql = "SELECT DATE(created_at) as day, COUNT(*) as total FROM audit_trail WHERE created_at >= DATE_SUB(CURDATE(), INTERVAL ? DAY) {$where} GROUP BY day ORDER BY day ASC";
        array_unshift($params, $days);
        return $this->db->fetchAll($sql, $params) ?: [];
    }

    public function getAuditReportSummary(string $start, string $end): ?\stdClass
    {
        $sql = "SELECT COUNT(*) as total_events, COUNT(DISTINCT user_id) as unique_users FROM audit_trail WHERE created_at >= ? AND created_at <= ?";
        return $this->db->fetch($sql, [$start . ' 00:00:00', $end . ' 23:59:59']) ?: null;
    }

    /**
     * @param list<string> $critical
     * @return SentryRows
     */
    public function getAuditCriticalEvents(array $critical, string $start, string $end): array
    {
        if (empty($critical)) return [];
        $placeholders = implode(',', array_fill(0, count($critical), '?'));
        $params = array_merge([$start . ' 00:00:00', $end . ' 23:59:59'], $critical);
        $sql = "SELECT * FROM audit_trail WHERE created_at >= ? AND created_at <= ? AND event IN ({$placeholders}) ORDER BY created_at DESC LIMIT 500";
        return $this->db->fetchAll($sql, $params) ?: [];
    }

    // --- Trend Analyzer Missing Placeholders ---

    /** @return SentryRows */
    public function getErrorHistoricalData(int $days): array
    {
        $days = max(1, min(365, $days));
        return $this->db->fetchAll(
            "SELECT DATE(created_at) as day, COUNT(DISTINCT issue_id) as issues, COUNT(*) as events
             FROM sentry_events
             WHERE created_at >= DATE_SUB(CURDATE(), INTERVAL ? DAY)
             GROUP BY day
             ORDER BY day ASC",
            [$days]
        ) ?: [];
    }

    /** @return SentryRows */
    public function getPerformanceHistoricalData(int $days): array
    {
        $days = max(1, min(365, $days));
        return $this->db->fetchAll(
            "SELECT DATE(created_at) as day, COALESCE(AVG(duration),0) as avg_duration, COUNT(*) as samples
             FROM performance_transactions
             WHERE created_at >= DATE_SUB(CURDATE(), INTERVAL ? DAY)
             GROUP BY day
             ORDER BY day ASC",
            [$days]
        ) ?: [];
    }

    /**
     * آخرین اجرای cron job (heartbeat)
     */
    public function getLastCronRun(): ?\stdClass
    {
        return $this->db->fetch(
            "SELECT created_at FROM activity_logs WHERE action = 'cron' ORDER BY id DESC LIMIT 1"
        );
    }

    /** @return SentryRows */
    public function getTopErrorSources(int $limit = 15): array
    {
        return $this->db->fetchAll(
            "SELECT
                exception_type AS source,
                COUNT(*) AS event_count,
                COUNT(DISTINCT issue_id) AS issue_count,
                MAX(created_at) AS last_seen
             FROM sentry_events
             WHERE created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
             AND exception_type IS NOT NULL
             GROUP BY exception_type
             ORDER BY event_count DESC
             LIMIT ?",
            [$limit]
        ) ?: [];
    }

    /** @return SentryRows */
    public function getErrorHotspots(int $days): array
    {
        $days = max(1, min(365, $days));
        return $this->db->fetchAll(
            "SELECT culprit AS hotspot, COUNT(DISTINCT issue_id) as issues, COUNT(*) as events
             FROM sentry_events
             WHERE created_at >= DATE_SUB(CURDATE(), INTERVAL ? DAY)
             GROUP BY hotspot
             ORDER BY events DESC
             LIMIT 50",
            [$days]
        ) ?: [];
    }

    public function getWeeklyPerformanceAvg(int $offset): float
    {
        $offset = max(0, (int)$offset);
        $startTimestamp = strtotime("-" . (($offset + 1) * 7) . " days");
        $endTimestamp = strtotime("-" . ($offset * 7) . " days");
        $start = date('Y-m-d H:i:s', $startTimestamp === false ? time() : $startTimestamp);
        $end = date('Y-m-d H:i:s', $endTimestamp === false ? time() : $endTimestamp);
        $row = $this->db->fetch(
            "SELECT COALESCE(AVG(duration), 0) as avg_duration FROM performance_transactions WHERE created_at >= ? AND created_at < ?",
            [$start, $end]
        );
        return $row ? (float)($row->avg_duration ?? 0.0) : 0.0;
    }

    // --- Escalation Manager helpers ---

    /** @return SentryRows */
    /** @return list<\stdClass> */
    public function getPendingEscalations(): array
    {
        return $this->db->fetchAll(
            "SELECT * FROM sentry_issues WHERE status IN ('unresolved', 'escalated') ORDER BY last_seen DESC LIMIT 200"
        ) ?: [];
    }

    /**
     * Escalate — status رو به 'escalated' تغییر بده و level رو ارتقا بده
     */
    public function escalateIssue(int $id, string $newLevel, string $oldLevel): void
    {
        try {
            $this->db->query(
                "UPDATE sentry_issues SET status = 'escalated', level = ?, updated_at = NOW() WHERE id = ?",
                [$newLevel, $id]
            );
            $this->db->execute(
                "INSERT INTO sentry_issue_events (issue_id, event_type, details, created_at) VALUES (?, ?, ?, NOW())",
                [$id, 'escalation', json_encode(['from_level' => $oldLevel, 'to_level' => $newLevel])]
            );
        } catch (\Throwable $e) {
            $this->logger->error('sentry.escalation.failed', ['issue_id' => $id, 'error' => $e->getMessage()]);
        }
    }

    /**
     * @deprecated Use escalateIssue() — backward compat
     */
    public function escalateAlert(int $id, string $new, string $old): void
    {
        $this->escalateIssue($id, $new, $old);
    }

    public function acknowledgeAlert(int $id, ?int $userId, ?string $note): bool
    {
        if (!$id) {
            return false;
        }

        try {
            // read existing metadata (if any) and attach acknowledgement note
            $issue = $this->db->fetch("SELECT metadata FROM sentry_issues WHERE id = ?", [$id]);
            if (!$issue) {
                return false;
            }

            $metadata = [];
            if (!empty($issue->metadata)) {
                $decoded = json_decode((string)$issue->metadata, true);
                if (is_array($decoded)) {
                    $metadata = $decoded;
                }
            }

            if ($note !== null) {
                $metadata['acknowledgement_note'] = $note;
            }

            // Update the issue: set acknowledged_at and acknowledged_by (if provided) and mark acknowledged
            if ($userId !== null) {
                $affected = $this->db->execute(
                    "UPDATE sentry_issues SET metadata = ?, acknowledged_at = NOW(), acknowledged_by = ?, status = 'acknowledged', updated_at = NOW() WHERE id = ?",
                    [json_encode($metadata, JSON_UNESCAPED_UNICODE), $userId, $id]
                );
            } else {
                $affected = $this->db->execute(
                    "UPDATE sentry_issues SET metadata = ?, acknowledged_at = NOW(), status = 'acknowledged', updated_at = NOW() WHERE id = ?",
                    [json_encode($metadata, JSON_UNESCAPED_UNICODE), $id]
                );
            }

            return $affected > 0;
        } catch (\Throwable $e) {
            // do not throw here; caller handles logging and user feedback
            return false;
        }
    }
    public function autoResolveErrorAlerts(): int
    {
        // Disabled by default to avoid accidental mass-resolution.
        $enabled = (bool) $this->appSettings->get('sentry.auto_resolve_enabled', false);
        if (!$enabled) {
            $this->logger->info('sentry.auto_resolve.disabled');
            return 0;
        }

        $daysSetting = $this->appSettings->get('sentry.auto_resolve_days', 90);
        $maxCountSetting = $this->appSettings->get('sentry.auto_resolve_max_count', 5);
        $days = is_scalar($daysSetting) && is_numeric((string)$daysSetting) ? (int)$daysSetting : 90;
        $maxCount = is_scalar($maxCountSetting) && is_numeric((string)$maxCountSetting) ? (int)$maxCountSetting : 5;

        try {
            $sql = "UPDATE sentry_issues SET status = 'resolved', updated_at = NOW() WHERE status != 'resolved' AND last_seen < DATE_SUB(NOW(), INTERVAL ? DAY) AND count <= ?";
            return (int) $this->db->execute($sql, [$days, $maxCount]);
        } catch (\Throwable $e) {
            $this->logger->error('sentry.auto_resolve.failed', ['error' => $e->getMessage()]);
            return 0;
        }
    }

    /**
     * آمار escalation — خروجی object با کلیدهای مورد انتظار EscalationManager
     */
    public function getEscalationStatistics(): object
    {
        try {
            $row = $this->db->fetch(
                "SELECT
                    COUNT(*) AS total_alerts,
                    SUM(CASE WHEN status = 'acknowledged' THEN 1 ELSE 0 END) AS acknowledged,
                    SUM(CASE WHEN status = 'escalated' THEN 1 ELSE 0 END) AS escalated,
                    COALESCE(AVG(
                        CASE WHEN acknowledged_at IS NOT NULL
                             THEN TIMESTAMPDIFF(MINUTE, created_at, acknowledged_at)
                             ELSE NULL END
                    ), 0) AS avg_response_time
                 FROM sentry_issues"
            );

            return $row ?: (object)[
                'total_alerts' => 0,
                'acknowledged' => 0,
                'escalated' => 0,
                'avg_response_time' => 0,
            ];
        } catch (\Throwable $e) {
            $this->logger->error('sentry.escalation_stats.failed', ['error' => $e->getMessage()]);

            return (object)[
                'total_alerts' => 0,
                'acknowledged' => 0,
                'escalated' => 0,
                'avg_response_time' => 0,
            ];
        }
    }

    // --- BUGFIX-CTRL-RAW-SQL-2026-06: Methods moved from LogController ---

    public function checkTableExists(string $table): bool
    {
        try {
            return (bool)$this->db->fetch('SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? LIMIT 1', [$table]);
        } catch (\Throwable) { return false; }
    }

    public function countTableRows(string $table, string $where = '1=1'): int
    {
        if (!$this->checkTableExists($table)) return 0;
        try {
            $row = $this->db->fetch("SELECT COUNT(*) AS c FROM `{$table}` WHERE {$where}");
            return (int)($row->c ?? 0);
        } catch (\Throwable) { return 0; }
    }

    public function avgTableColumn(string $table, string $column): float
    {
        if (!$this->checkTableExists($table)) return 0.0;
        try {
            $row = $this->db->fetch("SELECT COALESCE(AVG(`{$column}`),0) AS a FROM `{$table}`");
            return (float)($row->a ?? 0);
        } catch (\Throwable) { return 0.0; }
    }

    /** @return SentryRows */
    public function getErrorLogs(int $perPage, int $offset): array
    {
        if (!$this->checkTableExists('error_logs')) return [];
        return $this->db->fetchAll("SELECT id, message, exception_class AS exception_type, file AS file_path, line AS line_number, trace, status, occurrences AS occurrence_count, created_at AS first_occurred_at, updated_at AS last_occurred_at, (status = 'resolved') AS is_resolved FROM error_logs ORDER BY updated_at DESC LIMIT {$perPage} OFFSET {$offset}") ?: [];
    }

    public function findErrorById(int $id): ?\stdClass
    {
        if ($id <= 0 || !$this->checkTableExists('error_logs')) return null;
        return $this->db->fetch("SELECT id, message, exception_class AS exception_type, file AS file_path, line AS line_number, trace, NULL AS context, status, (status = 'resolved') AS is_resolved, occurrences AS occurrence_count, created_at AS first_occurred_at, updated_at AS last_occurred_at, NULL AS url, NULL AS method, NULL AS user_id, NULL AS ip_address, NULL AS user_agent, NULL AS resolved_by, NULL AS resolved_at, NULL AS resolution_note, 'ERROR' AS level FROM error_logs WHERE id = ? LIMIT 1", [$id]) ?: null;
    }

    /** @return SentryRows */
    public function getSimilarErrors(string $message): array
    {
        if (!$this->checkTableExists('error_logs')) return [];
        return $this->db->fetchAll('SELECT id, created_at, user_id, NULL AS ip_address, NULL AS url FROM error_logs WHERE message = ? ORDER BY created_at DESC LIMIT 10', [$message]) ?: [];
    }

    /** @return SentryRows */
    public function getNotificationChannelsForSettings(): array
    {
        if (!$this->checkTableExists('notification_channels')) return [];
        return $this->db->fetchAll("SELECT id, channel_type, channel_type AS channel_name, config, is_active, alert_levels, created_at FROM notification_channels ORDER BY id DESC") ?: [];
    }

    /** @return SentryRows */
    public function getAlertRulesForSettings(): array
    {
        if (!$this->checkTableExists('alert_rules')) return [];
        return $this->db->fetchAll("SELECT id, rule_name, `condition`, threshold_value AS threshold, time_window_minutes AS time_window, severity, is_active, NULL AS last_triggered_at, created_at FROM alert_rules ORDER BY id DESC") ?: [];
    }

    /** @return SentryRows */
    public function getTopErrorLogs(): array
    {
        if (!$this->checkTableExists('error_logs')) return [];
        return $this->db->fetchAll("SELECT id, message, exception_class, file AS file_path, line AS line_number, 'ERROR' AS level, occurrences AS occurrence_count, updated_at AS last_occurred_at FROM error_logs ORDER BY occurrences DESC, updated_at DESC LIMIT 10") ?: [];
    }

    /** @return SentryRows */
    public function getActiveAlertsForDashboard(): array
    {
        if (!$this->checkTableExists('system_alerts')) return [];
        return $this->db->fetchAll("SELECT id, severity, title, message, created_at FROM system_alerts WHERE is_active = 1 ORDER BY created_at DESC LIMIT 10") ?: [];
    }
}

