<?php

declare(strict_types=1);

namespace Tests\Unit\Jobs;

use App\Jobs\CustomTask\CreateCustomTaskJob;
use App\Jobs\Notification\SendFromTemplateNotificationJob;
use App\Jobs\Notification\SendNotificationJob;
use App\Jobs\ProcessNotificationJob;
use App\Jobs\UpdateFraudScoreJob;
use App\Services\Notification\NotificationDispatcher;
use App\Services\Notification\NotificationTemplateService;
use App\Services\ScoreService;
use PHPUnit\Framework\TestCase;

final class JobPayloadBoundaryBehaviorTest extends TestCase
{
    public function test_create_custom_task_rejects_non_array_data_before_side_effects(): void
    {
        $job = new CreateCustomTaskJob(
            $this->createMock(\Core\RateLimiter::class),
            $this->createMock(\App\Services\Settings\AppSettings::class),
            $this->createMock(\Core\Database::class),
            $this->createMock(\App\Models\User::class),
            $this->createMock(\App\Models\Ads::class),
            $this->createMock(\App\Services\EscrowService::class),
            $this->createMock(\Core\Logger::class),
            $this->createMock(\App\Services\Shared\IdempotencyService::class)
        );

        $result = $job->handle(['creator_id' => 42, 'data' => 'invalid']);

        $this->assertFalse($result['success']);
        $this->assertSame('ساختار داده وظیفه نامعتبر است', $result['message']);
    }

    public function test_process_notification_rejects_unknown_channel(): void
    {
        $job = new ProcessNotificationJob(
            $this->createMock(NotificationDispatcher::class),
            $this->createMock(\App\Contracts\LoggerInterface::class)
        );

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('channel must be fcm or sms');
        $job->handle(['channel' => ['fcm'], 'user_ids' => [1]]);
    }

    public function test_process_notification_rejects_empty_recipient_contract(): void
    {
        $job = new ProcessNotificationJob(
            $this->createMock(NotificationDispatcher::class),
            $this->createMock(\App\Contracts\LoggerInterface::class)
        );

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('user_ids must be a non-empty array');
        $job->handle(['channel' => 'fcm', 'user_ids' => []]);
    }

    public function test_update_fraud_score_rejects_structurally_invalid_domain(): void
    {
        $job = new UpdateFraudScoreJob($this->createMock(ScoreService::class));

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('domain and source must be non-empty strings');
        $job->handle(['user_id' => 7, 'delta' => 1, 'domain' => ['fraud']]);
    }

    public function test_template_job_fails_fast_when_renderer_breaks_title_contract(): void
    {
        $templates = $this->createMock(NotificationTemplateService::class);
        $templates->expects($this->once())->method('renderTemplate')->willReturn([
            'title' => ['invalid'],
            'message' => 'valid message',
        ]);
        $sender = $this->createMock(SendNotificationJob::class);
        $sender->expects($this->never())->method('handle');
        $job = new SendFromTemplateNotificationJob($templates, $sender);

        $this->expectException(\UnexpectedValueException::class);
        $this->expectExceptionMessage('must render string title and message');
        $job->handle(7, 'security_alert');
    }
}
