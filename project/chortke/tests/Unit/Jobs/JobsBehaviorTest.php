<?php

declare(strict_types=1);

namespace Tests\Unit\Jobs;

use App\Contracts\LoggerInterface;
use App\Contracts\OutboxServiceInterface;
use App\Contracts\WalletServiceInterface;
use App\Jobs\CustomTask\CronSubmissionsJob;
use App\Jobs\VitrineListingExpiryJob;
use App\Models\Ads;
use App\Models\CustomTaskSubmissionModel;
use App\Services\CustomTask\CustomTaskModerationService;
use App\Services\Notification\NotificationTemplateService;
use App\Services\OutboxService;
use App\Services\Settings\AppSettings;
use Core\Cache;
use Core\Database;
use Core\EventDispatcher;
use Core\Logger;
use Mockery as m;
use PHPUnit\Framework\TestCase;

/** Runtime behavior of scheduled jobs; no source inspection. */
final class JobsBehaviorTest extends TestCase
{
    protected function tearDown(): void { m::close(); parent::tearDown(); }

    public function test_cron_auto_approval_updates_submission_and_records_notification_outbox(): void
    {
        $submission=(object)['id'=>71,'worker_id'=>9,'task_id'=>12,'task_title'=>'Runtime task'];
        $model=m::mock(CustomTaskSubmissionModel::class);
        $model->shouldReceive('getOldSubmissionsForAutoApproval')->once()->with(48)->andReturn([$submission]);
        $model->shouldReceive('submission_update')->once()->with(71,m::on(static fn(array $d): bool=>isset($d['auto_approved_at'])))->andReturn(true);
        $moderation=m::mock(CustomTaskModerationService::class);
        $moderation->shouldReceive('approveSubmission')->once()->with($submission)->andReturn(['success'=>true]);
        $settings=m::mock(AppSettings::class);$settings->shouldReceive('get')->once()->with('custom_task_auto_approve_hours',48)->andReturn(48);
        $templates=m::mock(NotificationTemplateService::class);
        $templates->shouldReceive('renderTemplate')->once()->with('submission_auto_approved',['task_title'=>'Runtime task'])->andReturn(['title'=>'Approved','message'=>'Done']);
        $outbox=m::mock(OutboxServiceInterface::class);
        $outbox->shouldReceive('record')->once()->with('notification',9,'notification.requested',m::on(static fn(array $p): bool=>($p['data']['submission_id']??null)===71));

        $job=new CronSubmissionsJob($model,$moderation,$settings,m::mock(Logger::class),m::mock(Database::class),m::mock(Ads::class),$templates,$outbox);
        $this->assertSame(1,$job->autoApproveOldSubmissions());
    }

    public function test_cron_does_not_emit_notification_when_moderation_rejects(): void
    {
        $submission=(object)['id'=>72,'worker_id'=>9,'task_id'=>12,'task_title'=>'Rejected task'];
        $model=m::mock(CustomTaskSubmissionModel::class);
        $model->shouldReceive('getOldSubmissionsForAutoApproval')->once()->andReturn([$submission]);
        $model->shouldNotReceive('submission_update');
        $moderation=m::mock(CustomTaskModerationService::class);
        $moderation->shouldReceive('approveSubmission')->once()->andReturn(['success'=>false]);
        $settings=m::mock(AppSettings::class);$settings->shouldReceive('get')->andReturn(48);
        $outbox=m::mock(OutboxServiceInterface::class);$outbox->shouldNotReceive('record');
        $job=new CronSubmissionsJob($model,$moderation,$settings,m::mock(Logger::class),m::mock(Database::class),m::mock(Ads::class),m::mock(NotificationTemplateService::class),$outbox);
        $this->assertSame(0,$job->autoApproveOldSubmissions());
    }

    public function test_expired_submission_commits_status_and_pending_count_atomically(): void
    {
        $submission=(object)['id'=>73,'task_id'=>44];
        $model=m::mock(CustomTaskSubmissionModel::class);
        $model->shouldReceive('submission_getExpiredSubmissions')->once()->andReturn([$submission]);
        $model->shouldReceive('submission_update')->once()->with(73,['status'=>'expired'])->andReturn(true);
        $db=m::mock(Database::class);
        $db->shouldReceive('beginTransaction')->once();$db->shouldReceive('commit')->once();$db->shouldNotReceive('rollback');
        $task=m::mock(Ads::class);$task->shouldReceive('decrementPendingCount')->once()->with(44)->andReturn(true);
        $job=new CronSubmissionsJob($model,m::mock(CustomTaskModerationService::class),m::mock(AppSettings::class),m::mock(Logger::class),$db,$task,m::mock(NotificationTemplateService::class));
        $this->assertSame(1,$job->expireOldSubmissions());
    }

    public function test_vitrine_expiry_updates_rows_records_outbox_and_invalidates_cache(): void
    {
        $listing=(object)['id'=>81,'user_id'=>7];
        $db=m::mock(Database::class);
        $db->shouldReceive('fetchAll')->once()->with(m::on(static fn(string $sql): bool=>str_contains($sql,"status = 'active'")))->andReturn([$listing]);
        $db->shouldReceive('execute')->once()->with(m::on(static fn(string $sql): bool=>str_contains($sql,"status = 'expired'")),[81])->andReturn(1);
        $db->shouldReceive('fetchAll')->once()->with(m::on(static fn(string $sql): bool=>str_contains($sql,'hold_released = 0')))->andReturn([]);
        $outbox=m::mock(OutboxService::class);
        $outbox->shouldReceive('record')->once()->with('vitrine',81,'vitrine.listing_expired',['id'=>81,'user_id'=>7]);
        $cache=m::mock(Cache::class);$cache->shouldReceive('forget')->once()->with('vitrine:active_listings');
        $events=m::mock(EventDispatcher::class);$events->shouldReceive('dispatch')->once()->with('cache.invalidate',['key'=>'vitrine']);
        $logger = m::mock(LoggerInterface::class);
        $logger->shouldIgnoreMissing();
        $job=new VitrineListingExpiryJob($db,m::mock(WalletServiceInterface::class),$logger,$events,$cache,$outbox);
        $job->handle();
        $this->addToAssertionCount(1);
    }
}
