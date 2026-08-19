<?php

declare(strict_types=1);

namespace App\Services\AntiFraud;

use App\Contracts\LoggerInterface;
use App\Contracts\AntiFraud\FraudGuardInterface;
use App\Services\AntiFraud\GraphAnalysisService;
use App\Services\AntiFraud\MLFraudDetectionService;

final class FraudGuardService implements FraudGuardInterface
{
    private \App\Contracts\LoggerInterface $logger;
    private RiskDecisionService $riskDecision;
    private FraudDetectionService $fraudDetection;
    private FraudStrategyResolver $strategyResolver;
    private ?GraphAnalysisService $graphAnalysis = null;
    private ?MLFraudDetectionService $mlFraudDetection = null;
    public function __construct(
        \App\Contracts\LoggerInterface $logger,
        RiskDecisionService $riskDecision,
        FraudDetectionService $fraudDetection,
        FraudStrategyResolver $strategyResolver,
        ?GraphAnalysisService $graphAnalysis = null,
        ?MLFraudDetectionService $mlFraudDetection = null
    ) {        $this->logger = $logger;
        $this->riskDecision = $riskDecision;
        $this->fraudDetection = $fraudDetection;
        $this->strategyResolver = $strategyResolver;
        $this->graphAnalysis = $graphAnalysis;
        $this->mlFraudDetection = $mlFraudDetection;

            }

    /**
     * Unified evaluation entrypoint for processing user-action risks.
     * Decoupled constructor injection allows precise unit testing and avoids container anti-patterns.
     *
     * @param int $userId The user initiating the action.
     * @param string $action Unique tag identifying the flow (e.g., 'auth.login', 'payment.create').
     * @param array<string, mixed> $context Associated contextual facts (IP, user-agent, payload amounts, etc.).
     * @return array{allowed: bool, action: string, score: int, reason: string, details: array<string, mixed>} Unified evaluation format ['allowed' => bool, 'action' => 'allow|block|limit', 'reason' => string]
     */
    public function checkAction(int $userId, string $action, array $context = []): array
    {
        try {
            $this->logger->info("anti_fraud.check_initiated", [
                'user_id' => $userId,
                'action'  => $action,
            ]);

            // 📦 Refactored orchestration: Resolves the specialized strategy lazily to avoid booting unused dependencies
            $strategy = $this->strategyResolver->resolve($action);
            
            if (!$strategy) {
                $this->logger->warning("anti_fraud.unknown_action_called", ['action' => $action, 'user_id' => $userId]);
                $results = [];
            } else {
                $results = $strategy->check($userId, $action, $context);
                $this->assertPartialResults($results);
            }
            
            // Synthesize overall automatic fraud score engine
            $score = $this->fraudDetection->calculateFraudScore($userId);

            // 🛡️ Enhanced: Graph Analysis for bot/Sybil network detection
            $graphRisk = null;
            if ($this->graphAnalysis !== null) {
                try {
                    $graphResult = $this->graphAnalysis->detectSybilNetwork($userId);
                    if ($graphResult['is_sybil']) {
                        $graphRisk = 'sybil_network_detected';
                        $score = max($score, 85);
                        $this->logger->warning('anti_fraud.graph_sybil_detected', [
                            'user_id' => $userId, 'action' => $action,
                        ]);
                    }
                } catch (\Throwable $e) {
                    $this->logger->warning('anti_fraud.graph_analysis_failed', [
                        'error' => $e->getMessage(),
                    ]);
                    \App\Services\Sentry\SentryExceptionHandler::captureException($e, $userId, ['operation' => 'antifraud.fraudGuard.graphAnalysis']);
                }
            }

            // 🛡️ Enhanced: ML-based fraud scoring for sensitive actions
            if ($this->mlFraudDetection !== null) {
                $sensitiveActions = ['payment.create', 'withdrawal.create', 'wallet.transfer', 'auth.login'];
                if (in_array($action, $sensitiveActions, true)) {
                    try {
                        $mlResult = $this->mlFraudDetection->analyzeUser($userId, $context);
                        $mlLevel = $mlResult['risk_level'] ?? 'low';
                        if ($mlLevel === 'high' || $mlLevel === 'critical') {
                            $mlScoreVal = float_value($mlResult['risk_score'] ?? 0);
                            $score = max($score, (int) round($mlScoreVal * 100));
                            $results['ml_fraud'] = [
                                'score'      => $mlScoreVal,
                                'level'      => $mlLevel,
                                'factors'    => $mlResult['factors'] ?? [],
                                'recommendation' => $mlResult['recommendation'] ?? '',
                            ];
                        }
                    } catch (\Throwable $e) {
                        $this->logger->warning('anti_fraud.ml_scoring_failed', [
                            'error' => $e->getMessage(),
                        ]);
                        \App\Services\Sentry\SentryExceptionHandler::captureException($e, $userId, ['operation' => 'antifraud.fraudGuard.mlScoring', 'action' => $action]);
                    }
                }
            }

            // Execute unified policy decision map via RiskDecisionService
            $decision = $this->riskDecision->decide($userId, array_merge($context, [
                'action'          => $action,
                'fraud_score'     => $score,
                'graph_risk'      => $graphRisk,
                'partial_results' => $results
            ]));

            // Final decision logic compilator
            return $this->compileFinalDecision($userId, $action, $score, $results, $decision);

        } catch (\Throwable $e) {
            return $this->handleSystemFailure($userId, $action, $e);
        }
    }

    /** @param array<string, mixed> $results */
    private function assertPartialResults(array $results): void
    {
        foreach ($results as $name => $partial) {
            if (!is_string($name) || $name === '' || !is_array($partial)) {
                throw new \UnexpectedValueException('Fraud strategy results must map non-empty names to arrays.');
            }
        }
    }

    /**
     * Compiler aggregating raw partial decisions into deterministic final system responses.
     */
    /**
     * @param array<string, mixed> $results
     * @param array<string, mixed> $decision
     * @return array{allowed: bool, action: string, score: int, reason: string, details: array<string, mixed>}
     */
    private function compileFinalDecision(int $userId, string $action, int $score, array $results, array $decision): array
    {
        $decisionAction = $decision['decision'] ?? null;
        $decisionReason = $decision['reason'] ?? null;
        if (!is_string($decisionAction) || $decisionAction === '' || !is_string($decisionReason) || $decisionReason === '') {
            throw new \UnexpectedValueException('RiskDecisionService must return non-empty string decision and reason.');
        }
        $isAllowed = !in_array($decisionAction, ['block', 'suspend'], true);
        $finalReason = $decisionReason;
        $finalAction = $decisionAction;

        // Deterministic sub-check override (e.g. if explicit velocity control failed)
        if (isset($results['velocity']) && is_array($results['velocity']) && !empty($results['velocity']['allowed']) === false) {
            $isAllowed = false;
            $finalAction = 'limit';
            $finalReason = str_value($results['velocity']['reason'] ?? 'Velocity limits exceeded');
        }
        
        if (isset($results['rate_limit']) && is_array($results['rate_limit']) && !empty($results['rate_limit']['allowed']) === false) {
            $isAllowed = false;
            $finalAction = 'block';
            $finalReason = 'Rate limits exceeded';
        }

        foreach ((array)$results as $name => $partial) {
            if (is_array($partial) && array_key_exists('allowed', $partial) && !$partial['allowed']) {
                $isAllowed = false;
                $finalAction = $name === 'signature' ? 'block' : 'limit';
                $finalReason = $partial['reason'] ?? ($name . '_blocked');
                break;
            }
        }

        $ipQuality = $results['ip_quality'] ?? null;
        $rawIpRiskScore = is_array($ipQuality) ? ($ipQuality['risk_score'] ?? null) : null;
        $ipRiskScore = is_int($rawIpRiskScore) || is_float($rawIpRiskScore) || is_string($rawIpRiskScore)
            ? (int)$rawIpRiskScore
            : 0;

        if ($ipRiskScore >= 90) {
            $isAllowed = false;
            $finalAction = 'block';
            $finalReason = 'high_risk_ip';
        }

        $this->logger->info("anti_fraud.check_completed", [
            'user_id'  => $userId,
            'action'   => $action,
            'allowed'  => $isAllowed,
            'decision' => $finalAction,
            'reason'   => $finalReason
        ]);

        return [
            'allowed' => $isAllowed,
            'action'  => $finalAction,
            'score'   => $score,
            'reason'  => $finalReason,
            'details' => array_merge($results, ['decision_payload' => $decision])
        ];
    }

    /**
     * Standardized fail-safe recovery strategy.
     */
    /** @return array{allowed: bool, action: string, score: int, reason: string, details: array<string, mixed>} */
    private function handleSystemFailure(int $userId, string $action, \Throwable $e): array
    {
        // Root fix (H-11): fail-closed by default. Every fraud-guarded action in this
        // system is financial or reward-bearing, so an outage of the fraud engine must
        // NOT silently grant the action. Fail-open is permitted ONLY for actions that
        // are explicitly non-financial and carry no monetary/reward side effect.
        $failOpenRaw = config('antifraud.fail_open_actions', []);
        $failOpenAllowlist = is_array($failOpenRaw) ? $failOpenRaw : [];
        $isFailOpen = in_array($action, $failOpenAllowlist, true);

        $this->logger->error("anti_fraud.system_failure", [
            'user_id' => $userId,
            'action'  => $action,
            'error'   => $e->getMessage(),
            'fail_mode' => $isFailOpen ? 'fail-open' : 'fail-closed'
        ]);
        \App\Services\Sentry\SentryExceptionHandler::captureException($e, $userId, [
            'operation'   => 'antifraud.fraudGuard.checkAction',
            'action'      => $action,
            'fail_open'   => $isFailOpen,
        ]);

        return [
            'allowed' => $isFailOpen,
            'action'  => $isFailOpen ? 'allow' : 'deny',
            'reason'  => $isFailOpen ? 'system_failure_fail_open' : 'system_failure_fail_closed',
            'score'   => $isFailOpen ? 0 : 100,
            'details' => ['error' => $e->getMessage()]
        ];
    }
}
