<?php

declare(strict_types=1);

namespace Tests\Unit\Core;

use App\Services\LogService;
use Core\Logger;
use Mockery as m;
use PHPUnit\Framework\TestCase;

final class LoggerSecurityBehaviorTest extends TestCase
{
    protected function tearDown(): void
    {
        m::close();
        parent::tearDown();
    }

    public function test_valid_security_type_is_forwarded(): void
    {
        $service = m::mock(LogService::class);
        $service->shouldReceive('logSecurity')
            ->once()
            ->with('auth.login_failed', 'invalid password', 'warning', m::type('array'));

        (new Logger($service))->security(
            'warning',
            'invalid password',
            ['type' => 'auth.login_failed', 'user_id' => 42]
        );
        $this->addToAssertionCount(1);
    }

    /**
     * @dataProvider invalidTypeProvider
     * @param mixed $invalidType
     */
    public function test_invalid_security_type_falls_back_without_breaking_logging($invalidType): void
    {
        $service = m::mock(LogService::class);
        $service->shouldReceive('logSecurity')
            ->once()
            ->with('security', 'security event', 'error', m::type('array'));

        (new Logger($service))->security('error', 'security event', ['type' => $invalidType]);
        $this->addToAssertionCount(1);
    }

    /** @return array<string, array{0: mixed}> */
    public function invalidTypeProvider(): array
    {
        return [
            'array' => [['auth']],
            'empty string' => [''],
            'uppercase/space' => ['Auth Event'],
            'too long' => [str_repeat('a', 65)],
        ];
    }
}
