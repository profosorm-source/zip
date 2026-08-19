<?php

namespace Tests\Unit\Services;

use PHPUnit\Framework\TestCase;
use App\Services\AntiFraud\FraudGuardService;
use App\Services\AntiFraud\RiskDecisionService;
use App\Services\AntiFraud\FraudDetectionService;
use App\Services\AntiFraud\FraudStrategyResolver;
use App\Contracts\AntiFraud\FraudCheckStrategyInterface;
use App\Contracts\LoggerInterface;

class FraudGuardServiceTest extends TestCase
{
    /** @test */
    public function test_fraud_guard_service_resolves_strategy_and_compiles_decision(): void
    {
        // 1. Arrange Mocks
        $riskDecisionMock = $this->createMock(RiskDecisionService::class);
        $fraudDetectionMock = $this->createMock(FraudDetectionService::class);
        $strategyResolverMock = $this->createMock(FraudStrategyResolver::class);
        $loggerMock = $this->createMock(LoggerInterface::class);
        $strategyMock = $this->createMock(FraudCheckStrategyInterface::class);

        // Define expectations
        $userId = 42;
        $action = 'payment.create';
        $context = ['ip' => '127.0.0.1'];
        $partialResults = ['velocity' => ['allowed' => true]];

        $strategyResolverMock->expects($this->once())
            ->method('resolve')
            ->with($action)
            ->willReturn($strategyMock);

        $strategyMock->expects($this->once())
            ->method('check')
            ->with($userId, $action, $context)
            ->willReturn($partialResults);

        $fraudDetectionMock->expects($this->once())
            ->method('calculateFraudScore')
            ->with($userId)
            ->willReturn(15);

        $riskDecisionMock->expects($this->once())
            ->method('decide')
            ->with($userId, [
                'ip'              => '127.0.0.1',
                'action'          => $action,
                'fraud_score'     => 15,
                'graph_risk'      => null,
                'partial_results' => $partialResults
            ])
            ->willReturn([
                'decision' => 'allow',
                'reason'   => 'Low risk score'
            ]);

        // 2. Act
        $service = new FraudGuardService(
            $loggerMock,
            $riskDecisionMock,
            $fraudDetectionMock,
            $strategyResolverMock
        );

        $result = $service->checkAction($userId, $action, $context);

        // 3. Assert
        $this->assertTrue($result['allowed']);
        $this->assertEquals('allow', $result['action']);
        $this->assertEquals(15, $result['score']);
        $this->assertEquals('Low risk score', $result['reason']);
        $this->assertArrayHasKey('details', $result);
    }

    /** @test */
    public function test_fraud_guard_service_handles_unknown_action_gracefully(): void
    {
        // 1. Arrange Mocks
        $riskDecisionMock = $this->createMock(RiskDecisionService::class);
        $fraudDetectionMock = $this->createMock(FraudDetectionService::class);
        $strategyResolverMock = $this->createMock(FraudStrategyResolver::class);
        $loggerMock = $this->createMock(LoggerInterface::class);

        // Define expectations
        $userId = 42;
        $action = 'unknown.action';
        $context = [];

        $strategyResolverMock->expects($this->once())
            ->method('resolve')
            ->with($action)
            ->willReturn(null); // No strategy found

        $fraudDetectionMock->expects($this->once())
            ->method('calculateFraudScore')
            ->with($userId)
            ->willReturn(0);

        $riskDecisionMock->expects($this->once())
            ->method('decide')
            ->willReturn([
                'decision' => 'block',
                'reason'   => 'Suspicious action'
            ]);

        // 2. Act
        $service = new FraudGuardService(
            $loggerMock,
            $riskDecisionMock,
            $fraudDetectionMock,
            $strategyResolverMock
        );

        $result = $service->checkAction($userId, $action, $context);

        // 3. Assert
        $this->assertFalse($result['allowed']);
        $this->assertEquals('block', $result['action']);
    }

    /** @test */
    public function malformed_internal_strategy_result_fails_closed(): void
    {
        $riskDecision = $this->createMock(RiskDecisionService::class);
        $fraudDetection = $this->createMock(FraudDetectionService::class);
        $resolver = $this->createMock(FraudStrategyResolver::class);
        $logger = $this->createMock(LoggerInterface::class);
        $strategy = $this->createMock(FraudCheckStrategyInterface::class);

        $resolver->method('resolve')->willReturn($strategy);
        $strategy->method('check')->willReturn(['seo_fraud' => 'broken-contract']);
        $fraudDetection->expects($this->never())->method('calculateFraudScore');

        $result = (new FraudGuardService($logger, $riskDecision, $fraudDetection, $resolver))
            ->checkAction(42, 'task.seo');

        $this->assertFalse($result['allowed']);
        $this->assertSame('deny', $result['action']);
        $this->assertSame('system_failure_fail_closed', $result['reason']);
        $this->assertSame(100, $result['score']);
        $this->assertIsArray($result['details']);
    }

    public function malformed_risk_decision_fails_closed(): void
    {
        $riskDecision = $this->createMock(RiskDecisionService::class);
        $fraudDetection = $this->createMock(FraudDetectionService::class);
        $resolver = $this->createMock(FraudStrategyResolver::class);
        $logger = $this->createMock(LoggerInterface::class);
        $strategy = $this->createMock(FraudCheckStrategyInterface::class);

        $resolver->method('resolve')->willReturn($strategy);
        $strategy->method('check')->willReturn([]);
        $fraudDetection->method('calculateFraudScore')->willReturn(5);
        $riskDecision->method('decide')->willReturn(['decision' => ['allow'], 'reason' => null]);

        $result = (new FraudGuardService($logger, $riskDecision, $fraudDetection, $resolver))
            ->checkAction(42, 'task.seo');

        $this->assertFalse($result['allowed']);
        $this->assertSame('system_failure_fail_closed', $result['reason']);
        $this->assertSame(100, $result['score']);
    }

    /** @test */
    public function high_ip_risk_score_blocks_without_triggering_system_failure(): void
    {
        $riskDecision = $this->createMock(RiskDecisionService::class);
        $fraudDetection = $this->createMock(FraudDetectionService::class);
        $resolver = $this->createMock(FraudStrategyResolver::class);
        $logger = $this->createMock(LoggerInterface::class);
        $strategy = $this->createMock(FraudCheckStrategyInterface::class);

        $resolver->method('resolve')->willReturn($strategy);
        $strategy->method('check')->willReturn([
            'ip_quality' => ['risk_score' => 95, 'status' => 'suspicious'],
        ]);
        $fraudDetection->method('calculateFraudScore')->willReturn(10);
        $riskDecision->method('decide')->willReturn([
            'decision' => 'allow',
            'reason' => 'base policy allowed',
        ]);

        $service = new FraudGuardService($logger, $riskDecision, $fraudDetection, $resolver);
        $result = $service->checkAction(42, 'auth.login', ['ip' => '203.0.113.10']);

        $this->assertFalse($result['allowed']);
        $this->assertSame('block', $result['action']);
        $this->assertSame('high_risk_ip', $result['reason']);
        $this->assertArrayHasKey('ip_quality', $result['details']);
    }
}
