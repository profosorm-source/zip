<?php

declare(strict_types=1);

namespace App\Services\Sentry\Alerting;

use App\Models\SentryModel;
use Core\Logger;

/**
 * 📈 EscalationManager - مدیریت Escalation
 */
class EscalationManager
{


    private SentryModel $model;
    private Logger $logger;
    private AlertDispatcher $dispatcher;

    public function __construct(
        SentryModel $model,
        Logger $logger,
        AlertDispatcher $dispatcher
    ) {
        $this->model = $model;
        $this->logger = $logger;
        $this->dispatcher = $dispatcher;
    }

    /**
     * 🔄 Process Escalations
     */
    /**
     * @return list<\stdClass>
     */
    public function processEscalations(): array
    {
        $escalated = [];
        $pendingIssues = $this->model->getPendingEscalations();

        foreach ($pendingIssues as $issue) {
            if ($this->shouldEscalate($issue)) {
                $this->escalateIssue($issue);
                $escalated[] = $issue;
            }
        }

        return $escalated;
    }

    /**
     * آیا این issue باید escalate بشه؟
     * از $issue->level استفاده میشه (ستون level در sentry_issues)
     */
    private function shouldEscalate(\stdClass $issue): bool
    {
        // اگه قبلاً acknowledge شده، escalate نکن
        if (!empty($issue->acknowledged_at) ||
            (isset($issue->status) && $issue->status === 'acknowledged') ||
            !empty($issue->is_acknowledged)) {
            return false;
        }

        // sentry_issues ستون first_seen/last_seen/created_at داره
        $createdAt = $issue->first_seen ?? $issue->created_at ?? null;
        if (!$createdAt) {
            return false;
        }

        $age = time() - strtotime((string)$createdAt);

        // بر اساس level (نه severity) تایم escalation مشخص میشه
        $escalationTime = match($issue->level ?? 'warning') {
            'critical', 'fatal' => 5 * 60,      // 5 دقیقه
            'error'             => 15 * 60,      // 15 دقیقه
            'warning'           => 60 * 60,      // 1 ساعت
            'info'              => 4 * 60 * 60,  // 4 ساعت
            default             => 60 * 60
        };

        return $age > $escalationTime;
    }

    /**
     * Escalate — status رو به 'escalated' تغییر بده و level رو بالا ببر
     */
    private function escalateIssue(\stdClass $issue): void
    {
        try {
            $currentLevel = (string)($issue->level ?? 'warning');
            $newLevel = $this->getNextLevel($currentLevel);

            $this->model->escalateIssue((int)$issue->id, $newLevel, $currentLevel);

            $this->dispatcher->dispatch([
                'type' => 'escalation',
                'severity' => $this->mapLevelToSeverity($newLevel),
                'title' => "🚨 Escalated: {$issue->title}",
                'message' => $this->formatEscalationMessage($issue, $currentLevel, $newLevel),
                'metadata' => [
                    'original_level' => $currentLevel,
                    'new_level' => $newLevel,
                    'issue_id' => $issue->id,
                    'age_minutes' => round((time() - strtotime((string)($issue->first_seen ?? $issue->created_at))) / 60),
                ],
            ]);

            $this->logger->warning('Issue escalated', [
                'issue_id' => $issue->id,
                'from' => $currentLevel,
                'to' => $newLevel,
            ]);

        } catch (\Throwable $e) {
            $this->logger->error('Escalation failed', ['issue_id' => $issue->id, 'error' => $e->getMessage()]);
        }
    }

    /**
     * ارتقای level: warning → error → critical
     */
    private function getNextLevel(string $current): string
    {
        return match($current) {
            'info'     => 'warning',
            'warning'  => 'error',
            'error'    => 'critical',
            'critical' => 'critical',
            'fatal'    => 'fatal',
            default    => 'error'
        };
    }

    /**
     * map level to alert severity
     */
    private function mapLevelToSeverity(string $level): string
    {
        return match($level) {
            'critical', 'fatal' => 'critical',
            'error' => 'high',
            'warning' => 'medium',
            default => 'low'
        };
    }

    private function formatEscalationMessage(\stdClass $issue, string $oldLevel, string $newLevel): string
    {
        $createdAt = $issue->first_seen ?? $issue->created_at ?? 'unknown';
        $age = is_string($createdAt) ? round((time() - strtotime($createdAt)) / 60) : 0;

        return sprintf(
            "⚠️ Issue escalated from %s to %s\n\nIssue: %s\nAge: %d minutes\nOccurrences: %s\nStatus: Unacknowledged\n\nPlease investigate immediately!",
            strtoupper((string)$oldLevel),
            strtoupper((string)$newLevel),
            $issue->title ?? 'Unknown',
            $age,
            $issue->count ?? 'N/A'
        );
    }

    public function acknowledgeAlert(int $alertId, int $userId, ?string $note = null): bool
    {
        try {
            $this->model->acknowledgeAlert($alertId, $userId, $note);
            $this->logger->info('Alert acknowledged', ['alert_id' => $alertId, 'user_id' => $userId]);
            return true;
        } catch (\Throwable $e) {
            $this->logger->error('Acknowledge failed', ['alert_id' => $alertId, 'error' => $e->getMessage()]);
            return false;
        }
    }

    public function autoResolveAlerts(): int
    {
        try {
            $resolved = $this->model->autoResolveErrorAlerts();
            if ($resolved > 0) {
                $this->logger->info("Auto-resolved {$resolved} alerts");
            }
            return $resolved;
        } catch (\Throwable $e) {
            $this->logger->error('Auto-resolve failed', ['error' => $e->getMessage()]);
            return 0;
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function getStatistics(): array
    {
        $stats = $this->model->getEscalationStatistics();

        return [
            'total_alerts' => (int)($stats->total_alerts ?? 0),
            'acknowledged' => (int)($stats->acknowledged ?? 0),
            'escalated' => (int)($stats->escalated ?? 0),
            'avg_response_time_minutes' => round(floatval($stats->avg_response_time ?? 0), 2),
        ];
    }
}
