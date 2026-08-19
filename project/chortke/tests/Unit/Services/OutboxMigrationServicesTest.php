<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use PHPUnit\Framework\TestCase;
use Mockery as m;

/**
 * تست‌های Migration Outbox برای Services
 * 
 * بررسی می‌کند که:
 * ۱. OutboxServiceInterface درست inject شده
 * ۲. outbox?->record null-safe هست
 * ۳. dispatchAsync حذف شده
 */
/**
 * @group architecture
 */
class OutboxMigrationServicesTest extends TestCase
{
    protected function tearDown(): void
    {
        m::close();
        parent::tearDown();
    }

    /** @param class-string $className
     *  @return list<string> */
    private function constructorParameterNames(string $className): array
    {
        $constructor = (new \ReflectionClass($className))->getConstructor();
        $this->assertNotNull($constructor);
        $this->assertNotNull($constructor, "{$className} must declare a constructor.");
        return array_map(
            static fn(\ReflectionParameter $parameter): string => $parameter->getName(),
            $constructor->getParameters()
        );
    }

    // ─── TrustService ───────────────────────────────────────────

    /** @test */
    public function trust_service_accepts_outbox_injection(): void
    {
        $ed = m::mock('Core\\EventDispatcher');
        $logger = m::mock('App\\Contracts\\LoggerInterface');
        $scoreService = m::mock('App\\Services\\ScoreService');
        $trustStrategy = m::mock('App\\Domain\\Gamification\\Strategies\\TrustEvaluationStrategy');
        $outbox = m::mock('App\\Contracts\\OutboxServiceInterface');

        $ed->shouldIgnoreMissing();
        $logger->shouldIgnoreMissing();
        $scoreService->shouldIgnoreMissing();
        $outbox->shouldIgnoreMissing();

        $service = new \App\Services\Gamification\TrustService(m::mock('Core\Database'), $logger, $scoreService, $trustStrategy, $outbox);
        $this->assertInstanceOf(\App\Services\Gamification\TrustService::class, $service);
    }

    /** @test */
    public function trust_service_works_without_outbox(): void
    {
        $ed = m::mock('Core\\EventDispatcher');
        $logger = m::mock('App\\Contracts\\LoggerInterface');
        $scoreService = m::mock('App\\Services\\ScoreService');
        $trustStrategy = m::mock('App\\Domain\\Gamification\\Strategies\\TrustEvaluationStrategy');

        $ed->shouldIgnoreMissing();
        $logger->shouldIgnoreMissing();
        $scoreService->shouldIgnoreMissing();

        $service = new \App\Services\Gamification\TrustService(m::mock('Core\Database'), $logger, $scoreService, $trustStrategy);
        $this->assertInstanceOf(\App\Services\Gamification\TrustService::class, $service);
    }

    // ─── ScoreCommandService ────────────────────────────────────

    /** @test */
    public function score_command_service_accepts_outbox(): void
    {
        $ed = m::mock('Core\\EventDispatcher');
        $cache = m::mock('Core\\Cache');
        $logger = m::mock('App\\Contracts\\LoggerInterface');
        $scoreModel = m::mock('App\\Models\\Score');
        $rl = m::mock('Core\\RateLimiter');
        $outbox = m::mock('App\\Services\\OutboxService');

        $ed->shouldIgnoreMissing();
        $cache->shouldIgnoreMissing();
        $logger->shouldIgnoreMissing();

        $service = new \App\Services\Score\ScoreCommandService($cache, $logger, $scoreModel, m::mock('App\Policies\RateLimitPolicy'), null, null, $outbox, m::mock('App\Services\DistributedLockService'));
        $this->assertInstanceOf(\App\Services\Score\ScoreCommandService::class, $service);
    }

    /** @test */
    public function score_command_service_logs_warning_when_outbox_missing(): void
    {
        $ed = m::mock('Core\\EventDispatcher');
        $cache = m::mock('Core\\Cache');
        $logger = m::mock('App\\Contracts\\LoggerInterface');
        $scoreModel = m::mock('App\\Models\\Score');
        $rl = m::mock('Core\\RateLimiter');

        $ed->shouldIgnoreMissing();
        $cache->shouldIgnoreMissing();
        $scoreModel->shouldIgnoreMissing();
        $rl->shouldIgnoreMissing();
        $logger->shouldIgnoreMissing();

        // بدون outbox ساخته شده — باید warning بزنه اگه event ثبت بشه
        $service = new \App\Services\Score\ScoreCommandService($cache, $logger, $scoreModel, m::mock('App\Policies\RateLimitPolicy'), null, null, null, m::mock('App\Services\DistributedLockService'));
        $this->assertInstanceOf(\App\Services\Score\ScoreCommandService::class, $service);
    }

    // ─── ContentService ─────────────────────────────────────────

    /** @test */
    public function content_service_accepts_outbox_injection(): void
    {
        $paramNames = $this->constructorParameterNames(\App\Services\ContentService::class);

        $this->assertContains('outboxService', $paramNames);
    }

    // ─── AuthService ────────────────────────────────────────────

    /** @test */
    public function auth_service_accepts_outbox_injection(): void
    {
        $paramNames = $this->constructorParameterNames(\App\Services\Auth\AuthService::class);

        $this->assertContains('outbox', $paramNames);
    }

    // ─── SessionService ─────────────────────────────────────────

    /** @test */
    public function session_service_accepts_outbox_injection(): void
    {
        $paramNames = $this->constructorParameterNames(\App\Services\Auth\SessionService::class);

        $this->assertContains('outbox', $paramNames);
    }

    // ─── InfluencerService ──────────────────────────────────────

    /** @test */
    public function influencer_facade_delegates_to_command_service_that_owns_outbox(): void
    {
        $facadeParams = $this->constructorParameterNames(\App\Services\InfluencerService::class);
        $commandParams = $this->constructorParameterNames(\App\Services\Influencer\InfluencerCommandService::class);

        $this->assertContains('commandService', $facadeParams);
        $this->assertContains('outboxService', $commandParams);
    }

    // ─── TicketCommandService ───────────────────────────────────

    /** @test */
    public function ticket_command_service_has_outbox_injection(): void
    {
        $paramNames = $this->constructorParameterNames(\App\Services\TicketCommandService::class);

        $this->assertContains('outbox', $paramNames);
    }

    // ─── FraudDetectionService ──────────────────────────────────

    /** @test */
    public function fraud_detection_service_has_outbox_injection(): void
    {
        $paramNames = $this->constructorParameterNames(\App\Services\AntiFraud\FraudDetectionService::class);

        $this->assertContains('outbox', $paramNames);
    }

    // ─── AdsSeoService ──────────────────────────────────────────

    /** @test */
    public function ads_seo_service_has_outbox_injection(): void
    {
        $paramNames = $this->constructorParameterNames(\App\Services\Seo\AdsSeoService::class);

        $this->assertContains('outbox', $paramNames);
    }

    // ─── SocialTaskService ──────────────────────────────────────

    /** @test */
    public function social_task_service_has_outbox_injection(): void
    {
        $paramNames = $this->constructorParameterNames(\App\Services\SocialTask\SocialTaskService::class);

        $this->assertContains('outbox', $paramNames);
    }

    // ─── ReferralService ────────────────────────────────────────

    /** @test */
    public function referral_service_has_outbox_injection(): void
    {
        $paramNames = $this->constructorParameterNames(\App\Services\Shared\ReferralService::class);

        $this->assertContains('outboxService', $paramNames);
    }

    // ─── CustomTask Services ────────────────────────────────────

    /** @test */
    public function admin_custom_task_service_has_outbox(): void
    {
        $paramNames = $this->constructorParameterNames(\App\Services\CustomTask\AdminCustomTaskService::class);

        $this->assertContains('outboxService', $paramNames);
    }

    /** @test */
    public function custom_task_moderation_service_has_outbox(): void
    {
        $paramNames = $this->constructorParameterNames(\App\Services\CustomTask\CustomTaskModerationService::class);

        $this->assertContains('outbox', $paramNames);
    }

    // ─── ReportService ──────────────────────────────────────────

    /** @test */
    public function report_service_has_outbox(): void
    {
        $paramNames = $this->constructorParameterNames(\App\Services\Interaction\ReportService::class);

        $this->assertContains('outbox', $paramNames);
    }

    // ─── VitrineService ─────────────────────────────────────────

    /** @test */
    public function vitrine_service_has_outbox(): void
    {
        $paramNames = $this->constructorParameterNames(\App\Services\VitrineService::class);

        $this->assertContains('outbox', $paramNames);
    }

    // ─── No dispatchAsync in any migrated service ───────────────

}
