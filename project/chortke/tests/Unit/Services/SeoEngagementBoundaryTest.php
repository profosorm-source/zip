<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Jobs\Seo\CancelSeoTaskJob;
use App\Jobs\Seo\CompleteSeoTaskJob;
use App\Jobs\Seo\ProcessSeoTaskAsyncJob;
use App\Jobs\Seo\RateSeoTaskJob;
use App\Jobs\Seo\ReportSeoTaskJob;
use App\Jobs\Seo\StartSeoTaskJob;
use App\Services\Seo\SeoService;
use PHPUnit\Framework\TestCase;

final class SeoEngagementBoundaryTest extends TestCase
{
    public function test_rejects_missing_required_metric_before_job_dispatch(): void
    {
        $complete = $this->createMock(CompleteSeoTaskJob::class);
        $complete->expects($this->never())->method('handle');
        $service = $this->service($complete);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('scroll_depth must be numeric');
        $service->completeTask(1, 2, ['duration' => 30, 'interactions' => 2]);
    }

    public function test_rejects_malformed_nested_behavior(): void
    {
        $complete = $this->createMock(CompleteSeoTaskJob::class);
        $complete->expects($this->never())->method('handle');
        $service = $this->service($complete);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('behavior must be an array');
        $service->completeTask(1, 2, [
            'duration' => 30,
            'scroll_depth' => 50,
            'interactions' => 2,
            'behavior' => 'broken',
        ]);
    }

    public function test_task_fraud_producer_rejects_malformed_detector_contract(): void
    {
        $detector = $this->createMock(\App\Services\AntiFraud\SeoFraudDetector::class);
        $detector->method('detect')->willReturn([
            'is_fraud' => 'no',
            'flags' => 'broken',
            'risk_score' => 0,
        ]);
        $job = new \App\Jobs\AntiFraud\CheckTaskFraudJob(
            $this->createMock(\App\Services\AntiFraud\IPQualityService::class),
            $this->createMock(\App\Services\AntiFraud\SessionAnomalyService::class),
            $this->createMock(\App\Services\AntiFraud\VelocityCheckService::class),
            $this->createMock(\App\Services\AntiFraud\BehavioralBiometricsService::class),
            $detector,
            $this->createMock(\App\Services\SocialTask\SilentAntiFraudService::class),
            $this->createMock(\App\Services\FeatureFlagService::class)
        );

        $this->expectException(\UnexpectedValueException::class);
        $this->expectExceptionMessage('boolean is_fraud');
        $job->handle(9, 'task.seo', [
            'ad_id' => 12,
            'engagement_data' => [],
            'session_id' => '',
        ]);
    }

    public function test_valid_payload_is_canonicalized_before_job_dispatch(): void
    {
        $complete = $this->createMock(CompleteSeoTaskJob::class);
        $complete->expects($this->once())->method('handle')->with(
            1,
            2,
            $this->callback(static function (array $data): bool {
                return $data['duration'] === 30
                    && $data['scroll_depth'] === 50.5
                    && $data['interactions'] === 2
                    && $data['client_mode'] === 'web'
                    && $data['interaction_types'] === ['click'];
            })
        )->willReturn(['success' => true]);
        $service = $this->service($complete);

        $this->assertSame(['success' => true], $service->completeTask(1, 2, [
            'duration' => '30',
            'scroll_depth' => '50.5',
            'interactions' => '2',
            'interaction_types' => ['click'],
        ]));
    }

    private function service(CompleteSeoTaskJob $complete): SeoService
    {
        return new SeoService(
            $this->createMock(StartSeoTaskJob::class),
            $complete,
            $this->createMock(ProcessSeoTaskAsyncJob::class),
            $this->createMock(CancelSeoTaskJob::class),
            $this->createMock(ReportSeoTaskJob::class),
            $this->createMock(RateSeoTaskJob::class)
        );
    }
}
