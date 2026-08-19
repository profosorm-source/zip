<?php

declare(strict_types=1);

namespace Tests\Unit\Core;

use Core\Cache;
use Core\CircuitBreaker;
use Core\EventDispatcher;
use Core\Logger;
use InvalidArgumentException;
use Mockery as m;
use PHPUnit\Framework\TestCase;

final class CircuitBreakerConfigurationBehaviorTest extends TestCase
{
    protected function tearDown(): void
    {
        m::close();
        parent::tearDown();
    }

    /**
     * @dataProvider invalidConfigurationProvider
     * @param array<string, mixed> $configuration
     */
    public function test_invalid_configuration_fails_during_composition(array $configuration): void
    {
        $this->expectException(InvalidArgumentException::class);

        new CircuitBreaker(
            m::mock(Cache::class),
            m::mock(Logger::class),
            m::mock(EventDispatcher::class),
            $configuration
        );
    }

    /** @return array<string, array{0: array<string, mixed>}> */
    public function invalidConfigurationProvider(): array
    {
        return [
            'zero global threshold' => [['failure_threshold' => 0]],
            'negative global timeout' => [['retry_timeout_seconds' => -1]],
            'floating point threshold' => [['failure_threshold' => 1.5]],
            'non-numeric threshold' => [['failure_threshold' => 'five']],
            'service config is scalar' => [['provider' => 'invalid']],
            'service threshold is zero' => [['provider' => ['threshold' => 0, 'timeout' => 10]]],
            'service timeout exceeds bound' => [['provider' => ['threshold' => 2, 'timeout' => 31_536_001]]],
            'empty service name' => [['' => ['threshold' => 2, 'timeout' => 10]]],
        ];
    }

    public function test_valid_service_override_drives_the_open_transition(): void
    {
        $cache = m::mock(Cache::class);
        $logger = m::mock(Logger::class);
        $events = m::mock(EventDispatcher::class);

        $cache->shouldReceive('get')
            ->with('circuit_breaker:provider:status', 'closed')
            ->twice()
            ->andReturn('closed');
        $cache->shouldReceive('increment')
            ->with('circuit_breaker:provider:failures', 1, 7)
            ->twice()
            ->andReturn(1, 2);
        $cache->shouldReceive('putSeconds')
            ->with('circuit_breaker:provider:status', 'open', 14)
            ->once()
            ->andReturn(true);
        $cache->shouldReceive('putSeconds')
            ->with('circuit_breaker:provider:opened_at', m::type('int'), 14)
            ->once()
            ->andReturn(true);

        $logger->shouldReceive('error')->with('circuit_breaker.failure', m::on(
            static fn(array $context): bool => $context['threshold'] === 2
                && $context['status'] === 'closed'
        ))->twice();
        $logger->shouldReceive('critical')->with('circuit_breaker.tripped_open', m::on(
            static fn(array $context): bool => $context['service'] === 'provider'
                && $context['failures'] === 2
                && $context['threshold'] === 2
        ))->once();
        $events->shouldReceive('dispatch')
            ->with('circuit_breaker.opened', m::on(
                static fn(array $payload): bool => $payload['service'] === 'provider'
            ))
            ->once();

        $breaker = new CircuitBreaker(
            $cache,
            $logger,
            $events,
            [
                'failure_threshold' => '5',
                'retry_timeout_seconds' => '60',
                'provider' => ['threshold' => '2', 'timeout' => '7'],
            ]
        );

        $breaker->reportFailure('provider');
        $breaker->reportFailure('provider');

        $this->addToAssertionCount(1);
    }
}
