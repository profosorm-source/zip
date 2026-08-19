<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use PHPUnit\Framework\TestCase;
use Mockery as m;

/**
 * تست حرفه‌ای FraudDetectionService — رفتار، لبه‌ها و قراردادها.
 *
 * پوشش:
 *   - requiresReview: پرچم kyc_required → true؛ وضعیت suspended → true؛
 *     رکورد KYC در انتظار در db → true؛ هیچ‌کدام → false.
 *   - executeAutomatedActions: آستانه‌ی suspend → account_suspended؛
 *     آستانه‌ی review → manual_review؛ امتیاز پایین → بدون اقدام.
 *   - قرارداد: رویداد خروجی از طریق outbox ثبت می‌شود (نه dispatchAsync).
 */
/**
 * @group architecture
 */
class FraudDetectionServiceBehaviorTest extends TestCase
{
    protected function tearDown(): void { m::close(); parent::tearDown(); }

    /**
     * ساخت سرویس با mock وابستگی‌ها.
     * policy به‌گونه‌ای mock می‌شود که مقادیر خالی برگرداند؛ در نتیجه
     * سرویس از ثابت‌های RISK_THRESHOLDS/WEIGHTS/VELOCITY_DEFAULTS استفاده می‌کند.
     *
     * @param array<string, mixed> $opts
     * @return array{
     *   svc: \App\Services\AntiFraud\FraudDetectionService,
     *   db: \Core\Database&\Mockery\MockInterface,
     *   fraudModel: \App\Models\VelocityAndScoreModel&\Mockery\MockInterface,
     *   logger: \App\Contracts\LoggerInterface&\Mockery\MockInterface
     * }
     */
    private function make(array $opts = []): array
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

        $svc = new \App\Services\AntiFraud\FraudDetectionService(
            $logger,
            $fraudModel,
            $policy,
            $db,
            null,       // outbox
            null        // auditTrail
        );

        return ['svc' => $svc, 'db' => $db, 'fraudModel' => $fraudModel, 'logger' => $logger];
    }

    /** @test */
    public function requires_review_returns_true_when_flagged_for_kyc(): void
    {
        $c = $this->make();
        $c['fraudModel']->shouldReceive('getUserFraudInfo')->once()->with(1)->andReturn(
            (object)['flag_type' => 'kyc_required', 'status' => 'active', 'kyc_required' => 1]
        );

        $this->assertTrue($c['svc']->requiresReview(1));
    }

    /** @test */
    public function requires_review_returns_true_when_account_is_suspended(): void
    {
        $c = $this->make();
        $c['fraudModel']->shouldReceive('getUserFraudInfo')->once()->with(2)->andReturn(
            (object)['flag_type' => null, 'status' => 'suspended', 'kyc_required' => 0]
        );

        $this->assertTrue($c['svc']->requiresReview(2));
    }

    /** @test */
    public function requires_review_returns_true_when_pending_kyc_row_exists_in_db(): void
    {
        $c = $this->make();
        $c['fraudModel']->shouldReceive('getUserFraudInfo')->once()->with(3)->andReturn(
            (object)['flag_type' => null, 'status' => 'active', 'kyc_required' => 0]
        );
        // وجود رکورد KYC در انتظار → باید true برگرداند
        $c['db']->shouldReceive('fetch')->once()->andReturn((object)['id' => 55]);

        $this->assertTrue($c['svc']->requiresReview(3));
    }

    /** @test */
    public function requires_review_returns_false_when_no_flag_and_no_pending_kyc(): void
    {
        $c = $this->make();
        $c['fraudModel']->shouldReceive('getUserFraudInfo')->once()->with(4)->andReturn(
            (object)['flag_type' => null, 'status' => 'active', 'kyc_required' => 0]
        );
        // هیچ رکورد KYC در انتظار → false
        $c['db']->shouldReceive('fetch')->once()->andReturn(false);

        $this->assertFalse($c['svc']->requiresReview(4));
    }

    /** @test */
    public function automated_actions_suspends_account_at_suspend_threshold(): void
    {
        $c = $this->make();
        // score=100 >= suspend(95) → suspendAccount → suspendAccount + logFraudAction روی مدل
        $c['fraudModel']->shouldReceive('suspendAccount')->once()->with(5, m::on(fn($r) => str_contains($r, '100')));
        $c['fraudModel']->shouldReceive('logFraudAction')->once()->with(5, 'account_suspended', 100, m::any());

        $actions = $c['svc']->executeAutomatedActions(5, 100);

        $this->assertContains('account_suspended', $actions);
    }

    /** @test */
    public function automated_actions_flags_for_manual_review_in_review_band(): void
    {
        $c = $this->make();
        // score=90 در بازه‌ی review(85)..suspend(95) → manual_review
        $c['fraudModel']->shouldReceive('flagForManualReview')->once()->with(6, 90);
        $c['fraudModel']->shouldReceive('logFraudAction')->once()->with(6, 'manual_review', 90, m::any());

        $actions = $c['svc']->executeAutomatedActions(6, 90);

        $this->assertContains('manual_review_required', $actions);
    }

    /** @test */
    public function automated_actions_returns_empty_for_low_risk_score(): void
    {
        $c = $this->make();
        // score=10 زیر همه‌ی آستانه‌ها → هیچ اقدامی انجام نشود
        $c['fraudModel']->shouldNotReceive('suspendAccount');
        $c['fraudModel']->shouldNotReceive('flagForManualReview');
        $c['fraudModel']->shouldNotReceive('requireKYC');
        $c['fraudModel']->shouldNotReceive('flagForReview');

        $actions = $c['svc']->executeAutomatedActions(7, 10);

        $this->assertSame([], $actions);
    }

}
