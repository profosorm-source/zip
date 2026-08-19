<?php

/**
 * 🕐 Sentry Cron Jobs
 * 
 * این فایل باید به cron اضافه بشه:
 * 
 * *\/5 * * * * php /path/to/cron/sentry-monitor.php
 */

require_once __DIR__ . '/../../bootstrap/app.php';

use Core\Database;
use App\Services\Sentry\Alerting\AlertRulesEngine;
use App\Services\Sentry\Alerting\EscalationManager;
use App\Services\Sentry\Audit\AdvancedAuditTrail;
use App\Services\Sentry\Analytics\DashboardService;

$logger = logger();
$db = Database::getInstance();

try {
    $logger->info('Sentry cron job started', [
        'channel' => 'cron-sentry',
    ]);

    // ==========================================
    // 1. Evaluate Alert Rules (هر 5 دقیقه)
    // ==========================================
    try {
        $alertRules = app(AlertRulesEngine::class);
        $triggered = $alertRules->evaluateAllRules();

        if (!empty($triggered)) {
            $logger->info('Triggered alert rules', [
                'channel' => 'cron-sentry',
                'count' => count($triggered),
            ]);
        }
    } catch (\Throwable $e) {
        $logger->error('sentry.alert_rules.evaluation.failed', [
            'channel' => 'cron-sentry',
            'error' => $e->getMessage(),
            'exception' => get_class($e),
            'file' => $e->getFile(),
            'line' => $e->getLine(),
        ]);
    }

    // ==========================================
    // 2. Process Escalations (هر 5 دقیقه)
    // ==========================================
    try {
        $escalation = app(EscalationManager::class);
        $escalated = $escalation->processEscalations();
        
        if (!empty($escalated)) {
            $logger->info('Escalated ' . count($escalated) . ' alerts');
        }

        // Auto-resolve alerts
        $resolved = $escalation->autoResolveAlerts();
        if ($resolved > 0) {
            $logger->info("Auto-resolved {$resolved} alerts");
        }
    } catch (\Throwable $e) {
        $logger->error('Escalation processing failed', ['error' => $e->getMessage()]);
    }

    // ==========================================
    // 3. Cleanup Old Data (یک بار در روز - 2 صبح)
    // ==========================================
    $currentHour = (int)date('H');
    if ($currentHour === 2) {
        try {
            // پاکسازی Events قدیمی (90 روز)
            $deleted = $db->query(
                "DELETE FROM sentry_events 
                 WHERE created_at < DATE_SUB(NOW(), INTERVAL 90 DAY)"
            )->rowCount();
            
            if ($deleted > 0) {
                $logger->info("Deleted {$deleted} old events");
            }

            // پاکسازی Performance Transactions قدیمی (30 روز)
            $deleted = $db->query(
                "DELETE FROM performance_transactions 
                 WHERE created_at < DATE_SUB(NOW(), INTERVAL 30 DAY)"
            )->rowCount();
            
            if ($deleted > 0) {
                $logger->info("Deleted {$deleted} old transactions");
            }

            // پاکسازی Audit Trail (retention policy)
            $audit = app(AdvancedAuditTrail::class);
            $deleted = $audit->cleanupOldRecords();
            if ($deleted > 0) {
                $logger->info("Deleted {$deleted} old audit records");
            }

        } catch (\Throwable $e) {
            $logger->error('Cleanup failed', ['error' => $e->getMessage()]);
        }
    }

    // ==========================================
    // 4. Calculate Daily Statistics (نیمه‌شب)
    // ==========================================
    if ($currentHour === 0) {
        try {
            $yesterday = date('Y-m-d', strtotime('-1 day'));
            
            // محاسبه آمار روزانه
            $stats = $db->query(
                "SELECT 
                    COUNT(DISTINCT issue_id) as total_errors,
                    SUM(CASE WHEN level IN ('critical', 'fatal') THEN 1 ELSE 0 END) as critical_errors,
                    (SELECT COUNT(*) FROM performance_transactions WHERE DATE(created_at) = ?) as total_requests,
                    (SELECT COUNT(*) FROM performance_transactions WHERE DATE(created_at) = ? AND duration > 1000) as slow_requests,
                    (SELECT AVG(duration) FROM performance_transactions WHERE DATE(created_at) = ?) as avg_response_time,
                    (SELECT COUNT(DISTINCT user_id) FROM user_sessions WHERE DATE(last_activity) = ?) as unique_users
                 FROM sentry_events
                 WHERE DATE(created_at) = ?",
                [$yesterday, $yesterday, $yesterday, $yesterday, $yesterday]
            )->fetch(\PDO::FETCH_OBJ);

            // ذخیره در daily_statistics
            $db->query(
                "INSERT INTO daily_statistics (
                    stat_date, total_errors, critical_errors, total_requests,
                    slow_requests, avg_response_time, unique_users
                ) VALUES (?, ?, ?, ?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE
                    total_errors = VALUES(total_errors),
                    critical_errors = VALUES(critical_errors),
                    total_requests = VALUES(total_requests),
                    slow_requests = VALUES(slow_requests),
                    avg_response_time = VALUES(avg_response_time),
                    unique_users = VALUES(unique_users)",
                [
                    $yesterday,
                    $stats->total_errors ?? 0,
                    $stats->critical_errors ?? 0,
                    $stats->total_requests ?? 0,
                    $stats->slow_requests ?? 0,
                    $stats->avg_response_time ?? 0,
                    $stats->unique_users ?? 0,
                ]
            );

            $logger->info('Daily statistics calculated for ' . $yesterday);

        } catch (\Throwable $e) {
            $logger->error('Statistics calculation failed', ['error' => $e->getMessage()]);
        }
    }

    // ==========================================
    // 5. Health Check Monitoring (هر 5 دقیقه)
    // ==========================================
    try {
        $dashboard = app(DashboardService::class);
        $health = $dashboard->calculateHealthScore();

        // اگر Health Score پایین بیاد، alert بفرست
        if ($health['score'] < 60) {
            $alertDispatcher = app(\App\Services\Sentry\Alerting\AlertDispatcher::class);
            $alertDispatcher->dispatch([
                'type' => 'system',
                'severity' => $health['score'] < 40 ? 'critical' : 'high',
                'title' => '⚠️ System Health Score Low',
                'message' => "Health score dropped to {$health['score']} (Grade: {$health['grade']})",
                'metadata' => [
                    'health_score' => $health['score'],
                    'components' => $health['components'],
                ],
            ]);
        }

    } catch (\Throwable $e) {
        $logger->error('Health check failed', ['error' => $e->getMessage()]);
    }

    // ==========================================
// 6. Unmute Expired Issues (هر ساعت)
// ==========================================
$currentMinute = (int) date('i');
if ($currentMinute < 5) { // فقط در 5 دقیقه اول هر ساعت
    try {
        $unmuted = $db->query(
            "UPDATE sentry_issues
             SET status = 'unresolved'
             WHERE status = 'muted'
             AND JSON_EXTRACT(metadata, '$.muted_until') < NOW()"
        )->rowCount();

        if ($unmuted > 0) {
            $logger->info('sentry.issues.unmuted', [
                'channel' => 'cron-sentry',
                'count' => $unmuted,
            ]);
        }
    } catch (\Throwable $e) {
        $logger->error('sentry.issues.unmute.failed', [
            'channel' => 'cron-sentry',
            'error' => $e->getMessage(),
            'exception' => get_class($e),
            'file' => $e->getFile(),
            'line' => $e->getLine(),
        ]);
    }
}

$logger->info('sentry.cron.completed', [
    'channel' => 'cron-sentry',
]);

} catch (\Throwable $e) {
    $logger->critical('sentry.cron.failed', [
        'channel' => 'cron-sentry',
        'error' => $e->getMessage(),
        'exception' => get_class($e),
        'file' => $e->getFile(),
        'line' => $e->getLine(),
    ]);
    exit(1);
}

exit(0);
