<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use Tests\Support\CreatesTypedMockeryMocks;
use App\Contracts\LoggerInterface;
use App\Contracts\ValidatorFactoryInterface;
use App\Models\Ticket;
use App\Models\TicketMessage;
use App\Services\Shared\IdempotencyService;
use App\Services\TicketCommandService;
use Core\Database;
use Core\EventDispatcher;
use Core\RateLimiter;
use Mockery as m;
use PHPUnit\Framework\TestCase;

final class TicketCommandServiceBehaviorTest extends TestCase
{
    use CreatesTypedMockeryMocks;
    protected function tearDown(): void { m::close(); parent::tearDown(); }

    public function test_priority_detection_escalates_security_and_payment_language(): void
    {
        $service=$this->service();
        $this->assertSame('urgent',$service->detectPriority('حساب من هک شده و دسترسی ندارم'));
        $this->assertSame('urgent',$service->detectPriority('پرداخت ناموفق و پول از حساب کسر شد'));
        $this->assertSame('normal',$service->detectPriority('یک سوال عمومی دارم'));
    }

    public function test_browser_parser_returns_stable_family_and_platform(): void
    {
        $parsed=$this->service()->parseBrowser('Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit Chrome/120 Safari/537.36');
        $this->assertSame('Chrome',$parsed['browser']);
        $this->assertSame('Windows',$parsed['os']);
    }

    public function test_guard_rejects_invalid_user_before_validation_dependencies(): void
    {
        $service=$this->service();
        $this->expectException(\App\Exceptions\BusinessException::class);
        $service->guardCanCreateTicket(0,['subject'=>'valid subject','message'=>str_repeat('x',25)]);
    }

    private function service(): TicketCommandService
    {
        return new TicketCommandService(
            $this->lenientMock(ValidatorFactoryInterface::class),$this->lenientMock(EventDispatcher::class),
            $this->lenientMock(Database::class),$this->lenientMock(LoggerInterface::class),
            $this->lenientMock(Ticket::class),$this->lenientMock(TicketMessage::class),
            $this->lenientMock(RateLimiter::class),$this->lenientMock(IdempotencyService::class)
        );
    }
}
