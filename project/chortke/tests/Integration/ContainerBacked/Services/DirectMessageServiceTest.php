<?php

namespace Tests\Integration\ContainerBacked\Services;

use PHPUnit\Framework\TestCase;
use App\Services\DirectMessageService;
use Mockery as m;

/**
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
class DirectMessageServiceTest extends TestCase
{
    /** @var \Core\Redis&\Mockery\MockInterface */
    private \Core\Redis $redis;
    /** @var \Core\Database&\Mockery\MockInterface */
    private \Core\Database $db;
    /** @var \App\Contracts\LoggerInterface&\Mockery\MockInterface */
    private \App\Contracts\LoggerInterface $logger;
    /** @var \App\Models\DirectMessage&\Mockery\MockInterface */
    private \App\Models\DirectMessage $directMessageModel;
    /** @var \App\Services\Settings\AppSettings&\Mockery\MockInterface */
    private \App\Services\Settings\AppSettings $appSettings;
    /** @var \App\Services\User\UserSettingsService&\Mockery\MockInterface */
    private \App\Services\User\UserSettingsService $userSettingsService;
    private DirectMessageService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->redis = m::mock('Core\Redis');
        $this->db = m::mock('Core\Database');
        $this->logger = m::mock('App\Contracts\LoggerInterface');
        $this->directMessageModel = m::mock('App\Models\DirectMessage');
        $this->appSettings = m::mock('App\Services\Settings\AppSettings');
        $this->userSettingsService = m::mock('App\Services\User\UserSettingsService');

        $this->logger->shouldIgnoreMissing();
        
        // Define default Mock expectations to avoid strict failures in minor tests
        $this->db->shouldReceive('beginTransaction')->byDefault();
        $this->db->shouldReceive('rollBack')->byDefault();
        $this->db->shouldReceive('commit')->byDefault();

        $this->appSettings->shouldReceive('get')->byDefault()->andReturn(5000);
        $this->userSettingsService->shouldReceive('get')->byDefault()->andReturn(true);
        $this->directMessageModel->shouldReceive('isBlocked')->byDefault()->andReturn(false);
        $this->redis->shouldReceive('eval')->byDefault()->andReturn(1);

        \Core\Container::getInstance()->instance(\Core\Database::class, $this->db);

        $this->service = new DirectMessageService(
            $this->redis,
            $this->db,
            $this->logger,
            $this->directMessageModel,
            $this->appSettings,
            $this->userSettingsService
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
        $this->assertInstanceOf(DirectMessageService::class, $this->service);
    }

    /** @test */
    public function cannot_send_message_to_self(): void
    {
        $senderId = 1;
        $recipientId = 1;
        $message = 'سلام دنیا!';

        $result = $this->service->sendMessage($senderId, $recipientId, $message);

        $this->assertArrayHasKey('error', $result);
        $this->assertEquals('نمی‌توانید برای خودتان پیام بفرستید', $result['error']);
    }

    /** @test */
    public function validation_fails_on_empty_message(): void
    {
        $senderId = 1;
        $recipientId = 2;
        $message = ''; // Empty message

        $result = $this->service->sendMessage($senderId, $recipientId, $message);

        $this->assertArrayHasKey('error', $result);
        $this->assertIsString($result['error']);
        $this->assertStringContainsString('الزامی است', $result['error']);
    }

    /** @test */
    public function messaging_blocked_users_fails(): void
    {
        $senderId = 1;
        $recipientId = 2;
        $message = 'سلام';

        // User exists
        $this->directMessageModel->shouldReceive('getUserInfo')
            ->with($recipientId)
            ->once()
            ->andReturn((object)['id' => $recipientId]);

        // Override isBlocked for this test to return true (blocked)
        $this->directMessageModel->shouldReceive('isBlocked')
            ->andReturn(true);

        $result = $this->service->sendMessage($senderId, $recipientId, $message);

        $this->assertArrayHasKey('error', $result);
        $this->assertEquals('امکان ارسال پیام بین شما و این کاربر وجود ندارد', $result['error']);
    }

    /** @test */
    public function messaging_forbidden_content_is_blocked(): void
    {
        $senderId = 1;
        $recipientId = 2;
        
        $forbiddenMessages = [
            'شماره من 09123456789 هست تماس بگیر',
            'آیدی تلگرام من @some_user هست پی‌ام بده',
            'سایت ما رو ببینید: https://example.com',
            'کازینو آنلاین و بازی انفجار و شرط‌بندی',
            // Note: fullwidth Unicode bypass test requires php-intl extension (Normalizer class)
            // 'بایپس یونیکد: @ｓｏｍｅ_ｕｓｅｒ'
        ];

        foreach ($forbiddenMessages as $msg) {
            $this->directMessageModel->shouldReceive('getUserInfo')
                ->andReturn((object)['id' => $recipientId]);

            $result = $this->service->sendMessage($senderId, $recipientId, $msg);

            $this->assertArrayHasKey('error', $result);
            $this->assertIsString($result['error']);
            $this->assertStringContainsString('خلاف قوانین است', $result['error']);
        }
    }

    /** @test */
    public function send_message_success(): void
    {
        $senderId = 1;
        $recipientId = 2;
        $message = 'یک پیام کاملا سالم و قانونی!';

        $this->directMessageModel->shouldReceive('getUserInfo')
            ->with($recipientId)
            ->once()
            ->andReturn((object)['id' => $recipientId]);

        // Redis rate limit check
        $this->redis->shouldReceive('eval')
            ->once()
            ->andReturn(1); // allowed

        // DB Transaction mock
        $this->db->shouldReceive('beginTransaction')->once();
        
        // Create message mock
        $this->directMessageModel->shouldReceive('createMessage')
            ->with($senderId, $recipientId, htmlspecialchars($message, ENT_QUOTES, 'UTF-8'), false)
            ->once()
            ->andReturn(1001);

        // Pessimistic Lock on Conversation
        $this->db->shouldReceive('query')
            ->with(m::type('string'), [1, 2])
            ->once();

        $this->directMessageModel->shouldReceive('updateConversation')
            ->with($senderId, $recipientId, 1001)
            ->once();

        $this->db->shouldReceive('commit')->once();

        // Increment unread count in Redis
        $this->redis->shouldReceive('incr')
            ->with('unread:2:1')
            ->once();

        $result = $this->service->sendMessage($senderId, $recipientId, $message);

        $this->assertTrue($result['success']);
        $this->assertEquals(1001, $result['message_id']);
    }

    /** @test */
    public function get_conversation_returns_formatted_messages(): void
    {
        $userId = 1;
        $otherUserId = 2;

        $dbMessages = [
            (object)[
                'id' => 10,
                'sender_id' => 2,
                'sender_name' => 'کاربر دوم',
                'message' => 'سلام، چطوری؟',
                'is_encrypted' => 0,
                'attachment_count' => 0,
                'created_at' => '2026-06-03 12:00:00',
                'read_at' => null
            ]
        ];

        $this->directMessageModel->shouldReceive('getConversation')
            ->with($userId, $otherUserId, 50, 0)
            ->once()
            ->andReturn($dbMessages);

        // Redis last seen key
        $this->redis->shouldReceive('setex')
            ->once();

        $result = $this->service->getConversation($userId, $otherUserId);

        $this->assertCount(1, $result);
        $this->assertEquals(10, $result[0]['id']);
        $this->assertEquals('سلام، چطوری؟', $result[0]['message']);
        $this->assertFalse($result[0]['is_encrypted']);
    }

    /** @test */
    public function get_conversations_formats_successfully(): void
    {
        $userId = 1;
        $dbConversations = [
            (object)[
                'user_id' => 2,
                'full_name' => 'کاربر دوم',
                'avatar' => null,
                'last_message' => 'یک پیام طولانی که باید کوتاه شود تا در خروجی زیبا باشد.',
                'is_encrypted' => 0,
                'last_message_at' => '2026-06-03 12:00:00',
                'unread_count' => 3
            ]
        ];

        $this->directMessageModel->shouldReceive('getConversations')
            ->with($userId, 20, 0)
            ->once()
            ->andReturn($dbConversations);

        $result = $this->service->getConversations($userId);

        $this->assertCount(1, $result);
        $this->assertEquals(2, $result[0]['user_id']);
        $this->assertIsString($result[0]['last_message']);
        $this->assertStringContainsString('...', $result[0]['last_message']);
    }

    /** @test */
    public function delete_message_fails_if_unauthorized_idor_prevention(): void
    {
        $userId = 1;
        $messageId = 1001;

        // Message belongs to another sender (sender_id = 9)
        $messageMock = (object)[
            'id' => $messageId,
            'sender_id' => 9,
            'recipient_id' => 1
        ];

        $this->directMessageModel->shouldReceive('findMessageById')
            ->with($messageId)
            ->once()
            ->andReturn($messageMock);

        $result = $this->service->deleteMessage($messageId, $userId);

        $this->assertFalse($result);
    }

    /** @test */
    public function add_reaction_validates_emoji(): void
    {
        $userId = 1;
        $messageId = 1001;

        $messageMock = (object)[
            'id' => $messageId,
            'sender_id' => 1,
            'recipient_id' => 2
        ];

        $this->directMessageModel->shouldReceive('findMessageById')
            ->with($messageId)
            ->andReturn($messageMock);

        // Invalid non-emoji
        $result = $this->service->addReaction($messageId, $userId, 'text');
        $this->assertFalse($result);

        // Valid emoji
        $this->directMessageModel->shouldReceive('addReaction')
            ->with($messageId, $userId, '😊')
            ->once()
            ->andReturn(true);

        $result = $this->service->addReaction($messageId, $userId, '😊');
        $this->assertTrue($result);
    }
}
