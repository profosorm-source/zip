<?php

declare(strict_types=1);

namespace Tests\Unit\Events;

use PHPUnit\Framework\TestCase;
use App\Contracts\DomainEvent;

/**
 * تست اینکه Event‌های بیزینسی DomainEvent interface رو implement کردن
 */
class DomainEventImplementationTest extends TestCase
{
    /** @test */
    public function investment_created_event_implements_domain_event(): void
    {
        $event = new \App\Events\InvestmentCreatedEvent(1, 100, '50.0', 'usdt');
        $this->assertInstanceOf(DomainEvent::class, $event);
        $this->assertEquals('investment', $event->aggregateType());
        $this->assertEquals('100', $event->aggregateId());
        $this->assertIsArray($event->toPayload());
        $this->assertEquals(1, $event->toPayload()['user_id']);
    }

    /** @test */
    public function score_delta_appended_event_implements_domain_event(): void
    {
        $event = new \App\Events\ScoreDeltaAppendedEvent('user', 42, 'xp', 5.0, 'test');
        $this->assertInstanceOf(DomainEvent::class, $event);
        $this->assertEquals('user', $event->aggregateType());
        $this->assertEquals('42', $event->aggregateId());
        $payload = $event->toPayload();
        $this->assertEquals('xp', $payload['domain']);
        $this->assertEquals(5.0, $payload['delta']);
    }

    /** @test */
    public function generic_event_implements_domain_event(): void
    {
        $event = new \Core\GenericEvent(['user_id' => 10, 'event_name' => 'test.done']);
        $this->assertInstanceOf(DomainEvent::class, $event);
        $this->assertEquals('test.done', $event->aggregateType());
        $this->assertEquals('10', $event->aggregateId());
    }

    /** @test */
    public function generic_event_defaults_without_keys(): void
    {
        $event = new \Core\GenericEvent(['foo' => 'bar']);
        $this->assertEquals('general', $event->aggregateType());
        $this->assertEquals('0', $event->aggregateId());
    }

    /**
     * @test
     * @dataProvider nonDomainEventProvider
     * @param class-string $class
     * @param list<mixed> $args
     */
    public function non_domain_events_do_not_implement_interface(string $class, array $args): void
    {
        $event = new $class(...$args);
        $this->assertNotInstanceOf(DomainEvent::class, $event);
    }

    /** @return list<array{0:class-string,1:list<mixed>}> */
    public function nonDomainEventProvider(): array
    {
        return [
            ['App\\Events\\SettingsUpdated', [['key1']]],
        ];
    }

    /**
     * @test
     * @dataProvider domainEventProvider
     * @param class-string $class
     * @param list<mixed> $args
     */
    public function domain_events_return_valid_aggregate_data(string $class, array $args): void
    {
        $event = new $class(...$args);
        $this->assertInstanceOf(DomainEvent::class, $event);
        $this->assertNotEmpty($event->aggregateType());
        $this->assertIsString($event->aggregateId());
        $this->assertIsArray($event->toPayload());
    }

    /** @return list<array{0:class-string,1:list<mixed>}> */
    public function domainEventProvider(): array
    {
        return [
            ['App\\Events\\InvestmentCreatedEvent', [1, 1, '10.0', 'usdt']],
            ['App\\Events\\ScoreDeltaAppendedEvent', ['user', 1, 'xp', 1.0, 'test']],
            ['Core\\GenericEvent', [['user_id' => 1]]],
        ];
    }
}
