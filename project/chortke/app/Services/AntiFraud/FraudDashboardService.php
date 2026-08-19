<?php

declare(strict_types=1);

namespace App\Services\AntiFraud;

use App\Models\FraudAnalyticsModel;
use App\Contracts\LoggerInterface;

/**
 * @phpstan-type Overview array{total_frauds: int, active_alerts: int, blocked_users: int, total_sessions: int, detection_rate_percent: float|int, period_hours: int, high_risk_users: int, blacklisted_users: int, today_suspicious: int, today_rejected: int}
 * @phpstan-type DistributionRow array{type: string, count: int, avg_risk_score: float}
 * @phpstan-type HourlyTrendRow array{hour: string, count: int, avg_risk: float}
 * @phpstan-type GeographicThreatRow array{country_code: string, country_name: string, fraud_count: int, affected_users: int, avg_risk_score: float}
 * @phpstan-type SuspiciousUserRow array{user_id: int, username: string, email: string, fraud_count: int, avg_risk_score: float, last_fraud_at: mixed, fraud_score: int, is_blacklisted: bool}
 * @phpstan-type SuspiciousIpRow array{ip_address: string, user_count: int, fraud_count: int, avg_risk_score: float, last_seen: mixed}
 */
class FraudDashboardService
{
    public function __construct(
        private LoggerInterface $logger,
        private FraudAnalyticsModel $model
    ) {}

    /** helper: log + Sentry برای خطاهای dashboard — بدون کرش کردن UI */
    private function safeCapture(\Throwable $e, string $operation): void
    {
        $this->logger->warning("fraud_dashboard.{$operation}.failed", ['error' => $e->getMessage()]);
        \App\Services\Sentry\SentryExceptionHandler::captureException($e, null, ['operation' => "fraud_dashboard.{$operation}"]);
    }

    /** @return Overview */
    public function getOverview(int $hours = 24): array
    {
        try { $counts = $this->model->getOverviewCounts(date('Y-m-d H:i:s', time() - ($hours * 3600))); }
        catch (\Throwable $e) { $this->safeCapture($e, 'getOverview'); $counts = (object)[]; }
        $sessions = (int)($counts->total_sessions ?? 0);
        $frauds = (int)($counts->total_frauds ?? 0);
        return [
            'total_frauds' => $frauds,
            'active_alerts' => (int)($counts->active_alerts ?? 0),
            'blocked_users' => (int)($counts->blocked_users ?? 0),
            'total_sessions' => $sessions,
            'detection_rate_percent' => $sessions > 0 ? round(($frauds / $sessions) * 100, 2) : 0,
            'period_hours' => $hours,
            'high_risk_users' => (int)($counts->high_risk_users ?? 0),
            'blacklisted_users' => (int)($counts->blacklisted_users ?? 0),
            'today_suspicious' => (int)($counts->today_suspicious ?? $frauds),
            'today_rejected' => (int)($counts->today_rejected ?? 0),
        ];
    }

    /** @return list<\stdClass> */
    public function getRecentAlerts(int $limit = 50, ?string $severity = null): array
    {
        try { $alerts = $this->model->getRecentAlerts($limit, $severity); } catch (\Throwable $e) { $this->safeCapture($e, 'getRecentAlerts'); $alerts = []; }
        return array_map(fn($a) => (object)[
            'id' => $a->id ?? 0,
            'type' => $a->alert_type ?? 'unknown',
            'action' => $a->alert_type ?? 'unknown',
            'severity' => $a->severity ?? 'low',
            'user_id' => $a->user_id ?? null,
            'full_name' => $a->full_name ?? '—',
            'email' => $a->email ?? '—',
            'title' => $a->title ?? '',
            'description' => $a->description ?? '',
            'details' => (array)(json_decode($a->details ?? '{}', true) ?? []) ?: [],
            'status' => $a->status ?? 'open',
            'created_at' => $a->created_at ?? null,
        ], $alerts ?: []);
    }

    /** @return list<DistributionRow> */
    public function getFraudTypeDistribution(int $hours = 24): array
    {
        try { $rows = $this->model->getFraudTypeDistribution(date('Y-m-d H:i:s', time() - ($hours * 3600))); } catch (\Throwable $e) { $this->safeCapture($e, 'getFraudTypeDistribution'); $rows = []; }
        return array_map(fn($r) => ['type'=>$r->fraud_type ?? 'unknown','count'=>(int)($r->count ?? 0),'avg_risk_score'=>round(floatval($r->avg_risk_score ?? 0),2)], $rows ?: []);
    }

    /** @return list<HourlyTrendRow> */
    public function getHourlyTrend(int $hours = 24): array
    {
        try { $rows = $this->model->getHourlyTrend(date('Y-m-d H:i:s', time() - ($hours * 3600))); } catch (\Throwable $e) { $this->safeCapture($e, 'getHourlyTrend'); $rows = []; }
        return array_map(fn($r) => ['hour'=>$r->hour ?? '', 'count'=>(int)($r->count ?? 0), 'avg_risk'=>round(floatval($r->avg_risk ?? 0),2)], $rows ?: []);
    }

    /** @return list<GeographicThreatRow> */
    public function getGeographicThreats(int $hours = 24): array
    {
        try { $rows = $this->model->getGeographicThreats(date('Y-m-d H:i:s', time() - ($hours * 3600))); } catch (\Throwable $e) { $this->safeCapture($e, 'getGeographicThreats'); $rows = []; }
        return array_map(fn($r) => ['country_code'=>$r->country ?? 'XX','country_name'=>$r->country_name ?? 'Unknown','fraud_count'=>(int)($r->fraud_count ?? 0),'affected_users'=>(int)($r->affected_users ?? 0),'avg_risk_score'=>round(floatval($r->avg_risk_score ?? 0),2)], $rows ?: []);
    }

    /** @return list<SuspiciousUserRow> */
    public function getTopSuspiciousUsers(int $limit = 20): array
    {
        try { $rows = $this->model->getTopSuspiciousUsers($limit); } catch (\Throwable $e) { $this->safeCapture($e, 'getTopSuspiciousUsers'); $rows = []; }
        return array_map(fn($r) => ['user_id'=>(int)($r->id ?? 0),'username'=>$r->username ?? '','email'=>$r->email ?? '','fraud_count'=>(int)($r->fraud_count ?? 0),'avg_risk_score'=>round(floatval($r->avg_risk_score ?? 0),2),'last_fraud_at'=>$r->last_fraud_at ?? null,'fraud_score'=>(int)($r->fraud_score ?? 0),'is_blacklisted'=>(bool)($r->is_blacklisted ?? false)], $rows ?: []);
    }

    /** @return list<SuspiciousIpRow> */
    public function getTopSuspiciousIPs(int $limit = 20): array
    {
        try { $rows = $this->model->getTopSuspiciousIPs($limit); } catch (\Throwable $e) { $this->safeCapture($e, 'getTopSuspiciousIPs'); $rows = []; }
        return array_map(fn($r) => ['ip_address'=>$r->ip_address ?? '', 'user_count'=>(int)($r->user_count ?? 0), 'fraud_count'=>(int)($r->fraud_count ?? 0), 'avg_risk_score'=>round(floatval($r->avg_risk_score ?? 0),2), 'last_seen'=>$r->last_seen ?? null], $rows ?: []);
    }

    /** @return list<array{action: string, violation_count: int, unique_identifiers: int}> */
    public function getRateLimitViolations(int $hours = 24): array
    {
        try { $rows = $this->model->getRateLimitViolations(date('Y-m-d H:i:s', time() - ($hours * 3600))); } catch (\Throwable $e) { $this->safeCapture($e, 'getRateLimitViolations'); $rows = []; }
        return array_map(fn($r) => ['action'=>$r->action ?? '', 'violation_count'=>(int)($r->count ?? 0), 'unique_identifiers'=>(int)($r->unique_identifiers ?? 0)], $rows ?: []);
    }

    /** @return array{total_devices: int, emulator_count: int, vm_count: int, automation_count: int, avg_risk_score: float, emulator_percentage: float|int} */
    public function getDeviceStats(int $hours = 24): array
    {
        try { $r = $this->model->getDeviceStats(date('Y-m-d H:i:s', time() - ($hours * 3600))); } catch (\Throwable $e) { $this->safeCapture($e, 'getDeviceStats'); $r = (object)[]; }
        $total = (int)($r->total ?? 0);
        return ['total_devices'=>$total,'emulator_count'=>(int)($r->emulator_count ?? 0),'vm_count'=>(int)($r->vm_count ?? 0),'automation_count'=>(int)($r->automation_count ?? 0),'avg_risk_score'=>round(floatval($r->avg_risk_score ?? 0),2),'emulator_percentage'=>$total>0?round(((int)($r->emulator_count ?? 0)/$total)*100,2):0];
    }

    /** @param array<string, mixed>|null $details */
    public function createAlert(string $alertType, string $severity, string $title, ?int $userId = null, ?string $description = null, ?array $details = null): int
    { return (int)$this->model->createAlert(['alert_type'=>$alertType,'severity'=>$severity,'user_id'=>$userId,'title'=>$title,'description'=>$description,'details'=>$details]); }
    public function updateAlertStatus(int $alertId, string $status, ?int $assignedTo = null): bool { return $this->model->updateAlertStatus($alertId, $status, $assignedTo); }
    /** @return array{avg_detection_time_seconds: float, false_positive_rate_percent: int, avg_resolution_time_minutes: float} */
    public function getPerformanceMetrics(): array { try { $m=$this->model->getPerformanceMetrics(); } catch (\Throwable $e) { $this->safeCapture($e, 'getPerformanceMetrics'); $m=(object)[]; } return ['avg_detection_time_seconds'=>round(floatval($m->avg_detection_time ?? 0),2),'false_positive_rate_percent'=>0,'avg_resolution_time_minutes'=>round(floatval($m->avg_resolution_time ?? 0),2)]; }
    /** @return array{labels: list<string>, data: list<int>} */
    public function getRealTimeChartData(): array { try { $rows=$this->model->getRealTimeData(); } catch (\Throwable $e) { $this->safeCapture($e, 'getRealTimeChartData'); $rows=[]; } return ['labels'=>array_map(fn($r)=>$r->minute ?? '', $rows ?: []),'data'=>array_map(fn($r)=>(int)($r->count ?? 0), $rows ?: [])]; }
    /** @return array<string, mixed> */
    public function getCompleteDashboard(): array { return ['overview'=>$this->getOverview(24),'recent_alerts'=>$this->getRecentAlerts(10),'fraud_type_distribution'=>$this->getFraudTypeDistribution(24),'hourly_trend'=>$this->getHourlyTrend(24),'geographic_threats'=>$this->getGeographicThreats(24),'top_suspicious_users'=>$this->getTopSuspiciousUsers(10),'top_suspicious_ips'=>$this->getTopSuspiciousIPs(10),'rate_limit_violations'=>$this->getRateLimitViolations(24),'device_stats'=>$this->getDeviceStats(24),'performance_metrics'=>$this->getPerformanceMetrics(),'realtime_chart'=>$this->getRealTimeChartData(),'generated_at'=>date('Y-m-d H:i:s')]; }
}
