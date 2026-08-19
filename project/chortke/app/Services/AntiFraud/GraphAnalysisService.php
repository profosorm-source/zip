<?php

declare(strict_types=1);

namespace App\Services\AntiFraud;

use App\Contracts\LoggerInterface;
use App\Models\VelocityAndScoreModel;

/**
 * Graph analysis for fraud-network signals.
 *
 * @phpstan-type GraphNode array{id: int, fraud_score: int, is_blacklisted: bool, status: string, age_days: int}
 * @phpstan-type GraphEdge array{from: int, to: int, type: string, weight: int}
 * @phpstan-type Graph array{nodes: array<int, GraphNode>, edges: list<GraphEdge>}
 * @phpstan-type Neighbor array{user_id: int, connection_type: string, strength: int}
 * @phpstan-type ClusterAnalysis array{is_cluster: bool, is_suspicious: bool, size: int, suspicious_count?: int, blacklisted_count?: int, avg_fraud_score?: float, fraud_ratio?: float}
 * @phpstan-type IpSharingAnalysis array{shared_ip_count: int, suspicious_ips: list<array{ip: string, user_count: int}>, is_suspicious: bool}
 * @phpstan-type CircularAnalysis array{detected: bool, count: int, paths: list<\stdClass>}
 * @phpstan-type CentralityAnalysis array{degree: int, weighted_degree: int, is_hub: bool}
 * @phpstan-type NetworkAnalysis array{user_id: int, network_size: int, connection_count: int, cluster_analysis: ClusterAnalysis, ip_sharing: IpSharingAnalysis, circular_transactions: CircularAnalysis, centrality: CentralityAnalysis, bot_network_risk: float, overall_risk: float}
 */
class GraphAnalysisService
{
    private const CLUSTER_MIN_SIZE = 3;
    private const CLUSTER_FRAUD_RATIO = 0.5;
    private const MAX_SHARED_IP_USERS = 2;
    private const CIRCULAR_TRANSACTION_THRESHOLD = 3;

    public function __construct(
        private LoggerInterface $logger,
        private VelocityAndScoreModel $model
    ) {}

    /** @return NetworkAnalysis */
    public function analyzeUserNetwork(int $userId, int $depth = 2): array
    {
        $depth = max(0, min(5, $depth));
        $this->logger->info('graph.analyze_started', ['user_id' => $userId, 'depth' => $depth]);

        $graph = $this->buildUserGraph($userId, $depth);
        /** @var ClusterAnalysis $clusterAnalysis */
        $clusterAnalysis = $this->analyzeCluster($graph);
        /** @var CentralityAnalysis $centrality */
        $centrality = $this->calculateCentrality($userId, $graph);
        /** @var NetworkAnalysis $analysis */
        $analysis = [
            'user_id' => $userId,
            'network_size' => count($graph['nodes']),
            'connection_count' => count($graph['edges']),
            'cluster_analysis' => $clusterAnalysis,
            'ip_sharing' => $this->analyzeIPSharing($userId),
            'circular_transactions' => $this->detectCircularTransactions($userId),
            'centrality' => $centrality,
            'bot_network_risk' => $this->detectBotNetwork($graph),
            'overall_risk' => 0.0,
        ];
        $analysis['overall_risk'] = $this->calculateNetworkRisk($analysis);

        $this->logger->info('graph.analyze_completed', [
            'user_id' => $userId,
            'risk_score' => $analysis['overall_risk'],
        ]);

        return $analysis;
    }

    /** @return Graph */
    private function buildUserGraph(int $userId, int $maxDepth): array
    {
        /** @var Graph $graph */
        $graph = ['nodes' => [], 'edges' => []];
        /** @var array<int, true> $visited */
        $visited = [];
        /** @var list<array{id: int, depth: int}> $queue */
        $queue = [['id' => $userId, 'depth' => 0]];

        while ($queue !== []) {
            $current = array_shift($queue);
            if ($current === null) break;
            $currentId = $current['id'];
            $currentDepth = $current['depth'];
            if (isset($visited[$currentId]) || $currentDepth > $maxDepth) continue;

            $visited[$currentId] = true;
            $userInfo = $this->getUserInfo($currentId);
            if ($userInfo === null) continue;
            $graph['nodes'][$currentId] = $userInfo;

            if ($currentDepth >= $maxDepth) continue;
            foreach ($this->getNeighbors($currentId) as $neighbor) {
                $neighborId = $neighbor['user_id'];
                $graph['edges'][] = [
                    'from' => $currentId,
                    'to' => $neighborId,
                    'type' => $neighbor['connection_type'],
                    'weight' => $neighbor['strength'],
                ];
                if (!isset($visited[$neighborId])) {
                    $queue[] = ['id' => $neighborId, 'depth' => $currentDepth + 1];
                }
            }
        }

        return $graph;
    }

    /** @return ?GraphNode */
    private function getUserInfo(int $userId): ?array
    {
        $user = $this->model->getUserInfo($userId);
        if (!$user instanceof \stdClass) return null;

        $id = $this->rowPositiveInt($user, 'id');
        $status = $this->rowString($user, 'status');
        $createdAt = $this->rowString($user, 'created_at');
        if ($id === null || $status === '' || $createdAt === '') return null;

        return [
            'id' => $id,
            'fraud_score' => $this->rowInt($user, 'fraud_score'),
            'is_blacklisted' => $this->rowBool($user, 'is_blacklisted'),
            'status' => $status,
            'age_days' => $this->calculateAccountAge($createdAt),
        ];
    }

    /** @return list<Neighbor> */
    private function getNeighbors(int $userId): array
    {
        /** @var list<\stdClass> $rows */
        $rows = array_merge(
            $this->model->getReferralConnections($userId),
            $this->model->getTransactionConnections($userId),
            $this->model->getIPConnections($userId)
        );

        /** @var array<int, Neighbor> $neighbors */
        $neighbors = [];
        foreach ($rows as $row) {
            $neighbor = $this->neighborFromRow($row);
            if ($neighbor === null || $neighbor['user_id'] === $userId) continue;
            $id = $neighbor['user_id'];
            if (isset($neighbors[$id])) {
                $neighbors[$id]['strength'] += $neighbor['strength'];
                continue;
            }
            $neighbors[$id] = $neighbor;
        }

        return array_values($neighbors);
    }

    /** @return ?Neighbor */
    private function neighborFromRow(\stdClass $row): ?array
    {
        $userId = $this->rowPositiveInt($row, 'user_id');
        $type = $this->rowString($row, 'connection_type');
        if ($userId === null || $type === '') return null;

        return [
            'user_id' => $userId,
            'connection_type' => $type,
            'strength' => max(1, $this->rowInt($row, 'strength', 1)),
        ];
    }

    /**
     * @param Graph $graph
     * @return ClusterAnalysis
     */
    private function analyzeCluster(array $graph): array
    {
        $nodes = $graph['nodes'];
        $size = count($nodes);
        if ($size < self::CLUSTER_MIN_SIZE) {
            return ['is_cluster' => false, 'is_suspicious' => false, 'size' => $size];
        }

        $suspiciousCount = 0;
        $blacklistedCount = 0;
        $totalFraudScore = 0;
        foreach ($nodes as $node) {
            if ($node['fraud_score'] > 70) $suspiciousCount++;
            if ($node['is_blacklisted']) $blacklistedCount++;
            $totalFraudScore += $node['fraud_score'];
        }

        $avgFraudScore = $totalFraudScore / $size;
        $fraudRatio = $suspiciousCount / $size;
        return [
            'is_cluster' => true,
            'is_suspicious' => $fraudRatio >= self::CLUSTER_FRAUD_RATIO || $blacklistedCount >= 2 || $avgFraudScore > 60,
            'size' => $size,
            'suspicious_count' => $suspiciousCount,
            'blacklisted_count' => $blacklistedCount,
            'avg_fraud_score' => round($avgFraudScore, 2),
            'fraud_ratio' => round($fraudRatio, 2),
        ];
    }

    /** @return IpSharingAnalysis */
    private function analyzeIPSharing(int $userId): array
    {
        $sharedIps = $this->model->getSharedIPs($userId);
        /** @var list<array{ip: string, user_count: int}> $suspicious */
        $suspicious = [];
        foreach ($sharedIps as $ip) {
            $count = $this->rowInt($ip, 'user_count');
            $address = $this->rowString($ip, 'ip_address');
            if ($address !== '' && $count >= self::MAX_SHARED_IP_USERS) {
                $suspicious[] = ['ip' => $address, 'user_count' => $count];
            }
        }

        return [
            'shared_ip_count' => count($sharedIps),
            'suspicious_ips' => $suspicious,
            'is_suspicious' => $suspicious !== [],
        ];
    }

    /** @return CircularAnalysis */
    private function detectCircularTransactions(int $userId): array
    {
        $paths = $this->model->getCircularPaths($userId, self::CIRCULAR_TRANSACTION_THRESHOLD);
        return ['detected' => $paths !== [], 'count' => count($paths), 'paths' => array_slice($paths, 0, 5)];
    }

    /**
     * @param Graph $graph
     * @return CentralityAnalysis
     */
    private function calculateCentrality(int $userId, array $graph): array
    {
        $degree = 0;
        $weightedDegree = 0;
        foreach ($graph['edges'] as $edge) {
            if ($edge['from'] !== $userId && $edge['to'] !== $userId) continue;
            $degree++;
            $weightedDegree += $edge['weight'];
        }

        return ['degree' => $degree, 'weighted_degree' => $weightedDegree, 'is_hub' => $degree > 10];
    }

    /** @param Graph $graph */
    private function detectBotNetwork(array $graph): float
    {
        $nodes = $graph['nodes'];
        $size = count($nodes);
        if ($size < 3) return 0.0;

        $botLikeCount = 0;
        foreach ($nodes as $node) {
            if ($node['age_days'] < 7 && $node['fraud_score'] > 50) $botLikeCount++;
        }

        $ratio = $botLikeCount / $size;
        return $ratio > 0.5 ? $ratio : 0.0;
    }

    /** @param NetworkAnalysis $analysis */
    private function calculateNetworkRisk(array $analysis): float
    {
        $riskScore = 0.0;
        if ($analysis['cluster_analysis']['is_suspicious']) $riskScore += 0.3;
        if ($analysis['ip_sharing']['is_suspicious']) $riskScore += 0.2;
        if ($analysis['circular_transactions']['detected']) $riskScore += 0.25;
        if ($analysis['centrality']['is_hub']) $riskScore += 0.15;
        $riskScore += $analysis['bot_network_risk'] * 0.1;
        return min(1.0, $riskScore);
    }

    /** @return array{is_sybil: bool, shared_devices: list<\stdClass>} */
    public function detectSybilNetwork(int $userId): array
    {
        $deviceSharing = $this->model->getDeviceSharing($userId);
        foreach ($deviceSharing as $device) {
            if ($this->rowInt($device, 'account_count') >= 3) {
                return ['is_sybil' => true, 'shared_devices' => $deviceSharing];
            }
        }
        return ['is_sybil' => false, 'shared_devices' => $deviceSharing];
    }

    private function rowString(\stdClass $row, string $field, string $default = ''): string
    {
        $value = get_object_vars($row)[$field] ?? null;
        return is_scalar($value) ? (string)$value : $default;
    }

    private function rowInt(\stdClass $row, string $field, int $default = 0): int
    {
        $value = get_object_vars($row)[$field] ?? null;
        if (is_int($value)) return $value;
        if (is_string($value) && preg_match('/^-?\d+$/', $value) === 1) return (int)$value;
        return $default;
    }

    private function rowPositiveInt(\stdClass $row, string $field): ?int
    {
        $value = $this->rowInt($row, $field);
        return $value > 0 ? $value : null;
    }

    private function rowBool(\stdClass $row, string $field): bool
    {
        $value = get_object_vars($row)[$field] ?? null;
        return filter_var($value, FILTER_VALIDATE_BOOLEAN);
    }

    private function calculateAccountAge(string $createdAt): int
    {
        $created = strtotime($createdAt);
        return $created === false ? 0 : max(0, (int)((time() - $created) / 86400));
    }
}
