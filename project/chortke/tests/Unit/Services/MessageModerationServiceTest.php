<?php

namespace Tests\Unit\Services;

use PHPUnit\Framework\TestCase;
use App\Services\MessageModerationService;
use Mockery as m;

/**
 * تست حرفه‌ای MessageModerationService — رفتار، لبه‌ها و قراردادها.
 *
 * پوشش:
 *   - approveReport: گزارش ناموجود → خطا + rollback
 *   - approveReport: گزارش غیر pending → خطا + rollback
 *   - approveReport: action نامعتبر → خطا + rollback
 *   - approveReport: action معتبر 'delete' → تغییر وضعیت + commit
 *   - dismissReport: گزارش غیر pending → false + rollback
 *   - dismissReport: موفق → تغییر وضعیت dismissed + commit
 */
class MessageModerationServiceTest extends TestCase
{
    /** @var \Core\EventDispatcher&\Mockery\MockInterface */
    private \Core\EventDispatcher $eventDispatcher;
    /** @var \Core\Database&\Mockery\MockInterface */
    private \Core\Database $database;
    /** @var \App\Contracts\LoggerInterface&\Mockery\MockInterface */
    private \App\Contracts\LoggerInterface $logger;
    /** @var \App\Models\InteractionModel&\Mockery\MockInterface */
    private \App\Models\InteractionModel $interactionModel;
    /** @var \App\Models\MessageModerationModel&\Mockery\MockInterface */
    private \App\Models\MessageModerationModel $moderationModel;
    /** @var \Core\Cache&\Mockery\MockInterface */
    private \Core\Cache $cache;
    private MessageModerationService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->eventDispatcher = m::mock('Core\EventDispatcher');
        $this->database = m::mock('Core\Database');
        $this->logger = m::mock('App\Contracts\LoggerInterface');
        $this->interactionModel = m::mock('App\Models\InteractionModel');
        $this->moderationModel = m::mock('App\Models\MessageModerationModel');
        $this->cache = m::mock('Core\Cache');

        $this->service = new MessageModerationService(
            $this->eventDispatcher,
            $this->cache,
            $this->database,
            $this->logger,
            $this->interactionModel,
            $this->moderationModel,
            m::mock('App\Services\AuditTrail'),  // $auditTrail
            null,  // $appSettings
            null,  // $userService
            null   // $outbox
        );
    }

    protected function tearDown(): void
    {
        m::close();
    }

    /** @test */
    public function service_can_be_instantiated(): void
    {
        $this->assertInstanceOf(MessageModerationService::class, $this->service);
    }

    /**
     * @test
     * وقتی گزارش وجود نداشته باشد، approve باید خطا بدهد و تراکنش rollback شود.
     */
    public function approveReport_rolls_back_when_report_not_found(): void
    {
        $stmt = m::mock(\PDOStatement::class);
        $stmt->shouldReceive('fetch')->andReturn(false);

        $this->database->shouldReceive('beginTransaction')->once();
        $this->database->shouldReceive('query')->andReturn($stmt);
        $this->database->shouldReceive('rollback')->once();

        $result = $this->service->approveReport(999, 'warn', 1);

        $this->assertFalse($result['success']);
        $this->assertSame('گزارش یافت نشد', $result['message']);
    }

    /**
     * @test
     * گزارش‌های غیر pending نباید دوباره تعیین تکلیف شوند (Fail-Closed).
     */
    public function approveReport_rejects_non_pending_report(): void
    {
        $stmt = m::mock(\PDOStatement::class);
        // فراخوانی‌های متوالی fetch: ابتدا baseReport (sender_id)، سپس report (status=closed)
        $stmt->shouldReceive('fetch')
            ->andReturn(['sender_id' => 5], ['report_status' => 'closed', 'sender_id' => 5, 'message_id' => 10]);

        $this->database->shouldReceive('beginTransaction')->once();
        $this->database->shouldReceive('query')->andReturn($stmt);
        $this->database->shouldReceive('rollback')->once();

        $result = $this->service->approveReport(1, 'warn', 1);

        $this->assertFalse($result['success']);
        $this->assertIsString($result['message']);
        $this->assertStringContainsString('قبلاً تعیین تکلیف', $result['message']);
    }

    /**
     * @test
     * action ناشناخته نباید هیچ تغییری ایجاد کند و باید rollback شود.
     */
    public function approveReport_rejects_unknown_action(): void
    {
        $stmt = m::mock(\PDOStatement::class);
        $stmt->shouldReceive('fetch')
            ->andReturn(['sender_id' => 5], ['report_status' => 'pending', 'sender_id' => 5, 'message_id' => 10]);

        $this->database->shouldReceive('beginTransaction')->once();
        $this->database->shouldReceive('query')->andReturn($stmt);
        $this->database->shouldReceive('rollback')->once();

        $result = $this->service->approveReport(1, 'nonsense', 1);

        $this->assertFalse($result['success']);
        $this->assertSame('اقدام نامعتبر است', $result['message']);
    }

    /**
     * @test
     * action معتبر 'delete' باید پیام را حذف کند، وضعیت را resolved کند و commit کند.
     */
    public function approveReport_performs_valid_delete_action_and_commits(): void
    {
        $stmt = m::mock(\PDOStatement::class);
        $stmt->shouldReceive('fetch')
            ->andReturn(['sender_id' => 5], ['report_status' => 'pending', 'sender_id' => 5, 'message_id' => 10]);

        $this->database->shouldReceive('beginTransaction')->once();
        $this->database->shouldReceive('query')->andReturn($stmt);
        $this->database->shouldReceive('commit')->once();

        $this->moderationModel->shouldReceive('updateReportStatus')
            ->once()
            ->with(1, 'resolved', 42)
            ->andReturn(true);

        $this->logger->shouldReceive('info')->once(); // message_deleted_by_admin
        $this->eventDispatcher->shouldReceive('dispatch')->andReturnNull(); // cache.invalidate (deleteMessage + approve)

        $result = $this->service->approveReport(1, 'delete', 42);

        $this->assertTrue($result['success']);
        $this->assertSame('گزارش تایید شد', $result['message']);
    }

    /**
     * @test
     * dismissReport روی گزارش غیر pending باید false برگرداند و rollback شود.
     */
    public function dismissReport_returns_false_when_status_is_not_pending(): void
    {
        $stmt = m::mock(\PDOStatement::class);
        $stmt->shouldReceive('fetchColumn')->andReturn('closed');

        $this->database->shouldReceive('beginTransaction')->once();
        $this->database->shouldReceive('query')->andReturn($stmt);
        $this->database->shouldReceive('rollback')->once();

        $this->assertFalse($this->service->dismissReport(1, 7));
    }

    /**
     * @test
     * dismissReport روی گزارش pending باید وضعیت را dismissed کند و commit کند.
     * (این لبه، باگِ toObject(fetchColumn) را پوشش می‌دهد که باعث false برگشتن همیشگی می‌شد.)
     */
    public function dismissReport_commits_and_returns_true_on_pending_report(): void
    {
        $stmt = m::mock(\PDOStatement::class);
        $stmt->shouldReceive('fetchColumn')->andReturn('pending');

        $this->database->shouldReceive('beginTransaction')->once();
        $this->database->shouldReceive('query')->andReturn($stmt);
        $this->database->shouldReceive('commit')->once();
        $this->eventDispatcher->shouldReceive('dispatch')->andReturnNull();

        $this->moderationModel->shouldReceive('updateReportStatus')
            ->once()
            ->with(1, 'dismissed', 7)
            ->andReturn(true);

        $this->assertTrue($this->service->dismissReport(1, 7));
    }
}
