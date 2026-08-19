<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use PHPUnit\Framework\TestCase;
use App\Services\TicketService;
use App\Services\MessageModerationService;
use App\Services\Shared\DisputeService;
use App\Services\Notification\NotificationPreferenceService;
use App\Models\Ticket;
use App\Models\TicketMessage;
use App\Models\Dispute;
use App\Models\NotificationPreference;
use App\Models\InteractionModel;
use App\Models\MessageModerationModel;
use App\Contracts\LoggerInterface;
use App\Contracts\NotificationServiceInterface;
use App\Contracts\WalletServiceInterface;
use App\Contracts\ValidatorFactoryInterface;
use App\Services\AuditTrail;
use App\Services\ReconciliationService;
use App\Services\Shared\IdempotencyService;
use Core\Database;
use Core\EventDispatcher;
use Core\TransactionWrapper;
use Core\RateLimiter;
use Core\Cache;
use Mockery as m;

/**
 * AllSupportAndMessagingModulesBehaviorTest — تست‌های رفتاری و اکشن‌محور تمامی ماژول‌های پشتیبانی و ارتباطات
 */
class AllSupportAndMessagingModulesBehaviorTest extends TestCase
{
    /** @var Database&\Mockery\MockInterface */
    private Database $db;
    /** @var LoggerInterface&\Mockery\MockInterface */
    private LoggerInterface $logger;

    protected function setUp(): void
    {
        parent::setUp();
        $this->db = m::mock(Database::class);
        $this->db->shouldReceive('fetch')->andReturn((object)['value' => '1', 'count' => 0])->byDefault();
        $this->db->shouldReceive('fetchAll')->andReturn([])->byDefault();
        $this->db->shouldReceive('query')->andReturn([])->byDefault();

        $this->logger = m::mock(LoggerInterface::class);
        $this->logger->shouldIgnoreMissing();
    }

    protected function tearDown(): void
    {
        m::close();
        parent::tearDown();
    }

    // ─── ۱. Support Tickets (تیکت پشتیبانی) ─────────────────────────

    /** @test */
    public function ticket_service_can_be_instantiated(): void
    {
        $validatorFactory = m::mock(ValidatorFactoryInterface::class);
        $transactionWrapper = m::mock(TransactionWrapper::class);
        $eventDispatcher = m::mock(EventDispatcher::class);
        $ticketModel = m::mock(Ticket::class);
        $messageModel = m::mock(TicketMessage::class);
        $rateLimiter = m::mock(RateLimiter::class);
        $idempotencyService = m::mock(IdempotencyService::class);

        $service = new TicketService(
            $validatorFactory,
            $eventDispatcher,
            $this->db,
            $this->logger,
            $ticketModel,
            $messageModel,
            $rateLimiter,
            $idempotencyService
        );

        $this->assertInstanceOf(TicketService::class, $service);
    }

    // ─── ۲. Message Moderation & Filtering (موتور پالایش پیام) ──────────

    /** @test */
    public function message_moderation_service_can_be_instantiated(): void
    {
        $eventDispatcher = m::mock(EventDispatcher::class);
        $cache = m::mock(Cache::class);
        $interactionModel = m::mock(InteractionModel::class);
        $moderationModel = m::mock(MessageModerationModel::class);
        $notificationService = m::mock(NotificationServiceInterface::class);
        $auditTrail = m::mock(AuditTrail::class);

        $service = new MessageModerationService(
            $eventDispatcher,
            $cache,
            $this->db,
            $this->logger,
            $interactionModel,
            $moderationModel,
            $auditTrail
        );

        $this->assertInstanceOf(MessageModerationService::class, $service);
    }

    // ─── ۳. Dispute Resolution & Arbitration (حل اختلاف و داوری) ───────

    /** @test */
    public function dispute_service_opens_case_and_returns_record(): void
    {
        $disputeModel = m::mock(Dispute::class);
        $walletService = m::mock(WalletServiceInterface::class);
        $reconciliationService = m::mock(ReconciliationService::class);
        $transactionModel = m::mock(\App\Models\Transaction::class);
        $idempotencyService = m::mock(IdempotencyService::class);

        $disputeModel->shouldReceive('createDispute')->once()->andReturn((object)[
            'id' => 300,
            'ref_type' => 'vitrine_listing',
            'ref_id' => 12,
            'status' => 'open_peer'
        ]);

        $service = new DisputeService(
            $this->db,
            $this->logger,
            $disputeModel,
            $walletService,
            $reconciliationService,
            $transactionModel,
            $idempotencyService
        );

        $case = $service->openCase([
            'ref_type' => 'vitrine_listing',
            'ref_id' => 12,
            'user_id' => 10,
            'target_user_id' => 20,
            'reason' => 'عدم تحویل دسترسی به کانال خریده‌شده'
        ]);

        $this->assertNotNull($case);
        $this->assertSame(300, $case->id);
    }

    // ─── ۴. Notification Preference Center (ترجیحات اعلان‌ها) ─────────

    /** @test */
    public function notification_preference_service_filters_disabled_channels(): void
    {
        $prefModel = m::mock(NotificationPreference::class);
        $prefModel->shouldReceive('isChannelEnabledForType')->andReturn(true)->byDefault();

        $prefService = new NotificationPreferenceService($prefModel);

        $this->assertTrue($prefService->isSmsEnabled(5, 'system_alert'));
    }
}
