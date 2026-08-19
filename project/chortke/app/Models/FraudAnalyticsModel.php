<?php

declare(strict_types=1);

namespace App\Models;

use Core\Model;

/**
 * Read/write model for the anti-fraud operational dashboard.
 *
 * @phpstan-type AlertInput array{user_id?: int|null, alert_type: string, severity: string, title: string, description?: string|null, details?: array<string, mixed>|null}
 */
class FraudAnalyticsModel extends Model
{
    protected static string $table = 'fraud_logs';

    /** @return \stdClass */
    public function getOverviewCounts(string $since): \stdClass
    {
        $row = $this->db->fetch(
            "SELECT
                (SELECT COUNT(*) FROM fraud_logs WHERE created_at >= ?) AS total_frauds,
                (SELECT COUNT(*) FROM fraud_alerts WHERE status = 'pending' AND created_at >= ?) AS active_alerts,
                (SELECT COUNT(*) FROM users WHERE status = 'suspended' AND deleted_at IS NULL) AS blocked_users,
                (SELECT COUNT(*) FROM user_sessions WHERE created_at >= ?) AS total_sessions,
                (SELECT COUNT(DISTINCT user_id) FROM fraud_logs WHERE risk_score >= 70 AND created_at >= ?) AS high_risk_users,
                (SELECT COUNT(*) FROM users WHERE is_blacklisted = 1 AND deleted_at IS NULL) AS blacklisted_users,
                (SELECT COUNT(*) FROM fraud_logs WHERE created_at >= CURDATE()) AS today_suspicious,
                (SELECT COUNT(*) FROM fraud_logs WHERE action_taken IN ('reject', 'rejected') AND created_at >= CURDATE()) AS today_rejected",
            [$since, $since, $since, $since]
        );

        return $row ?? (object)[
            'total_frauds' => 0,
            'active_alerts' => 0,
            'blocked_users' => 0,
            'total_sessions' => 0,
            'high_risk_users' => 0,
            'blacklisted_users' => 0,
            'today_suspicious' => 0,
            'today_rejected' => 0,
        ];
    }

    /** @return list<\stdClass> */
    public function getRecentAlerts(int $limit, ?string $severity = null): array
    {
        $limit = max(1, min(200, $limit));
        $sql = "SELECT fa.*, u.full_name, u.email
                FROM fraud_alerts fa
                LEFT JOIN users u ON u.id = fa.user_id";
        $params = [];
        if ($severity !== null && $severity !== '') {
            $sql .= ' WHERE fa.severity = ?';
            $params[] = $severity;
        }
        $sql .= " ORDER BY fa.created_at DESC, fa.id DESC LIMIT {$limit}";
        return $this->db->fetchAll($sql, $params);
    }

    /** @return list<\stdClass> */
    public function getFraudTypeDistribution(string $since): array
    {
        return $this->db->fetchAll(
            "SELECT fraud_type, COUNT(*) AS count, COALESCE(AVG(risk_score), 0) AS avg_risk_score
             FROM fraud_logs
             WHERE created_at >= ?
             GROUP BY fraud_type
             ORDER BY count DESC, fraud_type ASC",
            [$since]
        );
    }

    /** @return list<\stdClass> */
    public function getHourlyTrend(string $since): array
    {
        return $this->db->fetchAll(
            "SELECT DATE_FORMAT(created_at, '%Y-%m-%d %H:00:00') AS hour,
                    COUNT(*) AS count,
                    COALESCE(AVG(risk_score), 0) AS avg_risk
             FROM fraud_logs
             WHERE created_at >= ?
             GROUP BY DATE_FORMAT(created_at, '%Y-%m-%d %H:00:00')
             ORDER BY hour ASC",
            [$since]
        );
    }

    /** @return list<\stdClass> */
    public function getGeographicThreats(string $since): array
    {
        return $this->db->fetchAll(
            "SELECT COALESCE(s.country, u.country_code, 'XX') AS country,
                    COALESCE(u.country_name, s.country, 'Unknown') AS country_name,
                    COUNT(DISTINCT fl.id) AS fraud_count,
                    COUNT(DISTINCT fl.user_id) AS affected_users,
                    COALESCE(AVG(fl.risk_score), 0) AS avg_risk_score
             FROM fraud_logs fl
             LEFT JOIN user_sessions s ON s.session_id = fl.session_id
             LEFT JOIN users u ON u.id = fl.user_id
             WHERE fl.created_at >= ?
               AND COALESCE(s.country, u.country_code) IS NOT NULL
             GROUP BY COALESCE(s.country, u.country_code), COALESCE(u.country_name, s.country)
             ORDER BY fraud_count DESC, country ASC",
            [$since]
        );
    }

    /** @return list<\stdClass> */
    public function getTopSuspiciousUsers(int $limit = 10, string $since = ''): array
    {
        $limit = max(1, min(100, $limit));
        $sql = "SELECT u.id, u.username, u.email, u.fraud_score, u.is_blacklisted,
                       COUNT(fl.id) AS fraud_count,
                       COALESCE(AVG(fl.risk_score), 0) AS avg_risk_score,
                       MAX(fl.created_at) AS last_fraud_at
                FROM users u
                JOIN fraud_logs fl ON fl.user_id = u.id";
        $params = [];
        if ($since !== '') {
            $sql .= ' WHERE fl.created_at >= ?';
            $params[] = $since;
        }
        $sql .= " GROUP BY u.id, u.username, u.email, u.fraud_score, u.is_blacklisted
                  ORDER BY avg_risk_score DESC, fraud_count DESC, last_fraud_at DESC
                  LIMIT {$limit}";
        return $this->db->fetchAll($sql, $params);
    }

    /** @return list<\stdClass> */
    public function getTopSuspiciousIPs(int $limit = 10, string $since = ''): array
    {
        $limit = max(1, min(100, $limit));
        $sql = "SELECT fl.ip_address,
                       COUNT(DISTINCT fl.user_id) AS user_count,
                       COUNT(*) AS fraud_count,
                       COALESCE(AVG(fl.risk_score), 0) AS avg_risk_score,
                       MAX(fl.created_at) AS last_seen
                FROM fraud_logs fl
                WHERE fl.ip_address IS NOT NULL AND fl.ip_address <> ''";
        $params = [];
        if ($since !== '') {
            $sql .= ' AND fl.created_at >= ?';
            $params[] = $since;
        }
        $sql .= " GROUP BY fl.ip_address
                  ORDER BY avg_risk_score DESC, fraud_count DESC, last_seen DESC
                  LIMIT {$limit}";
        return $this->db->fetchAll($sql, $params);
    }

    /** @return list<\stdClass> */
    public function getRateLimitViolations(string $since, int $limit = 20): array
    {
        $limit = max(1, min(100, $limit));
        return $this->db->fetchAll(
            "SELECT action, COUNT(*) AS count, COUNT(DISTINCT identifier_key) AS unique_identifiers
             FROM rate_limit_requests
             WHERE created_at >= ?
             GROUP BY action
             ORDER BY count DESC, action ASC
             LIMIT {$limit}",
            [$since]
        );
    }

    /** @return list<\stdClass> */
    public function getRealTimeData(): array
    {
        return $this->db->fetchAll(
            "SELECT DATE_FORMAT(created_at, '%Y-%m-%d %H:%i:00') AS minute, COUNT(*) AS count
             FROM fraud_logs
             WHERE created_at >= DATE_SUB(NOW(), INTERVAL 1 HOUR)
             GROUP BY DATE_FORMAT(created_at, '%Y-%m-%d %H:%i:00')
             ORDER BY minute ASC"
        );
    }

    /** @return \stdClass */
    public function getDeviceStats(string $since): \stdClass
    {
        $row = $this->db->fetch(
            "SELECT COUNT(DISTINCT COALESCE(device_fingerprint, fingerprint)) AS total,
                    SUM(CASE WHEN LOWER(COALESCE(device_type, '')) LIKE '%emulator%' THEN 1 ELSE 0 END) AS emulator_count,
                    SUM(CASE WHEN LOWER(COALESCE(device_type, '')) LIKE '%virtual%' OR LOWER(COALESCE(device_type, '')) LIKE '%vm%' THEN 1 ELSE 0 END) AS vm_count,
                    SUM(CASE WHEN LOWER(COALESCE(user_agent, '')) REGEXP 'headless|selenium|playwright|puppeteer' THEN 1 ELSE 0 END) AS automation_count,
                    0 AS avg_risk_score
             FROM user_sessions
             WHERE created_at >= ?",
            [$since]
        );
        return $row ?? (object)['total' => 0, 'emulator_count' => 0, 'vm_count' => 0, 'automation_count' => 0, 'avg_risk_score' => 0];
    }

    /** @return \stdClass */
    public function getPerformanceMetrics(): \stdClass
    {
        $row = $this->db->fetch(
            "SELECT 0 AS avg_detection_time,
                    COALESCE(AVG(CASE WHEN status <> 'pending' THEN TIMESTAMPDIFF(MINUTE, created_at, updated_at) END), 0) AS avg_resolution_time
             FROM fraud_alerts"
        );
        return $row ?? (object)['avg_detection_time' => 0, 'avg_resolution_time' => 0];
    }

    /** @param AlertInput $data */
    public function createAlert(array $data): int
    {
        $alertType = trim($data['alert_type']);
        $severity = trim($data['severity']);
        $title = trim($data['title']);
        if ($alertType === '' || $severity === '' || $title === '') {
            throw new \InvalidArgumentException('Fraud alert type, severity and title are required.');
        }

        $details = $data['details'] ?? null;
        $detailsJson = $details === null ? null : json_encode($details, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $this->db->query(
            "INSERT INTO fraud_alerts (user_id, alert_type, severity, title, description, details, status, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, 'pending', NOW(), NOW())",
            [$data['user_id'] ?? null, $alertType, $severity, $title, $data['description'] ?? null, $detailsJson]
        );
        return (int)$this->db->lastInsertId();
    }

    public function updateAlertStatus(int $id, string $status, ?int $assignedTo = null): bool
    {
        $status = trim($status);
        if ($id <= 0 || $status === '') return false;
        $statement = $this->db->query(
            "UPDATE fraud_alerts SET status = ?, assigned_to = ?, updated_at = NOW() WHERE id = ?",
            [$status, $assignedTo, $id]
        );
        return $statement->rowCount() > 0;
    }

    /** @param array<string, mixed> $data */
    public function logFraudEvent(array $data): bool
    {
        $statement = $this->db->query(
            "INSERT INTO fraud_logs (user_id, fraud_type, risk_score, details, ip_address, created_at)
             VALUES (?, ?, ?, ?, ?, NOW())",
            [
                $data['user_id'] ?? null,
                is_string($data['type'] ?? null) ? $data['type'] : 'unknown',
                is_scalar($data['score'] ?? null) ? (int)$data['score'] : 0,
                json_encode(is_array($data['details'] ?? null) ? $data['details'] : [], JSON_UNESCAPED_UNICODE),
                is_string($data['ip'] ?? null) ? $data['ip'] : null,
            ]
        );
        return $statement->rowCount() > 0;
    }
}
