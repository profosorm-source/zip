<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use PHPUnit\Framework\TestCase;
use Mockery as m;

/**
 * تست حرفه‌ای FraudDetectionService::calculateFraudScore — رفتار و لبه‌ها.
 *
 * پوشش:
 *   - سناریوی ریسک پایین (حساب قدیمی، شهرت بالا، بدون velocity/ناهنجاری) → امتیاز پایین.
 *   - سناریوی ریسک بالا (حساب تازه، بدون شهرت، velocity و ناهنجاری جغرافیایی زیاد) → امتیاز بالا.
 *   - ثبت محاسبه (logFraudCalculation) و انتشار رویداد از طریق outbox (fraud.score_updated).
 *   - محصور بودن امتیاز در بازه‌ی [0,100].
 */
/**
 * @group architecture
 */
class FraudDetectionServiceTest extends TestCase
{
    protected function tearDown(): void { m::close(); parent::tearDown(); }

    /**
     * @return array{
     *   svc: \App\Services\AntiFraud\FraudDetectionService,
     *   fraudModel: \App\Models\VelocityAndScoreModel&\Mockery\MockInterface,
     *   outbox: \App\Contracts\OutboxServiceInterface&\Mockery\MockInterface,
     *   db: \Core\Database&\Mockery\MockInterface
     * }
     */
    private function make(): array
    {
        $db = m::mock('Core\Database');
        $db->shouldIgnoreMissing();
        $fraudModel = m::mock('App\Models\VelocityAndScoreModel');
        $fraudModel->shouldIgnoreMissing();
        $policy = m::mock('App\Services\AntiFraud\RiskPolicyService');
        $policy->shouldReceive('getArray')->andReturn([]);
        $logger = m::mock('App\Contracts\LoggerInterface');
        $logger->shouldIgnoreMissing();
        $dispatcher = m::mock('Core\EventDispatcher');
        $dispatcher->shouldIgnoreMissing();
        $outbox = m::mock('App\Contracts\OutboxServiceInterface');
        $outbox->shouldIgnoreMissing();

        $svc = new \App\Services\AntiFraud\FraudDetectionService(
            $logger,
            $fraudModel,
            $policy,
            $db,
            $outbox,
            null
        );

        return ['svc' => $svc, 'fraudModel' => $fraudModel, 'outbox' => $outbox, 'db' => $db];
    }

    /** @param \App\Models\VelocityAndScoreModel&\Mockery\MockInterface $fraudModel */
    private function stubLowRiskFactors(\App\Models\VelocityAndScoreModel $fraudModel, int $userId): void
    {
        $fraudModel->shouldReceive('getAccountAge')->with($userId)->andReturn(365);
        $fraudModel->shouldReceive('getUserReputation')->with($userId)->andReturn(100);
        $fraudModel->shouldReceive('getDailyTransactionCount')->with($userId)->andReturn(0);
        $fraudModel->shouldReceive('getWeeklyTransactionCount')->with($userId)->andReturn(0);
        $fraudModel->shouldReceive('getPreviousWeeklyTransactionCount')->with($userId)->andReturn(0);
        $fraudModel->shouldReceive('getCountryChanges')->with($userId)->andReturn(0);
        $fraudModel->shouldReceive('getCityChanges')->with($userId)->andReturn(0);
        $fraudModel->shouldReceive('getSuspiciousIPCount')->with($userId)->andReturn(0);
    }

    /** @test */
    public function low_risk_profile_yields_low_fraud_score(): void
    {
        $c = $this->make();
        $this->stubLowRiskFactors($c['fraudModel'], 1);

        $score = $c['svc']->calculateFraudScore(1);

        $this->assertGreaterThanOrEqual(0, $score);
        $this->assertLessThanOrEqual(30, $score, 'پروفایل کم‌ریسک نباید امتیاز بالایی بگیرد');
    }

    /** @test */
    public function high_risk_profile_yields_high_fraud_score(): void
    {
        $c = $this->make();
        // حساب تازه + بدون شهرت + velocity بالا + ناهنجاری جغرافیایی زیاد
        $c['fraudModel']->shouldReceive('getAccountAge')->with(2)->andReturn(1);
        $c['fraudModel']->shouldReceive('getUserReputation')->with(2)->andReturn(0);
        $c['fraudModel']->shouldReceive('getDailyTransactionCount')->with(2)->andReturn(50);
        $c['fraudModel']->shouldReceive('getWeeklyTransactionCount')->with(2)->andReturn(150);
        $c['fraudModel']->shouldReceive('getPreviousWeeklyTransactionCount')->with(2)->andReturn(10);
        $c['fraudModel']->shouldReceive('getCountryChanges')->with(2)->andReturn(4);
        $c['fraudModel']->shouldReceive('getCityChanges')->with(2)->andReturn(6);
        $c['fraudModel']->shouldReceive('getSuspiciousIPCount')->with(2)->andReturn(8);

        $score = $c['svc']->calculateFraudScore(2);

        $this->assertGreaterThanOrEqual(50, $score, 'پروفایل پرریسک باید امتیاز بالایی بگیرد');
        $this->assertLessThanOrEqual(100, $score);
    }

    /** @test */
    public function score_is_clamped_to_valid_range(): void
    {
        $c = $this->make();
        // عوامل افراطی → امتیاز نباید از ۱۰۰ تجاوز کند
        $c['fraudModel']->shouldReceive('getAccountAge')->with(3)->andReturn(0);
        $c['fraudModel']->shouldReceive('getUserReputation')->with(3)->andReturn(0);
        $c['fraudModel']->shouldReceive('getDailyTransactionCount')->with(3)->andReturn(1000);
        $c['fraudModel']->shouldReceive('getWeeklyTransactionCount')->with(3)->andReturn(5000);
        $c['fraudModel']->shouldReceive('getPreviousWeeklyTransactionCount')->with(3)->andReturn(2000);
        $c['fraudModel']->shouldReceive('getCountryChanges')->with(3)->andReturn(50);
        $c['fraudModel']->shouldReceive('getCityChanges')->with(3)->andReturn(50);
        $c['fraudModel']->shouldReceive('getSuspiciousIPCount')->with(3)->andReturn(50);

        $score = $c['svc']->calculateFraudScore(3);

        $this->assertGreaterThanOrEqual(0, $score);
        $this->assertLessThanOrEqual(100, $score);
    }

    /** @test */
    public function fraud_score_event_is_published_via_outbox(): void
    {
        $c = $this->make();
        $this->stubLowRiskFactors($c['fraudModel'], 4);

        $captured = [];
        $c['outbox']->shouldReceive('record')
            ->with('fraud', 4, 'fraud.score_updated', m::on(function ($data) use (&$captured) {
                $captured = $data;
                return isset($data['user_id'], $data['fraud_score']);
            }));

        $c['svc']->calculateFraudScore(4);

        $this->assertSame(4, $captured['user_id']);
        $this->assertIsInt($captured['fraud_score']);
    }

}
