<?php

namespace Tests\Unit\Services;

use PHPUnit\Framework\TestCase;
use App\Services\TicketService;
use Mockery as m;

class TicketServiceTest extends TestCase
{
    /** @var \App\Contracts\ValidatorFactoryInterface&\Mockery\MockInterface */
    private \App\Contracts\ValidatorFactoryInterface $validatorFactory;
    /** @var \Core\EventDispatcher&\Mockery\MockInterface */
    private \Core\EventDispatcher $eventDispatcher;
    /** @var \Core\Database&\Mockery\MockInterface */
    private \Core\Database $db;
    /** @var \App\Contracts\LoggerInterface&\Mockery\MockInterface */
    private \App\Contracts\LoggerInterface $logger;
    /** @var \App\Models\Ticket&\Mockery\MockInterface */
    private \App\Models\Ticket $ticketModel;
    /** @var \App\Models\TicketMessage&\Mockery\MockInterface */
    private \App\Models\TicketMessage $messageModel;
    /** @var \Core\RateLimiter&\Mockery\MockInterface */
    private \Core\RateLimiter $rateLimiter;
    /** @var \App\Services\Shared\IdempotencyService&\Mockery\MockInterface */
    private \App\Services\Shared\IdempotencyService $idempotency;
    private TicketService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->validatorFactory = m::mock(\App\Contracts\ValidatorFactoryInterface::class);
        $this->eventDispatcher = m::mock(\Core\EventDispatcher::class);
        $this->db = m::mock(\Core\Database::class);
        $this->logger = m::mock(\App\Contracts\LoggerInterface::class);
        $this->ticketModel = m::mock(\App\Models\Ticket::class);
        $this->messageModel = m::mock(\App\Models\TicketMessage::class);
        $this->rateLimiter = m::mock(\Core\RateLimiter::class);
        $this->idempotency = m::mock(\App\Services\Shared\IdempotencyService::class);

        $this->logger->shouldIgnoreMissing();
        $this->eventDispatcher->shouldIgnoreMissing();

        $this->service = new TicketService(
            $this->validatorFactory,
            $this->eventDispatcher,
            $this->db,
            $this->logger,
            $this->ticketModel,
            $this->messageModel,
            $this->rateLimiter,
            $this->idempotency
        );
    }

    protected function tearDown(): void
    {
        m::close();
        parent::tearDown();
    }

    /** @test */
    public function service_can_be_instantiated(): void
    {
        $this->assertInstanceOf(TicketService::class, $this->service);
    }

    /** @test */
    public function detect_priority_works_correctly(): void
    {
        // Test high priority keywords
        $this->assertEquals('urgent', $this->service->detectPriority('درخواست فوری و بحرانی دارم و پول واریز نشد'));
        $this->assertEquals('high', $this->service->detectPriority('مشکل مهمی در سیستم یا ارور دارم'));
        $this->assertEquals('normal', $this->service->detectPriority('پروفایل کاربری من چطور کار میکند؟'));
        
        // Test category based priority
        $this->assertEquals('high', $this->service->detectPriority('سلام', 1));
    }

    /** @test */
    public function parse_browser_works_correctly(): void
    {
        $uaChrome = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/125.0.0.0 Safari/537.36';
        $browserInfo = $this->service->parseBrowser($uaChrome);
        
        $this->assertEquals('Chrome', $browserInfo['browser']);
        $this->assertEquals('Windows', $browserInfo['os']);

        $uaFirefox = 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10.15; rv:126.0) Gecko/20100101 Firefox/126.0';
        $browserInfo = $this->service->parseBrowser($uaFirefox);
        
        $this->assertEquals('Firefox', $browserInfo['browser']);
        $this->assertEquals('macOS', $browserInfo['os']);
    }

    /** @test */
    public function guard_can_create_ticket_validates_successfully(): void
    {
        $data = [
            'subject' => 'درخواست فعال‌سازی',
            'message' => 'این پیام متنی طولانی‌تر از ۲۰ کاراکتر است تا ولیدیشن تیکت رد نشود.',
            'category' => 'billing',
            'priority' => 'normal',
            'idempotency_key' => 'some_unique_key_123456789'
        ];

        $validator = m::mock(\Core\Validator::class);
        $validator->shouldReceive('validateOrFail')->once()->andReturn($data);

        $this->validatorFactory->shouldReceive('make')
            ->once()
            ->andReturn($validator);

        // Should not throw any exception
        $this->service->guardCanCreateTicket(1, $data);
        $this->assertTrue(true);
    }

    /** @test */
    public function create_ticket_success(): void
    {
        $data = [
            'subject' => 'درخواست تست',
            'message' => 'پیام تیکت که باید طولانی‌تر از ۲۰ کاراکتر باشد.',
            'category_id' => 1,
            'idempotency_key' => 'unique_key_test'
        ];

        // Setup rate limiting mock
        $this->rateLimiter->shouldReceive('attempt')
            ->with('ticket_creation:1', 3, 3600)
            ->once()
            ->andReturn(true);

        // Setup validation mock
        $validator = m::mock(\Core\Validator::class);
        $validator->shouldReceive('validateOrFail')->once()->andReturn($data);
        $this->validatorFactory->shouldReceive('make')->andReturn($validator);

        // Setup idempotency mock
        $this->idempotency->shouldReceive('executeWithTransaction')
            ->once()
            ->andReturnUsing(function ($scope, $actorId, $payload, $callback, $key = null) {
                return $callback();
            });

        // Setup model mocks
        $this->ticketModel->shouldReceive('create')
            ->once()
            ->andReturn(101);

        $this->messageModel->shouldReceive('create')
            ->once()
            ->andReturn(201);

        $result = $this->service->create(1, $data);

        $this->assertTrue($result['success']);
        $this->assertSame(101, int_value($result['ticket_id'] ?? 0));
    }

    /** @test */
    public function create_ticket_rate_limited(): void
    {
        $data = [
            'subject' => 'درخواست تست',
            'message' => 'پیام تیکت که باید طولانی‌تر از ۲۰ کاراکتر باشد.',
            'category_id' => 1,
            'idempotency_key' => 'unique_key_test'
        ];

        // Setup rate limiting mock to fail
        $this->rateLimiter->shouldReceive('attempt')
            ->with('ticket_creation:1', 3, 3600)
            ->once()
            ->andReturn(false);

        // Setup validation mock (guard is called first)
        $validator = m::mock(\Core\Validator::class);
        $validator->shouldReceive('validateOrFail')->once()->andReturn($data);
        $this->validatorFactory->shouldReceive('make')->andReturn($validator);

        $result = $this->service->create(1, $data);

        $this->assertFalse($result['success']);
        $this->assertIsString($result['message']);
        $this->assertStringContainsString('شما اخیراً تیکت‌های زیادی ایجاد کرده‌اید', $result['message']);
    }

    /** @test */
    public function reply_ticket_success(): void
    {
        $ticketId = 101;
        $userId = 1;
        $message = 'پاسخ جدید برای تیکت مورد نظر.';

        // Setup rate limiting mock
        $this->rateLimiter->shouldReceive('attempt')
            ->with("ticket_reply:{$userId}", 5, 3600)
            ->once()
            ->andReturn(true);

        // Setup validation mock (inside the callback)
        $validator = m::mock(\Core\Validator::class);
        $validator->shouldReceive('result')->once()->andReturn([
            'valid' => true,
            'errors' => [],
            'data' => []
        ]);
        $this->validatorFactory->shouldReceive('make')->andReturn($validator);

        // Setup DB mock
        $ticketMock = (object)[
            'id' => $ticketId,
            'user_id' => $userId,
            'status' => 'open',
            'subject' => 'درخواست تست'
        ];
        $this->db->shouldReceive('fetch')
            ->once()
            ->andReturn($ticketMock);

        // Setup models
        $this->messageModel->shouldReceive('create')->once()->andReturn(202);
        $this->ticketModel->shouldReceive('updateLastReply')->once()->andReturn(true);

        // Setup idempotency mock
        $this->idempotency->shouldReceive('executeWithTransaction')
            ->once()
            ->andReturnUsing(function ($scope, $actorId, $payload, $callback, $key = null) {
                return $callback();
            });

        $result = $this->service->reply($ticketId, $userId, $message);

        $this->assertTrue($result['success']);
        $this->assertEquals('پاسخ با موفقیت ثبت شد.', $result['message']);
    }
}
