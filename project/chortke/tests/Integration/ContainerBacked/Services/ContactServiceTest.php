<?php

namespace Tests\Integration\ContainerBacked\Services;

use PHPUnit\Framework\TestCase;
use App\Services\ContactService;
use Mockery as m;

/**
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
class ContactServiceTest extends TestCase
{
    /** @var \App\Contracts\LoggerInterface&\Mockery\MockInterface */
    private \App\Contracts\LoggerInterface $logger;
    /** @var \App\Models\ContactMessage&\Mockery\MockInterface */
    private \App\Models\ContactMessage $contactMessageModel;
    /** @var \Core\RateLimiter&\Mockery\MockInterface */
    private \Core\RateLimiter $rateLimiter;
    /** @var \App\Services\CaptchaService&\Mockery\MockInterface */
    private \App\Services\CaptchaService $captchaService;
    private ContactService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->logger = m::mock('App\Contracts\LoggerInterface');
        $this->contactMessageModel = m::mock('App\Models\ContactMessage');
        $this->rateLimiter = m::mock('Core\RateLimiter');
        $this->captchaService = m::mock('App\Services\CaptchaService');

        $this->logger->shouldIgnoreMissing();

        // Standard rate limiting allows requests
        $this->rateLimiter->shouldReceive('attempt')->byDefault()->andReturn(true);

        // Captcha is enabled by default
        $this->captchaService->shouldReceive('isEnabled')->byDefault()->andReturn(true);

        // Bind Database mock inside Container for Validator to resolve without crashing
        $dbMock = m::mock('Core\Database');
        \Core\Container::getInstance()->instance(\Core\Database::class, $dbMock);

        $this->service = new ContactService(
            $this->logger,
            $this->contactMessageModel,
            $this->rateLimiter,
            $this->captchaService
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
        $this->assertInstanceOf(ContactService::class, $this->service);
    }

    /** @test */
    public function send_message_throws_validation_exception_on_missing_captcha_tokens(): void
    {
        $this->expectException(\Core\Exceptions\ValidationException::class);
        $this->expectExceptionMessage('Validation failed');

        $data = [
            'name' => 'علیرضا',
            'email' => 'alireza@example.com',
            'subject' => 'درخواست همکاری',
            'message' => 'متن پیام همکاری طولانی‌تر از ده کاراکتر.',
            'captcha_token' => '', // Empty
            'captcha_response' => ''
        ];

        $this->service->sendMessage($data);
    }

    /** @test */
    public function send_message_throws_security_exception_on_failed_captcha(): void
    {
        $this->expectException(\Core\Exceptions\SecurityException::class);
        $this->expectExceptionMessage('تأییدیه امنیتی نامعتبر است');

        $data = [
            'name' => 'علیرضا',
            'email' => 'alireza@example.com',
            'subject' => 'درخواست همکاری',
            'message' => 'متن پیام همکاری طولانی‌تر از ده کاراکتر.',
            'captcha_token' => 'invalid_token',
            'captcha_response' => 'wrong_answer'
        ];

        $this->captchaService->shouldReceive('verify')
            ->with('invalid_token', 'wrong_answer')
            ->once()
            ->andReturn(false); // Fail

        $this->service->sendMessage($data);
    }

    /** @test */
    public function send_message_success_with_valid_captcha_and_payload(): void
    {
        $data = [
            'name' => 'علیرضا',
            'email' => 'alireza@example.com',
            'subject' => 'درخواست همکاری',
            'message' => 'متن پیام همکاری طولانی‌تر از ده کاراکتر.',
            'captcha_token' => 'valid_token',
            'captcha_response' => '9'
        ];

        $this->captchaService->shouldReceive('verify')
            ->with('valid_token', '9')
            ->once()
            ->andReturn(true); // Success

        // Model expectation
        $this->contactMessageModel->shouldReceive('createMessage')
            ->once()
            ->andReturn(12);

        $result = $this->service->sendMessage($data);

        $this->assertTrue($result['success']);
        $this->assertIsString($result['message']);
        $this->assertStringContainsString('با موفقیت ارسال شد', $result['message']);
    }
}
