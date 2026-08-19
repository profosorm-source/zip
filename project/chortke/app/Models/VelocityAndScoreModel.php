<?php

declare(strict_types=1);

namespace App\Models;

use Core\Model;

/**
 * VelocityAndScoreModel - Transaction Velocity, Account Takeover, Fraud Scoring & Connection Graph Data Access Layer
 *
 * @phpstan-type RoundNumberStats array{total: int, round_count: int}
 * @phpstan-type TransactionAmountStats array{count: int, mean: float, std_dev: float}
 * @phpstan-type BehaviorMetrics array{transaction_count: int, avg_amount: float, total_amount: float}
 * @phpstan-type PredictionFeatures array<string, mixed>
 * @phpstan-type TakeoverDetection array{risk_score: int|float, action: string, ...}
 * @phpstan-type EmailAnalysis array{is_disposable: bool|int, is_free_provider: bool|int, mx_records_valid: bool|int, ...}
 * @phpstan-type PhoneAnalysis array{country_code: string, line_type: string, is_voip: bool|int, ...}
 * @phpstan-type FraudFactors array<string, mixed>
 * @phpstan-type AlertData array{user_id?: int|null, alert_type?: string, severity?: string|int, title?: string, description?: string|null, details?: array<string, mixed>|null}
 */
class VelocityAndScoreModel extends Model
{
    protected static string $table = 'transactions';

    private function rowInt(?\stdClass $row, string $field, int $default = 0): int
    {
        if ($row === null) return $default;
        $value = get_object_vars($row)[$field] ?? null;
        if (is_int($value)) return $value;
        if (is_string($value) && preg_match('/^-?\d+$/', $value) === 1) return (int)$value;
        return $default;
    }

    private function rowFloat(?\stdClass $row, string $field, float $default = 0.0): float
    {
        if ($row === null) return $default;
        $value = get_object_vars($row)[$field] ?? null;
        return is_scalar($value) && is_numeric((string)$value) ? (float)$value : $default;
    }

    private function rowString(?\stdClass $row, string $field, string $default = ''): string
    {
        if ($row === null) return $default;
        $value = get_object_vars($row)[$field] ?? null;
        return is_scalar($value) ? (string)$value : $default;
    }

    // ═══════════════════════════════════════════════════════════════════════
    // Velocity & Behavior Check
    // ═══════════════════════════════════════════════════════════════════════

    public function getTransactionCount(int $userId, string $type, int $seconds): int
    {
        if ($type === 'login') {
            $row = $this->db->fetch(
                "SELECT COUNT(*) as count FROM activity_logs 
                 WHERE user_id = ? AND event IN ('login', 'login_success', 'login_failed') AND created_at > DATE_SUB(NOW(), INTERVAL ? SECOND)",
                [$userId, $seconds]
            );
            return $this->rowInt($row, 'count');
        }
        if ($type === 'password_change') {
            $row = $this->db->fetch(
                "SELECT COUNT(*) as count FROM activity_logs 
                 WHERE user_id = ? AND event = 'password_changed' AND created_at > DATE_SUB(NOW(), INTERVAL ? SECOND)",
                [$userId, $seconds]
            );
            return $this->rowInt($row, 'count');
        }

        $row = $this->db->fetch(
            "SELECT COUNT(*) as count FROM transactions 
             WHERE user_id = ? AND type = ? 
             AND status NOT IN ('failed', 'rejected', 'cancelled')
             AND created_at > DATE_SUB(NOW(), INTERVAL ? SECOND)",
            [$userId, $type, $seconds]
        );
        return $this->rowInt($row, 'count');
    }

    // M-01: Missing method - Get total transaction amount within time period
    public function getTotalAmount(int $userId, string $type, int $seconds): float
    {
        $row = $this->db->fetch(
            "SELECT COALESCE(SUM(amount), 0) as total FROM transactions 
             WHERE user_id = ? AND type = ? 
             AND status NOT IN ('failed', 'rejected', 'cancelled')
             AND created_at > DATE_SUB(NOW(), INTERVAL ? SECOND)",
            [$userId, $type, $seconds]
        );
        return $this->rowFloat($row, 'total');
    }

    // M-01: Missing method - Count repeated transactions with same amount
    public function getRepeatedTransactionsCount(int $userId, string $type, float $amount): int
    {
        $row = $this->db->fetch(
            "SELECT COUNT(*) as count FROM transactions 
             WHERE user_id = ? AND type = ? AND amount = ? 
             AND status NOT IN ('failed', 'rejected', 'cancelled')
             AND created_at > DATE_SUB(NOW(), INTERVAL 24 HOUR)",
            [$userId, $type, $amount]
        );
        return $this->rowInt($row, 'count');
    }

    // M-01: Missing method - Get stats on round number transactions
    /** @return RoundNumberStats */
    public function getRoundNumberStats(int $userId): array
    {
        $roundNumbers = [10000, 50000, 100000, 500000, 1000000, 5000000, 10000000];
        $placeholders = implode(',', array_fill(0, count($roundNumbers), '?'));
        
        $row = $this->db->fetch(
            "SELECT 
                COUNT(*) as total,
                SUM(CASE WHEN amount IN ($placeholders) THEN 1 ELSE 0 END) as round_count
             FROM transactions 
             WHERE user_id = ? AND created_at > DATE_SUB(NOW(), INTERVAL 90 DAY)",
            array_merge($roundNumbers, [$userId])
        );
        
        return [
            'total' => $this->rowInt($row, 'total'),
            'round_count' => $this->rowInt($row, 'round_count')
        ];
    }

    public function getRecentTransactionCount(int $userId, int $hours): int
    {
        $row = $this->db->fetch(
            "SELECT COUNT(*) as count FROM transactions 
             WHERE user_id = ? 
             AND created_at >= DATE_SUB(NOW(), INTERVAL ? HOUR)",
            [$userId, $hours]
        );
        return $this->rowInt($row, 'count');
    }

    public function getUserAverageDaily(int $userId): float
    {
        $sql = "SELECT COUNT(*) / GREATEST(DATEDIFF(NOW(), MIN(created_at)), 1) as avg_daily
                FROM transactions
                WHERE user_id = ?";
        $result = $this->db->fetch($sql, [$userId]);
        return $this->rowFloat($result, 'avg_daily');
    }

    /** @return TransactionAmountStats */
    public function getTransactionAmountStats(int $userId, int $days = 90): array
    {
        $sql = "SELECT 
                    COUNT(*) as count,
                    AVG(amount) as mean,
                    STDDEV(amount) as std_dev
                FROM transactions
                WHERE user_id = ?
                AND amount > 0
                AND created_at >= DATE_SUB(NOW(), INTERVAL ? DAY)";

        $result = $this->db->fetch($sql, [$userId, $days]);

        return [
            'count' => $this->rowInt($result, 'count'),
            'mean' => $this->rowFloat($result, 'mean'),
            'std_dev' => $this->rowFloat($result, 'std_dev'),
        ];
    }

    /** @return array<int, int> */
    public function getHourlyActivity(int $userId, int $days = 30): array
    {
        $sql = "SELECT HOUR(created_at) as hour, COUNT(*) as count
                FROM transactions
                WHERE user_id = ? 
                AND created_at >= DATE_SUB(NOW(), INTERVAL ? DAY)
                GROUP BY HOUR(created_at)";

        $results = $this->db->fetchAll($sql, [$userId, $days]);
        $hourlyActivity = [];

        foreach ($results as $row) {
            $hourlyActivity[$this->rowInt($row, 'hour')] = $this->rowInt($row, 'count');
        }

        return $hourlyActivity;
    }

    public function getDeviceCount(int $userId, int $days = 7): int
    {
        $sql = "SELECT COUNT(DISTINCT device_fingerprint) as device_count
                FROM transactions
                WHERE user_id = ?
                AND created_at >= DATE_SUB(NOW(), INTERVAL ? DAY)
                AND device_fingerprint IS NOT NULL";

        $result = $this->db->fetch($sql, [$userId, $days]);
        return $this->rowInt($result, 'device_count');
    }

    /** @return BehaviorMetrics */
    public function getBehaviorMetrics(int $userId, int $days, int $offset = 0): array
    {
        $sql = "SELECT 
                    COUNT(*) as transaction_count,
                    AVG(amount) as avg_amount,
                    SUM(amount) as total_amount
                FROM transactions
                WHERE user_id = ?
                AND created_at >= DATE_SUB(NOW(), INTERVAL ? DAY)
                AND created_at < DATE_SUB(NOW(), INTERVAL ? DAY)";

        $result = $this->db->fetch($sql, [$userId, $days + $offset, $offset]);

        return [
            'transaction_count' => $this->rowInt($result, 'transaction_count'),
            'avg_amount' => $this->rowFloat($result, 'avg_amount'),
            'total_amount' => $this->rowFloat($result, 'total_amount'),
        ];
    }

    public function getUserAndReferrerInfo(int $userId): ?\stdClass
    {
        $sql = "SELECT u.referred_by, r.fraud_score as referrer_fraud_score, r.is_blacklisted as referrer_is_blacklisted
                FROM users u
                LEFT JOIN users r ON u.referred_by = r.id
                WHERE u.id = ?";
        return $this->db->fetch($sql, [$userId]);
    }

    /** @return list<\stdClass> */
    public function getSharedIPData(int $userId, int $days = 30): array
    {
        $sql = "SELECT t.ip_address, COUNT(DISTINCT t.user_id) AS user_count,
                       SUM(CASE WHEN u.fraud_score > 70 THEN 1 ELSE 0 END) AS suspicious_users
                FROM transactions t
                JOIN users u ON t.user_id = u.id
                WHERE t.ip_address IN (
                    SELECT DISTINCT source.ip_address
                    FROM transactions source
                    WHERE source.user_id = ?
                      AND source.ip_address IS NOT NULL
                      AND source.created_at >= DATE_SUB(NOW(), INTERVAL ? DAY)
                )
                  AND t.created_at >= DATE_SUB(NOW(), INTERVAL ? DAY)
                GROUP BY t.ip_address
                HAVING COUNT(DISTINCT t.user_id) > 1";

        return $this->db->fetchAll($sql, [$userId, $days, $days]);
    }

    /** @param PredictionFeatures $features */
    public function storePrediction(int $userId, float $riskScore, array $features): bool
    {
        $probability = max(0.0, min(1.0, $riskScore));
        $label = $probability >= 0.75
            ? 'high'
            : ($probability >= 0.50 ? 'medium' : ($probability >= 0.25 ? 'low' : 'safe'));

        $featuresJson = json_encode($features, JSON_UNESCAPED_UNICODE);
        if ($featuresJson === false) {
            $featuresJson = '{}';
        }
        $rawOutput = json_encode([
            'risk_score' => $probability,
            'features' => $features,
            'source' => 'rule_based_weighted_scoring',
        ], JSON_UNESCAPED_UNICODE) ?: '{}';

        $sql = "INSERT INTO ml_fraud_predictions
                (user_id, model_name, model_version, probability, predicted_label,
                 threshold_used, top_features, raw_output, risk_score, features, created_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())";

        $stmt = $this->db->query($sql, [
            $userId,
            'rule_based_fraud_scoring',
            '1.0',
            $probability,
            $label,
            0.75,
            $featuresJson,
            $rawOutput,
            $probability,
            $featuresJson,
        ]);

        return (bool) $stmt;
    }

    public function updatePredictionFeedback(int $userId, string $actualOutcome): bool
    {
        $sql = "UPDATE ml_fraud_predictions
                SET raw_output = JSON_SET(COALESCE(raw_output, JSON_OBJECT()), '$.actual_outcome', ?)
                WHERE user_id = ?
                ORDER BY created_at DESC
                LIMIT 1";

        $stmt = $this->db->query($sql, [$actualOutcome, $userId]);
        return (bool) $stmt;
    }

    // ═══════════════════════════════════════════════════════════════════════
    // Account Takeover & Contact Verification Caching
    // ═══════════════════════════════════════════════════════════════════════

    public function getLastPasswordChange(int $userId): ?string
    {
        $sql = "SELECT created_at FROM activity_logs 
                WHERE user_id = ? AND event = 'password_changed' 
                ORDER BY created_at DESC LIMIT 1";
        $result = $this->db->fetch($sql, [$userId]);
        $createdAt = $this->rowString($result, 'created_at');
        return $createdAt === '' ? null : $createdAt;
    }

    public function getLastEmailChange(int $userId): ?string
    {
        $sql = "SELECT created_at FROM activity_logs 
                WHERE user_id = ? AND event = 'email_changed' 
                ORDER BY created_at DESC LIMIT 1";
        $result = $this->db->fetch($sql, [$userId]);
        $createdAt = $this->rowString($result, 'created_at');
        return $createdAt === '' ? null : $createdAt;
    }

    public function getIPUsageCount(int $userId, string $ip): int
    {
        $sql = 'SELECT COUNT(*) as count FROM user_sessions WHERE user_id = ? AND ip_address = ?';
        $result = $this->db->fetch($sql, [$userId, $ip]);
        return $this->rowInt($result, 'count');
    }

    public function getDeviceUsageCount(int $userId, string $userAgent): int
    {
        $sql = 'SELECT COUNT(*) as count FROM user_sessions WHERE user_id = ? AND user_agent = ?';
        $result = $this->db->fetch($sql, [$userId, $userAgent]);
        return $this->rowInt($result, 'count');
    }

    public function getRecentFailedAttempts(int $userId): int
    {
        $sql = "SELECT COUNT(*) as count FROM activity_logs 
                WHERE user_id = ? AND event = 'login_failed' 
                AND created_at > DATE_SUB(NOW(), INTERVAL 1 HOUR)";
        $result = $this->db->fetch($sql, [$userId]);
        return $this->rowInt($result, 'count');
    }

    /** @param TakeoverDetection $detection */
    public function logTakeoverDetection(int $userId, string $ip, string $userAgent, array $detection): void
    {
        $sql = "INSERT INTO fraud_logs (user_id, fraud_type, risk_score, details, action_taken, ip_address, user_agent, created_at) 
                VALUES (?, 'account_takeover', ?, ?, ?, ?, ?, NOW())";
        $this->db->query($sql, [
            $userId,
            $detection['risk_score'],
            json_encode($detection, JSON_UNESCAPED_UNICODE),
            $detection['action'],
            $ip,
            $userAgent,
        ]);
    }

    public function getEmailFromCache(string $email): ?\stdClass
    {
        $sql = "SELECT * FROM email_intelligence 
                WHERE email = ? 
                AND last_checked_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)";
        return $this->db->fetch($sql, [$email]);
    }

    public function getDomainIntelligence(string $domain): ?\stdClass
    {
        // The curated disposable-domain feed is authoritative. Per-email cache
        // observations are retained as a secondary signal for domains not yet in
        // that feed.
        return $this->db->fetch(
            "SELECT MAX(candidate.is_disposable) AS is_disposable
             FROM (
                SELECT 1 AS is_disposable FROM disposable_domains WHERE domain = ?
                UNION ALL
                SELECT is_disposable FROM email_intelligence WHERE domain = ?
             ) AS candidate",
            [$domain, $domain]
        );
    }

    /** @param EmailAnalysis $analysis */
    public function saveEmailToCache(string $email, string $domain, array $analysis): bool
    {
        $sql = "INSERT INTO email_intelligence 
                (email, domain, is_disposable, is_free_provider, mx_records_valid, last_checked_at)
                VALUES (?, ?, ?, ?, ?, NOW())
                ON DUPLICATE KEY UPDATE
                is_disposable = VALUES(is_disposable),
                is_free_provider = VALUES(is_free_provider),
                mx_records_valid = VALUES(mx_records_valid),
                last_checked_at = NOW()";
        
        $stmt = $this->db->query($sql, [
            $email,
            $domain,
            (int) $analysis['is_disposable'],
            (int) $analysis['is_free_provider'],
            (int) $analysis['mx_records_valid']
        ]);

        return (bool) $stmt;
    }

    public function getPhoneFromCache(string $phone): ?\stdClass
    {
        $sql = "SELECT * FROM phone_intelligence 
                WHERE phone = ? 
                AND last_checked_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)";
        return $this->db->fetch($sql, [$phone]);
    }

    /** @param PhoneAnalysis $analysis */
    public function savePhoneToCache(string $phone, array $analysis): bool
    {
        $sql = "INSERT INTO phone_intelligence 
                (phone, country_code, line_type, is_voip, is_valid, last_checked_at)
                VALUES (?, ?, ?, ?, TRUE, NOW())
                ON DUPLICATE KEY UPDATE
                country_code = VALUES(country_code),
                line_type = VALUES(line_type),
                is_voip = VALUES(is_voip),
                last_checked_at = NOW()";
        
        $stmt = $this->db->query($sql, [
            $phone,
            $analysis['country_code'],
            $analysis['line_type'],
            (int) $analysis['is_voip']
        ]);

        return (bool) $stmt;
    }

    // ═══════════════════════════════════════════════════════════════════════
    // Scoring, Reputation & Connections Graphs
    // ═══════════════════════════════════════════════════════════════════════

    public function getAccountAge(int $userId): int
    {
        $result = $this->db->fetch(
            "SELECT DATEDIFF(NOW(), created_at) as days FROM users WHERE id = ?",
            [$userId]
        );
        return $this->rowInt($result, 'days');
    }

    public function getUserReputation(int $userId): int
    {
        $result = $this->db->fetch(
            "SELECT fraud_score FROM users WHERE id = ? LIMIT 1",
            [$userId]
        );
        return 100 - $this->rowInt($result, 'fraud_score');
    }

    public function getDailyTransactionCount(int $userId): int
    {
        $result = $this->db->fetch(
            "SELECT COUNT(*) as count FROM transactions WHERE user_id = ? AND DATE(created_at) = CURDATE()",
            [$userId]
        );
        return $this->rowInt($result, 'count');
    }

    public function getWeeklyTransactionCount(int $userId): int
    {
        $result = $this->db->fetch(
            "SELECT COUNT(*) as count FROM transactions WHERE user_id = ? AND created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)",
            [$userId]
        );
        return $this->rowInt($result, 'count');
    }

    public function getPreviousWeeklyTransactionCount(int $userId): int
    {
        $result = $this->db->fetch(
            "SELECT COUNT(*) as count FROM transactions 
             WHERE user_id = ? 
             AND created_at >= DATE_SUB(NOW(), INTERVAL 14 DAY)
             AND created_at < DATE_SUB(NOW(), INTERVAL 7 DAY)",
            [$userId]
        );
        return $this->rowInt($result, 'count');
    }

    public function getCountryChanges(int $userId): int
    {
        $result = $this->db->fetch(
            "SELECT COUNT(DISTINCT country) as count 
             FROM user_sessions 
             WHERE user_id = ? AND created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)",
            [$userId]
        );
        return $this->rowInt($result, 'count');
    }

    public function getCityChanges(int $userId): int
    {
        $result = $this->db->fetch(
            "SELECT COUNT(DISTINCT city) as count 
             FROM user_sessions 
             WHERE user_id = ? AND created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)",
            [$userId]
        );
        return $this->rowInt($result, 'count');
    }

    public function getSuspiciousIPCount(int $userId): int
    {
        $result = $this->db->fetch(
            "SELECT COUNT(*) as count FROM user_sessions us
             JOIN ip_blacklist ib ON us.ip_address = ib.ip_address
             WHERE us.user_id = ? AND us.created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)",
            [$userId]
        );
        return $this->rowInt($result, 'count');
    }

    public function getUserInfo(int $userId): ?\stdClass
    {
        $sql = "SELECT id, fraud_score, is_blacklisted, status, created_at
                FROM users
                WHERE id = ?";
        return $this->db->fetch($sql, [$userId]);
    }

    /** @return list<\stdClass> */
    public function getReferralConnections(int $userId): array
    {
        $sql = "SELECT id as user_id, 'referral' as connection_type, 3 as strength
                FROM users
                WHERE referred_by = ?
                
                UNION
                
                SELECT referred_by as user_id, 'referred_by' as connection_type, 2 as strength
                FROM users
                WHERE id = ? AND referred_by IS NOT NULL";

        return $this->db->fetchAll($sql, [$userId, $userId]);
    }

    /** @return list<\stdClass> */
    public function getTransactionConnections(int $userId, int $days = 30): array
    {
        // The current transactions schema is wallet-centric and does not always
        // contain peer-to-peer from_user_id/to_user_id columns. Treat the graph
        // transaction edge source as optional instead of breaking all analysis.
        try {
            $hasFrom = $this->db->fetch("SHOW COLUMNS FROM transactions LIKE 'from_user_id'");
            $hasTo = $this->db->fetch("SHOW COLUMNS FROM transactions LIKE 'to_user_id'");
            if (!$hasFrom || !$hasTo) {
                return [];
            }

            $sql = "SELECT
                        CASE
                            WHEN from_user_id = ? THEN to_user_id
                            ELSE from_user_id
                        END as user_id,
                        'transaction' as connection_type,
                        COUNT(*) as strength
                    FROM transactions
                    WHERE (from_user_id = ? OR to_user_id = ?)
                    AND created_at >= DATE_SUB(NOW(), INTERVAL ? DAY)
                    GROUP BY user_id
                    HAVING strength >= 2";

            return $this->db->fetchAll($sql, [$userId, $userId, $userId, $days]);
        } catch (\Throwable $e) {
            return [];
        }
    }

    /** @return list<\stdClass> */
    public function getIPConnections(int $userId, int $days = 30): array
    {
        $sql = "SELECT DISTINCT t2.user_id, 'shared_ip' as connection_type, 1 as strength
                FROM transactions t1
                JOIN transactions t2 ON t1.ip_address = t2.ip_address
                WHERE t1.user_id = ?
                AND t2.user_id != ?
                AND t1.ip_address IS NOT NULL
                AND t1.created_at >= DATE_SUB(NOW(), INTERVAL ? DAY)";

        return $this->db->fetchAll($sql, [$userId, $userId, $days]);
    }

    /** @return list<\stdClass> */
    public function getSharedIPs(int $userId, int $days = 30): array
    {
        $sql = "SELECT 
                    t.ip_address,
                    COUNT(DISTINCT t.user_id) as user_count,
                    GROUP_CONCAT(DISTINCT t.user_id) as user_ids
                FROM transactions t
                WHERE t.ip_address IN (
                    SELECT DISTINCT ip_address 
                    FROM transactions 
                    WHERE user_id = ? 
                    AND created_at >= DATE_SUB(NOW(), INTERVAL ? DAY)
                )
                AND t.created_at >= DATE_SUB(NOW(), INTERVAL ? DAY)
                GROUP BY t.ip_address
                HAVING user_count > 1";

        return $this->db->fetchAll($sql, [$userId, $days, $days]);
    }

    /** @return list<\stdClass> */
    public function getCircularPaths(int $userId, int $minDepth, int $days = 7): array
    {
        try {
            $hasFrom = $this->db->fetch("SHOW COLUMNS FROM transactions LIKE 'from_user_id'");
            $hasTo = $this->db->fetch("SHOW COLUMNS FROM transactions LIKE 'to_user_id'");
            if (!$hasFrom || !$hasTo) {
                return [];
            }

            $sql = "WITH RECURSIVE paths AS (
                        SELECT
                            from_user_id as start_user,
                            to_user_id as current_user,
                            CAST(from_user_id AS CHAR(1000)) as path,
                            1 as depth,
                            amount
                        FROM transactions
                        WHERE from_user_id = ?
                        AND created_at >= DATE_SUB(NOW(), INTERVAL ? DAY)

                        UNION ALL

                        SELECT
                            p.start_user,
                            t.to_user_id,
                            CONCAT(p.path, '->', t.to_user_id),
                            p.depth + 1,
                            p.amount
                        FROM paths p
                        JOIN transactions t ON p.current_user = t.from_user_id
                        WHERE p.depth < 5
                        AND FIND_IN_SET(t.to_user_id, REPLACE(p.path, '->', ',')) = 0
                        AND t.created_at >= DATE_SUB(NOW(), INTERVAL ? DAY)
                    )
                    SELECT * FROM paths
                    WHERE current_user = start_user
                    AND depth >= ?";

            return $this->db->fetchAll($sql, [$userId, $days, $days, $minDepth]);
        } catch (\Throwable $e) {
            return [];
        }
    }

    public function updateUserFraudScore(int $userId, int $score): bool
    {
        $stmt = $this->db->query(
            "UPDATE users SET fraud_score = ?, fraud_score_updated_at = NOW() WHERE id = ?",
            [$score, $userId]
        );
        
        return $stmt->rowCount() > 0;
    }

    /** @param FraudFactors $factors */
    public function logFraudCalculation(int $userId, array $factors, int $finalScore): bool
    {
        $stmt = $this->db->query(
            "INSERT INTO fraud_calculation_logs 
             (user_id, account_age_factor, reputation_factor, velocity_factor, geographic_factor, final_score, created_at)
             VALUES (?, ?, ?, ?, ?, ?, NOW())",
            [
                $userId,
                $factors['account_age_factor'] ?? 0,
                $factors['reputation_factor'] ?? 0,
                $factors['velocity_factor'] ?? 0,
                $factors['geographic_factor'] ?? 0,
                $finalScore
            ]
        );
        
        return $stmt->rowCount() > 0;
    }

    public function flagForReview(int $userId, int $score): bool
    {
        $stmt = $this->db->query(
            "INSERT INTO user_fraud_flags (user_id, flag_type, severity, created_at)
             VALUES (?, 'flagged', ?, NOW())
             ON DUPLICATE KEY UPDATE severity = VALUES(severity), created_at = NOW()",
            [$userId, $score]
        );
        
        return $stmt->rowCount() > 0;
    }

    public function suspendAccount(int $userId, string $reason): bool
    {
        // 1. Update user status to suspended
        $this->db->query("UPDATE users SET status = 'suspended' WHERE id = ?", [$userId]);

        // 2. Record in fraud flags
        $this->db->query(
            "INSERT INTO user_fraud_flags (user_id, flag_type, severity, metadata, created_at)
             VALUES (?, 'suspended', 95, ?, NOW())
             ON DUPLICATE KEY UPDATE flag_type = 'suspended', metadata = VALUES(metadata), created_at = NOW()",
            [$userId, json_encode(['reason' => $reason])]
        );

        return true;
    }

    public function logFraudAction(int $userId, string $action, int $score, string $details): bool
    {
        $jsonDetails = json_encode(['message' => $details], JSON_UNESCAPED_UNICODE);
        $stmt = $this->db->query(
            "INSERT INTO fraud_logs (user_id, fraud_type, risk_score, details, action_taken, created_at)
             VALUES (?, 'automated_action', ?, ?, ?, NOW())",
            [$userId, $score, $jsonDetails, $action]
        );
        return $stmt->rowCount() > 0;
    }

    public function requireKYC(int $userId, int $score): bool
    {
        $stmt = $this->db->query(
            "INSERT INTO user_fraud_flags (user_id, flag_type, severity, created_at)
             VALUES (?, 'kyc_required', ?, NOW())
             ON DUPLICATE KEY UPDATE flag_type = 'kyc_required', severity = VALUES(severity), created_at = NOW()",
            [$userId, $score]
        );
        return $stmt->rowCount() > 0;
    }

    public function flagForManualReview(int $userId, int $score): bool
    {
        $stmt = $this->db->query(
            "INSERT INTO user_fraud_flags (user_id, flag_type, severity, created_at)
             VALUES (?, 'manual_review_required', ?, NOW())
             ON DUPLICATE KEY UPDATE flag_type = 'manual_review_required', severity = VALUES(severity), created_at = NOW()",
            [$userId, $score]
        );
        return $stmt->rowCount() > 0;
    }
    
    public function getUserFraudInfo(int $userId): ?\stdClass
    {
        $sql = "SELECT * FROM user_fraud_flags WHERE user_id = ? LIMIT 1";
        return $this->db->fetch($sql, [$userId]);
    }

    public function getUserTimezone(int $userId): string
    {
        $row = $this->db->fetch("SELECT timezone FROM users WHERE id = ? LIMIT 1", [$userId]);
        return (string)($row->timezone ?? 'Asia/Tehran');
    }

    public function getUserFlags(int $userId): ?\stdClass
    {
        return $this->db->fetch(
            "SELECT requires_review, requires_kyc, requires_manual_review, is_blacklisted, blacklist_reason
             FROM user_flags WHERE user_id = ? LIMIT 1",
            [$userId]
        );
    }

    public function clearUserFlags(int $userId): bool
    {
        $stmt = $this->db->query(
            "DELETE FROM user_flags WHERE user_id = ?",
            [$userId]
        );
        return $stmt !== false;
    }

    public function blacklistUser(int $userId, string $reason): bool
    {
        $statement = $this->db->query(
            "UPDATE users
             SET is_blacklisted = 1, blacklist_reason = ?, blacklisted_at = NOW(), updated_at = NOW()
             WHERE id = ?",
            [$reason, $userId]
        );
        return $statement->rowCount() > 0 || $this->db->fetch('SELECT id FROM users WHERE id = ?', [$userId]) !== null;
    }

    public function unblacklistUser(int $userId): bool
    {
        $statement = $this->db->query(
            "UPDATE users
             SET is_blacklisted = 0, blacklist_reason = NULL, blacklisted_at = NULL, updated_at = NOW()
             WHERE id = ?",
            [$userId]
        );
        return $statement->rowCount() > 0 || $this->db->fetch('SELECT id FROM users WHERE id = ?', [$userId]) !== null;
    }

    public function updateDisposableDomain(string $domain): bool
    {
        $stmt = $this->db->query(
            "INSERT INTO disposable_domains (domain, created_at) VALUES (?, NOW()) ON DUPLICATE KEY UPDATE updated_at = NOW()",
            [$domain]
        );
        return $stmt !== false;
    }

    /** Cleans the two intelligence caches that this model actually writes. */
    public function cleanupOldCache(int $daysToKeep = 30): int
    {
        $emailStatement = $this->db->query(
            "DELETE FROM email_intelligence WHERE last_checked_at < DATE_SUB(NOW(), INTERVAL ? DAY)",
            [$daysToKeep]
        );
        $phoneStatement = $this->db->query(
            "DELETE FROM phone_intelligence WHERE last_checked_at < DATE_SUB(NOW(), INTERVAL ? DAY)",
            [$daysToKeep]
        );
        return $emailStatement->rowCount() + $phoneStatement->rowCount();
    }

    /**
     * Device fingerprints used by this user and the number of distinct accounts
     * that used each fingerprint inside the same observation window.
     *
     * @return list<\stdClass>
     */
    public function getDeviceSharing(int $userId, int $days = 30): array
    {
        $sql = "SELECT t2.device_fingerprint, COUNT(DISTINCT t2.user_id) AS account_count
                FROM transactions t2
                WHERE t2.device_fingerprint IN (
                    SELECT DISTINCT t1.device_fingerprint
                    FROM transactions t1
                    WHERE t1.user_id = ?
                      AND t1.device_fingerprint IS NOT NULL
                      AND t1.created_at >= DATE_SUB(NOW(), INTERVAL ? DAY)
                )
                  AND t2.created_at >= DATE_SUB(NOW(), INTERVAL ? DAY)
                GROUP BY t2.device_fingerprint
                HAVING COUNT(DISTINCT t2.user_id) > 1";

        return $this->db->fetchAll($sql, [$userId, $days, $days]);
    }

}
